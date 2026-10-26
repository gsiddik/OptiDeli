import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import {
  Badge,
  Empty,
  ErrorBox,
  Field,
  Form,
  Loading,
  Pagination,
} from "./components";
import { Modal } from "./Cash";
import { dateTime, money } from "./commercial";
import type { Page } from "./types";

type ReadyCharge = {
  id: string;
  charge_code: string;
  name: string;
  kind: "PLAN" | "UNPLAN";
  amount_minor: number;
};
type Ready = {
  type: "SPM" | "CONTRACT";
  spm_id: string | null;
  spm_number: string;
  customer_id: string;
  customer_label: string | null;
  project_id: string | null;
  project_label: string | null;
  route: string;
  total_minor: number;
  charges: ReadyCharge[];
};
type Batch = {
  id: string;
  number: string;
  status: "ISSUED" | "CANCELLED";
  customer_label: string | null;
  template_label: string | null;
  invoice_count: number;
  total_minor: number;
  created_at: string;
  cancel_reason?: string | null;
};
type Doc = {
  id: string;
  number: string;
  status: string;
  amount_minor: number;
  subtotal_minor: number | null;
  tax_minor: number;
  issue_date: string;
  due_date: string;
  counterparty_name: string;
};
type Opt = { value: string; label: string };

const TEMPLATE_HINT: Record<string, string> = {
  PER_SPM: "satu invoice per SPM",
  PER_PROJECT: "satu invoice per proyek",
  PER_PERIOD: "satu invoice untuk semua yang dipilih",
};

/** Ready to bill (verified selling charges not on an invoice), billing runs, and the settings that matter for tax. */
export function BillingPage() {
  const [tab, setTab] = useState<"ready" | "batches">("ready");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PENAGIHAN</span>
          <h1 tabIndex={-1}>Penagihan</h1>
          <p>
            Biaya jual yang sudah diverifikasi ditagihkan menurut template
            invoice pelanggan; invoice masuk ke sub-ledger dan dikirim ke
            akuntansi lewat feed.
          </p>
        </div>
      </div>
      <div className="tabs" role="group" aria-label="Penagihan">
        <button aria-pressed={tab === "ready"} onClick={() => setTab("ready")}>
          Siap tagih
        </button>
        <button
          aria-pressed={tab === "batches"}
          onClick={() => setTab("batches")}
        >
          Riwayat penagihan
        </button>
      </div>
      {tab === "ready" ? <ReadyPanel /> : <BatchPanel />}
    </>
  );
}

