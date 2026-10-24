import React, { useState, useEffect } from "react";
import { api } from "../../api";
import type { EdiTransmission } from "../../types/edi";

export const EdiGatewayConsole: React.FC = () => {
  const [transmissions, setTransmissions] = useState<EdiTransmission[]>([]);
  const [selectedX12, setSelectedX12] = useState<EdiTransmission | null>(null);

  // Form Simulator State
  const [testSet, setTestSet] = useState<"204" | "214" | "210">("204");
  const [shipmentId, setShipmentId] = useState("PO-TEST-9001");

  const fetchTransmissions = async () => {
    try {
      const data = await api<{ data: EdiTransmission[] }>("edi/transmissions");
      setTransmissions(data.data || []);
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    fetchTransmissions();
  }, []);

  const handleSimulate = async () => {
    if (testSet === "204") {
      const sample = `ISA*00*          *00*          *ZZ*ERP-CUSTOMER   *ZZ*OPTIDELI       *261023*1500*U*00401*000000001*0*P*>~\nGS*SM*ERP-CUSTOMER*OPTIDELI*20261023*1500*1*X*004010~\nST*204*0001~\nB2**CARRIER*${shipmentId}**PP~\nN1*SH*JAKARTA-LOGISTICS-HUB~\nN1*CN*SURABAYA-DIST-CENTER~\nOID*FMCG RETAIL GOODS*50*CT*1200*KG~\nL3*1200*G*480000000~\nSE*8*0001~\nGE*1*1~\nIEA*1*000000001~`;
      await api<unknown>("edi/inbound-204", "POST", { raw_x12: sample });
    } else if (testSet === "214") {
      await api<unknown>("edi/generate-214", "POST", {
        shipment_id: shipmentId,
        status: "DELIVERED",
        location_city: "SURABAYA",
      });
    } else {
      await api<unknown>("edi/sync-210", "POST", {
        invoice_number: `INV-${shipmentId}`,
        order_id: shipmentId,
        customer_ar_minor_units: 480000000,
        vendor_ap_minor_units: 390000000,
        accessorials_minor_units: 20000000,
      });
    }
    fetchTransmissions();
  };

  return (
    <div className="space-y-6 rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-xl">
      <div className="flex flex-col items-start justify-between gap-4 border-b border-slate-800 pb-4 md:flex-row md:items-center">
        <div>
          <h2 className="text-xl font-bold text-cyan-400">
            Enterprise EDI Gateway (X12 Standard)
          </h2>
          <p className="text-sm text-slate-400">
            Integrasi protokol EDI 204 (Load Tender), EDI 214 (Status
            Pelacakan), dan EDI 210 (Tagihan Finansial).
          </p>
        </div>
        <button
          onClick={fetchTransmissions}
          className="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-cyan-400 hover:bg-slate-700"
        >
          Refresh Data
        </button>
      </div>

      {/* Simulator Quick Action */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs">
        <span className="font-semibold text-slate-300">
          Simulator Transmisi:
        </span>
        <select
          value={testSet}
          onChange={(e) => setTestSet(e.target.value as "204" | "214" | "210")}
          className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-slate-200"
        >
          <option value="204">EDI 204 (Inbound Load Tender)</option>
          <option value="214">EDI 214 (Outbound Status Tracking)</option>
          <option value="210">EDI 210 (Freight Invoice Sync)</option>
        </select>
        <input
          type="text"
          value={shipmentId}
          onChange={(e) => setShipmentId(e.target.value)}
          placeholder="Ref ID"
          className="rounded border border-slate-700 bg-slate-900 px-3 py-1.5 text-slate-200"
        />
        <button
          onClick={handleSimulate}
          className="rounded bg-cyan-600 px-4 py-1.5 font-semibold text-white transition hover:bg-cyan-500"
        >
          Simulasikan Transmisi
        </button>
      </div>

      {/* Transmissions Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-700 bg-slate-800/60 text-xs font-semibold uppercase tracking-wider text-slate-300">
              <th className="px-4 py-3">Set EDI</th>
              <th className="px-4 py-3">Arah</th>
              <th className="px-4 py-3">Nomor Referensi</th>
              <th className="px-4 py-3">Waktu Transmisi</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-center">Payload X12</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800">
            {transmissions.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-6 text-center text-slate-500">
                  Belum ada transmisi EDI tercatat.
                </td>
              </tr>
            ) : (
              transmissions.map((t) => (
                <tr key={t.id} className="transition hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-mono font-bold text-cyan-300">
                    EDI {t.transaction_set}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`rounded px-2 py-0.5 text-xs font-bold ${
                        t.direction === "INBOUND"
                          ? "border border-cyan-800 bg-cyan-950 text-cyan-400"
                          : "border border-emerald-800 bg-emerald-950 text-emerald-400"
                      }`}
                    >
                      {t.direction}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-mono text-slate-200">
                    {t.reference_id}
                  </td>
                  <td className="px-4 py-3 text-xs text-slate-400">
                    {new Date(t.transmitted_at).toLocaleString("id-ID")}
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded-full border border-emerald-800 bg-emerald-950 px-2 py-0.5 text-xs text-emerald-400">
                      {t.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <button
                      onClick={() => setSelectedX12(t)}
                      className="rounded border border-slate-700 bg-slate-800 px-2.5 py-1 text-xs text-cyan-400 hover:bg-slate-700"
                    >
                      Lihat X12
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Modal Inspector Raw ANSI X12 */}
      {selectedX12 && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
          <div className="w-full max-w-2xl rounded-xl border border-slate-800 bg-slate-950 p-6 shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <h3 className="font-bold text-cyan-400">
                  ANSI X12 Message Detail (EDI {selectedX12.transaction_set})
                </h3>
                <p className="text-xs text-slate-400">
                  Ref: {selectedX12.reference_id}
                </p>
              </div>
              <button
                onClick={() => setSelectedX12(null)}
                className="text-slate-400 hover:text-white"
              >
                ✕
              </button>
            </div>
            <pre className="mt-4 max-h-96 overflow-x-auto rounded-lg border border-slate-800 bg-slate-900 p-4 font-mono text-xs text-emerald-400">
              {selectedX12.raw_content}
            </pre>
            <div className="mt-4 flex justify-end">
              <button
                onClick={() => setSelectedX12(null)}
                className="rounded bg-slate-800 px-4 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
