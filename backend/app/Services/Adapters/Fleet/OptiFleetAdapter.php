<?php

namespace App\Services\Adapters\Fleet;

use App\Domain\Integration\Fleet\FleetAvailabilityClient;
use App\Domain\Integration\Fleet\FleetEventReceiver;
use App\Domain\Integration\Fleet\FleetUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Readiness of a vehicle (by registration number) as OptiFleet sees it right now: the live query when the tenant has an
 * OptiFleet credential, otherwise the last availability event OptiFleet announced. The dispatch itself uses the same sources
 * (App\Domain\Dispatch\OptiFleetAvailability); this is the read-only lookup behind GET /fleet/assets/{plate}/readiness.
 */
class OptiFleetAdapter implements FleetMaintenancePortInterface
{
    public function __construct(private ?FleetAvailabilityClient $client = null) {}

    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        $client = $this->client ?? app(FleetAvailabilityClient::class);

        if ($client->configuredFor($tenantId)) {
            try {
                $now = CarbonImmutable::now();
                $answer = $client->ask($tenantId, $vehicleId, $now, $now);

                return $this->result($vehicleId, $answer === null ? true : $answer['available'], $answer === null ? 'UNKNOWN' : ($answer['available'] ? 'AVAILABLE' : 'UNAVAILABLE'), $answer['blockers'] ?? []);
            } catch (FleetUnavailable) {
                // fall through to what OptiFleet announced
            }
        }

        $state = DB::table('fleet_vehicle_states')->where('tenant_id', $tenantId)->where('registration_key', FleetEventReceiver::key($vehicleId))->first();

        return $state
            ? $this->result($vehicleId, $state->operational_status === 'AVAILABLE', $state->operational_status, [], 'event')
            : $this->result($vehicleId, true, 'UNKNOWN', [], 'none');
    }

    /** @param  array<int, array<string, mixed>>  $blockers */
    private function result(string $vehicleId, bool $available, string $status, array $blockers, string $source = 'live'): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => $available,
            'status' => $status,
            'driver' => 'optifleet',
            'source' => $source,
            'reason' => $available ? null : (collect($blockers)->pluck('code')->implode(', ') ?: 'Tidak tersedia menurut OptiFleet.'),
        ];
    }
}
