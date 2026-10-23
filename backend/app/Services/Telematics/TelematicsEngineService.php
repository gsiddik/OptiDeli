<?php

namespace App\Services\Telematics;

use App\Models\TelemetryAlert;
use App\Models\TelemetryLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TelematicsEngineService
{
    /**
     * Memproses ping telematika IoT dan mengevaluasi pelanggaran ambang batas (Exception Alert).
     *
     * @param array{
     *   trip_id: string,
     *   vehicle_id: string,
     *   latitude: float,
     *   longitude: float,
     *   speed_kmh: float,
     *   fuel_level_pct: float,
     *   reefer_temp_celsius?: float|null,
     *   is_door_open?: bool
     * } $data
     * @return array{log: TelemetryLog, alerts: array<int, TelemetryAlert>}
     */
    public function processPing(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $now = Carbon::now();

            $log = TelemetryLog::create([
                'tenant_id' => $tenantId,
                'trip_id' => $data['trip_id'],
                'vehicle_id' => $data['vehicle_id'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'speed_kmh' => $data['speed_kmh'],
                'fuel_level_pct' => $data['fuel_level_pct'],
                'reefer_temp_celsius' => $data['reefer_temp_celsius'] ?? null,
                'is_door_open' => (bool) ($data['is_door_open'] ?? false),
                'recorded_at' => $now,
            ]);

            $generatedAlerts = [];

            // 1. Evaluasi Over-Speeding (Batas: 90 km/h)
            if ($data['speed_kmh'] > 90.0) {
                $generatedAlerts[] = TelemetryAlert::create([
                    'tenant_id' => $tenantId,
                    'trip_id' => $data['trip_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'alert_type' => 'OVERSPEED',
                    'severity' => $data['speed_kmh'] > 110.0 ? 'CRITICAL' : 'WARNING',
                    'message' => sprintf('Kecepatan kendaraan %.1f km/jam melampaui batas regulasi.', $data['speed_kmh']),
                    'metric_value' => $data['speed_kmh'],
                    'threshold_value' => 90.0,
                    'is_resolved' => false,
                    'triggered_at' => $now,
                ]);
            }

            // 2. Evaluasi Suhu Reefer Cold-Chain (Batas maksimal beku: -15.0 C)
            if (isset($data['reefer_temp_celsius']) && $data['reefer_temp_celsius'] !== null) {
                if ($data['reefer_temp_celsius'] > -15.0) {
                    $generatedAlerts[] = TelemetryAlert::create([
                        'tenant_id' => $tenantId,
                        'trip_id' => $data['trip_id'],
                        'vehicle_id' => $data['vehicle_id'],
                        'alert_type' => 'REEFER_TEMP_BREACH',
                        'severity' => 'CRITICAL',
                        'message' => sprintf('Integritas suhu kargo dingin terancam (Suhu saat ini: %.1f°C).', $data['reefer_temp_celsius']),
                        'metric_value' => $data['reefer_temp_celsius'],
                        'threshold_value' => -15.0,
                        'is_resolved' => false,
                        'triggered_at' => $now,
                    ]);
                }
            }

            // 3. Evaluasi Pintu Terbuka Saat Melaju
            if (! empty($data['is_door_open']) && $data['speed_kmh'] > 10.0) {
                $generatedAlerts[] = TelemetryAlert::create([
                    'tenant_id' => $tenantId,
                    'trip_id' => $data['trip_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'alert_type' => 'DOOR_OPEN_ENROUTE',
                    'severity' => 'CRITICAL',
                    'message' => 'Pintu kargo box terdeteksi terbuka saat kendaraan melaju.',
                    'metric_value' => $data['speed_kmh'],
                    'threshold_value' => 10.0,
                    'is_resolved' => false,
                    'triggered_at' => $now,
                ]);
            }

            return [
                'log' => $log,
                'alerts' => $generatedAlerts,
            ];
        });
    }

    /**
     * Mengambil status posisi GPS dan diagnostik terkini armada aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLiveFleet(string $tenantId): array
    {
        $latestLogs = TelemetryLog::where('tenant_id', $tenantId)
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->unique('vehicle_id');

        $activeAlerts = TelemetryAlert::where('tenant_id', $tenantId)
            ->where('is_resolved', false)
            ->get()
            ->groupBy('vehicle_id');

        $result = [];
        foreach ($latestLogs as $log) {
            $vehicleAlerts = $activeAlerts->get($log->vehicle_id, collect());
            $result[] = [
                'vehicle_id' => $log->vehicle_id,
                'trip_id' => $log->trip_id,
                'latitude' => $log->latitude,
                'longitude' => $log->longitude,
                'speed_kmh' => $log->speed_kmh,
                'fuel_level_pct' => $log->fuel_level_pct,
                'reefer_temp_celsius' => $log->reefer_temp_celsius,
                'is_door_open' => $log->is_door_open,
                'recorded_at' => $log->recorded_at->toIso8601String(),
                'status' => $vehicleAlerts->isNotEmpty() ? 'EXCEPTION' : ($log->speed_kmh > 0 ? 'EN_ROUTE' : 'IDLE'),
                'active_alerts' => $vehicleAlerts->values()->all(),
            ];
        }

        return $result;
    }
}
