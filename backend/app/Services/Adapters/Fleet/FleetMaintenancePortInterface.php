<?php

namespace App\Services\Adapters\Fleet;

interface FleetMaintenancePortInterface
{
    /**
     * Memvalidasi kesiapan unit armada dari pekerjaan bengkel / maintenance.
     *
     * @return array{
     *   vehicle_id: string,
     *   is_available: bool,
     *   status: string,
     *   driver: string,
     *   reason: ?string
     * }
     */
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array;
}
