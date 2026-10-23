<?php

namespace App\Services\Adapters\Fleet;

class FleetMaintenanceManager
{
    public static function create(): FleetMaintenancePortInterface
    {
        $driver = config('optideli.fleet.driver', 'local');

        return match ($driver) {
            'optifleet' => new OptiFleetAdapter(),
            'none' => new NullFleetAdapter(),
            default => new LocalFleetAdapter(),
        };
    }
}
