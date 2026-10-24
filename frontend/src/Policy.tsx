import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { ErrorBox, Field, Form, Loading } from "./components";
import { stages } from "./types";
import { can } from "./opsTypes";
type Policy = {
  minimum_margin_bps: number;
  stage_margin_bps: Record<string, number>;
  version: number;
};
export function PolicyPage() {
  const state = useData<{ data: Policy }>("commercial/policy");
  const session = useSession();
  const [saved, setSaved] = useState(false);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">GOVERNANCE / KOMERSIAL</span>
          <h1 tabIndex={-1}>Kebijakan margin</h1>
          <p>
            Threshold dasar dan pengecualian per tahap. Perubahan hanya dapat
            dilakukan CFO / Owner.
          </p>
        </div>
      </div>
      <ErrorBox error={state.error} />
      {saved && (
        <div className="alert success" role="status">
          Kebijakan diperbarui. Rencana lama memerlukan pemeriksaan dan
          pembuatan ulang sebelum dispatch.
        </div>
      )}
      {state.loading ? (
        <Loading />
      ) : (
        state.data && (
          <PolicyForm
            key={state.data.data.version}
            policy={state.data.data}
            readOnly={!can(session, "policy.manage")}
            onSave={() => {
              setSaved(true);
              state.refresh();
            }}
          />
        )
      )}
    </>
  );
}
function parseBps(value: string) {
  if (!/^\d{1,3}(?:[.,]\d{1,2})?$/.test(value))
    throw new Error("Threshold harus 0–100%, maksimal dua desimal.");
  const [whole, dec = ""] = value.replace(",", ".").split(".");
  const bps = Number(whole) * 100 + Number(dec.padEnd(2, "0"));
  if (bps > 10000) throw new Error("Threshold maksimal 100%.");
  return bps;
}
function PolicyForm({
  policy,
  readOnly,
  onSave,
}: {
  policy: Policy;
  readOnly: boolean;
  onSave: () => void;
}) {
  const [base, setBase] = useState(String(policy.minimum_margin_bps / 100));
  const [overrides, setOverrides] = useState<Record<string, string>>(
    Object.fromEntries(
      Object.entries(policy.stage_margin_bps).map(([k, v]) => [
        k,
        String(v / 100),
      ]),
    ),
  );
  const [reason, setReason] = useState("");
  return (
    <section className="card">
      <h2>Threshold aktif · Versi {policy.version}</h2>
      <Form
        label="Simpan kebijakan margin"
        disabled={readOnly}
        onSubmit={async () => {
          const stage = Object.fromEntries(
            Object.entries(overrides)
              .filter(([, v]) => v !== "")
              .map(([k, v]) => [k, parseBps(v)]),
          );
          await api("commercial/policy", "PUT", {
            minimum_margin_bps: parseBps(base),
            stage_margin_bps: stage,
            reason,
          });
          onSave();
        }}
      >
        <div className="form-grid">
          <Field label="Threshold dasar (%)">
            <input
              inputMode="decimal"
              required
              readOnly={readOnly}
              value={base}
              onChange={(e) => setBase(e.target.value)}
            />
          </Field>
          {Object.entries(stages).map(([k, v]) => (
            <Field
              key={k}
              label={`${v} (%)`}
              hint="Kosongkan untuk mengikuti threshold dasar."
            >
              <input
                inputMode="decimal"
                readOnly={readOnly}
                value={overrides[k] ?? ""}
                onChange={(e) =>
                  setOverrides((old) => ({ ...old, [k]: e.target.value }))
                }
              />
            </Field>
          ))}
        </div>
        {!readOnly && (
          <Field label="Alasan perubahan kebijakan">
            <textarea
              required
              minLength={20}
              maxLength={2000}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </Field>
        )}
        <div className="alert warning">
          Perubahan threshold membatalkan validitas snapshot rencana sebelumnya.
          Sistem menuntut rencana baru dan persetujuan ulang. Tier otorisasi
          tetap ditentukan dari margin aktual: ≥10% Supervisor, ≥5% Head of
          Logistics, dan &lt;5% CFO / Owner jika margin di bawah threshold
          tahap.
        </div>
      </Form>
    </section>
  );
}
