import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { dateTime } from "./commercial";
import { can } from "./opsTypes";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import { WorkQueue } from "./Planning";

type Item = {
  kind: "TRIP" | "DISPATCH" | "BOOKING";
  id: string;
  vehicle_id: string;
  label: string;
  detail: string;
  status: string;
  starts_at: string;
  ends_at: string;
};
type Schedule = {
  from: string;
  to: string;
  vehicles: { id: string; plate: string; type: string; status: string }[];
  items: Item[];
};
type Opt = { value: string; label: string };

const DAY = 24 * 3600 * 1000;
const startOfWeek = (d: Date) => {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
  return x;
};
const KIND: Record<Item["kind"], string> = {
  TRIP: "Perjalanan SPM",
  DISPATCH: "Dispatch order",
  BOOKING: "Booking",
};

/** A week of what every vehicle is doing: SPM trips, order-based dispatches and bookings; the place to book a vehicle ahead. */
export function SchedulePage() {
  const session = useSession();
  const navigate = useNavigate();
  const [week, setWeek] = useState(() => startOfWeek(new Date()));
  const from = week;
  const to = new Date(week.getTime() + 7 * DAY);
  const state = useData<{ data: Schedule }>(
    `schedule?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`,
  );
  const [dialog, setDialog] = useState<"book" | { cancel: Item } | null>(null);
  const [choices, setChoices] = useState<{
    vehicles: Opt[];
    drivers: Opt[];
  }>();
  const canBook = can(session, "spm.manage");
  useEffect(() => {
    if (!canBook) return;
    let live = true;
    api<{
      data: {
        vehicles: { id: string; plate: string; type: string }[];
        drivers: { id: string; name: string }[];
      };
    }>("fleet")
      .then(
        (f) =>
          live &&
          setChoices({
            vehicles: f.data.vehicles.map((v) => ({
              value: v.id,
              label: `${v.plate} · ${v.type}`,
            })),
            drivers: f.data.drivers.map((d) => ({
              value: d.id,
              label: d.name,
            })),
          }),
      )
      .catch(() => live && setChoices({ vehicles: [], drivers: [] }));
    return () => {
      live = false;
    };
  }, [canBook]);
  const d = state.data?.data;
  const days = Array.from(
    { length: 7 },
    (_, i) => new Date(week.getTime() + i * DAY),
  );
  const span = 7 * DAY;
  const bar = (i: Item) => {
    const a = Math.max(new Date(i.starts_at).getTime(), from.getTime());
    const b = Math.min(new Date(i.ends_at).getTime(), to.getTime());
    return {
      left: `${((a - from.getTime()) / span) * 100}%`,
      width: `${Math.max(((b - a) / span) * 100, 1.5)}%`,
    };
  };
  const fields: EditorField[] = [
    {
      name: "vehicle_id",
      label: "Kendaraan",
      kind: "select",
      options: choices?.vehicles ?? [],
      value: "",
    },
    {
      name: "driver_id",
      label: "Pengemudi (opsional)",
      kind: "select",
      options: choices?.drivers ?? [],
      value: "",
      required: false,
    },
    { name: "starts_at", label: "Mulai", kind: "datetime", value: "" },
    { name: "ends_at", label: "Selesai", kind: "datetime", value: "" },
    {
      name: "purpose",
      label: "Keperluan",
      value: "",
      required: false,
    },
  ];
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PERENCANAAN</span>
          <h1 tabIndex={-1}>Jadwal kendaraan</h1>
          <p>
            Perjalanan, dispatch order, dan booking per kendaraan. Booking
            menahan kendaraan dan dipakai habis oleh perjalanan yang
            direncanakan untuknya.
          </p>
        </div>
        {canBook && (
          <button className="primary" onClick={() => setDialog("book")}>
            + Booking kendaraan
          </button>
        )}
      </div>
      <WorkQueue />
      <div className="actions">
        <button onClick={() => setWeek(new Date(week.getTime() - 7 * DAY))}>
          ← Minggu lalu
        </button>
        <button onClick={() => setWeek(startOfWeek(new Date()))}>
          Minggu ini
        </button>
        <button onClick={() => setWeek(new Date(week.getTime() + 7 * DAY))}>
          Minggu depan →
        </button>
        <strong>
          {days[0].toLocaleDateString("id-ID", {
            day: "numeric",
            month: "short",
          })}{" "}
          –{" "}
          {days[6].toLocaleDateString("id-ID", {
            day: "numeric",
            month: "short",
            year: "numeric",
          })}
        </strong>
      </div>
      <ErrorBox error={state.error} />
      {state.loading && <Loading />}
      {d &&
        (d.vehicles.length ? (
          <section className="card gantt" aria-label="Jadwal kendaraan">
            <div className="gantt-head">
              <div className="gantt-name" />
              <div className="gantt-days">
                {days.map((x) => (
                  <div key={x.toISOString()}>
                    {x.toLocaleDateString("id-ID", {
                      weekday: "short",
                      day: "numeric",
                    })}
                  </div>
                ))}
              </div>
            </div>
            {d.vehicles.map((v) => (
              <div className="gantt-row" key={v.id}>
                <div className="gantt-name">
                  <strong>{v.plate}</strong>
                  <small className="muted"> {v.type}</small>
                </div>
                <div className="gantt-track">
                  {d.items
                    .filter((i) => i.vehicle_id === v.id)
                    .map((i) => (
                      <button
                        key={`${i.kind}-${i.id}`}
                        className={`gantt-bar ${i.kind.toLowerCase()}`}
                        style={bar(i)}
                        title={`${KIND[i.kind]}: ${i.label} ${i.detail} (${dateTime(i.starts_at)} – ${dateTime(i.ends_at)})`}
                        onClick={() =>
                          i.kind === "TRIP"
                            ? navigate(`/planning/${i.id}`)
                            : i.kind === "BOOKING" && canBook
                              ? setDialog({ cancel: i })
                              : undefined
                        }
                      >
                        {i.label}
                      </button>
                    ))}
                </div>
              </div>
            ))}
            <small className="muted">
              Biru: perjalanan SPM · Abu: dispatch order · Kuning: booking.
            </small>
          </section>
        ) : (
          <Empty>Belum ada kendaraan terdaftar.</Empty>
        ))}
      {dialog === "book" && choices && (
        <EditorDialog
          title="Booking kendaraan"
          fields={fields}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord("reservations", body, key);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {typeof dialog === "object" && dialog && "cancel" in dialog && (
        <EditorDialog
          title={`Batalkan booking ${dialog.cancel.detail || ""}`.trim()}
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
            await submitRecord(
              `reservations/${dialog.cancel.id}/cancel`,
              body,
              key,
            );
            setDialog(null);
            state.refresh();
          }}
        />
      )}
    </>
  );
}

