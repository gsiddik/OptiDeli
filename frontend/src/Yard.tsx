import { useState } from "react";
import { useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { Action } from "./RecordEditor";
import { dateTime } from "./commercial";
import type { Page } from "./types";
import type { Appointment, Dock, Trip } from "./opsTypes";
import { canOperate } from "./opsTypes";
export function YardPage() {
  const state = useData<{
    data: { docks: Dock[]; appointments: Appointment[] };
  }>("yard");
  const [tripQuery, setTripQuery] = useState("");
  const [tripDraft, setTripDraft] = useState("");
  const trips = useData<Page<Trip>>(`trips?q=${encodeURIComponent(tripQuery)}`);
  const ops = canOperate(useSession());
  const actions: Record<string, { label: string; action: string }> = {
    BOOKED: { label: "Catat gate-in", action: "gate_in" },
    GATE_IN: { label: "Masuk dock", action: "dock_in" },
    AT_DOCK: { label: "Selesai bongkar/muat", action: "dock_out" },
    COMPLETED: { label: "Catat gate-out", action: "gate_out" },
  };
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">DOCK & YARD</span>
          <h1 tabIndex={-1}>Reservasi & pergerakan yard</h1>
          <p>
            Reservasi tanpa benturan; jejak gate-in sampai gate-out dicatat
            server.
          </p>
        </div>
        {ops && (
          <div className="actions">
            <Action
              label="Daftarkan dock"
              path="yard/docks"
              fields={[
                { name: "hub", label: "Nama hub" },
                { name: "name", label: "Nama pintu dock" },
              ]}
              onDone={state.refresh}
            />
            <Action
              label="Reservasi slot"
              path="yard/appointments"
              fields={[
                {
                  name: "dock_id",
                  label: "Dock",
                  kind: "select",
                  options: state.data?.data.docks.map((d) => ({
                    value: d.id,
                    label: `${d.hub} · ${d.name}`,
                  })),
                },
                {
                  name: "trip_id",
                  label: "Trip aktif",
                  kind: "select",
                  options: trips.data?.data
                    .filter((t) => t.active)
                    .map((t) => ({ value: t.id, label: t.id.slice(0, 8) })),
                },
                { name: "starts_at", label: "Mulai slot", kind: "datetime" },
                { name: "ends_at", label: "Akhir slot", kind: "datetime" },
              ]}
              onDone={state.refresh}
              primary
            />
          </div>
        )}
      </div>
      <form
        className="filters"
        onSubmit={(e) => {
          e.preventDefault();
          setTripQuery(tripDraft);
        }}
      >
        <label className="field">
          Cari trip untuk pilihan formulir (ID / kontrak)
          <input
            value={tripDraft}
            maxLength={100}
            onChange={(e) => setTripDraft(e.target.value)}
            placeholder="Ketik ID atau referensi kontrak"
          />
          <small>
            Formulir menampilkan 25 hasil terbaru; cari untuk memilih trip
            lainnya.
          </small>
        </label>
        <button type="submit">Cari trip</button>
      </form>
      <ErrorBox error={state.error ?? trips.error} />
      <div className="alert info">
        Slot berurutan boleh bersinggungan tepat di waktu batas. Dua armada
        tidak boleh menempati dock yang sama secara bersamaan, meskipun jadwal
        rencananya telah lewat.
      </div>
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
                      <th>Dock / trip</th>
                      <th>Jadwal</th>
                      <th>Status</th>
                      <th>Aktual</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.appointments.map((a) => {
                      const dock = state.data!.data.docks.find(
                        (d) => d.id === a.dock_id,
                      );
                      return (
                        <tr key={a.id}>
                          <td>
                            {dock?.hub} · {dock?.name}
                            <small>{a.trip_id.slice(0, 8)}</small>
                          </td>
                          <td>
                            {dateTime(a.starts_at)}
                            <small>s.d. {dateTime(a.ends_at)}</small>
                          </td>
                          <td>
                            <Badge value={a.status} />
                          </td>
                          <td>
                            <small>
                              Gate-in:{" "}
                              {a.gate_in_at ? dateTime(a.gate_in_at) : "—"}
                            </small>
                            <small>
                              Dock-in:{" "}
                              {a.dock_in_at ? dateTime(a.dock_in_at) : "—"}
                            </small>
                            <small>
                              Dock-out:{" "}
                              {a.dock_out_at ? dateTime(a.dock_out_at) : "—"}
                            </small>
                            <small>
                              Gate-out:{" "}
                              {a.gate_out_at ? dateTime(a.gate_out_at) : "—"}
                            </small>
                          </td>
                          <td>
                            {ops && actions[a.status] && (
                              <div className="actions">
                                <Action
                                  label={actions[a.status].label}
                                  path={`yard/appointments/${a.id}/transition`}
                                  fixed={{ action: actions[a.status].action }}
                                  onDone={state.refresh}
                                />
                                {a.status === "BOOKED" && (
                                  <Action
                                    label="Batalkan slot"
                                    path={`yard/appointments/${a.id}/transition`}
                                    fixed={{ action: "cancel" }}
                                    onDone={state.refresh}
                                  />
                                )}
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              {!state.data.data.appointments.length && (
                <Empty>Belum ada reservasi yard.</Empty>
              )}
              <small className="muted">
                Menampilkan maksimal 100 reservasi terbaru. Integrasi sensor
                gate/WMS belum diaktifkan.
              </small>
            </>
          )
        )}
      </section>
    </>
  );
}
