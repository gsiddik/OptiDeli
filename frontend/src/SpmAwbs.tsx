import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api } from "./api";
import { useSession } from "./App";
import { Badge, Empty } from "./components";
import { can } from "./opsTypes";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";

type Opt = { value: string; label: string };
export type AwbLine = {
  id: string;
  line_no: number;
  material: string;
  quantity: string;
  weight_kg: string | null;
  awb_id?: string | null;
};
export type Awb = {
  id: string;
  number: string;
  status: "PENDING" | "ON_TRIP" | "DELIVERED" | "CANCELLED";
  destination_text: string;
  destination_location_id: string;
  consignee_name: string | null;
  consignee_phone: string | null;
  notes: string | null;
  line_ids: string[];
  weight_kg: number;
  trip_id: string | null;
  trip_number: string | null;
};

const AWB_STATUS = {
  PENDING: "Menunggu trip",
  ON_TRIP: "Di trip",
  DELIVERED: "Terkirim",
  CANCELLED: "Dibatalkan",
};
const optional = (f: EditorField): EditorField => ({ ...f, required: false });
const num = (v: string | number | null) =>
  v === null
    ? "—"
    : Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });

function useResources() {
  const [r, setR] = useState<{
    locations: Opt[];
    vehicles: Opt[];
    drivers: Opt[];
    vendors: Opt[];
  }>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    Promise.all([
      api<Rows>("master/locations/options"),
      api<{
        data: {
          vehicles: { id: string; plate: string; type: string }[];
          drivers: { id: string; name: string }[];
        };
      }>("fleet"),
      api<Rows>("vendors"),
    ])
      .then(([l, f, v]) => {
        if (!live) return;
        const code = (x: Rows) =>
          x.data.map((o) => ({ value: o.id, label: `${o.code} · ${o.name}` }));
        setR({
          locations: code(l),
          vehicles: f.data.vehicles.map((x) => ({
            value: x.id,
            label: `${x.plate} · ${x.type}`,
          })),
          drivers: f.data.drivers.map((x) => ({ value: x.id, label: x.name })),
          vendors: code(v),
        });
      })
      .catch(
        () =>
          live &&
          setR({ locations: [], vehicles: [], drivers: [], vendors: [] }),
      );
    return () => {
      live = false;
    };
  }, []);
  return r;
}