type Board = {
  counts: Record<string, number>;
  unplanned_awbs: number;
  trips: {
    id: string;
    number: string;
    spm_id: string;
    spm_number: string | null;
    status: string;
    vehicle: string | null;
    driver: string | null;
    starts_at: string;
    ends_at: string;
    stops_done: number;
    stops_total: number;
    current_stop: {
      type: string;
      location: string | null;
      arrived_at: string | null;
      source: string | null;
    } | null;
    awb_delivered: number;
    awb_total: number;
    last_signal_at: string | null;
    flags: string[];
  }[];
};
const FLAG: Record<string, string> = {
  HELD: "Menunggu persetujuan",
  NOT_STARTED: "Belum berangkat",
  LATE: "Terlambat",
  WAITING: "Menunggu lama di stop",
};

/** Every trip that is not finished, how far it is and what is wrong with it; refreshes by itself. */
export function MonitoringPage() {
  const state = useData<{ data: Board }>("monitoring");
  const [only, setOnly] = useState("");
  useEffect(() => {
    const t = setInterval(state.refresh, 30000);
    return () => clearInterval(t);
  }, [state.refresh]);
  const d = state.data?.data;
  const rows = (d?.trips ?? []).filter(
    (t) => !only || t.flags.includes(only) || t.status === only,
  );
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">MONITORING</span>
          <h1 tabIndex={-1}>Papan monitoring</h1>
          <p>
            Perjalanan yang sedang berjalan atau menunggu, diperbarui tiap 30
            detik.
          </p>
        </div>
        <button onClick={state.refresh}>Segarkan</button>
      </div>
      <WorkQueue />
      <ErrorBox error={state.error} />
      {state.loading && !d && <Loading />}
      {d && (
        <>
          <div className="stats" role="group" aria-label="Ringkasan">
            {(
              [
                ["IN_TRANSIT", "Di perjalanan", d.counts.IN_TRANSIT],
                ["PLANNED", "Direncanakan", d.counts.PLANNED],
                [
                  "PENDING_APPROVAL",
                  "Menunggu persetujuan",
                  d.counts.PENDING_APPROVAL,
                ],
                ["LATE", "Perlu perhatian", d.counts.LATE],
              ] as const
            ).map(([key, label, n]) => (
              <button
                key={key}
                className="card stat"
                aria-pressed={only === key}
                onClick={() => setOnly(only === key ? "" : key)}
              >
                <strong>{n}</strong>
                <span>{label}</span>
              </button>
            ))}
            <Link className="card stat" to="/spm">
              <strong>{d.unplanned_awbs}</strong>
              <span>AWB belum punya trip</span>
            </Link>
          </div>
          <section className="card">
            {rows.length ? (
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Perjalanan</th>
                      <th>Kendaraan / driver</th>
                      <th>Posisi</th>
                      <th>Progres</th>
                      <th>Sinyal terakhir</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((t) => (
                      <tr key={t.id}>
                        <td>
                          <Link to={`/planning/${t.id}`}>{t.number}</Link>
                          <br />
                          <small className="muted">{t.spm_number}</small>
                        </td>
                        <td>
                          {t.vehicle}
                          <br />
                          <small className="muted">{t.driver}</small>
                        </td>
                        <td>
                          {t.current_stop
                            ? `${t.current_stop.type === "PICKUP" ? "Muat" : "Bongkar"} · ${t.current_stop.location}${t.current_stop.arrived_at ? " (di lokasi)" : ""}`
                            : "—"}
                        </td>
                        <td>
                          Stop {t.stops_done}/{t.stops_total} · AWB{" "}
                          {t.awb_delivered}/{t.awb_total}
                        </td>
                        <td>
                          {t.last_signal_at ? dateTime(t.last_signal_at) : "—"}
                        </td>
                        <td>
                          <Badge value={t.status} />{" "}
                          {t.flags.map((f) => (
                            <span className="badge failed" key={f}>
                              {FLAG[f] ?? f}
                            </span>
                          ))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <Empty>Tidak ada perjalanan yang cocok.</Empty>
            )}
          </section>
        </>
      )}
    </>
  );
}
