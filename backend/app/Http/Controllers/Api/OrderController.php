<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Master\MasterLabels;
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
        $order->plans = $store->query('dispatch_plans')->where('order_id', $id)->orderByDesc('created_at')->get();
        MasterLabels::annotate($store, [$order], MasterLabels::ORDER);

        return response()->json(['data' => $order]);
    }

    public function create(Request $request)
    {
        self::writeAccess($request);
        $money = 'required|integer|min:0|max:1000000000000';
        $tenant = $request->attributes->get('tenant')->id;
        $master = fn (string $table) => ['nullable', 'uuid', Rule::exists($table, 'id')->where('tenant_id', $tenant)->where('active', true)];
        $data = $request->validate([
            'customer_name' => 'required_without:customer_id|nullable|string|max:200', 'reference' => 'nullable|string|max:100',
            'customer_id' => array_merge(['required_with:project_id'], $master('customers')), 'project_id' => $master('customer_projects'),
            'service_id' => $master('services'), 'origin_location_id' => $master('locations'), 'destination_location_id' => $master('locations'),
            'stage' => ['required', Rule::in(['FIRST_MILE', 'MIDDLE_MILE', 'LAST_MILE', 'E2E'])],
            'origin' => 'required_without:origin_location_id|nullable|string|max:300', 'destination' => 'required_without:destination_location_id|nullable|string|max:300',
            'currency' => ['required', Rule::in(['IDR'])], 'base_price_minor' => $money, 'surcharge_minor' => $money, 'accessorial_minor' => $money,
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:description,quantity,unit_weight_g,length_mm,width_mm,height_mm,stackable,hazmat_class',
            'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.unit_weight_g' => 'required|integer|min:1|max:100000000',
            'items.*.length_mm' => 'required|integer|min:1|max:20000', 'items.*.width_mm' => 'required|integer|min:1|max:20000', 'items.*.height_mm' => 'required|integer|min:1|max:20000',
            'items.*.stackable' => 'required|boolean', 'items.*.hazmat_class' => 'nullable|string|max:20',
        ]);
        $store = self::storeFor($request);
        self::checkProject($store, $data);
        $order = $store->idempotent('order.create', self::key($request), $data, function () use ($data, $store) {
            $id = (string) Str::uuid();
            $items = $data['items'];
            unset($data['items']);
            $data = self::snapshot($store, $data);
            $sell = $data['base_price_minor'] + $data['surcharge_minor'] + $data['accessorial_minor'];
            abort_unless($sell > 0, 422, 'Harga jual harus lebih dari nol.');
            DB::table('orders')->insert([...$data, 'id' => $id, 'tenant_id' => $store->tenantId, 'number' => 'OD-'.Str::ulid(), 'sell_minor' => $sell, 'status' => 'DRAFT', 'created_by' => $store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $item) {
                DB::table('order_items')->insert([...$item, 'id' => (string) Str::uuid(), 'tenant_id' => $store->tenantId, 'order_id' => $id]);
            }
            $store->event('order.created', $id, ['number' => $store->find('orders', $id)->number]);

            return $store->find('orders', $id);
        });

        return response()->json(['data' => $order], 201);
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
                $status = 'CONFIRMED';
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
