<?php

namespace App\Domain\Integration\Pickup;

use App\Domain\Shared\TenantStore;
use App\Domain\Spm\SpmService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pickup requests from OptiStash (docs/integration/PICKUP_REQUESTS.md). The event says where the goods are (a warehouse, never its
 * zones or bins) and what to collect. It does not say for whom or where to, so a request is an inbox record. It becomes an order with one
 * SPM in DRAFT when the warehouse is linked to a location of the tenant AND the tenant's defaults (customer, service, destination) are set,
 * or when a dispatcher supplies them. Nothing is priced here: the SPM is priced from the tariffs when it is planned.
 */
final class PickupRequests
{
    public const RECEIVED = 'optistash.pickup.requested';

    public const SOURCE = 'optistash';

    public const MAX_ITEMS = 500;

    /** @return 'created'|'converted'|'duplicate'|'ignored'|'invalid' */
    public static function apply(array $event): string
    {
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== self::SOURCE) {
            return 'ignored';
        }
        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->where('active', true)->first();
        if (! $tenant) {
            return 'ignored';
        }
        $eventId = (string) ($event['event_id'] ?? '');
        $clean = self::validated($event['data']['payload'] ?? null);
        if (! Str::isUuid($eventId) || $clean === null) {
            return 'invalid';
        }

        $id = (string) Str::uuid();
        $stored = DB::transaction(function () use ($tenant, $eventId, $clean, $id, $event) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            if (DB::table('pickup_requests')->where('tenant_id', $tenant->id)->where('source_system', self::SOURCE)->where('source_event_id', $eventId)->exists()) {
                return false;
            }
            $location = self::linkedLocation($tenant->id, $clean['warehouse']['code']);
            DB::table('pickup_requests')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'source_system' => self::SOURCE, 'source_event_id' => $eventId, 'shipment_number' => $clean['shipment_number'],
                'warehouse' => json_encode($clean['warehouse'], JSON_THROW_ON_ERROR), 'items' => json_encode($clean['items'], JSON_THROW_ON_ERROR),
                'status' => 'RECEIVED', 'warehouse_location_id' => $location?->id,
                'occurred_at' => self::time($event['occurred_at'] ?? null), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
        if (! $stored) {
            return 'duplicate';
        }

        return self::autoConvert($tenant->id, $id) ? 'converted' : 'created';
    }

    /** The tenant location tied to a warehouse code, if an operator linked one. */
    public static function linkedLocation(string $tenantId, string $warehouseCode): ?object
    {
        return DB::table('locations')->where('tenant_id', $tenantId)->where('external_ref', self::ref($warehouseCode))->where('active', true)->first();
    }

    public static function ref(string $warehouseCode): string
    {
        return self::SOURCE.':'.mb_strtoupper(trim($warehouseCode));
    }

