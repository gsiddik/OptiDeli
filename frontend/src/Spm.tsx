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
  Loading,
  Pagination,
} from "./components";
import { dateTime, money } from "./commercial";
import { can } from "./opsTypes";
import { SpmAwbSection } from "./SpmAwbs";
import type { Awb } from "./SpmAwbs";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import type { Page } from "./types";

type Opt = { value: string; label: string };
type SpmRow = {
  id: string;
  number: string;
  order_id: string;
  order_number: string | null;
  sequence: number;
  status: string;
  customer_label: string | null;
  origin_text: string;
  destination_text: string;
  planned_pickup_at: string | null;
};
type Line = {
  id: string;
  awb_id?: string | null;
  line_no: number;
  po_number: string | null;
  so_number: string | null;
  material: string;
  quantity: string;
  pallets: number | null;
  volume_cbm: string | null;
  weight_kg: string | null;
  seal_number: string | null;
  damage_qty: string;
  loss_qty: string;
  over_qty: string;
  notes: string | null;
};
type Charge = {
  id: string;
  side: "SELL" | "BUY";
  charge_code_label: string | null;
  kind: string;
  source: string;
  basis: string | null;
  quantity: string | null;
  unit_price_minor: number | null;
  amount_minor: number;
  rate_label: string | null;
  markup_code: string | null;
  reason: string | null;
  status: string;
};
type Detail = {
  spm: SpmRow & {
    reference: string | null;
    notes: string | null;
    status_reason: string | null;
    planned_delivery_at: string | null;
    project_label?: string | null;
    service_label?: string | null;
    origin_location_label?: string | null;
    destination_location_label?: string | null;
    carrier_type_label?: string | null;
    vendor_label?: string | null;
    carrier_type_id: string | null;
    vendor_id: string | null;
  };
  order: { id: string; number: string; customer_name: string; status: string };
  lines: Line[];
  awbs: Awb[];
  charges: Charge[];
  history: {
    id: string;
    from_status: string | null;
    to_status: string;
    reason: string | null;
    actor_name: string | null;
    created_at: string;
  }[];
  totals: { sell_minor: number; buy_minor?: number; margin_minor?: number };
  siblings: { id: string; number: string; sequence: number; status: string }[];
};

export const SPM_STATUS: Record<string, string> = {
  DRAFT: "Draf",
  PLANNED: "Direncanakan",
  LOADING: "Muat",
  IN_TRANSIT: "Di perjalanan",
  DISCHARGED: "Selesai bongkar",
  DOC_VERIFIED: "Dokumen terverifikasi",
  CLOSED: "Ditutup",
  REOPENED: "Dibuka kembali",
  CANCELLED: "Dibatalkan",
};
const BASES: Record<string, string> = {
  TRIP: "per trip",
  KG: "per kg",
  CBM: "per CBM",
  CARTON: "per karton",
  KM: "per km",
  DAY: "per hari",
  DROP: "per drop",
};

/** Next steps by status: [target, label, permission, needs a reason]. */
const STEPS: Record<string, [string, string, string, boolean][]> = {
  DRAFT: [
    ["PLANNED", "Rencanakan & hitung tarif", "spm.manage", false],
    ["CANCELLED", "Batalkan SPM", "spm.manage", true],
  ],
  PLANNED: [
    ["LOADING", "Mulai muat", "spm.manage", false],
    ["CANCELLED", "Batalkan SPM", "spm.manage", true],
  ],
  LOADING: [["IN_TRANSIT", "Berangkat", "spm.manage", false]],
  IN_TRANSIT: [["DISCHARGED", "Selesai bongkar", "spm.manage", false]],
  DISCHARGED: [
    ["DOC_VERIFIED", "Verifikasi dokumen", "execution.verify", false],
    ["REOPENED", "Buka kembali", "spm.reopen", true],
  ],
  DOC_VERIFIED: [
    ["CLOSED", "Tutup SPM", "spm.manage", false],
    ["REOPENED", "Buka kembali", "spm.reopen", true],
  ],
  CLOSED: [["REOPENED", "Buka kembali", "spm.reopen", true]],
  REOPENED: [
    ["DOC_VERIFIED", "Verifikasi ulang dokumen", "execution.verify", false],
    ["DISCHARGED", "Kembali ke selesai bongkar", "spm.manage", false],
  ],
};