/** The AWBs of an SPM: grouping its shipment lines, and planning trips for them. */
export function SpmAwbSection({
  spmId,
  status,
  lines,
  awbs,
  onChange,
}: {
  spmId: string;
  status: string;
  lines: AwbLine[];
  awbs: Awb[];
  onChange: () => void;
}) {
  const session = useSession();
  const navigate = useNavigate();
  const resources = useResources();
  const manage = can(session, "spm.manage");
  const open = ["DRAFT", "PLANNED", "LOADING", "IN_TRANSIT"].includes(status);
  const carrying = ["PLANNED", "LOADING", "IN_TRANSIT"].includes(status);
  const loose = lines.filter((l) => !l.awb_id);
  const [pickLines, setPickLines] = useState<string[]>([]);
  const [pickAwbs, setPickAwbs] = useState<string[]>([]);
  const [dialog, setDialog] = useState<
    "awb" | "trip" | { edit: Awb } | { cancel: Awb } | null
  >(null);
  const [extras, setExtras] = useState<{ templates: Opt[]; bookings: Opt[] }>({
    templates: [],
    bookings: [],
  });
  useEffect(() => {
    if (dialog !== "trip") return;
    let live = true;
    Promise.all([
      api<{ data: { id: string; code: string; name: string }[] }>(
        "route-templates?active=1",
      ),
      api<{
        data: {
          id: string;
          vehicle_label: string;
          starts_at: string;
          ends_at: string;
          purpose: string | null;
          status: string;
        }[];
      }>("reservations?status=BOOKED"),
    ])
      .then(([t, b]) => {
        if (!live) return;
        setExtras({
          templates: t.data.map((x) => ({
            value: x.id,
            label: `${x.code} · ${x.name}`,
          })),
          bookings: b.data.map((x) => ({
            value: x.id,
            label: `${x.vehicle_label} · ${new Date(x.starts_at).toLocaleString("id-ID")} – ${new Date(x.ends_at).toLocaleString("id-ID")}${x.purpose ? ` · ${x.purpose}` : ""}`,
          })),
        });
      })
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, [dialog]);
  const live = awbs.filter((a) => a.status !== "CANCELLED");
  const toggle = (list: string[], set: (v: string[]) => void, id: string) =>
    set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);

  const awbFields = (a?: Awb): EditorField[] => [
    optional({
      name: "destination_location_id",
      label: "Tujuan AWB (kosong = tujuan SPM)",
      kind: "select",
      options: resources?.locations ?? [],
      value: a?.destination_location_id ?? "",
    }),
    optional({
      name: "consignee_name",
      label: "Penerima",
      value: a?.consignee_name ?? "",
    }),
    optional({
      name: "consignee_phone",
      label: "Telepon penerima",
      value: a?.consignee_phone ?? "",
    }),
    optional({ name: "notes", label: "Catatan", value: a?.notes ?? "" }),
  ];

  return (
    <section className="card block">
      <div className="page-heading">
        <h2>AWB &amp; perjalanan</h2>
        {manage && open && (
          <div className="actions">
            <button
              disabled={!pickLines.length}
              onClick={() => setDialog("awb")}
            >
              + AWB dari baris terpilih
            </button>
            {carrying && (
              <button
                className="primary"
                disabled={!pickAwbs.length}
                onClick={() => setDialog("trip")}
              >
                Rencanakan perjalanan
              </button>
            )}
          </div>
        )}
      </div>
      <p className="muted">
        Satu SPM dapat memiliki beberapa AWB; tiap AWB diangkut oleh satu
        perjalanan, sehingga satu SPM bisa memiliki beberapa perjalanan. Baris
        muatan yang belum masuk AWB otomatis dikelompokkan saat SPM
        direncanakan.
      </p>
      {loose.length > 0 && (
        <div className="table-scroll">
          <table aria-label="Baris belum masuk AWB">
            <thead>
              <tr>
                {manage && open && <th>Pilih</th>}
                <th>#</th>
                <th>Material</th>
                <th>Jumlah</th>
                <th>Berat (kg)</th>
              </tr>
            </thead>
            <tbody>
              {loose.map((l) => (
                <tr key={l.id}>
                  {manage && open && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Pilih baris ${l.line_no}`}
                        checked={pickLines.includes(l.id)}
                        onChange={() => toggle(pickLines, setPickLines, l.id)}
                      />
                    </td>
                  )}
                  <td>{l.line_no}</td>
                  <td>{l.material}</td>
                  <td>{num(l.quantity)}</td>
                  <td>{num(l.weight_kg)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {live.length ? (
        <div className="table-scroll">
          <table aria-label="Daftar AWB">
            <thead>
              <tr>
                {manage && carrying && <th>Pilih</th>}
                <th>Nomor AWB</th>
                <th>Tujuan</th>
                <th>Penerima</th>
                <th>Baris</th>
                <th>Berat (kg)</th>
                <th>Status</th>
                <th>Perjalanan</th>
                {manage && open && <th>Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {live.map((a) => (
                <tr key={a.id}>
                  {manage && carrying && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Pilih ${a.number}`}
                        disabled={a.status !== "PENDING"}
                        checked={pickAwbs.includes(a.id)}
                        onChange={() => toggle(pickAwbs, setPickAwbs, a.id)}
                      />
                    </td>
                  )}
                  <td>{a.number}</td>
                  <td>{a.destination_text}</td>
                  <td>{a.consignee_name ?? "—"}</td>
                  <td>{a.line_ids.length}</td>
                  <td>{num(a.weight_kg)}</td>
                  <td>
                    <Badge value={a.status} />{" "}
                    <small className="muted">{AWB_STATUS[a.status]}</small>
                  </td>
                  <td>
                    {a.trip_id ? (
                      <Link to={`/planning/${a.trip_id}`}>{a.trip_number}</Link>
                    ) : (
                      "—"
                    )}
                  </td>
                  {manage && open && (
                    <td>
                      {a.status === "PENDING" && (
                        <div className="actions">
                          <button onClick={() => setDialog({ edit: a })}>
                            Ubah
                          </button>
                          <button onClick={() => setDialog({ cancel: a })}>
                            Batalkan
                          </button>
                        </div>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada AWB.</Empty>
      )}

      {dialog === "awb" && resources && (
        <EditorDialog
          title="AWB baru dari baris terpilih"
          fields={awbFields()}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`spm/${spmId}/awbs`, body, key, {
              line_ids: pickLines,
            });
            setPickLines([]);
            setDialog(null);
            onChange();
          }}
        />
      )}
      {typeof dialog === "object" &&
        dialog &&
        "edit" in dialog &&
        resources && (
          <EditorDialog
            title={`Ubah ${dialog.edit.number}`}
            fields={awbFields(dialog.edit)}
            onClose={() => setDialog(null)}
            onSubmit={async (body, key) => {
              await submitRecord(
                `awbs/${dialog.edit.id}`,
                body,
                key,
                {},
                "PUT",
              );
              setDialog(null);
              onChange();
            }}
          />
        )}
      {typeof dialog === "object" && dialog && "cancel" in dialog && (
        <EditorDialog
          title={`Batalkan ${dialog.cancel.number}`}
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
            await submitRecord(`awbs/${dialog.cancel.id}/cancel`, body, key);
            setDialog(null);
            onChange();
          }}
        />
      )}
      {dialog === "trip" && resources && (
        <EditorDialog
          title={`Rencanakan perjalanan (${pickAwbs.length} AWB)`}
          fields={[
            {
              name: "vehicle_id",
              label: "Kendaraan",
              kind: "select",
              options: resources.vehicles,
              value: "",
            },
            {
              name: "driver_id",
              label: "Pengemudi",
              kind: "select",
              options: resources.drivers,
              value: "",
            },
            optional({
              name: "vendor_id",
              label: "Vendor (kosong = armada sendiri)",
              kind: "select",
              options: resources.vendors,
              value: "",
            }),
            optional({
              name: "route_template_id",
              label: "Template rute (opsional)",
              kind: "select",
              options: extras.templates,
              value: "",
            }),
            optional({
              name: "reservation_id",
              label: "Pakai booking (opsional)",
              kind: "select",
              options: extras.bookings,
              value: "",
            }),
            { name: "starts_at", label: "Mulai", kind: "datetime", value: "" },
            { name: "ends_at", label: "Selesai", kind: "datetime", value: "" },
            optional({
              name: "reason_code",
              label: "Reason code (bila margin di bawah threshold)",
              value: "",
            }),
            optional({
              name: "notes",
              label: "Catatan",
              kind: "textarea",
              value: "",
            }),
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            const res = (await submitRecord("spm-trips", body, key, {
              spm_id: spmId,
              awb_ids: pickAwbs,
            })) as { data: { trip: { id: string } } };
            setPickAwbs([]);
            setDialog(null);
            navigate(`/planning/${res.data.trip.id}`);
          }}
        />
      )}
    </section>
  );
}
