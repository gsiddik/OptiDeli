// scripts/write_increment_2b_part2.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Controller VendorRateCard
  {
    path: 'backend/app/Http/Controllers/Api/VendorRateCardController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\VendorRateCard;
use App\\Services\\Tendering\\RateCardEngineService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class VendorRateCardController extends Controller
{
    public function __construct(
        protected RateCardEngineService $rateEngine
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $cards = VendorRateCard::where('tenant_id', $tenantId)
            ->orderBy('vendor_id')
            ->orderBy('vehicle_type')
            ->get();

        return response()->json(['data' => $cards]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'vendor_id' => 'required|string',
            'vehicle_type' => 'required|string',
            'zone_origin' => 'required|string',
            'zone_destination' => 'required|string',
            'base_rate_minor_units' => 'required|integer|min:0',
            'rate_per_km_minor_units' => 'required|integer|min:0',
            'min_distance_km' => 'required|integer|min:0',
        ]);

        $card = VendorRateCard::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'vendor_id' => $validated['vendor_id'],
                'vehicle_type' => $validated['vehicle_type'],
                'zone_origin' => $validated['zone_origin'],
                'zone_destination' => $validated['zone_destination'],
            ],
            array_merge($validated, ['is_active' => true])
        );

        return response()->json(['data' => $card, 'message' => 'Rate card berhasil disimpan.'], 201);
    }

    public function calculateEstimate(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'vendor_id' => 'required|string',
            'vehicle_type' => 'required|string',
            'zone_origin' => 'required|string',
            'zone_destination' => 'required|string',
            'distance_km' => 'required|numeric|min:0',
        ]);

        $costMinorUnits = $this->rateEngine->calculateEstimatedCost(
            $tenantId,
            $validated['vendor_id'],
            $validated['vehicle_type'],
            $validated['zone_origin'],
            $validated['zone_destination'],
            (float) $validated['distance_km']
        );

        return response()->json([
            'estimated_cost_minor_units' => $costMinorUnits,
            'estimated_cost_idr' => $costMinorUnits / 100,
        ]);
    }
}
`
  },

  // 2. Controller WaterfallTender
  {
    path: 'backend/app/Http/Controllers/Api/WaterfallTenderController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\TenderDispatch;
use App\\Services\\Tendering\\WaterfallTenderEngineService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class WaterfallTenderController extends Controller
{
    public function __construct(
        protected WaterfallTenderEngineService $tenderEngine
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $dispatches = TenderDispatch::where('tenant_id', $tenantId)
            ->with('offers')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => $dispatches]);
    }

    public function startWaterfall(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'order_id' => 'required|uuid',
            'timeout_minutes' => 'nullable|integer|min:5|max:1440',
            'ranked_vendors' => 'required|array|min:1',
            'ranked_vendors.*.vendor_id' => 'required|string',
            'ranked_vendors.*.vehicle_type' => 'required|string',
            'ranked_vendors.*.zone_origin' => 'required|string',
            'ranked_vendors.*.zone_destination' => 'required|string',
            'ranked_vendors.*.distance_km' => 'required|numeric|min:0',
        ]);

        $dispatch = $this->tenderEngine->initiateWaterfall(
            $tenantId,
            $validated['order_id'],
            $validated['ranked_vendors'],
            $validated['timeout_minutes'] ?? 30
        );

        return response()->json(['data' => $dispatch, 'message' => 'Waterfall tender berhasil diinisiasi.'], 201);
    }

    public function respondOffer(Request $request, string $offerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'action' => 'required|in:ACCEPT,REJECT',
            'rejection_reason' => 'nullable|string|max:255',
        ]);

        $dispatch = $this->tenderEngine->processVendorResponse(
            $tenantId,
            $offerId,
            $validated['action'],
            $validated['rejection_reason'] ?? null
        );

        return response()->json(['data' => $dispatch, 'message' => 'Tawaran tender berhasil diproses.']);
    }
}
`
  },

  // 3. Automated Feature Test (PHPUnit)
  {
    path: 'backend/tests/Feature/WaterfallTenderTest.php',
    content: `<?php

namespace Tests\\Feature;

use App\\Models\\VendorRateCard;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class WaterfallTenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_card_minor_unit_calculation_and_waterfall_cascading(): void
    {
        $tenantId = 'demo';
        $orderId = (string) Str::uuid();

        // 1. Setup Rate Cards (1 unit = Rp 0,01)
        // Vendor A: Rp 3.500.000 (350.000.000 minor units), Rp 4.500/km (450.000 minor units)
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

        // Vendor B: Rp 3.800.000 (380.000.000 minor units)
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
        $response = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/tenders/waterfall/start', [
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
                ]
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
        $rejectResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson("/api/v1/tenders/offers/{$offerAlphaId}/respond", [
                'action' => 'REJECT',
                'rejection_reason' => 'Unit sedang perawatan berkala.',
            ]);

        $rejectResponse->assertStatus(200);
        $offersUpdated = $rejectResponse->json('data.offers');
        $this->assertEquals('REJECTED', $offersUpdated[0]['status']);
        $this->assertEquals('OFFERED', $offersUpdated[1]['status']); // Ter-eskalasi otomatis!

        // 4. Vendor BETA menerima -> tender berstatus ACCEPTED
        $acceptResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson("/api/v1/tenders/offers/{$offerBetaId}/respond", [
                'action' => 'ACCEPT',
            ]);

        $acceptResponse->assertStatus(200);
        $this->assertEquals('ACCEPTED', $acceptResponse->json('data.status'));
        $this->assertEquals('VND-BETA', $acceptResponse->json('data.accepted_vendor_id'));
    }
}
`
  },

  // 4. Frontend Component: RateCardDynamicGrid
  {
    path: 'frontend/src/components/RateCards/RateCardDynamicGrid.tsx',
    content: `import React, { useState, useEffect } from 'react';
import { VendorRateCard, formatRupiahFromMinor } from '../../types/tendering';

export const RateCardDynamicGrid: React.FC = () => {
  const [rateCards, setRateCards] = useState<VendorRateCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterZone, setFilterZone] = useState('');

  const [vendorId, setVendorId] = useState('');
  const [vehicleType, setVehicleType] = useState('CDD');
  const [origin, setOrigin] = useState('JKT-HUB');
  const [dest, setDest] = useState('SBY-HUB');
  const [baseRateRp, setBaseRateRp] = useState(3500000);
  const [ratePerKmRp, setRatePerKmRp] = useState(4500);

  const fetchRateCards = async () => {
    try {
      const res = await fetch('/api/v1/rate-cards', {
        headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
      });
      const data = await res.json();
      setRateCards(data.data || []);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRateCards();
  }, []);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    await fetch('/api/v1/rate-cards', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Tenant-Id': 'demo',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        vendor_id: vendorId,
        vehicle_type: vehicleType,
        zone_origin: origin,
        zone_destination: dest,
        base_rate_minor_units: Math.round(baseRateRp * 100),
        rate_per_km_minor_units: Math.round(ratePerKmRp * 100),
        min_distance_km: 0,
      }),
    });
    setVendorId('');
    fetchRateCards();
  };

  const filteredCards = filterZone
    ? rateCards.filter(c => c.zone_origin.toLowerCase().includes(filterZone.toLowerCase()) || c.zone_destination.toLowerCase().includes(filterZone.toLowerCase()))
    : rateCards;

  return (
    <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 text-slate-100 shadow-xl space-y-6">
      <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-slate-800 pb-4">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">Vendor Rate Card Matrix</h2>
          <p className="text-sm text-slate-400">Aturan tarif vendor berbasis zona, armada, dan minor unit IDR.</p>
        </div>
        <input
          type="text"
          placeholder="Cari zona..."
          value={filterZone}
          onChange={e => setFilterZone(e.target.value)}
          className="bg-slate-800 border border-slate-700 px-3 py-1.5 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
        />
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="bg-slate-800/60 text-slate-300 font-semibold uppercase tracking-wider text-xs border-b border-slate-700">
              <th className="py-3 px-4">Vendor ID</th>
              <th className="py-3 px-4">Armada</th>
              <th className="py-3 px-4">Rute (Origin → Dest)</th>
              <th className="py-3 px-4 text-right">Tarif Dasar</th>
              <th className="py-3 px-4 text-right">Per KM</th>
              <th className="py-3 px-4 text-center">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800">
            {loading ? (
              <tr><td colSpan={6} className="text-center py-6 text-slate-500">Memuat matriks tarif...</td></tr>
            ) : filteredCards.length === 0 ? (
              <tr><td colSpan={6} className="text-center py-6 text-slate-500">Belum ada rate card tercatat.</td></tr>
            ) : (
              filteredCards.map(rc => (
                <tr key={rc.id} className="hover:bg-slate-800/40 transition">
                  <td className="py-3 px-4 font-mono font-medium text-cyan-300">{rc.vendor_id}</td>
                  <td className="py-3 px-4"><span className="bg-slate-800 px-2 py-0.5 rounded text-xs text-orange-300 border border-orange-500/30">{rc.vehicle_type}</span></td>
                  <td className="py-3 px-4 text-slate-300">{rc.zone_origin} <span className="text-cyan-400">→</span> {rc.zone_destination}</td>
                  <td className="py-3 px-4 text-right font-mono font-semibold text-emerald-400">{formatRupiahFromMinor(rc.base_rate_minor_units)}</td>
                  <td className="py-3 px-4 text-right font-mono text-slate-400">{formatRupiahFromMinor(rc.rate_per_km_minor_units)}/km</td>
                  <td className="py-3 px-4 text-center">
                    <span className="text-xs px-2 py-0.5 rounded-full bg-emerald-950 text-emerald-400 border border-emerald-800">Aktif</span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <form onSubmit={handleSave} className="bg-slate-950 border border-slate-800 p-4 rounded-lg space-y-4">
        <h3 className="text-sm font-semibold text-slate-200">Tambah / Perbarui Rate Card</h3>
        <div className="grid grid-cols-2 md:grid-cols-6 gap-3">
          <input
            type="text"
            placeholder="Vendor ID (e.g. VND-01)"
            value={vendorId}
            onChange={e => setVendorId(e.target.value)}
            className="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-xs text-slate-200"
            required
          />
          <select
            value={vehicleType}
            onChange={e => setVehicleType(e.target.value)}
            className="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-xs text-slate-200"
          >
            <option value="CDE">CDE (Engkel)</option>
            <option value="CDD">CDD (Double)</option>
            <option value="FUSO">Fuso Box</option>
            <option value="WINGBOX">Wingbox</option>
            <option value="TRAILER">Trailer 40ft</option>
          </select>
          <input
            type="text"
            placeholder="Zone Origin"
            value={origin}
            onChange={e => setOrigin(e.target.value)}
            className="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-xs text-slate-200"
            required
          />
          <input
            type="text"
            placeholder="Zone Destination"
            value={dest}
            onChange={e => setDest(e.target.value)}
            className="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-xs text-slate-200"
            required
          />
          <input
            type="number"
            placeholder="Tarif Dasar (Rp)"
            value={baseRateRp}
            onChange={e => setBaseRateRp(Number(e.target.value))}
            className="bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-xs text-slate-200"
            required
          />
          <button
            type="submit"
            className="bg-cyan-600 hover:bg-cyan-500 font-semibold text-white px-4 py-1.5 rounded text-xs transition"
          >
            Simpan Matrix
          </button>
        </div>
      </form>
    </div>
  );
};
`
  },

  // 5. Frontend Component: TenderWaterfallBoard
  {
    path: 'frontend/src/components/Tendering/TenderWaterfallBoard.tsx',
    content: `import React, { useState, useEffect } from 'react';
import { TenderDispatch, formatRupiahFromMinor } from '../../types/tendering';

export const TenderWaterfallBoard: React.FC = () => {
  const [dispatches, setDispatches] = useState<TenderDispatch[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchTenders = async () => {
    try {
      const res = await fetch('/api/v1/tenders', {
        headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
      });
      const data = await res.json();
      setDispatches(data.data || []);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTenders();
    const interval = setInterval(fetchTenders, 10000);
    return () => clearInterval(interval);
  }, []);

  const handleRespond = async (offerId: string, action: 'ACCEPT' | 'REJECT') => {
    await fetch(\`/api/v1/tenders/offers/\${offerId}/respond\`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Tenant-Id': 'demo',
        Accept: 'application/json',
      },
      body: JSON.stringify({ action }),
    });
    fetchTenders();
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'OFFERED':
        return <span className="bg-amber-500/20 text-amber-400 border border-amber-500/40 px-2 py-0.5 rounded-full text-xs animate-pulse">Menunggu Respons</span>;
      case 'ACCEPTED':
        return <span className="bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 px-2 py-0.5 rounded-full text-xs">Telah Diterima</span>;
      case 'REJECTED':
        return <span className="bg-rose-500/20 text-rose-400 border border-rose-500/40 px-2 py-0.5 rounded-full text-xs">Ditolak</span>;
      case 'EXPIRED':
        return <span className="bg-slate-700 text-slate-400 px-2 py-0.5 rounded-full text-xs">Kedaluwarsa</span>;
      case 'BYPASSED':
        return <span className="bg-slate-800 text-slate-500 px-2 py-0.5 rounded-full text-xs">Bypassed</span>;
      default:
        return <span className="bg-slate-800 text-slate-400 px-2 py-0.5 rounded-full text-xs">Pending Antrean</span>;
    }
  };

  return (
    <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 text-slate-100 shadow-xl space-y-6">
      <div className="flex justify-between items-center border-b border-slate-800 pb-4">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">Automated Waterfall Tendering Console</h2>
          <p className="text-sm text-slate-400">Monitoring alokasi tender berjenjang ke vendor secara sekuensial.</p>
        </div>
        <button
          onClick={fetchTenders}
          className="bg-slate-800 hover:bg-slate-700 text-cyan-400 px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {loading ? (
        <div className="text-center py-10 text-slate-500">Memuat papan tender...</div>
      ) : dispatches.length === 0 ? (
        <div className="text-center py-10 text-slate-500">Belum ada proses tender yang berjalan.</div>
      ) : (
        <div className="space-y-6">
          {dispatches.map(td => (
            <div key={td.id} className="bg-slate-950 border border-slate-800 rounded-lg p-5 space-y-4">
              <div className="flex flex-wrap justify-between items-center gap-2">
                <div>
                  <span className="text-xs uppercase font-bold text-orange-400 bg-orange-950/60 px-2 py-0.5 rounded border border-orange-900">
                    TENDER #{td.id.substring(0, 8)}
                  </span>
                  <span className="ml-3 text-sm text-slate-300 font-mono">Order Ref: {td.order_id.substring(0, 8)}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Status Sesi:</span>
                  <span className={\`text-xs font-bold px-2 py-0.5 rounded \${
                    td.status === 'ACCEPTED' ? 'bg-emerald-950 text-emerald-400' :
                    td.status === 'RUNNING' ? 'bg-cyan-950 text-cyan-400' : 'bg-rose-950 text-rose-400'
                  }\`}>
                    {td.status}
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                {td.offers.map(offer => (
                  <div
                    key={offer.id}
                    className={\`p-3 rounded-lg border transition \${
                      offer.status === 'OFFERED'
                        ? 'bg-cyan-950/30 border-cyan-500/60 ring-1 ring-cyan-500/40'
                        : offer.status === 'ACCEPTED'
                        ? 'bg-emerald-950/30 border-emerald-500/50'
                        : 'bg-slate-900 border-slate-800 opacity-70'
                    }\`}
                  >
                    <div className="flex justify-between items-center mb-2">
                      <span className="text-xs font-bold text-slate-400">Tingkat #{offer.sequence}</span>
                      {getStatusBadge(offer.status)}
                    </div>
                    <p className="font-semibold text-slate-200">{offer.vendor_id}</p>
                    <p className="text-xs font-mono text-cyan-300 mt-1">
                      {formatRupiahFromMinor(offer.offered_cost_minor_units)}
                    </p>

                    {offer.status === 'OFFERED' && (
                      <div className="mt-3 flex gap-2">
                        <button
                          onClick={() => handleRespond(offer.id, 'ACCEPT')}
                          className="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs py-1 rounded font-medium transition"
                        >
                          Terima
                        </button>
                        <button
                          onClick={() => handleRespond(offer.id, 'REJECT')}
                          className="flex-1 bg-rose-600/80 hover:bg-rose-600 text-white text-xs py-1 rounded font-medium transition"
                        >
                          Tolak
                        </button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};
`
  }
];

