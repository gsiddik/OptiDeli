<?php

namespace App\Services\Tendering;

use App\Models\VendorRateCard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class RateCardEngineService
{
    public function calculateEstimatedCost(
        string $tenantId,
        string $vendorId,
        string $vehicleType,
        string $zoneOrigin,
        string $zoneDestination,
        string|int|float $actualDistanceKm
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

        // Decimal arithmetic on money: billable km x per-km rate, rounded half-up to whole minor units.
        $billableDistanceKm = BigDecimal::of((string) $actualDistanceKm)->minus($rateCard->min_distance_km);
        if ($billableDistanceKm->isNegative()) {
            $billableDistanceKm = BigDecimal::zero();
        }
        $distanceSurcharge = $billableDistanceKm->multipliedBy($rateCard->rate_per_km_minor_units)->toScale(0, RoundingMode::HalfUp);

        return BigDecimal::of($rateCard->base_rate_minor_units)->plus($distanceSurcharge)->toInt();
    }
}
