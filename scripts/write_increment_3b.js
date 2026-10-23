// scripts/write_increment_3b.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Migration: telemetry_logs & telemetry_alerts
  {
    path: 'backend/database/migrations/2026_10_23_000003_create_telemetry_logs_and_alerts_table.php',
    content: `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id')->index();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('speed_kmh')->default(0);
            $table->float('fuel_level_pct')->default(100);
            $table->float('reefer_temp_celsius')->nullable();
            $table->boolean('is_door_open')->default(false);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'vehicle_id', 'recorded_at']);
        });

        Schema::create('telemetry_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('trip_id')->index();
            $table->string('vehicle_id')->index();
            $table->string('alert_type'); // OVERSPEED, REEFER_TEMP_BREACH, DOOR_OPEN_ENROUTE
            $table->string('severity'); // WARNING, CRITICAL
            $table->string('message');
            $table->float('metric_value')->nullable();
            $table->float('threshold_value')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_alerts');
        Schema::dropIfExists('telemetry_logs');
    }
};
`
  },

  // 2. Model: TelemetryLog
  {
    path: 'backend/app/Models/TelemetryLog.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class TelemetryLog extends Model
{
    use HasUuids;

    protected $table = 'telemetry_logs';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'fuel_level_pct',
        'reefer_temp_celsius',
        'is_door_open',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'speed_kmh' => 'float',
        'fuel_level_pct' => 'float',
        'reefer_temp_celsius' => 'float',
        'is_door_open' => 'boolean',
        'recorded_at' => 'datetime',
    ];
}
`
  },

  // 3. Model: TelemetryAlert
  {
    path: 'backend/app/Models/TelemetryAlert.php',
    content: `<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

class TelemetryAlert extends Model
{
    use HasUuids;

    protected $table = 'telemetry_alerts';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'alert_type',
        'severity',
        'message',
        'metric_value',
        'threshold_value',
        'is_resolved',
        'triggered_at',
    ];

    protected $casts = [
        'metric_value' => 'float',
        'threshold_value' => 'float',
        'is_resolved' => 'boolean',
        'triggered_at' => 'datetime',
    ];
}
`
  },

  // 4. Service: TelematicsEngineService
  {
    path: 'backend/app/Services/Telematics/TelematicsEngineService.php',
    content: `<?php

namespace App\\Services\\Telematics;

use App\\Models\\TelemetryAlert;
use App\\Models\\TelemetryLog;
use Carbon\\Carbon;
use Illuminate\\Support\\Facades\\DB;

