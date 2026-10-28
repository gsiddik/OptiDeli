import { useState } from "react";
import { useData } from "./api";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import { newId } from "./uuid";

export type FleetDocument = {
  id: string;
  kind: string;
  number: string | null;
  valid_until: string | null;
};
type Common = {
  id: string;
  verification_status: "PENDING" | "VERIFIED" | "REJECTED";
  verification_note: string | null;
  active: boolean | number;
  documents: FleetDocument[];
  missing_documents: string[];
};
export type VendorVehicle = Common & {
  plate: string;
  carrier_type_id: string | null;
  carrier_type_label: string | null;
  tracker_id?: string | null;
  capacity_g: number;
  length_mm: number;
  width_mm: number;
  height_mm: number;
};
export type VendorDriver = Common & {
  name: string;
  license_number: string;
  phone: string | null;
};
type Overview = { vehicles: VendorVehicle[]; drivers: VendorDriver[] };
type Options = { carrier_types: { id: string; label: string }[] };

export const docLabel: Record<string, string> = {
  STNK: "STNK",
  KIR: "KIR",
  SIM: "SIM",
  KTP: "KTP",
};

const statusText = {
  PENDING: "Menunggu verifikasi",
  VERIFIED: "Terverifikasi",
  REJECTED: "Ditolak",
};

function Documents({
  doc,
  missing,
  base,
}: {
  doc: FleetDocument[];
  missing: string[];
  base: string;
}) {
  return (
    <ul className="plain">
      {doc.map((d) => (
        <li key={d.id}>
          <a href={`${base}/${d.id}`}>{docLabel[d.kind]}</a>
          {d.valid_until && ` s.d. ${d.valid_until.slice(0, 10)}`}
        </li>
      ))}
      {missing.map((k) => (
        <li key={k} className="muted">
          {docLabel[k]} belum diunggah
        </li>
      ))}
    </ul>
  );
}

