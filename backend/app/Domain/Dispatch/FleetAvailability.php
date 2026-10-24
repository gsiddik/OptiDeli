<?php

namespace App\Domain\Dispatch;

use Carbon\CarbonImmutable;

interface FleetAvailability
{
    /** @param  CarbonImmutable|null  $start  when the trip begins; defaults to now */
    public function assertReady(object $vehicle, object $driver, CarbonImmutable $end, ?CarbonImmutable $start = null): void;
}