class TelematicsEngineService
{
    /**
     * Memproses ping telematika IoT dan mengevaluasi pelanggaran ambang batas (Exception Alert).
     *
     * @param array{
     *   trip_id: string,
     *   vehicle_id: string,
     *   latitude: float,
     *   longitude: float,
     *   speed_kmh: float,
     *   fuel_level_pct: float,
     *   reefer_temp_celsius?: float|null,
     *   is_door_open?: bool
     * } $data
     * @return array{log: TelemetryLog, alerts: array<int, TelemetryAlert>}
     */
    public function processPing(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $now = Carbon::now();

            $log = TelemetryLog::create([
                'tenant_id' => $tenantId,
                'trip_id' => $data['trip_id'],
                'vehicle_id' => $data['vehicle_id'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'speed_kmh' => $data['speed_kmh'],
                'fuel_level_pct' => $data['fuel_level_pct'],
                'reefer_temp_celsius' => $data['reefer_temp_celsius'] ?? null,
                'is_door_open' => (bool) ($data['is_door_open'] ?? false),
                'recorded_at' => $now,
            ]);

            $generatedAlerts = [];

            // 1. Evaluasi Over-Speeding (Batas: 90 km/h)
            if ($data['speed_kmh'] > 90.0) {
                $generatedAlerts[] = TelemetryAlert::create([
                    'tenant_id' => $tenantId,
                    'trip_id' => $data['trip_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'alert_type' => 'OVERSPEED',
                    'severity' => $data['speed_kmh'] > 110.0 ? 'CRITICAL' : 'WARNING',
                    'message' => sprintf('Kecepatan kendaraan %.1f km/jam melampaui batas regulasi.', $data['speed_kmh']),
                    'metric_value' => $data['speed_kmh'],
                    'threshold_value' => 90.0,
                    'is_resolved' => false,
                    'triggered_at' => $now,
                ]);
            }

            // 2. Evaluasi Suhu Reefer Cold-Chain (Batas maksimal beku: -15.0 C)
            if (isset($data['reefer_temp_celsius']) && $data['reefer_temp_celsius'] !== null) {
                if ($data['reefer_temp_celsius'] > -15.0) {
                    $generatedAlerts[] = TelemetryAlert::create([
                        'tenant_id' => $tenantId,
                        'trip_id' => $data['trip_id'],
                        'vehicle_id' => $data['vehicle_id'],
                        'alert_type' => 'REEFER_TEMP_BREACH',
                        'severity' => 'CRITICAL',
                        'message' => sprintf('Integritas suhu kargo dingin terancam (Suhu saat ini: %.1f°C).', $data['reefer_temp_celsius']),
                        'metric_value' => $data['reefer_temp_celsius'],
                        'threshold_value' => -15.0,
                        'is_resolved' => false,
                        'triggered_at' => $now,
                    ]);
                }
            }

            // 3. Evaluasi Pintu Terbuka Saat Melaju
            if (! empty($data['is_door_open']) && $data['speed_kmh'] > 10.0) {
                $generatedAlerts[] = TelemetryAlert::create([
                    'tenant_id' => $tenantId,
                    'trip_id' => $data['trip_id'],
                    'vehicle_id' => $data['vehicle_id'],
                    'alert_type' => 'DOOR_OPEN_ENROUTE',
                    'severity' => 'CRITICAL',
                    'message' => 'Pintu kargo box terdeteksi terbuka saat kendaraan melaju.',
                    'metric_value' => $data['speed_kmh'],
                    'threshold_value' => 10.0,
                    'is_resolved' => false,
                    'triggered_at' => $now,
                ]);
            }

            return [
                'log' => $log,
                'alerts' => $generatedAlerts,
            ];
        });
    }

