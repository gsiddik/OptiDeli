import React, { useState, useEffect } from "react";
import { TenderDispatch, formatRupiahFromMinor } from "../../types/tendering";

export const TenderWaterfallBoard: React.FC = () => {
  const [dispatches, setDispatches] = useState<TenderDispatch[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchTenders = async () => {
    try {
      const res = await fetch("/api/v1/tenders", {
        headers: { "X-Tenant-Id": "demo", Accept: "application/json" },
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

  const handleRespond = async (
    offerId: string,
    action: "ACCEPT" | "REJECT",
  ) => {
    await fetch(`/api/v1/tenders/offers/${offerId}/respond`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Tenant-Id": "demo",
        Accept: "application/json",
      },
      body: JSON.stringify({ action }),
    });
    fetchTenders();
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "OFFERED":
        return (
          <span className="bg-amber-500/20 text-amber-400 border border-amber-500/40 px-2 py-0.5 rounded-full text-xs animate-pulse">
            Menunggu Respons
          </span>
        );
      case "ACCEPTED":
        return (
          <span className="bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 px-2 py-0.5 rounded-full text-xs">
            Telah Diterima
          </span>
        );
      case "REJECTED":
        return (
          <span className="bg-rose-500/20 text-rose-400 border border-rose-500/40 px-2 py-0.5 rounded-full text-xs">
            Ditolak
          </span>
        );
      case "EXPIRED":
        return (
          <span className="bg-slate-700 text-slate-400 px-2 py-0.5 rounded-full text-xs">
            Kedaluwarsa
          </span>
        );
      case "BYPASSED":
        return (
          <span className="bg-slate-800 text-slate-500 px-2 py-0.5 rounded-full text-xs">
            Bypassed
          </span>
        );
      default:
        return (
          <span className="bg-slate-800 text-slate-400 px-2 py-0.5 rounded-full text-xs">
            Pending Antrean
          </span>
        );
    }
  };

  return (
    <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 text-slate-100 shadow-xl space-y-6">
      <div className="flex justify-between items-center border-b border-slate-800 pb-4">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">
            Automated Waterfall Tendering Console
          </h2>
          <p className="text-sm text-slate-400">
            Monitoring alokasi tender berjenjang ke vendor secara sekuensial.
          </p>
        </div>
        <button
          onClick={fetchTenders}
          className="bg-slate-800 hover:bg-slate-700 text-cyan-400 px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {loading ? (
        <div className="text-center py-10 text-slate-500">
          Memuat papan tender...
        </div>
      ) : dispatches.length === 0 ? (
        <div className="text-center py-10 text-slate-500">
          Belum ada proses tender yang berjalan.
        </div>
      ) : (
        <div className="space-y-6">
          {dispatches.map((td) => (
            <div
              key={td.id}
              className="bg-slate-950 border border-slate-800 rounded-lg p-5 space-y-4"
            >
              <div className="flex flex-wrap justify-between items-center gap-2">
                <div>
                  <span className="text-xs uppercase font-bold text-orange-400 bg-orange-950/60 px-2 py-0.5 rounded border border-orange-900">
                    TENDER #{td.id.substring(0, 8)}
                  </span>
                  <span className="ml-3 text-sm text-slate-300 font-mono">
                    Order Ref: {td.order_id.substring(0, 8)}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Status Sesi:</span>
                  <span
                    className={`text-xs font-bold px-2 py-0.5 rounded ${
                      td.status === "ACCEPTED"
                        ? "bg-emerald-950 text-emerald-400"
                        : td.status === "RUNNING"
                          ? "bg-cyan-950 text-cyan-400"
                          : "bg-rose-950 text-rose-400"
                    }`}
                  >
                    {td.status}
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                {td.offers.map((offer) => (
                  <div
                    key={offer.id}
                    className={`p-3 rounded-lg border transition ${
                      offer.status === "OFFERED"
                        ? "bg-cyan-950/30 border-cyan-500/60 ring-1 ring-cyan-500/40"
                        : offer.status === "ACCEPTED"
                          ? "bg-emerald-950/30 border-emerald-500/50"
                          : "bg-slate-900 border-slate-800 opacity-70"
                    }`}
                  >
                    <div className="flex justify-between items-center mb-2">
                      <span className="text-xs font-bold text-slate-400">
                        Tingkat #{offer.sequence}
                      </span>
                      {getStatusBadge(offer.status)}
                    </div>
                    <p className="font-semibold text-slate-200">
                      {offer.vendor_id}
                    </p>
                    <p className="text-xs font-mono text-cyan-300 mt-1">
                      {formatRupiahFromMinor(offer.offered_cost_minor_units)}
                    </p>

                    {offer.status === "OFFERED" && (
                      <div className="mt-3 flex gap-2">
                        <button
                          onClick={() => handleRespond(offer.id, "ACCEPT")}
                          className="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs py-1 rounded font-medium transition"
                        >
                          Terima
                        </button>
                        <button
                          onClick={() => handleRespond(offer.id, "REJECT")}
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
