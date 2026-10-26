import { useState } from "react";
import { api, useData } from "./api";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { money } from "./commercial";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";

type Table = {
  id: string;
  code: string;
  name: string;
  version: number;
  status: "DRAFT" | "APPROVED" | "RETIRED";
  state: "DRAFT" | "SUBMITTED" | "RETURNED" | "APPROVED" | "RETIRED";
  origin: "VENDOR" | "TENANT";
  valid_from: string;
  valid_until: string | null;
  line_count: number;
  review_note: string | null;
};
type Line = {
  id: string;
  carrier_type_label: string | null;
  charge_code_label: string | null;
  basis: string;
  unit_price_minor: number;
  min_charge_minor: number;
  origin_location_label: string | null;
  origin_city_label: string | null;
  origin_zone_label: string | null;
  destination_location_label: string | null;
  destination_city_label: string | null;
  destination_zone_label: string | null;
};
type Opt = { id: string; label: string };
type Options = {
  carrier_types: Opt[];
  charge_codes: Opt[];
  locations: Opt[];
  bases: Record<string, string>;
};

const emptyOptions: Options = {
  carrier_types: [],
  charge_codes: [],
  locations: [],
  bases: {},
};
const STATE: Record<Table["state"], string> = {
  DRAFT: "Draf (belum diajukan)",
  SUBMITTED: "Menunggu persetujuan tenant",
  RETURNED: "Dikembalikan tenant",
  APPROVED: "Disetujui",
  RETIRED: "Dihentikan",
};
const place = (a: string | null, b: string | null, c: string | null) =>
  a ?? b ?? c ?? "Semua";

