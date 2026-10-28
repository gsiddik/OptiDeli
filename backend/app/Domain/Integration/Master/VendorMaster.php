<?php

namespace App\Domain\Integration\Master;

use App\Domain\Integration\Fleet\FleetEventReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The vehicles and drivers of the tenant's vendors, for OptiRadar only (docs/integration/MASTER_DATA_SYNC.md, stage 4). A vendor's
 * vehicle may carry a GPS device or another IoT unit that has to be tracked in OptiRadar, so OptiRadar is told which vendor owns it.
 * OptiFleet is NOT told: the tenant does not maintain a vendor's vehicles. That is why these events have keys of their own
 * (`optideli.vendor_vehicle.upserted`, `optideli.vendor_driver.upserted`): only OptiRadar subscribes to them, while the own fleet goes out as
 * `optideli.vehicle.*` to OptiFleet. Only what the tenant has verified is published, again whenever it changes, with `active` so a deactivated
 * vehicle or driver can be told apart.
 */
final class VendorMaster
{
    public const VEHICLE = 'optideli.vendor_vehicle.upserted';

    public const DRIVER = 'optideli.vendor_driver.upserted';

    /** Queues a verified vehicle or driver of a vendor. @param 'VEHICLE'|'DRIVER' $type @return bool whether an event was queued */
    public static function publish(string $tenantId, string $type, string $id): bool
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $row = DB::table($type === 'VEHICLE' ? 'vehicles' : 'drivers')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $tenant?->optinexus_tenant_id || ! $row || $row->vendor_id === null || $row->verification_status !== 'VERIFIED') {
            return false;
        }
        $vendor = DB::table('vendors')->where('tenant_id', $tenantId)->where('id', $row->vendor_id)->first(['code', 'name']);
        $key = $type === 'VEHICLE' ? FleetEventReceiver::key($row->plate) : DriverMaster::key($row->license_number);
        if ($key === '' || ! $vendor) {
            return false;
        }

        $eventKey = $type === 'VEHICLE' ? self::VEHICLE : self::DRIVER;
        // Increases for every record even when two publishes share a millisecond or the clock steps back.
        $last = (int) DB::table('master_events')->where('tenant_id', $tenantId)->where('aggregate_id', $id)->where('event_key', $eventKey)->max(DB::raw("(payload::jsonb->>'version')::bigint"));
        $payload = [
            'key' => $key, 'version' => max((int) (microtime(true) * 1000), $last + 1), 'changed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'active' => (bool) $row->active, 'vendor' => ['code' => $vendor->code, 'name' => $vendor->name],
        ];
        if ($type === 'VEHICLE') {
            $carrier = $row->carrier_type_id ? DB::table('carrier_types')->where('tenant_id', $tenantId)->where('id', $row->carrier_type_id)->value('code') : null;
            $payload += ['registration_number' => $row->plate, 'tracker_id' => $row->tracker_id, 'transport' => [
                'carrier_type_code' => $carrier, 'capacity_g' => $row->capacity_g, 'length_mm' => $row->length_mm, 'width_mm' => $row->width_mm, 'height_mm' => $row->height_mm,
            ]];
        } else {
            $payload += ['license_number' => $row->license_number, 'name' => $row->name];
        }
        DB::table('master_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'event_key' => $eventKey, 'aggregate_id' => $id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        return true;
    }

    /** Queues every verified vendor vehicle and driver (first start-up of the sync, or after an outage). @return int queued */
    public static function publishAll(?string $tenantId = null): int
    {
        $count = 0;
        foreach (['VEHICLE' => 'vehicles', 'DRIVER' => 'drivers'] as $type => $table) {
            DB::table($table)->whereNotNull('vendor_id')->where('verification_status', 'VERIFIED')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('id')
                ->each(function ($r) use ($type, &$count) {
                    $count += self::publish($r->tenant_id, $type, $r->id) ? 1 : 0;
                });
        }

        return $count;
    }
}
