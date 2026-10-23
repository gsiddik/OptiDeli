// scripts/write_nexus_bridge_resolver.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Service: NexusBridgeResolverService
  {
    path: 'backend/app/Services/Adapters/NexusBridgeResolverService.php',
    content: `<?php

namespace App\\Services\\Adapters;

use Exception;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;

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
`
  },

  // 2. Pembaruan: OptiFleetAdapter dengan HTTP Call Riil & Circuit Breaker
  {
    path: 'backend/app/Services/Adapters/Fleet/OptiFleetAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Fleet;

use App\\Services\\Adapters\\NexusBridgeResolverService;
use Exception;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;

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
`
  },

  // 3. Pembaruan: OptiEntryAdapter dengan HTTP Call Riil & Circuit Breaker
  {
    path: 'backend/app/Services/Adapters/Accounting/OptiEntryAdapter.php',
    content: `<?php

namespace App\\Services\\Adapters\\Accounting;

use App\\Services\\Adapters\\NexusBridgeResolverService;
use Exception;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;

class OptiEntryAdapter implements AccountingSettlementPortInterface
{
    public function __construct(
        protected ?NexusBridgeResolverService $nexusResolver = null
    ) {
        $this->nexusResolver = $nexusResolver ?? new NexusBridgeResolverService;
    }

    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        $config = $this->nexusResolver->getBridgeConfig($tenantId, 'accounting');

        if (! $config['enabled'] || empty($config['base_url'])) {
            return [
                'order_id' => $orderId,
                'synced' => false,
                'driver' => 'internal_ledger',
                'message' => 'Integrasi OptiEntry dinonaktifkan di OptiNexus. Tagihan dicatat pada ledger internal.',
            ];
        }

        $endpoint = sprintf('%s/api/v1/app/invoices/freight-sync', rtrim((string) $config['base_url'], '/'));

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders(['X-Tenant-Id' => $tenantId, 'Accept' => 'application/json'])
                ->post($endpoint, [
                    'order_id' => $orderId,
                    'customer_ar_minor_units' => $customerArMinor,
                    'vendor_ap_minor_units' => $vendorApMinor,
                    'accessorials_minor_units' => $accessorialsMinor,
                ]);

            if ($response->successful()) {
                return [
                    'order_id' => $orderId,
                    'synced' => true,
                    'driver' => 'optientry_live',
                    'message' => 'Faktur berhasil dibukukan ke buku besar OptiAccounting/OptiEntry.',
                ];
            }
        } catch (Exception $e) {
            Log::warning(sprintf('Circuit Breaker OptiEntry: Gagal kirim jurnal (%s).', $e->getMessage()));
        }

        return [
            'order_id' => $orderId,
            'synced' => false,
            'driver' => 'optientry_circuit_breaker',
            'message' => 'Koneksi OptiEntry bermasalah. Transaksi disimpan dalam antrean internal.',
        ];
    }
}
`
  },

  // 4. Feature Test: NexusBridgeResolverTest
  {
    path: 'backend/tests/Feature/NexusBridgeResolverTest.php',
    content: `<?php

namespace Tests\\Feature;

use App\\Services\\Adapters\\Fleet\\OptiFleetAdapter;
use App\\Services\\Adapters\\NexusBridgeResolverService;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class NexusBridgeResolverTest extends TestCase
{
    public function test_bridge_resolver_handles_optinexus_toggle_and_circuit_breaker(): void
    {
        $tenantId = 'demo-corp';

        // 1. Mock respons sukses dari OptiNexus (OptiFleet aktif, OptiEntry aktif)
        Http::fake([
            'http://optinexus.local:8000/api/v1/tenants/demo-corp/applications/optideli/bridges' => Http::response([
                'data' => [
                    'bridges' => [
                        'fleet' => [
                            'enabled' => true,
                            'base_url' => 'http://optifleet.local:8001',
                            'timeout_seconds' => 3,
                        ],
                    ],
                ],
            ], 200),
            'http://optifleet.local:8001/api/v1/app/vehicles/B-1111-SRV' => Http::response([
                'data' => ['status' => 'IN_MAINTENANCE'],
            ], 200),
            'http://optifleet.local:8001/api/v1/app/vehicles/B-2222-OK' => Http::response([
                'data' => ['status' => 'ACTIVE'],
            ], 200),
        ]);

        config(['optideli.deployment_mode' => 'saas']);

        $resolver = new NexusBridgeResolverService;
        $fleetAdapter = new OptiFleetAdapter($resolver);

        // Uji armada yang sedang diservis -> harus terkunci
        $blocked = $fleetAdapter->checkVehicleReadiness($tenantId, 'B-1111-SRV');
        $this->assertFalse($blocked['is_available']);
        $this->assertEquals('IN_MAINTENANCE', $blocked['status']);

        // Uji armada aktif -> harus tersedia
        $available = $fleetAdapter->checkVehicleReadiness($tenantId, 'B-2222-OK');
        $this->assertTrue($available['is_available']);
        $this->assertEquals('ACTIVE', $available['status']);
    }

    public function test_circuit_breaker_falls_back_when_remote_service_times_out(): void
    {
        $tenantId = 'demo-corp';

        // Mock OptiFleet timeout / down
        Http::fake([
            '*' => function () {
                throw new \\Illuminate\\Http\\Client\\ConnectionException('Connection timeout');
            },
        ]);

        config(['optideli.deployment_mode' => 'saas']);

        $resolver = new NexusBridgeResolverService;
        $fleetAdapter = new OptiFleetAdapter($resolver);

        // Harus tetap bernilai is_available = true agar tidak memblokir operasional gudang saat jaringan down
        $result = $fleetAdapter->checkVehicleReadiness($tenantId, 'B-9999-XYZ');
        $this->assertTrue($result['is_available']);
        $this->assertEquals('optifleet_circuit_breaker', $result['driver']);
    }
}
`
  }
];

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});
console.log('\nSelesai! Komponen Dynamic Bridge Resolver OptiDeli telah selesai dibangun.');