function ReadyPanel() {
  const navigate = useNavigate();
  const [customer, setCustomer] = useState("");
  const [customers, setCustomers] = useState<Opt[]>([]);
  const [templates, setTemplates] = useState<Opt[]>([]);
  const state = useData<{ data: Ready[] }>(
    `billing/ready?customer_id=${customer}`,
  );
  const [picked, setPicked] = useState<string[]>([]);
  const [issuing, setIssuing] = useState(false);
  const [template, setTemplate] = useState("");
  const [due, setDue] = useState("");
  const [result, setResult] = useState<string>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    Promise.all([
      api<Rows>("master/customers/options"),
      api<Rows>("master/invoice_templates/options"),
    ])
      .then(([c, t]) => {
        if (!live) return;
        const map = (r: Rows) =>
          r.data.map((o) => ({ value: o.id, label: `${o.code} · ${o.name}` }));
        setCustomers(map(c));
        setTemplates(map(t));
      })
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);
  const rows = state.data?.data ?? [];
  const keyOf = (r: Ready) => r.spm_id ?? `k:${r.charges[0].id}`;
  const chosen = rows.filter((r) => picked.includes(keyOf(r)));
  const customerIds = [...new Set(chosen.map((r) => r.customer_id))];
  const toggle = (id: string) =>
    setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
  return (
    <>
      <div className="actions">
        <select
          aria-label="Pelanggan"
          value={customer}
          onChange={(e) => {
            setCustomer(e.target.value);
            setPicked([]);
          }}
        >
          <option value="">Semua pelanggan</option>
          {customers.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <button
          className="primary"
          disabled={!chosen.length || customerIds.length !== 1}
          title={
            customerIds.length > 1
              ? "Satu penagihan untuk satu pelanggan."
              : undefined
          }
          onClick={() => setIssuing(true)}
        >
          Terbitkan invoice ({chosen.length} SPM)
        </button>
      </div>
      {result && (
        <div className="alert success" role="status">
          {result}
        </div>
      )}
      <ErrorBox error={state.error} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : rows.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Pilih</th>
                  <th>SPM</th>
                  <th>Pelanggan / proyek</th>
                  <th>Rute</th>
                  <th>Rincian</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={keyOf(r)}>
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Pilih ${r.spm_number}`}
                        checked={picked.includes(keyOf(r))}
                        onChange={() => toggle(keyOf(r))}
                      />
                    </td>
                    <td>
                      {r.spm_id ? (
                        <Link to={`/spm/${r.spm_id}`}>{r.spm_number}</Link>
                      ) : (
                        r.spm_number
                      )}
                    </td>
                    <td>
                      {r.customer_label}
                      {r.project_label && ` / ${r.project_label}`}
                    </td>
                    <td>{r.route}</td>
                    <td>
                      {r.charges
                        .map(
                          (c) =>
                            `${c.charge_code} ${money(c.amount_minor)}${c.kind === "UNPLAN" ? " (Unplan)" : ""}`,
                        )
                        .join(", ")}
                    </td>
                    <td>{money(r.total_minor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>
            Belum ada biaya jual terverifikasi yang siap ditagih. Verifikasi
            biaya di halaman SPM yang sudah selesai bongkar.
          </Empty>
        )}
      </section>
      {issuing && (
        <Modal title="Terbitkan invoice" onClose={() => setIssuing(false)}>
          <Form
            label="Terbitkan"
            onSubmit={async () => {
              const res = await api<{
                data: { batch: Batch; invoices: Doc[] };
              }>("billing/batches", "POST", {
                customer_id: customerIds[0],
                spm_ids: chosen.filter((r) => r.spm_id).map((r) => r.spm_id),
                contract_charge_ids: chosen
                  .filter((r) => !r.spm_id)
                  .flatMap((r) => r.charges.map((c) => c.id)),
                template_id: template || null,
                due_date: due || null,
              });
              setIssuing(false);
              setPicked([]);
              setResult(
                `Penagihan ${res.data.batch.number}: ${res.data.invoices.length} invoice, total ${money(res.data.batch.total_minor)}.`,
              );
              state.refresh();
              if (res.data.invoices.length === 1)
                navigate(`/billing/invoices/${res.data.invoices[0].id}`);
            }}
          >
            <p>
              {chosen.length} SPM, total{" "}
              {money(chosen.reduce((s, r) => s + r.total_minor, 0))}.
            </p>
            <Field
              label="Template invoice"
              hint="Kosong = template proyek, lalu pelanggan, lalu standar."
            >
              <select
                value={template}
                onChange={(e) => setTemplate(e.target.value)}
              >
                <option value="">Ikuti pengaturan pelanggan</option>
                {templates.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Jatuh tempo (opsional)">
              <input
                type="date"
                value={due}
                onChange={(e) => setDue(e.target.value)}
              />
            </Field>
            <small className="muted">
              Pengelompokan mengikuti template (
              {Object.values(TEMPLATE_HINT).join("; ")}).
            </small>
          </Form>
        </Modal>
      )}
    </>
  );
}

function BatchPanel() {
  const [page, setPage] = useState(1);
  const state = useData<Page<Batch>>(`billing/batches?page=${page}`);
  const [open, setOpen] = useState<string>();
  return (
    <>
      <ErrorBox error={state.error} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : state.data?.data.length ? (
          <>
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Penagihan</th>
                    <th>Pelanggan</th>
                    <th>Template</th>
                    <th>Invoice</th>
                    <th>Total</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {state.data.data.map((b) => (
                    <tr key={b.id}>
                      <td>
                        <button onClick={() => setOpen(b.id)}>
                          {b.number}
                        </button>
                        <br />
                        <small className="muted">
                          {dateTime(b.created_at)}
                        </small>
                      </td>
                      <td>{b.customer_label}</td>
                      <td>{b.template_label}</td>
                      <td>{b.invoice_count}</td>
                      <td>{money(b.total_minor)}</td>
                      <td>
                        <Badge value={b.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={state.data} onPage={setPage} />
          </>
        ) : (
          <Empty>Belum ada penagihan.</Empty>
        )}
      </section>
      {open && (
        <BatchDialog
          id={open}
          onClose={() => setOpen(undefined)}
          onChanged={state.refresh}
        />
      )}
    </>
  );
}

function BatchDialog({
  id,
  onClose,
  onChanged,
}: {
  id: string;
  onClose: () => void;
  onChanged: () => void;
}) {
  const state = useData<{ data: { batch: Batch; invoices: Doc[] } }>(
    `billing/batches/${id}`,
  );
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");
  const d = state.data?.data;
  return (
    <Modal
      title={d ? `Penagihan ${d.batch.number}` : "Penagihan"}
      onClose={onClose}
    >
      <ErrorBox error={state.error} />
      {!d ? (
        <Loading />
      ) : (
        <>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Jatuh tempo</th>
                  <th>Jumlah</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {d.invoices.map((i) => (
                  <tr key={i.id}>
                    <td>
                      <Link to={`/billing/invoices/${i.id}`}>{i.number}</Link>
                    </td>
                    <td>{i.due_date}</td>
                    <td>{money(i.amount_minor)}</td>
                    <td>
                      <Badge value={i.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {d.batch.status === "ISSUED" &&
            (cancelling ? (
              <Form
                label="Batalkan penagihan"
                onSubmit={async () => {
                  await api(`billing/batches/${id}/cancel`, "POST", { reason });
                  setCancelling(false);
                  state.refresh();
                  onChanged();
                }}
              >
                <Field label="Alasan (minimal 5 karakter)">
                  <textarea
                    required
                    minLength={5}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                </Field>
              </Form>
            ) : (
              <button onClick={() => setCancelling(true)}>
                Batalkan penagihan
              </button>
            ))}
          {d.batch.cancel_reason && <p>Dibatalkan: {d.batch.cancel_reason}</p>}
        </>
      )}
    </Modal>
  );
}

type InvoiceData = {
  document: Doc & { void_reason: string | null; paid_minor: number };
  lines: {
    id: string;
    spm_number: string | null;
    order_number: string | null;
    route: string | null;
    date: string | null;
    description: string;
    quantity: string | null;
    unit_price_minor: number | null;
    amount_minor: number;
    kind: string;
  }[];
  customer: {
    name: string;
    tax_id: string | null;
    address: string | null;
  } | null;
  batch_number: string | null;
  columns: string[];
  footer: string | null;
  template: string | null;
};
const COLUMN: Record<string, string> = {
  spm: "SPM",
  order: "Order",
  route: "Rute",
  date: "Tanggal",
  description: "Uraian",
  qty: "Jumlah",
  unit_price: "Harga satuan",
  amount: "Jumlah (Rp)",
};

/** The invoice as the customer's template lays it out; printable. */
export function InvoicePage() {
  const { id } = useParams();
  const state = useData<{ data: InvoiceData }>(`billing/invoices/${id}`);
  if (state.loading) return <Loading />;
  if (state.error || !state.data)
    return <ErrorBox error={state.error ?? "Invoice tidak ditemukan."} />;
  const { document: doc, lines, customer, columns, footer } = state.data.data;
  const cell = (key: string, l: InvoiceData["lines"][number]) => {
    switch (key) {
      case "spm":
        return l.spm_number;
      case "order":
        return l.order_number;
      case "route":
        return l.route;
      case "date":
        return l.date;
      case "description":
        return l.description + (l.kind === "UNPLAN" ? " (di luar tarif)" : "");
      case "qty":
        return l.quantity ? Number(l.quantity).toLocaleString("id-ID") : "";
      case "unit_price":
        return l.unit_price_minor === null ? "" : money(l.unit_price_minor);
      default:
        return money(l.amount_minor);
    }
  };
  return (
    <article className="print-sheet" aria-label={`Invoice ${doc.number}`}>
      <div className="actions no-print">
        <button className="primary" onClick={() => window.print()}>
          Cetak / simpan PDF
        </button>
        <Link className="button" to="/billing">
          Kembali
        </Link>
      </div>
      <h1>INVOICE {doc.number}</h1>
      <dl className="details">
        <dt>Kepada</dt>
        <dd>
          {customer?.name ?? doc.counterparty_name}
          {customer?.tax_id && ` · NPWP ${customer.tax_id}`}
        </dd>
        <dt>Tanggal terbit</dt>
        <dd>{doc.issue_date}</dd>
        <dt>Jatuh tempo</dt>
        <dd>{doc.due_date}</dd>
        <dt>Status</dt>
        <dd>
          <Badge value={doc.status} />
        </dd>
      </dl>
      <table>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c}>{COLUMN[c] ?? c}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id}>
              {columns.map((c) => (
                <td key={c}>{cell(c, l)}</td>
              ))}
            </tr>
          ))}
        </tbody>
        <tfoot>
          {doc.tax_minor > 0 && (
            <>
              <tr>
                <th colSpan={columns.length - 1}>Subtotal</th>
                <th>{money(doc.subtotal_minor ?? 0)}</th>
              </tr>
              <tr>
                <th colSpan={columns.length - 1}>PPN</th>
                <th>{money(doc.tax_minor)}</th>
              </tr>
            </>
          )}
          <tr>
            <th colSpan={columns.length - 1}>Total</th>
            <th>{money(doc.amount_minor)}</th>
          </tr>
        </tfoot>
      </table>
      {footer && <p>{footer}</p>}
    </article>
  );
}
