<?php

namespace App\Domain\Dispatch;

use Carbon\CarbonImmutable;

interface FleetAvailability
{
    public function assertReady(object $vehicle, object $driver, CarbonImmutable $end): void;
}
