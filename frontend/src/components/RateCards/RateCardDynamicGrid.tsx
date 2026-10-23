import React, { useState, useEffect } from 'react';
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
