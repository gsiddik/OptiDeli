<?php

namespace App\Domain\Integration\Fleet;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Asks OptiFleet whether a vehicle can take a trip in a window (GET /api/v1/integration/availability, docs in OptiFleet
 * docs/integration/VEHICLE_AVAILABILITY.md). The credential is the tenant's own and is only ever sent to the configured OptiFleet.
 */
class FleetAvailabilityClient
{
    public function configuredFor(string $tenantId): bool
    {
        return (string) config('optideli.fleet.optifleet_url') !== ''
            && DB::table('tenants')->where('id', $tenantId)->whereNotNull('fleet_availability_token')->exists();
    }

    /**
     * @return array{available: bool, blockers: array<int, array<string, mixed>>}|null null when OptiFleet does not know the vehicle
     *
     * @throws FleetUnavailable when OptiFleet cannot give a usable answer
     */
    public function ask(string $tenantId, string $registrationNumber, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $stored = DB::table('tenants')->where('id', $tenantId)->value('fleet_availability_token');
        if (! $stored) {
            throw new FleetUnavailable('Kredensial OptiFleet belum dipasang untuk tenant ini.');
        }

        try {
            $token = Crypt::decryptString($stored);
            $response = Http::acceptJson()->withToken($token)->timeout((int) config('optideli.fleet.timeout_seconds'))
                ->get(config('optideli.fleet.optifleet_url').'/api/v1/integration/availability', [
                    'registration_number' => $registrationNumber, 'from' => $from->utc()->toIso8601String(), 'to' => $to->utc()->toIso8601String(),
                ]);
        } catch (\Throwable $e) {
            throw new FleetUnavailable('OptiFleet tidak dapat dihubungi.', previous: $e);
        }

        if ($response->status() === 404 && $response->json('code') === 'VEHICLE_NOT_FOUND') {
            return null;
        }
        if (! $response->successful() || ! is_bool($response->json('data.available')) || ! is_array($response->json('data.blockers'))) {
            throw new FleetUnavailable('OptiFleet memberi jawaban yang tidak dapat dipakai ('.$response->status().').');
        }

        return ['available' => $response->json('data.available'), 'blockers' => $response->json('data.blockers')];
    }
}