// Append API routes ke backend/routes/api.php jika belum ada
const apiRoutesPath = path.resolve(process.cwd(), 'backend/routes/api.php');
if (fs.existsSync(apiRoutesPath)) {
  let content = fs.readFileSync(apiRoutesPath, 'utf8');
  if (!content.includes('VendorRateCardController')) {
    const routeSnippet = `

// ==========================================
// INCREMENT 2B: RATE CARD & WATERFALL TENDER
// ==========================================
use App\\Http\\Controllers\\Api\\VendorRateCardController;
use App\\Http\\Controllers\\Api\\WaterfallTenderController;

Route::prefix('v1')->group(function () {
    Route::get('/rate-cards', [VendorRateCardController::class, 'index']);
    Route::post('/rate-cards', [VendorRateCardController::class, 'store']);
    Route::post('/rate-cards/calculate-estimate', [VendorRateCardController::class, 'calculateEstimate']);

    Route::get('/tenders', [WaterfallTenderController::class, 'index']);
    Route::post('/tenders/waterfall/start', [WaterfallTenderController::class, 'startWaterfall']);
    Route::post('/tenders/offers/{offerId}/respond', [WaterfallTenderController::class, 'respondOffer']);
});
`;
    fs.appendFileSync(apiRoutesPath, routeSnippet, 'utf8');
    console.log('✅ Rute API berhasil didaftarkan ke backend/routes/api.php');
  }
}

console.log('Menulis komponen penutup Increment 2B (Controllers, Test, TSX)...');
files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});
console.log('\nSelesai! Seluruh komponen Increment 2B telah lengkap.');