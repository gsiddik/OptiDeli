import React, { useState, useEffect } from "react";
import { api } from "../../api";
import type { VendorRateCard } from "../../types/tendering";
import { formatRupiahFromMinor } from "../../types/tendering";

export const RateCardDynamicGrid: React.FC = () => {
  const [rateCards, setRateCards] = useState<VendorRateCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterZone, setFilterZone] = useState("");

  const [vendorId, setVendorId] = useState("");
  const [vehicleType, setVehicleType] = useState("CDD");
  const [origin, setOrigin] = useState("JKT-HUB");
  const [dest, setDest] = useState("SBY-HUB");
  const [baseRateRp, setBaseRateRp] = useState(3500000);
  const [ratePerKmRp, setRatePerKmRp] = useState(4500);

  const fetchRateCards = async () => {
    try {
      const data = await api<{ data: VendorRateCard[] }>("rate-cards");
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
    await api<unknown>("rate-cards", "POST", {
      vendor_id: vendorId,
      vehicle_type: vehicleType,
      zone_origin: origin,
      zone_destination: dest,
      base_rate_minor_units: Math.round(baseRateRp * 100),
      rate_per_km_minor_units: Math.round(ratePerKmRp * 100),
      min_distance_km: 0,
    });
    setVendorId("");
    fetchRateCards();
  };

  const filteredCards = filterZone
    ? rateCards.filter(
        (c) =>
          c.zone_origin.toLowerCase().includes(filterZone.toLowerCase()) ||
          c.zone_destination.toLowerCase().includes(filterZone.toLowerCase()),
      )
    : rateCards;

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">
            Vendor Rate Card Matrix
          </h2>
          <p className="text-sm text-slate-400">
            Aturan tarif vendor berbasis zona, armada, dan minor unit IDR.
          </p>
        </div>
        <input
          type="text"
          placeholder="Cari zona..."
          value={filterZone}
          onChange={(e) => setFilterZone(e.target.value)}
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
        />
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-700 bg-slate-800/60 text-xs font-semibold uppercase tracking-wider text-slate-300">
              <th className="px-4 py-3">Vendor ID</th>
              <th className="px-4 py-3">Armada</th>
              <th className="px-4 py-3">Rute (Origin → Dest)</th>
              <th className="px-4 py-3 text-right">Tarif Dasar</th>
              <th className="px-4 py-3 text-right">Per KM</th>
              <th className="px-4 py-3 text-center">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800">
            {loading ? (
              <tr>
                <td colSpan={6} className="py-6 text-center text-slate-500">
                  Memuat matriks tarif...
                </td>
              </tr>
            ) : filteredCards.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-6 text-center text-slate-500">
                  Belum ada rate card tercatat.
                </td>
              </tr>
            ) : (
              filteredCards.map((rc) => (
                <tr key={rc.id} className="transition hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-mono font-medium text-cyan-300">
                    {rc.vendor_id}
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded border border-orange-500/30 bg-slate-800 px-2 py-0.5 text-xs text-orange-300">
                      {rc.vehicle_type}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-300">
                    {rc.zone_origin} <span className="text-cyan-400">→</span>{" "}
                    {rc.zone_destination}
                  </td>
                  <td className="px-4 py-3 text-right font-mono font-semibold text-emerald-400">
                    {formatRupiahFromMinor(rc.base_rate_minor_units)}
                  </td>
                  <td className="px-4 py-3 text-right font-mono text-slate-400">
                    {formatRupiahFromMinor(rc.rate_per_km_minor_units)}/km
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="rounded-full border border-emerald-800 bg-emerald-950 px-2 py-0.5 text-xs text-emerald-400">
                      Aktif
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <form
        onSubmit={handleSave}
        className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-4"
      >
        <h3 className="text-sm font-semibold text-slate-200">
          Tambah / Perbarui Rate Card
        </h3>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
          <input
            type="text"
            placeholder="Vendor ID"
            value={vendorId}
            onChange={(e) => setVendorId(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <select
            value={vehicleType}
            onChange={(e) => setVehicleType(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
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
            onChange={(e) => setOrigin(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <input
            type="text"
            placeholder="Zone Destination"
            value={dest}
            onChange={(e) => setDest(e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <input
            type="number"
            placeholder="Tarif Dasar (Rp)"
            value={baseRateRp}
            onChange={(e) => setBaseRateRp(Number(e.target.value))}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
          <input
            type="number"
            placeholder="Tarif per KM (Rp)"
            value={ratePerKmRp}
            onChange={(e) => setRatePerKmRp(Number(e.target.value))}
            className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-200"
            required
          />
        </div>
        <div className="flex justify-end">
          <button
            type="submit"
            className="rounded bg-cyan-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-cyan-500"
          >
            Simpan Matrix
          </button>
        </div>
      </form>
    </div>
  );
};
