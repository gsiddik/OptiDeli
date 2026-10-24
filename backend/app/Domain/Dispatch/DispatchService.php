<?php

namespace App\Domain\Dispatch;

use App\Domain\Commercial\Margin;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DispatchService
{
    public function __construct(private FleetAvailability $fleet) {}

    public function create(TenantStore $store, array $data, string $key): object
    {
        return $store->idempotent('dispatch.plan', $key, $data, function () use ($store, $data) {
            $order = $store->find('orders', $data['order_id'], true);
            abort_unless($order->status === 'CONFIRMED', 422, 'Order harus dikonfirmasi sebelum perencanaan dispatch.');
            $vehicle = $store->find('vehicles', $data['vehicle_id'], true);
            $driver = $store->find('drivers', $data['driver_id'], true);
            $this->validateResources($store, $order, $vehicle, $driver, $data);
            $tenant = DB::table('tenants')->where('id', $store->tenantId)->firstOrFail();
            $stages = json_decode($tenant->stage_margin_bps ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $threshold = $stages[$order->stage] ?? $tenant->minimum_margin_bps;
            $cost = $data['vendor_base_minor'] + $data['vendor_accessorial_minor'];
            $level = Margin::requiredLevel($order->sell_minor, $cost, $threshold);
            abort_if($level > 0 && empty($data['reason_code']), 422, 'Reason code wajib untuk margin di bawah threshold.');
            $id = (string) Str::uuid();
            DB::table('dispatch_plans')->insert([...$data, 'id' => $id, 'tenant_id' => $store->tenantId, 'cost_minor' => $cost, 'required_level' => $level, 'threshold_bps' => $threshold, 'policy_version' => $tenant->policy_version, 'requested_by' => $store->actorId, 'status' => $level === 0 ? 'READY' : 'PENDING_APPROVAL', 'created_at' => now(), 'updated_at' => now()]);
            $store->event('dispatch.plan_created', $id, ['required_level' => $level, 'cost_minor' => $cost]);

            return $store->find('dispatch_plans', $id);
        });
    }

    public function approve(TenantStore $store, string $id, int $approvalLevel): object
    {
        return DB::transaction(function () use ($store, $id, $approvalLevel) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $plan = $store->find('dispatch_plans', $id, true);
            abort_unless($plan->status === 'PENDING_APPROVAL', 409, 'Rencana tidak menunggu persetujuan.');
            abort_if($plan->requested_by === $store->actorId, 403, 'Pemohon tidak boleh menyetujui permohonannya sendiri.');
            abort_unless($approvalLevel >= $plan->required_level, 403, 'Tingkat otorisasi tidak cukup.');
            $this->assertPolicy($store, $plan);
            $store->query('dispatch_plans')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $store->actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $store->event('dispatch.margin_approved', $id, ['required_level' => $plan->required_level, 'reason_code' => $plan->reason_code]);

            return $store->find('dispatch_plans', $id);
        }, 3);
    }

    public function confirm(TenantStore $store, string $id): object
    {
        return DB::transaction(function () use ($store, $id) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $plan = $store->find('dispatch_plans', $id, true);
            if ($plan->status === 'DISPATCHED') {
                return $store->query('trips')->where('plan_id', $id)->firstOrFail();
            }
            abort_unless(in_array($plan->status, ['READY', 'APPROVED'], true), 422, 'Dispatch terkunci: persetujuan margin belum lengkap.');
            $this->assertPolicy($store, $plan);
            $order = $store->find('orders', $plan->order_id, true);
            abort_unless($order->status === 'CONFIRMED', 409, 'Order tidak lagi tersedia untuk dispatch.');
            $vehicle = $store->find('vehicles', $plan->vehicle_id, true);
            $driver = $store->find('drivers', $plan->driver_id, true);
            $this->validateResources($store, $order, $vehicle, $driver, (array) $plan);
            $id = (string) Str::uuid();
            DB::table('trips')->insert(['id' => $id, 'tenant_id' => $store->tenantId, 'plan_id' => $plan->id, 'order_id' => $order->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => 'ASSIGNED', 'created_at' => now(), 'updated_at' => now()]);
            $store->query('orders')->where('id', $order->id)->update(['status' => 'DISPATCHED', 'updated_at' => now()]);
            $store->query('dispatch_plans')->where('id', $plan->id)->update(['status' => 'DISPATCHED', 'updated_at' => now()]);
            $store->event('trip.dispatched', $id, ['order_id' => $order->id, 'plan_id' => $plan->id]);

            return $store->find('trips', $id);
        }, 3);
    }

    private function assertPolicy(TenantStore $store, object $plan): void
    {
        $tenant = DB::table('tenants')->where('id', $store->tenantId)->firstOrFail();
        $order = $store->find('orders', $plan->order_id);
        $stage = json_decode($tenant->stage_margin_bps ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $threshold = $stage[$order->stage] ?? $tenant->minimum_margin_bps;
        abort_unless($tenant->policy_version === $plan->policy_version && $threshold === $plan->threshold_bps && Margin::requiredLevel($order->sell_minor, $plan->cost_minor, $threshold) === $plan->required_level, 409, 'Kebijakan margin berubah; buat rencana baru untuk persetujuan ulang.');
    }

    private function validateResources(TenantStore $store, object $order, object $vehicle, object $driver, array $data): void
    {
        $end = CarbonImmutable::parse($data['ends_at']);
        abort_unless(CarbonImmutable::parse($data['starts_at'])->greaterThanOrEqualTo(now()->subMinute()), 422, 'Jadwal mulai sudah lewat.');
        $this->fleet->assertReady($vehicle, $driver, $end, CarbonImmutable::parse($data['starts_at']));
        abort_if($store->query('trips')->where('active', true)->where(fn ($q) => $q->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))->exists(), 409, 'Kendaraan atau pengemudi telah dialokasikan.');
        $weight = 0;
        $volume = 0;
        foreach ($store->query('order_items')->where('order_id', $order->id)->get() as $item) {
            abort_if($item->hazmat_class !== null, 422, 'Pengiriman hazmat memerlukan modul keselamatan khusus yang belum tersedia.');
            $dimensions = [$item->length_mm, $item->width_mm, $item->height_mm];
            sort($dimensions);
            $box = [$vehicle->length_mm, $vehicle->width_mm, $vehicle->height_mm];
            sort($box);
            foreach ($dimensions as $i => $dimension) {
                abort_if($dimension > $box[$i], 422, 'Dimensi barang melampaui box kendaraan.');
            }
            $weight += $item->quantity * $item->unit_weight_g;
            $volume += $item->quantity * $item->length_mm * $item->width_mm * $item->height_mm;
        }
        abort_if($weight > $vehicle->capacity_g, 422, 'Berat muatan melebihi kapasitas kendaraan.');
        abort_if($volume > $vehicle->length_mm * $vehicle->width_mm * $vehicle->height_mm, 422, 'Volume muatan melebihi box kendaraan.');
    }
}
