// scripts/resolve_ci_checks.js
const fs = require('fs');
const path = require('path');

// 1. Perbaiki frontend/src/components/RateCards/RateCardDynamicGrid.tsx
const rateCardGridPath = path.resolve(process.cwd(), 'frontend/src/components/RateCards/RateCardDynamicGrid.tsx');
const rateCardGridContent = `import React, { useState, useEffect } from 'react';
import type { VendorRateCard } from '../../types/tendering';
import { formatRupiahFromMinor } from '../../types/tendering';

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
    ? rateCards.filter(
        (c) =>
          c.zone_origin.toLowerCase().includes(filterZone.toLowerCase()) ||
          c.zone_destination.toLowerCase().includes(filterZone.toLowerCase())
      )
    : rateCards;

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">Vendor Rate Card Matrix</h2>
          <p className="text-sm text-slate-400">Aturan tarif vendor berbasis zona, armada, dan minor unit IDR.</p>
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
                  <td className="px-4 py-3 font-mono font-medium text-cyan-300">{rc.vendor_id}</td>
                  <td className="px-4 py-3">
                    <span className="rounded border border-orange-500/30 bg-slate-800 px-2 py-0.5 text-xs text-orange-300">
                      {rc.vehicle_type}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-300">
                    {rc.zone_origin} <span className="text-cyan-400">→</span> {rc.zone_destination}
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

      <form onSubmit={handleSave} className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-4">
        <h3 className="text-sm font-semibold text-slate-200">Tambah / Perbarui Rate Card</h3>
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
`;
fs.writeFileSync(rateCardGridPath, rateCardGridContent, 'utf8');
console.log('✅ Diperbaiki: frontend/src/components/RateCards/RateCardDynamicGrid.tsx');

// 2. Perbaiki frontend/src/components/Tendering/TenderWaterfallBoard.tsx
const waterfallBoardPath = path.resolve(process.cwd(), 'frontend/src/components/Tendering/TenderWaterfallBoard.tsx');
const waterfallBoardContent = `import React, { useState, useEffect } from 'react';
import type { TenderDispatch } from '../../types/tendering';
import { formatRupiahFromMinor } from '../../types/tendering';

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
        return (
          <span className="animate-pulse rounded-full border border-amber-500/40 bg-amber-500/20 px-2 py-0.5 text-xs text-amber-400">
            Menunggu Respons
          </span>
        );
      case 'ACCEPTED':
        return (
          <span className="rounded-full border border-emerald-500/40 bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-400">
            Telah Diterima
          </span>
        );
      case 'REJECTED':
        return (
          <span className="rounded-full border border-rose-500/40 bg-rose-500/20 px-2 py-0.5 text-xs text-rose-400">
            Ditolak
          </span>
        );
      case 'EXPIRED':
        return <span className="rounded-full bg-slate-700 px-2 py-0.5 text-xs text-slate-400">Kedaluwarsa</span>;
      case 'BYPASSED':
        return <span className="rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-500">Bypassed</span>;
      default:
        return <span className="rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-400">Pending Antrean</span>;
    }
  };

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex items-center justify-between border-b border-slate-800 pb-4">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">Automated Waterfall Tendering Console</h2>
          <p className="text-sm text-slate-400">Monitoring alokasi tender berjenjang ke vendor secara sekuensial.</p>
        </div>
        <button
          onClick={fetchTenders}
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-cyan-400 hover:bg-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {loading ? (
        <div className="py-10 text-center text-slate-500">Memuat papan tender...</div>
      ) : dispatches.length === 0 ? (
        <div className="py-10 text-center text-slate-500">Belum ada proses tender yang berjalan.</div>
      ) : (
        <div className="space-y-6">
          {dispatches.map((td) => (
            <div key={td.id} className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span className="rounded border border-orange-900 bg-orange-950/60 px-2 py-0.5 text-xs font-bold uppercase text-orange-400">
                    TENDER #{td.id.substring(0, 8)}
                  </span>
                  <span className="ml-3 font-mono text-sm text-slate-300">Order Ref: {td.order_id.substring(0, 8)}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Status Sesi:</span>
                  <span
                    className={\`rounded px-2 py-0.5 text-xs font-bold \${
                      td.status === 'ACCEPTED'
                        ? 'bg-emerald-950 text-emerald-400'
                        : td.status === 'RUNNING'
                          ? 'bg-cyan-950 text-cyan-400'
                          : 'bg-rose-950 text-rose-400'
                    }\`}
                  >
                    {td.status}
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                {td.offers.map((offer) => (
                  <div
                    key={offer.id}
                    className={\`rounded-lg border p-3 transition \${
                      offer.status === 'OFFERED'
                        ? 'border-cyan-500/60 bg-cyan-950/30 ring-1 ring-cyan-500/40'
                        : offer.status === 'ACCEPTED'
                          ? 'border-emerald-500/50 bg-emerald-950/30'
                          : 'border-slate-800 bg-slate-900 opacity-70'
                    }\`}
                  >
                    <div className="mb-2 flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-400">Tingkat #{offer.sequence}</span>
                      {getStatusBadge(offer.status)}
                    </div>
                    <p className="font-semibold text-slate-200">{offer.vendor_id}</p>
                    <p className="mt-1 font-mono text-xs text-cyan-300">
                      {formatRupiahFromMinor(offer.offered_cost_minor_units)}
                    </p>

                    {offer.status === 'OFFERED' && (
                      <div className="mt-3 flex gap-2">
                        <button
                          onClick={() => handleRespond(offer.id, 'ACCEPT')}
                          className="flex-1 rounded bg-emerald-600 py-1 text-xs font-medium text-white transition hover:bg-emerald-500"
                        >
                          Terima
                        </button>
                        <button
                          onClick={() => handleRespond(offer.id, 'REJECT')}
                          className="flex-1 rounded bg-rose-600/80 py-1 text-xs font-medium text-white transition hover:bg-rose-600"
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
`;
fs.writeFileSync(waterfallBoardPath, waterfallBoardContent, 'utf8');
console.log('✅ Diperbaiki: frontend/src/components/Tendering/TenderWaterfallBoard.tsx');

