<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class EcosystemAdaptersTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_ecosystem_context_and_pluggable_adapters(): void
    {
        $tenantId = $this->signInTenantMember();

        // 1. Cek Context Ekosistem
        $resContext = $this->getJson('/api/v1/ecosystem/context');

        $resContext->assertStatus(200);
        $resContext->assertJsonPath('data.identity.tenant_id', $tenantId);
        $resContext->assertJsonPath('data.identity.role', 'dispatcher');
        $resContext->assertJsonStructure([
            'data' => [
                'deployment_mode',
                'identity' => ['tenant_id', 'user_id', 'role', 'mode', 'is_sso'],
                'active_drivers' => ['identity', 'telematics', 'fleet', 'accounting'],
                'integrations',
            ],
        ]);

        // 2. Cek Kesiapan Armada (Asset Lock)
        $resFleet = $this->getJson('/api/v1/fleet/assets/B-1234-KDA/readiness');

        $resFleet->assertStatus(200);
        $this->assertTrue($resFleet->json('data.is_available'));

        // 3. Cek Sinkronisasi Akuntansi
        $resAcc = $this->postJson('/api/v1/accounting/sync', [
            'order_id' => 'ORD-TEST-001',
            'customer_ar_minor_units' => 500000000,
            'vendor_ap_minor_units' => 400000000,
        ]);

        $resAcc->assertStatus(200);
        $this->assertTrue($resAcc->json('data.synced'));
    }
}
