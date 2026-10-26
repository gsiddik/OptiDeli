import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { money } from "./commercial";
import { can } from "./opsTypes";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";

type Contract = {
  id: string;
  code: string;
  name: string;
  status: "ACTIVE" | "ENDED";
  customer_id: string;
  project_id: string | null;
  vehicle_id: string;
  vendor_id: string | null;
  customer_label?: string;
  vehicle_label?: string;
  vendor_label?: string | null;
  valid_from: string;
  valid_until: string | null;
  monthly_sell_minor: number;
  monthly_buy_minor: number;
  trips_included: number;
  overage_sell_minor: number;
  overage_buy_minor: number;
  prorate: boolean;
  working_days: number;
  notes: string | null;
};
type Charge = {
  id: string;
  period: string;
  side: "SELL" | "BUY";
  kind: "FIXED" | "OVERAGE";
  description: string;
  amount_minor: number;
  status: string;
  calculation: string | null;
};
type Opt = { value: string; label: string };

const toInput = (minor: number | null | undefined) =>
  minor ? String(minor / 100) : "";
const lastMonth = () => {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - 1);
  return d.toISOString().slice(0, 7);
};

function useOptions() {
  const [o, setO] = useState<Record<string, Opt[]>>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    Promise.all([
      api<Rows>("master/customers/options"),
      api<Rows>("master/customer_projects/options"),
      api<Rows>("vendors"),
      api<{
        data: { vehicles: { id: string; plate: string; type: string }[] };
      }>("fleet"),
    ])
      .then(([c, p, v, f]) => {
        if (!live) return;
        const map = (r: Rows) =>
          r.data.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` }));
        setO({
          customers: map(c),
          projects: map(p),
          vendors: map(v),
          vehicles: f.data.vehicles.map((x) => ({
            value: x.id,
            label: `${x.plate} · ${x.type}`,
          })),
        });
      })
      .catch(
        () =>
          live &&
          setO({ customers: [], projects: [], vendors: [], vehicles: [] }),
      );
    return () => {
      live = false;
    };
  }, []);
  return o;
}

const optional = (f: EditorField): EditorField => ({ ...f, required: false });

function contractFields(o: Record<string, Opt[]>, c?: Contract): EditorField[] {
  return [
    ...(c
      ? []
      : [{ name: "code", label: "Kode kontrak", value: "" } as EditorField]),
    { name: "name", label: "Nama kontrak", value: c?.name ?? "" },
    ...(c
      ? []
      : ([
          {
            name: "customer_id",
            label: "Pelanggan",
            kind: "select",
            options: o.customers,
            value: "",
          },
          {
            name: "vehicle_id",
            label: "Kendaraan",
            kind: "select",
            options: o.vehicles,
            value: "",
          },
        ] as EditorField[])),
    optional({
      name: "project_id",
      label: "Proyek (opsional)",
      kind: "select",
      options: o.projects,
      value: c?.project_id ?? "",
    }),
    optional({
      name: "vendor_id",
      label: "Vendor kendaraan (kosong = armada sendiri)",
      kind: "select",
      options: o.vendors,
      value: c?.vendor_id ?? "",
    }),
    {
      name: "valid_from",
      label: "Berlaku dari",
      kind: "date",
      value: c?.valid_from.slice(0, 10) ?? "",
    },
    optional({
      name: "valid_until",
      label: "Berlaku sampai",
      kind: "date",
      value: c?.valid_until?.slice(0, 10) ?? "",
    }),
    {
      name: "monthly_sell_minor",
      label: "Biaya jual per bulan (Rp)",
      kind: "money",
      value: toInput(c?.monthly_sell_minor),
    },
    optional({
      name: "monthly_buy_minor",
      label: "Biaya beli per bulan (Rp)",
      kind: "money",
      value: toInput(c?.monthly_buy_minor),
    }),
    optional({
      name: "trips_included",
      label: "Trip termasuk per bulan",
      kind: "number",
      value: String(c?.trips_included ?? 0),
    }),
    optional({
      name: "overage_sell_minor",
      label: "Tarif jual per trip lebih (Rp)",
      kind: "money",
      value: toInput(c?.overage_sell_minor),
    }),
    optional({
      name: "overage_buy_minor",
      label: "Tarif beli per trip lebih (Rp)",
      kind: "money",
      value: toInput(c?.overage_buy_minor),
    }),
    {
      name: "prorate",
      label: "Pro-rata menurut kehadiran",
      kind: "select",
      options: [
        { value: "1", label: "Ya" },
        { value: "0", label: "Tidak" },
      ],
      value: c ? (c.prorate ? "1" : "0") : "1",
    },
    {
      name: "working_days",
      label: "Hari kerja per bulan",
      kind: "number",
      value: String(c?.working_days ?? 26),
    },
  ];
}

/** Fixed-cost vehicle contracts: what a customer pays for a dedicated vehicle, and what the vehicle costs. */
export function ContractsPage() {
  const session = useSession();
  const options = useOptions();
  const state = useData<{ data: Contract[] }>("contracts");
  const [creating, setCreating] = useState(false);
  const [attending, setAttending] = useState(false);
  const manage = can(session, "contract.manage");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">KONTRAK</span>
          <h1 tabIndex={-1}>Kontrak kendaraan (fixcost)</h1>
          <p>
            Kendaraan yang dikontrak pelanggan dengan biaya bulanan tetap, trip
            termasuk, dan tarif trip lebih. Biaya bulanan dihitung pro-rata
            menurut kehadiran lalu diverifikasi, ditagih, dan masuk P&amp;L.
          </p>
        </div>
        <div className="actions">
          {can(session, "attendance.manage") && (
            <button onClick={() => setAttending(true)}>Catat kehadiran</button>
          )}
          {manage && (
            <button className="primary" onClick={() => setCreating(true)}>
              + Kontrak
            </button>
          )}
        </div>
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
                  <th>Kontrak</th>
                  <th>Pelanggan</th>
                  <th>Kendaraan</th>
                  <th>Per bulan (jual / beli)</th>
                  <th>Trip termasuk</th>
                  <th>Berlaku</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {state.data.data.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <Link to={`/contracts/${c.id}`}>{c.code}</Link>
                      <br />
                      <small className="muted">{c.name}</small>
                    </td>
                    <td>{c.customer_label}</td>
                    <td>{c.vehicle_label}</td>
                    <td>
                      {money(c.monthly_sell_minor)} /{" "}
                      {money(c.monthly_buy_minor)}
                    </td>
                    <td>{c.trips_included}</td>
                    <td>
                      {c.valid_from.slice(0, 10)} –{" "}
                      {c.valid_until?.slice(0, 10) ?? "…"}
                    </td>
                    <td>
                      <Badge value={c.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada kontrak kendaraan.</Empty>
        )}
      </section>
      {creating && options && (
        <EditorDialog
          title="Kontrak kendaraan baru"
          fields={contractFields(options)}
          onClose={() => setCreating(false)}
          onSubmit={async (body, key) => {
            await submitRecord("contracts", body, key);
            setCreating(false);
            state.refresh();
          }}
        />
      )}
      {attending && options && (
        <EditorDialog
          title="Catat kehadiran kendaraan"
          fields={[
            {
              name: "vehicle_id",
              label: "Kendaraan",
              kind: "select",
              options: options.vehicles,
              value: "",
            },
            { name: "from", label: "Dari", kind: "date", value: "" },
            { name: "to", label: "Sampai", kind: "date", value: "" },
            {
              name: "status",
              label: "Status",
              kind: "select",
              options: [
                { value: "PRESENT", label: "Hadir / beroperasi" },
                { value: "STANDBY", label: "Siaga (dihitung hadir)" },
                { value: "ABSENT", label: "Tidak hadir" },
                { value: "OFF", label: "Libur" },
              ],
              value: "PRESENT",
            },
            optional({ name: "notes", label: "Catatan", value: "" }),
          ]}
          onClose={() => setAttending(false)}
          onSubmit={async (body, key) => {
            await submitRecord("attendance", body, key);
            setAttending(false);
          }}
        />
      )}
    </>
  );
}

const SIDE = { SELL: "Jual", BUY: "Beli" };
const KIND = { FIXED: "Biaya tetap", OVERAGE: "Trip lebih" };

/** One contract: its months, the run of a month, and the verification of what the run produced. */
export function ContractDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const session = useSession();
  const options = useOptions();
  const state = useData<{ data: { contract: Contract; charges: Charge[] } }>(
    `contracts/${id}`,
  );
  const [period, setPeriod] = useState(lastMonth());
  const [notice, setNotice] = useState<string[]>([]);
  const [failure, setFailure] = useState<string>();
  const [dialog, setDialog] = useState<
    "edit" | "end" | { reject: "SELL" | "BUY"; period: string } | null
  >(null);
  const d = state.data?.data;
  if (state.loading) return <Loading />;
  if (state.error || !d) return <ErrorBox error={state.error} />;
  const c = d.contract;
  const manage = can(session, "contract.manage") && c.status === "ACTIVE";
  const periods = [...new Set(d.charges.map((x) => x.period))];

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
          <span className="eyebrow">KONTRAK</span>
          <h1 tabIndex={-1}>{c.code}</h1>
          <p>
            {c.name} · {c.customer_label} · {c.vehicle_label}
            {c.vendor_label && ` (vendor ${c.vendor_label})`}
          </p>
        </div>
        <Badge value={c.status} />
      </div>
      <ErrorBox error={failure} />
      {notice.map((n) => (
        <div className="alert" role="status" key={n}>
          {n}
        </div>
      ))}
      <section className="card">
        <dl className="details">
          <dt>Per bulan</dt>
          <dd>
            jual {money(c.monthly_sell_minor)} · beli{" "}
            {money(c.monthly_buy_minor)}
          </dd>
          <dt>Trip termasuk</dt>
          <dd>
            {c.trips_included}; lebih: jual {money(c.overage_sell_minor)} · beli{" "}
            {money(c.overage_buy_minor)} per trip
          </dd>
          <dt>Pro-rata</dt>
          <dd>{c.prorate ? `Ya, ${c.working_days} hari kerja` : "Tidak"}</dd>
          <dt>Berlaku</dt>
          <dd>
            {c.valid_from.slice(0, 10)} – {c.valid_until?.slice(0, 10) ?? "…"}
          </dd>
        </dl>
        {manage && (
          <div className="actions">
            <button onClick={() => setDialog("edit")}>Ubah</button>
            <button onClick={() => setDialog("end")}>Akhiri kontrak</button>
            <input
              type="month"
              aria-label="Periode"
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
            />
            <button
              className="primary"
              onClick={() =>
                void act(async () => {
                  const res = await api<{ data: { warnings: string[] } }>(
                    `contracts/${c.id}/runs`,
                    "POST",
                    { period },
                  );
                  setNotice(res.data.warnings);
                })
              }
            >
              Hitung bulan
            </button>
          </div>
        )}
      </section>
      {periods.map((p) => {
        const rows = d.charges.filter((x) => x.period === p);
        return (
          <section className="card block" key={p} aria-label={`Periode ${p}`}>
            <div className="page-heading">
              <h2>{p}</h2>
              <div className="actions">
                {(["SELL", "BUY"] as const).map((side) => {
                  const waiting = rows.some(
                    (r) => r.side === side && r.status === "PROPOSED",
                  );
                  const allowed = can(
                    session,
                    side === "SELL" ? "selling.verify" : "cost.verify",
                  );
                  return (
                    waiting &&
                    allowed && (
                      <span className="actions" key={side}>
                        <button
                          onClick={() =>
                            void act(() =>
                              api(`contracts/${c.id}/charges/verify`, "POST", {
                                period: p,
                                side,
                                decision: "VERIFY",
                              }),
                            )
                          }
                        >
                          Verifikasi biaya {SIDE[side].toLowerCase()}
                        </button>
                        <button
                          onClick={() => setDialog({ reject: side, period: p })}
                        >
                          Tolak biaya {SIDE[side].toLowerCase()}
                        </button>
                      </span>
                    )
                  );
                })}
              </div>
            </div>
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Sisi</th>
                    <th>Jenis</th>
                    <th>Uraian</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id}>
                      <td>{SIDE[r.side]}</td>
                      <td>{KIND[r.kind]}</td>
                      <td>{r.description}</td>
                      <td>{money(r.amount_minor)}</td>
                      <td>
                        <Badge value={r.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        );
      })}
      {!periods.length && <Empty>Belum ada bulan yang dihitung.</Empty>}
      <p>
        <button onClick={() => navigate("/contracts")}>
          Kembali ke daftar kontrak
        </button>
      </p>
      {dialog === "edit" && options && (
        <EditorDialog
          title="Ubah kontrak"
          fields={contractFields(options, c)}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`contracts/${c.id}`, body, key, {}, "PUT");
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog === "end" && (
        <EditorDialog
          title="Akhiri kontrak"
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
            await submitRecord(`contracts/${c.id}/end`, body, key);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {typeof dialog === "object" && dialog && "reject" in dialog && (
        <EditorDialog
          title="Tolak biaya kontrak"
          fields={[
            {
              name: "note",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`contracts/${c.id}/charges/verify`, body, key, {
              period: dialog.period,
              side: dialog.reject,
              decision: "REJECT",
            });
            setDialog(null);
            state.refresh();
          }}
        />
      )}
    </>
  );
}
