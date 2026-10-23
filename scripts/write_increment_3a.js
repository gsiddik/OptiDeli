// scripts/write_increment_3a.js
const fs = require('fs');
const path = require('path');

const files = [
  // 1. Backend Service: LoadOptimizerService
  {
    path: 'backend/app/Services/Optimizer/LoadOptimizerService.php',
    content: `<?php

namespace App\\Services\\Optimizer;

use InvalidArgumentException;

class LoadOptimizerService
{
    /**
     * Mengkalkulasi penataan kargo 3D dan distribusi beban as roda.
     *
     * @param array{
     *   type: string,
     *   max_weight_kg: float,
     *   max_volume_m3: float,
     *   container_dimensions: array{length_cm: float, width_cm: float, height_cm: float},
     *   axle_limits: array{front_max_kg: float, rear_max_kg: float}
     * } $vehicle
     * @param array<int, array{
     *   id: string,
     *   name: string,
     *   length_cm: float,
     *   width_cm: float,
     *   height_cm: float,
     *   weight_kg: float,
     *   quantity: int,
     *   is_hazmat: bool,
     *   stackable: bool,
     *   max_stack?: int
     * }> $cargoItems
     * @return array<string, mixed>
     */
    public function optimize(array $vehicle, array $cargoItems): array
    {
        $containerL = (float) $vehicle['container_dimensions']['length_cm'];
        $containerW = (float) $vehicle['container_dimensions']['width_cm'];
        $containerH = (float) $vehicle['container_dimensions']['height_cm'];

        if ($containerL <= 0 || $containerW <= 0 || $containerH <= 0) {
            throw new InvalidArgumentException('Dimensi kontainer tidak valid.');
        }

        $containerVolumeM3 = ($containerL * $containerW * $containerH) / 1000000.0;
        $maxWeightKg = (float) $vehicle['max_weight_kg'];

        // Uraikan kargo per unit koli dan urutkan (Hazmat diutamakan di lantai, lalu descending volume)
        $units = [];
        foreach ($cargoItems as $item) {
            $qty = (int) $item['quantity'];
            for ($i = 0; $i < $qty; $i++) {
                $units[] = [
                    'id' => $item['id'] . '-' . ($i + 1),
                    'name' => $item['name'],
                    'length_cm' => (float) $item['length_cm'],
                    'width_cm' => (float) $item['width_cm'],
                    'height_cm' => (float) $item['height_cm'],
                    'weight_kg' => (float) $item['weight_kg'],
                    'volume_m3' => ((float) $item['length_cm'] * (float) $item['width_cm'] * (float) $item['height_cm']) / 1000000.0,
                    'is_hazmat' => (bool) ($item['is_hazmat'] ?? false),
                    'stackable' => (bool) ($item['stackable'] ?? true),
                ];
            }
        }

        // Urutkan: Hazmat pertama (untuk alokasi lantai dasar), kemudian volume terbesar
        usort($units, function ($a, $b) {
            if ($a['is_hazmat'] !== $b['is_hazmat']) {
                return $a['is_hazmat'] ? -1 : 1;
            }

            return $b['volume_m3'] <=> $a['volume_m3'];
        });

        // 3D Shelf-Packing Heuristic
        $placed = [];
        $unplaced = [];
        $currentX = 0.0;
        $currentY = 0.0;
        $currentZ = 0.0;
        $maxLayerH = 0.0;
        $maxRowW = 0.0;

        $totalWeight = 0.0;
        $totalVolume = 0.0;
        $weightedXSum = 0.0; // Untuk menghitung Center of Gravity (CoG)

        foreach ($units as $unit) {
            $l = $unit['length_cm'];
            $w = $unit['width_cm'];
            $h = $unit['height_cm'];

            // Hazmat tidak boleh ditumpuk di atas kargo lain
            if ($unit['is_hazmat'] && $currentZ > 0.0) {
                // Pindah ke lantai berikutnya di sumbu X/Y
                $currentZ = 0.0;
                $currentY += $maxRowW;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di tinggi Z
            if (($currentZ + $h) > $containerH || ! $unit['stackable']) {
                $currentZ = 0.0;
                $currentY += $maxRowW;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di lebar Y
            if (($currentY + $w) > $containerW) {
                $currentY = 0.0;
                $currentZ = 0.0;
                $currentX += $maxLayerH;
                $maxLayerH = 0.0;
                $maxRowW = 0.0;
            }

            // Cek apakah muat di panjang X
            if (($currentX + $l) > $containerL || ($totalWeight + $unit['weight_kg']) > $maxWeightKg) {
                $unplaced[] = $unit;

                continue;
            }

            // Sukses ditempatkan
            $posX = $currentX;
            $posY = $currentY;
            $posZ = $currentZ;

            $placed[] = array_merge($unit, [
                'position' => [
                    'x' => round($posX, 2),
                    'y' => round($posY, 2),
                    'z' => round($posZ, 2),
                ],
            ]);

            $totalWeight += $unit['weight_kg'];
            $totalVolume += $unit['volume_m3'];

            // Titik tengah massa unit sepanjang sumbu X
            $centerX = $posX + ($l / 2.0);
            $weightedXSum += ($unit['weight_kg'] * $centerX);

            // Update batas lapisan
            if ($h > $maxLayerH) {
                $maxLayerH = $h;
            }
            if ($w > $maxRowW) {
                $maxRowW = $w;
            }

            if ($unit['stackable'] && ! $unit['is_hazmat']) {
                $currentZ += $h;
            } else {
                $currentY += $w;
            }
        }

        // Kalkulasi Distribusi Beban As Roda (Front vs Rear Axle)
        $frontMax = (float) ($vehicle['axle_limits']['front_max_kg'] ?? ($maxWeightKg * 0.4));
        $rearMax = (float) ($vehicle['axle_limits']['rear_max_kg'] ?? ($maxWeightKg * 0.6));

        $frontAxleLoad = 0.0;
        $rearAxleLoad = 0.0;

        if ($totalWeight > 0.0) {
            $centerOfGravityX = $weightedXSum / $totalWeight;
            // Rasio posisi CoG terhadap panjang kontainer
            $rearRatio = min(1.0, max(0.0, $centerOfGravityX / $containerL));
            $frontRatio = 1.0 - $rearRatio;

            $frontAxleLoad = round($totalWeight * $frontRatio, 2);
            $rearAxleLoad = round($totalWeight * $rearRatio, 2);
        }

        $frontOverload = $frontAxleLoad > $frontMax;
        $rearOverload = $rearAxleLoad > $rearMax;
        $isValidDispatch = empty($unplaced) && ! $frontOverload && ! $rearOverload;

        return [
            'is_valid_dispatch' => $isValidDispatch,
            'summary' => [
                'total_placed_items' => count($placed),
                'total_unplaced_items' => count($unplaced),
                'total_weight_kg' => round($totalWeight, 2),
                'max_weight_kg' => $maxWeightKg,
                'weight_utilization_pct' => round(($totalWeight / $maxWeightKg) * 100, 2),
                'total_volume_m3' => round($totalVolume, 3),
                'container_volume_m3' => round($containerVolumeM3, 3),
                'volume_utilization_pct' => round(($totalVolume / $containerVolumeM3) * 100, 2),
            ],
            'axle_distribution' => [
                'front_axle_load_kg' => $frontAxleLoad,
                'front_max_kg' => $frontMax,
                'front_status' => $frontOverload ? 'OVERLOAD' : 'OK',
                'rear_axle_load_kg' => $rearAxleLoad,
                'rear_max_kg' => $rearMax,
                'rear_status' => $rearOverload ? 'OVERLOAD' : 'OK',
            ],
            'container_dimensions' => [
                'length_cm' => $containerL,
                'width_cm' => $containerW,
                'height_cm' => $containerH,
            ],
            'placed_items' => $placed,
            'unplaced_items' => $unplaced,
        ];
    }
}
`
  },

  // 2. Backend Controller: LoadOptimizerController
  {
    path: 'backend/app/Http/Controllers/Api/LoadOptimizerController.php',
    content: `<?php

namespace App\\Http\\Controllers\\Api;

use App\\Http\\Controllers\\Controller;
use App\\Services\\Optimizer\\LoadOptimizerService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

class LoadOptimizerController extends Controller
{
    public function __construct(
        protected LoadOptimizerService $optimizerService
    ) {}

    public function build(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle' => 'required|array',
            'vehicle.type' => 'required|string',
            'vehicle.max_weight_kg' => 'required|numeric|min:1',
            'vehicle.container_dimensions' => 'required|array',
            'vehicle.container_dimensions.length_cm' => 'required|numeric|min:10',
            'vehicle.container_dimensions.width_cm' => 'required|numeric|min:10',
            'vehicle.container_dimensions.height_cm' => 'required|numeric|min:10',
            'vehicle.axle_limits' => 'nullable|array',
            'vehicle.axle_limits.front_max_kg' => 'nullable|numeric|min:1',
            'vehicle.axle_limits.rear_max_kg' => 'nullable|numeric|min:1',
            'cargo_items' => 'required|array|min:1',
            'cargo_items.*.id' => 'required|string',
            'cargo_items.*.name' => 'required|string',
            'cargo_items.*.length_cm' => 'required|numeric|min:1',
            'cargo_items.*.width_cm' => 'required|numeric|min:1',
            'cargo_items.*.height_cm' => 'required|numeric|min:1',
            'cargo_items.*.weight_kg' => 'required|numeric|min:0.1',
            'cargo_items.*.quantity' => 'required|integer|min:1',
            'cargo_items.*.is_hazmat' => 'nullable|boolean',
            'cargo_items.*.stackable' => 'nullable|boolean',
        ]);

        $result = $this->optimizerService->optimize($validated['vehicle'], $validated['cargo_items']);

        return response()->json([
            'data' => $result,
            'message' => 'Kalkulasi 3D Load Optimization berhasil.',
        ]);
    }
}
`
  },

  // 3. Backend Feature Test: LoadOptimizerTest
  {
    path: 'backend/tests/Feature/LoadOptimizerTest.php',
    content: `<?php

namespace Tests\\Feature;

use Tests\\TestCase;

class LoadOptimizerTest extends TestCase
{
    public function test_load_optimizer_places_cargo_and_calculates_axle_loads(): void
    {
        $payload = [
            'vehicle' => [
                'type' => 'CDD Box',
                'max_weight_kg' => 4000,
                'container_dimensions' => [
                    'length_cm' => 420,
                    'width_cm' => 200,
                    'height_cm' => 200,
                ],
                'axle_limits' => [
                    'front_max_kg' => 1800,
                    'rear_max_kg' => 2600,
                ],
            ],
            'cargo_items' => [
                [
                    'id' => 'CARGO-ELEC',
                    'name' => 'Kardus TV Elektronik',
                    'length_cm' => 60,
                    'width_cm' => 40,
                    'height_cm' => 40,
                    'weight_kg' => 20,
                    'quantity' => 10,
                    'is_hazmat' => false,
                    'stackable' => true,
                ],
                [
                    'id' => 'CARGO-HAZMAT',
                    'name' => 'Bahan Kimia Cat (Hazmat)',
                    'length_cm' => 50,
                    'width_cm' => 50,
                    'height_cm' => 50,
                    'weight_kg' => 30,
                    'quantity' => 2,
                    'is_hazmat' => true,
                    'stackable' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/load-optimization/build', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_valid_dispatch', true);
        $response->assertJsonPath('data.summary.total_placed_items', 12);
        $response->assertJsonPath('data.summary.total_unplaced_items', 0);
        $response->assertJsonPath('data.axle_distribution.front_status', 'OK');
        $response->assertJsonPath('data.axle_distribution.rear_status', 'OK');

        // Pastikan item Hazmat ditaruh di lantai dasar (z = 0)
        $placed = $response->json('data.placed_items');
        foreach ($placed as $item) {
            if ($item['is_hazmat']) {
                $this->assertEquals(0, $item['position']['z']);
            }
        }
    }
}
`
  },

  // 4. Frontend Type Definition: optimizer.ts
  {
    path: 'frontend/src/types/optimizer.ts',
    content: `export interface VehicleDimension {
  length_cm: number;
  width_cm: number;
  height_cm: number;
}

export interface AxleLimits {
  front_max_kg: number;
  rear_max_kg: number;
}

export interface VehicleSpec {
  type: string;
  max_weight_kg: number;
  container_dimensions: VehicleDimension;
  axle_limits: AxleLimits;
}

export interface CargoItemInput {
  id: string;
  name: string;
  length_cm: number;
  width_cm: number;
  height_cm: number;
  weight_kg: number;
  quantity: number;
  is_hazmat: boolean;
  stackable: boolean;
}

export interface PlacedCargoItem extends CargoItemInput {
  volume_m3: number;
  position: {
    x: number;
    y: number;
    z: number;
  };
}

export interface OptimizationResult {
  is_valid_dispatch: boolean;
  summary: {
    total_placed_items: number;
    total_unplaced_items: number;
    total_weight_kg: number;
    max_weight_kg: number;
    weight_utilization_pct: number;
    total_volume_m3: number;
    container_volume_m3: number;
    volume_utilization_pct: number;
  };
  axle_distribution: {
    front_axle_load_kg: number;
    front_max_kg: number;
    front_status: 'OK' | 'OVERLOAD';
    rear_axle_load_kg: number;
    rear_max_kg: number;
    rear_status: 'OK' | 'OVERLOAD';
  };
  container_dimensions: VehicleDimension;
  placed_items: PlacedCargoItem[];
  unplaced_items: CargoItemInput[];
}
`
  },

  // 5. Frontend Interactive 3D Component: LoadOptimizationViewer3D.tsx
  {
    path: 'frontend/src/components/Optimizer/LoadOptimizationViewer3D.tsx',
    content: `import React, { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import type { OptimizationResult, VehicleSpec, CargoItemInput } from '../../types/optimizer';

export const LoadOptimizationViewer3D: React.FC = () => {
  const mountRef = useRef<HTMLDivElement>(null);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<OptimizationResult | null>(null);

  // Default Vehicle Spec (CDD Box)
  const [vehicle] = useState<VehicleSpec>({
    type: 'CDD Box Standard',
    max_weight_kg: 4000,
    container_dimensions: { length_cm: 420, width_cm: 200, height_cm: 200 },
    axle_limits: { front_max_kg: 1800, rear_max_kg: 2600 },
  });

  // Default Sample Cargo
  const [cargoItems, setCargoItems] = useState<CargoItemInput[]>([
    {
      id: 'CRG-01',
      name: 'Karton Elektronik',
      length_cm: 60,
      width_cm: 40,
      height_cm: 40,
      weight_kg: 20,
      quantity: 15,
      is_hazmat: false,
      stackable: true,
    },
    {
      id: 'CRG-02',
      name: 'Tong Cairan Kimia (Hazmat)',
      length_cm: 50,
      width_cm: 50,
      height_cm: 50,
      weight_kg: 35,
      quantity: 4,
      is_hazmat: true,
      stackable: false,
    },
  ]);

  const runOptimization = async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/v1/load-optimization/build', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ vehicle, cargo_items: cargoItems }),
      });
      const data = await res.json();
      setResult(data.data);
    } catch (err) {
      console.error('Gagal mengoptimasi muatan:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    runOptimization();
  }, []);

  // Three.js Rendering Effect
  useEffect(() => {
    if (!mountRef.current || !result) return;

    const width = mountRef.current.clientWidth;
    const height = 450;

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0a0f1d);

    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 2000);
    camera.position.set(400, 300, 500);
    camera.lookAt(150, 80, 80);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(window.devicePixelRatio);
    mountRef.current.replaceChildren(renderer.domElement);

    // Pencahayaan
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.8);
    scene.add(ambientLight);
    const dirLight = new THREE.DirectionalLight(0x06b6d4, 1.2);
    dirLight.position.set(200, 400, 300);
    scene.add(dirLight);

    // Bounding Box Truk (Transparan Wireframe)
    const { length_cm, width_cm, height_cm } = result.container_dimensions;
    const boxGeo = new THREE.BoxGeometry(length_cm, height_cm, width_cm);
    const boxMat = new THREE.MeshBasicMaterial({
      color: 0x06b6d4,
      wireframe: true,
      transparent: true,
      opacity: 0.35,
    });
    const containerMesh = new THREE.Mesh(boxGeo, boxMat);
    containerMesh.position.set(length_cm / 2, height_cm / 2, width_cm / 2);
    scene.add(containerMesh);

    // Lantai Grid
    const grid = new THREE.GridHelper(Math.max(length_cm, width_cm) * 1.5, 20, 0x06b6d4, 0x1e293b);
    grid.position.set(length_cm / 2, 0, width_cm / 2);
    scene.add(grid);

    // Render Box Kargo
    result.placed_items.forEach((item) => {
      const itemGeo = new THREE.BoxGeometry(item.length_cm, item.height_cm, item.width_cm);
      const color = item.is_hazmat ? 0xf97316 : 0x0ea5e9;
      const itemMat = new THREE.MeshStandardMaterial({
        color,
        roughness: 0.3,
        metalness: 0.2,
      });
      const mesh = new THREE.Mesh(itemGeo, itemMat);

      // Posisi dihitung dari titik tengah geometri Three.js
      mesh.position.set(
        item.position.x + item.length_cm / 2,
        item.position.z + item.height_cm / 2,
        item.position.y + item.width_cm / 2
      );

      // Garis tepi kontur box
      const edges = new THREE.EdgesGeometry(itemGeo);
      const line = new THREE.LineSegments(edges, new THREE.LineBasicMaterial({ color: 0xffffff, linewidth: 1 }));
      mesh.add(line);

      scene.add(mesh);
    });

    let frameId: number;
    const animate = () => {
      frameId = requestAnimationFrame(animate);
      scene.rotation.y += 0.002; // Rotasi perlahan otomatis
      renderer.render(scene, camera);
    };
    animate();

    return () => {
      cancelAnimationFrame(frameId);
      renderer.dispose();
    };
  }, [result]);

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">3D Load Optimizer & Axle Balancing</h2>
          <p className="text-sm text-slate-400">
            Simulasi penataan kargo 3D, isolasi Hazmat, dan proteksi beban as roda (Front vs Rear).
          </p>
        </div>
        <button
          onClick={runOptimization}
          disabled={loading}
          className="rounded-lg bg-cyan-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-cyan-500 disabled:opacity-50"
        >
          {loading ? 'Mengalkulasi...' : 'Kalkulasi Ulang 3D'}
        </button>
      </div>

      {/* 3D Canvas Viewport */}
      <div className="relative overflow-hidden rounded-xl border border-slate-800 bg-slate-950">
        <div ref={mountRef} className="w-full" style={{ height: '450px' }} />
        <div className="absolute bottom-3 left-3 flex gap-3 text-xs">
          <span className="flex items-center gap-1.5 rounded-md bg-slate-900/80 px-2.5 py-1 text-cyan-400 backdrop-blur border border-slate-700">
            <span className="h-2 w-2 rounded-full bg-cyan-400" /> Kargo Normal
          </span>
          <span className="flex items-center gap-1.5 rounded-md bg-slate-900/80 px-2.5 py-1 text-orange-400 backdrop-blur border border-slate-700">
            <span className="h-2 w-2 rounded-full bg-orange-500" /> Kargo Hazmat (Dasar Lantai)
          </span>
        </div>
      </div>

      {/* Analytics & Axle Balancing Widgets */}
      {result && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <p className="text-xs font-medium text-slate-400">Utilisasi Kubikasi</p>
            <p className="mt-1 font-mono text-2xl font-bold text-cyan-400">
              {result.summary.volume_utilization_pct}%
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {result.summary.total_volume_m3} / {result.summary.container_volume_m3} m³
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <p className="text-xs font-medium text-slate-400">Utilisasi Tonase</p>
            <p className="mt-1 font-mono text-2xl font-bold text-emerald-400">
              {result.summary.weight_utilization_pct}%
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {result.summary.total_weight_kg} / {result.summary.max_weight_kg} kg
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <div className="flex justify-between items-center">
              <p className="text-xs font-medium text-slate-400">Beban As Depan</p>
              <span
                className={\`text-xs font-bold px-1.5 py-0.5 rounded \${
                  result.axle_distribution.front_status === 'OK'
                    ? 'bg-emerald-950 text-emerald-400'
                    : 'bg-rose-950 text-rose-400 animate-pulse'
                }\`}
              >
                {result.axle_distribution.front_status}
              </span>
            </div>
            <p className="mt-1 font-mono text-xl font-bold text-slate-200">
              {result.axle_distribution.front_axle_load_kg} kg
            </p>
            <p className="mt-1 text-xs text-slate-500">Batas: {result.axle_distribution.front_max_kg} kg</p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <div className="flex justify-between items-center">
              <p className="text-xs font-medium text-slate-400">Beban As Belakang</p>
              <span
                className={\`text-xs font-bold px-1.5 py-0.5 rounded \${
                  result.axle_distribution.rear_status === 'OK'
                    ? 'bg-emerald-950 text-emerald-400'
                    : 'bg-rose-950 text-rose-400 animate-pulse'
                }\`}
              >
                {result.axle_distribution.rear_status}
              </span>
            </div>
            <p className="mt-1 font-mono text-xl font-bold text-slate-200">
              {result.axle_distribution.rear_axle_load_kg} kg
            </p>
            <p className="mt-1 text-xs text-slate-500">Batas: {result.axle_distribution.rear_max_kg} kg</p>
          </div>
        </div>
      )}
    </div>
  );
};
`
  }
];

// Daftarkan rute ke backend/routes/web.php
const webPath = path.resolve(process.cwd(), 'backend/routes/web.php');
let webContent = fs.readFileSync(webPath, 'utf8');

if (!webContent.includes('LoadOptimizerController')) {
  const snippet = `
// ==========================================
// INCREMENT 3A: 3D LOAD OPTIMIZATION ENGINE
// ==========================================
use App\\Http\\Controllers\\Api\\LoadOptimizerController;

Route::prefix('api/v1')->group(function () {
    Route::post('/load-optimization/build', [LoadOptimizerController::class, 'build']);
});
`;
  webContent = webContent.trimEnd() + '\n' + snippet + '\n';
  fs.writeFileSync(webPath, webContent, 'utf8');
  console.log('✅ Rute 3D Load Optimizer didaftarkan ke backend/routes/web.php');
}

files.forEach(f => {
  const fullPath = path.resolve(process.cwd(), f.path);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, f.content, 'utf8');
  console.log(`✅ Ditulis: ${f.path}`);
});

console.log('\nSelesai! Seluruh komponen Increment 3A telah dibuat.');