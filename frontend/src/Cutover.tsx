import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { money } from "./commercial";
import { can } from "./opsTypes";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";

type Row = {
  id: string;
  code: string;
  name: string;
  status: "PLANNING" | "PARALLEL" | "CUT_OVER";
  legacy_system: string;
  customer_label: string | null;
  project_label: string | null;
  checks_done: number;
  checks_total: number;
  planned_cutover_on: string | null;
};
type Totals = { spm_count: number; sell_minor: number; buy_minor: number };
type Recon = {
  period: string;
  status: "MATCHED" | "DIFFERENT" | "ACCEPTED" | "NO_LEGACY" | "OPEN";
  deli: Totals;
  legacy: (Totals & { source_note: string | null }) | null;
  diff: Totals | null;
  accept_note: string | null;
};
type Detail = {
  cutover: Row & {
    tolerance_minor: number;
    legacy_frozen_at: string | null;
    parallel_from: string | null;
    notes: string | null;
  };
  checks: {
    code: string;
    label: string;
    done: boolean;
    done_by_name: string | null;
    note: string | null;
  }[];
  reconciliation: Recon[];
  gate: { open: boolean; blockers: string[] };
};
type Opt = { value: string; label: string };

const optional = (f: EditorField): EditorField => ({ ...f, required: false });

