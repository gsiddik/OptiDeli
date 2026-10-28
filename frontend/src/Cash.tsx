import { useEffect, useRef, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import {
  Badge,
  Empty,
  ErrorBox,
  Field,
  Form,
  Loading,
  Pagination,
} from "./components";
import { dateTime, minor, money } from "./commercial";
import { can } from "./opsTypes";
import type { Page } from "./types";
import { Select } from "./Select";

export const VOUCHER_STATUS: Record<string, string> = {
  REQUESTED: "Menunggu ACC",
  APPROVED: "Disetujui",
  REJECTED: "Ditolak",
  DISBURSED: "Dicairkan",
  SETTLED: "Direalisasi",
  CANCELLED: "Dibatalkan",
};

type Voucher = {
  id: string;
  number: string;
  status: string;
  trip_id: string;
  trip_number: string | null;
  spm_number: string | null;
  driver_label: string | null;
  cashbox_label: string | null;
  purpose: string | null;
  requested_minor: number;
  approved_minor: number | null;
  disbursed_minor: number | null;
  actual_minor: number | null;
  returned_minor: number | null;
  reimbursed_minor: number | null;
  requested_by: number;
  status_reason: string | null;
  created_at: string;
};
type VLine = {
  id: string;
  charge_code_id: string;
  charge_code_label: string | null;
  kind: "PLAN" | "UNPLAN";
  planned_minor: number | null;
  requested_minor: number;
  approved_minor: number | null;
  actual_minor: number | null;
  receipt_ref: string | null;
  reason: string | null;
};
type VDetail = {
  voucher: Voucher;
  lines: VLine[];
  ledger: {
    id: string;
    entry_type: string;
    amount_minor: number;
    memo: string | null;
    created_at: string;
  }[];
};
type Cashbox = {
  id: string;
  code: string;
  name: string;
  active: boolean;
  balance_minor: number;
};
type Overview = {
  cashboxes: Cashbox[];
  drivers: { driver_id: string; driver_name: string; balance_minor: number }[];
  approval_limit_minor: number;
};
type Opt = { value: string; label: string };

const rupiah = (v: number | null | undefined) =>
  v === null || v === undefined ? "—" : money(v);
const asInput = (m: number | null | undefined) =>
  m === null || m === undefined ? "" : String(m / 100);

export function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = ref.current!;
    const previous = document.activeElement as HTMLElement;
    dialog.showModal();
    return () => {
      dialog.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog
      className="record-dialog"
      ref={ref}
      aria-label={title}
      onCancel={onClose}
    >
      <h2>{title}</h2>
      <button
        type="button"
        className="close"
        onClick={onClose}
        aria-label="Tutup dialog"
      >
        ×
      </button>
      {children}
    </dialog>
  );
}

function Money({
  label,
  value,
  onChange,
  required = true,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
}) {
  return (
    <Field label={label}>
      <input
        inputMode="decimal"
        required={required}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </Field>
  );
}

function useChargeOptions() {
  const [options, setOptions] = useState<Opt[]>();
  useEffect(() => {
    let live = true;
    api<{ data: { id: string; code: string; name: string }[] }>(
      "master/charge_codes/options",
    )
      .then(
        (r) =>
          live &&
          setOptions(
            r.data.map((o) => ({
              value: o.id,
              label: `${o.code} · ${o.name}`,
            })),
          ),
      )
      .catch(() => live && setOptions([]));
    return () => {
      live = false;
    };
  }, []);
  return options;
}

