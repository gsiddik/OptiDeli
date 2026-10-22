import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading, Pagination } from "./components";
import { Action, noteField } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import { money } from "./commercial";
import type { Page } from "./types";
import type { Invoice, Trip } from "./opsTypes";
import { canFinance } from "./opsTypes";
export const invoiceFields: EditorField[] = [
  { name: "number", label: "Nomor invoice vendor" },
  { name: "base_minor", label: "Tarif dasar invoice (Rp)", kind: "money" },
  {
    name: "accessorial_minor",
    label: "Aksesorial invoice (Rp)",
    kind: "money",
    value: "0",
  },
  {
    name: "rounding_minor",
    label: "Pembulatan/tol/admin (Rp; boleh negatif)",
    kind: "signedMoney",
    value: "0",
  },
  { name: "document_reference", label: "Referensi dokumen invoice" },
  {
    name: "file",
    label: "Berkas invoice PDF/JPEG/PNG (maks. 10 MB)",
    kind: "file",
  },
];
export function FreightPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const state = useData<Page<Invoice>>(
    `freight/invoices?page=${page}&status=${status}`,
  );
  const [tripQuery, setTripQuery] = useState("");
  const [tripDraft, setTripDraft] = useState("");
  const trips = useData<Page<Trip>>(`trips?q=${encodeURIComponent(tripQuery)}`);
  const session = useSession();
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">FINANCE / FREIGHT AUDIT</span>
          <h1 tabIndex={-1}>Antrean audit & discrepancy</h1>
          <p>
            Cocokkan kontrak, ePOD dan invoice. Settlement ditahan sampai bukti
            dan otorisasi lengkap.
          </p>
        </div>
        {canFinance(session.role) && (
          <Action
            label="Catat invoice vendor"
            path="freight/invoices"
            fields={[
              {
                name: "trip_id",
                label: "Trip / kontrak",
                kind: "select",
                options: trips.data?.data
                  .filter((t) => t.vendor_id && t.status !== "CANCELLED")
                  .map((t) => ({
                    value: t.id,
                    label: `${t.id.slice(0, 8)} · ${t.contract_reference}`,
                  })),
              },
              ...invoiceFields,
            ]}
            onDone={state.refresh}
            primary
          />
        )}
      </div>
      <form
        className="filters"
        onSubmit={(e) => {
          e.preventDefault();
          setTripQuery(tripDraft);
        }}
      >
        <label className="field">
          Cari trip untuk pilihan formulir (ID / kontrak)
          <input
            value={tripDraft}
            maxLength={100}
            onChange={(e) => setTripDraft(e.target.value)}
            placeholder="Ketik ID atau referensi kontrak"
          />
          <small>
            Formulir menampilkan 25 hasil terbaru; cari untuk memilih trip
            lainnya.
          </small>
        </label>
        <button type="submit">Cari trip</button>
      </form>
      <div className="alert info">
        Tarif dasar: toleransi Rp0. Admin/pembulatan ≤Rp10.000: Finance.
        Aksesorial valid/sedang sampai Rp2 juta: dual approval. Di atas Rp2 juta
        atau margin &lt;5%: CFO dengan resolusi dispute. Status siap bayar bukan
        transaksi transfer dana.
      </div>
      <section className="card">
        <div className="filters">
          <label className="field">
            Status
            <select
              value={status}
              onChange={(e) => {
                setPage(1);
                setStatus(e.target.value);
              }}
            >
              <option value="">Semua status</option>
              {["ON_HOLD", "READY_FOR_PAYMENT", "VOID"].map((s) => (
                <option key={s}>{s}</option>
              ))}
            </select>
          </label>
          <button onClick={state.refresh}>Muat ulang</button>
        </div>
        <ErrorBox error={state.error} />
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Invoice</th>
                      <th>Nilai</th>
                      <th>Otorisasi</th>
                      <th>Status / alasan</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((i) => (
                      <tr key={i.id}>
                        <td>
                          <Link to={`/freight/${i.id}`}>{i.number}</Link>
                          <small>
                            Versi {i.version} · trip {i.trip_id.slice(0, 8)}
                          </small>
                        </td>
                        <td>{money(i.total_minor)}</td>
                        <td>{i.approval_tier}</td>
                        <td>
                          <Badge value={i.status} />
                          <small>{i.hold_reason}</small>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>Belum ada invoice pada antrean ini.</Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
    </>
  );
}
export function FreightDetail() {
  const { id } = useParams();
  const state = useData<{ data: Invoice }>(`freight/invoices/${id}`);
  const session = useSession();
  if (state.loading) return <Loading />;
  if (state.error) return <ErrorBox error={state.error} />;
  const i = state.data!.data;
  const eligible =
    (i.approval_tier === "FINANCE" &&
      ["finance", "finance_supervisor"].includes(session.role)) ||
    (i.approval_tier === "DUAL" &&
      ["finance_supervisor", "supervisor", "head_of_logistics"].includes(
        session.role,
      )) ||
    (i.approval_tier === "CFO" && session.role === "cfo");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">FREIGHT AUDIT / INVOICE</span>
          <h1 tabIndex={-1}>{i.number}</h1>
          <p>
            Versi bukti {i.version} · {i.approval_tier}
          </p>
        </div>
        <Badge value={i.status} />
      </div>
      <div className="split">
        <section className="card">
          <h2>3-way matching</h2>
          <dl className="details">
            <dt>Trip / ePOD</dt>
            <dd>
              <Link to={`/trips/${i.trip_id}`}>Lihat perjalanan & bukti</Link>
            </dd>
            <dt>Dasar kontrak</dt>
            <dd>{money(i.plan?.vendor_base_minor ?? 0)}</dd>
            <dt>Dasar invoice</dt>
            <dd>{money(i.base_minor)}</dd>
            <dt>Aksesorial awal</dt>
            <dd>{money(i.plan?.vendor_accessorial_minor ?? 0)}</dd>
            <dt>Aksesorial invoice</dt>
            <dd>{money(i.accessorial_minor)}</dd>
            <dt>Pembulatan/admin</dt>
            <dd>{money(i.rounding_minor)}</dd>
            <dt>Total tagihan</dt>
            <dd>
              <strong>{money(i.total_minor)}</strong>
            </dd>
          </dl>
          <a className="block-small" href={`/api/v1/files/invoices/${i.id}`}>
            Unduh invoice privat
          </a>
        </section>
        <section className="card">
          <h2>Kontrol settlement</h2>
          <div
            className={`alert ${i.status === "READY_FOR_PAYMENT" ? "success" : "warning"}`}
          >
            {i.hold_reason}
          </div>
          <div className="actions">
            {eligible &&
              i.hold_reason === "APPROVAL_REQUIRED" &&
              i.submitted_by !== session.id && (
                <Action
                  label="Setujui sesuai otoritas"
                  path={`freight/invoices/${i.id}/approve`}
                  fixed={{ version: i.version }}
                  fields={[noteField]}
                  onDone={state.refresh}
                  primary
                />
              )}
            {canFinance(session.role) && i.status !== "VOID" && (
              <Action
                label="Create dispute log"
                path={`freight/invoices/${i.id}/disputes`}
                fields={[
                  {
                    name: "message",
                    label: "Sanggahan resmi untuk vendor",
                    kind: "textarea",
                  },
                ]}
                onDone={state.refresh}
              />
            )}
          </div>
          <p className="muted block">
            Approver terikat pada versi bukti. Dual approval memerlukan dua
            orang dengan otoritas berbeda. Penginput invoice tidak dapat
            menyetujui inputnya sendiri.
          </p>
          {i.approvals?.map((a) => (
            <p key={a.authority}>
              <Badge value={a.authority} /> · Actor {a.actor_id}
              <small>{a.note}</small>
            </p>
          ))}
        </section>
      </div>
      <section className="card block">
        <h2>Klaim & validasi aksesorial</h2>
        {i.claims?.map((c) => (
          <article key={c.id} className="ops-record">
            <div>
              <strong>
                {c.category} · {money(c.amount_minor)}
              </strong>
              <p>{c.reason}</p>
              <small>{c.evidence_reference}</small>
              <Badge value={c.status} />
            </div>
            {canFinance(session.role) &&
              c.status === "SUBMITTED" &&
              c.submitted_by !== session.id && (
                <Action
                  label="Verifikasi bukti klaim"
                  path={`freight/claims/${c.id}/verify`}
                  fields={[
                    {
                      name: "decision",
                      label: "Keputusan",
                      kind: "select",
                      options: [
                        { value: "verify", label: "Bukti valid" },
                        { value: "reject", label: "Tolak" },
                      ],
                    },
                    noteField,
                  ]}
                  onDone={state.refresh}
                />
              )}
          </article>
        ))}
        {!i.claims?.length && (
          <Empty>Belum ada klaim aksesorial tambahan.</Empty>
        )}
      </section>
      <section className="card block">
        <h2>Dispute & jawaban vendor</h2>
        {i.disputes?.map((d) => (
          <article className="ops-record" key={d.id}>
            <div>
              <Badge value={d.status} />
              <p>{d.message}</p>
              {d.vendor_response && (
                <p>
                  <strong>Vendor:</strong> {d.vendor_response}
                </p>
              )}
              {d.resolution && (
                <p>
                  <strong>Resolusi:</strong> {d.resolution}
                </p>
              )}
            </div>
            {canFinance(session.role) &&
              d.status !== "RESOLVED" &&
              !d.system_key?.startsWith("base:") &&
              (i.approval_tier !== "CFO" || session.role === "cfo") && (
                <Action
                  label="Resolusi dispute"
                  path={`freight/disputes/${d.id}/action`}
                  fixed={{ action: "resolve" }}
                  fields={[noteField]}
                  onDone={state.refresh}
                />
              )}
          </article>
        ))}
        {!i.disputes?.length && (
          <Empty>Tidak ada dispute pada invoice ini.</Empty>
        )}
        <p className="muted">
          Sanggahan dipublikasikan ke portal vendor terkait, bersama akses ePOD
          trip tersebut. Tidak ada email atau notifikasi eksternal yang dikirim
          dari increment ini.
        </p>
      </section>
    </>
  );
}
