<?php

namespace App\Domain\Dispatch;

use App\Domain\Integration\Fleet\FleetAvailabilityClient;
use App\Domain\Integration\Fleet\FleetEventReceiver;
use App\Domain\Integration\Fleet\FleetUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle readiness when OptiFleet is the fleet system (FLEET_SERVICE_DRIVER=optifleet). OptiDeli's own checks (documents, driver)
 * always run first. Then OptiFleet decides about the vehicle itself:
 *  - the tenant has an OptiFleet credential: OptiFleet is asked for the trip window (live, authoritative, includes planned
 *    workshop windows). If it cannot answer, the last availability event OptiFleet announced is used; with none, the dispatch is
 *    refused unless OPTIFLEET_FAIL_OPEN=true;
 *  - no credential: only the announced availability events are used (a vehicle OptiFleet put ON_HOLD / IN_USE is refused).
 * A vehicle OptiFleet does not know is not blocked by OptiFleet (it is OptiDeli's own).
 */
final class OptiFleetAvailability implements FleetAvailability
{
    public function __construct(private LocalFleetAvailability $local, private FleetAvailabilityClient $fleet) {}

    public function assertReady(object $vehicle, object $driver, CarbonImmutable $end, ?CarbonImmutable $start = null): void
    {
        $this->local->assertReady($vehicle, $driver, $end, $start);

        $from = ($start ?? CarbonImmutable::now())->max(CarbonImmutable::now()->subMinute());
        $announced = $this->announced($vehicle);

        if ($this->fleet->configuredFor($vehicle->tenant_id)) {
            try {
                $answer = $this->fleet->ask($vehicle->tenant_id, $vehicle->plate, $from, $end->max($from));
            } catch (FleetUnavailable) {
                $this->fallBack($announced);

                return;
            }
            if ($answer !== null && ! $answer['available']) {
                abort(422, 'Kendaraan tidak tersedia menurut OptiFleet: '.$this->describe($answer['blockers']).'.');
            }

            return;
        }

        $this->fallBack($announced, false);
    }

    private function announced(object $vehicle): ?object
    {
        return DB::table('fleet_vehicle_states')->where('tenant_id', $vehicle->tenant_id)->where('registration_key', FleetEventReceiver::key($vehicle->plate))->first();
    }

    /** OptiFleet could not be asked (or there is no credential): use what it announced. */
    private function fallBack(?object $announced, bool $mustKnow = true): void
    {
        if ($announced) {
            abort_if($announced->operational_status !== 'AVAILABLE', 422, 'Kendaraan tidak tersedia menurut OptiFleet: status '.$announced->operational_status.'.');

            return;
        }
        abort_if($mustKnow && ! config('optideli.fleet.fail_open'), 503, 'Ketersediaan kendaraan tidak dapat diverifikasi ke OptiFleet; coba lagi.');
    }

    /** @param  array<int, array<string, mixed>>  $blockers */
    private function describe(array $blockers): string
    {
        return collect($blockers)->map(fn ($b) => match ($b['code'] ?? '') {
            'SERVICE_SCHEDULED' => 'servis terjadwal '.($b['work_order'] ?? '').' ('.($b['starts_at'] ?? '?').' - '.($b['ends_at'] ?? '?').')',
            'VEHICLE_ON_HOLD' => 'sedang ditahan (bengkel/kerusakan)',
            'VEHICLE_IN_USE' => 'sedang dipakai',
            default => (string) ($b['code'] ?? 'tidak tersedia'),
        })->implode('; ') ?: 'tidak tersedia';
    }
}
