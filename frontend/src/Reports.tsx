import { useState } from "react";
import { useData } from "./api";
import { useSession } from "./App";
import { Empty, ErrorBox, Loading } from "./components";
import { money } from "./commercial";
import { can } from "./opsTypes";

type Catalog = {
  key: string;
  name: string;
  description: string;
  permission: string;
  dimensions?: Record<string, string>;
}[];
type PnlRow = {
  key: string;
  label: string;
  spm_count: number;
  sell_minor: number;
  buy_minor: number;
  margin_minor: number;
  margin_bps: number | null;
  sell_plan_minor: number;
  sell_unplan_minor: number;
  unverified_sell_minor: number;
  unverified_buy_minor: number;
};
type Pnl = {
  rows: PnlRow[];
  totals: {
    sell_minor: number;
    buy_minor: number;
    margin_minor: number;
    margin_bps: number | null;
    unverified_sell_minor: number;
    unverified_buy_minor: number;
  };
};
type Aging = {
  customer: string;
  current: number;
  d1_30: number;
  d31_60: number;
  d61_90: number;
  d90_plus: number;
  total: number;
  documents: number;
}[];

const pct = (bps: number | null) =>
  bps === null
    ? "—"
    : `${(bps / 100).toLocaleString("id-ID", { maximumFractionDigits: 2 })}%`;

/** The catalogue of management reports the user may open, with the contribution P&L and the receivables aging. */
export function ReportsPage() {
  const catalog = useData<{ data: Catalog }>("reports");
  const [open, setOpen] = useState<string>();
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">LAPORAN</span>
          <h1 tabIndex={-1}>Laporan</h1>
          <p>
            Laporan manajemen dari biaya SPM yang terverifikasi dan sub-ledger.
            Ini bukan laporan laba rugi buku besar; itu ada di akuntansi.
          </p>
        </div>
      </div>
      <ErrorBox error={catalog.error} />
      {catalog.loading && <Loading />}
      <div className="stats">
        {catalog.data?.data.map((c) => (
          <button
            key={c.key}
            className="card stat"
            aria-pressed={open === c.key}
            onClick={() => setOpen(c.key)}
          >
            <strong>{c.name}</strong>
            <span>{c.description}</span>
          </button>
        ))}
      </div>
      {open === "pnl" && <PnlReport />}
      {open === "ar_aging" && <AgingReport />}
      {open === "spm_status" && <StatusReport />}
      {open === "ready_to_bill" && (
        <p>
          Daftar siap tagih ada di menu <a href="/billing">Penagihan</a>.
        </p>
      )}
    </>
  );
}

function PnlReport() {
  const session = useSession();
  const [by, setBy] = useState("customer");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const query = `group_by=${by}${from ? `&from=${from}` : ""}${to ? `&to=${to}` : ""}`;
  const state = useData<{ data: Pnl }>(`reports/pnl?${query}`);
  const d = state.data?.data;
  return (
    <section className="card block" aria-label="P&L kontribusi">
      <h2>P&amp;L kontribusi</h2>
      <div className="actions">
        <select
          aria-label="Per"
          value={by}
          onChange={(e) => setBy(e.target.value)}
        >
          <option value="customer">Pelanggan</option>
          <option value="project">Proyek</option>
          <option value="spm">SPM</option>
          <option value="vehicle">Kendaraan</option>
          <option value="vendor">Vendor</option>
          <option value="lane">Rute</option>
        </select>
        <input
          type="date"
          aria-label="Dari"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
        />
        <input
          type="date"
          aria-label="Sampai"
          value={to}
          onChange={(e) => setTo(e.target.value)}
        />
        {can(session, "report.pnl.view") && (
          <>
            <a href={`/api/v1/reports/pnl?${query}&format=xlsx`}>
              Ekspor Excel
            </a>
            <a href={`/api/v1/reports/pnl?${query}&format=csv`}>Ekspor CSV</a>
          </>
        )}
      </div>
      <ErrorBox error={state.error} />
      {state.loading ? (
        <Loading />
      ) : d && d.rows.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>SPM</th>
                <th>Jual</th>
                <th>Beli</th>
                <th>Margin</th>
                <th>%</th>
                <th>Belum diverifikasi (jual / beli)</th>
              </tr>
            </thead>
            <tbody>
              {d.rows.map((r) => (
                <tr key={r.key}>
                  <td>{r.label}</td>
                  <td>{r.spm_count}</td>
                  <td>{money(r.sell_minor)}</td>
                  <td>{money(r.buy_minor)}</td>
                  <td>{money(r.margin_minor)}</td>
                  <td>{pct(r.margin_bps)}</td>
                  <td>
                    {money(r.unverified_sell_minor)} /{" "}
                    {money(r.unverified_buy_minor)}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <th colSpan={2}>Total</th>
                <th>{money(d.totals.sell_minor)}</th>
                <th>{money(d.totals.buy_minor)}</th>
                <th>{money(d.totals.margin_minor)}</th>
                <th>{pct(d.totals.margin_bps)}</th>
                <th>
                  {money(d.totals.unverified_sell_minor)} /{" "}
                  {money(d.totals.unverified_buy_minor)}
                </th>
              </tr>
            </tfoot>
          </table>
        </div>
      ) : (
        <Empty>Belum ada biaya terverifikasi pada periode ini.</Empty>
      )}
    </section>
  );
}

function AgingReport() {
  const state = useData<{ data: Aging }>("reports/ar-aging");
  return (
    <section className="card block" aria-label="Umur piutang">
      <h2>Umur piutang</h2>
      <ErrorBox error={state.error} />
      {state.loading ? (
        <Loading />
      ) : state.data?.data.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Pelanggan</th>
                <th>Belum jatuh tempo</th>
                <th>1–30</th>
                <th>31–60</th>
                <th>61–90</th>
                <th>&gt; 90</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              {state.data.data.map((r) => (
                <tr key={r.customer}>
                  <td>{r.customer}</td>
                  <td>{money(r.current)}</td>
                  <td>{money(r.d1_30)}</td>
                  <td>{money(r.d31_60)}</td>
                  <td>{money(r.d61_90)}</td>
                  <td>{money(r.d90_plus)}</td>
                  <td>{money(r.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Tidak ada piutang terbuka.</Empty>
      )}
    </section>
  );
}

function StatusReport() {
  const state = useData<{
    data: {
      statuses: Record<string, number>;
      charges_waiting: { sell: number; buy: number };
    };
  }>("reports/spm-status");
  const d = state.data?.data;
  return (
    <section className="card block" aria-label="Status SPM">
      <h2>Status SPM</h2>
      <ErrorBox error={state.error} />
      {state.loading ? (
        <Loading />
      ) : (
        d && (
          <>
            <dl className="details">
              {Object.entries(d.statuses).map(([k, v]) => (
                <div key={k}>
                  <dt>{k}</dt>
                  <dd>{v}</dd>
                </div>
              ))}
            </dl>
            <p>
              Biaya menunggu verifikasi: jual {d.charges_waiting.sell}, beli{" "}
              {d.charges_waiting.buy}.
            </p>
          </>
        )
      )}
    </section>
  );
}