/** Asks for driver cash for a trip: one or more lines per charge code. */
function RequestDialog({
  tripId,
  onClose,
  onDone,
}: {
  tripId: string;
  onClose: () => void;
  onDone: (id: string) => void;
}) {
  const charges = useChargeOptions();
  const [purpose, setPurpose] = useState("");
  const [lines, setLines] = useState([
    { charge_code_id: "", amount: "", reason: "" },
  ]);
  const set = (i: number, patch: Partial<(typeof lines)[number]>) =>
    setLines((all) => all.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  if (!charges) return null;
  return (
    <Modal title="Minta uang jalan" onClose={onClose}>
      <Form
        label="Ajukan"
        onSubmit={async () => {
          const res = await api<{ data: VDetail }>(
            `spm-trips/${tripId}/cash-vouchers`,
            "POST",
            {
              purpose: purpose || null,
              lines: lines.map((l) => ({
                charge_code_id: l.charge_code_id,
                requested_minor: minor(l.amount),
                reason: l.reason || null,
              })),
            },
          );
          onDone(res.data.voucher.id);
        }}
      >
        <Field label="Keperluan">
          <input value={purpose} onChange={(e) => setPurpose(e.target.value)} />
        </Field>
        {lines.map((l, i) => (
          <fieldset key={i} className="form-grid">
            <legend>Rincian {i + 1}</legend>
            <Field label={`Kode biaya ${i + 1}`}>
              <Select
                required
                value={l.charge_code_id}
                onChange={(e) => set(i, { charge_code_id: e.target.value })}
              >
                <option value="">Pilih…</option>
                {charges.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Money
              label={`Jumlah ${i + 1} (Rp)`}
              value={l.amount}
              onChange={(v) => set(i, { amount: v })}
            />
            <Field
              label={`Alasan ${i + 1}`}
              hint="Wajib untuk biaya di luar tarif atau melebihi nilai tarif."
            >
              <input
                value={l.reason}
                onChange={(e) => set(i, { reason: e.target.value })}
              />
            </Field>
          </fieldset>
        ))}
        <button
          type="button"
          onClick={() =>
            setLines((all) => [
              ...all,
              { charge_code_id: "", amount: "", reason: "" },
            ])
          }
        >
          + Rincian
        </button>
      </Form>
    </Modal>
  );
}

/** The cash vouchers of a trip, and the button that asks for one. */
export function TripCashSection({
  tripId,
  status,
}: {
  tripId: string;
  status: string;
}) {
  const session = useSession();
  const navigate = useNavigate();
  const view = can(session, "cash.view");
  const state = useData<Page<Voucher>>(
    view ? `cash/vouchers?trip_id=${tripId}` : "workqueue",
  );
  const [asking, setAsking] = useState(false);
  if (!view && !can(session, "cash.request")) return null;
  const rows = view
    ? ((state.data as Page<Voucher> | undefined)?.data ?? [])
    : [];
  return (
    <section className="card block">
      <div className="page-heading">
        <h2>Uang jalan</h2>
        {can(session, "cash.request") &&
          ["PLANNED", "IN_TRANSIT"].includes(status) && (
            <button onClick={() => setAsking(true)}>+ Minta uang jalan</button>
          )}
      </div>
      {view &&
        (rows.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Voucher</th>
                  <th>Diminta</th>
                  <th>Disetujui</th>
                  <th>Realisasi</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id}>
                    <td>
                      <Link to={`/cash/${v.id}`}>{v.number}</Link>
                    </td>
                    <td>{rupiah(v.requested_minor)}</td>
                    <td>{rupiah(v.approved_minor)}</td>
                    <td>{rupiah(v.actual_minor)}</td>
                    <td>
                      <Badge value={v.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada uang jalan untuk perjalanan ini.</Empty>
        ))}
      {asking && (
        <RequestDialog
          tripId={tripId}
          onClose={() => setAsking(false)}
          onDone={(id) => {
            setAsking(false);
            navigate(`/cash/${id}`);
          }}
        />
      )}
    </section>
  );
}

export function CashPage() {
  const session = useSession();
  const [tab, setTab] = useState<"vouchers" | "kas">("vouchers");
  const [status, setStatus] = useState(
    new URLSearchParams(window.location.search).get("status") ?? "",
  );
  const [q, setQ] = useState("");
  const [draft, setDraft] = useState("");
  const [page, setPage] = useState(1);
  const list = useData<Page<Voucher>>(
    `cash/vouchers?q=${encodeURIComponent(q)}&status=${status}&page=${page}`,
  );
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">UANG JALAN</span>
          <h1 tabIndex={-1}>Uang jalan &amp; kas</h1>
          <p>
            Permintaan, persetujuan, pencairan, dan realisasi uang jalan driver
            dengan saldo kas dan saldo yang dipegang driver.
          </p>
        </div>
      </div>
      <div className="tabs" role="group" aria-label="Uang jalan">
        <button
          aria-pressed={tab === "vouchers"}
          onClick={() => setTab("vouchers")}
        >
          Voucher
        </button>
        <button aria-pressed={tab === "kas"} onClick={() => setTab("kas")}>
          Kas &amp; saldo
        </button>
      </div>
      {tab === "kas" ? (
        <CashboxPanel canManage={can(session, "cash.manage")} />
      ) : (
        <>
          <form
            className="actions"
            role="search"
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              setQ(draft.trim());
              setPage(1);
            }}
          >
            <input
              aria-label="Cari voucher"
              placeholder="Cari nomor voucher…"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
            />
            <select
              aria-label="Status"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {Object.entries(VOUCHER_STATUS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
            <button type="submit">Cari</button>
          </form>
          <ErrorBox error={list.error} />
          <section className="card">
            {list.loading ? (
              <Loading />
            ) : (
              list.data && (
                <>
                  <div className="table-scroll">
                    <table>
                      <thead>
                        <tr>
                          <th>Voucher</th>
                          <th>Perjalanan</th>
                          <th>Pengemudi</th>
                          <th>Diminta</th>
                          <th>Disetujui</th>
                          <th>Realisasi</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {list.data.data.map((v) => (
                          <tr key={v.id}>
                            <td>
                              <Link to={`/cash/${v.id}`}>{v.number}</Link>
                            </td>
                            <td>{v.trip_number}</td>
                            <td>{v.driver_label}</td>
                            <td>{rupiah(v.requested_minor)}</td>
                            <td>{rupiah(v.approved_minor)}</td>
                            <td>{rupiah(v.actual_minor)}</td>
                            <td>
                              <Badge value={v.status} />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {!list.data.data.length && (
                    <Empty>Belum ada voucher yang cocok.</Empty>
                  )}
                  <Pagination page={list.data} onPage={setPage} />
                </>
              )
            )}
          </section>
        </>
      )}
    </>
  );
}

function CashboxPanel({ canManage }: { canManage: boolean }) {
  const state = useData<{ data: Overview }>("cash/overview");
  const [dialog, setDialog] = useState<
    "box" | "limit" | { topup: Cashbox } | { ledger: Cashbox } | null
  >(null);
  const [name, setName] = useState({ code: "", name: "" });
  const [amount, setAmount] = useState("");
  const [memo, setMemo] = useState("");
  const [limit, setLimit] = useState("");
  const d = state.data?.data;
  const ledger = useData<
    Page<{
      id: string;
      entry_type: string;
      amount_minor: number;
      memo: string | null;
      voucher_number: string | null;
      created_at: string;
    }>
  >(
    typeof dialog === "object" && dialog && "ledger" in dialog
      ? `cash/ledger?cashbox_id=${dialog.ledger.id}`
      : "cash/ledger?cashbox_id=00000000-0000-4000-8000-000000000000",
  );
  if (state.loading) return <Loading />;
  if (state.error || !d) return <ErrorBox error={state.error} />;
  return (
    <>
      <section className="card">
        <div className="page-heading">
          <h2>Kas</h2>
          {canManage && (
            <div className="actions">
              <button
                onClick={() => {
                  setLimit(asInput(d.approval_limit_minor));
                  setDialog("limit");
                }}
              >
                Batas persetujuan: {rupiah(d.approval_limit_minor)}
              </button>
              <button
                className="primary"
                onClick={() => {
                  setName({ code: "", name: "" });
                  setDialog("box");
                }}
              >
                + Kas
              </button>
            </div>
          )}
        </div>
        {d.cashboxes.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Kode</th>
                  <th>Nama</th>
                  <th>Saldo</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {d.cashboxes.map((c) => (
                  <tr key={c.id}>
                    <td>{c.code}</td>
                    <td>{c.name}</td>
                    <td>{rupiah(c.balance_minor)}</td>
                    <td>
                      <Badge value={c.active ? "ACTIVE" : "INACTIVE"} />
                    </td>
                    <td>
                      <div className="actions">
                        <button onClick={() => setDialog({ ledger: c })}>
                          Mutasi
                        </button>
                        {canManage && c.active && (
                          <button
                            onClick={() => {
                              setAmount("");
                              setMemo("");
                              setDialog({ topup: c });
                            }}
                          >
                            Isi saldo
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada kas. Buat kas lalu isi saldonya.</Empty>
        )}
      </section>
      <section className="card block">
        <h2>Saldo yang dipegang driver</h2>
        {d.drivers.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Pengemudi</th>
                  <th>Seharusnya dipegang</th>
                </tr>
              </thead>
              <tbody>
                {d.drivers.map((x) => (
                  <tr key={x.driver_id}>
                    <td>{x.driver_name}</td>
                    <td>{rupiah(x.balance_minor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Tidak ada driver yang masih memegang uang jalan.</Empty>
        )}
      </section>
      {dialog === "box" && (
        <Modal title="Kas baru" onClose={() => setDialog(null)}>
          <Form
            label="Simpan"
            onSubmit={async () => {
              await api("cash/cashboxes", "POST", name);
              setDialog(null);
              state.refresh();
            }}
          >
            <Field label="Kode">
              <input
                required
                value={name.code}
                onChange={(e) => setName({ ...name, code: e.target.value })}
              />
            </Field>
            <Field label="Nama">
              <input
                required
                value={name.name}
                onChange={(e) => setName({ ...name, name: e.target.value })}
              />
            </Field>
          </Form>
        </Modal>
      )}
      {dialog === "limit" && (
        <Modal title="Batas persetujuan" onClose={() => setDialog(null)}>
          <Form
            label="Simpan"
            onSubmit={async () => {
              await api("cash/limit", "PUT", {
                approval_limit_minor: minor(limit),
              });
              setDialog(null);
              state.refresh();
            }}
          >
            <p>
              Voucher di atas batas ini hanya dapat disetujui akun dengan izin
              persetujuan nominal besar.
            </p>
            <Money label="Batas (Rp)" value={limit} onChange={setLimit} />
          </Form>
        </Modal>
      )}
      {typeof dialog === "object" && dialog && "topup" in dialog && (
        <Modal
          title={`Isi saldo ${dialog.topup.name}`}
          onClose={() => setDialog(null)}
        >
          <Form
            label="Simpan"
            onSubmit={async () => {
              await api(`cash/cashboxes/${dialog.topup.id}/topups`, "POST", {
                amount_minor: minor(amount),
                memo: memo || null,
              });
              setDialog(null);
              state.refresh();
            }}
          >
            <Money label="Jumlah (Rp)" value={amount} onChange={setAmount} />
            <Field label="Keterangan">
              <input value={memo} onChange={(e) => setMemo(e.target.value)} />
            </Field>
          </Form>
        </Modal>
      )}
      {typeof dialog === "object" && dialog && "ledger" in dialog && (
        <Modal
          title={`Mutasi ${dialog.ledger.name}`}
          onClose={() => setDialog(null)}
        >
          {ledger.loading ? (
            <Loading />
          ) : (
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>Jenis</th>
                    <th>Voucher</th>
                    <th>Jumlah</th>
                  </tr>
                </thead>
                <tbody>
                  {ledger.data?.data.map((e) => (
                    <tr key={e.id}>
                      <td>{dateTime(e.created_at)}</td>
                      <td>{e.entry_type}</td>
                      <td>{e.voucher_number ?? e.memo ?? "—"}</td>
                      <td>{rupiah(e.amount_minor)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Modal>
      )}
    </>
  );
}

type Step = "approve" | "reject" | "cancel" | "disburse" | "settle";

export function CashDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const state = useData<{ data: VDetail }>(`cash/vouchers/${id}`);
  const overview = useData<{ data: Overview }>(
    can(session, "cash.disburse") ? "cash/overview" : "workqueue",
  );
  const [step, setStep] = useState<Step | null>(null);
  const [amounts, setAmounts] = useState<Record<string, string>>({});
  const [receipts, setReceipts] = useState<Record<string, string>>({});
  const [returned, setReturned] = useState("0");
  const [reason, setReason] = useState("");
  const [box, setBox] = useState("");
  const d = state.data?.data;
  if (state.loading) return <Loading />;
  if (state.error || !d)
    return (
      <>
        <ErrorBox error={state.error} />
        <button onClick={state.refresh}>Coba lagi</button>
      </>
    );
  const v = d.voucher;
  const mine = v.requested_by === session.id;
  const boxes =
    (overview.data?.data as Overview | undefined)?.cashboxes?.filter(
      (c) => c.active,
    ) ?? [];
  const begin = (s: Step) => {
    setReason("");
    setBox("");
    setReturned("0");
    setAmounts(
      Object.fromEntries(
        d.lines.map((l) => [
          l.id,
          asInput(s === "approve" ? l.requested_minor : l.approved_minor),
        ]),
      ),
    );
    setReceipts({});
    setStep(s);
  };
  const submit = async () => {
    if (step === "approve")
      await api(`cash/vouchers/${v.id}/approve`, "POST", {
        lines: d.lines.map((l) => ({
          id: l.id,
          approved_minor: minor(amounts[l.id] || "0"),
        })),
      });
    else if (step === "reject" || step === "cancel")
      await api(`cash/vouchers/${v.id}/${step}`, "POST", { reason });
    else if (step === "disburse")
      await api(`cash/vouchers/${v.id}/disburse`, "POST", { cashbox_id: box });
    else if (step === "settle")
      await api(`cash/vouchers/${v.id}/settle`, "POST", {
        lines: d.lines.map((l) => ({
          id: l.id,
          actual_minor: minor(amounts[l.id] || "0"),
          receipt_ref: receipts[l.id] || null,
        })),
        returned_minor: minor(returned || "0"),
      });
    setStep(null);
    state.refresh();
  };
  const titles: Record<Step, string> = {
    approve: "Setujui uang jalan",
    reject: "Tolak uang jalan",
    cancel: "Batalkan uang jalan",
    disburse: "Cairkan uang jalan",
    settle: "Realisasi uang jalan",
  };
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">UANG JALAN</span>
          <h1 tabIndex={-1}>{v.number}</h1>
          <p>
            Perjalanan{" "}
            <Link to={`/planning/${v.trip_id}`}>{v.trip_number}</Link> · SPM{" "}
            {v.spm_number} · {v.driver_label}
          </p>
        </div>
        <Badge value={v.status} />
      </div>
      <div className="split">
        <section className="card">
          <h2>Ringkasan</h2>
          <dl className="details">
            <dt>Keperluan</dt>
            <dd>{v.purpose ?? "—"}</dd>
            <dt>Diminta</dt>
            <dd>{rupiah(v.requested_minor)}</dd>
            <dt>Disetujui</dt>
            <dd>{rupiah(v.approved_minor)}</dd>
            <dt>Dicairkan</dt>
            <dd>
              {rupiah(v.disbursed_minor)}
              {v.cashbox_label && ` dari ${v.cashbox_label}`}
            </dd>
            <dt>Realisasi</dt>
            <dd>{rupiah(v.actual_minor)}</dd>
            <dt>Dikembalikan / diganti</dt>
            <dd>
              {rupiah(v.returned_minor)} / {rupiah(v.reimbursed_minor)}
            </dd>
            {v.status_reason && (
              <>
                <dt>Alasan</dt>
                <dd>{v.status_reason}</dd>
              </>
            )}
          </dl>
        </section>
        <section className="card">
          <h2>Langkah berikutnya</h2>
          <div className="stack">
            {v.status === "REQUESTED" && can(session, "cash.approve") && (
              <>
                <button
                  className="primary"
                  disabled={mine}
                  title={mine ? "Disetujui oleh orang lain." : undefined}
                  onClick={() => begin("approve")}
                >
                  Setujui
                </button>
                <button disabled={mine} onClick={() => begin("reject")}>
                  Tolak
                </button>
              </>
            )}
            {v.status === "APPROVED" && can(session, "cash.disburse") && (
              <button
                className="primary"
                disabled={mine}
                title={mine ? "Dicairkan oleh orang lain." : undefined}
                onClick={() => begin("disburse")}
              >
                Cairkan
              </button>
            )}
            {v.status === "DISBURSED" && can(session, "cash.settle") && (
              <button className="primary" onClick={() => begin("settle")}>
                Realisasi
              </button>
            )}
            {["REQUESTED", "APPROVED"].includes(v.status) &&
              can(session, "cash.request") && (
                <button
                  className="danger-button"
                  onClick={() => begin("cancel")}
                >
                  Batalkan
                </button>
              )}
          </div>
        </section>
      </div>
      <section className="card block">
        <h2>Rincian</h2>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Kode biaya</th>
                <th>Jenis</th>
                <th>Nilai tarif</th>
                <th>Diminta</th>
                <th>Disetujui</th>
                <th>Realisasi</th>
                <th>Nota</th>
                <th>Alasan</th>
              </tr>
            </thead>
            <tbody>
              {d.lines.map((l) => (
                <tr key={l.id}>
                  <td>{l.charge_code_label}</td>
                  <td>{l.kind === "PLAN" ? "Plan" : "Unplan"}</td>
                  <td>{rupiah(l.planned_minor)}</td>
                  <td>{rupiah(l.requested_minor)}</td>
                  <td>{rupiah(l.approved_minor)}</td>
                  <td>{rupiah(l.actual_minor)}</td>
                  <td>{l.receipt_ref ?? "—"}</td>
                  <td>{l.reason ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <section className="card block">
        <h2>Mutasi kas</h2>
        {d.ledger.length ? (
          <ul className="timeline">
            {d.ledger.map((e) => (
              <li key={e.id}>
                <strong>{e.entry_type}</strong> · {dateTime(e.created_at)} ·{" "}
                {rupiah(e.amount_minor)}
                {e.memo && <div className="muted">{e.memo}</div>}
              </li>
            ))}
          </ul>
        ) : (
          <Empty>Belum ada mutasi.</Empty>
        )}
      </section>
      {step && (
        <Modal title={titles[step]} onClose={() => setStep(null)}>
          <Form label="Lanjutkan" onSubmit={submit}>
            {(step === "approve" || step === "settle") &&
              d.lines.map((l) => (
                <fieldset key={l.id} className="form-grid">
                  <legend>{l.charge_code_label}</legend>
                  <Money
                    label={`${step === "approve" ? "Disetujui" : "Realisasi"} ${l.charge_code_label} (Rp)`}
                    value={amounts[l.id] ?? ""}
                    onChange={(x) => setAmounts({ ...amounts, [l.id]: x })}
                  />
                  {step === "settle" && (
                    <Field label={`Nota ${l.charge_code_label}`}>
                      <input
                        value={receipts[l.id] ?? ""}
                        onChange={(e) =>
                          setReceipts({ ...receipts, [l.id]: e.target.value })
                        }
                      />
                    </Field>
                  )}
                </fieldset>
              ))}
            {step === "settle" && (
              <Money
                label="Uang dikembalikan ke kas (Rp)"
                value={returned}
                onChange={setReturned}
              />
            )}
            {(step === "reject" || step === "cancel") && (
              <Field label="Alasan (minimal 5 karakter)">
                <textarea
                  required
                  minLength={5}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                />
              </Field>
            )}
            {step === "disburse" && (
              <Field label="Kas sumber">
                <Select
                  required
                  value={box}
                  onChange={(e) => setBox(e.target.value)}
                >
                  <option value="">Pilih…</option>
                  {boxes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.code} · {c.name} ({rupiah(c.balance_minor)})
                    </option>
                  ))}
                </Select>
              </Field>
            )}
          </Form>
        </Modal>
      )}
    </>
  );
}
