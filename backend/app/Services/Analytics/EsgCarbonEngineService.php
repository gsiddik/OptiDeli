<?php

namespace App\Services\Analytics;

use App\Models\CarbonEmissionRecord;
use Carbon\Carbon;
use InvalidArgumentException;

class EsgCarbonEngineService
{
    /**
     * Standar Faktor Emisi GLEC / ISO 14083 (gCO2e / ton-km)
     */
    protected const EMISSION_FACTORS = [
        'DIESEL_B35' => 110.0,
        'DIESEL_EURO4' => 125.0,
        'CNG' => 85.0,
        'EV' => 20.0,
    ];

    /**
     * Menghitung dan menyimpan jejak karbon trip.
     *
     * @param array{
     *   trip_id: string,
     *   vehicle_id: string,
     *   fleet_ownership: string,
     *   fuel_type: string,
     *   distance_km: float,
     *   cargo_weight_ton: float
     * } $data
     */
    public function recordTripEmission(string $tenantId, array $data): CarbonEmissionRecord
    {
        $fuelType = strtoupper($data['fuel_type']);
        if (! isset(self::EMISSION_FACTORS[$fuelType])) {
            throw new InvalidArgumentException(sprintf('Tipe bahan bakar %s tidak dikenali dalam standar GLEC.', $fuelType));
        }

        $distanceKm = max(0.1, (float) $data['distance_km']);
        $weightTon = max(0.01, (float) $data['cargo_weight_ton']);
        $tonKm = round($distanceKm * $weightTon, 3);

        $factor = self::EMISSION_FACTORS[$fuelType];
        $totalCo2eKg = round(($tonKm * $factor) / 1000.0, 3);

        $isInternal = strtoupper($data['fleet_ownership']) === 'INTERNAL';
        $scope = $isInternal ? 'SCOPE_1' : 'SCOPE_3';

        return CarbonEmissionRecord::create([
            'tenant_id' => $tenantId,
            'trip_id' => $data['trip_id'],
            'vehicle_id' => $data['vehicle_id'],
            'fleet_ownership' => $isInternal ? 'INTERNAL' : 'VENDOR',
            'fuel_type' => $fuelType,
            'distance_km' => $distanceKm,
            'cargo_weight_ton' => $weightTon,
            'ton_km' => $tonKm,
            'emission_factor_g_per_ton_km' => $factor,
            'total_co2e_kg' => $totalCo2eKg,
            'scope' => $scope,
            'recorded_at' => Carbon::now(),
        ]);
    }

    /**
     * Menghitung ringkasan metrik ESG karbon untuk dashboard.
     *
     * @return array<string, mixed>
     */
    public function getSummary(string $tenantId): array
    {
        $records = CarbonEmissionRecord::where('tenant_id', $tenantId)->get();

        $totalCo2eKg = (float) $records->sum('total_co2e_kg');
        $totalTonKm = (float) $records->sum('ton_km');
        $scope1Kg = (float) $records->where('scope', 'SCOPE_1')->sum('total_co2e_kg');
        $scope3Kg = (float) $records->where('scope', 'SCOPE_3')->sum('total_co2e_kg');

        $averageIntensity = $totalTonKm > 0.0
            ? round(($totalCo2eKg * 1000.0) / $totalTonKm, 2)
            : 0.0;

        return [
            'total_emissions_ton_co2e' => round($totalCo2eKg / 1000.0, 3),
            'total_emissions_kg_co2e' => round($totalCo2eKg, 2),
            'total_ton_km' => round($totalTonKm, 2),
            'average_intensity_g_per_ton_km' => $averageIntensity,
            'scope_breakdown' => [
                'scope_1_kg' => round($scope1Kg, 2),
                'scope_1_pct' => $totalCo2eKg > 0 ? round(($scope1Kg / $totalCo2eKg) * 100, 1) : 0,
                'scope_3_kg' => round($scope3Kg, 2),
                'scope_3_pct' => $totalCo2eKg > 0 ? round(($scope3Kg / $totalCo2eKg) * 100, 1) : 0,
            ],
            'total_trips_logged' => $records->count(),
        ];
    }
}
