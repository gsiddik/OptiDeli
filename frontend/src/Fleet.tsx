import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { dateTime } from "./commercial";
import type { Fleet, Vehicle } from "./types";
const localFuture = () => {
  const d = new Date(Date.now() + 86400000);
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};
export function FleetPage() {
  const session = useSession();
  const state = useData<{ data: Fleet }>("fleet");
  const [tab, setTab] = useState<"vehicles" | "drivers">("vehicles");
  const [form, setForm] = useState(false);
  const [notice, setNotice] = useState("");
  const [selected, setSelected] = useState<Vehicle>();
  const [status, setStatus] = useState("AVAILABLE");
  const [note, setNote] = useState("");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">SUMBER DAYA</span>
          <h1 tabIndex={-1}>Armada & pengemudi</h1>
          <p>
            Register lokal untuk kesiapan operasional. Periksa sebelum setiap
            dispatch.
          </p>
        </div>
        {session.role !== "viewer" && (
          <button className="primary" onClick={() => setForm((v) => !v)}>
            {form
              ? "Tutup form"
              : `+ ${tab === "vehicles" ? "Daftarkan kendaraan" : "Daftarkan pengemudi"}`}
          </button>
        )}
      </div>
      <div className="alert info">
        Sumber kesiapan: register lokal. Pemeriksaan harus diperbarui maksimal
        15 menit sebelum konfirmasi dispatch; status servis yang tidak tersedia
        akan mengunci penugasan.
      </div>
      {notice && (
        <div className="alert success" role="status">
          {notice}
        </div>
      )}
      <div className="tabs" role="group" aria-label="Jenis sumber daya">
        <button
          aria-pressed={tab === "vehicles"}
          onClick={() => {
            setTab("vehicles");
            setForm(false);
          }}
        >
          Kendaraan
        </button>
        <button
          aria-pressed={tab === "drivers"}
          onClick={() => {
            setTab("drivers");
            setForm(false);
          }}
        >
          Pengemudi
        </button>
      </div>
      {form && (
        <section className="card block">
          {tab === "vehicles" ? (
            <VehicleForm
              onDone={() => {
                state.refresh();
                setForm(false);
                setNotice("Kendaraan berhasil didaftarkan.");
              }}
            />
          ) : (
            <DriverForm
              onDone={() => {
                state.refresh();
                setForm(false);
                setNotice("Pengemudi berhasil didaftarkan.");
              }}
            />
          )}
        </section>
      )}
      {selected && (
        <section className="card block">
          <h2>Pemeriksaan kesiapan · {selected.plate}</h2>
          <Form
            label="Catat pemeriksaan"
            onSubmit={async () => {
              await api(`fleet/vehicles/${selected.id}/readiness`, "POST", {
                status,
                note,
              });
              setSelected(undefined);
              state.refresh();
              setNotice("Pemeriksaan kesiapan tercatat pada jejak audit.");
            }}
          >
            <div className="form-grid">
              <Field label="Status operasional">
                <select
                  value={status}
                  onChange={(e) => setStatus(e.target.value)}
                >
                  <option value="AVAILABLE">Tersedia</option>
                  <option value="MAINTENANCE">Dalam perawatan</option>
                  <option value="UNAVAILABLE">Tidak tersedia</option>
                </select>
              </Field>
              <Field label="Catatan hasil pemeriksaan">
                <textarea
                  minLength={10}
                  maxLength={1000}
                  required
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                />
              </Field>
            </div>
            <button type="button" onClick={() => setSelected(undefined)}>
              Batal
            </button>
          </Form>
        </section>
      )}
      <section className="card">
        <ErrorBox error={state.error} />
        {state.error && <button onClick={state.refresh}>Coba lagi</button>}
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <div className="table-scroll">
              {tab === "vehicles" ? (
                <>
                  <table>
                    <thead>
                      <tr>
                        <th>Kendaraan</th>
                        <th>Kapasitas</th>
                        <th>Dokumen sampai</th>
                        <th>Kesiapan</th>
                        <th>Alokasi / aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {state.data.data.vehicles.map((v) => (
                        <tr key={v.id}>
                          <td>
                            <strong>{v.plate}</strong>
                            <small>{v.type}</small>
                          </td>
                          <td>
                            {(v.capacity_g / 1000).toLocaleString("id-ID")} kg
                            <small>
                              {v.length_mm} × {v.width_mm} × {v.height_mm} mm
                            </small>
                          </td>
                          <td>{v.documents_valid_until}</td>
                          <td>
                            <Badge value={v.status} />
                            <small>{dateTime(v.readiness_checked_at)}</small>
                          </td>
                          <td>
                            {state.data!.data.allocations.some(
                              (a) => a.vehicle_id === v.id,
                            ) ? (
                              <Badge value="ALLOCATED" />
                            ) : (
                              <span className="muted">Belum dialokasikan</span>
                            )}
                            {session.role !== "viewer" && (
                              <button
                                className="block-small"
                                onClick={() => {
                                  setSelected(v);
                                  setStatus(v.status);
                                  setNote("");
                                }}
                              >
                                Periksa kesiapan
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {!state.data.data.vehicles.length && (
                    <Empty>
                      Belum ada kendaraan dalam register tenant ini.
                    </Empty>
                  )}
                </>
              ) : (
                <>
                  <table>
                    <thead>
                      <tr>
                        <th>Pengemudi</th>
                        <th>Nomor SIM</th>
                        <th>SIM sampai</th>
                        <th>Kelayakan diverifikasi sampai</th>
                        <th>Alokasi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {state.data.data.drivers.map((d) => (
                        <tr key={d.id}>
                          <td>{d.name}</td>
                          <td>{d.license_number}</td>
                          <td>{d.license_valid_until}</td>
                          <td>{dateTime(d.eligibility_verified_until)}</td>
                          <td>
                            {state.data!.data.allocations.some(
                              (a) => a.driver_id === d.id,
                            ) ? (
                              <Badge value="ALLOCATED" />
                            ) : (
                              <Badge value="AVAILABLE" />
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {!state.data.data.drivers.length && (
                    <Empty>
                      Belum ada pengemudi dalam register tenant ini.
                    </Empty>
                  )}
                </>
              )}
            </div>
          )
        )}
      </section>
    </>
  );
}
function VehicleForm({ onDone }: { onDone: () => void }) {
  const [plate, setPlate] = useState("");
  const [type, setType] = useState("");
  const [capacity, setCapacity] = useState(1000000);
  const [length, setLength] = useState(4000);
  const [width, setWidth] = useState(2000);
  const [height, setHeight] = useState(2000);
  const [expires, setExpires] = useState("");
  return (
    <>
      <h2>Daftarkan kendaraan</h2>
      <Form
        label="Simpan kendaraan"
        onSubmit={async () => {
          await api("fleet/vehicles", "POST", {
            plate,
            type,
            capacity_g: capacity,
            length_mm: length,
            width_mm: width,
            height_mm: height,
            documents_valid_until: expires,
          });
          onDone();
        }}
      >
        <div className="form-grid">
          <Field label="Nomor polisi">
            <input
              required
              maxLength={30}
              value={plate}
              onChange={(e) => setPlate(e.target.value)}
            />
          </Field>
          <Field label="Tipe kendaraan">
            <input
              required
              maxLength={80}
              value={type}
              onChange={(e) => setType(e.target.value)}
            />
          </Field>
          <Field label="Kapasitas muatan (gram)">
            <input
              type="number"
              required
              min={1}
              max={100000000}
              step={1}
              value={capacity}
              onChange={(e) => setCapacity(e.target.valueAsNumber)}
            />
          </Field>
          <Field
            label="Dokumen operasional berlaku sampai"
            hint="Gunakan masa berlaku terpendek dari STNK, KIR dan dokumen wajib lainnya."
          >
            <input
              required
              type="date"
              value={expires}
              onChange={(e) => setExpires(e.target.value)}
            />
          </Field>
          {[
            { label: "Panjang box (mm)", value: length, set: setLength },
            { label: "Lebar box (mm)", value: width, set: setWidth },
            { label: "Tinggi box (mm)", value: height, set: setHeight },
          ].map((f) => (
            <Field key={f.label} label={f.label}>
              <input
                type="number"
                required
                min={1}
                max={20000}
                step={1}
                value={f.value}
                onChange={(e) => f.set(e.target.valueAsNumber)}
              />
            </Field>
          ))}
        </div>
        <p className="muted">
          Pendaftaran ini mencatat pernyataan kesiapan awal Anda. Validitas
          wajib diperiksa kembali sebelum dispatch.
        </p>
      </Form>
    </>
  );
}
function DriverForm({ onDone }: { onDone: () => void }) {
  const [name, setName] = useState("");
  const [license, setLicense] = useState("");
  const [expires, setExpires] = useState("");
  const [verified, setVerified] = useState(localFuture);
  const [attested, setAttested] = useState(false);
  return (
    <>
      <h2>Daftarkan pengemudi</h2>
      <Form
        label="Simpan pengemudi"
        disabled={!attested}
        onSubmit={async () => {
          await api("fleet/drivers", "POST", {
            name,
            license_number: license,
            license_valid_until: expires,
            eligibility_verified_until: new Date(verified).toISOString(),
          });
          onDone();
        }}
      >
        <div className="form-grid">
          <Field label="Nama pengemudi">
            <input
              required
              maxLength={150}
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </Field>
          <Field label="Nomor SIM">
            <input
              required
              maxLength={80}
              value={license}
              onChange={(e) => setLicense(e.target.value)}
            />
          </Field>
          <Field label="SIM berlaku sampai">
            <input
              type="date"
              required
              value={expires}
              onChange={(e) => setExpires(e.target.value)}
            />
          </Field>
          <Field label="Kelayakan operasi diverifikasi sampai">
            <input
              type="datetime-local"
              required
              value={verified}
              onChange={(e) => setVerified(e.target.value)}
            />
          </Field>
        </div>
        <label className="check">
          <input
            type="checkbox"
            checked={attested}
            required
            onChange={(e) => setAttested(e.target.checked)}
          />{" "}
          Saya telah memeriksa golongan SIM, kelayakan dan jam kerja pengemudi
          untuk jendela operasi ini.
        </label>
        <p className="muted">
          Verifikasi manual tidak menggantikan mesin kepatuhan HOS berbasis
          regulasi. Gunakan batas waktu sesuai bukti pemeriksaan.
        </p>
      </Form>
    </>
  );
}
