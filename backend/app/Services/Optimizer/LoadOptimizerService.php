<?php

namespace App\Services\Optimizer;

use InvalidArgumentException;

class LoadOptimizerService
{
    /**
     * Mengkalkulasi penataan kargo 3D dan distribusi beban as roda.
     *
     * @param array{
     *   type: string,
     *   max_weight_kg: float,
     *   max_volume_m3: float,
     *   container_dimensions: array{length_cm: float, width_cm: float, height_cm: float},
     *   axle_limits: array{front_max_kg: float, rear_max_kg: float}
     * } $vehicle
     * @param array<int, array{
     *   id: string,
     *   name: string,
     *   length_cm: float,
     *   width_cm: float,
     *   height_cm: float,
     *   weight_kg: float,
     *   quantity: int,
     *   is_hazmat: bool,
     *   stackable: bool,
     *   max_stack?: int
     * }> $cargoItems
     * @return array<string, mixed>
     */
    public function optimize(array $vehicle, array $cargoItems): array
    {
        $containerL = (float) $vehicle['container_dimensions']['length_cm'];
        $containerW = (float) $vehicle['container_dimensions']['width_cm'];
        $containerH = (float) $vehicle['container_dimensions']['height_cm'];

        if ($containerL <= 0 || $containerW <= 0 || $containerH <= 0) {
            throw new InvalidArgumentException('Dimensi kontainer tidak valid.');
        }

        $containerVolumeM3 = ($containerL * $containerW * $containerH) / 1000000.0;
        $maxWeightKg = (float) $vehicle['max_weight_kg'];

        // Uraikan kargo per unit koli dan urutkan
        $units = [];
        foreach ($cargoItems as $item) {
            $qty = (int) $item['quantity'];
            for ($i = 0; $i < $qty; $i++) {
                $unitNumber = $i + 1;
                $units[] = [
                    'id' => sprintf('%s-%d', $item['id'], $unitNumber),
                    'name' => $item['name'],
                    'length_cm' => (float) $item['length_cm'],
                    'width_cm' => (float) $item['width_cm'],
                    'height_cm' => (float) $item['height_cm'],
                    'weight_kg' => (float) $item['weight_kg'],
                    'volume_m3' => ((float) $item['length_cm'] * (float) $item['width_cm'] * (float) $item['height_cm']) / 1000000.0,
                    'is_hazmat' => (bool) ($item['is_hazmat'] ?? false),
                    'stackable' => (bool) ($item['stackable'] ?? true),
                ];
            }
        }

        // Urutkan: Hazmat pertama, lalu volume terbesar
        usort($units, function ($a, $b) {
            if ($a['is_hazmat'] !== $b['is_hazmat']) {
                return $a['is_hazmat'] ? -1 : 1;
            }

            return $b['volume_m3'] <=> $a['volume_m3'];
        });

        // 3D Shelf-Packing Heuristic
        $placed = [];
        $unplaced = [];
        $currentX = 0.0;
        $currentY = 0.0;
        $currentZ = 0.0;
        $maxLayerH = 0.0;
        $maxRowW = 0.0;

        $totalWeight = 0.0;
        $totalVolume = 0.0;
        $weightedXSum = 0.0;

        foreach ($units as $unit) {
            $l = $unit['length_cm'];
            $w = $unit['width_cm'];
            $h = $unit['height_cm'];

            // Hazmat tidak boleh ditumpuk di atas kargo lain
            if ($unit['is_hazmat'] && $currentZ > 0.0) {
                $currentZ = 0.0;
                $currentY += $maxRowW;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di tinggi Z
            if (($currentZ + $h) > $containerH || ! $unit['stackable']) {
                $currentZ = 0.0;
                $currentY += $maxRowW;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di lebar Y
            if (($currentY + $w) > $containerW) {
                $currentY = 0.0;
                $currentZ = 0.0;
                $currentX += $maxLayerH;
                $maxLayerH = 0.0;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di panjang X atau melebihi berat total
            if (($currentX + $l) > $containerL || ($totalWeight + $unit['weight_kg']) > $maxWeightKg) {
                $unplaced[] = $unit;

                continue;
            }

            $posX = $currentX;
            $posY = $currentY;
            $posZ = $currentZ;

            $placed[] = array_merge($unit, [
                'position' => [
                    'x' => round($posX, 2),
                    'y' => round($posY, 2),
                    'z' => round($posZ, 2),
                ],
            ]);

            $totalWeight += $unit['weight_kg'];
            $totalVolume += $unit['volume_m3'];

            $centerX = $posX + ($l / 2.0);
            $weightedXSum += ($unit['weight_kg'] * $centerX);

            if ($h > $maxLayerH) {
                $maxLayerH = $h;
            }
            if ($w > $maxRowW) {
                $maxRowW = $w;
            }

            if ($unit['stackable'] && ! $unit['is_hazmat']) {
                $currentZ += $h;
            } else {
                $currentY += $w;
            }
        }

        // Kalkulasi Distribusi Beban As Roda
        $frontMax = (float) ($vehicle['axle_limits']['front_max_kg'] ?? ($maxWeightKg * 0.4));
        $rearMax = (float) ($vehicle['axle_limits']['rear_max_kg'] ?? ($maxWeightKg * 0.6));

        $frontAxleLoad = 0.0;
        $rearAxleLoad = 0.0;

        if ($totalWeight > 0.0) {
            $centerOfGravityX = $weightedXSum / $totalWeight;
            $rearRatio = min(1.0, max(0.0, $centerOfGravityX / $containerL));
            $frontRatio = 1.0 - $rearRatio;

            $frontAxleLoad = round($totalWeight * $frontRatio, 2);
            $rearAxleLoad = round($totalWeight * $rearRatio, 2);
        }

        $frontOverload = $frontAxleLoad > $frontMax;
        $rearOverload = $rearAxleLoad > $rearMax;
        $isValidDispatch = empty($unplaced) && ! $frontOverload && ! $rearOverload;

        return [
            'is_valid_dispatch' => $isValidDispatch,
            'summary' => [
                'total_placed_items' => count($placed),
                'total_unplaced_items' => count($unplaced),
                'total_weight_kg' => round($totalWeight, 2),
                'max_weight_kg' => $maxWeightKg,
                'weight_utilization_pct' => round(($totalWeight / $maxWeightKg) * 100, 2),
                'total_volume_m3' => round($totalVolume, 3),
                'container_volume_m3' => round($containerVolumeM3, 3),
                'volume_utilization_pct' => round(($totalVolume / $containerVolumeM3) * 100, 2),
            ],
            'axle_distribution' => [
                'front_axle_load_kg' => $frontAxleLoad,
                'front_max_kg' => $frontMax,
                'front_status' => $frontOverload ? 'OVERLOAD' : 'OK',
                'rear_axle_load_kg' => $rearAxleLoad,
                'rear_max_kg' => $rearMax,
                'rear_status' => $rearOverload ? 'OVERLOAD' : 'OK',
            ],
            'container_dimensions' => [
                'length_cm' => $containerL,
                'width_cm' => $containerW,
                'height_cm' => $containerH,
            ],
            'placed_items' => $placed,
            'unplaced_items' => $unplaced,
        ];
    }
}
