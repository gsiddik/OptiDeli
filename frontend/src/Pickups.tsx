import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import {
  Badge,
  Empty,
  ErrorBox,
  Field,
  Form,
  Loading,
  Pagination,
} from "./components";
import { dateTime } from "./commercial";
import { can } from "./opsTypes";
import { SearchSelect } from "./SearchSelect";
import type { SearchOption } from "./SearchSelect";
import type { Page } from "./types";

type Row = {
  id: string;
  shipment_number: string;
  status: "RECEIVED" | "CONVERTED" | "REJECTED";
  warehouse: { code: string; name: string | null; city: string | null };
  warehouse_linked: boolean;
  lines: number;
  total_quantity: number;
  received_at: string;
  auto_failure: string | null;
  transport_order_id: string | null;
};
type Detail = Row & {
  warehouse: {
    code: string;
    name: string | null;
    address: string | null;
    city: string | null;
  };
  items: {
    product_code: string;
    product_name: string;
    quantity: number;
    uom: string | null;
  }[];
  reason: string | null;
  warehouse_location: { id: string; code: string; name: string } | null;
  order_number: string | null;
  spm_number: string | null;
};
type Settings = {
  auto_order: boolean;
  customer_id: string | null;
  service_id: string | null;
  destination_location_id: string | null;
  stage: string;
};

const stages: Record<string, string> = {
  FIRST_MILE: "First mile",
  MIDDLE_MILE: "Middle mile",
  LAST_MILE: "Last mile",
  E2E: "End to end",
};
const statusLabel: Record<string, string> = {
  RECEIVED: "MASUK",
  CONVERTED: "JADI ORDER",
  REJECTED: "DITOLAK",
};

/** Master choices for the pickers: customers, services and locations as searchable options. */
function useChoices() {
  const [choices, setChoices] = useState<{
    customers: SearchOption[];
    services: SearchOption[];
    locations: SearchOption[];
  }>();
  useEffect(() => {
    let live = true;
    type Rows = { data: { id: string; code: string; name: string }[] };
    const map = (r: Rows) =>
      r.data.map((o) => ({ value: o.id, label: `${o.code} · ${o.name}` }));
    Promise.all([
      api<Rows>("master/customers/options"),
      api<Rows>("master/services/options"),
      api<Rows>("master/locations/options"),
    ])
      .then(([c, s, l]) => {
        if (live)
          setChoices({
            customers: map(c),
            services: map(s),
            locations: map(l),
          });
      })
      .catch(() => {
        if (live) setChoices({ customers: [], services: [], locations: [] });
      });
    return () => {
      live = false;
    };
  }, []);
  return choices;
}