function Detail({
  table,
  options,
  onChange,
}: {
  table: Table;
  options: Options;
  onChange: () => void;
}) {
  const state = useData<{ data: { lines: Line[] } }>(
    `vendor/rates/${table.id}`,
  );
  const [adding, setAdding] = useState(false);
  const [failure, setFailure] = useState<string>();
  const editable =
    table.origin === "VENDOR" && ["DRAFT", "RETURNED"].includes(table.state);
  const opt = (list: Opt[]) =>
    list.map((o) => ({ value: o.id, label: o.label }));
  const fields: EditorField[] = [
    {
      name: "carrier_type_id",
      label: "Tipe kendaraan",
      kind: "select",
      options: opt(options.carrier_types),
      value: "",
    },
    {
      name: "charge_code_id",
      label: "Kode biaya",
      kind: "select",
      options: opt(options.charge_codes),
      value: "",
    },
    {
      name: "basis",
      label: "Dasar tarif",
      kind: "select",
      options: Object.entries(options.bases).map(([value, label]) => ({
        value,
        label,
      })),
      value: "TRIP",
    },
    { name: "unit_price_minor", label: "Tarif (Rp)", kind: "money", value: "" },
    {
      name: "min_charge_minor",
      label: "Biaya minimum (Rp)",
      kind: "money",
      required: false,
      value: "",
    },
    {
      name: "origin_location_id",
      label: "Asal (kosong = semua)",
      kind: "select",
      options: opt(options.locations),
      required: false,
      value: "",
    },
    {
      name: "destination_location_id",
      label: "Tujuan (kosong = semua)",
      kind: "select",
      options: opt(options.locations),
      required: false,
      value: "",
    },
  ];

  async function act(run: () => Promise<unknown>) {
    setFailure(undefined);
    try {
      await run();
      state.refresh();
      onChange();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }
  if (state.loading) return <Loading />;
  const lines = state.data?.data.lines ?? [];
  return (
    <div className="block">
      <ErrorBox error={failure ?? state.error} />
      {lines.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Tipe kendaraan</th>
                <th>Rute</th>
                <th>Kode biaya</th>
                <th>Dasar</th>
                <th>Tarif</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.id}>
                  <td>{l.carrier_type_label}</td>
                  <td>
                    {place(
                      l.origin_location_label,
                      l.origin_city_label,
                      l.origin_zone_label,
                    )}{" "}
                    →{" "}
                    {place(
                      l.destination_location_label,
                      l.destination_city_label,
                      l.destination_zone_label,
                    )}
                  </td>
                  <td>{l.charge_code_label}</td>
                  <td>{options.bases[l.basis] ?? l.basis}</td>
                  <td>
                    {money(l.unit_price_minor)}
                    {l.min_charge_minor > 0 && (
                      <small className="muted">
                        {" "}
                        min. {money(l.min_charge_minor)}
                      </small>
                    )}
                  </td>
                  <td>
                    {editable && (
                      <button
                        onClick={() =>
                          void act(() =>
                            api(
                              `vendor/rates/${table.id}/lines/${l.id}`,
                              "DELETE",
                            ),
                          )
                        }
                      >
                        Hapus
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada baris tarif.</Empty>
      )}
      {editable && (
        <div className="actions">
          <button onClick={() => setAdding(true)}>+ Baris tarif</button>
          <button
            className="primary"
            disabled={!lines.length}
            onClick={() =>
              void act(() => api(`vendor/rates/${table.id}/submit`, "POST"))
            }
          >
            Ajukan ke tenant
          </button>
        </div>
      )}
      {table.state === "SUBMITTED" && (
        <button
          onClick={() =>
            void act(() => api(`vendor/rates/${table.id}/withdraw`, "POST"))
          }
        >
          Tarik kembali pengajuan
        </button>
      )}
      {table.origin === "VENDOR" && table.state === "APPROVED" && (
        <button
          onClick={() =>
            void act(() =>
              api(`vendor/rates/${table.id}/versions`, "POST", {
                valid_from: new Date().toISOString().slice(0, 10),
              }),
            )
          }
        >
          Buat versi baru untuk diajukan
        </button>
      )}
      {adding && (
        <EditorDialog
          title="Baris tarif"
          fields={fields}
          onClose={() => setAdding(false)}
          onSubmit={async (body, key) => {
            await submitRecord(`vendor/rates/${table.id}/lines`, body, key);
            setAdding(false);
            state.refresh();
            onChange();
          }}
        />
      )}
    </div>
  );
}

/** A vendor's buying rates per vehicle type. What it proposes is a draft until the tenant approves it; what the tenant entered shows here read-only. */
export function VendorRatesPanel() {
  const rates = useData<{ data: Table[] }>("vendor/rates");
  const options = useData<{ data: Options }>("vendor/rate-options");
  const [open, setOpen] = useState<string>();
  const [creating, setCreating] = useState(false);
  return (
    <section className="card">
      <div className="section-heading">
        <h2>Tarif per tipe kendaraan</h2>
        <button className="primary" onClick={() => setCreating(true)}>
          + Usulkan tarif
        </button>
      </div>
      <p className="muted">
        Tarif berlaku sebagai harga beli tenant setelah disetujui tenant. Tarif
        dibuat per tipe kendaraan, bukan per pengemudi.
      </p>
      <ErrorBox error={rates.error ?? options.error} />
      {rates.loading || options.loading ? (
        <Loading />
      ) : rates.data?.data.length && options.data ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Tarif</th>
                <th>Berlaku</th>
                <th>Baris</th>
                <th>Disusun oleh</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {rates.data.data.map((t) => (
                <tr key={t.id}>
                  <td>
                    <button
                      aria-expanded={open === t.id}
                      onClick={() => setOpen(open === t.id ? undefined : t.id)}
                    >
                      {t.code} v{t.version}
                    </button>
                    <br />
                    <small className="muted">{t.name}</small>
                    {t.review_note && t.state === "RETURNED" && (
                      <p className="alert" role="status">
                        Catatan tenant: {t.review_note}
                      </p>
                    )}
                    {open === t.id && (
                      <Detail
                        table={t}
                        options={options.data?.data ?? emptyOptions}
                        onChange={rates.refresh}
                      />
                    )}
                  </td>
                  <td>
                    {t.valid_from.slice(0, 10)} –{" "}
                    {t.valid_until?.slice(0, 10) ?? "…"}
                  </td>
                  <td>{t.line_count}</td>
                  <td>{t.origin === "VENDOR" ? "Vendor" : "Tenant"}</td>
                  <td>
                    <Badge value={t.state} />
                    <br />
                    <small className="muted">{STATE[t.state]}</small>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada tarif.</Empty>
      )}
      {creating && (
        <EditorDialog
          title="Usulkan tarif"
          fields={[
            { name: "name", label: "Nama tarif", value: "" },
            {
              name: "valid_from",
              label: "Berlaku dari",
              kind: "date",
              value: "",
            },
            {
              name: "valid_until",
              label: "Berlaku sampai",
              kind: "date",
              required: false,
              value: "",
            },
            {
              name: "notes",
              label: "Catatan",
              kind: "textarea",
              required: false,
              value: "",
            },
          ]}
          onClose={() => setCreating(false)}
          onSubmit={async (body, key) => {
            await submitRecord("vendor/rates", body, key);
            setCreating(false);
            rates.refresh();
          }}
        />
      )}
    </section>
  );
}
