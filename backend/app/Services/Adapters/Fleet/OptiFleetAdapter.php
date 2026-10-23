<?php

namespace App\Services\Adapters\Fleet;

use App\Services\Adapters\NexusBridgeResolverService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OptiFleetAdapter implements FleetMaintenancePortInterface
{
    public function __construct(
        protected ?NexusBridgeResolverService $nexusResolver = null
    ) {
        $this->nexusResolver = $nexusResolver ?? new NexusBridgeResolverService;
    }

    public function checkVehicleReadiness(string $tenantId, string $vehicleId): array
    {
        $config = $this->nexusResolver->getBridgeConfig($tenantId, 'fleet');

        // Jika integrasi dinonaktifkan di OptiNexus / lokal -> izinkan dispatch tanpa penguncian
        if (! $config['enabled'] || empty($config['base_url'])) {
            return [
                'vehicle_id' => $vehicleId,
                'is_available' => true,
                'status' => 'ACTIVE',
                'driver' => 'local_unlocked',
                'reason' => 'Pemeriksaan servis OptiFleet dinonaktifkan.',
            ];
        }

        // Jika aktif, lakukan panggilan HTTP riil ke OptiFleet VMS Service
        $endpoint = sprintf('%s/api/v1/app/vehicles/%s', rtrim((string) $config['base_url'], '/'), $vehicleId);

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders(['X-Tenant-Id' => $tenantId, 'Accept' => 'application/json'])
                ->get($endpoint);

            if ($response->successful()) {
                $status = strtoupper((string) $response->json('data.status', 'ACTIVE'));
                $isMaintenance = in_array($status, ['IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE'], true);

                return [
                    'vehicle_id' => $vehicleId,
                    'is_available' => ! $isMaintenance,
                    'status' => $status,
                    'driver' => 'optifleet_live',
                    'reason' => $isMaintenance ? sprintf('Unit tidak dapat ditugaskan: Status armada %s di OptiFleet.', $status) : null,
                ];
            }
        } catch (Exception $e) {
            Log::warning(sprintf('Circuit Breaker OptiFleet: Gagal menghubungi API (%s). Melakukan fallback lokal.', $e->getMessage()));
        }

        // Fallback jika API OptiFleet down: jangan hentikan dispatch gudang
        return [
            'vehicle_id' => $vehicleId,
            'is_available' => true,
            'status' => 'CIRCUIT_OPEN',
            'driver' => 'optifleet_circuit_breaker',
            'reason' => 'Koneksi OptiFleet terputus sementara. Dispatch diizinkan via Circuit Breaker.',
        ];
    }
}
