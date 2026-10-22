import { useState } from "react";
import { useData } from "./api";
import { useSession } from "./App";
import { Action, noteField } from "./RecordEditor";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { invoiceFields } from "./Freight";
import { claimFields } from "./Trips";
import { money } from "./commercial";
import type { Vendor, Trip, Invoice, Dispute, Pod } from "./opsTypes";
export function VendorPortal({ onLogout }: { onLogout: () => Promise<void> }) {
  const session = useSession();
  const state = useData<{
    data: {
      vendor: Vendor;
      trips: Trip[];
      invoices: Invoice[];
      disputes: Dispute[];
      pods: Pod[];
    };
  }>("vendor/portal");
  const [error, setError] = useState<unknown>();
  const d = state.data?.data;
  return (
    <div className="portal">
      <header>
        <div className="brand-image">
          <img src="/brand/Logo%20OptiDeli%20-%201.jpg" alt="OptiDeli" />
        </div>
        <div>
          <strong>{d?.vendor.name ?? "Portal Vendor"}</strong>
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
        <nav className="breadcrumb" aria-label="Breadcrumb">
          Portal vendor / Invoice & dispute
        </nav>
        <div className="page-heading block">
          <div>
            <span className="eyebrow">VENDOR SELF-SERVICE</span>
            <h1 tabIndex={-1}>Invoice & sanggahan</h1>
            <p>Hanya perjalanan dan dokumen perusahaan Anda yang tersedia.</p>
          </div>
          <button onClick={state.refresh}>Muat ulang</button>
        </div>
        <ErrorBox error={error ?? state.error} />
        {state.loading ? (
          <Loading />
        ) : (
          d && (
            <>
              <section className="card">
                <div className="section-heading">
                  <h2>Trip & pengajuan</h2>
                </div>
                {d.trips.map((t) => (
                  <article key={t.id} className="ops-record">
                    <div>
                      <strong>Trip {t.id.slice(0, 8)}</strong>
                      <p>
                        {t.contract_reference} · <Badge value={t.status} />
                      </p>
                      <div className="actions">
                        {d.pods
                          .filter((p) => p.trip_id === t.id)
                          .map((p) => (
                            <a key={p.id} href={`/api/v1/files/pods/${p.id}`}>
                              ePOD {p.status}
                            </a>
                          ))}
                      </div>
                    </div>
                    <div className="actions">
                      {t.status !== "CANCELLED" &&
                        !d.invoices.some(
                          (i) => i.trip_id === t.id && i.status !== "VOID",
                        ) && (
                          <Action
                            label="Unggah invoice"
                            path="vendor/invoices"
                            fixed={{ trip_id: t.id }}
                            fields={invoiceFields}
                            onDone={state.refresh}
                          />
                        )}
                      <Action
                        label="Ajukan aksesorial"
                        path="vendor/claims"
                        fixed={{ trip_id: t.id }}
                        fields={claimFields}
                        onDone={state.refresh}
                      />
                    </div>
                  </article>
                ))}
                {!d.trips.length && (
                  <Empty>
                    Belum ada perjalanan yang ditetapkan kepada perusahaan Anda.
                  </Empty>
                )}
              </section>
              <section className="card block">
                <h2>Invoice perusahaan</h2>
                <div className="table-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>Invoice</th>
                        <th>Nilai</th>
                        <th>Status / alasan</th>
                        <th>Dokumen / koreksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {d.invoices.map((i) => (
                        <tr key={i.id}>
                          <td>{i.number}</td>
                          <td>{money(i.total_minor)}</td>
                          <td>
                            <Badge value={i.status} />
                            <small>{i.hold_reason}</small>
                          </td>
                          <td>
                            <a href={`/api/v1/files/invoices/${i.id}`}>
                              Unduh invoice
                            </a>
                            {i.status === "ON_HOLD" &&
                              [
                                "BASE_RATE_MISMATCH",
                                "UNVERIFIED_ACCESSORIAL",
                              ].includes(i.hold_reason) && (
                                <Action
                                  label="Unggah invoice koreksi"
                                  path={`vendor/invoices/${i.id}/correct`}
                                  fixed={{ trip_id: i.trip_id }}
                                  fields={invoiceFields.map((f) => ({
                                    ...f,
                                    value:
                                      f.name === "number"
                                        ? i.number
                                        : f.name === "base_minor"
                                          ? String(i.base_minor / 100)
                                          : f.value,
                                  }))}
                                  onDone={state.refresh}
                                />
                              )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
              <section className="card block">
                <h2>Dispute log dari Finance</h2>
                {d.disputes.map((dispute) => (
                  <article key={dispute.id} className="ops-record">
                    <div>
                      <Badge value={dispute.status} />
                      <p>{dispute.message}</p>
                      {dispute.vendor_response && (
                        <p>Jawaban Anda: {dispute.vendor_response}</p>
                      )}
                      {dispute.resolution && (
                        <p>Resolusi: {dispute.resolution}</p>
                      )}
                    </div>
                    {dispute.status !== "RESOLVED" && (
                      <Action
                        label="Jawab sanggahan"
                        path={`vendor/disputes/${dispute.id}/action`}
                        fixed={{ action: "respond" }}
                        fields={[
                          { ...noteField, label: "Jawaban resmi vendor" },
                        ]}
                        onDone={state.refresh}
                      />
                    )}
                  </article>
                ))}
                {!d.disputes.length && (
                  <Empty>Tidak ada sanggahan untuk perusahaan Anda.</Empty>
                )}
              </section>
            </>
          )
        )}
      </main>
    </div>
  );
}
