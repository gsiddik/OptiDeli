import { useState } from "react";
import type { FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import {
  Badge,
  Confirm,
  Empty,
  ErrorBox,
  Field,
  Loading,
  Pagination,
} from "./components";
import { dateTime, money } from "./commercial";
import { can } from "./opsTypes";
import { EditorDialog } from "./RecordEditor";
import type { Page } from "./types";
import { TripCashSection } from "./Cash";

export const TRIP_STATUS: Record<string, string> = {
  PENDING_APPROVAL: "Menunggu persetujuan margin",
  PLANNED: "Direncanakan",
  IN_TRANSIT: "Di perjalanan",
  COMPLETED: "Selesai",
  CANCELLED: "Dibatalkan",
};

type TripRow = {
  id: string;
  number: string;
  transport_order_id: string;
  spm_number: string | null;
  status: string;
  vehicle_label: string | null;
  driver_label: string | null;
  starts_at: string;
  ends_at: string;
  awb_count: number;
};
type Stop = {
  id: string;
  sequence: number;
  type: "PICKUP" | "DROP";
  location_label: string | null;
  planned_from: string | null;
  planned_to: string | null;
  arrived_at: string | null;
  departed_at: string | null;
  time_source: string | null;
  awb_numbers: string[];
};
type TripDetail = {
  trip: TripRow & {
    vendor_label: string | null;
    sell_minor: number | null;
    cost_minor: number | null;
    required_level: number;
    reason_code: string | null;
    cancellation_reason: string | null;
    notes: string | null;
    created_by: number;
  };
  spm: { id: string; number: string; status: string } | null;
  awbs: {
    id: string;
    number: string;
    status: string;
    destination_text: string;
    consignee_name: string | null;
    weight_kg: number;
  }[];
  stops: Stop[];
  warnings?: string[];
};
type Queue = Record<string, number>;

const QUEUE_LABEL: Record<string, [string, string]> = {
  spm_draft: ["SPM draf", "/spm?status=DRAFT"],
  spm_reopened: ["SPM dibuka kembali", "/spm?status=REOPENED"],
  awb_pending: ["AWB menunggu trip", "/spm"],
  trips_to_approve: [
    "Trip menunggu persetujuan",
    "/planning?status=PENDING_APPROVAL",
  ],
  vouchers_to_approve: ["Uang jalan menunggu ACC", "/cash?status=REQUESTED"],
  vouchers_to_disburse: ["Uang jalan siap dicairkan", "/cash?status=APPROVED"],
  vouchers_to_settle: [
    "Uang jalan menunggu realisasi",
    "/cash?status=DISBURSED",
  ],
  tariffs_to_approve: ["Tarif menunggu persetujuan", "/tariffs?status=DRAFT"],
};

/** What is waiting for the user (counts come from the server by permission). */
export function WorkQueue() {
  const state = useData<{ data: Queue }>("workqueue");
  const items = Object.entries(state.data?.data ?? {}).filter(([, n]) => n > 0);
  if (!items.length) return null;
  return (
    <section className="card" aria-label="Antrean kerja">
      <strong>Antrean kerja</strong>
      <div className="actions">
        {items.map(([key, n]) => (
          <Link className="button" key={key} to={QUEUE_LABEL[key]?.[1] ?? "/"}>
            {QUEUE_LABEL[key]?.[0] ?? key}: <strong>{n}</strong>
          </Link>
        ))}
      </div>
    </section>
  );
}

export function TripListPage() {
  const [draft, setDraft] = useState("");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState(
    new URLSearchParams(window.location.search).get("status") ?? "",
  );
  const [page, setPage] = useState(1);
  const state = useData<Page<TripRow>>(
    `spm-trips?q=${encodeURIComponent(q)}&status=${status}&page=${page}`,
  );
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PERENCANAAN</span>
          <h1 tabIndex={-1}>Perjalanan SPM</h1>
          <p>
            Satu SPM dapat memiliki beberapa perjalanan, masing-masing membawa
            AWB-nya sendiri. Rencanakan perjalanan dari halaman SPM.
          </p>
        </div>
      </div>
      <WorkQueue />
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
          aria-label="Cari perjalanan"
          placeholder="Cari nomor perjalanan…"
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
          {Object.entries(TRIP_STATUS).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </select>
        <button type="submit">Cari</button>
      </form>
      <ErrorBox error={state.error} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Perjalanan</th>
                      <th>SPM</th>
                      <th>Kendaraan</th>
                      <th>Pengemudi</th>
                      <th>AWB</th>
                      <th>Jadwal</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((t) => (
                      <tr key={t.id}>
                        <td>
                          <Link to={`/planning/${t.id}`}>{t.number}</Link>
                        </td>
                        <td>
                          <Link to={`/spm/${t.transport_order_id}`}>
                            {t.spm_number}
                          </Link>
                        </td>
                        <td>{t.vehicle_label}</td>
                        <td>{t.driver_label}</td>
                        <td>{t.awb_count}</td>
                        <td>{dateTime(t.starts_at)}</td>
                        <td>
                          <Badge value={t.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>Belum ada perjalanan yang cocok.</Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
    </>
  );
}

const localNow = () => {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};

export function TripDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const navigate = useNavigate();
  const state = useData<{ data: TripDetail }>(`spm-trips/${id}`);
  const [dialog, setDialog] = useState<
    { stop: Stop; event: "arrive" | "depart" } | "cancel" | null
  >(null);
  const [notice, setNotice] = useState<string[]>([]);
  const [failure, setFailure] = useState<string>();
  const d = state.data?.data;

  async function act(path: string, body: unknown = {}) {
    setFailure(undefined);
    try {
      const res = await api<{ data: TripDetail }>(path, "POST", body);
      setNotice(res.data.warnings ?? []);
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }
  async function move(stop: Stop, direction: -1 | 1) {
    if (!d) return;
    const ids = d.stops.map((s) => s.id);
    const i = ids.indexOf(stop.id);
    const j = i + direction;
    if (j < 1 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    setFailure(undefined);
    try {
      await api(`spm-trips/${id}/stops`, "PUT", { stop_ids: ids });
      state.refresh();
    } catch (e) {
      setFailure(
        e instanceof Error ? e.message : "Tidak dapat mengubah urutan.",
      );
    }
  }

  if (state.loading) return <Loading />;
  if (state.error || !d)
    return (
      <>
        <ErrorBox error={state.error} />
        <button onClick={state.refresh}>Coba lagi</button>
      </>
    );
  const t = d.trip;
  const manage = can(session, "spm.manage");
  const approver = [1, 2, 3].some((l) =>
    can(session, `dispatch.approve.level${l}`),
  );
  const editable = ["PENDING_APPROVAL", "PLANNED"].includes(t.status);
  const next = d.stops.find((s) => !s.departed_at);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PERJALANAN SPM</span>
          <h1 tabIndex={-1}>{t.number}</h1>
          <p>
            SPM <Link to={`/spm/${t.transport_order_id}`}>{d.spm?.number}</Link>{" "}
            · {t.vehicle_label} · {t.driver_label}
          </p>
        </div>
        <Badge value={t.status} />
      </div>
      <ErrorBox error={failure} />
      {notice.map((n) => (
        <div className="alert" role="status" key={n}>
          {n}
        </div>
      ))}
      {t.status === "PENDING_APPROVAL" && (
        <div className="alert" role="status">
          Margin di bawah threshold (level persetujuan {t.required_level},
          reason code {t.reason_code}). Perlu disetujui oleh orang lain selain
          perencana.
        </div>
      )}
      <div className="split">
        <section className="card">
          <h2>Ringkasan</h2>
          <dl className="details">
            <dt>Jadwal</dt>
            <dd>
              {dateTime(t.starts_at)} – {dateTime(t.ends_at)}
            </dd>
            <dt>Vendor</dt>
            <dd>{t.vendor_label ?? "Armada sendiri"}</dd>
            {t.sell_minor !== null && (
              <>
                <dt>Bagian harga jual</dt>
                <dd>{money(t.sell_minor)}</dd>
                <dt>Bagian biaya beli</dt>
                <dd>{money(t.cost_minor ?? 0)}</dd>
              </>
            )}
            {t.cancellation_reason && (
              <>
                <dt>Alasan batal</dt>
                <dd>{t.cancellation_reason}</dd>
              </>
            )}
          </dl>
        </section>
        <section className="card">
          <h2>Langkah berikutnya</h2>
          <div className="stack">
            {t.status === "PENDING_APPROVAL" && approver && (
              <button
                className="primary"
                disabled={t.created_by === session.id}
                title={
                  t.created_by === session.id
                    ? "Perjalanan harus disetujui oleh orang lain."
                    : undefined
                }
                onClick={() => void act(`spm-trips/${t.id}/approve`)}
              >
                Setujui margin
              </button>
            )}
            {t.status === "PLANNED" && manage && (
              <button
                className="primary"
                onClick={() => void act(`spm-trips/${t.id}/start`)}
              >
                Berangkat
              </button>
            )}
            {t.status === "IN_TRANSIT" && manage && !next && (
              <button
                className="primary"
                onClick={() => void act(`spm-trips/${t.id}/complete`)}
              >
                Selesaikan perjalanan
              </button>
            )}
            {editable && manage && (
              <button
                className="danger-button"
                onClick={() => setDialog("cancel")}
              >
                Batalkan perjalanan
              </button>
            )}
            {!manage && !approver && (
              <p className="muted">
                Tidak ada langkah yang tersedia untuk Anda.
              </p>
            )}
          </div>
        </section>
      </div>
      <section className="card block">
        <h2>Stop</h2>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Jenis</th>
                <th>Lokasi</th>
                <th>AWB</th>
                <th>Tiba</th>
                <th>Berangkat</th>
                {manage && <th>Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {d.stops.map((s) => (
                <tr key={s.id}>
                  <td>{s.sequence}</td>
                  <td>{s.type === "PICKUP" ? "Muat" : "Bongkar"}</td>
                  <td>{s.location_label}</td>
                  <td>{s.awb_numbers.join(", ") || "—"}</td>
                  <td>{s.arrived_at ? dateTime(s.arrived_at) : "—"}</td>
                  <td>{s.departed_at ? dateTime(s.departed_at) : "—"}</td>
                  {manage && (
                    <td>
                      <div className="actions">
                        {t.status === "IN_TRANSIT" &&
                          next?.id === s.id &&
                          !s.arrived_at && (
                            <button
                              onClick={() =>
                                setDialog({ stop: s, event: "arrive" })
                              }
                            >
                              Catat tiba
                            </button>
                          )}
                        {t.status === "IN_TRANSIT" &&
                          s.arrived_at &&
                          !s.departed_at && (
                            <button
                              onClick={() =>
                                setDialog({ stop: s, event: "depart" })
                              }
                            >
                              Catat berangkat
                            </button>
                          )}
                        {editable && s.type === "DROP" && (
                          <>
                            <button
                              aria-label={`Naikkan stop ${s.sequence}`}
                              onClick={() => void move(s, -1)}
                            >
                              ↑
                            </button>
                            <button
                              aria-label={`Turunkan stop ${s.sequence}`}
                              onClick={() => void move(s, 1)}
                            >
                              ↓
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <small className="muted">
          Jam tiba/berangkat dicatat manual (sumber: manual). Menunggu lebih
          lama dari free time pada tarif dihitung sebagai biaya detention, dan
          menginap melewati tengah malam sebagai overnight.
        </small>
      </section>
      <section className="card block">
        <h2>AWB di perjalanan ini</h2>
        {d.awbs.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Nomor AWB</th>
                  <th>Tujuan</th>
                  <th>Penerima</th>
                  <th>Berat (kg)</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {d.awbs.map((a) => (
                  <tr key={a.id}>
                    <td>{a.number}</td>
                    <td>{a.destination_text}</td>
                    <td>{a.consignee_name ?? "—"}</td>
                    <td>
                      {a.weight_kg.toLocaleString("id-ID", {
                        maximumFractionDigits: 3,
                      })}
                    </td>
                    <td>
                      <Badge value={a.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Tidak ada AWB.</Empty>
        )}
      </section>
      <TripCashSection tripId={t.id} status={t.status} />
      {typeof dialog === "object" && dialog && (
        <EditorDialog
          title={`${dialog.event === "arrive" ? "Tiba di" : "Berangkat dari"} ${dialog.stop.location_label}`}
          fields={[
            {
              name: "at",
              label: "Waktu",
              kind: "datetime",
              value: localNow(),
              hint: "Boleh diubah bila dicatat terlambat; tidak boleh di masa depan.",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body) => {
            const res = await api<{ data: TripDetail }>(
              `spm-trips/${t.id}/stops/${dialog.stop.id}/${dialog.event}`,
              "POST",
              body,
            );
            setNotice(res.data.warnings ?? []);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog === "cancel" && (
        <Confirm
          title={`Batalkan ${t.number}?`}
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            const reason =
              (document.getElementById("cancel-reason") as HTMLTextAreaElement)
                ?.value ?? "";
            await api(`spm-trips/${t.id}/cancel`, "POST", { reason });
            state.refresh();
          }}
        >
          <p>
            AWB kembali menunggu perjalanan; kendaraan dan pengemudi dibebaskan.
          </p>
          <Field label="Alasan (minimal 5 karakter)">
            <textarea id="cancel-reason" required minLength={5} />
          </Field>
        </Confirm>
      )}
      <p>
        <button onClick={() => navigate("/planning")}>
          Kembali ke daftar perjalanan
        </button>
      </p>
    </>
  );
}
