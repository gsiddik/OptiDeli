<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Master\MasterLabels;
use App\Domain\Order\OrderPricing;
use App\Domain\Shared\TenantStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public static function storeFor(Request $request): TenantStore
    {
        return new TenantStore($request->attributes->get('tenant')->id, $request->user()->id);
    }

    public static function writeAccess(Request $request): void
    {
        Access::authorize($request, 'operations.manage', 'Akses hanya baca.');
    }

    public static function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        abort_unless((bool) preg_match('/^[a-zA-Z0-9_-]{16,100}$/', $key), 422, 'Idempotency-Key wajib (16–100 karakter).');

        return $key;
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['DRAFT', 'CONFIRMED', 'DISPATCHED', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'])], 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $q = self::storeFor($request)->query('orders');
        if (! empty($data['status'])) {
            $q->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $q->where(fn ($q) => $q->where('number', 'like', '%'.addcslashes($data['q'], '%_\\').'%')->orWhere('customer_name', 'like', '%'.addcslashes($data['q'], '%_\\').'%'));
        }

        $page = $q->orderByDesc('created_at')->orderBy('id')->paginate(25);
        MasterLabels::annotate(self::storeFor($request), $page->items(), MasterLabels::ORDER);

        return response()->json($page);
    }

    public function show(Request $request, string $id)
    {
        $store = self::storeFor($request);
        $order = $store->find('orders', $id);
        $order->items = $store->query('order_items')->where('order_id', $id)->get();
        $order->pricing_snapshot = $order->pricing_snapshot === null ? null : json_decode($order->pricing_snapshot, true);
        $order->vehicle_label = $order->vehicle_id ? $store->query('vehicles')->where('id', $order->vehicle_id)->value('plate') : null;
        $order->plans = $store->query('dispatch_plans')->where('order_id', $id)->orderByDesc('created_at')->get();
        MasterLabels::annotate($store, [$order], MasterLabels::ORDER);

        return response()->json(['data' => $order]);
    }

    public function create(Request $request)
    {
        self::writeAccess($request);
        $money = 'required|integer|min:0|max:1000000000000';
        $tenant = $request->attributes->get('tenant')->id;
        $mapping = $request->input('price_mode') === 'MAPPING';
        $master = fn (string $table) => ['nullable', 'uuid', Rule::exists($table, 'id')->where('tenant_id', $tenant)->where('active', true)];
        $data = $request->validate([
            'customer_name' => 'required_without:customer_id|nullable|string|max:200', 'reference' => 'nullable|string|max:100',
            'customer_id' => array_merge(['required_with:project_id'], $master('customers')), 'project_id' => $master('customer_projects'),
            'service_id' => $master('services'), 'origin_location_id' => $master('locations'), 'destination_location_id' => $master('locations'),
            'stage' => ['required', Rule::in(['FIRST_MILE', 'MIDDLE_MILE', 'LAST_MILE', 'E2E'])],
            'origin' => 'required_without:origin_location_id|nullable|string|max:300', 'destination' => 'required_without:destination_location_id|nullable|string|max:300',
            'currency' => ['required', Rule::in(['IDR'])],
            'price_mode' => ['nullable', Rule::in(['MANUAL', 'MAPPING'])],
            'base_price_minor' => $mapping ? 'nullable|integer' : $money, 'surcharge_minor' => $mapping ? 'nullable|integer' : $money, 'accessorial_minor' => $mapping ? 'nullable|integer' : $money,
            'transporter_vendor_id' => $master('vendors'), 'vehicle_id' => $master('vehicles'), 'carrier_type_id' => $master('carrier_types'), 'price_reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:description,quantity,unit_weight_g,length_mm,width_mm,height_mm,stackable,hazmat_class,unit_id,manual_price_minor',
            'items.*.unit_id' => $mapping ? ['required', 'uuid', Rule::exists('units', 'id')->where('tenant_id', $tenant)->where('active', true)] : $master('units'),
            'items.*.manual_price_minor' => $mapping ? 'nullable|integer|min:1|max:1000000000000' : 'prohibited',
            'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.unit_weight_g' => 'required|integer|min:1|max:100000000',
            'items.*.length_mm' => 'required|integer|min:1|max:20000', 'items.*.width_mm' => 'required|integer|min:1|max:20000', 'items.*.height_mm' => 'required|integer|min:1|max:20000',
            'items.*.stackable' => 'required|boolean', 'items.*.hazmat_class' => 'nullable|string|max:20',
        ]);
        $store = self::storeFor($request);
        self::checkProject($store, $data);
        $order = $store->idempotent('order.create', self::key($request), $data, function () use ($data, $store, $mapping, $request) {
            $id = (string) Str::uuid();
            $items = $data['items'];
            unset($data['items'], $data['price_mode']);
            $data = self::snapshot($store, $data);
            $pricing = ['price_source' => 'MANUAL', 'price_status' => 'FIXED'];
            if ($mapping) {
                [$data, $pricing] = self::priceFromMapping($store, $data, $items, $request->user()->id);
            } else {
                $data = array_diff_key($data, array_flip(['transporter_vendor_id', 'vehicle_id', 'carrier_type_id', 'price_reason']));
            }
            $data['base_price_minor'] ??= 0;
            $data['surcharge_minor'] ??= 0;
            $data['accessorial_minor'] ??= 0;
            $sell = $data['base_price_minor'] + $data['surcharge_minor'] + $data['accessorial_minor'];
            abort_unless($sell > 0, 422, 'Harga jual harus lebih dari nol.');
            DB::table('orders')->insert([...$data, ...$pricing, 'id' => $id, 'tenant_id' => $store->tenantId, 'number' => 'OD-'.Str::ulid(), 'sell_minor' => $sell, 'status' => 'DRAFT', 'created_by' => $store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $item) {
                $item += ['unit_id' => null, 'manual_price_minor' => null];
                DB::table('order_items')->insert([...$item, 'id' => (string) Str::uuid(), 'tenant_id' => $store->tenantId, 'order_id' => $id]);
            }
            $store->event('order.created', $id, ['number' => $store->find('orders', $id)->number]);

            return $store->find('orders', $id);
        });

        return response()->json(['data' => $order], 201);
    }

    /**
     * Prices an order from the transporter price mapping: the fleet type of the vehicle (or the one given), the lane and the unit of
     * every cargo line. A line without a mapping row needs a manual price and the order's reason; the order then waits for a second
     * person. Returns the order columns (price, fleet, snapshot) and the pricing state.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function priceFromMapping(TenantStore $store, array $data, array $items, int $actorId): array
    {
        foreach (['customer_id', 'origin_location_id', 'destination_location_id'] as $required) {
            if (empty($data[$required])) {
                throw ValidationException::withMessages([$required => 'Pelanggan, lokasi asal, dan lokasi tujuan wajib dipilih dari master untuk harga otomatis.']);
            }
        }
        $pricing = new OrderPricing($store);
        $fleet = $pricing->fleet($data['transporter_vendor_id'] ?? null, $data['vehicle_id'] ?? null, $data['carrier_type_id'] ?? null);
        $result = $pricing->price($data, $fleet['carrier_type_id'], $items);
        $manual = [];
        foreach ($result['unpriced'] as $i) {
            if (empty($items[$i]['manual_price_minor'])) {
                $label = $items[$i]['description'] ?? '#'.($i + 1);
                throw ValidationException::withMessages(["items.$i.manual_price_minor" => "Tidak ada tarif untuk baris \"{$label}\" (rute, tipe armada, dan satuan ini). Isi harga manual beserta alasan untuk persetujuan."]);
            }
            $manual[] = $i;
        }
        if ($manual !== [] && trim((string) ($data['price_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['price_reason' => 'Alasan wajib untuk harga manual.']);
        }
        $total = $result['total_minor'];
        foreach ($manual as $i) {
            $total += (int) $items[$i]['manual_price_minor'];
            $result['items'][$i]['amount_minor'] = (int) $items[$i]['manual_price_minor'];
            $result['items'][$i]['source'] = 'MANUAL';
        }
        $data['price_reason'] = $manual === [] ? null : $data['price_reason'];
        $data['base_price_minor'] = $total;
        $data['surcharge_minor'] = 0;
        $data['accessorial_minor'] = 0;
        $data['carrier_type_id'] = $fleet['carrier_type_id'];
        $data['vehicle_id'] = $fleet['vehicle_id'];
        $data['transporter_vendor_id'] ??= null;

        return [$data, [
            'price_source' => $manual === [] ? 'MAPPING' : 'APPROVAL', 'price_status' => $manual === [] ? 'FIXED' : 'PENDING',
            'price_requested_by' => $manual === [] ? null : $actorId,
            'pricing_snapshot' => json_encode(self::snapshotOf($data, $result), JSON_THROW_ON_ERROR),
        ]];
    }

    /** What an order keeps of its price: the inputs and the amounts per cargo line, never a buying price. */
    private static function snapshotOf(array $data, array $result): array
    {
        return [
            'priced_at' => now()->toIso8601String(), 'transporter_vendor_id' => $data['transporter_vendor_id'] ?? null, 'vehicle_id' => $data['vehicle_id'] ?? null,
            'carrier_type_id' => $data['carrier_type_id'], 'origin_location_id' => $data['origin_location_id'], 'destination_location_id' => $data['destination_location_id'],
            'customer_id' => $data['customer_id'], 'project_id' => $data['project_id'] ?? null, 'items' => $result['items'], 'total_minor' => $data['base_price_minor'],
        ];
    }

    /** The price a draft order would get, without writing anything (the form shows it read-only). */
    public function pricePreview(Request $request)
    {
        $tenant = $request->attributes->get('tenant')->id;
        $ref = fn (string $table) => ['nullable', 'uuid', Rule::exists($table, 'id')->where('tenant_id', $tenant)];
        $data = $request->validate([
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where('tenant_id', $tenant)], 'project_id' => $ref('customer_projects'),
            'origin_location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->where('tenant_id', $tenant)], 'destination_location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->where('tenant_id', $tenant)],
            'transporter_vendor_id' => $ref('vendors'), 'vehicle_id' => $ref('vehicles'), 'carrier_type_id' => $ref('carrier_types'),
            'items' => 'required|array|min:1|max:100', 'items.*.description' => 'nullable|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.unit_id' => ['required', 'uuid', Rule::exists('units', 'id')->where('tenant_id', $tenant)],
        ]);
        $store = self::storeFor($request);
        self::checkProject($store, $data);
        $pricing = new OrderPricing($store);
        $fleet = $pricing->fleet($data['transporter_vendor_id'] ?? null, $data['vehicle_id'] ?? null, $data['carrier_type_id'] ?? null);

        return response()->json(['data' => $pricing->price($data, $fleet['carrier_type_id'], $data['items']) + ['carrier_type_id' => $fleet['carrier_type_id']]]);
    }

    /** A manual price waits for a second person with rate.approve; the one who asked for it cannot decide. */
    public function priceDecision(Request $request, string $id)
    {
        Access::authorize($request, 'rate.approve', 'Akun Anda tidak boleh menyetujui harga.');
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'reject')]]);
        $store = self::storeFor($request);
        $order = DB::transaction(function () use ($store, $id, $data) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $order = $store->find('orders', $id, true);
            abort_unless($order->price_status === 'PENDING' && $order->status === 'DRAFT', 409, 'Harga order ini tidak menunggu persetujuan.');
            abort_if($order->price_requested_by === $store->actorId, 403, 'Pemohon harga manual tidak boleh menyetujuinya sendiri.');
            $status = $data['decision'] === 'approve' ? 'APPROVED' : 'REJECTED';
            $store->query('orders')->where('id', $id)->update(['price_status' => $status, 'price_decided_by' => $store->actorId, 'price_decided_at' => now(), 'price_decision_note' => $data['note'] ?? null, 'updated_at' => now()]);
            $store->event('order.price_'.strtolower($status), $id, ['sell_minor' => $order->sell_minor]);

            return $store->find('orders', $id);
        }, 3);

        return response()->json(['data' => $order]);
    }

    /** A project must belong to the customer it is ordered for. */
    private static function checkProject(TenantStore $store, array $data): void
    {
        if (! empty($data['project_id']) && $store->query('customer_projects')->where('id', $data['project_id'])->where('customer_id', $data['customer_id'])->doesntExist()) {
            throw ValidationException::withMessages(['project_id' => 'Proyek tidak termasuk pelanggan yang dipilih.']);
        }
    }

    /** The free text of an order is a snapshot of the master data it points at, kept as it was when the order was made. */
    private static function snapshot(TenantStore $store, array $data): array
    {
        if (! empty($data['customer_id'])) {
            $data['customer_name'] = $store->find('customers', $data['customer_id'])->name;
        }
        foreach (['origin' => 'origin_location_id', 'destination' => 'destination_location_id'] as $text => $column) {
            if (! empty($data[$column])) {
                $data[$text] = mb_substr($store->find('locations', $data[$column])->name, 0, 300);
            }
        }

        return $data;
    }

    /** The price is fixed when the order is confirmed: a mapping price is computed once more from today's approved tariffs; an approved manual price stays as approved. */
    private static function lockPrice(TenantStore $store, object $order): void
    {
        $update = ['price_locked_at' => now()];
        if ($order->price_source === 'MAPPING') {
            $items = $store->query('order_items')->where('order_id', $order->id)->get()->map(fn ($i) => (array) $i)->all();
            $result = (new OrderPricing($store))->price((array) $order, $order->carrier_type_id, $items);
            if ($result['unpriced'] !== []) {
                throw ValidationException::withMessages(['items' => 'Tarif untuk order ini tidak lagi berlaku; harga tidak dapat dikunci. Buat order baru.']);
            }
            $data = ['base_price_minor' => $result['total_minor']] + (array) $order;
            $update += ['base_price_minor' => $result['total_minor'], 'sell_minor' => $result['total_minor'] + (int) $order->surcharge_minor + (int) $order->accessorial_minor, 'pricing_snapshot' => json_encode(self::snapshotOf($data, $result), JSON_THROW_ON_ERROR)];
        }
        $store->query('orders')->where('id', $order->id)->update($update);
    }

    public function transition(Request $request, string $id)
    {
        self::writeAccess($request);
        $data = $request->validate(['action' => ['required', Rule::in(['confirm', 'cancel'])]]);
        $store = self::storeFor($request);
        $order = DB::transaction(function () use ($store, $id, $data) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $order = $store->find('orders', $id, true);
            if ($data['action'] === 'confirm') {
                abort_unless($order->status === 'DRAFT', 409, 'Hanya draft yang dapat dikonfirmasi.');
                abort_if(in_array($order->price_status, ['PENDING', 'REJECTED'], true), 409, $order->price_status === 'PENDING' ? 'Harga manual order ini belum disetujui.' : 'Harga manual order ini ditolak; buat order baru.');
                $status = 'CONFIRMED';
                self::lockPrice($store, $order);
            } else {
                abort_unless(in_array($order->status, ['DRAFT', 'CONFIRMED'], true), 409, 'Order yang telah dispatch tidak dapat dibatalkan melalui aksi ini.');
                $status = 'CANCELLED';
            }
            $store->query('orders')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            $store->event('order.'.strtolower($status), $id);

            return $store->find('orders', $id);
        }, 3);

        return response()->json(['data' => $order]);
    }
}
