// scripts/fix_edi_pint_style.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Edi214Service.php
  {
    path: 'backend/app/Services/EDI/Edi214Service.php',
    content: `<?php

namespace App\\Services\\EDI;

use App\\Models\\EdiTransmission;
use Carbon\\Carbon;

class Edi214Service
{
    /**
     * Memetakan status internal OptiDeli ke kode standar ANSI X12 AT7.
     */
    protected const STATUS_MAP = [
        'DISPATCHED' => 'AF',
        'EN_ROUTE' => 'X6',
        'ARRIVED' => 'X1',
        'DELIVERED' => 'D1',
    ];

    /**
     * Mengonstruksi pesan status ANSI X12 214 Outbound.
     */
    public function generateX12(
        string $tenantId,
        string $shipmentId,
        string $internalStatus,
        string $locationCity,
        ?string $etaIso = null
    ): string {
        $ediCode = self::STATUS_MAP[strtoupper($internalStatus)] ?? 'X6';
        $now = Carbon::now();
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('Hi');

        $segments = [
            sprintf('ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*CUSTOMERERP    *%s*%s*U*00401*000000001*0*P*>', $dateStr, $timeStr),
            sprintf('GS*QM*OPTIDELI*CUSTOMERERP*%s*%s*1*X*004010', $dateStr, $timeStr),
            'ST*214*0001',
            sprintf('B10*%s*%s*OPTD', $shipmentId, $shipmentId),
            'LX*1',
            sprintf('AT7*%s*NS***%s*%s*LT', $ediCode, $dateStr, $timeStr),
            sprintf('MS1*%s*ID*ID', strtoupper($locationCity)),
            'SE*7*0001',
            'GE*1*1',
            'IEA*1*000000001~',
        ];

        $x12 = implode("~\\n", $segments);

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '214',
            'direction' => 'OUTBOUND',
            'reference_id' => $shipmentId,
            'raw_content' => $x12,
            'parsed_payload' => [
                'shipment_id' => $shipmentId,
                'status_code' => $ediCode,
                'internal_status' => $internalStatus,
                'location' => $locationCity,
                'eta' => $etaIso,
            ],
            'status' => 'TRANSMITTED',
            'transmitted_at' => $now,
        ]);

        return $x12;
    }
}
`
  },

  // 2. Edi210Service.php
  {
    path: 'backend/app/Services/EDI/Edi210Service.php',
    content: `<?php

namespace App\\Services\\EDI;

use App\\Models\\EdiTransmission;
use Carbon\\Carbon;

class Edi210Service
{
    /**
     * Membentuk dokumen tagihan ANSI X12 210 untuk sinkronisasi piutang/utang ke OptiEntry / ERP Finance.
     */
    public function generateX12(
        string $tenantId,
        string $invoiceNumber,
        string $orderId,
        int $customerArMinorUnits,
        int $vendorApMinorUnits,
        int $accessorialsMinorUnits = 0
    ): string {
        $now = Carbon::now();
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('Hi');
        $totalAmountMinor = $customerArMinorUnits + $accessorialsMinorUnits;

        $segments = [
            sprintf('ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*FINANCEERP     *%s*%s*U*00401*000000001*0*P*>', $dateStr, $timeStr),
            sprintf('GS*IM*OPTIDELI*FINANCEERP*%s*%s*1*X*004010', $dateStr, $timeStr),
            'ST*210*0001',
            sprintf('B3*B*%s*%s*PP*%s*%d**IDR', $invoiceNumber, $orderId, $dateStr, $totalAmountMinor),
            'N1*BT*CUSTOMER-ACCOUNTS-PAYABLE',
            'N1*CA*OPTIDELI-OPERATIONS',
            sprintf('L3*0*G*%d', $totalAmountMinor),
            'SE*7*0001',
            'GE*1*1',
            'IEA*1*000000001~',
        ];

        $x12 = implode("~\\n", $segments);

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '210',
            'direction' => 'OUTBOUND',
            'reference_id' => $invoiceNumber,
            'raw_content' => $x12,
            'parsed_payload' => [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'customer_ar_minor_units' => $customerArMinorUnits,
                'vendor_ap_minor_units' => $vendorApMinorUnits,
                'accessorials_minor_units' => $accessorialsMinorUnits,
                'total_minor_units' => $totalAmountMinor,
            ],
            'status' => 'TRANSMITTED',
            'transmitted_at' => $now,
        ]);

        return $x12;
    }
}
`
  },

  // 3. EdiGatewayTest.php
  {
    path: 'backend/tests/Feature/EdiGatewayTest.php',
    content: `<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class EdiGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_edi_204_214_210_full_cycle(): void
    {
        $tenantId = 'demo';

        // 1. Inbound EDI 204
        $segments204 = [
            'ISA*00*          *00*          *ZZ*CUSTOMERERP    *ZZ*OPTIDELI       *261023*1530*U*00401*000000001*0*P*>',
            'GS*SM*CUSTOMERERP*OPTIDELI*20261023*1530*1*X*004010',
            'ST*204*0001',
            'B2**CARRIER*PO-882199**PP',
            'N1*SH*JAKARTA-HUB-01',
            'N1*CN*SURABAYA-CROSSDOCK-02',
            'OID*ELEKTRONIK CONSUMER*100*CT*2500*KG',
            'L3*2500*G*550000000',
            'SE*8*0001',
            'GE*1*1',
            'IEA*1*000000001~',
        ];
        $sample204 = implode("~\\n", $segments204);

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
`
  }
];

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Diperbaiki sesuai standar Pint: ${f.path}`);
});