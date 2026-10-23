<?php

namespace App\Services\Adapters\Fleet;

class LocalFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => true,
            'status' => 'ACTIVE',
            'driver' => 'local',
            'reason' => null,
        ];
    }
}