const optional = (f: EditorField): EditorField => ({ ...f, required: false });
const num = (v: string | number | null | undefined) =>
  v === null || v === undefined || v === ""
    ? "—"
    : Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });
const localInput = (v: string | null | undefined) =>
  v ? new Date(v).toISOString().slice(0, 16) : "";

function useChoices(): Record<string, Opt[]> | undefined {
  const [choices, setChoices] = useState<Record<string, Opt[]>>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    Promise.all([
      api<Rows>("master/carrier_types/options"),
      api<Rows>("master/locations/options"),
      api<Rows>("master/charge_codes/options"),
      api<Rows>("vendors"),
    ])
      .then(([c, l, ch, v]) => {
        if (!live) return;
        const map = (r: Rows) =>
          r.data.map((o) => ({ value: o.id, label: `${o.code} · ${o.name}` }));
        setChoices({
          carriers: map(c),
          locations: map(l),
          charges: map(ch),
          vendors: map(v),
        });
      })
      .catch(() => live && setChoices({}));
    return () => {
      live = false;
    };
  }, []);
  return choices;
}

function headerFields(
  c: Record<string, Opt[]>,
  d?: Detail["spm"],
): EditorField[] {
  return [
    optional({
      name: "carrier_type_id",
      label: "Tipe armada",
      kind: "select",
      options: c.carriers ?? [],
      value: d?.carrier_type_id ?? "",
    }),
    optional({
      name: "vendor_id",
      label: "Vendor (kosong = armada sendiri)",
      kind: "select",
      options: c.vendors ?? [],
      value: d?.vendor_id ?? "",
    }),
    optional({
      name: "planned_pickup_at",
      label: "Rencana muat",
      kind: "datetime",
      value: localInput(d?.planned_pickup_at),
    }),
    optional({
      name: "planned_delivery_at",
      label: "Rencana tiba",
      kind: "datetime",
      value: localInput(d?.planned_delivery_at),
    }),
    optional({
      name: "reference",
      label: "Referensi pelanggan",
      value: d?.reference ?? "",
    }),
    optional({
      name: "notes",
      label: "Catatan",
      kind: "textarea",
      value: d?.notes ?? "",
    }),
  ];
}

/** Dialog that makes an SPM from an order; the lines are copied from the order items. */
export function SpmCreateDialog({
  orderId,
  onClose,
}: {
  orderId: string;
  onClose: () => void;
}) {
  const choices = useChoices();
  const navigate = useNavigate();
  if (!choices) return null;
  return (
    <EditorDialog
      title="Buat SPM dari order ini"
      fields={headerFields(choices)}
      onClose={onClose}
      onSubmit={async (body, key) => {
        const res = (await submitRecord(
          `orders/${orderId}/spm`,
          body,
          key,
        )) as {
          data: Detail;
        };
        onClose();
        navigate(`/spm/${res.data.spm.id}`);
      }}
    />
  );
}

