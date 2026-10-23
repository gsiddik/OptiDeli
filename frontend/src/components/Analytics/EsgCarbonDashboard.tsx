import React, { useState, useEffect } from "react";
import type { CarbonEmissionRecord, EsgSummary } from "../../types/esg";

export const EsgCarbonDashboard: React.FC = () => {
  const [summary, setSummary] = useState<EsgSummary | null>(null);
  const [records, setRecords] = useState<CarbonEmissionRecord[]>([]);

  // Form State
  const [tripId, setTripId] = useState("TRIP-901");
  const [vehicleId, setVehicleId] = useState("B-8821-DEL");
  const [ownership, setOwnership] = useState<"INTERNAL" | "VENDOR">("INTERNAL");
  const [fuelType, setFuelType] = useState("DIESEL_B35");
  const [distanceKm, setDistanceKm] = useState(180);
  const [cargoTon, setCargoTon] = useState(8.5);

  const fetchEsgData = async () => {
    try {
      const [sumRes, recRes] = await Promise.all([
        fetch("/api/v1/esg/summary", {
          headers: { "X-Tenant-Id": "demo", Accept: "application/json" },
        }),
        fetch("/api/v1/esg/records", {
          headers: { "X-Tenant-Id": "demo", Accept: "application/json" },
        }),
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
    await fetch("/api/v1/esg/calculate-trip", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Tenant-Id": "demo",
        Accept: "application/json",
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
          <h2 className="text-xl font-bold text-cyan-400">
            ESG Carbon Tracking & GLEC Reporting
          </h2>
          <p className="text-sm text-slate-400">
            Kalkulator emisi gas rumah kaca berbasis ton-kilometer (Standar ISO
            14083 / GLEC Framework).
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
            <span className="text-xs font-medium text-slate-400">
              Total Emisi Karbon
            </span>
            <p className="mt-1 font-mono text-2xl font-bold text-emerald-400">
              {summary.total_emissions_ton_co2e}{" "}
              <span className="text-sm font-normal text-slate-400">tCO₂e</span>
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {summary.total_emissions_kg_co2e} kg CO₂e
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">
              Intensitas Emisi Rata-Rata
            </span>
            <p className="mt-1 font-mono text-2xl font-bold text-cyan-400">
              {summary.average_intensity_g_per_ton_km}{" "}
              <span className="text-sm font-normal text-slate-400">
                g/ton-km
              </span>
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Benchmark GLEC Nasional
            </p>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">
              Scope 1 (Armada Sendiri)
            </span>
            <p className="mt-1 font-mono text-xl font-bold text-cyan-300">
              {summary.scope_breakdown.scope_1_kg} kg{" "}
              <span className="text-xs font-normal text-slate-400">
                ({summary.scope_breakdown.scope_1_pct}%)
              </span>
            </p>
            <div className="mt-2 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
              <div
                className="h-full bg-cyan-400"
                style={{ width: `${summary.scope_breakdown.scope_1_pct}%` }}
              />
            </div>
          </div>

          <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
            <span className="text-xs font-medium text-slate-400">
              Scope 3 (Vendor / 3PL)
            </span>
            <p className="mt-1 font-mono text-xl font-bold text-orange-300">
              {summary.scope_breakdown.scope_3_kg} kg{" "}
              <span className="text-xs font-normal text-slate-400">
                ({summary.scope_breakdown.scope_3_pct}%)
              </span>
            </p>
            <div className="mt-2 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
              <div
                className="h-full bg-orange-400"
                style={{ width: `${summary.scope_breakdown.scope_3_pct}%` }}
              />
            </div>
          </div>
        </div>
      )}

      {/* Simulator Input Form */}
      <form
        onSubmit={handleCalculate}
        className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-4"
      >
        <h3 className="text-sm font-semibold text-slate-200">
          Kalkulasi Emisi Trip Baru
        </h3>
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
            onChange={(e) =>
              setOwnership(e.target.value as "INTERNAL" | "VENDOR")
            }
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
                  <td className="px-4 py-3 font-mono text-cyan-400">
                    {r.trip_id}
                  </td>
                  <td className="px-4 py-3 text-slate-200">{r.vehicle_id}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`rounded px-2 py-0.5 text-xs font-bold ${
                        r.scope === "SCOPE_1"
                          ? "bg-cyan-950 text-cyan-400 border border-cyan-800"
                          : "bg-orange-950 text-orange-400 border border-orange-800"
                      }`}
                    >
                      {r.scope}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-400 text-xs">
                    {r.fuel_type}
                  </td>
                  <td className="px-4 py-3 text-right font-mono text-slate-300">
                    {r.ton_km} t·km
                  </td>
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
