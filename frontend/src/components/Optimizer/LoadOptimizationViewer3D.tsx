import React, { useEffect, useRef, useState } from "react";
import * as THREE from "three";
import type {
  OptimizationResult,
  VehicleSpec,
  CargoItemInput,
} from "../../types/optimizer";

export const LoadOptimizationViewer3D: React.FC = () => {
  const mountRef = useRef<HTMLDivElement>(null);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<OptimizationResult | null>(null);

  // Default Vehicle Spec (CDD Box)
  const [vehicle] = useState<VehicleSpec>({
    type: "CDD Box Standard",
    max_weight_kg: 4000,
    container_dimensions: { length_cm: 420, width_cm: 200, height_cm: 200 },
    axle_limits: { front_max_kg: 1800, rear_max_kg: 2600 },
  });

  // Default Sample Cargo
  const [cargoItems, setCargoItems] = useState<CargoItemInput[]>([
    {
      id: "CRG-01",
      name: "Karton Elektronik",
      length_cm: 60,
      width_cm: 40,
      height_cm: 40,
      weight_kg: 20,
      quantity: 15,
      is_hazmat: false,
      stackable: true,
    },
    {
      id: "CRG-02",
      name: "Tong Cairan Kimia (Hazmat)",
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
      const res = await fetch("/api/v1/load-optimization/build", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ vehicle, cargo_items: cargoItems }),
      });
      const data = await res.json();
      setResult(data.data);
    } catch (err) {
      console.error("Gagal mengoptimasi muatan:", err);
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
    const grid = new THREE.GridHelper(
      Math.max(length_cm, width_cm) * 1.5,
      20,
      0x06b6d4,
      0x1e293b,
    );
    grid.position.set(length_cm / 2, 0, width_cm / 2);
    scene.add(grid);

    // Render Box Kargo
    result.placed_items.forEach((item) => {
      const itemGeo = new THREE.BoxGeometry(
        item.length_cm,
        item.height_cm,
        item.width_cm,
      );
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
        item.position.y + item.width_cm / 2,
      );

      // Garis tepi kontur box
      const edges = new THREE.EdgesGeometry(itemGeo);
      const line = new THREE.LineSegments(
        edges,
        new THREE.LineBasicMaterial({ color: 0xffffff, linewidth: 1 }),
      );
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
          <h2 className="text-xl font-bold text-cyan-400">
            3D Load Optimizer & Axle Balancing
          </h2>
          <p className="text-sm text-slate-400">
            Simulasi penataan kargo 3D, isolasi Hazmat, dan proteksi beban as
            roda (Front vs Rear).
          </p>
        </div>
        <button
          onClick={runOptimization}
          disabled={loading}
          className="rounded-lg bg-cyan-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-cyan-500 disabled:opacity-50"
        >
          {loading ? "Mengalkulasi..." : "Kalkulasi Ulang 3D"}
        </button>
      </div>

      {/* 3D Canvas Viewport */}
      <div className="relative overflow-hidden rounded-xl border border-slate-800 bg-slate-950">
        <div ref={mountRef} className="w-full" style={{ height: "450px" }} />
        <div className="absolute bottom-3 left-3 flex gap-3 text-xs">
          <span className="flex items-center gap-1.5 rounded-md bg-slate-900/80 px-2.5 py-1 text-cyan-400 backdrop-blur border border-slate-700">
            <span className="h-2 w-2 rounded-full bg-cyan-400" /> Kargo Normal
          </span>
          <span className="flex items-center gap-1.5 rounded-md bg-slate-900/80 px-2.5 py-1 text-orange-400 backdrop-blur border border-slate-700">
            <span className="h-2 w-2 rounded-full bg-orange-500" /> Kargo Hazmat
            (Dasar Lantai)
          </span>
        </div>
      </div>

      {/* Analytics & Axle Balancing Widgets */}
      {result && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <p className="text-xs font-medium text-slate-400">
              Utilisasi Kubikasi
            </p>
            <p className="mt-1 font-mono text-2xl font-bold text-cyan-400">
              {result.summary.volume_utilization_pct}%
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {result.summary.total_volume_m3} /{" "}
              {result.summary.container_volume_m3} m³
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <p className="text-xs font-medium text-slate-400">
              Utilisasi Tonase
            </p>
            <p className="mt-1 font-mono text-2xl font-bold text-emerald-400">
              {result.summary.weight_utilization_pct}%
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {result.summary.total_weight_kg} / {result.summary.max_weight_kg}{" "}
              kg
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <div className="flex justify-between items-center">
              <p className="text-xs font-medium text-slate-400">
                Beban As Depan
              </p>
              <span
                className={`text-xs font-bold px-1.5 py-0.5 rounded ${
                  result.axle_distribution.front_status === "OK"
                    ? "bg-emerald-950 text-emerald-400"
                    : "bg-rose-950 text-rose-400 animate-pulse"
                }`}
              >
                {result.axle_distribution.front_status}
              </span>
            </div>
            <p className="mt-1 font-mono text-xl font-bold text-slate-200">
              {result.axle_distribution.front_axle_load_kg} kg
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Batas: {result.axle_distribution.front_max_kg} kg
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <div className="flex justify-between items-center">
              <p className="text-xs font-medium text-slate-400">
                Beban As Belakang
              </p>
              <span
                className={`text-xs font-bold px-1.5 py-0.5 rounded ${
                  result.axle_distribution.rear_status === "OK"
                    ? "bg-emerald-950 text-emerald-400"
                    : "bg-rose-950 text-rose-400 animate-pulse"
                }`}
              >
                {result.axle_distribution.rear_status}
              </span>
            </div>
            <p className="mt-1 font-mono text-xl font-bold text-slate-200">
              {result.axle_distribution.rear_axle_load_kg} kg
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Batas: {result.axle_distribution.rear_max_kg} kg
            </p>
          </div>
        </div>
      )}
    </div>
  );
};
