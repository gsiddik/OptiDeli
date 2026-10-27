<?php

namespace App\Domain\Integration\Master;

use App\Domain\Integration\Fleet\FleetEventReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The vehicle master data shared with the other platforms (docs/integration/MASTER_DATA_SYNC.md). OptiDeli owns the transport
 * group (carrier type, capacity, box size) and publishes it for the tenant's own fleet; vendor vehicles are never published.
 * It takes the asset group (brand, model, year, VIN) from OptiFleet. A vehicle OptiFleet announces that OptiDeli does not know
 * becomes a transport draft: it exists, but cannot be dispatched until its transport data is completed here.
 * What is applied from another platform is never published again, so two platforms cannot echo each other.
 */
final class VehicleMaster
{
    public const PUBLISHED = 'optideli.vehicle.upserted';

    public const RECEIVED = 'optifleet.vehicle.upserted';

    /** Queues the vehicle's transport data for the other platforms. Does nothing for an unlinked tenant or a vendor's vehicle. */
    public static function publish(string $tenantId, string $vehicleId): bool
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $vehicle = DB::table('vehicles')->where('tenant_id', $tenantId)->where('id', $vehicleId)->first();
        if (! $tenant?->optinexus_tenant_id || ! $vehicle || $vehicle->vendor_id !== null) {
            return false;
        }

        $carrier = $vehicle->carrier_type_id ? DB::table('carrier_types')->where('tenant_id', $tenantId)->where('id', $vehicle->carrier_type_id)->value('code') : null;
        DB::table('master_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'event_key' => self::PUBLISHED, 'aggregate_id' => $vehicleId,
            'payload' => json_encode([
                'key' => FleetEventReceiver::key($vehicle->plate), 'registration_number' => $vehicle->plate,
                'version' => (int) (microtime(true) * 1000), 'changed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'transport' => [
                    'carrier_type_code' => $carrier ?? $vehicle->type, 'capacity_g' => $vehicle->capacity_g,
                    'length_mm' => $vehicle->length_mm, 'width_mm' => $vehicle->width_mm, 'height_mm' => $vehicle->height_mm,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return true;
    }

    /** Queues every own-fleet vehicle (first start-up of the sync, or after an outage). @return int vehicles queued */
    public static function publishAll(?string $tenantId = null): int
    {
        $count = 0;
        DB::table('vehicles')->whereNull('vendor_id')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('id')->each(function ($v) use (&$count) {
            $count += self::publish($v->tenant_id, $v->id) ? 1 : 0;
        });

        return $count;
    }

    /** @return 'applied'|'created'|'stale'|'ignored' */
    public function apply(array $event): string
    {
        $payload = $event['data']['payload'] ?? null;
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== 'optifleet' || ! is_array($payload)) {
            return 'ignored';
        }
        $plate = trim((string) ($payload['registration_number'] ?? ''));
        $key = FleetEventReceiver::key($plate);
        $version = $payload['version'] ?? null;
        $asset = $payload['asset'] ?? null;
        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->first();
        if ($key === '' || ! is_int($version) || ! is_array($asset) || ! $tenant) {
            return 'ignored';
        }

        return DB::transaction(function () use ($tenant, $key, $plate, $version, $asset) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $seen = DB::table('master_versions')->where(['tenant_id' => $tenant->id, 'registration_key' => $key, 'source' => 'OPTIFLEET'])->value('version');
            if ($seen !== null && $seen >= $version) {
                return 'stale';
            }
            DB::table('master_versions')->upsert([['tenant_id' => $tenant->id, 'registration_key' => $key, 'source' => 'OPTIFLEET', 'version' => $version]], ['tenant_id', 'registration_key', 'source'], ['version']);

            $values = [
                'brand' => $this->text($asset['brand'] ?? null, 100), 'model' => $this->text($asset['model'] ?? null, 100),
                'vehicle_year' => is_int($asset['year'] ?? null) && $asset['year'] >= 1900 && $asset['year'] <= 2100 ? $asset['year'] : null,
                'vin' => $this->text($asset['vin'] ?? null, 40), 'updated_at' => now(),
            ];

            $existing = DB::table('vehicles')->where('tenant_id', $tenant->id)->whereRaw("regexp_replace(upper(plate), '[^A-Z0-9]', '', 'g') = ?", [$key])->first();
            if ($existing) {
                if ($existing->vendor_id !== null) {
                    return 'ignored'; // a vendor's vehicle is not the tenant's asset
                }
                DB::table('vehicles')->where('id', $existing->id)->update($values);

                return 'applied';
            }

            $id = (string) Str::uuid();
            DB::table('vehicles')->insert($values + [
                'id' => $id, 'tenant_id' => $tenant->id, 'plate' => $plate, 'status' => 'AVAILABLE', 'source' => 'OPTIFLEET',
                'transport_complete' => false, 'created_at' => now(),
            ]);

            return 'created';
        });
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }
}
