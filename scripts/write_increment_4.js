// scripts/write_increment_4.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Migration: carbon_emission_records
  {
    path: 'backend/database/migrations/2026_10_23_000004_create_carbon_emission_records_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_emission_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id');
            $table->string('fleet_ownership'); // INTERNAL | VENDOR
            $table->string('fuel_type'); // DIESEL_B35 | DIESEL_EURO4 | CNG | EV
            $table->float('distance_km');
            $table->float('cargo_weight_ton');
            $table->float('ton_km');
            $table->float('emission_factor_g_per_ton_km');
            $table->float('total_co2e_kg');
            $table->string('scope'); // SCOPE_1 | SCOPE_3
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_emission_records');
    }
};
`
  },

  // 2. Model: CarbonEmissionRecord
  {
    path: 'backend/app/Models/CarbonEmissionRecord.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class CarbonEmissionRecord extends Model
{
    use HasUuids;

    protected $table = 'carbon_emission_records';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'fleet_ownership',
        'fuel_type',
        'distance_km',
        'cargo_weight_ton',
        'ton_km',
        'emission_factor_g_per_ton_km',
        'total_co2e_kg',
        'scope',
        'recorded_at',
    ];

    protected $casts = [
        'distance_km' => 'float',
        'cargo_weight_ton' => 'float',
        'ton_km' => 'float',
        'emission_factor_g_per_ton_km' => 'float',
        'total_co2e_kg' => 'float',
        'recorded_at' => 'datetime',
    ];
}
`
  },

  // 3. Domain Service: EsgCarbonEngineService
  {
    path: 'backend/app/Services/Analytics/EsgCarbonEngineService.php',
    content: `<?php

namespace App\\Services\\Analytics;

use App\\Models\\CarbonEmissionRecord;
use Carbon\\Carbon;
use InvalidArgumentException;

class EsgCarbonEngineService
{
    /**
     * Standar Faktor Emisi GLEC / ISO 14083 (gCO2e / ton-km)
     */
    protected const EMISSION_FACTORS = [
        'DIESEL_B35' => 110.0,
        'DIESEL_EURO4' => 125.0,
        'CNG' => 85.0,
        'EV' => 20.0,
    ];

    /**
     * Menghitung dan menyimpan jejak karbon trip.
     *
     * @param array{
     *   trip_id: string,
     *   vehicle_id: string,
     *   fleet_ownership: string,
     *   fuel_type: string,
     *   distance_km: float,
     *   cargo_weight_ton: float
     * } $data
     */
    public function recordTripEmission(string $tenantId, array $data): CarbonEmissionRecord
    {
        $fuelType = strtoupper($data['fuel_type']);
        if (! isset(self::EMISSION_FACTORS[$fuelType])) {
            throw new InvalidArgumentException(sprintf('Tipe bahan bakar %s tidak dikenali dalam standar GLEC.', $fuelType));
        }

        $distanceKm = max(0.1, (float) $data['distance_km']);
        $weightTon = max(0.01, (float) $data['cargo_weight_ton']);
        $tonKm = round($distanceKm * $weightTon, 3);

        $factor = self::EMISSION_FACTORS[$fuelType];
        $totalCo2eKg = round(($tonKm * $factor) / 1000.0, 3);

        $isInternal = strtoupper($data['fleet_ownership']) === 'INTERNAL';
        $scope = $isInternal ? 'SCOPE_1' : 'SCOPE_3';

        return CarbonEmissionRecord::create([
            'tenant_id' => $tenantId,
            'trip_id' => $data['trip_id'],
            'vehicle_id' => $data['vehicle_id'],
            'fleet_ownership' => $isInternal ? 'INTERNAL' : 'VENDOR',
            'fuel_type' => $fuelType,
            'distance_km' => $distanceKm,
            'cargo_weight_ton' => $weightTon,
            'ton_km' => $tonKm,
            'emission_factor_g_per_ton_km' => $factor,
            'total_co2e_kg' => $totalCo2eKg,
            'scope' => $scope,
            'recorded_at' => Carbon::now(),
        ]);
    }

    /**
     * Menghitung ringkasan metrik ESG karbon untuk dashboard.
     *
     * @return array<string, mixed>
     */
    public function getSummary(string $tenantId): array
    {
        $records = CarbonEmissionRecord::where('tenant_id', $tenantId)->get();

        $totalCo2eKg = (float) $records->sum('total_co2e_kg');
        $totalTonKm = (float) $records->sum('ton_km');
        $scope1Kg = (float) $records->where('scope', 'SCOPE_1')->sum('total_co2e_kg');
        $scope3Kg = (float) $records->where('scope', 'SCOPE_3')->sum('total_co2e_kg');

        $averageIntensity = $totalTonKm > 0.0
            ? round(($totalCo2eKg * 1000.0) / $totalTonKm, 2)
            : 0.0;

        return [
            'total_emissions_ton_co2e' => round($totalCo2eKg / 1000.0, 3),
            'total_emissions_kg_co2e' => round($totalCo2eKg, 2),
            'total_ton_km' => round($totalTonKm, 2),
            'average_intensity_g_per_ton_km' => $averageIntensity,
            'scope_breakdown' => [
                'scope_1_kg' => round($scope1Kg, 2),
                'scope_1_pct' => $totalCo2eKg > 0 ? round(($scope1Kg / $totalCo2eKg) * 100, 1) : 0,
                'scope_3_kg' => round($scope3Kg, 2),
                'scope_3_pct' => $totalCo2eKg > 0 ? round(($scope3Kg / $totalCo2eKg) * 100, 1) : 0,
            ],
            'total_trips_logged' => $records->count(),
        ];
    }
}
`
  },

  // 4. Controller: EsgCarbonController
  {
    path: 'backend/app/Http/Controllers/Api/EsgCarbonController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\CarbonEmissionRecord;
use App\\Services\\Analytics\\EsgCarbonEngineService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class EsgCarbonController extends Controller
{
    public function __construct(
        protected EsgCarbonEngineService $esgEngine
    ) {}

    public function recordTrip(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'trip_id' => 'required|string',
            'vehicle_id' => 'required|string',
            'fleet_ownership' => 'required|in:INTERNAL,VENDOR,internal,vendor',
            'fuel_type' => 'required|in:DIESEL_B35,DIESEL_EURO4,CNG,EV,diesel_b35,diesel_euro4,cng,ev',
            'distance_km' => 'required|numeric|min:0.1',
            'cargo_weight_ton' => 'required|numeric|min:0.01',
        ]);

        $record = $this->esgEngine->recordTripEmission($tenantId, $validated);

        return response()->json([
            'data' => $record,
            'message' => 'Jejak emisi karbon berhasil dihitung dan dicatat.',
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $summary = $this->esgEngine->getSummary($tenantId);

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $records = CarbonEmissionRecord::where('tenant_id', $tenantId)
            ->orderByDesc('recorded_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $records,
        ]);
    }
}
`
  },

  // 5. Feature Test: EsgCarbonTest
  {
    path: 'backend/tests/Feature/EsgCarbonTest.php',
    content: `<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class EsgCarbonTest extends TestCase
{
    use RefreshDatabase;

    public function test_esg_carbon_calculator_segregates_scope1_and_scope3(): void
    {
        $tenantId = 'demo';

        // 1. Catat Trip Armada Sendiri (Scope 1)
        $res1 = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/esg/calculate-trip', [
                'trip_id' => 'TRIP-OWN-01',
                'vehicle_id' => 'B-1001-OWN',
                'fleet_ownership' => 'INTERNAL',
                'fuel_type' => 'DIESEL_B35',
                'distance_km' => 200.0,
                'cargo_weight_ton' => 10.0, // 2000 ton-km * 110 g/ton-km / 1000 = 220 kg CO2e
            ]);

        $res1->assertStatus(201);
        $this->assertEquals('SCOPE_1', $res1->json('data.scope'));
        $this->assertEquals(220.0, $res1->json('data.total_co2e_kg'));

        // 2. Catat Trip Vendor Subkontraktor (Scope 3)
        $res2 = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/esg/calculate-trip', [
                'trip_id' => 'TRIP-VND-02',
                'vehicle_id' => 'B-2002-VND',
                'fleet_ownership' => 'VENDOR',
                'fuel_type' => 'DIESEL_EURO4',
                'distance_km' => 100.0,
                'cargo_weight_ton' => 5.0, // 500 ton-km * 125 g/ton-km / 1000 = 62.5 kg CO2e
            ]);

        $res2->assertStatus(201);
        $this->assertEquals('SCOPE_3', $res2->json('data.scope'));
        $this->assertEquals(62.5, $res2->json('data.total_co2e_kg'));

        // 3. Verifikasi Dashboard Summary
        $resSummary = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->getJson('/api/v1/esg/summary');

        $resSummary->assertStatus(200);
        $summary = $resSummary->json('data');

        $this->assertEquals(2, $summary['total_trips_logged']);
        $this->assertEquals(282.5, $summary['total_emissions_kg_co2e']);
        $this->assertEquals(220.0, $summary['scope_breakdown']['scope_1_kg']);
        $this->assertEquals(62.5, $summary['scope_breakdown']['scope_3_kg']);
    }
}
`
  },

  // 6. Frontend Types: esg.ts
  {
    path: 'frontend/src/types/esg.ts',
    content: `export interface CarbonEmissionRecord {
  id: string;
  trip_id: string;
  vehicle_id: string;
  fleet_ownership: 'INTERNAL' | 'VENDOR';
  fuel_type: 'DIESEL_B35' | 'DIESEL_EURO4' | 'CNG' | 'EV';
  distance_km: number;
  cargo_weight_ton: number;
  ton_km: number;
  emission_factor_g_per_ton_km: number;
  total_co2e_kg: number;
  scope: 'SCOPE_1' | 'SCOPE_3';
  recorded_at: string;
}

