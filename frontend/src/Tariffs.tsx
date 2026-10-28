import { useEffect, useState } from "react";
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
import { money } from "./commercial";
import { can } from "./opsTypes";
import { ImportDialog } from "./MasterImport";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import type { Page } from "./types";
import { Select } from "./Select";

type Opt = { value: string; label: string };
type Options = Record<string, Opt[]>;
type Table = {
  id: string;
  code: string;
  version: number;
  name: string;
  side: "SELL" | "BUY";
  status: "DRAFT" | "APPROVED" | "RETIRED";
  customer_label?: string | null;
  project_label?: string | null;
  vendor_label?: string | null;
  valid_from: string;
  valid_until: string | null;
  notes: string | null;
  status_reason: string | null;
  created_by: number;
  line_count?: number;
  proposed_by_vendor?: boolean;
  submitted_at?: string | null;
  review_note?: string | null;
};
type Line = Record<string, string | number | null> & {
  id: string;
  basis: string;
  unit_price_minor: number;
  min_charge_minor: number;
};
type Detail = {
  table: Table;
  lines: Line[];
  versions: {
    id: string;
    version: number;
    status: string;
    valid_from: string;
    valid_until: string | null;
  }[];
};
type Priced = {
  source: string;
  table_code?: string;
  version?: number;
  basis: string;
  quantity: number | null;
  unit_price_minor: number | null;
  amount_minor: number | null;
  markup?: { code: string };
};
type Resolution = {
  lines: {
    charge_code: { code: string; name: string };
    buy?: Priced | null;
    sell: Priced | null;
    margin_minor?: number | null;
  }[];
  totals: { buy_minor?: number; sell_minor: number; margin_minor?: number };
  warnings: string[];
};

const BASES: Record<string, string> = {
  TRIP: "Per trip",
  KG: "Per kg",
  CBM: "Per CBM",
  CARTON: "Per karton",
  KM: "Per km",
  DAY: "Per hari",
  DROP: "Per drop",
};
const SIDE = { SELL: "Tarif jual", BUY: "Tarif beli" };
const MASTERS = [
  "customers",
  "customer_projects",
  "cities",
  "zones",
  "locations",
  "carrier_types",
  "services",
  "charge_codes",
];

/** Choices for every master a tariff refers to, plus the vendors. */
function useOptions(): Options | undefined {
  const [options, setOptions] = useState<Options>();
  useEffect(() => {
    let live = true;
    Promise.all([
      ...MASTERS.map((m) =>
        api<{ data: { id: string; code: string; name: string }[] }>(
          `master/${m}/options`,
        ),
      ),
      api<{ data: { id: string; code: string; name: string }[] }>("vendors"),
    ])
      .then((all) => {
        if (!live) return;
        const keys = [...MASTERS, "vendors"];
        setOptions(
          Object.fromEntries(
            keys.map((k, i) => [
              k,
              all[i].data.map((o) => ({
                value: o.id,
                label: `${o.code} · ${o.name}`,
              })),
            ]),
          ),
        );
      })
      .catch(() => live && setOptions({}));
    return () => {
      live = false;
    };
  }, []);
  return options;
}

const optional = (f: EditorField): EditorField => ({ ...f, required: false });
const date = (v: string | null) => (v ? v.slice(0, 10) : "—");
const rupiah = (minor: number | null | undefined) =>
  minor === null || minor === undefined ? "—" : money(minor);
const toInput = (minor: unknown) =>
  minor === null || minor === undefined || minor === ""
    ? ""
    : String(Number(minor) / 100);

