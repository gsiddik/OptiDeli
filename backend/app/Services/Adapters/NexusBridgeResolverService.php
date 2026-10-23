<?php

namespace App\Services\Adapters;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NexusBridgeResolverService
{
    /**
     * Mengambil konfigurasi jembatan integrasi tenant dari OptiNexus dengan proteksi Circuit Breaker.
     *
     * @return array{
     *   enabled: bool,
     *   base_url: ?string,
     *   timeout_seconds: int,
     *   source: string
     * }
     */
    public function getBridgeConfig(string $tenantId, string $bridgeName): array
    {
        $deploymentMode = config('optideli.deployment_mode', 'standalone');

        // Jika mode Standalone, selalu gunakan pengaturan .env lokal
        if ($deploymentMode === 'standalone') {
            return $this->resolveStandaloneConfig($bridgeName);
        }

        // Cek Cache Lokal (TTL: 60 detik) agar tidak membebani jaringan setiap request
        $cacheKey = sprintf('nexus_bridge_config_%s_%s', $tenantId, $bridgeName);

        return Cache::remember($cacheKey, 60, function () use ($tenantId, $bridgeName) {
            return $this->fetchFromNexusWithCircuitBreaker($tenantId, $bridgeName);
        });
    }

    /**
     * Memanggil OptiNexus dengan pembatasan timeout dan Circuit Breaker fallback.
     *
     * @return array<string, mixed>
     */
    protected function fetchFromNexusWithCircuitBreaker(string $tenantId, string $bridgeName): array
    {
        $nexusUrl = rtrim((string) config('optideli.identity.optinexus_url', 'http://optinexus.local:8000'), '/');
        $endpoint = sprintf('%s/api/v1/tenants/%s/applications/optideli/bridges', $nexusUrl, $tenantId);

        try {
            $response = Http::timeout(2)
                ->withHeaders([
                    'X-Client-Id' => (string) config('optideli.identity.m2m_client_id', 'optideli-service-account'),
                    'Accept' => 'application/json',
                ])
                ->get($endpoint);

            if ($response->successful()) {
                $bridges = $response->json('data.bridges', []);
                if (isset($bridges[$bridgeName])) {
                    return [
                        'enabled' => (bool) ($bridges[$bridgeName]['enabled'] ?? false),
                        'base_url' => $bridges[$bridgeName]['base_url'] ?? null,
                        'timeout_seconds' => (int) ($bridges[$bridgeName]['timeout_seconds'] ?? 3),
                        'source' => 'optinexus_live',
                    ];
                }
            }
        } catch (Exception $e) {
            Log::warning(sprintf('Circuit Breaker: Gagal menghubungi OptiNexus (%s). Menggunakan fallback aman.', $e->getMessage()));
        }

        // Fallback aman jika OptiNexus tidak terjangkau
        return $this->resolveStandaloneConfig($bridgeName);
    }

    /**
     * Konfigurasi cadangan berbasis .env internal.
     *
     * @return array<string, mixed>
     */
    protected function resolveStandaloneConfig(string $bridgeName): array
    {
        $driver = config(sprintf('optideli.%s.driver', $bridgeName), 'none');
        $baseUrl = config(sprintf('optideli.%s.%s_url', $bridgeName, $bridgeName), null);

        $isEnabled = ! in_array($driver, ['none', 'internal', 'local'], true);

        return [
            'enabled' => $isEnabled,
            'base_url' => $baseUrl,
            'timeout_seconds' => 3,
            'source' => 'standalone_fallback',
        ];
    }
}
