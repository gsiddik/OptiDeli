<?php

namespace App\Services\Adapters\Fleet;

class NullFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => true,
            'status' => 'UNCHECKED',
            'driver' => 'none',
            'reason' => 'Pengecekan servis armada dinonaktifkan (Driver: none).',
        ];
    }
}
