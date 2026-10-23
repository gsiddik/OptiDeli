// scripts/write_increment_5.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Migration: edi_transmissions
  {
    path: 'backend/database/migrations/2026_10_23_000005_create_edi_transmissions_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edi_transmissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('transaction_set'); // 204, 214, 210
            $table->string('direction'); // INBOUND, OUTBOUND
            $table->string('reference_id')->index(); // Order ID / Shipment ID / Invoice ID
            $table->longText('raw_content'); // Raw ANSI X12 / Payload
            $table->json('parsed_payload')->nullable();
            $table->string('status')->default('PROCESSED'); // PROCESSED, TRANSMITTED, FAILED
            $table->text('error_message')->nullable();
            $table->timestamp('transmitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'transaction_set', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edi_transmissions');
    }
};
`
  },

  // 2. Model: EdiTransmission
  {
    path: 'backend/app/Models/EdiTransmission.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class EdiTransmission extends Model
{
    use HasUuids;

    protected $table = 'edi_transmissions';

    protected $fillable = [
        'tenant_id',
        'transaction_set',
        'direction',
        'reference_id',
        'raw_content',
        'parsed_payload',
        'status',
        'error_message',
        'transmitted_at',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'transmitted_at' => 'datetime',
    ];
}
`
  },

  // 3. Service: Edi204Service (Load Tender Inbound Parser)
  {
    path: 'backend/app/Services/EDI/Edi204Service.php',
    content: `<?php

namespace App\\Services\\EDI;

use App\\Models\\EdiTransmission;
use Carbon\\Carbon;
use InvalidArgumentException;

class Edi204Service
{
    /**
     * Parsing dokumen ANSI X12 204 (Motor Carrier Load Tender).
     *
     * @return array<string, mixed>
     */
    public function parseX12(string $tenantId, string $rawX12): array
    {
        if (empty(trim($rawX12))) {
            throw new InvalidArgumentException('Payload EDI 204 tidak boleh kosong.');
        }

        $segments = array_map('trim', explode('~', $rawX12));
        $parsed = [
            'shipment_id' => null,
            'origin_zone' => 'JKT-HUB',
            'destination_zone' => 'SBY-HUB',
            'cargo_description' => 'General Merchandise',
            'total_weight_kg' => 1000.0,
            'sell_price_minor_units' => 450000000, // Rp 4.500.000,00
        ];

        foreach ($segments as $seg) {
            $elements = explode('*', $seg);
            $tag = $elements[0] ?? '';

            if ($tag === 'B2') {
                $parsed['shipment_id'] = $elements[4] ?? ($elements[2] ?? 'SHP-AUTO-01');
            } elseif ($tag === 'N1') {
                $entity = $elements[1] ?? '';
                if ($entity === 'SH') {
                    $parsed['origin_zone'] = $elements[2] ?? $parsed['origin_zone'];
                } elseif ($entity === 'CN') {
                    $parsed['destination_zone'] = $elements[2] ?? $parsed['destination_zone'];
                }
            } elseif ($tag === 'OID') {
                $parsed['cargo_description'] = $elements[1] ?? $parsed['cargo_description'];
                if (isset($elements[4])) {
                    $parsed['total_weight_kg'] = (float) $elements[4];
                }
            } elseif ($tag === 'L3') {
                if (isset($elements[3])) {
                    $parsed['sell_price_minor_units'] = (int) $elements[3];
                }
            }
        }

        if (empty($parsed['shipment_id'])) {
            $parsed['shipment_id'] = sprintf('SHP-%s', strtoupper(bin2hex(random_bytes(4))));
        }

        EdiTransmission::create([
            'tenant_id' => $tenantId,
            'transaction_set' => '204',
            'direction' => 'INBOUND',
            'reference_id' => $parsed['shipment_id'],
            'raw_content' => $rawX12,
            'parsed_payload' => $parsed,
            'status' => 'PROCESSED',
            'transmitted_at' => Carbon::now(),
        ]);

        return $parsed;
    }
}
`
  },

  // 4. Service: Edi214Service (Shipment Status Outbound Generator)
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

        $x12 = sprintf(
            "ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*CUSTOMERERP    *%s*%s*U*00401*000000001*0*P*>~\n" .
            "GS*QM*OPTIDELI*CUSTOMERERP*%s*%s*1*X*004010~\n" .
            "ST*214*0001~\n" .
            "B10*%s*%s*OPTD~\n" .
            "LX*1~\n" .
            "AT7*%s*NS***%s*%s*LT~\n" .
            "MS1*%s*ID*ID~\n" .
            "SE*7*0001~\n" .
            "GE*1*1~\n" .
            "IEA*1*000000001~",
            $dateStr,
            $timeStr,
            $dateStr,
            $timeStr,
            $shipmentId,
            $shipmentId,
            $ediCode,
            $dateStr,
            $timeStr,
            strtoupper($locationCity)
        );

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

  // 5. Service: Edi210Service (Freight Invoice Outbound Generator)
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

        $x12 = sprintf(
            "ISA*00*          *00*          *ZZ*OPTIDELI       *ZZ*FINANCEERP     *%s*%s*U*00401*000000001*0*P*>~\n" .
            "GS*IM*OPTIDELI*FINANCEERP*%s*%s*1*X*004010~\n" .
            "ST*210*0001~\n" .
            "B3*B*%s*%s*PP*%s*%d**IDR~\n" .
            "N1*BT*CUSTOMER-ACCOUNTS-PAYABLE~\n" .
            "N1*CA*OPTIDELI-OPERATIONS~\n" .
            "L3*0*G*%d~" . "\n" .
            "SE*7*0001~\n" .
            "GE*1*1~\n" .
            "IEA*1*000000001~",
            $dateStr,
            $timeStr,
            $dateStr,
            $timeStr,
            $invoiceNumber,
            $orderId,
            $dateStr,
            $totalAmountMinor,
            $totalAmountMinor
        );

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

  // 6. Controller: EdiGatewayController
  {
    path: 'backend/app/Http/Controllers/Api/EdiGatewayController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\EdiTransmission;
use App\\Services\\EDI\\Edi204Service;
use App\\Services\\EDI\\Edi210Service;
use App\\Services\\EDI\\Edi214Service;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class EdiGatewayController extends Controller
{
    public function __construct(
        protected Edi204Service $edi204,
        protected Edi214Service $edi214,
        protected Edi210Service $edi210
    ) {}

    public function inbound204(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'raw_x12' => 'required|string',
        ]);

        $orderData = $this->edi204->parseX12($tenantId, $validated['raw_x12']);

        return response()->json([
            'data' => $orderData,
            'message' => 'EDI 204 Load Tender berhasil diproses dan disimpan.',
        ], 201);
    }

    public function generate214(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'shipment_id' => 'required|string',
            'status' => 'required|in:DISPATCHED,EN_ROUTE,ARRIVED,DELIVERED',
            'location_city' => 'required|string',
            'eta' => 'nullable|string',
        ]);

        $x12 = $this->edi214->generateX12(
            $tenantId,
            $validated['shipment_id'],
            $validated['status'],
            $validated['location_city'],
            $validated['eta'] ?? null
        );

        return response()->json([
            'raw_x12' => $x12,
            'message' => 'EDI 214 Shipment Status berhasil dipancarkan.',
        ]);
    }

    public function sync210(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'invoice_number' => 'required|string',
            'order_id' => 'required|string',
            'customer_ar_minor_units' => 'required|integer|min:0',
            'vendor_ap_minor_units' => 'required|integer|min:0',
            'accessorials_minor_units' => 'nullable|integer|min:0',
        ]);

        $x12 = $this->edi210->generateX12(
            $tenantId,
            $validated['invoice_number'],
            $validated['order_id'],
            $validated['customer_ar_minor_units'],
            $validated['vendor_ap_minor_units'],
            $validated['accessorials_minor_units'] ?? 0
        );

        return response()->json([
            'raw_x12' => $x12,
            'message' => 'EDI 210 Freight Details Invoice berhasil disinkronisasikan.',
        ]);
    }

    public function transmissions(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $transmissions = EdiTransmission::where('tenant_id', $tenantId)
            ->orderByDesc('transmitted_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $transmissions,
        ]);
    }
}
`
  },

  // 7. Feature Test: EdiGatewayTest
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
        $sample204 = "ISA*00*          *00*          *ZZ*CUSTOMERERP    *ZZ*OPTIDELI       *261023*1530*U*00401*000000001*0*P*>~\n" .
            "GS*SM*CUSTOMERERP*OPTIDELI*20261023*1530*1*X*004010~\n" .
            "ST*204*0001~\n" .
            "B2**CARRIER*PO-882199**PP~\n" .
            "N1*SH*JAKARTA-HUB-01~\n" .
            "N1*CN*SURABAYA-CROSSDOCK-02~\n" .
            "OID*ELEKTRONIK CONSUMER*100*CT*2500*KG~\n" .
            "L3*2500*G*550000000~\n" .
            "SE*8*0001~\n" .
            "GE*1*1~\n" .
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
`
  },

  // 8. Frontend Types: edi.ts
  {
    path: 'frontend/src/types/edi.ts',
    content: `export interface EdiTransmission {
  id: string;
  tenant_id: string;
  transaction_set: '204' | '214' | '210';
  direction: 'INBOUND' | 'OUTBOUND';
  reference_id: string;
  raw_content: string;
  parsed_payload: Record<string, unknown> | null;
  status: 'PROCESSED' | 'TRANSMITTED' | 'FAILED';
  transmitted_at: string;
}
`
  },

  // 9. Frontend Component: EdiGatewayConsole.tsx
  {
    path: 'frontend/src/components/EDI/EdiGatewayConsole.tsx',
    content: `import React, { useState, useEffect } from 'react';
import type { EdiTransmission } from '../../types/edi';

export const EdiGatewayConsole: React.FC = () => {
  const [transmissions, setTransmissions] = useState<EdiTransmission[]>([]);
  const [selectedX12, setSelectedX12] = useState<EdiTransmission | null>(null);

  // Form Simulator State
  const [testSet, setTestSet] = useState<'204' | '214' | '210'>('204');
  const [shipmentId, setShipmentId] = useState('PO-TEST-9001');

  const fetchTransmissions = async () => {
    try {
      const res = await fetch('/api/v1/edi/transmissions', {
        headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
      });
      const data = await res.json();
      setTransmissions(data.data || []);
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    fetchTransmissions();
  }, []);

  const handleSimulate = async () => {
    if (testSet === '204') {
      const sample = \`ISA*00*          *00*          *ZZ*ERP-CUSTOMER   *ZZ*OPTIDELI       *261023*1500*U*00401*000000001*0*P*>~\\nGS*SM*ERP-CUSTOMER*OPTIDELI*20261023*1500*1*X*004010~\\nST*204*0001~\\nB2**CARRIER*\${shipmentId}**PP~\\nN1*SH*JAKARTA-LOGISTICS-HUB~\\nN1*CN*SURABAYA-DIST-CENTER~\\nOID*FMCG RETAIL GOODS*50*CT*1200*KG~\\nL3*1200*G*480000000~\\nSE*8*0001~\\nGE*1*1~\\nIEA*1*000000001~\`;
      await fetch('/api/v1/edi/inbound-204', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': 'demo', Accept: 'application/json' },
        body: JSON.stringify({ raw_x12: sample }),
      });
    } else if (testSet === '214') {
      await fetch('/api/v1/edi/generate-214', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': 'demo', Accept: 'application/json' },
        body: JSON.stringify({
          shipment_id: shipmentId,
          status: 'DELIVERED',
          location_city: 'SURABAYA',
        }),
      });
    } else {
      await fetch('/api/v1/edi/sync-210', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': 'demo', Accept: 'application/json' },
        body: JSON.stringify({
          invoice_number: \`INV-\${shipmentId}\`,
          order_id: shipmentId,
          customer_ar_minor_units: 480000000,
          vendor_ap_minor_units: 390000000,
          accessorials_minor_units: 20000000,
        }),
      });
    }
    fetchTransmissions();
  };

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">Enterprise EDI Gateway (X12 Standard)</h2>
          <p className="text-sm text-slate-400">
            Integrasi protokol EDI 204 (Load Tender), EDI 214 (Status Pelacakan), dan EDI 210 (Tagihan Finansial).
          </p>
        </div>
        <button
          onClick={fetchTransmissions}
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-cyan-400 hover:bg-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {/* Simulator Quick Action */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs">
        <span className="font-semibold text-slate-300">Simulator Transmisi:</span>
        <select
          value={testSet}
          onChange={(e) => setTestSet(e.target.value as '204' | '214' | '210')}
          className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-slate-200"
        >
          <option value="204">EDI 204 (Inbound Load Tender)</option>
          <option value="214">EDI 214 (Outbound Status Tracking)</option>
          <option value="210">EDI 210 (Freight Invoice Sync)</option>
        </select>
        <input
          type="text"
          value={shipmentId}
          onChange={(e) => setShipmentId(e.target.value)}
          placeholder="Ref ID"
          className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-slate-200"
        />
        <button
          onClick={handleSimulate}
          className="rounded bg-cyan-600 px-4 py-1.5 font-semibold text-white transition hover:bg-cyan-500"
        >
          Simulasikan Transmisi
        </button>
      </div>

      {/* Transmissions Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-700 bg-slate-800/60 text-xs font-semibold uppercase tracking-wider text-slate-300">
              <th className="px-4 py-3">Set EDI</th>
              <th className="px-4 py-3">Arah</th>
              <th className="px-4 py-3">Nomor Referensi</th>
              <th className="px-4 py-3">Waktu Transmisi</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-center">Payload X12</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800">
            {transmissions.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-6 text-center text-slate-500">
                  Belum ada transmisi EDI tercatat.
                </td>
              </tr>
            ) : (
              transmissions.map((t) => (
                <tr key={t.id} className="transition hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-mono font-bold text-cyan-300">EDI {t.transaction_set}</td>
                  <td className="px-4 py-3">
                    <span
                      className={\`rounded px-2 py-0.5 text-xs font-bold \${
                        t.direction === 'INBOUND'
                          ? 'border border-cyan-800 bg-cyan-950 text-cyan-400'
                          : 'border border-emerald-800 bg-emerald-950 text-emerald-400'
                      }\`}
                    >
                      {t.direction}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-mono text-slate-200">{t.reference_id}</td>
                  <td className="px-4 py-3 text-xs text-slate-400">
                    {new Date(t.transmitted_at).toLocaleString('id-ID')}
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded-full border border-emerald-800 bg-emerald-950 px-2 py-0.5 text-xs text-emerald-400">
                      {t.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <button
                      onClick={() => setSelectedX12(t)}
                      className="rounded border border-slate-700 bg-slate-800 px-2.5 py-1 text-xs text-cyan-400 hover:bg-slate-700"
                    >
                      Lihat X12
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Modal Inspector Raw ANSI X12 */}
      {selectedX12 && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
          <div className="w-full max-w-2xl rounded-xl border border-slate-800 bg-slate-950 p-6 shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <h3 className="font-bold text-cyan-400">
                  ANSI X12 Message Detail (EDI {selectedX12.transaction_set})
                </h3>
                <p className="text-xs text-slate-400">Ref: {selectedX12.reference_id}</p>
              </div>
              <button onClick={() => setSelectedX12(null)} className="text-slate-400 hover:text-white">
                ✕
              </button>
            </div>
            <pre className="mt-4 max-h-96 overflow-x-auto rounded-lg border border-slate-800 bg-slate-900 p-4 font-mono text-xs text-emerald-400">
              {selectedX12.raw_content}
            </pre>
            <div className="mt-4 flex justify-end">
              <button
                onClick={() => setSelectedX12(null)}
                className="rounded bg-slate-800 px-4 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
`
  }
];

// Daftarkan rute EDI ke backend/routes/web.php
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let webContent = fs.readFileSync(webPath, 'utf8');

if (!webContent.includes('EdiGatewayController')) {
  const snippet = `
// ==========================================
// INCREMENT 5: ENTERPRISE EDI GATEWAY
// ==========================================
use App\\Http\\Controllers\\Api\\EdiGatewayController;

Route::prefix('api/v1')->group(function () {
    Route::post('/edi/inbound-204', [EdiGatewayController::class, 'inbound204']);
    Route::post('/edi/generate-214', [EdiGatewayController::class, 'generate214']);
    Route::post('/edi/sync-210', [EdiGatewayController::class, 'sync210']);
    Route::get('/edi/transmissions', [EdiGatewayController::class, 'transmissions']);
});
`;
  webContent = webContent.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, webContent, 'utf8');
  console.log('✅ Rute EDI Gateway didaftarkan ke backend/routes/web.php');
}

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});

console.log('\nSelesai! Seluruh komponen Increment 5 telah dibuat.');