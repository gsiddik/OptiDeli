import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { ErrorBox } from "./components";
import { money } from "./commercial";
import { can } from "./opsTypes";
import { EditorDialog, submitRecord } from "./RecordEditor";

type Charge = { id: string; side: "SELL" | "BUY"; status: string };
type Preview = {
  agreed_minor: number;
  unverified_count: number;
  awb_total: number;
  awb_delivered: number;
  already_billed: number;
  ready: boolean;
};

/** Verification of the selling and buying charges of a discharged SPM, and the vendor bill that must match the verified buying charges. */
export function SpmFinance({
  spmId,
  status,
  charges,
  hasVendor,
  onChange,
}: {
  spmId: string;
  status: string;
  charges: Charge[];
  hasVendor: boolean;
  onChange: () => void;
}) {
  const session = useSession();
  const [dialog, setDialog] = useState<
    { reject: "SELL" | "BUY" } | "bill" | null
  >(null);
  const [failure, setFailure] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const verifiable = ["DISCHARGED", "DOC_VERIFIED", "CLOSED"].includes(status);
  const sides = (
    [
      ["SELL", "jual", "selling.verify"],
      ["BUY", "beli", "cost.verify"],
    ] as const
  )
    .map(([side, label, perm]) => ({
      side,
      label,
      waiting: charges.filter((c) => c.side === side && c.status === "PROPOSED")
        .length,
      allowed: can(session, perm),
    }))
    .filter((s) => s.allowed && s.waiting > 0);
  const canBill = hasVendor && can(session, "cost.verify") && verifiable;
  const preview = useData<{ data: Preview }>(
    canBill ? `spm/${spmId}/vendor-bill` : "reports/spm-status",
  );
  if (!verifiable || (!sides.length && !canBill)) return null;
  const p = canBill ? (preview.data?.data as Preview | undefined) : undefined;

  async function verify(side: "SELL" | "BUY") {
    setFailure(undefined);
    try {
      const res = await api<{ data: { decided: number; total_minor: number } }>(
        `spm/${spmId}/charges/verify`,
        "POST",
        { side, decision: "VERIFY" },
      );
      setNotice(
        `${res.data.decided} biaya diverifikasi (${money(res.data.total_minor)}).`,
      );
      onChange();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Tidak dapat memverifikasi.");
    }
  }

  return (
    <section className="card block" aria-label="Verifikasi biaya">
      <h2>Verifikasi &amp; tagihan</h2>
      <ErrorBox error={failure} />
      {notice && (
        <div className="alert success" role="status">
          {notice}
        </div>
      )}
      <div className="actions">
        {sides.map((s) => (
          <span key={s.side} className="actions">
            <button className="primary" onClick={() => void verify(s.side)}>
              Verifikasi {s.waiting} biaya {s.label}
            </button>
            <button onClick={() => setDialog({ reject: s.side })}>
              Tolak biaya {s.label}
            </button>
          </span>
        ))}
        {canBill && (
          <button onClick={() => setDialog("bill")} disabled={!p?.ready}>
            Catat tagihan vendor
          </button>
        )}
      </div>
      {canBill && p && (
        <small className="muted">
          3-way match: biaya beli disepakati {money(p.agreed_minor)}
          {p.unverified_count > 0 &&
            ` (${p.unverified_count} belum diverifikasi)`}
          ; AWB diterima {p.awb_delivered}/{p.awb_total}.
        </small>
      )}
      <small className="muted">
        Biaya yang dibuat sendiri tidak dapat Anda verifikasi. Biaya jual yang
        terverifikasi siap ditagih di menu Penagihan.
      </small>
      {typeof dialog === "object" && dialog && "reject" in dialog && (
        <EditorDialog
          title={`Tolak biaya ${dialog.reject === "SELL" ? "jual" : "beli"}`}
          fields={[
            {
              name: "note",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`spm/${spmId}/charges/verify`, body, key, {
              side: dialog.reject,
              decision: "REJECT",
            });
            setDialog(null);
            onChange();
          }}
        />
      )}
      {dialog === "bill" && p && (
        <EditorDialog
          title="Tagihan vendor"
          fields={[
            {
              name: "vendor_invoice_number",
              label: "Nomor invoice vendor",
              value: "",
            },
            {
              name: "amount_minor",
              label: `Jumlah invoice vendor (Rp; terverifikasi ${money(p.agreed_minor)})`,
              kind: "money",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`spm/${spmId}/vendor-bill`, body, key);
            setDialog(null);
            setNotice("Tagihan vendor dicatat di sub-ledger.");
            onChange();
          }}
        />
      )}
    </section>
  );
}