    /**
     * What is safe to keep from the payload: lengths bounded, quantities positive whole numbers. Null when the event cannot be used at all.
     *
     * @return array{shipment_number:string,warehouse:array<string,?string>,items:list<array<string,mixed>>}|null
     */
    private static function validated(mixed $p): ?array
    {
        if (! is_array($p)) {
            return null;
        }
        $text = fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $shipment = $text($p['shipment_number'] ?? null, 60);
        $w = is_array($p['warehouse'] ?? null) ? $p['warehouse'] : [];
        $code = $text($w['code'] ?? null, 40);
        $items = $p['items'] ?? null;
        if ($shipment === null || $code === null || ! is_array($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            return null;
        }
        $clean = [];
        foreach ($items as $item) {
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            $productCode = is_array($item) ? $text($item['product_code'] ?? null, 60) : null;
            $name = is_array($item) ? $text($item['product_name'] ?? null, 200) : null;
            if ($productCode === null || $name === null || ! is_int($quantity) || $quantity < 1 || $quantity > 99999999) {
                return null;
            }
            $clean[] = ['product_code' => $productCode, 'product_name' => $name, 'quantity' => $quantity, 'uom' => $text($item['uom'] ?? null, 20)];
        }

        return [
            'shipment_number' => $shipment,
            'warehouse' => ['code' => $code, 'name' => $text($w['name'] ?? null, 200), 'address' => $text($w['address'] ?? null, 1000), 'city' => $text($w['city'] ?? null, 100)],
            'items' => $clean,
        ];
    }

    private static function time(mixed $v): ?string
    {
        try {
            return is_string($v) ? CarbonImmutable::parse($v)->toIso8601String() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ settings

    public static function settings(string $tenantId): object
    {
        return DB::table('pickup_settings')->where('tenant_id', $tenantId)->first()
            ?? (object) ['tenant_id' => $tenantId, 'auto_order' => false, 'customer_id' => null, 'service_id' => null, 'destination_location_id' => null, 'stage' => 'LAST_MILE', 'configured_by' => null];
    }

    public static function saveSettings(TenantStore $store, array $in): object
    {
        $row = [
            'auto_order' => (bool) ($in['auto_order'] ?? false), 'customer_id' => $in['customer_id'] ?? null, 'service_id' => $in['service_id'] ?? null,
            'destination_location_id' => $in['destination_location_id'] ?? null, 'stage' => $in['stage'] ?? 'LAST_MILE',
        ];
        foreach (['customer_id' => 'customers', 'service_id' => 'services', 'destination_location_id' => 'locations'] as $column => $table) {
            if ($row[$column] !== null && ! $store->query($table)->where('id', $row[$column])->where('active', true)->exists()) {
                throw ValidationException::withMessages([$column => 'Data master tidak ditemukan atau tidak aktif.']);
            }
        }
        if ($row['auto_order'] && in_array(null, [$row['customer_id'], $row['service_id'], $row['destination_location_id']], true)) {
            throw ValidationException::withMessages(['auto_order' => 'Order otomatis membutuhkan pelanggan, layanan, dan tujuan bawaan.']);
        }
        DB::transaction(function () use ($store, $row) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            DB::table('pickup_settings')->upsert([['tenant_id' => $store->tenantId, ...$row, 'configured_by' => $store->actorId, 'updated_at' => now()]], ['tenant_id'], array_merge(array_keys($row), ['configured_by', 'updated_at']));
            $store->event('pickup.settings_saved', $store->tenantId, $row);
        }, 3);
        self::autoConvertPending($store->tenantId);

        return self::settings($store->tenantId);
    }

    // ------------------------------------------------------------------ reading

    public static function list(string $tenantId, ?string $status, int $page, int $perPage = 25): array
    {
        $q = DB::table('pickup_requests')->where('tenant_id', $tenantId)->when($status, fn ($q) => $q->where('status', $status));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('received_at')->orderBy('id')->forPage($page, $perPage)->get()->map(fn ($r) => self::shape($r, false))->all();

        return ['data' => $rows, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public static function show(TenantStore $store, string $id): array
    {
        return self::shape($store->find('pickup_requests', $id), true);
    }

    private static function shape(object $r, bool $detail): array
    {
        $warehouse = json_decode($r->warehouse, true);
        $items = json_decode($r->items, true);
        $out = [
            'id' => $r->id, 'shipment_number' => $r->shipment_number, 'status' => $r->status, 'warehouse' => $warehouse, 'warehouse_linked' => $r->warehouse_location_id !== null,
            'lines' => count($items), 'total_quantity' => array_sum(array_column($items, 'quantity')), 'received_at' => $r->received_at, 'auto_failure' => $r->auto_failure,
            'order_id' => $r->order_id, 'transport_order_id' => $r->transport_order_id,
        ];
        if ($detail) {
            $out += [
                'items' => $items, 'reason' => $r->reason, 'handled_at' => $r->handled_at, 'occurred_at' => $r->occurred_at,
                'warehouse_location' => $r->warehouse_location_id ? DB::table('locations')->where('tenant_id', $r->tenant_id)->where('id', $r->warehouse_location_id)->first(['id', 'code', 'name']) : null,
                'order_number' => $r->order_id ? DB::table('orders')->where('tenant_id', $r->tenant_id)->where('id', $r->order_id)->value('number') : null,
                'spm_number' => $r->transport_order_id ? DB::table('transport_orders')->where('tenant_id', $r->tenant_id)->where('id', $r->transport_order_id)->value('number') : null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ handling

    /** Ties the warehouse of a request to a warehouse location of the tenant (an operator's decision, never guessed). */
    public static function linkWarehouse(TenantStore $store, string $requestId, string $locationId): array
    {
        $code = DB::transaction(function () use ($store, $requestId, $locationId) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $request = $store->find('pickup_requests', $requestId, true);
            $location = $store->find('locations', $locationId, true);
            abort_unless($location->active && $location->type === 'WAREHOUSE', 422, 'Pilih lokasi aktif bertipe Gudang.');
            $code = json_decode($request->warehouse, true)['code'];
            $ref = self::ref($code);
            abort_if($location->external_ref !== null && $location->external_ref !== $ref, 422, 'Lokasi ini sudah tertaut ke gudang lain.');
            abort_if($store->query('locations')->where('external_ref', $ref)->where('id', '!=', $locationId)->exists(), 422, 'Gudang ini sudah tertaut ke lokasi lain.');
            $store->query('locations')->where('id', $locationId)->update(['external_ref' => $ref, 'updated_at' => now()]);
            foreach ($store->query('pickup_requests')->where('status', 'RECEIVED')->whereNull('warehouse_location_id')->get() as $pending) {
                if (mb_strtoupper(json_decode($pending->warehouse, true)['code']) === mb_strtoupper($code)) {
                    $store->query('pickup_requests')->where('id', $pending->id)->update(['warehouse_location_id' => $locationId, 'updated_at' => now()]);
                }
            }
            $store->event('pickup.warehouse_linked', $requestId, ['warehouse' => $code, 'location' => $location->name]);

            return $code;
        }, 3);
        self::autoConvertPending($store->tenantId);

        return self::show($store, $requestId);
    }

    /**
     * Makes the order and the SPM. $in: customer_id, service_id, destination_location_id, stage, optional planned_pickup_at and notes.
     * Everything or nothing: a refusal leaves the request RECEIVED.
     */
    public static function convert(TenantStore $store, string $requestId, array $in, bool $auto = false): array
    {
        DB::transaction(function () use ($store, $requestId, $in, $auto) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $request = $store->find('pickup_requests', $requestId, true);
            abort_unless($request->status === 'RECEIVED', 409, 'Permintaan ini sudah diproses.');
            $warehouse = json_decode($request->warehouse, true);
            $origin = self::linkedLocation($store->tenantId, $warehouse['code']);
            abort_unless($origin, 422, 'Tautkan gudang ke lokasi OptiDeli lebih dulu.');
            $active = fn (string $table, ?string $id) => $id !== null ? $store->query($table)->where('id', $id)->where('active', true)->first() : null;
            $customer = $active('customers', $in['customer_id'] ?? null);
            $service = $active('services', $in['service_id'] ?? null);
            $destination = $active('locations', $in['destination_location_id'] ?? null);
            foreach (['customer_id' => $customer, 'service_id' => $service, 'destination_location_id' => $destination] as $field => $found) {
                if (! $found) {
                    throw ValidationException::withMessages([$field => 'Wajib diisi dengan data master yang aktif.']);
                }
            }
            $stage = $in['stage'] ?? 'LAST_MILE';
            abort_unless(in_array($stage, ['FIRST_MILE', 'MIDDLE_MILE', 'LAST_MILE', 'E2E'], true), 422, 'Tahap tidak dikenal.');

            $orderId = (string) Str::uuid();
            $orderNumber = 'OD-'.Str::ulid();
            // Not priced here (the SPM is priced from the tariffs when planned): the order carries the commercial amounts as zero.
            DB::table('orders')->insert([
                'id' => $orderId, 'tenant_id' => $store->tenantId, 'number' => $orderNumber, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
                'reference' => mb_substr($request->shipment_number, 0, 100), 'stage' => $stage, 'service_id' => $service->id,
                'origin_location_id' => $origin->id, 'origin' => mb_substr($origin->name, 0, 300),
                'destination_location_id' => $destination->id, 'destination' => mb_substr($destination->name, 0, 300),
                'currency' => 'IDR', 'base_price_minor' => 0, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'sell_minor' => 0,
                'status' => 'CONFIRMED', 'created_by' => $store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $store->event('order.created', $orderId, ['number' => $orderNumber, 'source' => 'pickup_request']);
            $store->event('order.confirmed', $orderId);

            (new SpmService($store))->create($orderId, [
                'reference' => mb_substr($request->shipment_number, 0, 120), 'notes' => mb_substr('Permintaan jemput OptiStash '.$request->shipment_number.($in['notes'] ?? '' ? '. '.$in['notes'] : ''), 0, 2000),
                'planned_pickup_at' => $in['planned_pickup_at'] ?? null,
                'lines' => self::lines($store, json_decode($request->items, true)),
            ]);
            $spm = $store->query('transport_orders')->where('order_id', $orderId)->first(['id', 'number']);
            $spmId = $spm->id;

            $store->query('pickup_requests')->where('id', $requestId)->update([
                'status' => 'CONVERTED', 'warehouse_location_id' => $origin->id, 'order_id' => $orderId, 'transport_order_id' => $spmId, 'auto_failure' => null, 'reason' => null,
                'handled_by' => $store->actorId, 'handled_at' => now(), 'updated_at' => now(),
            ]);
            $store->event('pickup.converted', $requestId, ['shipment' => $request->shipment_number, 'order' => $orderNumber, 'spm' => $spm->number, 'auto' => $auto]);
        }, 3);

        return self::show($store, $requestId);
    }

    /** SPM lines of the goods to collect: the goods master by code and the unit by code when they exist, else the name as free text. */
    private static function lines(TenantStore $store, array $items): array
    {
        $goods = $store->query('goods')->where('active', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id])->all();
        $units = $store->query('units')->where('active', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id])->all();
        $lines = [];
        foreach ($items as $item) {
            $lines[] = array_filter([
                'goods_id' => $goods[mb_strtoupper($item['product_code'])] ?? null, 'material' => $item['product_name'], 'quantity' => $item['quantity'],
                'unit_id' => $item['uom'] ? ($units[mb_strtoupper($item['uom'])] ?? null) : null, 'notes' => mb_substr('SKU '.$item['product_code'].($item['uom'] ? ' · '.$item['uom'] : ''), 0, 300),
            ], fn ($v) => $v !== null);
        }

        return $lines;
    }

    public static function reject(TenantStore $store, string $requestId, string $reason): array
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');
        DB::transaction(function () use ($store, $requestId, $reason) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $request = $store->find('pickup_requests', $requestId, true);
            abort_unless($request->status === 'RECEIVED', 409, 'Permintaan ini sudah diproses.');
            $store->query('pickup_requests')->where('id', $requestId)->update(['status' => 'REJECTED', 'reason' => trim($reason), 'handled_by' => $store->actorId, 'handled_at' => now(), 'updated_at' => now()]);
            $store->event('pickup.rejected', $requestId, ['shipment' => $request->shipment_number, 'reason' => trim($reason)]);
        }, 3);

        return self::show($store, $requestId);
    }

    // ------------------------------------------------------------------ automatic orders

    /** Whether the defaults are complete and switched on; the orders are then made in the name of whoever saved them. */
    private static function autoStore(string $tenantId): ?array
    {
        $s = self::settings($tenantId);
        if (! $s->auto_order || ! $s->customer_id || ! $s->service_id || ! $s->destination_location_id || ! $s->configured_by) {
            return null;
        }

        return [new TenantStore($tenantId, (int) $s->configured_by), ['customer_id' => $s->customer_id, 'service_id' => $s->service_id, 'destination_location_id' => $s->destination_location_id, 'stage' => $s->stage]];
    }

    private static function autoConvert(string $tenantId, string $requestId): bool
    {
        $auto = self::autoStore($tenantId);
        if ($auto === null) {
            return false;
        }
        [$store, $defaults] = $auto;
        try {
            self::convert($store, $requestId, $defaults, auto: true);

            return true;
        } catch (\Throwable $e) {
            // The request stays in the inbox for a person, with the reason shown.
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : ($e instanceof HttpException ? $e->getMessage() : 'Gagal membuat order otomatis.');
            DB::table('pickup_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->where('status', 'RECEIVED')->update(['auto_failure' => mb_substr($message, 0, 300), 'updated_at' => now()]);

            return false;
        }
    }

    /** After a warehouse is linked or the defaults are saved: requests that were waiting for exactly that are handled now. */
    public static function autoConvertPending(string $tenantId): int
    {
        if (self::autoStore($tenantId) === null) {
            return 0;
        }
        $done = 0;
        foreach (DB::table('pickup_requests')->where('tenant_id', $tenantId)->where('status', 'RECEIVED')->whereNotNull('warehouse_location_id')->orderBy('received_at')->limit(100)->pluck('id') as $id) {
            $done += self::autoConvert($tenantId, $id) ? 1 : 0;
        }

        return $done;
    }
}