export interface EsgSummary {
  total_emissions_ton_co2e: number;
  total_emissions_kg_co2e: number;
  total_ton_km: number;
  average_intensity_g_per_ton_km: number;
  scope_breakdown: {
    scope_1_kg: number;
    scope_1_pct: number;
    scope_3_kg: number;
    scope_3_pct: number;
  };
  total_trips_logged: number;
}
`
  },

  // 7. Frontend Component: EsgCarbonDashboard.tsx
  {
    path: 'frontend/src/components/Analytics/EsgCarbonDashboard.tsx',
    content: `import React, { useState, useEffect } from 'react';
import type { CarbonEmissionRecord, EsgSummary } from '../../types/esg';

export const EsgCarbonDashboard: React.FC = () => {
  const [summary, setSummary] = useState<EsgSummary | null>(null);
  const [records, setRecords] = useState<CarbonEmissionRecord[]>([]);

  // Form State
  const [tripId, setTripId] = useState('TRIP-901');
  const [vehicleId, setVehicleId] = useState('B-8821-DEL');
  const [ownership, setOwnership] = useState<'INTERNAL' | 'VENDOR'>('INTERNAL');
  const [fuelType, setFuelType] = useState('DIESEL_B35');
  const [distanceKm, setDistanceKm] = useState(180);
  const [cargoTon, setCargoTon] = useState(8.5);

  const fetchEsgData = async () => {
    try {
      const [sumRes, recRes] = await Promise.all([
        fetch('/api/v1/esg/summary', { headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' } }),
        fetch('/api/v1/esg/records', { headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' } }),
      ]);
      const sumData = await sumRes.json();
      const recData = await recRes.json();
      setSummary(sumData.data);
      setRecords(recData.data || []);
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    fetchEsgData();
  }, []);

  const handleCalculate = async (e: React.FormEvent) => {
    e.preventDefault();
    await fetch('/api/v1/esg/calculate-trip', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Tenant-Id': 'demo',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        trip_id: tripId,
        vehicle_id: vehicleId,
        fleet_ownership: ownership,
        fuel_type: fuelType,
        distance_km: distanceKm,
        cargo_weight_ton: cargoTon,
      }),
    });
    fetchEsgData();
  };

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">ESG Carbon Tracking & GLEC Reporting</h2>
          <p className="text-sm text-slate-400">
            Kalkulator emisi gas rumah kaca berbasis ton-kilometer (Standar ISO 14083 / GLEC Framework).
          </p>
        </div>
        <button
          onClick={fetchEsgData}
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-cyan-400 hover:bg-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {/* KPI Cards */}
      {summary && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">Total Emisi Karbon</span>
            <p className="mt-1 font-mono text-2xl font-bold text-emerald-400">
              {summary.total_emissions_ton_co2e} <span className="text-sm font-normal text-slate-400">tCO₂e</span>
            </p>
            <p className="mt-1 text-xs text-slate-500">{summary.total_emissions_kg_co2e} kg CO₂e</p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">Intensitas Emisi Rata-Rata</span>
            <p className="mt-1 font-mono text-2xl font-bold text-cyan-400">
              {summary.average_intensity_g_per_ton_km}{' '}
              <span className="text-sm font-normal text-slate-400">g/ton-km</span>
            </p>
            <p className="mt-1 text-xs text-slate-500">Benchmark GLEC Nasional</p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">Scope 1 (Armada Sendiri)</span>
            <p className="mt-1 font-mono text-xl font-bold text-cyan-300">
              {summary.scope_breakdown.scope_1_kg} kg{' '}
              <span className="text-xs font-normal text-slate-400">({summary.scope_breakdown.scope_1_pct}%)</span>
            </p>
            <div className="mt-2 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
              <div
                className="h-full bg-cyan-400"
                style={{ width: \`\${summary.scope_breakdown.scope_1_pct}%\` }}
              />
            </div>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">Scope 3 (Vendor / 3PL)</span>
            <p className="mt-1 font-mono text-xl font-bold text-orange-300">
              {summary.scope_breakdown.scope_3_kg} kg{' '}
              <span className="text-xs font-normal text-slate-400">({summary.scope_breakdown.scope_3_pct}%)</span>
            </p>
            <div className="mt-2 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
              <div
                className="h-full bg-orange-400"
                style={{ width: \`\${summary.scope_breakdown.scope_3_pct}%\` }}
              />
            </div>
          </div>
        </div>
      )}

      {/* Simulator Input Form */}
      <form onSubmit={handleCalculate} className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-4">
        <h3 className="text-sm font-semibold text-slate-200">Kalkulasi Emisi Trip Baru</h3>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
          <input
            type="text"
            placeholder="Trip ID"
            value={tripId}
            onChange={(e) => setTripId(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <input
            type="text"
            placeholder="No Polisi / Armada"
            value={vehicleId}
            onChange={(e) => setVehicleId(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <select
            value={ownership}
            onChange={(e) => setOwnership(e.target.value as 'INTERNAL' | 'VENDOR')}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
          >
            <option value="INTERNAL">Armada Internal (Scope 1)</option>
            <option value="VENDOR">Mitra Vendor (Scope 3)</option>
          </select>
          <select
            value={fuelType}
            onChange={(e) => setFuelType(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
          >
            <option value="DIESEL_B35">Bio-Solar B35 (110 g/t-km)</option>
            <option value="DIESEL_EURO4">Diesel Euro 4 (125 g/t-km)</option>
            <option value="CNG">Gas CNG (85 g/t-km)</option>
            <option value="EV">Electric / EV (20 g/t-km)</option>
          </select>
          <input
            type="number"
            placeholder="Jarak (km)"
            value={distanceKm}
            onChange={(e) => setDistanceKm(Number(e.target.value))}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <input
            type="number"
            step="0.1"
            placeholder="Muatan (Ton)"
            value={cargoTon}
            onChange={(e) => setCargoTon(Number(e.target.value))}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
        </div>
        <div className="flex justify-end">
          <button
            type="submit"
            className="rounded bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-500"
          >
            Hitung & Catat Emisi
          </button>
        </div>
      </form>

      {/* Audit Trail Records Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-700 bg-slate-800/60 text-xs font-semibold uppercase tracking-wider text-slate-300">
              <th className="px-4 py-3">Trip ID</th>
              <th className="px-4 py-3">Armada</th>
              <th className="px-4 py-3">Scope</th>
              <th className="px-4 py-3">Bahan Bakar</th>
              <th className="px-4 py-3 text-right">Ton-Km</th>
              <th className="px-4 py-3 text-right">Total Emisi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800">
            {records.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-6 text-center text-slate-500">
                  Belum ada log emisi karbon yang dicatat.
                </td>
              </tr>
            ) : (
              records.map((r) => (
                <tr key={r.id} className="transition hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-mono text-cyan-400">{r.trip_id}</td>
                  <td className="px-4 py-3 text-slate-200">{r.vehicle_id}</td>
                  <td className="px-4 py-3">
                    <span
                      className={\`rounded px-2 py-0.5 text-xs font-bold \${
                        r.scope === 'SCOPE_1'
                          ? 'bg-cyan-950 text-cyan-400 border border-cyan-800'
                          : 'bg-orange-950 text-orange-400 border border-orange-800'
                      }\`}
                    >
                      {r.scope}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-400 text-xs">{r.fuel_type}</td>
                  <td className="px-4 py-3 text-right font-mono text-slate-300">{r.ton_km} t·km</td>
                  <td className="px-4 py-3 text-right font-mono font-bold text-emerald-400">
                    {r.total_co2e_kg} kg CO₂e
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};
`
  }
];

// Daftarkan rute ESG ke backend/routes/web.php
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let webContent = fs.readFileSync(webPath, 'utf8');

if (!webContent.includes('EsgCarbonController')) {
  const snippet = `
// ==========================================
// INCREMENT 4: ESG CARBON TRACKING & GLEC
// ==========================================
use App\\Http\\Controllers\\Api\\EsgCarbonController;

Route::prefix('api/v1')->group(function () {
    Route::post('/esg/calculate-trip', [EsgCarbonController::class, 'recordTrip']);
    Route::get('/esg/summary', [EsgCarbonController::class, 'summary']);
    Route::get('/esg/records', [EsgCarbonController::class, 'records']);
});
`;
  webContent = webContent.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, webContent, 'utf8');
  console.log('✅ Rute ESG didaftarkan ke backend/routes/web.php');
}

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});

console.log('\nSelesai! Seluruh komponen Increment 4 telah dibuat.');