<?php

namespace App\Services\Adapters\Fleet;

class OptiFleetAdapter implements FleetMaintenancePortInterface
{
    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        // Simulasi pemeriksaan M2M ke OptiFleet VMS Service
        $isMaintenanceMock = str_contains(strtoupper($vehicleId), 'SRV');

        return [
            'vehicle_id' => $vehicleId,
            'is_available' => ! $isMaintenanceMock,
            'status' => $isMaintenanceMock ? 'IN_MAINTENANCE' : 'ACTIVE',
            'driver' => 'optifleet',
            'reason' => $isMaintenanceMock ? 'Unit sedang dalam pengerjaan bengkel (OptiFleet WO).' : null,
        ];
    }
}
