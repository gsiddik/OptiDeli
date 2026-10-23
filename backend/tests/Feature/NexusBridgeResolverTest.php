<?php

namespace Tests\Feature;

use App\Services\Adapters\Fleet\OptiFleetAdapter;
use App\Services\Adapters\NexusBridgeResolverService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
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
