<?php

namespace Tests\Feature;

use App\Models\VendorRateCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class WaterfallTenderTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public function test_rate_card_minor_unit_calculation_and_waterfall_cascading(): void
    {
        $tenantId = $this->signInTenantMember();
        $orderId = (string) Str::uuid();

        // 1. Setup Rate Cards (1 unit = Rp 0,01)
        VendorRateCard::create([
            'tenant_id' => $tenantId,
            'vendor_id' => 'VND-ALPHA',
            'vehicle_type' => 'CDD',
            'zone_origin' => 'JKT-HUB',
            'zone_destination' => 'BDG-HUB',
            'base_rate_minor_units' => 350000000,
            'rate_per_km_minor_units' => 450000,
            'min_distance_km' => 10,
            'is_active' => true,
        ]);

        VendorRateCard::create([
            'tenant_id' => $tenantId,
            'vendor_id' => 'VND-BETA',
            'vehicle_type' => 'CDD',
            'zone_origin' => 'JKT-HUB',
            'zone_destination' => 'BDG-HUB',
            'base_rate_minor_units' => 380000000,
            'rate_per_km_minor_units' => 400000,
            'min_distance_km' => 10,
            'is_active' => true,
        ]);

        // 2. Start Waterfall Tender via API
        $response = $this->postJson('/api/v1/tenders/waterfall/start', [
            'order_id' => $orderId,
            'timeout_minutes' => 15,
            'ranked_vendors' => [
                [
                    'vendor_id' => 'VND-ALPHA',
                    'vehicle_type' => 'CDD',
                    'zone_origin' => 'JKT-HUB',
                    'zone_destination' => 'BDG-HUB',
                    'distance_km' => 150.0,
                ],
                [
                    'vendor_id' => 'VND-BETA',
                    'vehicle_type' => 'CDD',
                    'zone_origin' => 'JKT-HUB',
                    'zone_destination' => 'BDG-HUB',
                    'distance_km' => 150.0,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('RUNNING', $data['status']);
        $this->assertCount(2, $data['offers']);
        $this->assertEquals('OFFERED', $data['offers'][0]['status']);
        $this->assertEquals('PENDING', $data['offers'][1]['status']);

        $offerAlphaId = $data['offers'][0]['id'];
        $offerBetaId = $data['offers'][1]['id'];

        // 3. Vendor ALPHA menolak -> cascade otomatis ke Vendor BETA
        $rejectResponse = $this->postJson("/api/v1/tenders/offers/{$offerAlphaId}/respond", [
            'action' => 'REJECT',
            'rejection_reason' => 'Unit sedang perawatan berkala.',
        ]);

        $rejectResponse->assertStatus(200);
        $offersUpdated = $rejectResponse->json('data.offers');
        $this->assertEquals('REJECTED', $offersUpdated[0]['status']);
        $this->assertEquals('OFFERED', $offersUpdated[1]['status']);

        // 4. Vendor BETA menerima -> tender berstatus ACCEPTED
        $acceptResponse = $this->postJson("/api/v1/tenders/offers/{$offerBetaId}/respond", [
            'action' => 'ACCEPT',
        ]);

        $acceptResponse->assertStatus(200);
        $this->assertEquals('ACCEPTED', $acceptResponse->json('data.status'));
        $this->assertEquals('VND-BETA', $acceptResponse->json('data.accepted_vendor_id'));
    }
}