/** The SPMs of an order, with the button that makes the next one. */
export function OrderSpmSection({
  orderId,
  status,
}: {
  orderId: string;
  status: string;
}) {
  const session = useSession();
  const state = useData<Page<SpmRow>>(`spm?order_id=${orderId}`);
  const [creating, setCreating] = useState(false);
  const canMake =
    can(session, "spm.manage") &&
    ["CONFIRMED", "DISPATCHED", "IN_TRANSIT"].includes(status);
  return (
    <section className="card block">
      <div className="page-heading">
        <h2>SPM (Surat Perintah Muat)</h2>
        {canMake && (
          <button className="primary" onClick={() => setCreating(true)}>
            + Buat SPM
          </button>
        )}
      </div>
      {state.loading ? (
        <Loading />
      ) : state.data?.data.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Nomor</th>
                <th>Tujuan</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {state.data.data.map((s) => (
                <tr key={s.id}>
                  <td>
                    <Link to={`/spm/${s.id}`}>{s.number}</Link>
                  </td>
                  <td>{s.destination_text}</td>
                  <td>
                    <Badge value={s.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Order ini belum punya SPM.</Empty>
      )}
      {creating && (
        <SpmCreateDialog
          orderId={orderId}
          onClose={() => {
            setCreating(false);
            state.refresh();
          }}
        />
      )}
    </section>
  );
}

export function SpmListPage() {
  const [draft, setDraft] = useState("");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const state = useData<Page<SpmRow>>(
    `spm?q=${encodeURIComponent(q)}&status=${status}&page=${page}`,
  );
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">SPM</span>
          <h1 tabIndex={-1}>Surat Perintah Muat</h1>
          <p>
            Unit kerja dan unit tagih di antara order dan perjalanan. Buat SPM
            dari detail order yang sudah dikonfirmasi.
          </p>
        </div>
      </div>
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
          aria-label="Cari SPM"
          placeholder="Cari nomor, referensi, asal, tujuan…"
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
          {Object.entries(SPM_STATUS).map(([k, v]) => (
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
                      <th>Nomor SPM</th>
                      <th>Order</th>
                      <th>Pelanggan</th>
                      <th>Asal</th>
                      <th>Tujuan</th>
                      <th>Rencana muat</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((s) => (
                      <tr key={s.id}>
                        <td>
                          <Link to={`/spm/${s.id}`}>{s.number}</Link>
                        </td>
                        <td>
                          <Link to={`/orders/${s.order_id}`}>
                            {s.order_number}
                          </Link>
                        </td>
                        <td>{s.customer_label ?? "—"}</td>
                        <td>{s.origin_text}</td>
                        <td>{s.destination_text}</td>
                        <td>
                          {s.planned_pickup_at
                            ? dateTime(s.planned_pickup_at)
                            : "—"}
                        </td>
                        <td>
                          <Badge value={s.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>Belum ada SPM yang cocok.</Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
    </>
  );
}

function lineFields(line?: Line, actualsOnly = false): EditorField[] {
  const f = (
    name: string,
    label: string,
    kind: EditorField["kind"] = "text",
    required = false,
  ): EditorField => ({
    name,
    label,
    kind,
    required,
    value: String((line as Record<string, unknown> | undefined)?.[name] ?? ""),
  });
  if (actualsOnly)
    return [
      f("seal_number", "Nomor segel"),
      f("damage_qty", "Rusak", "decimal"),
      f("loss_qty", "Hilang", "decimal"),
      f("over_qty", "Lebih", "decimal"),
      f("notes", "Catatan"),
    ].map((x) => ({ ...x, required: false }));
  return [
    f("material", "Material / barang", "text", true),
    f("quantity", "Jumlah", "decimal", true),
    f("po_number", "No. PO"),
    f("so_number", "No. SO"),
    f("pallets", "Jumlah pallet", "number"),
    f("weight_kg", "Berat (kg)", "decimal"),
    f("volume_cbm", "Volume (CBM)", "decimal"),
    f("seal_number", "Nomor segel"),
    f("notes", "Catatan"),
  ].map((x) => ({ ...x, required: x.required === true }));
}

export function SpmDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const navigate = useNavigate();
  const choices = useChoices();
  const state = useData<{ data: Detail }>(`spm/${id}`);
  const [dialog, setDialog] = useState<
    | "header"
    | "line"
    | "destination"
    | "charge"
    | { line: Line }
    | { step: [string, string, string, boolean] }
    | null
  >(null);
  const [notice, setNotice] = useState<string[]>([]);
  const [failure, setFailure] = useState<string>();
  const d = state.data?.data;

  async function remove(line: Line) {
    setFailure(undefined);
    try {
      await api(`spm/${id}/lines/${line.id}`, "DELETE");
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Tidak dapat menghapus.");
    }
  }
  async function step(to: string, reason?: string) {
    const res = await api<{ data: { warnings: string[] } }>(
      `spm/${id}/status`,
      "POST",
      { to, reason },
    );
    setNotice(res.data.warnings);
    state.refresh();
  }

  if (state.loading) return <Loading />;
  if (state.error || !d)
    return (
      <>
        <ErrorBox error={state.error} />
        <button onClick={state.refresh}>Coba lagi</button>
      </>
    );
  const s = d.spm;
  const manage = can(session, "spm.manage");
  const draft = s.status === "DRAFT";
  const actuals = ["LOADING", "IN_TRANSIT", "DISCHARGED", "REOPENED"].includes(
    s.status,
  );
  const hasBuy = d.totals.buy_minor !== undefined;
  const steps = (STEPS[s.status] ?? []).filter(([, , perm]) =>
    can(session, perm),
  );
  const pending = typeof dialog === "object" && dialog && "step" in dialog;
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">SPM</span>
          <h1 tabIndex={-1}>{s.number}</h1>
          <p>
            Order <Link to={`/orders/${d.order.id}`}>{d.order.number}</Link> ·{" "}
            {d.order.customer_name}
            {d.siblings.length > 1 &&
              ` · SPM ${s.sequence} dari ${d.siblings.length}`}
          </p>
        </div>
        <Badge value={s.status} />
      </div>
      <ErrorBox error={failure} />
      {notice.map((n) => (
        <div className="alert" role="status" key={n}>
          {n}
        </div>
      ))}
      <div className="split">
        <section className="card">
          <h2>Rute & armada</h2>
          <dl className="details">
            <dt>Asal</dt>
            <dd>{s.origin_location_label ?? s.origin_text}</dd>
            <dt>Tujuan</dt>
            <dd>{s.destination_location_label ?? s.destination_text}</dd>
            <dt>Tipe armada</dt>
            <dd>{s.carrier_type_label ?? "—"}</dd>
            <dt>Vendor</dt>
            <dd>{s.vendor_label ?? "Armada sendiri"}</dd>
            <dt>Rencana muat</dt>
            <dd>{s.planned_pickup_at ? dateTime(s.planned_pickup_at) : "—"}</dd>
            <dt>Rencana tiba</dt>
            <dd>
              {s.planned_delivery_at ? dateTime(s.planned_delivery_at) : "—"}
            </dd>
            <dt>Referensi</dt>
            <dd>{s.reference ?? "—"}</dd>
            {s.status_reason && (
              <>
                <dt>Alasan terakhir</dt>
                <dd>{s.status_reason}</dd>
              </>
            )}
          </dl>
          <div className="actions">
            {manage && draft && (
              <button onClick={() => setDialog("header")}>Ubah</button>
            )}
            {can(session, "spm.reopen") &&
              ["DRAFT", "PLANNED", "LOADING"].includes(s.status) && (
                <button onClick={() => setDialog("destination")}>
                  Ganti tujuan
                </button>
              )}
            <Link className="button" to={`/spm/${s.id}/print`}>
              Cetak SPM
            </Link>
          </div>
        </section>
        <section className="card">
          <h2>Langkah berikutnya</h2>
          <div className="stack">
            {steps.map((st) => (
              <button
                key={st[0]}
                className={st[0] === "CANCELLED" ? "danger-button" : "primary"}
                onClick={() => setDialog({ step: st })}
              >
                {st[1]}
              </button>
            ))}
            {!steps.length && (
              <p className="muted">
                Tidak ada langkah yang tersedia untuk Anda.
              </p>
            )}
          </div>
          {d.siblings.length > 1 && (
            <p>
              SPM lain di order ini:{" "}
              {d.siblings
                .filter((x) => x.id !== s.id)
                .map((x) => (
                  <Link key={x.id} to={`/spm/${x.id}`}>
                    {x.number}{" "}
                  </Link>
                ))}
            </p>
          )}
        </section>
      </div>
      <section className="card block">
        <div className="page-heading">
          <h2>Muatan</h2>
          {manage && draft && (
            <button onClick={() => setDialog("line")}>+ Baris muatan</button>
          )}
        </div>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Material</th>
                <th>PO / SO</th>
                <th>Jumlah</th>
                <th>Berat (kg)</th>
                <th>Volume (CBM)</th>
                <th>Segel</th>
                <th>Rusak / hilang / lebih</th>
                {manage && (draft || actuals) && <th>Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {d.lines.map((l) => (
                <tr key={l.id}>
                  <td>{l.line_no}</td>
                  <td>{l.material}</td>
                  <td>
                    {[l.po_number, l.so_number].filter(Boolean).join(" / ") ||
                      "—"}
                  </td>
                  <td>{num(l.quantity)}</td>
                  <td>{num(l.weight_kg)}</td>
                  <td>{num(l.volume_cbm)}</td>
                  <td>{l.seal_number ?? "—"}</td>
                  <td>
                    {num(l.damage_qty)} / {num(l.loss_qty)} / {num(l.over_qty)}
                  </td>
                  {manage && (draft || actuals) && (
                    <td>
                      <div className="actions">
                        <button onClick={() => setDialog({ line: l })}>
                          {draft ? "Ubah" : "Catat realisasi"}
                        </button>
                        {draft && (
                          <button onClick={() => void remove(l)}>Hapus</button>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <SpmAwbSection
        spmId={s.id}
        status={s.status}
        lines={d.lines}
        awbs={d.awbs}
        onChange={state.refresh}
      />
      <section className="card block">
        <div className="page-heading">
          <h2>Biaya</h2>
          {manage &&
            [
              "PLANNED",
              "LOADING",
              "IN_TRANSIT",
              "DISCHARGED",
              "REOPENED",
            ].includes(s.status) && (
              <button onClick={() => setDialog("charge")}>
                + Biaya tambahan / koreksi
              </button>
            )}
        </div>
        {d.charges.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Sisi</th>
                  <th>Kode biaya</th>
                  <th>Jenis</th>
                  <th>Dasar</th>
                  <th>Jumlah</th>
                  <th>Sumber</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {d.charges.map((c) => (
                  <tr key={c.id}>
                    <td>{c.side === "SELL" ? "Jual" : "Beli"}</td>
                    <td>{c.charge_code_label}</td>
                    <td>{c.kind === "PLAN" ? "Plan" : "Unplan"}</td>
                    <td>
                      {c.basis
                        ? `${num(c.quantity)} ${BASES[c.basis]} × ${money(c.unit_price_minor ?? 0)}`
                        : (c.reason ?? "—")}
                    </td>
                    <td>{money(c.amount_minor)}</td>
                    <td>
                      {c.source === "RATE"
                        ? `${c.rate_label ?? "Tarif"}${c.markup_code ? ` + markup ${c.markup_code}` : ""}`
                        : "Manual"}
                    </td>
                    <td>
                      <Badge value={c.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <th colSpan={4}>Total jual</th>
                  <th>{money(d.totals.sell_minor)}</th>
                  <th colSpan={2} />
                </tr>
                {hasBuy && (
                  <>
                    <tr>
                      <th colSpan={4}>Total beli</th>
                      <th>{money(d.totals.buy_minor ?? 0)}</th>
                      <th colSpan={2} />
                    </tr>
                    <tr>
                      <th colSpan={4}>Margin</th>
                      <th>{money(d.totals.margin_minor ?? 0)}</th>
                      <th colSpan={2} />
                    </tr>
                  </>
                )}
              </tfoot>
            </table>
          </div>
        ) : (
          <Empty>
            Belum ada biaya. Tarif dihitung saat SPM direncanakan; bila tidak
            ada tarif yang berlaku, tambahkan biaya manual dengan alasan.
          </Empty>
        )}
      </section>
      <section className="card block">
        <h2>Riwayat status</h2>
        <ol className="timeline">
          {d.history.map((h) => (
            <li key={h.id}>
              <strong>{SPM_STATUS[h.to_status] ?? h.to_status}</strong> ·{" "}
              {dateTime(h.created_at)} · {h.actor_name}
              {h.reason && <div className="muted">{h.reason}</div>}
            </li>
          ))}
        </ol>
      </section>

      {dialog === "header" && choices && (
        <EditorDialog
          title="Ubah SPM"
          fields={headerFields(choices, s)}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`spm/${s.id}`, body, key, {}, "PUT");
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {(dialog === "line" ||
        (typeof dialog === "object" && dialog && "line" in dialog)) && (
        <EditorDialog
          title={
            dialog === "line"
              ? "Baris muatan baru"
              : draft
                ? "Ubah baris muatan"
                : "Catat realisasi muatan"
          }
          fields={lineFields(
            typeof dialog === "object" && dialog && "line" in dialog
              ? dialog.line
              : undefined,
            !draft,
          )}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            if (typeof dialog === "object" && dialog && "line" in dialog)
              await submitRecord(
                `spm/${s.id}/lines/${dialog.line.id}`,
                body,
                key,
                {},
                "PUT",
              );
            else await submitRecord(`spm/${s.id}/lines`, body, key);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog === "destination" && choices && (
        <EditorDialog
          title="Ganti tujuan"
          fields={[
            {
              name: "destination_location_id",
              label: "Tujuan baru",
              kind: "select",
              options: choices.locations ?? [],
              value: "",
            },
            {
              name: "reason",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            const res = (await submitRecord(
              `spm/${s.id}/destination`,
              body,
              key,
            )) as { data: { warnings: string[] } };
            setNotice(res.data.warnings);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {dialog === "charge" && choices && (
        <EditorDialog
          title="Biaya tambahan / koreksi"
          fields={[
            {
              name: "side",
              label: "Sisi",
              kind: "select",
              options: [
                { value: "SELL", label: "Jual (ditagihkan)" },
                ...(hasBuy ? [{ value: "BUY", label: "Beli (biaya)" }] : []),
              ],
              value: "SELL",
            },
            {
              name: "charge_code_id",
              label: "Kode biaya",
              kind: "select",
              options: choices.charges ?? [],
              value: "",
            },
            {
              name: "amount_minor",
              label: "Jumlah (Rp, boleh negatif untuk koreksi)",
              kind: "signedMoney",
              value: "",
            },
            {
              name: "reason",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            await submitRecord(`spm/${s.id}/charges`, body, key);
            setDialog(null);
            state.refresh();
          }}
        />
      )}
      {pending && typeof dialog === "object" && dialog && "step" in dialog && (
        <>
          {dialog.step[3] ? (
            <EditorDialog
              title={dialog.step[1]}
              fields={[
                {
                  name: "reason",
                  label: "Alasan (minimal 5 karakter)",
                  kind: "textarea",
                  value: "",
                },
              ]}
              onClose={() => setDialog(null)}
              onSubmit={async (body) => {
                const target = dialog.step[0];
                await step(target, String(body.reason));
                setDialog(null);
              }}
            />
          ) : (
            <Confirm
              title={dialog.step[1] + "?"}
              onClose={() => setDialog(null)}
              onConfirm={() => step(dialog.step[0])}
            >
              <p>
                {dialog.step[0] === "PLANNED"
                  ? "Tarif jual dan beli dihitung sekarang dan disimpan sebagai biaya SPM. Perubahan tarif sesudahnya tidak mengubahnya."
                  : "Status SPM akan berubah dan tercatat di riwayat."}
              </p>
            </Confirm>
          )}
        </>
      )}
      <p>
        <button onClick={() => navigate("/spm")}>Kembali ke daftar SPM</button>
      </p>
    </>
  );
}

/** A plain A4 sheet for printing (or saving as PDF from the browser's print dialog). */
export function SpmPrintPage() {
  const { id } = useParams();
  const state = useData<{ data: Detail }>(`spm/${id}`);
  if (state.loading) return <Loading />;
  if (state.error || !state.data)
    return <ErrorBox error={state.error ?? "SPM tidak ditemukan."} />;
  const { spm: s, order, lines } = state.data.data;
  return (
    <article className="print-sheet" aria-label={`SPM ${s.number}`}>
      <div className="actions no-print">
        <button className="primary" onClick={() => window.print()}>
          Cetak / simpan PDF
        </button>
        <Link className="button" to={`/spm/${s.id}`}>
          Kembali
        </Link>
      </div>
      <h1>SURAT PERINTAH MUAT</h1>
      <p>
        <strong>{s.number}</strong> · Order {order.number}
      </p>
      <dl className="details">
        <dt>Pelanggan</dt>
        <dd>{order.customer_name}</dd>
        <dt>Asal</dt>
        <dd>{s.origin_location_label ?? s.origin_text}</dd>
        <dt>Tujuan</dt>
        <dd>{s.destination_location_label ?? s.destination_text}</dd>
        <dt>Armada</dt>
        <dd>
          {s.carrier_type_label ?? "—"} · {s.vendor_label ?? "Armada sendiri"}
        </dd>
        <dt>Rencana muat</dt>
        <dd>{s.planned_pickup_at ? dateTime(s.planned_pickup_at) : "—"}</dd>
        <dt>Referensi</dt>
        <dd>{s.reference ?? "—"}</dd>
      </dl>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Material</th>
            <th>PO / SO</th>
            <th>Jumlah</th>
            <th>Berat (kg)</th>
            <th>Segel</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id}>
              <td>{l.line_no}</td>
              <td>{l.material}</td>
              <td>
                {[l.po_number, l.so_number].filter(Boolean).join(" / ") || "—"}
              </td>
              <td>{num(l.quantity)}</td>
              <td>{num(l.weight_kg)}</td>
              <td>{l.seal_number ?? ""}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="signatures">
        <div>Dispatcher</div>
        <div>Pengemudi</div>
        <div>Penerima</div>
      </div>
    </article>
  );
}
