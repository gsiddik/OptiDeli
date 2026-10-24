<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Shared\TenantStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        return response()->json($q->orderByDesc('created_at')->orderBy('id')->paginate(25));
    }

    public function show(Request $request, string $id)
    {
        $store = self::storeFor($request);
        $order = $store->find('orders', $id);
        $order->items = $store->query('order_items')->where('order_id', $id)->get();
        $order->plans = $store->query('dispatch_plans')->where('order_id', $id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $order]);
    }

    public function create(Request $request)
    {
        self::writeAccess($request);
        $money = 'required|integer|min:0|max:1000000000000';
        $data = $request->validate([
            'customer_name' => 'required|string|max:200', 'reference' => 'nullable|string|max:100',
            'stage' => ['required', Rule::in(['FIRST_MILE', 'MIDDLE_MILE', 'LAST_MILE', 'E2E'])],
            'origin' => 'required|string|max:300', 'destination' => 'required|string|max:300',
            'currency' => ['required', Rule::in(['IDR'])], 'base_price_minor' => $money, 'surcharge_minor' => $money, 'accessorial_minor' => $money,
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:description,quantity,unit_weight_g,length_mm,width_mm,height_mm,stackable,hazmat_class',
            'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.unit_weight_g' => 'required|integer|min:1|max:100000000',
            'items.*.length_mm' => 'required|integer|min:1|max:20000', 'items.*.width_mm' => 'required|integer|min:1|max:20000', 'items.*.height_mm' => 'required|integer|min:1|max:20000',
            'items.*.stackable' => 'required|boolean', 'items.*.hazmat_class' => 'nullable|string|max:20',
        ]);
        $store = self::storeFor($request);
        $order = $store->idempotent('order.create', self::key($request), $data, function () use ($data, $store) {
            $id = (string) Str::uuid();
            $items = $data['items'];
            unset($data['items']);
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
