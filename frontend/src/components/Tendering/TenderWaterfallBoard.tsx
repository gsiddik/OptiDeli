import React, { useState, useEffect } from "react";
import type { TenderDispatch } from "../../types/tendering";
import { formatRupiahFromMinor } from "../../types/tendering";

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
          <span className="animate-pulse rounded-full border border-amber-500/40 bg-amber-500/20 px-2 py-0.5 text-xs text-amber-400">
            Menunggu Respons
          </span>
        );
      case "ACCEPTED":
        return (
          <span className="rounded-full border border-emerald-500/40 bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-400">
            Telah Diterima
          </span>
        );
      case "REJECTED":
        return (
          <span className="rounded-full border border-rose-500/40 bg-rose-500/20 px-2 py-0.5 text-xs text-rose-400">
            Ditolak
          </span>
        );
      case "EXPIRED":
        return (
          <span className="rounded-full bg-slate-700 px-2 py-0.5 text-xs text-slate-400">
            Kedaluwarsa
          </span>
        );
      case "BYPASSED":
        return (
          <span className="rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-500">
            Bypassed
          </span>
        );
      default:
        return (
          <span className="rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-400">
            Pending Antrean
          </span>
        );
    }
  };

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex items-center justify-between border-b border-slate-800 pb-4">
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
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-cyan-400 hover:bg-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {loading ? (
        <div className="py-10 text-center text-slate-500">
          Memuat papan tender...
        </div>
      ) : dispatches.length === 0 ? (
        <div className="py-10 text-center text-slate-500">
          Belum ada proses tender yang berjalan.
        </div>
      ) : (
        <div className="space-y-6">
          {dispatches.map((td) => (
            <div
              key={td.id}
              className="space-y-4 rounded-lg border border-slate-800 bg-slate-950 p-5"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span className="rounded border border-orange-900 bg-orange-950/60 px-2 py-0.5 text-xs font-bold uppercase text-orange-400">
                    TENDER #{td.id.substring(0, 8)}
                  </span>
                  <span className="ml-3 font-mono text-sm text-slate-300">
                    Order Ref: {td.order_id.substring(0, 8)}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Status Sesi:</span>
                  <span
                    className={`rounded px-2 py-0.5 text-xs font-bold ${
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

              <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                {td.offers.map((offer) => (
                  <div
                    key={offer.id}
                    className={`rounded-lg border p-3 transition ${
                      offer.status === "OFFERED"
                        ? "border-cyan-500/60 bg-cyan-950/30 ring-1 ring-cyan-500/40"
                        : offer.status === "ACCEPTED"
                          ? "border-emerald-500/50 bg-emerald-950/30"
                          : "border-slate-800 bg-slate-900 opacity-70"
                    }`}
                  >
                    <div className="mb-2 flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-400">
                        Tingkat #{offer.sequence}
                      </span>
                      {getStatusBadge(offer.status)}
                    </div>
                    <p className="font-semibold text-slate-200">
                      {offer.vendor_id}
                    </p>
                    <p className="mt-1 font-mono text-xs text-cyan-300">
                      {formatRupiahFromMinor(offer.offered_cost_minor_units)}
                    </p>

                    {offer.status === "OFFERED" && (
                      <div className="mt-3 flex gap-2">
                        <button
                          onClick={() => handleRespond(offer.id, "ACCEPT")}
                          className="flex-1 rounded bg-emerald-600 py-1 text-xs font-medium text-white transition hover:bg-emerald-500"
                        >
                          Terima
                        </button>
                        <button
                          onClick={() => handleRespond(offer.id, "REJECT")}
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