/** A vendor's own vehicles and drivers: register them, upload their documents, and see what the tenant decided. */
export function VendorFleetPanel() {
  const fleet = useData<{ data: Overview }>("vendor/fleet");
  const options = useData<{ data: Options }>("vendor/rate-options");
  const [dialog, setDialog] = useState<
    | { kind: "vehicle"; row?: VendorVehicle }
    | { kind: "driver"; row?: VendorDriver }
    | { kind: "doc"; type: "vehicles" | "drivers"; row: Common }
    | null
  >(null);
  const [failure, setFailure] = useState<string>();
  const d = fleet.data?.data;
  const types = options.data?.data.carrier_types ?? [];

  async function toggle(
    type: "vehicles" | "drivers",
    row: Common,
    active: boolean,
  ) {
    setFailure(undefined);
    try {
      await submitRecord(
        `vendor/${type}/${row.id}/active`,
        { active },
        newId(),
        {},
        "PUT",
      );
      fleet.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }

  const vehicleFields = (v?: VendorVehicle): EditorField[] => [
    { name: "plate", label: "Nomor kendaraan", value: v?.plate ?? "" },
    {
      name: "carrier_type_id",
      label: "Tipe kendaraan (menentukan tarif)",
      kind: "select",
      options: types.map((t) => ({ value: t.id, label: t.label })),
      value: v?.carrier_type_id ?? "",
    },
    {
      name: "capacity_kg",
      label: "Kapasitas muat (kg)",
      kind: "decimal",
      value: v ? String(v.capacity_g / 1000) : "",
    },
    {
      name: "length_mm",
      label: "Panjang bak (mm)",
      kind: "number",
      value: v ? String(v.length_mm) : "",
    },
    {
      name: "width_mm",
      label: "Lebar bak (mm)",
      kind: "number",
      value: v ? String(v.width_mm) : "",
    },
    {
      name: "height_mm",
      label: "Tinggi bak (mm)",
      kind: "number",
      value: v ? String(v.height_mm) : "",
    },
    {
      name: "tracker_id",
      label: "ID perangkat pelacak (IMEI)",
      kind: "text",
      required: false,
      hint: "Opsional. ID yang dikirim perangkat GPS/IoT di kendaraan ini, mis. IMEI 15 digit. Dipakai agar kendaraan langsung terpantau di OptiRadar.",
      value: v?.tracker_id ?? "",
    },
  ];

  return (
    <>
      <ErrorBox error={failure ?? fleet.error} />
      <section className="card">
        <div className="section-heading">
          <h2>Kendaraan</h2>
          <button
            className="primary"
            onClick={() => setDialog({ kind: "vehicle" })}
          >
            + Kendaraan
          </button>
        </div>
        {fleet.loading ? (
          <Loading />
        ) : d?.vehicles.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Kendaraan</th>
                  <th>Tipe</th>
                  <th>Kapasitas</th>
                  <th>Dokumen</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {d.vehicles.map((v) => (
                  <tr key={v.id}>
                    <td>
                      {v.plate}
                      {!v.active && (
                        <small className="muted"> (nonaktif)</small>
                      )}
                    </td>
                    <td>{v.carrier_type_label ?? "—"}</td>
                    <td>{v.capacity_g / 1000} kg</td>
                    <td>
                      <Documents
                        doc={v.documents}
                        missing={v.missing_documents}
                        base="/api/v1/vendor/fleet-documents"
                      />
                    </td>
                    <td>
                      <Badge value={v.verification_status} />
                      <br />
                      <small className="muted">
                        {statusText[v.verification_status]}
                        {v.verification_note && ` — ${v.verification_note}`}
                      </small>
                    </td>
                    <td>
                      <div className="actions">
                        <button
                          onClick={() => setDialog({ kind: "vehicle", row: v })}
                        >
                          Ubah
                        </button>
                        <button
                          onClick={() =>
                            setDialog({ kind: "doc", type: "vehicles", row: v })
                          }
                        >
                          Unggah dokumen
                        </button>
                        <button
                          onClick={() => void toggle("vehicles", v, !v.active)}
                        >
                          {v.active ? "Nonaktifkan" : "Aktifkan"}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada kendaraan terdaftar.</Empty>
        )}
      </section>
      <section className="card block">
        <div className="section-heading">
          <h2>Pengemudi</h2>
          <button
            className="primary"
            onClick={() => setDialog({ kind: "driver" })}
          >
            + Pengemudi
          </button>
        </div>
        {fleet.loading ? (
          <Loading />
        ) : d?.drivers.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Pengemudi</th>
                  <th>No. SIM</th>
                  <th>Dokumen</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {d.drivers.map((r) => (
                  <tr key={r.id}>
                    <td>
                      {r.name}
                      {r.phone && (
                        <>
                          <br />
                          <small className="muted">{r.phone}</small>
                        </>
                      )}
                      {!r.active && (
                        <small className="muted"> (nonaktif)</small>
                      )}
                    </td>
                    <td>{r.license_number}</td>
                    <td>
                      <Documents
                        doc={r.documents}
                        missing={r.missing_documents}
                        base="/api/v1/vendor/fleet-documents"
                      />
                    </td>
                    <td>
                      <Badge value={r.verification_status} />
                      <br />
                      <small className="muted">
                        {statusText[r.verification_status]}
                        {r.verification_note && ` — ${r.verification_note}`}
                      </small>
                    </td>
                    <td>
                      <div className="actions">
                        <button
                          onClick={() => setDialog({ kind: "driver", row: r })}
                        >
                          Ubah
                        </button>
                        <button
                          onClick={() =>
                            setDialog({ kind: "doc", type: "drivers", row: r })
                          }
                        >
                          Unggah dokumen
                        </button>
                        <button
                          onClick={() => void toggle("drivers", r, !r.active)}
                        >
                          {r.active ? "Nonaktifkan" : "Aktifkan"}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada pengemudi terdaftar.</Empty>
        )}
      </section>
      {dialog?.kind === "vehicle" && (
        <EditorDialog
          title={dialog.row ? "Ubah kendaraan" : "Daftarkan kendaraan"}
          fields={vehicleFields(dialog.row)}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            if (dialog.row)
              await submitRecord(
                `vendor/vehicles/${dialog.row.id}`,
                body,
                key,
                {},
                "PUT",
              );
            else await submitRecord("vendor/vehicles", body, key);
            setDialog(null);
            fleet.refresh();
          }}
        />
      )}
      {dialog?.kind === "driver" && (
        <EditorDialog
          title={dialog.row ? "Ubah pengemudi" : "Daftarkan pengemudi"}
          fields={[
            {
              name: "name",
              label: "Nama pengemudi",
              value: dialog.row?.name ?? "",
            },
            {
              name: "license_number",
              label: "Nomor SIM",
              value: dialog.row?.license_number ?? "",
            },
            {
              name: "phone",
              label: "Telepon",
              required: false,
              value: dialog.row?.phone ?? "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            if (dialog.row)
              await submitRecord(
                `vendor/drivers/${dialog.row.id}`,
                body,
                key,
                {},
                "PUT",
              );
            else await submitRecord("vendor/drivers", body, key);
            setDialog(null);
            fleet.refresh();
          }}
        />
      )}
      {dialog?.kind === "doc" && (
        <EditorDialog
          title="Unggah dokumen"
          fields={[
            {
              name: "kind",
              label: "Jenis dokumen",
              kind: "select",
              options:
                dialog.type === "vehicles"
                  ? [
                      { value: "STNK", label: "STNK" },
                      { value: "KIR", label: "KIR" },
                    ]
                  : [
                      { value: "SIM", label: "SIM" },
                      { value: "KTP", label: "KTP" },
                    ],
              value: dialog.type === "vehicles" ? "STNK" : "SIM",
            },
            {
              name: "number",
              label: "Nomor dokumen",
              required: false,
              value: "",
            },
            {
              name: "valid_until",
              label: "Berlaku sampai (STNK, KIR, SIM)",
              kind: "date",
              required: false,
              value: "",
            },
            {
              name: "file",
              label: "Berkas (PDF, JPG, PNG; maks. 5 MB)",
              kind: "file",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(
              `vendor/${dialog.type}/${dialog.row.id}/documents`,
              body,
              key,
            );
            setDialog(null);
            fleet.refresh();
          }}
        />
      )}
    </>
  );
}
