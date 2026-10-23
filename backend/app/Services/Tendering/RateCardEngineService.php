<?php

namespace App\Services\Tendering;

use App\Models\VendorRateCard;
use InvalidArgumentException;

class RateCardEngineService
{
    public function calculateEstimatedCost(
        string $tenantId,
        string $vendorId,
        string $vehicleType,
        string $zoneOrigin,
        string $zoneDestination,
        float $actualDistanceKm
    ): int {
        $rateCard = VendorRateCard::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('vehicle_type', $vehicleType)
            ->where('zone_origin', $zoneOrigin)
            ->where('zone_destination', $zoneDestination)
            ->where('is_active', true)
            ->first();

        if (! $rateCard) {
            throw new InvalidArgumentException("Tarif Rate Card tidak ditemukan untuk vendor '{$vendorId}' pada rute {$zoneOrigin} -> {$zoneDestination}");
        }

        $baseRate = $rateCard->base_rate_minor_units;
        $billableDistanceKm = max(0.0, $actualDistanceKm - $rateCard->min_distance_km);
        $distanceSurcharge = (int) round($billableDistanceKm * $rateCard->rate_per_km_minor_units);

        return $baseRate + $distanceSurcharge;
    }
}