function tableFields(side: "SELL" | "BUY", o: Options, t?: Table) {
  const fields: EditorField[] = [];
  if (!t) fields.push({ name: "code", label: "Kode tarif", value: "" });
  fields.push({ name: "name", label: "Nama tarif", value: t?.name ?? "" });
  if (side === "SELL") {
    fields.push(
      optional({
        name: "customer_id",
        label: "Pelanggan (kosong = tarif umum)",
        kind: "select",
        options: o.customers ?? [],
        value: "",
      }),
      optional({
        name: "project_id",
        label: "Proyek / kontrak",
        kind: "select",
        options: o.customer_projects ?? [],
        value: "",
      }),
    );
  } else {
    fields.push(
      optional({
        name: "vendor_id",
        label: "Vendor (kosong = armada sendiri)",
        kind: "select",
        options: o.vendors ?? [],
        value: "",
      }),
    );
  }
  fields.push(
    {
      name: "valid_from",
      label: "Berlaku dari",
      kind: "date",
      value: t?.valid_from.slice(0, 10) ?? "",
    },
    optional({
      name: "valid_until",
      label: "Berlaku sampai",
      kind: "date",
      value: t?.valid_until?.slice(0, 10) ?? "",
    }),
    optional({
      name: "notes",
      label: "Catatan",
      kind: "textarea",
      value: t?.notes ?? "",
    }),
  );
  return fields;
}

function lineFields(o: Options, line?: Line): EditorField[] {
  const pick = (name: string, label: string, master: string): EditorField =>
    optional({
      name,
      label,
      kind: "select",
      options: o[master] ?? [],
      value: String(line?.[name] ?? ""),
      hint: undefined,
    });
  return [
    {
      name: "charge_code_id",
      label: "Kode biaya",
      kind: "select",
      options: o.charge_codes ?? [],
      value: String(line?.charge_code_id ?? ""),
    },
    {
      name: "basis",
      label: "Dasar tarif",
      kind: "select",
      options: Object.entries(BASES).map(([value, label]) => ({
        value,
        label,
      })),
      value: String(line?.basis ?? "TRIP"),
    },
    {
      name: "unit_price_minor",
      label: "Tarif per satuan (Rp)",
      kind: "money",
      value: toInput(line?.unit_price_minor),
    },
    optional({
      name: "min_charge_minor",
      label: "Biaya minimum (Rp)",
      kind: "money",
      value: line?.min_charge_minor ? toInput(line.min_charge_minor) : "",
    }),
    pick("origin_location_id", "Asal: lokasi", "locations"),
    pick("origin_city_id", "Asal: kota", "cities"),
    pick("origin_zone_id", "Asal: zona", "zones"),
    pick("destination_location_id", "Tujuan: lokasi", "locations"),
    pick("destination_city_id", "Tujuan: kota", "cities"),
    pick("destination_zone_id", "Tujuan: zona", "zones"),
    pick("carrier_type_id", "Tipe armada (kosong = semua)", "carrier_types"),
    pick("service_id", "Layanan (kosong = semua)", "services"),
    optional({
      name: "free_time_hours",
      label: "Free time (jam)",
      kind: "number",
      value: String(line?.free_time_hours ?? ""),
    }),
    optional({
      name: "notes",
      label: "Catatan",
      kind: "text",
      value: String(line?.notes ?? ""),
    }),
  ];
}

