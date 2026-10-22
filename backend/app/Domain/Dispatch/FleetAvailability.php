<?php
namespace App\Domain\Dispatch;
interface FleetAvailability
{
    public function assertReady(object $vehicle,object $driver,\Carbon\CarbonImmutable $end): void;
}