function useOptions() {
  const [o, setO] = useState<Record<string, Opt[]>>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    Promise.all([
      api<Rows>("master/customers/options"),
      api<Rows>("master/customer_projects/options"),
    ])
      .then(([c, p]) => {
        if (!live) return;
        const map = (r: Rows) =>
          r.data.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` }));
        setO({ customers: map(c), projects: map(p) });
      })
      .catch(() => live && setO({ customers: [], projects: [] }));
    return () => {
      live = false;
    };
  }, []);
  return o;
}

export function CutoverPage() {
  const session = useSession();
  const options = useOptions();
  const state = useData<{ data: Row[] }>("cutover");
  const [creating, setCreating] = useState(false);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">CUT-OVER</span>
          <h1 tabIndex={-1}>Cut-over dari ARK / OP</h1>
          <p>
            Satu pelacak per pelanggan atau proyek: daftar periksa, masa paralel
            yang direkonsiliasi dengan total kontrol sistem lama, lalu Gerbang C
            yang disetujui orang lain sebelum sistem lama dibekukan.
          </p>
        </div>
        {can(session, "cutover.manage") && (
          <button className="primary" onClick={() => setCreating(true)}>
            + Pelacak cut-over
          </button>
        )}
      </div>
      <ErrorBox error={state.error} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : state.data?.data.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Cut-over</th>
                  <th>Pelanggan / proyek</th>
                  <th>Sistem lama</th>
                  <th>Daftar periksa</th>
                  <th>Rencana</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {state.data.data.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <Link to={`/cutover/${c.id}`}>{c.code}</Link>
                      <br />
                      <small className="muted">{c.name}</small>
                    </td>
                    <td>
                      {c.customer_label}
                      {c.project_label && ` · ${c.project_label}`}
                    </td>
                    <td>{c.legacy_system}</td>
                    <td>
                      {c.checks_done}/{c.checks_total}
                    </td>
                    <td>{c.planned_cutover_on?.slice(0, 10) ?? "–"}</td>
                    <td>
                      <Badge value={c.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada pelacak cut-over.</Empty>
        )}
      </section>
      {creating && options && (
        <EditorDialog
          title="Pelacak cut-over baru"
          fields={[
            { name: "code", label: "Kode", value: "" },
            { name: "name", label: "Nama", value: "" },
            {
              name: "customer_id",
              label: "Pelanggan",
              kind: "select",
              options: options.customers,
              value: "",
            },
            optional({
              name: "project_id",
              label: "Proyek (kosong = seluruh pelanggan)",
              kind: "select",
              options: options.projects,
              value: "",
            }),
            {
              name: "legacy_system",
              label: "Sistem lama",
              kind: "select",
              options: [
                { value: "ARK", label: "ARK TMS" },
                { value: "OP", label: "Order Planning" },
                { value: "OTHER", label: "Lainnya" },
              ],
              value: "ARK",
            },
            optional({
              name: "parallel_from",
              label: "Masa paralel mulai",
              kind: "date",
              value: "",
            }),
            optional({
              name: "planned_cutover_on",
              label: "Rencana cut-over",
              kind: "date",
              value: "",
            }),
            optional({
              name: "tolerance_minor",
              label: "Toleransi selisih nilai (Rp)",
              kind: "money",
              value: "",
            }),
          ]}
          onClose={() => setCreating(false)}
          onSubmit={async (body, key) => {
            await submitRecord("cutover", body, key);
            setCreating(false);
            state.refresh();
          }}
        />
      )}
    </>
  );
}

const STATUS_LABEL: Record<Recon["status"], string> = {
  MATCHED: "Sesuai",
  DIFFERENT: "Selisih",
  ACCEPTED: "Selisih diterima",
  NO_LEGACY: "Belum ada total legacy",
  OPEN: "Berjalan",
};
const signed = (n: number) => (n > 0 ? `+${n}` : String(n));

export function CutoverDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const state = useData<{ data: Detail }>(`cutover/${id}`);
  const [failure, setFailure] = useState<string>();
  const [dialog, setDialog] = useState<
    | { kind: "totals"; period?: string }
    | { kind: "accept"; period: string }
    | { kind: "rollback" }
    | null
  >(null);
  const d = state.data?.data;
  if (state.loading) return <Loading />;
  if (state.error || !d) return <ErrorBox error={state.error} />;
  const c = d.cutover;
  const manage = can(session, "cutover.manage");
  const approve = can(session, "cutover.approve");
  const frozen = c.status === "CUT_OVER";

  async function act(run: () => Promise<unknown>) {
    setFailure(undefined);
    try {
      await run();
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">CUT-OVER</span>
          <h1 tabIndex={-1}>{c.code}</h1>
          <p>
            {c.name} · {c.customer_label}
            {c.project_label && ` · ${c.project_label}`} · sistem lama{" "}
            {c.legacy_system}
          </p>
        </div>
        <Badge value={c.status} />
      </div>
      <ErrorBox error={failure} />
      {frozen && (
        <div className="alert" role="status">
          Sistem lama dibekukan (hanya baca) sejak{" "}
          {c.legacy_frozen_at?.slice(0, 16).replace("T", " ")}.
        </div>
      )}
      <section className="card">
        <h2>Gerbang C</h2>
        {c.status === "PLANNING" && manage && (
          <button
            className="primary"
            onClick={() =>
              void act(() =>
                api(`cutover/${c.id}/status`, "POST", { to: "PARALLEL" }),
              )
            }
          >
            Mulai masa paralel
          </button>
        )}
        {c.status === "PARALLEL" && (
          <>
            {d.gate.blockers.length ? (
              <ul>
                {d.gate.blockers.map((b) => (
                  <li key={b}>{b}</li>
                ))}
              </ul>
            ) : (
              <p>Semua syarat terpenuhi.</p>
            )}
            {approve && (
              <button
                className="primary"
                disabled={!d.gate.open}
                onClick={() =>
                  void act(() =>
                    api(`cutover/${c.id}/status`, "POST", { to: "CUT_OVER" }),
                  )
                }
              >
                Setujui cut-over
              </button>
            )}
          </>
        )}
        {frozen && approve && (
          <button onClick={() => setDialog({ kind: "rollback" })}>
            Batalkan cut-over
          </button>
        )}
      </section>
      <section className="card block">
        <h2>Daftar periksa</h2>
        <ul className="checklist">
          {d.checks.map((k) => (
            <li key={k.code}>
              <label>
                <input
                  type="checkbox"
                  checked={k.done}
                  disabled={!manage || frozen}
                  onChange={(e) =>
                    void act(() =>
                      api(`cutover/${c.id}/checks/${k.code}`, "PUT", {
                        done: e.target.checked,
                      }),
                    )
                  }
                />{" "}
                {k.label}
              </label>
              {k.done && k.done_by_name && (
                <small className="muted"> — {k.done_by_name}</small>
              )}
            </li>
          ))}
        </ul>
      </section>
      <section className="card block">
        <div className="page-heading">
          <h2>Rekonsiliasi per bulan</h2>
          {manage && !frozen && (
            <button onClick={() => setDialog({ kind: "totals" })}>
              + Total kontrol legacy
            </button>
          )}
        </div>
        <p className="muted">
          Bulan = bulan rencana jemput SPM. SPM yang sudah keluar dari draf dan
          tidak dibatalkan, dengan biaya jual dan beli terverifikasi. Toleransi
          selisih nilai: {money(c.tolerance_minor)}.
        </p>
        {d.reconciliation.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Bulan</th>
                  <th>SPM (legacy / baru)</th>
                  <th>Jual (legacy / baru)</th>
                  <th>Beli (legacy / baru)</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {d.reconciliation.map((r) => (
                  <tr key={r.period}>
                    <td>{r.period}</td>
                    <td>
                      {r.legacy?.spm_count ?? "–"} / {r.deli.spm_count}
                      {r.diff && r.diff.spm_count !== 0 && (
                        <small> ({signed(r.diff.spm_count)})</small>
                      )}
                    </td>
                    <td>
                      {r.legacy ? money(r.legacy.sell_minor) : "–"} /{" "}
                      {money(r.deli.sell_minor)}
                      {r.diff && r.diff.sell_minor !== 0 && (
                        <small> ({money(r.diff.sell_minor)})</small>
                      )}
                    </td>
                    <td>
                      {r.legacy ? money(r.legacy.buy_minor) : "–"} /{" "}
                      {money(r.deli.buy_minor)}
                      {r.diff && r.diff.buy_minor !== 0 && (
                        <small> ({money(r.diff.buy_minor)})</small>
                      )}
                    </td>
                    <td>
                      <Badge value={r.status} />
                      <br />
                      <small className="muted">
                        {STATUS_LABEL[r.status]}
                        {r.accept_note && ` — ${r.accept_note}`}
                      </small>
                    </td>
                    <td>
                      {manage && !frozen && (
                        <div className="actions">
                          <button
                            onClick={() =>
                              setDialog({ kind: "totals", period: r.period })
                            }
                          >
                            {r.legacy ? "Ubah" : "Isi total"}
                          </button>
                          {r.status === "DIFFERENT" && (
                            <button
                              onClick={() =>
                                setDialog({ kind: "accept", period: r.period })
                              }
                            >
                              Terima selisih
                            </button>
                          )}
                          {r.legacy && (
                            <button
                              onClick={() =>
                                void act(() =>
                                  api(
                                    `cutover/${c.id}/totals/${r.period}`,
                                    "DELETE",
                                  ),
                                )
                              }
                            >
                              Hapus
                            </button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>
            Belum ada total kontrol legacy maupun SPM pada masa paralel.
          </Empty>
        )}
      </section>
      {dialog?.kind === "totals" && (
        <EditorDialog
          title="Total kontrol sistem lama"
          fields={[
            {
              name: "period",
              label: "Bulan (YYYY-MM)",
              value: dialog.period ?? "",
            },
            {
              name: "spm_count",
              label: "Jumlah SPM",
              kind: "number",
              value: "",
            },
            {
              name: "sell_minor",
              label: "Total jual (Rp)",
              kind: "money",
              value: "",
            },
            {
              name: "buy_minor",
              label: "Total beli (Rp)",
              kind: "money",
              value: "",
            },
            optional({
              name: "source_note",
              label: "Sumber (mis. nama laporan ARK)",
              value: "",
            }),
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`cutover/${c.id}/totals`, body, key, {}, "PUT");
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog?.kind === "accept" && (
        <EditorDialog
          title={`Terima selisih ${dialog.period}`}
          fields={[
            {
              name: "note",
              label: "Penjelasan selisih (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(
              `cutover/${c.id}/totals/${dialog.period}/accept`,
              body,
              key,
            );
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog?.kind === "rollback" && (
        <EditorDialog
          title="Batalkan cut-over"
          fields={[
            {
              name: "reason",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`cutover/${c.id}/status`, body, key, {
              to: "PARALLEL",
            });
            setDialog(null);
            state.refresh();
          }}
        />
      )}
    </>
  );
}