    /**
     * Mengambil status posisi GPS dan diagnostik terkini armada aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLiveFleet(string $tenantId): array
    {
        $latestLogs = TelemetryLog::where('tenant_id', $tenantId)
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->unique('vehicle_id');

        $activeAlerts = TelemetryAlert::where('tenant_id', $tenantId)
            ->where('is_resolved', false)
            ->get()
            ->groupBy('vehicle_id');

        $result = [];
        foreach ($latestLogs as $log) {
            $vehicleAlerts = $activeAlerts->get($log->vehicle_id, collect());
            $result[] = [
                'vehicle_id' => $log->vehicle_id,
                'trip_id' => $log->trip_id,
                'latitude' => $log->latitude,
                'longitude' => $log->longitude,
                'speed_kmh' => $log->speed_kmh,
                'fuel_level_pct' => $log->fuel_level_pct,
                'reefer_temp_celsius' => $log->reefer_temp_celsius,
                'is_door_open' => $log->is_door_open,
                'recorded_at' => $log->recorded_at->toIso8601String(),
                'status' => $vehicleAlerts->isNotEmpty() ? 'EXCEPTION' : ($log->speed_kmh > 0 ? 'EN_ROUTE' : 'IDLE'),
                'active_alerts' => $vehicleAlerts->values()->all(),
            ];
        }

        return $result;
    }
}
`
  },

  // 5. Controller: TelematicsController
  {
    path: 'backend/app/Http/Controllers/Api/TelematicsController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Models\\TelemetryAlert;
use App\\Services\\Telematics\\TelematicsEngineService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class TelematicsController extends Controller
{
    public function __construct(
        protected TelematicsEngineService $telematicsEngine
    ) {}

    public function ping(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $validated = $request->validate([
            'trip_id' => 'required|string',
            'vehicle_id' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'speed_kmh' => 'required|numeric|min:0',
            'fuel_level_pct' => 'required|numeric|between:0,100',
            'reefer_temp_celsius' => 'nullable|numeric',
            'is_door_open' => 'nullable|boolean',
        ]);

        $res = $this->telematicsEngine->processPing($tenantId, $validated);

        return response()->json([
            'data' => $res,
            'message' => 'Telemetry ping berhasil diproses.',
        ], 201);
    }

    public function live(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');
        $fleet = $this->telematicsEngine->getLiveFleet($tenantId);

        return response()->json([
            'data' => $fleet,
        ]);
    }

    public function resolveAlert(Request $request, string $alertId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        $alert = TelemetryAlert::where('tenant_id', $tenantId)
            ->where('id', $alertId)
            ->firstOrFail();

        $alert->update(['is_resolved' => true]);

        return response()->json([
            'message' => 'Peringatan telematika berhasil diselesaikan.',
        ]);
    }
}
`
  },

  // 6. Test: TelematicsTest
  {
    path: 'backend/tests/Feature/TelematicsTest.php',
    content: `<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class TelematicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_telematics_ping_triggers_overspeed_and_reefer_alerts(): void
    {
        $tenantId = 'demo';

        // 1. Ingestion ping dengan overspeed dan suhu reefer bocor
        $response = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/v1/telematics/ping', [
                'trip_id' => 'TRIP-DEMO-01',
                'vehicle_id' => 'B-9123-KDA',
                'latitude' => -6.2088,
                'longitude' => 106.8456,
                'speed_kmh' => 95.5, // Overspeed (> 90 km/h)
                'fuel_level_pct' => 78.0,
                'reefer_temp_celsius' => -10.0, // Melebihi ambang batas -15 C
                'is_door_open' => false,
            ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertEquals('B-9123-KDA', $data['log']['vehicle_id']);
        $this->assertCount(2, $data['alerts']); // 1 overspeed, 1 reefer breach

        // 2. Cek Live Fleet Status
        $liveResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->getJson('/api/v1/telematics/live');

        $liveResponse->assertStatus(200);
        $liveData = $liveResponse->json('data');

        $this->assertNotEmpty($liveData);
        $this->assertEquals('EXCEPTION', $liveData[0]['status']);
        $this->assertCount(2, $liveData[0]['active_alerts']);

        // 3. Resolve salah satu alert
        $alertId = $liveData[0]['active_alerts'][0]['id'];
        $resolveResponse = $this->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson("/api/v1/telematics/alerts/{$alertId}/resolve");

        $resolveResponse->assertStatus(200);
    }
}
`
  },

  // 7. Frontend Types: telematics.ts
  {
    path: 'frontend/src/types/telematics.ts',
    content: `export interface TelemetryAlert {
  id: string;
  trip_id: string;
  vehicle_id: string;
  alert_type: 'OVERSPEED' | 'REEFER_TEMP_BREACH' | 'DOOR_OPEN_ENROUTE';
  severity: 'WARNING' | 'CRITICAL';
  message: string;
  metric_value: number;
  threshold_value: number;
  is_resolved: boolean;
  triggered_at: string;
}

export interface LiveVehicleState {
  vehicle_id: string;
  trip_id: string;
  latitude: number;
  longitude: number;
  speed_kmh: number;
  fuel_level_pct: number;
  reefer_temp_celsius: number | null;
  is_door_open: boolean;
  recorded_at: string;
  status: 'EN_ROUTE' | 'IDLE' | 'EXCEPTION';
  active_alerts: TelemetryAlert[];
}
`
  },

  // 8. Frontend Component: GISControlTowerMap.tsx
  {
    path: 'frontend/src/components/Telematics/GISControlTowerMap.tsx',
    content: `import React, { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import type { LiveVehicleState } from '../../types/telematics';

export const GISControlTowerMap: React.FC = () => {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<L.Map | null>(null);
  const markersRef = useRef<{ [key: string]: L.CircleMarker }>({});

  const [fleet, setFleet] = useState<LiveVehicleState[]>([]);
  const [selectedVehicle, setSelectedVehicle] = useState<LiveVehicleState | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchLiveFleet = async () => {
    try {
      const res = await fetch('/api/v1/telematics/live', {
        headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
      });
      const data = await res.json();
      setFleet(data.data || []);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLiveFleet();
    const interval = setInterval(fetchLiveFleet, 8000);
    return () => clearInterval(interval);
  }, []);

  // Inisialisasi Peta Leaflet
  useEffect(() => {
    if (!mapContainerRef.current || mapInstanceRef.current) return;

    // Titik pusat pulau Jawa / Jakarta
    const map = L.map(mapContainerRef.current).setView([-6.2088, 106.8456], 8);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
      maxZoom: 19,
    }).addTo(map);

    mapInstanceRef.current = map;

    return () => {
      map.remove();
      mapInstanceRef.current = null;
    };
  }, []);

  // Render Marker Armada
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map) return;

    fleet.forEach((v) => {
      const color = v.status === 'EXCEPTION' ? '#f43f5e' : v.status === 'EN_ROUTE' ? '#06b6d4' : '#94a3b8';

      if (markersRef.current[v.vehicle_id]) {
        markersRef.current[v.vehicle_id].setLatLng([v.latitude, v.longitude]);
        markersRef.current[v.vehicle_id].setStyle({ color, fillColor: color });
      } else {
        const marker = L.circleMarker([v.latitude, v.longitude], {
          radius: 9,
          fillColor: color,
          color: '#ffffff',
          weight: 2,
          opacity: 1,
          fillOpacity: 0.9,
        }).addTo(map);

        marker.on('click', () => {
          setSelectedVehicle(v);
        });

        markersRef.current[v.vehicle_id] = marker;
      }
    });
  }, [fleet]);

  const handleResolveAlert = async (alertId: string) => {
    await fetch(\`/api/v1/telematics/alerts/\${alertId}/resolve\`, {
      method: 'POST',
      headers: { 'X-Tenant-Id': 'demo', Accept: 'application/json' },
    });
    fetchLiveFleet();
    if (selectedVehicle) {
      setSelectedVehicle((prev) =>
        prev
          ? {
              ...prev,
              active_alerts: prev.active_alerts.filter((a) => a.id !== alertId),
            }
          : null
      );
    }
  };

  return (
    <div className="relative flex h-[620px] w-full overflow-hidden rounded-xl border border-slate-800 bg-slate-900 shadow-2xl">
      {/* Viewport Peta */}
      <div ref={mapContainerRef} className="h-full flex-1 z-0" />

      {/* Header Overlay */}
      <div className="absolute top-4 left-4 z-10 rounded-lg border border-slate-800 bg-slate-950/90 p-3.5 backdrop-blur">
        <h2 className="text-base font-bold text-cyan-400">GIS Control Tower & IoT Telematics</h2>
        <p className="text-xs text-slate-400">Monitoring posisi GPS, BBM, pintu box, dan suhu dingin reefer.</p>
        <div className="mt-2 flex gap-3 text-[11px]">
          <span className="flex items-center gap-1 text-cyan-400">
            <span className="h-2 w-2 rounded-full bg-cyan-400" /> Melaju
          </span>
          <span className="flex items-center gap-1 text-rose-400">
            <span className="h-2 w-2 rounded-full bg-rose-500 animate-pulse" /> Pengecualian / Insiden
          </span>
          <span className="flex items-center gap-1 text-slate-400">
            <span className="h-2 w-2 rounded-full bg-slate-400" /> Berhenti
          </span>
        </div>
      </div>

      {/* Telematics Diagnostics Drawer (Panel Samping) */}
      {selectedVehicle && (
        <div className="absolute top-0 right-0 z-20 h-full w-80 border-l border-slate-800 bg-slate-950/95 p-5 text-slate-200 backdrop-blur shadow-2xl flex flex-col justify-between">
          <div className="space-y-5">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <p className="font-mono text-base font-bold text-cyan-400">{selectedVehicle.vehicle_id}</p>
                <p className="text-xs text-slate-400">Trip: {selectedVehicle.trip_id}</p>
              </div>
              <button
                onClick={() => setSelectedVehicle(null)}
                className="rounded p-1 text-slate-400 hover:bg-slate-800 hover:text-white"
              >
                ✕
              </button>
            </div>

            {/* Gauge Diagnostik */}
            <div className="grid grid-cols-2 gap-3 text-xs">
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Kecepatan</span>
                <p className="mt-1 font-mono text-lg font-bold text-slate-100">{selectedVehicle.speed_kmh} km/jam</p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Tangki BBM</span>
                <p className="mt-1 font-mono text-lg font-bold text-emerald-400">{selectedVehicle.fuel_level_pct}%</p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Suhu Reefer</span>
                <p
                  className={\`mt-1 font-mono text-lg font-bold \${
                    selectedVehicle.reefer_temp_celsius && selectedVehicle.reefer_temp_celsius > -15
                      ? 'text-rose-400'
                      : 'text-cyan-400'
                  }\`}
                >
                  {selectedVehicle.reefer_temp_celsius !== null
                    ? \`\${selectedVehicle.reefer_temp_celsius}°C\`
                    : 'Non-Reefer'}
                </p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Status Pintu</span>
                <p className="mt-1 font-bold text-slate-200">
                  {selectedVehicle.is_door_open ? (
                    <span className="text-rose-400">Terbuka</span>
                  ) : (
                    <span className="text-emerald-400">Terkunci</span>
                  )}
                </p>
              </div>
            </div>

            {/* Exception Alerts Container */}
            <div>
              <p className="text-xs font-semibold text-slate-300 mb-2">Peringatan Aktif</p>
              {selectedVehicle.active_alerts.length === 0 ? (
                <p className="text-xs text-slate-500 italic">Tidak ada insiden aktif.</p>
              ) : (
                <div className="space-y-2">
                  {selectedVehicle.active_alerts.map((alert) => (
                    <div
                      key={alert.id}
                      className="rounded-lg border border-rose-500/40 bg-rose-950/30 p-2.5 text-xs space-y-1.5"
                    >
                      <div className="flex justify-between items-center">
                        <span className="font-bold text-rose-400">{alert.alert_type}</span>
                        <span className="text-[10px] bg-rose-900/60 text-rose-300 px-1.5 py-0.2 rounded">
                          {alert.severity}
                        </span>
                      </div>
                      <p className="text-slate-300 text-[11px]">{alert.message}</p>
                      <button
                        onClick={() => handleResolveAlert(alert.id)}
                        className="w-full rounded bg-rose-600/80 hover:bg-rose-600 py-1 text-white text-[11px] font-medium transition"
                      >
                        Selesaikan Masalah
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          <div className="text-[10px] text-slate-500 border-t border-slate-800 pt-2">
            Pembaruan terakhir: {new Date(selectedVehicle.recorded_at).toLocaleTimeString('id-ID')}
          </div>
        </div>
      )}
    </div>
  );
};
`
  }
];

// Daftarkan rute Telematika ke backend/routes/web.php
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let webContent = fs.readFileSync(webPath, 'utf8');

if (!webContent.includes('TelematicsController')) {
  const snippet = `
// ==========================================
// INCREMENT 3B: GIS TELEMATICS & CONTROL TOWER
// ==========================================
use App\\Http\\Controllers\\Api\\TelematicsController;

Route::prefix('api/v1')->group(function () {
    Route::post('/telematics/ping', [TelematicsController::class, 'ping']);
    Route::get('/telematics/live', [TelematicsController::class, 'live']);
    Route::post('/telematics/alerts/{alertId}/resolve', [TelematicsController::class, 'resolveAlert']);
});
`;
  webContent = webContent.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, webContent, 'utf8');
  console.log('✅ Rute Telematika didaftarkan ke backend/routes/web.php');
}

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});

console.log('\nSelesai! Seluruh komponen Increment 3B telah dibuat.');