import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading, Pagination } from "./components";
import { Action, noteField } from "./RecordEditor";
import { dateTime, money, percentage } from "./commercial";
import type { Page } from "./types";
import type { Trip, Vendor } from "./opsTypes";
import { canOperate, canVerify, canFinance } from "./opsTypes";
export function TripList() {
  const [page, setPage] = useState(1);
  const state = useData<Page<Trip>>(`trips?page=${page}`);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">EKSEKUSI</span>
          <h1 tabIndex={-1}>Perjalanan transportasi</h1>
          <p>
            Pantau leg perjalanan, bukti kirim dan pelepasan alokasi armada.
          </p>
        </div>
      </div>
      <section className="card">
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
                      <th>Trip</th>
                      <th>Kontrak vendor</th>
                      <th>Status</th>
                      <th>Alokasi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((t) => (
                      <tr key={t.id}>
                        <td>
                          <Link to={`/trips/${t.id}`}>
                            Trip {t.id.slice(0, 8)}
                          </Link>
                          <small>{dateTime(t.created_at)}</small>
                        </td>
                        <td>{t.contract_reference ?? "Belum ditetapkan"}</td>
                        <td>
                          <Badge value={t.status} />
                        </td>
                        <td>{t.active ? "Aktif" : "Dilepas"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>
                  Belum ada trip. Konfirmasikan dispatch terlebih dahulu.
                </Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
    </>
  );
}
export function TripDetail() {
  const { id } = useParams();
  const state = useData<{ data: Trip }>(`trips/${id}`);
  const vendors = useData<{ data: Vendor[] }>("vendors");
  const session = useSession();
  if (state.loading) return <Loading />;
  if (state.error) return <ErrorBox error={state.error} />;
  const t = state.data!.data;
  const ops = canOperate(session.role);
  const verified = canVerify(session.role);
  const cost =
    (t.plan?.cost_minor ?? 0) +
    (t.claims ?? [])
      .filter((c) => c.status !== "REJECTED")
      .reduce((sum, c) => sum + c.amount_minor, 0);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">EKSEKUSI / TRIP</span>
          <h1 tabIndex={-1}>Trip {t.id.slice(0, 8)}</h1>
          <p>
            {t.order?.number} · {t.order?.origin} → {t.order?.destination}
          </p>
        </div>
        <Badge value={t.status} />
      </div>
      <div className="split">
        <section className="card">
          <h2>Perjalanan & komersial</h2>
          <dl className="details">
            <dt>Order</dt>
            <dd>
              <Link to={`/orders/${t.order_id}`}>{t.order?.number}</Link>
            </dd>
            <dt>Kontrak vendor</dt>
            <dd>{t.contract_reference ?? "Belum ditetapkan"}</dd>
            <dt>Harga jual</dt>
            <dd>{money(t.order?.sell_minor ?? 0)}</dd>
            <dt>Biaya awal</dt>
            <dd>{money(t.plan?.cost_minor ?? 0)}</dd>
            <dt>Proyeksi biaya + klaim</dt>
            <dd>{money(cost)}</dd>
            <dt>Proyeksi margin</dt>
            <dd>{percentage(t.order?.sell_minor ?? 0, cost)}</dd>
            <dt>Alokasi</dt>
            <dd>{t.active ? "Aktif" : "Dilepas; riwayat dipertahankan"}</dd>
          </dl>
          <p className="muted block">
            Klaim SUBMITTED turut tampil pada proyeksi; angka ini belum
            merupakan persetujuan pembayaran.
          </p>
        </section>
        <section className="card">
          <h2>Aksi operasional</h2>
          <div className="actions">
            {ops && t.status === "ASSIGNED" && (
              <>
                <Action
                  label="Tetapkan vendor/kontrak"
                  path={`trips/${id}/vendor`}
                  fields={[
                    {
                      name: "vendor_id",
                      label: "Vendor",
                      kind: "select",
                      options: vendors.data?.data.map((v) => ({
                        value: v.id,
                        label: v.name,
                      })),
                    },
                    {
                      name: "contract_reference",
                      label: "Referensi kontrak/PO",
                    },
                  ]}
                  onDone={state.refresh}
                />
                <Action
                  label="Mulai perjalanan"
                  path={`trips/${id}/transition`}
                  fixed={{ action: "start" }}
                  onDone={state.refresh}
                  primary
                />
                <Action
                  label="Batalkan trip"
                  path={`trips/${id}/transition`}
                  fixed={{ action: "cancel" }}
                  fields={[
                    {
                      name: "reason",
                      label: "Alasan pembatalan",
                      kind: "textarea",
                    },
                  ]}
                  onDone={state.refresh}
                />
              </>
            )}
            {ops && t.status === "ARRIVED" && (
              <Action
                label="Selesaikan & lepas alokasi"
                path={`trips/${id}/transition`}
                fixed={{ action: "complete" }}
                onDone={state.refresh}
                primary
              />
            )}
          </div>
          <p className="muted block">
            Penyelesaian memerlukan semua leg tiba, ePOD lengkap terverifikasi
            dan gate-out. Trip yang sudah berangkat tidak dapat dibatalkan
            sebagai draft.
          </p>
        </section>
      </div>
      <section className="card block">
        <div className="section-heading">
          <h2>Leg perjalanan</h2>
          {ops && t.status === "ASSIGNED" && (
            <Action
              label="Tambah leg"
              path={`trips/${id}/legs`}
              fields={[
                {
                  name: "origin",
                  label: "Asal leg",
                  value: t.legs?.at(-1)?.destination ?? t.order?.origin,
                },
                {
                  name: "destination",
                  label: "Tujuan leg",
                  value: t.order?.destination,
                },
                { name: "planned_eta", label: "ETA leg", kind: "datetime" },
              ]}
              onDone={state.refresh}
            />
          )}
        </div>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Leg</th>
                <th>Rute</th>
                <th>ETA</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {t.legs?.map((l) => (
                <tr key={l.id}>
                  <td>{l.sequence}</td>
                  <td>
                    {l.origin} → {l.destination}
                  </td>
                  <td>{dateTime(l.planned_eta)}</td>
                  <td>
                    <Badge value={l.status} />
                  </td>
                  <td>
                    {ops &&
                      t.status === "IN_TRANSIT" &&
                      ["PLANNED", "IN_TRANSIT"].includes(l.status) && (
                        <Action
                          label={
                            l.status === "PLANNED"
                              ? "Catat berangkat"
                              : "Catat tiba"
                          }
                          path={`legs/${l.id}/transition`}
                          fixed={{
                            action:
                              l.status === "PLANNED" ? "depart" : "arrive",
                          }}
                          onDone={state.refresh}
                        />
                      )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!t.legs?.length && (
          <Empty>Tambahkan leg berurutan sebelum berangkat.</Empty>
        )}
      </section>
      <section className="card block">
        <div className="section-heading">
          <h2>ePOD · bukti kirim privat</h2>
          {ops && t.status === "ARRIVED" && (
            <Action
              label="Unggah ePOD"
              path={`trips/${id}/pods`}
              fields={[
                { name: "receiver_name", label: "Nama penerima" },
                {
                  name: "delivered_units",
                  label: "Jumlah unit diterima",
                  kind: "number",
                },
                {
                  name: "outcome",
                  label: "Hasil pengiriman",
                  kind: "select",
                  options: [
                    { value: "DELIVERED", label: "Terkirim lengkap" },
                    {
                      value: "EXCEPTION",
                      label: "Pengecualian / tidak lengkap",
                    },
                  ],
                },
                {
                  name: "note",
                  label: "Catatan pengecualian",
                  kind: "textarea",
                  required: false,
                },
                {
                  name: "file",
                  label: "Bukti PDF/JPEG/PNG (maks. 10 MB)",
                  kind: "file",
                },
              ]}
              onDone={state.refresh}
            />
          )}
        </div>
        {t.pods?.map((p) => (
          <article className="ops-record" key={p.id}>
            <div>
              <strong>
                {p.receiver_name} · {p.delivered_units} unit
              </strong>
              <p>
                {p.outcome} · <Badge value={p.status} />
              </p>
              <a href={`/api/v1/files/pods/${p.id}`}>Unduh bukti kirim</a>
            </div>
            {verified &&
              p.status === "SUBMITTED" &&
              p.submitted_by !== session.id && (
                <Action
                  label="Tinjau ePOD"
                  path={`pods/${p.id}/verify`}
                  fields={[
                    {
                      name: "decision",
                      label: "Keputusan",
                      kind: "select",
                      options: [
                        { value: "verify", label: "Verifikasi" },
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
        {!t.pods?.length && <Empty>Belum ada bukti kirim.</Empty>}
      </section>
      <section className="card block">
        <div className="section-heading">
          <h2>Aksesorial susulan</h2>
          {ops && t.status !== "CANCELLED" && (
            <Action
              label="Ajukan aksesorial"
              path="freight/claims"
              fixed={{ trip_id: id }}
              fields={claimFields}
              onDone={state.refresh}
            />
          )}
        </div>
        <p className="muted">
          Klaim baru langsung menahan settlement invoice yang terkait. Finance
          memverifikasi bukti; persetujuan biaya mengikuti matriks terpisah.
        </p>
        {t.claims?.map((c) => (
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
                  label="Verifikasi aksesorial"
                  path={`freight/claims/${c.id}/verify`}
                  fields={[
                    {
                      name: "decision",
                      label: "Keputusan",
                      kind: "select",
                      options: [
                        { value: "verify", label: "Bukti valid" },
                        { value: "reject", label: "Tolak klaim" },
                      ],
                    },
                    noteField,
                  ]}
                  onDone={state.refresh}
                />
              )}
          </article>
        ))}
      </section>
    </>
  );
}
export const claimFields = [
  {
    name: "category",
    label: "Kategori biaya",
    kind: "select" as const,
    options: [
      "DETENTION",
      "DEMURRAGE",
      "LOADING",
      "UNLOADING",
      "OVERNIGHT",
    ].map((value) => ({ value, label: value })),
  },
  { name: "amount_minor", label: "Nilai klaim (Rp)", kind: "money" as const },
  { name: "reason", label: "Alasan klaim", kind: "textarea" as const },
  { name: "evidence_reference", label: "Referensi bukti / laporan yard" },
];