function TariffTabs({ active, buy }: { active: string; buy: boolean }) {
  const navigate = useNavigate();
  const tabs = [
    ["/tariffs", "Tarif", "list"],
    ...(buy ? [["/tariffs/markups", "Markup", "markups"]] : []),
    ["/tariffs/test", "Uji tarif", "test"],
  ];
  return (
    <div className="tabs" role="group" aria-label="Tarif">
      {tabs.map(([to, label, key]) => (
        <button
          key={key}
          aria-pressed={active === key}
          onClick={() => navigate(to)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

function Heading({ children }: { children?: React.ReactNode }) {
  return (
    <div className="page-heading">
      <div>
        <span className="eyebrow">TARIF</span>
        <h1 tabIndex={-1}>Tarif jual &amp; beli</h1>
        <p>
          Tarif berversi per rute, armada, dan layanan. Perubahan disetujui
          orang lain, dan harga yang berlaku dipilih dari yang paling spesifik.
        </p>
      </div>
      {children}
    </div>
  );
}

export function TariffListPage() {
  const session = useSession();
  const navigate = useNavigate();
  const canManage = can(session, "rate.manage");
  const buy = can(session, "rate.buy.view");
  const options = useOptions();
  const [q, setQ] = useState("");
  const [draft, setDraft] = useState("");
  const [side, setSide] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState<"SELL" | "BUY">();
  const state = useData<Page<Table>>(
    `tariffs?q=${encodeURIComponent(q)}&side=${side}&status=${status}&page=${page}`,
  );
  return (
    <>
      <Heading>
        {canManage && (
          <div className="actions">
            <button className="primary" onClick={() => setCreating("SELL")}>
              + Tarif jual
            </button>
            {buy && (
              <button onClick={() => setCreating("BUY")}>+ Tarif beli</button>
            )}
          </div>
        )}
      </Heading>
      <TariffTabs active="list" buy={buy} />
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
          aria-label="Cari tarif"
          placeholder="Cari kode atau nama…"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
        />
        {buy && (
          <select
            aria-label="Sisi"
            value={side}
            onChange={(e) => {
              setSide(e.target.value);
              setPage(1);
            }}
          >
            <option value="">Jual &amp; beli</option>
            <option value="SELL">Tarif jual</option>
            <option value="BUY">Tarif beli</option>
          </select>
        )}
        <select
          aria-label="Status"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Semua status</option>
          <option value="DRAFT">Draf</option>
          <option value="APPROVED">Disetujui</option>
          <option value="RETIRED">Dihentikan</option>
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
                      <th>Kode</th>
                      <th>Nama</th>
                      <th>Sisi</th>
                      <th>Pihak</th>
                      <th>Berlaku</th>
                      <th>Baris</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((t) => (
                      <tr key={t.id}>
                        <td>
                          <Link to={`/tariffs/${t.id}`}>
                            {t.code} v{t.version}
                          </Link>
                        </td>
                        <td>{t.name}</td>
                        <td>{SIDE[t.side]}</td>
                        <td>
                          {t.project_label ??
                            t.customer_label ??
                            t.vendor_label ??
                            (t.side === "SELL" ? "Umum" : "Armada sendiri")}
                        </td>
                        <td>
                          {date(t.valid_from)} – {date(t.valid_until)}
                        </td>
                        <td>{t.line_count}</td>
                        <td>
                          <Badge value={t.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>Belum ada tarif yang cocok.</Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
      {creating && options && (
        <EditorDialog
          title={creating === "SELL" ? "Tarif jual baru" : "Tarif beli baru"}
          fields={tableFields(creating, options)}
          onClose={() => setCreating(undefined)}
          onSubmit={async (body, key) => {
            const res = (await submitRecord("tariffs", body, key, {
              side: creating,
            })) as { data: Table };
            setCreating(undefined);
            navigate(`/tariffs/${res.data.id}`);
          }}
        />
      )}
    </>
  );
}

export function TariffDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const canManage = can(session, "rate.manage");
  const canApprove = can(session, "rate.approve");
  const buy = can(session, "rate.buy.view");
  const options = useOptions();
  const state = useData<{ data: Detail }>(`tariffs/${id}`);
  const navigate = useNavigate();
  const [dialog, setDialog] = useState<
    "edit" | "line" | "version" | "reject" | { line: Line } | null
  >(null);
  const [retiring, setRetiring] = useState(false);
  const [importing, setImporting] = useState(false);
  const [reason, setReason] = useState("");
  const [failure, setFailure] = useState<string>();
  const detail = state.data?.data;
  const t = detail?.table;
  const draft = t?.status === "DRAFT";

  async function act(path: string, method = "POST", body: unknown = {}) {
    setFailure(undefined);
    try {
      await api(path, method, body);
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }

  const where = (l: Line, p: "origin" | "destination") =>
    String(
      l[`${p}_location_label`] ??
        l[`${p}_city_label`] ??
        l[`${p}_zone_label`] ??
        "Semua",
    );
  return (
    <>
      <Heading />
      <TariffTabs active="list" buy={buy} />
      <ErrorBox error={state.error ?? failure} />
      {state.loading && <Loading />}
      {t && detail && options && (
        <>
          <section className="card">
            <h2>
              {t.code} v{t.version} · {t.name} <Badge value={t.status} />
            </h2>
            <p>
              {SIDE[t.side]} ·{" "}
              {t.project_label ??
                t.customer_label ??
                t.vendor_label ??
                (t.side === "SELL" ? "Umum" : "Armada sendiri")}{" "}
              · berlaku {date(t.valid_from)} – {date(t.valid_until)}
            </p>
            {t.notes && <p>{t.notes}</p>}
            {t.proposed_by_vendor && (
              <p className="alert" role="status">
                Tarif ini diusulkan vendor.{" "}
                {t.submitted_at
                  ? "Setujui atau kembalikan kepada vendor dengan alasan; isinya tidak diubah di sini."
                  : "Vendor belum mengajukannya."}
              </p>
            )}
            {t.status_reason && <p>Alasan dihentikan: {t.status_reason}</p>}
            {detail.versions.length > 1 && (
              <p>
                Versi:{" "}
                {detail.versions.map((v) => (
                  <span key={v.id}>
                    <Link to={`/tariffs/${v.id}`}>v{v.version}</Link> (
                    {v.status.toLowerCase()}){" "}
                  </span>
                ))}
              </p>
            )}
            <div className="actions">
              {canManage && draft && !t.proposed_by_vendor && (
                <>
                  <button onClick={() => setDialog("edit")}>Ubah</button>
                  <button onClick={() => setDialog("line")}>
                    + Baris tarif
                  </button>
                  <button onClick={() => setImporting(true)}>
                    Impor Excel / CSV
                  </button>
                  <a href="/api/v1/tariffs/lines-template?format=xlsx">
                    Template Excel
                  </a>
                </>
              )}
              {canApprove && draft && (
                <button
                  className="primary"
                  disabled={
                    t.created_by === session.id ||
                    !detail.lines.length ||
                    (!!t.proposed_by_vendor && !t.submitted_at)
                  }
                  title={
                    t.created_by === session.id
                      ? "Tarif harus disetujui oleh orang lain."
                      : undefined
                  }
                  onClick={() => void act(`tariffs/${t.id}/approve`)}
                >
                  Setujui
                </button>
              )}
              {canApprove &&
                draft &&
                t.proposed_by_vendor &&
                t.submitted_at && (
                  <button onClick={() => setDialog("reject")}>
                    Kembalikan ke vendor
                  </button>
                )}
              {canManage && t.status !== "DRAFT" && (
                <button onClick={() => setDialog("version")}>
                  Buat versi baru
                </button>
              )}
              {canApprove && t.status === "APPROVED" && (
                <button
                  onClick={() => {
                    setReason("");
                    setRetiring(true);
                  }}
                >
                  Hentikan
                </button>
              )}
            </div>
            {draft && t.created_by === session.id && (
              <small className="muted">
                Anda penyusun draf ini; orang lain dengan izin persetujuan yang
                perlu menyetujuinya.
              </small>
            )}
          </section>
          <section className="card">
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Kode biaya</th>
                    <th>Asal</th>
                    <th>Tujuan</th>
                    <th>Armada</th>
                    <th>Layanan</th>
                    <th>Dasar</th>
                    <th>Tarif</th>
                    <th>Minimum</th>
                    {canManage && draft && <th>Aksi</th>}
                  </tr>
                </thead>
                <tbody>
                  {detail.lines.map((l) => (
                    <tr key={l.id}>
                      <td>{String(l.charge_code_label)}</td>
                      <td>{where(l, "origin")}</td>
                      <td>{where(l, "destination")}</td>
                      <td>{String(l.carrier_type_label ?? "Semua")}</td>
                      <td>{String(l.service_label ?? "Semua")}</td>
                      <td>{BASES[l.basis]}</td>
                      <td>{rupiah(l.unit_price_minor)}</td>
                      <td>
                        {l.min_charge_minor ? rupiah(l.min_charge_minor) : "—"}
                      </td>
                      {canManage && draft && (
                        <td>
                          <div className="actions">
                            <button onClick={() => setDialog({ line: l })}>
                              Ubah
                            </button>
                            <button
                              onClick={() =>
                                void act(
                                  `tariffs/${t.id}/lines/${l.id}`,
                                  "DELETE",
                                )
                              }
                            >
                              Hapus
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {!detail.lines.length && <Empty>Belum ada baris tarif.</Empty>}
          </section>
          {dialog === "edit" && (
            <EditorDialog
              title="Ubah tarif"
              fields={tableFields(t.side, options, t)}
              onClose={() => setDialog(null)}
              onSubmit={async (body, key) => {
                await submitRecord(`tariffs/${t.id}`, body, key, {}, "PUT");
                setDialog(null);
                state.refresh();
              }}
            />
          )}
          {(dialog === "line" ||
            (typeof dialog === "object" && dialog !== null)) && (
            <EditorDialog
              title={
                dialog === "line" ? "Baris tarif baru" : "Ubah baris tarif"
              }
              fields={lineFields(
                options,
                typeof dialog === "object" ? dialog.line : undefined,
              )}
              onClose={() => setDialog(null)}
              onSubmit={async (body, key) => {
                if (typeof dialog === "object" && dialog !== null)
                  await submitRecord(
                    `tariffs/${t.id}/lines/${dialog.line.id}`,
                    body,
                    key,
                    {},
                    "PUT",
                  );
                else await submitRecord(`tariffs/${t.id}/lines`, body, key);
                setDialog(null);
                state.refresh();
              }}
            />
          )}
          {dialog === "reject" && (
            <EditorDialog
              title="Kembalikan tarif ke vendor"
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
                await submitRecord(`tariffs/${t.id}/reject`, body, key);
                setDialog(null);
                state.refresh();
              }}
            />
          )}
          {dialog === "version" && (
            <EditorDialog
              title={`Versi ${t.version + 1} dari ${t.code}`}
              fields={[
                {
                  name: "valid_from",
                  label: "Berlaku dari",
                  kind: "date",
                  value: "",
                  hint: "Baris tarif disalin ke draf baru. Versi sebelumnya berakhir sehari sebelum tanggal ini saat versi baru disetujui.",
                },
              ]}
              onClose={() => setDialog(null)}
              onSubmit={async (body, key) => {
                const res = (await submitRecord(
                  `tariffs/${t.id}/versions`,
                  body,
                  key,
                )) as { data: Table };
                setDialog(null);
                navigate(`/tariffs/${res.data.id}`);
              }}
            />
          )}
          {importing && (
            <ImportDialog
              uploadPath={`tariffs/${t.id}/imports`}
              label={`baris tarif ${t.code}`}
              onClose={() => setImporting(false)}
              onDone={state.refresh}
            />
          )}
          {retiring && (
            <Confirm
              title={`Hentikan ${t.code} v${t.version}?`}
              onClose={() => setRetiring(false)}
              onConfirm={async () => {
                await api(`tariffs/${t.id}/retire`, "POST", { reason });
                state.refresh();
              }}
            >
              <p>Tarif yang dihentikan tidak lagi dipakai untuk harga baru.</p>
              <Field label="Alasan (minimal 5 karakter)">
                <textarea
                  required
                  minLength={5}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                />
              </Field>
            </Confirm>
          )}
        </>
      )}
    </>
  );
}

type Markup = {
  id: string;
  code: string;
  name: string;
  mode: "PERCENT" | "AMOUNT";
  percent_bps: number | null;
  amount_minor: number | null;
  customer_label?: string | null;
  project_label?: string | null;
  carrier_type_label?: string | null;
  service_label?: string | null;
  active: boolean;
  [key: string]: unknown;
};

export function MarkupPage() {
  const session = useSession();
  const canManage = can(session, "rate.manage");
  const options = useOptions();
  const state = useData<{ data: Markup[] }>("tariffs/markups");
  const [dialog, setDialog] = useState<{ row?: Markup } | null>(null);
  const fields = (m?: Markup): EditorField[] => {
    const pick = (name: string, label: string, master: string) =>
      optional({
        name,
        label,
        kind: "select",
        options: options?.[master] ?? [],
        value: String(m?.[name] ?? ""),
      });
    return [
      ...(m ? [] : [{ name: "code", label: "Kode", value: "" }]),
      { name: "name", label: "Nama", value: m?.name ?? "" },
      {
        name: "mode",
        label: "Jenis",
        kind: "select" as const,
        options: [
          { value: "PERCENT", label: "Persentase dari tarif beli" },
          { value: "AMOUNT", label: "Nominal tetap per SPM" },
        ],
        value: m?.mode ?? "PERCENT",
      },
      optional({
        name: "percent",
        label: "Persentase (%)",
        kind: "decimal",
        value: m?.percent_bps != null ? String(m.percent_bps / 100) : "",
        hint: "Untuk jenis persentase.",
      }),
      optional({
        name: "amount_minor",
        label: "Nominal (Rp)",
        kind: "money",
        value: toInput(m?.amount_minor),
        hint: "Untuk jenis nominal.",
      }),
      pick("customer_id", "Pelanggan (kosong = semua)", "customers"),
      pick("project_id", "Proyek", "customer_projects"),
      pick("carrier_type_id", "Tipe armada", "carrier_types"),
      pick("service_id", "Layanan", "services"),
      pick("origin_city_id", "Kota asal", "cities"),
      pick("destination_city_id", "Kota tujuan", "cities"),
    ];
  };
  return (
    <>
      <Heading>
        {canManage && (
          <div className="actions">
            <button className="primary" onClick={() => setDialog({})}>
              + Aturan markup
            </button>
          </div>
        )}
      </Heading>
      <TariffTabs active="markups" buy />
      <p>
        Markup mengubah tarif beli menjadi harga jual bagi pelanggan yang tidak
        punya tarif jual tetap. Aturan yang paling spesifik dipakai.
      </p>
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
                      <th>Kode</th>
                      <th>Nama</th>
                      <th>Markup</th>
                      <th>Berlaku untuk</th>
                      <th>Status</th>
                      {canManage && <th>Aksi</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((m) => (
                      <tr key={m.id}>
                        <td>{m.code}</td>
                        <td>{m.name}</td>
                        <td>
                          {m.mode === "PERCENT"
                            ? `${(m.percent_bps ?? 0) / 100}%`
                            : rupiah(m.amount_minor)}
                        </td>
                        <td>
                          {[
                            m.project_label,
                            m.customer_label,
                            m.carrier_type_label,
                            m.service_label,
                          ]
                            .filter(Boolean)
                            .join(" · ") || "Semua"}
                        </td>
                        <td>
                          <Badge value={m.active ? "ACTIVE" : "INACTIVE"} />
                        </td>
                        {canManage && (
                          <td>
                            <div className="actions">
                              <button onClick={() => setDialog({ row: m })}>
                                Ubah
                              </button>
                              <button
                                onClick={() =>
                                  void api(
                                    `tariffs/markups/${m.id}/status`,
                                    "POST",
                                    { active: !m.active },
                                  ).then(state.refresh)
                                }
                              >
                                {m.active ? "Nonaktifkan" : "Aktifkan"}
                              </button>
                            </div>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>Belum ada aturan markup.</Empty>
              )}
            </>
          )
        )}
      </section>
      {dialog && options && (
        <EditorDialog
          title={dialog.row ? "Ubah aturan markup" : "Aturan markup baru"}
          fields={fields(dialog.row)}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            const { percent, ...rest } = body;
            const data = {
              ...rest,
              percent_bps:
                percent === null || percent === undefined
                  ? null
                  : Math.round(Number(percent) * 100),
            };
            await submitRecord(
              dialog.row
                ? `tariffs/markups/${dialog.row.id}`
                : "tariffs/markups",
              data,
              key,
              {},
              dialog.row ? "PUT" : "POST",
            );
            setDialog(null);
            state.refresh();
          }}
        />
      )}
    </>
  );
}

export function ResolverPage() {
  const session = useSession();
  const buy = can(session, "rate.buy.view");
  const options = useOptions();
  const [v, setV] = useState<Record<string, string>>({
    date: new Date().toISOString().slice(0, 10),
  });
  const [q, setQ] = useState<Record<string, string>>({ trips: "1" });
  const [result, setResult] = useState<Resolution>();
  const [error, setError] = useState<string>();
  const set = (name: string, value: string) =>
    setV((x) => ({ ...x, [name]: value }));
  const choice = (name: string, label: string, master: string, req = false) => (
    <Field label={label}>
      <Select
        required={req}
        value={v[name] ?? ""}
        onChange={(e) => set(name, e.target.value)}
      >
        <option value="">{req ? "Pilih…" : "—"}</option>
        {(options?.[master] ?? []).map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
    </Field>
  );
  async function run(e: FormEvent) {
    e.preventDefault();
    setError(undefined);
    const body: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(v)) if (val) body[k] = val;
    body.quantities = Object.fromEntries(
      Object.entries(q)
        .filter(([, val]) => val !== "")
        .map(([k, val]) => [k, Number(val)]),
    );
    try {
      const res = await api<{ data: Resolution }>(
        "tariffs/resolve",
        "POST",
        body,
      );
      setResult(res.data);
    } catch (err) {
      setResult(undefined);
      setError(
        err instanceof Error ? err.message : "Tarif tidak dapat dihitung.",
      );
    }
  }
  return (
    <>
      <Heading />
      <TariffTabs active="test" buy={buy} />
      <section className="card">
        <form onSubmit={(e) => void run(e)}>
          <div className="form-grid">
            <Field label="Tanggal">
              <input
                type="date"
                required
                value={v.date}
                onChange={(e) => set("date", e.target.value)}
              />
            </Field>
            {choice("origin_location_id", "Lokasi asal", "locations", true)}
            {choice(
              "destination_location_id",
              "Lokasi tujuan",
              "locations",
              true,
            )}
            {choice("customer_id", "Pelanggan", "customers")}
            {choice("project_id", "Proyek", "customer_projects")}
            {buy && choice("vendor_id", "Vendor (tarif beli)", "vendors")}
            {choice("carrier_type_id", "Tipe armada", "carrier_types")}
            {choice("service_id", "Layanan", "services")}
            {(
              [
                ["trips", "Jumlah trip"],
                ["kg", "Berat (kg)"],
                ["cbm", "Volume (CBM)"],
                ["carton", "Karton"],
                ["km", "Jarak (km)"],
                ["day", "Hari"],
                ["drop", "Drop"],
              ] as const
            ).map(([k, label]) => (
              <Field key={k} label={label}>
                <input
                  type="number"
                  min={0}
                  step="any"
                  value={q[k] ?? ""}
                  onChange={(e) => setQ((x) => ({ ...x, [k]: e.target.value }))}
                />
              </Field>
            ))}
          </div>
          <button className="primary" type="submit">
            Hitung tarif
          </button>
        </form>
      </section>
      <ErrorBox error={error} />
      {result && (
        <section className="card" aria-label="Hasil tarif">
          {result.warnings.map((w) => (
            <div className="alert" role="status" key={w}>
              {w}
            </div>
          ))}
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Kode biaya</th>
                  {buy && <th>Beli</th>}
                  <th>Jual</th>
                  {buy && <th>Margin</th>}
                  <th>Sumber</th>
                </tr>
              </thead>
              <tbody>
                {result.lines.map((l) => (
                  <tr key={l.charge_code.code}>
                    <td>{l.charge_code.name}</td>
                    {buy && <td>{rupiah(l.buy?.amount_minor)}</td>}
                    <td>{rupiah(l.sell?.amount_minor)}</td>
                    {buy && <td>{rupiah(l.margin_minor)}</td>}
                    <td>
                      {l.sell?.source === "MARKUP"
                        ? `Markup ${l.sell.markup?.code ?? ""}`
                        : l.sell?.table_code
                          ? `${l.sell.table_code} v${l.sell.version}`
                          : l.sell
                            ? "Turunan beli"
                            : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <th>Total</th>
                  {buy && <th>{rupiah(result.totals.buy_minor)}</th>}
                  <th>{rupiah(result.totals.sell_minor)}</th>
                  {buy && <th>{rupiah(result.totals.margin_minor)}</th>}
                  <th />
                </tr>
              </tfoot>
            </table>
          </div>
          {!result.lines.length && <Empty>Tidak ada tarif yang berlaku.</Empty>}
        </section>
      )}
    </>
  );
}