// 3. Perbaiki backend/routes/api.php (Hapus pembungkus ganda Route::prefix('v1'))
const apiRoutesPath = path.resolve(process.cwd(), 'backend/routes/api.php');
if (fs.existsSync(apiRoutesPath)) {
  let content = fs.readFileSync(apiRoutesPath, 'utf8');
  
  // Bersihkan deklarasi sebelumnya jika ada
  content = content.replace(/\/\/ ==========================================\s*\/\/ INCREMENT 2B: RATE CARD & WATERFALL TENDER[\s\S]*$/, '');
  
  const cleanSnippet = `
// ==========================================
// INCREMENT 2B: RATE CARD & WATERFALL TENDER
// ==========================================
use App\\Http\\Controllers\\Api\\VendorRateCardController;
use App\\Http\\Controllers\\Api\\WaterfallTenderController;

Route::get('/rate-cards', [VendorRateCardController::class, 'index']);
Route::post('/rate-cards', [VendorRateCardController::class, 'store']);
Route::post('/rate-cards/calculate-estimate', [VendorRateCardController::class, 'calculateEstimate']);

Route::get('/tenders', [WaterfallTenderController::class, 'index']);
Route::post('/tenders/waterfall/start', [WaterfallTenderController::class, 'startWaterfall']);
Route::post('/tenders/offers/{offerId}/respond', [WaterfallTenderController::class, 'respondOffer']);
`;

  content = content.trimEnd() + '\n' + cleanSnippet + '\n';
  fs.writeFileSync(apiRoutesPath, content, 'utf8');
  console.log('✅ Diperbaiki: backend/routes/api.php (Route langsung tanpa double prefix)');
}

console.log('\nSelesai! Seluruh isu formatting dan routing telah diperbaiki.');