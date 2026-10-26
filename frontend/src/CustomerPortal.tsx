import { useState } from "react";
import { useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { money } from "./commercial";

type Summary = {
  customer: { code: string; name: string };
  open_spm: number;
  unpaid_invoices: number;
  outstanding_minor: number;
};
type SpmRow = {
  id: string;
  number: string;
  status: string;
  origin_text: string;
  destination_text: string;
  reference: string | null;
  planned_delivery_at: string | null;
  awbs: { number: string; status: string }[];
};
type SpmDetail = {
  spm: SpmRow;
  awbs: {
    id: string;
    number: string;
    status: string;
    destination_text: string;
    consignee_name: string | null;
    delivered_at: string | null;
    lines: { material: string; quantity: string; po_number: string | null }[];
  }[];
  history: { to_status: string; created_at: string }[];
};
type InvoiceRow = {
  id: string;
  number: string;
  issue_date: string;
  due_date: string;
  status: string;
  amount_minor: number;
  paid_minor: number;
};
type InvoiceDetail = {
  invoice: InvoiceRow;
  lines: {
    description: string;
    amount_minor: number;
    spm_number: string | null;
  }[];
};

const day = (v: string | null) => (v ? v.slice(0, 10) : "–");

function SpmDetailView({ id }: { id: string }) {
  const state = useData<{ data: SpmDetail }>(`customer/spm/${id}`);
  if (state.loading) return <Loading />;
  const d = state.data?.data;
  if (!d) return <ErrorBox error={state.error} />;
  return (
    <div className="block">
      <h3>AWB</h3>
      {d.awbs.map((a) => (
        <p key={a.id}>
          <strong>{a.number}</strong> <Badge value={a.status} /> ·{" "}
          {a.destination_text}
          {a.consignee_name && ` · ${a.consignee_name}`}
          {a.delivered_at && ` · diterima ${day(a.delivered_at)}`}
          <br />
          <small className="muted">
            {a.lines.map((l) => `${l.material} × ${l.quantity}`).join("; ")}
          </small>
        </p>
      ))}
      <h3>Riwayat status</h3>
      <ol>
        {d.history.map((h) => (
          <li key={h.created_at + h.to_status}>
            {h.to_status} · {h.created_at.slice(0, 16).replace("T", " ")}
          </li>
        ))}
      </ol>
    </div>
  );
}

function Shipments() {
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState<string>();
  const state = useData<{ data: SpmRow[] }>(
    `customer/spm?search=${encodeURIComponent(search)}`,
  );
  return (
    <section className="card">
      <div className="section-heading">
        <h2>Pengiriman</h2>
        <input
          type="search"
          aria-label="Cari SPM, AWB, atau referensi"
          placeholder="Cari SPM / AWB / referensi"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>
      <ErrorBox error={state.error} />
      {state.loading ? (
        <Loading />
      ) : state.data?.data.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>SPM</th>
                <th>Rute</th>
                <th>AWB</th>
                <th>Rencana tiba</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {state.data.data.map((s) => (
                <tr key={s.id}>
                  <td>
                    <button
                      aria-expanded={open === s.id}
                      onClick={() => setOpen(open === s.id ? undefined : s.id)}
                    >
                      {s.number}
                    </button>
                    {open === s.id && <SpmDetailView id={s.id} />}
                    {s.reference && (
                      <>
                        <br />
                        <small className="muted">{s.reference}</small>
                      </>
                    )}
                  </td>
                  <td>
                    {s.origin_text} → {s.destination_text}
                  </td>
                  <td>{s.awbs.map((a) => a.number).join(", ")}</td>
                  <td>{day(s.planned_delivery_at)}</td>
                  <td>
                    <Badge value={s.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada pengiriman.</Empty>
      )}
    </section>
  );
}

function InvoiceDetailView({ id }: { id: string }) {
  const state = useData<{ data: InvoiceDetail }>(`customer/invoices/${id}`);
  if (state.loading) return <Loading />;
  const d = state.data?.data;
  if (!d) return <ErrorBox error={state.error} />;
  return (
    <ul className="block">
      {d.lines.map((l, i) => (
        <li key={i}>
          {l.spm_number && `${l.spm_number} · `}
          {l.description} — {money(l.amount_minor)}
        </li>
      ))}
    </ul>
  );
}

function Invoices() {
  const [open, setOpen] = useState<string>();
  const state = useData<{ data: InvoiceRow[] }>("customer/invoices");
  return (
    <section className="card block">
      <h2>Invoice</h2>
      <ErrorBox error={state.error} />
      {state.loading ? (
        <Loading />
      ) : state.data?.data.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Terbit</th>
                <th>Jatuh tempo</th>
                <th>Nilai</th>
                <th>Terbayar</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {state.data.data.map((i) => (
                <tr key={i.id}>
                  <td>
                    <button
                      aria-expanded={open === i.id}
                      onClick={() => setOpen(open === i.id ? undefined : i.id)}
                    >
                      {i.number}
                    </button>
                    {open === i.id && <InvoiceDetailView id={i.id} />}
                  </td>
                  <td>{day(i.issue_date)}</td>
                  <td>{day(i.due_date)}</td>
                  <td>{money(i.amount_minor)}</td>
                  <td>{money(i.paid_minor)}</td>
                  <td>
                    <Badge value={i.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada invoice.</Empty>
      )}
    </section>
  );
}

/** What a customer sees after signing in: its own shipments and invoices, nothing else. */
export function CustomerPortal({
  onLogout,
}: {
  onLogout: () => Promise<void>;
}) {
  const session = useSession();
  const [error, setError] = useState<unknown>();
  const state = useData<{ data: Summary }>("customer/portal");
  const d = state.data?.data;
  return (
    <div className="portal">
      <header>
        <div className="brand-image">
          <img src="/brand/Logo%20OptiDeli%20-%201.jpg" alt="OptiDeli" />
        </div>
        <div>
          <strong>{d?.customer.name ?? "Portal Pelanggan"}</strong>
          <small>
            {session.name} · {session.tenant.name}
          </small>
        </div>
        <button
          onClick={() => {
            void onLogout().catch(setError);
          }}
        >
          Keluar
        </button>
      </header>
      <main>
        <div className="page-heading block">
          <div>
            <span className="eyebrow">PORTAL PELANGGAN</span>
            <h1 tabIndex={-1}>Pengiriman & invoice</h1>
            <p>Hanya pengiriman dan tagihan perusahaan Anda yang tampil.</p>
          </div>
          <button onClick={state.refresh}>Muat ulang</button>
        </div>
        <ErrorBox error={error ?? state.error} />
        {d && (
          <p>
            {d.open_spm} pengiriman berjalan · {d.unpaid_invoices} invoice belum
            lunas · sisa tagihan {money(d.outstanding_minor)}
          </p>
        )}
        <Shipments />
        <Invoices />
      </main>
    </div>
  );
}
