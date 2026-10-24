import { canOperate, dispatchApprovalLevel } from "./opsTypes";
import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import {
  Badge,
  Confirm,
  Empty,
  ErrorBox,
  Field,
  Form,
  Loading,
  Pagination,
} from "./components";
import {
  approvalLevel,
  dateTime,
  minor,
  money,
  percentage,
} from "./commercial";
import { levels, stages } from "./types";
import type { Fleet, Order, Page, Plan } from "./types";
const localDate = (hours: number) => {
  const d = new Date(Date.now() + hours * 3600000);
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};
export function DispatchBoard() {
  const session = useSession();
  const policy = useData<{
    data: {
      minimum_margin_bps: number;
      stage_margin_bps: Record<string, number>;
    };
  }>("commercial/policy");
  const [params] = useSearchParams();
  const [page, setPage] = useState(1);
  const [orderPage, setOrderPage] = useState(1);
  const orders = useData<Page<Order>>(
    `orders?status=CONFIRMED&page=${orderPage}`,
  );
  const fleet = useData<{ data: Fleet }>("fleet");
  const plans = useData<Page<Plan>>(`dispatch/plans?page=${page}`);
  const [orderId, setOrderId] = useState(params.get("order") ?? "");
  const selected = useData<{ data: Order }>(
    orderId ? `orders/${orderId}` : "orders?status=CONFIRMED",
  );
  const [vehicle, setVehicle] = useState("");
  const [driver, setDriver] = useState("");
  const [base, setBase] = useState("");
  const [accessorial, setAccessorial] = useState("0");
  const [start, setStart] = useState(() => localDate(1));
  const [end, setEnd] = useState(() => localDate(5));
  const [attestation, setAttestation] = useState("");
  const [reason, setReason] = useState("");
  const [detail, setDetail] = useState("");
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState("");
  const [confirm, setConfirm] = useState<{
    id: string;
    action: "approve" | "confirm";
  }>();
  useEffect(() => {
    if (orders.data && !orderId && orders.data.data[0])
      setOrderId(orders.data.data[0].id);
  }, [orders.data, orderId]);
  const order =
    orderId &&
    selected.data &&
    "data" in selected.data &&
    !Array.isArray(selected.data.data)
      ? selected.data.data
      : undefined;
  let cost = 0;
  let validCost = false;
  try {
    cost = minor(base) + minor(accessorial);
    validCost = true;
  } catch {
    /* Incomplete preview. */
  }
  const activePolicy = policy.data?.data ?? session.tenant;
  const threshold = order
    ? (activePolicy.stage_margin_bps[order.stage] ??
      activePolicy.minimum_margin_bps)
    : activePolicy.minimum_margin_bps;
  const level =
    order && validCost ? approvalLevel(order.sell_minor, cost, threshold) : 0;
  const roleLevel = dispatchApprovalLevel(session);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">DISPATCH</span>
          <h1 tabIndex={-1}>Board alokasi & persetujuan</h1>
          <p>Periksa armada, lindungi margin, lalu konfirmasikan penugasan.</p>
        </div>
        <Badge value="LOCAL FLEET" />
      </div>
      {notice && (
        <div className="alert success" role="status">
          {notice}
        </div>
      )}
      {canOperate(session) && (
        <Form
          label={
            level > 0 ? "Ajukan persetujuan margin" : "Simpan rencana dispatch"
          }
          disabled={
            !order || order.status !== "CONFIRMED" || !vehicle || !driver
          }
          onSubmit={async () => {
            const data = {
              order_id: orderId,
              vehicle_id: vehicle,
              driver_id: driver,
              vendor_base_minor: minor(base),
              vendor_accessorial_minor: minor(accessorial),
              starts_at: new Date(start).toISOString(),
              ends_at: new Date(end).toISOString(),
              load_safety_attestation: attestation,
              reason_code: reason || null,
              reason_detail: detail || null,
            };
            const result = await api<{ data: Plan }>(
              "dispatch/plans",
              "POST",
              data,
              key,
            );
            setNotice(
              `Rencana tersimpan: ${result.data.status === "READY" ? "siap dikonfirmasi" : "menunggu persetujuan " + levels[result.data.required_level]}. Armada belum dialokasikan.`,
            );
            plans.refresh();
          }}
        >
          <div
            onChange={() => {
              setKey(crypto.randomUUID());
              setNotice("");
            }}
            className="split"
          >
            <section className="card">
              <h2>Order & sumber daya</h2>
              <ErrorBox error={orders.error ?? fleet.error ?? selected.error} />
              {orders.loading || fleet.loading ? (
                <Loading />
              ) : (
                <>
                  <Field label="Order terkonfirmasi">
                    <select
                      required
                      value={orderId}
                      onChange={(e) => setOrderId(e.target.value)}
                    >
                      <option value="">Pilih order</option>
                      {orderId &&
                        order &&
                        !orders.data?.data.some((o) => o.id === orderId) && (
                          <option value={orderId}>
                            {order.number} · {order.customer_name}
                          </option>
                        )}
                      {orders.data?.data.map((o) => (
                        <option value={o.id} key={o.id}>
                          {o.number} · {o.customer_name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  {orders.data && orders.data.last_page > 1 && (
                    <Pagination page={orders.data} onPage={setOrderPage} />
                  )}{" "}
                  {!orders.data?.data.length && !order && (
                    <Empty>
                      Belum ada order terkonfirmasi.{" "}
                      <Link to="/orders">Buka pemesanan</Link>.
                    </Empty>
                  )}
                  {order && (
                    <div className="route-preview">
                      <small>{stages[order.stage]}</small>
                      <strong>
                        {order.origin} → {order.destination}
                      </strong>
                      <span>Harga jual: {money(order.sell_minor)}</span>
                    </div>
                  )}
                  <div className="form-grid">
                    <Field label="Kendaraan">
                      <select
                        required
                        value={vehicle}
                        onChange={(e) => setVehicle(e.target.value)}
                      >
                        <option value="">Pilih kendaraan</option>
                        {fleet.data?.data.vehicles.map((v) => (
                          <option
                            key={v.id}
                            value={v.id}
                            disabled={
                              v.status !== "AVAILABLE" ||
                              fleet.data!.data.allocations.some(
                                (a) => a.vehicle_id === v.id,
                              )
                            }
                          >
                            {v.plate} · {v.type} · {v.status}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Pengemudi">
                      <select
                        required
                        value={driver}
                        onChange={(e) => setDriver(e.target.value)}
                      >
                        <option value="">Pilih pengemudi</option>
                        {fleet.data?.data.drivers.map((d) => (
                          <option
                            key={d.id}
                            value={d.id}
                            disabled={
                              !d.active ||
                              fleet.data!.data.allocations.some(
                                (a) => a.driver_id === d.id,
                              )
                            }
                          >
                            {d.name}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Mulai perjalanan">
                      <input
                        type="datetime-local"
                        required
                        value={start}
                        onChange={(e) => setStart(e.target.value)}
                      />
                    </Field>
                    <Field label="Akhir perjalanan">
                      <input
                        type="datetime-local"
                        required
                        value={end}
                        onChange={(e) => setEnd(e.target.value)}
                      />
                    </Field>
                  </div>
                  <small className="muted">
                    Zona waktu tampilan:{" "}
                    {Intl.DateTimeFormat().resolvedOptions().timeZone}. Server
                    menyimpan waktu UTC.
                  </small>
                  <Field
                    label="Pernyataan pemeriksaan keselamatan muatan"
                    hint="Catat pemeriksaan penempatan, pengikatan, penumpukan dan distribusi beban. Pemeriksaan kapasitas tidak menggantikan penilaian keselamatan petugas."
                  >
                    <textarea
                      required
                      minLength={20}
                      maxLength={2000}
                      value={attestation}
                      onChange={(e) => setAttestation(e.target.value)}
                    />
                  </Field>
                  <Link to="/fleet">Kelola kesiapan armada & pengemudi →</Link>
                </>
              )}
            </section>
            <section className="card costing">
              <span className="eyebrow">MARGIN GUARDRAIL</span>
              <h2>Estimasi biaya vendor</h2>
              <Field label="Tarif dasar vendor (Rp)">
                <input
                  required
                  inputMode="decimal"
                  value={base}
                  onChange={(e) => setBase(e.target.value)}
                />
              </Field>
              <Field label="Aksesorial vendor disetujui (Rp)">
                <input
                  required
                  inputMode="decimal"
                  value={accessorial}
                  onChange={(e) => setAccessorial(e.target.value)}
                />
              </Field>
              <div className="cost-total">
                <span>Biaya vendor total</span>
                <strong>{validCost ? money(cost) : "—"}</strong>
              </div>
              <div className={`margin-display ${level > 0 ? "below" : ""}`}>
                <span>Margin operasional</span>
                <strong>
                  {order && validCost
                    ? percentage(order.sell_minor, cost)
                    : "—"}
                </strong>
                <small>
                  Threshold: {threshold / 100}% · {levels[level]}
                </small>
              </div>
              {level > 0 && (
                <>
                  <div className="alert warning">
                    Dispatch terkunci sampai {levels[level]} menyetujui rencana
                    ini.
                  </div>
                  <Field label="Reason code wajib">
                    <select
                      required
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                    >
                      <option value="">Pilih alasan bisnis</option>
                      <option value="CUSTOMER_RETENTION">
                        Strategic Customer Retention
                      </option>
                      <option value="BACKHAUL">
                        Backhaul / Empty Trip Mitigation
                      </option>
                      <option value="CONTRACT_COMMITMENT">
                        Contractual Minimum Commitment
                      </option>
                    </select>
                  </Field>
                  <Field label="Penjelasan alasan (opsional)">
                    <textarea
                      minLength={10}
                      maxLength={2000}
                      value={detail}
                      onChange={(e) => setDetail(e.target.value)}
                    />
                  </Field>
                </>
              )}
              <small className="muted">
                Nilai preview dibulatkan untuk tampilan. Persetujuan server
                memakai angka presisi tanpa pembulatan batas margin.
              </small>
            </section>
          </div>
        </Form>
      )}
      <section className="card block">
        <div className="section-heading">
          <h2>Rencana dispatch</h2>
          <button
            onClick={() => {
              plans.refresh();
              fleet.refresh();
              orders.refresh();
            }}
          >
            Muat ulang
          </button>
        </div>
        <ErrorBox error={plans.error} />
        {plans.loading ? (
          <Loading />
        ) : (
          plans.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Order / rencana</th>
                      <th>Jadwal mulai</th>
                      <th>Biaya vendor</th>
                      <th>Persetujuan</th>
                      <th>Status / aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {plans.data.data.map((p) => (
                      <tr key={p.id}>
                        <td>
                          <Link
                            className="record-link"
                            to={`/orders/${p.order_id}`}
                          >
                            Detail order →
                          </Link>
                          <small>{p.id.slice(0, 8)}</small>
                        </td>
                        <td>{dateTime(p.starts_at)}</td>
                        <td className="nowrap">{money(p.cost_minor)}</td>
                        <td>
                          {levels[p.required_level]}
                          {p.reason_code && (
                            <small>{p.reason_code.replaceAll("_", " ")}</small>
                          )}
                        </td>
                        <td>
                          <div className="stack">
                            <Badge value={p.status} />
                            <div className="actions">
                              {p.status === "PENDING_APPROVAL" &&
                                roleLevel >= p.required_level &&
                                p.requested_by !== session.id && (
                                  <button
                                    onClick={() =>
                                      setConfirm({
                                        id: p.id,
                                        action: "approve",
                                      })
                                    }
                                  >
                                    Setujui margin
                                  </button>
                                )}
                              {["READY", "APPROVED"].includes(p.status) &&
                                canOperate(session) && (
                                  <button
                                    className="primary"
                                    onClick={() =>
                                      setConfirm({
                                        id: p.id,
                                        action: "confirm",
                                      })
                                    }
                                  >
                                    Konfirmasi dispatch
                                  </button>
                                )}
                            </div>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {plans.data.data.length === 0 && (
                <Empty>Belum ada rencana dispatch.</Empty>
              )}
              <Pagination page={plans.data} onPage={setPage} />
            </>
          )
        )}
      </section>
      {confirm && (
        <Confirm
          title={
            confirm.action === "approve"
              ? "Setujui override margin?"
              : "Konfirmasi penugasan armada?"
          }
          onClose={() => setConfirm(undefined)}
          onConfirm={async () => {
            await api(`dispatch/plans/${confirm.id}/${confirm.action}`, "POST");
            plans.refresh();
            fleet.refresh();
            orders.refresh();
            selected.refresh();
            setNotice(
              confirm.action === "approve"
                ? "Persetujuan margin tercatat."
                : "Dispatch berhasil. Kendaraan dan pengemudi telah dialokasikan.",
            );
          }}
        >
          <p>
            {confirm.action === "approve"
              ? "Persetujuan Anda terikat pada biaya dan kebijakan dalam rencana ini serta akan tercatat pada jejak audit."
              : "Sistem akan memeriksa ulang margin, dokumen, kesiapan armada dan ketersediaan sumber daya sebelum mengalokasikan."}
          </p>
        </Confirm>
      )}
    </>
  );
}
