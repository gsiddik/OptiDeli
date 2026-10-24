<?php

namespace App\Domain\Integration\Fleet;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies an optifleet.vehicle.availability_changed event to the projection. The tenant is the one linked to the OptiNexus tenant
 * of the event (an unlinked tenant is ignored); an older event never overwrites a newer state, and a repeat of the same event
 * changes nothing, so delivery order and retries cannot corrupt it.
 */
class FleetEventReceiver
{
    public const EVENT = 'optifleet.vehicle.availability_changed';

    private const STATUSES = ['AVAILABLE', 'IN_USE', 'ON_HOLD'];

    /** @return 'applied'|'stale'|'ignored' */
    public function apply(array $event): string
    {
        $payload = $event['data']['payload'] ?? null;
        $plate = is_array($payload) ? (string) ($payload['registration_number'] ?? '') : '';
        $status = is_array($payload) ? (string) ($payload['operational_status'] ?? '') : '';

        if (($event['event_type'] ?? null) !== self::EVENT || ($event['source_system'] ?? null) !== 'optifleet'
            || $plate === '' || ! in_array($status, self::STATUSES, true) || ! Str::isUuid((string) ($event['event_id'] ?? ''))) {
            return 'ignored';
        }

        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->first();
        if (! $tenant) {
            return 'ignored';
        }

        $changedAt = CarbonImmutable::parse((string) ($payload['changed_at'] ?? $event['occurred_at'] ?? now()));
        $key = self::key($plate);

        return DB::transaction(function () use ($tenant, $event, $payload, $plate, $status, $changedAt, $key) {
            $current = DB::table('fleet_vehicle_states')->where('tenant_id', $tenant->id)->where('registration_key', $key)->lockForUpdate()->first();
            if ($current && CarbonImmutable::parse($current->changed_at)->greaterThan($changedAt)) {
                return 'stale';
            }

            $values = [
                'registration_number' => $plate, 'operational_status' => $status, 'fleet_status' => $payload['status'] ?? null,
                'fleet_vehicle_id' => Str::isUuid((string) ($payload['vehicle_id'] ?? '')) ? $payload['vehicle_id'] : null,
                'changed_at' => $changedAt, 'last_event_id' => $event['event_id'], 'updated_at' => now(),
            ];
            if ($current) {
                DB::table('fleet_vehicle_states')->where('id', $current->id)->update($values);
            } else {
                try {
                    DB::table('fleet_vehicle_states')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'registration_key' => $key, 'created_at' => now()]);
                } catch (UniqueConstraintViolationException) {
                    return 'stale'; // a concurrent delivery for the same vehicle won; the newer one is applied by its own retry if needed
                }
            }

            return 'applied';
        });
    }

    /** Plates are compared without case, spaces or dashes: "B 1234 XY" and "b-1234-xy" are the same vehicle. */
    public static function key(string $registrationNumber): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper($registrationNumber)) ?? '';
    }
}