/** Pickup requests from OptiStash: link the warehouse once, then make an order and an SPM, or reject. */
export function PickupsPage() {
  const session = useSession();
  const [status, setStatus] = useState("RECEIVED");
  const [page, setPage] = useState(1);
  const list = useData<Page<Row>>(
    `pickup-requests?status=${status}&page=${page}`,
  );
  const [selected, setSelected] = useState<string>();
  const detail = useData<{ data: Detail }>(
    selected ? `pickup-requests/${selected}` : "pickup-settings",
  );
  const choices = useChoices();
  const handle = can(session, "spm.manage");
  const done = () => {
    list.refresh();
    detail.refresh();
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>Permintaan jemput</h1>
      <p className="muted">
        Permintaan dari gudang OptiStash. Tautkan gudangnya ke lokasi OptiDeli,
        lalu buat order dan SPM. Bila pengaturan bawaan di bawah diisi,
        permintaan dari gudang yang sudah tertaut menjadi order otomatis.
      </p>
      <Defaults choices={choices} />
      <div className="toolbar">
        <Field label="Status">
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
              setSelected(undefined);
            }}
          >
            <option value="RECEIVED">Masuk (belum diproses)</option>
            <option value="CONVERTED">Sudah jadi order</option>
            <option value="REJECTED">Ditolak</option>
          </select>
        </Field>
      </div>
      <ErrorBox error={list.error} />
      {list.loading && <Loading />}
      {list.data &&
        (list.data.data.length === 0 ? (
          <Empty>Tidak ada permintaan jemput.</Empty>
        ) : (
          <>
            <table>
              <thead>
                <tr>
                  <th>Pengiriman</th>
                  <th>Gudang</th>
                  <th>Baris</th>
                  <th>Total</th>
                  <th>Masuk</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {list.data.data.map((r) => (
                  <tr key={r.id}>
                    <td>{r.shipment_number}</td>
                    <td>
                      {r.warehouse.code}
                      {r.warehouse.name ? ` · ${r.warehouse.name}` : ""}{" "}
                      {!r.warehouse_linked && r.status === "RECEIVED" && (
                        <Badge value="BELUM TERTAUT" />
                      )}
                    </td>
                    <td>{r.lines}</td>
                    <td>{r.total_quantity}</td>
                    <td>{dateTime(r.received_at)}</td>
                    <td>
                      <Badge value={statusLabel[r.status]} />
                    </td>
                    <td>
                      <button onClick={() => setSelected(r.id)}>Buka</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination page={list.data} onPage={setPage} />
          </>
        ))}
      {selected && (
        <>
          <ErrorBox error={detail.error} />
          {detail.loading && <Loading />}
          {detail.data && "data" in detail.data && (
            <DetailPanel
              d={(detail.data as { data: Detail }).data}
              choices={choices}
              handle={handle}
              onDone={done}
              onClose={() => setSelected(undefined)}
            />
          )}
        </>
      )}
    </section>
  );
}

function DetailPanel({
  d,
  choices,
  handle,
  onDone,
  onClose,
}: {
  d: Detail;
  choices: ReturnType<typeof useChoices>;
  handle: boolean;
  onDone: () => void;
  onClose: () => void;
}) {
  const settings = useData<{ data: Settings }>("pickup-settings");
  const [location, setLocation] = useState("");
  const [customer, setCustomer] = useState("");
  const [service, setService] = useState("");
  const [destination, setDestination] = useState("");
  const [stage, setStage] = useState("LAST_MILE");
  const [notes, setNotes] = useState("");
  const [reason, setReason] = useState("");
  const prefilled = settings.data?.data;
  // The tenant's defaults are the starting point of the form; a dispatcher can change them for this request.
  useEffect(() => {
    if (!prefilled) return;
    setCustomer((v) => v || (prefilled.customer_id ?? ""));
    setService((v) => v || (prefilled.service_id ?? ""));
    setDestination((v) => v || (prefilled.destination_location_id ?? ""));
    setStage(prefilled.stage);
  }, [prefilled]);
  const open = d.status === "RECEIVED";
  return (
    <div className="card nested">
      <h2>
        {d.shipment_number} <Badge value={statusLabel[d.status]} />
      </h2>
      <p>
        <strong>Gudang:</strong> {d.warehouse.code}
        {d.warehouse.name ? ` · ${d.warehouse.name}` : ""}
        {d.warehouse.address ? ` · ${d.warehouse.address}` : ""}
        {d.warehouse.city ? `, ${d.warehouse.city}` : ""}
        <br />
        <strong>Lokasi OptiDeli:</strong>{" "}
        {d.warehouse_location
          ? `${d.warehouse_location.code} · ${d.warehouse_location.name}`
          : "belum tertaut"}
      </p>
      {d.auto_failure && open && (
        <p className="muted">Order otomatis belum dibuat: {d.auto_failure}</p>
      )}
      {d.reason && (
        <p>
          <strong>Alasan:</strong> {d.reason}
        </p>
      )}
      {d.spm_number && (
        <p>
          Order {d.order_number} ·{" "}
          <Link to={`/spm/${d.transport_order_id}`}>SPM {d.spm_number}</Link>
        </p>
      )}
      <table>
        <thead>
          <tr>
            <th>Kode</th>
            <th>Barang</th>
            <th>Jumlah</th>
            <th>Satuan</th>
          </tr>
        </thead>
        <tbody>
          {d.items.map((i, n) => (
            <tr key={n}>
              <td>{i.product_code}</td>
              <td>{i.product_name}</td>
              <td>{i.quantity}</td>
              <td>{i.uom ?? "-"}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {open && handle && (
        <>
          {!d.warehouse_location && (
            <>
              <h3>Tautkan gudang</h3>
              <p className="muted">
                Pilih lokasi bertipe Gudang di OptiDeli untuk gudang{" "}
                {d.warehouse.code}. Sekali tertaut, semua permintaan dari gudang
                ini memakai lokasi itu.
              </p>
              <Form
                label="Tautkan"
                disabled={!location}
                onSubmit={async () => {
                  await api(`pickup-requests/${d.id}/warehouse`, "POST", {
                    location_id: location,
                  });
                  onDone();
                }}
              >
                <Field label="Lokasi gudang">
                  <SearchSelect
                    value={location}
                    onChange={setLocation}
                    options={choices?.locations ?? []}
                    required
                  />
                </Field>
              </Form>
            </>
          )}
          <h3>Buat order dan SPM</h3>
          <Form
            label="Buat order & SPM"
            disabled={
              !d.warehouse_location || !customer || !service || !destination
            }
            onSubmit={async () => {
              await api(`pickup-requests/${d.id}/convert`, "POST", {
                customer_id: customer,
                service_id: service,
                destination_location_id: destination,
                stage,
                notes: notes || null,
              });
              onDone();
            }}
          >
            <Field label="Pelanggan">
              <SearchSelect
                value={customer}
                onChange={setCustomer}
                options={choices?.customers ?? []}
                required
              />
            </Field>
            <Field label="Layanan">
              <SearchSelect
                value={service}
                onChange={setService}
                options={choices?.services ?? []}
                required
              />
            </Field>
            <Field label="Tujuan">
              <SearchSelect
                value={destination}
                onChange={setDestination}
                options={choices?.locations ?? []}
                required
              />
            </Field>
            <Field label="Tahap">
              <select value={stage} onChange={(e) => setStage(e.target.value)}>
                {Object.entries(stages).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Catatan">
              <input
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                maxLength={500}
              />
            </Field>
          </Form>
          <h3>Tolak</h3>
          <Form
            label="Tolak permintaan"
            disabled={reason.trim().length < 5}
            onSubmit={async () => {
              await api(`pickup-requests/${d.id}/reject`, "POST", { reason });
              setReason("");
              onDone();
            }}
          >
            <Field label="Alasan (minimal 5 karakter)">
              <input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                maxLength={500}
              />
            </Field>
          </Form>
        </>
      )}
      <div className="actions">
        <button onClick={onClose}>Tutup</button>
      </div>
    </div>
  );
}

/** The tenant's defaults that turn a request from a linked warehouse into an order without a person. */
function Defaults({ choices }: { choices: ReturnType<typeof useChoices> }) {
  const session = useSession();
  const data = useData<{ data: Settings }>("pickup-settings");
  const manage = can(session, "master.manage");
  const [form, setForm] = useState<Settings>();
  const current = data.data?.data;
  useEffect(() => {
    if (current) setForm(current);
  }, [current]);
  if (!form || !choices) return data.loading ? <Loading /> : null;
  const set = (patch: Partial<Settings>) => setForm({ ...form, ...patch });
  return (
    <details>
      <summary>
        Order otomatis: {form.auto_order ? "aktif" : "nonaktif"}
      </summary>
      <p className="muted">
        Pelanggan, layanan, dan tujuan bawaan untuk permintaan dari gudang yang
        sudah tertaut. Order dibuat atas nama orang yang terakhir menyimpan
        pengaturan ini, belum berharga (harga ditentukan dari tarif saat SPM
        direncanakan).
      </p>
      <Form
        label="Simpan pengaturan"
        disabled={!manage}
        onSubmit={async () => {
          await api("pickup-settings", "PUT", form);
          data.refresh();
        }}
      >
        <Field label="Order otomatis">
          <select
            value={form.auto_order ? "1" : "0"}
            onChange={(e) => set({ auto_order: e.target.value === "1" })}
            disabled={!manage}
          >
            <option value="0">Nonaktif (masuk ke kotak masuk)</option>
            <option value="1">Aktif</option>
          </select>
        </Field>
        <Field label="Pelanggan bawaan">
          <SearchSelect
            value={form.customer_id ?? ""}
            onChange={(v) => set({ customer_id: v || null })}
            options={choices.customers}
            disabled={!manage}
          />
        </Field>
        <Field label="Layanan bawaan">
          <SearchSelect
            value={form.service_id ?? ""}
            onChange={(v) => set({ service_id: v || null })}
            options={choices.services}
            disabled={!manage}
          />
        </Field>
        <Field label="Tujuan bawaan">
          <SearchSelect
            value={form.destination_location_id ?? ""}
            onChange={(v) => set({ destination_location_id: v || null })}
            options={choices.locations}
            disabled={!manage}
          />
        </Field>
        <Field label="Tahap">
          <select
            value={form.stage}
            onChange={(e) => set({ stage: e.target.value })}
            disabled={!manage}
          >
            {Object.entries(stages).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </Field>
      </Form>
    </details>
  );
}
