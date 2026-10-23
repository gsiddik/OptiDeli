<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdiGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_edi_204_214_210_full_cycle(): void
    {
        $tenantId = 'demo';

        // 1. Inbound EDI 204
        $sample204 = "ISA*00*          *00*          *ZZ*CUSTOMERERP    *ZZ*OPTIDELI       *261023*1530*U*00401*000000001*0*P*>~
" .
            "GS*SM*CUSTOMERERP*OPTIDELI*20261023*1530*1*X*004010~
" .
            "ST*204*0001~
" .
            "B2**CARRIER*PO-882199**PP~
" .
            "N1*SH*JAKARTA-HUB-01~
" .
            "N1*CN*SURABAYA-CROSSDOCK-02~
" .
            "OID*ELEKTRONIK CONSUMER*100*CT*2500*KG~
" .
            "L3*2500*G*550000000~
" .
            "SE*8*0001~
" .
            "GE*1*1~
" .
            "IEA*1*000000001~";

        $res204 = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/edi/inbound-204', ['raw_x12' => $sample204]);

        $res204->assertStatus(201);
        $this->assertEquals('PO-882199', $res204->json('data.shipment_id'));
        $this->assertEquals('JAKARTA-HUB-01', $res204->json('data.origin_zone'));
        $this->assertEquals(550000000, $res204->json('data.sell_price_minor_units'));

        // 2. Outbound EDI 214 Status
        $res214 = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/edi/generate-214', [
                'shipment_id' => 'PO-882199',
                'status' => 'DELIVERED',
                'location_city' => 'SURABAYA',
            ]);

        $res214->assertStatus(200);
        $this->assertStringContainsString('ST*214*0001', $res214->json('raw_x12'));
        $this->assertStringContainsString('AT7*D1*NS', $res214->json('raw_x12'));

        // 3. Outbound EDI 210 Invoice
        $res210 = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/edi/sync-210', [
                'invoice_number' => 'INV-2026-001',
                'order_id' => 'PO-882199',
                'customer_ar_minor_units' => 550000000,
                'vendor_ap_minor_units' => 450000000,
                'accessorials_minor_units' => 25000000,
            ]);

        $res210->assertStatus(200);
        $this->assertStringContainsString('ST*210*0001', $res210->json('raw_x12'));
        $this->assertStringContainsString('INV-2026-001', $res210->json('raw_x12'));

        // 4. Verifikasi Transmissions List
        $resList = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->getJson('/api/v1/edi/transmissions');

        $resList->assertStatus(200);
        $this->assertCount(3, $resList->json('data'));
    }
}
