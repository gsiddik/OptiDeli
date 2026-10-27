<?php

namespace App\Domain\Integration\Master;

use App\Domain\Integration\Fleet\FleetEventReceiver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tells the other platforms who drives a vehicle (docs/integration/MASTER_DATA_SYNC.md, stage 4). OptiDeli owns the drivers and the
 * allocation of a driver to a vehicle; OptiFleet only reads the history per vehicle. A fact is queued when a trip leaves (the real
 * use, so a trip that is planned and cancelled never counts) and when a day of a vehicle contract is recorded as worked by a driver.
 * Only the tenant's own vehicles and drivers are published: a vendor's are not the tenant's people, and a tenant that is not linked
 * to OptiNexus has nobody to tell. The driver is a snapshot (name, phone, licence number masked to its last four characters); the
 * licence number, date of birth and address never leave OptiDeli.
 */
final class DriverAssignments
{
    public const PUBLISHED = 'optideli.vehicle.driver_assigned';

    public const SPM_TRIP = 'SPM_TRIP';

    public const ORDER_TRIP = 'ORDER_TRIP';

    public const ATTENDANCE = 'ATTENDANCE';

    /** Queues "this driver took this vehicle at this time". @return bool whether an event was queued */
    public static function publish(string $tenantId, string $vehicleId, string $driverId, CarbonInterface $at, string $type, string $referenceId, string $number): bool
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $vehicle = DB::table('vehicles')->where('tenant_id', $tenantId)->where('id', $vehicleId)->first();
        $driver = DB::table('drivers')->where('tenant_id', $tenantId)->where('id', $driverId)->first();
        if (! $tenant?->optinexus_tenant_id || ! $vehicle || ! $driver || $vehicle->vendor_id !== null || $driver->vendor_id !== null) {
            return false;
        }
        $key = FleetEventReceiver::key($vehicle->plate);
        if ($key === '') {
            return false;
        }

        DB::table('master_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'event_key' => self::PUBLISHED, 'aggregate_id' => $vehicleId,
            'payload' => json_encode([
                'key' => $key, 'registration_number' => $vehicle->plate, 'assigned_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
                'reference' => ['type' => $type, 'id' => $referenceId, 'number' => $number],
                'driver' => ['name' => $driver->name, 'phone' => $driver->phone, 'license_masked' => self::mask($driver->license_number)],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return true;
    }

    /** "B1234567" -> "****4567": enough to tell two drivers apart, not enough to be the licence. */
    public static function mask(string $licenseNumber): string
    {
        $clean = preg_replace('/[^A-Z0-9]/', '', Str::upper($licenseNumber)) ?? '';

        return '****'.substr($clean, -4);
    }
}
