import { OrderSpmSection } from "./Spm";
import { can, canOperate } from "./opsTypes";
import { newId } from "./uuid";
import { useEffect, useState } from "react";
import {
  Link,
  useNavigate,
  useParams,
  useSearchParams,
} from "react-router-dom";
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
import { dateTime, minor, money } from "./commercial";
import type { Item, Order, Page, PricePreview } from "./types";
import { stages } from "./types";
import { Select } from "./Select";
export function OrderList() {
  const [params] = useSearchParams();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState(params.get("status") ?? "");
  const [search, setSearch] = useState("");
  const session = useSession();
  const state = useData<Page<Order>>(
    `orders?page=${page}&status=${status}&q=${encodeURIComponent(search)}`,
  );
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PEMESANAN</span>
          <h1 tabIndex={-1}>Pesanan transportasi</h1>
          <p>Kelola permintaan pengiriman dari draft hingga dispatch.</p>
        </div>
        {canOperate(session) && (
          <Link className="button primary" to="/orders/new">
            + Buat order
          </Link>
        )}
      </div>
      <section className="card">
        <div className="filters">
          <Field label="Cari order atau pelanggan">
            <input
              type="search"
              placeholder="Nomor order / nama pelanggan"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </Field>
          <Field label="Status">
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {["DRAFT", "CONFIRMED", "DISPATCHED", "CANCELLED"].map((s) => (
                <option key={s}>{s}</option>
              ))}
            </select>
          </Field>
        </div>
        <ErrorBox error={state.error} />
        {state.error && <button onClick={state.refresh}>Coba lagi</button>}
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Order / pelanggan</th>
                      <th>Tahap</th>
                      <th>Rute</th>
                      <th>Harga jual</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((o) => (
                      <tr key={o.id}>
                        <td>
                          <Link className="record-link" to={`/orders/${o.id}`}>
                            {o.number}
                          </Link>
                          <small>{o.customer_name}</small>
                        </td>
                        <td>{stages[o.stage]}</td>
                        <td>
                          {o.origin}
                          <span className="route-arrow"> → </span>
                          {o.destination}
                        </td>
                        <td className="nowrap">{money(o.sell_minor)}</td>
                        <td>
                          <Badge value={o.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>
                  Belum ada order yang sesuai. Buat order atau ubah filter
                  pencarian.
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
const blankItem = (): Item => ({
  description: "",
  quantity: 1,
  unit_weight_g: 1000,
  length_mm: 100,
  width_mm: 100,
  height_mm: 100,
  stackable: false,
});
type MasterOption = {
  id: string;
  code: string;
  name: string;
  customer_id?: string;
};
type Masters = Record<string, MasterOption[]>;
const MASTER_PICKS = [
  "customers",
  "customer_projects",
  "services",
  "locations",
  "units",
  "carrier_types",
];
type FleetVehicle = {
  id: string;
  plate: string;
  vendor_id: string | null;
  carrier_type_id: string | null;
  carrier_type_label: string | null;
  active?: boolean;
  verification_status?: string;
};
type VendorOption = { id: string; code: string; name: string };
export function OrderCreate() {
  const navigate = useNavigate();
  const session = useSession();
  const [key, setKey] = useState(() => newId());
  const [customer, setCustomer] = useState("");
  const [masters, setMasters] = useState<Masters>({});
  const [masterError, setMasterError] = useState<string>();
  const [customerId, setCustomerId] = useState("");
  const [projectId, setProjectId] = useState("");
  const [serviceId, setServiceId] = useState("");
  const [originId, setOriginId] = useState("");
  const [destinationId, setDestinationId] = useState("");
  useEffect(() => {
    Promise.all(
      MASTER_PICKS.map((r) =>
        api<{ data: MasterOption[] }>(`master/${r}/options`),
      ),
    )
      .then((all) =>
        setMasters(
          Object.fromEntries(MASTER_PICKS.map((r, i) => [r, all[i].data])),
        ),
      )
      .catch((e: unknown) =>
        setMasterError(
          e instanceof Error ? e.message : "Master data tidak dapat dimuat.",
        ),
      );
  }, []);
  const pick = (resource: string, id: string) =>
    masters[resource]?.find((o) => o.id === id);
  // Price from the transporter price mapping (read-only), or typed by hand for the old way of ordering.
  const [auto, setAuto] = useState(true);
  const [transporterId, setTransporterId] = useState("");
  const [vehicleId, setVehicleId] = useState("");
  const [carrierTypeId, setCarrierTypeId] = useState("");
  const [vendors, setVendors] = useState<VendorOption[]>([]);
  const [vehicles, setVehicles] = useState<FleetVehicle[]>([]);
  const [preview, setPreview] = useState<PricePreview>();
  const [previewError, setPreviewError] = useState<string>();
  const [manualPrices, setManualPrices] = useState<Record<number, string>>({});
  const [priceReason, setPriceReason] = useState("");
  useEffect(() => {
    api<{ data: VendorOption[] }>("vendors")
      .then((r) => setVendors(Array.isArray(r.data) ? r.data : []))
      .catch(() => setVendors([]));
    api<{ data: { vehicles: FleetVehicle[] } }>("fleet")
      .then((r) => setVehicles(r.data?.vehicles ?? []))
      .catch(() => setVehicles([]));
  }, []);
  const ownVehicles = vehicles.filter(
    (v) =>
      (v.vendor_id ?? "") === transporterId &&
      v.active !== false &&
      (v.verification_status ?? "VERIFIED") === "VERIFIED" &&
      v.carrier_type_id,
  );
  const [reference, setReference] = useState("");
  const [stage, setStage] = useState("FIRST_MILE");
  const [origin, setOrigin] = useState("");
  const [destination, setDestination] = useState("");
  const [base, setBase] = useState("");
  const [surcharge, setSurcharge] = useState("0");
  const [accessorial, setAccessorial] = useState("0");
  const [items, setItems] = useState<Item[]>([blankItem()]);
  function update(
    index: number,
    field: keyof Item,
    value: string | number | boolean,
  ) {
    setItems((old) =>
      old.map((item, i) => (i === index ? { ...item, [field]: value } : item)),
    );
    setKey(newId());
  }
  let total = 0;
  try {
    total = minor(base) + minor(surcharge) + minor(accessorial);
  } catch {
    /* Preview remains empty until values are valid. */
  }
  const ready =
    auto &&
    !!customerId &&
    !!originId &&
    !!destinationId &&
    (!!vehicleId || !!carrierTypeId) &&
    items.every((i) => i.unit_id && i.quantity >= 1);
  const previewKey = JSON.stringify([
    ready,
    customerId,
    projectId,
    originId,
    destinationId,
    transporterId,
    vehicleId,
    carrierTypeId,
    items.map((i) => [i.description, i.quantity, i.unit_id]),
  ]);
  useEffect(() => {
    if (!ready) {
      setPreview(undefined);
      setPreviewError(undefined);
      return;
    }
    let live = true;
    const timer = setTimeout(() => {
      api<{ data: PricePreview }>(
        "orders/price-preview",
        "POST",
        {
          customer_id: customerId,
          project_id: projectId || null,
          origin_location_id: originId,
          destination_location_id: destinationId,
          transporter_vendor_id: transporterId || null,
          vehicle_id: vehicleId || null,
          carrier_type_id: vehicleId ? null : carrierTypeId || null,
          items: items.map((i) => ({
            description: i.description || null,
            quantity: i.quantity,
            unit_id: i.unit_id,
          })),
        },
        newId(),
      )
        .then((r) => {
          if (!live) return;
          setPreview(r.data);
          setPreviewError(undefined);
        })
        .catch((e: unknown) => {
          if (!live) return;
          setPreview(undefined);
          setPreviewError(
            e instanceof Error ? e.message : "Harga tidak dapat dihitung.",
          );
        });
    }, 400);
    return () => {
      live = false;
      clearTimeout(timer);
    };
    // The key stands for every input of the preview.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewKey]);
  if (!canOperate(session))
    return <ErrorBox error="Akun Anda memiliki akses hanya baca." />;
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PEMESANAN / BARU</span>
          <h1 tabIndex={-1}>Buat order pengiriman</h1>
          <p>
            Simpan draft, periksa muatan dan harga, lalu konfirmasi sebelum
            penugasan.
          </p>
        </div>
      </div>
      <Form
        label="Simpan draft order"
        onSubmit={async () => {
          if (auto && !ready)
            throw new Error(
              "Pilih pelanggan, lokasi asal dan tujuan dari master, kendaraan atau tipe armada, dan satuan setiap barang.",
            );
          const unpriced = preview?.unpriced ?? [];
          const data = {
            price_mode: auto ? "MAPPING" : "MANUAL",
            transporter_vendor_id: auto ? transporterId || null : undefined,
            vehicle_id: auto ? vehicleId || null : undefined,
            carrier_type_id: auto && !vehicleId ? carrierTypeId : undefined,
            price_reason: auto && unpriced.length ? priceReason : undefined,
            customer_name: customer,
            customer_id: customerId || null,
            project_id: projectId || null,
            service_id: serviceId || null,
            origin_location_id: originId || null,
            destination_location_id: destinationId || null,
            reference: reference || null,
            stage,
            origin,
            destination,
            currency: "IDR",
            base_price_minor: auto ? undefined : minor(base),
            surcharge_minor: auto ? undefined : minor(surcharge),
            accessorial_minor: auto ? undefined : minor(accessorial),
            items: items.map((item, index) => ({
              ...item,
              unit_id: item.unit_id || undefined,
              manual_price_minor:
                auto && unpriced.includes(index)
                  ? minor(manualPrices[index] ?? "")
                  : undefined,
            })),
          };
          const result = await api<{ data: Order }>(
            "orders",
            "POST",
            data,
            key,
          );
          navigate(`/orders/${result.data.id}`);
        }}
      >
        <div className="split">
          <div className="stack">
            <section className="card">
              <h2>Informasi pengiriman</h2>
              <ErrorBox error={masterError} />
              <div className="form-grid">
                <Field label="Pelanggan (master data)">
                  <Select
                    value={customerId}
                    onChange={(e) => {
                      const found = pick("customers", e.target.value);
                      setCustomerId(e.target.value);
                      setProjectId("");
                      if (found) setCustomer(found.name);
                      setKey(newId());
                    }}
                  >
                    <option value="">Tulis nama manual</option>
                    {masters.customers?.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.code} · {o.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Proyek / kontrak">
                  <Select
                    value={projectId}
                    disabled={!customerId}
                    onChange={(e) => {
                      setProjectId(e.target.value);
                      setKey(newId());
                    }}
                  >
                    <option value="">Tanpa proyek</option>
                    {masters.customer_projects
                      ?.filter((o) => o.customer_id === customerId)
                      .map((o) => (
                        <option key={o.id} value={o.id}>
                          {o.code} · {o.name}
                        </option>
                      ))}
                  </Select>
                </Field>
                <Field label="Layanan">
                  <Select
                    value={serviceId}
                    onChange={(e) => {
                      setServiceId(e.target.value);
                      setKey(newId());
                    }}
                  >
                    <option value="">Tanpa layanan</option>
                    {masters.services?.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.code} · {o.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Nama pelanggan">
                  <input
                    required
                    maxLength={200}
                    value={customer}
                    readOnly={!!customerId}
                    onChange={(e) => {
                      setCustomer(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <Field label="Referensi pelanggan (opsional)">
                  <input
                    maxLength={100}
                    value={reference}
                    onChange={(e) => {
                      setReference(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <Field label="Tahap rantai pasok">
                  <Select
                    value={stage}
                    onChange={(e) => {
                      setStage(e.target.value);
                      setKey(newId());
                    }}
                  >
                    {Object.entries(stages).map(([k, v]) => (
                      <option key={k} value={k}>
                        {v}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Mata uang">
                  <input value="IDR — Rupiah Indonesia" readOnly />
                </Field>
                <Field label="Lokasi asal (master data)">
                  <Select
                    value={originId}
                    onChange={(e) => {
                      const found = pick("locations", e.target.value);
                      setOriginId(e.target.value);
                      if (found) setOrigin(found.name);
                      setKey(newId());
                    }}
                  >
                    <option value="">Tulis alamat manual</option>
                    {masters.locations?.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.code} · {o.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Alamat / hub asal">
                  <textarea
                    required
                    maxLength={300}
                    readOnly={!!originId}
                    value={origin}
                    onChange={(e) => {
                      setOrigin(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <Field label="Lokasi tujuan (master data)">
                  <Select
                    value={destinationId}
                    onChange={(e) => {
                      const found = pick("locations", e.target.value);
                      setDestinationId(e.target.value);
                      if (found) setDestination(found.name);
                      setKey(newId());
                    }}
                  >
                    <option value="">Tulis alamat manual</option>
                    {masters.locations?.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.code} · {o.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Alamat / hub tujuan">
                  <textarea
                    required
                    maxLength={300}
                    readOnly={!!destinationId}
                    value={destination}
                    onChange={(e) => {
                      setDestination(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
              </div>
            </section>
            {auto && (
              <section className="card">
                <h2>Transporter dan armada</h2>
                <p className="muted">
                  Harga dihitung dari matriks harga transporter menurut tipe
                  armada, rute, dan satuan muatan.
                </p>
                <div className="form-grid">
                  <Field label="Transporter">
                    <Select
                      value={transporterId}
                      onChange={(e) => {
                        setTransporterId(e.target.value);
                        setVehicleId("");
                        setKey(newId());
                      }}
                    >
                      <option value="">
                        {session.tenant.name} (armada sendiri)
                      </option>
                      {vendors.map((v) => (
                        <option key={v.id} value={v.id}>
                          {v.code} · {v.name}
                        </option>
                      ))}
                    </Select>
                  </Field>
                  <Field label="Kendaraan">
                    <Select
                      value={vehicleId}
                      onChange={(e) => {
                        setVehicleId(e.target.value);
                        setKey(newId());
                      }}
                    >
                      <option value="">Pilih tipe armada saja</option>
                      {ownVehicles.map((v) => (
                        <option key={v.id} value={v.id}>
                          {v.plate} · {v.carrier_type_label}
                        </option>
                      ))}
                    </Select>
                  </Field>
                  {!vehicleId && (
                    <Field label="Tipe armada">
                      <Select
                        value={carrierTypeId}
                        onChange={(e) => {
                          setCarrierTypeId(e.target.value);
                          setKey(newId());
                        }}
                      >
                        <option value="">Pilih tipe armada</option>
                        {masters.carrier_types?.map((o) => (
                          <option key={o.id} value={o.id}>
                            {o.code} · {o.name}
                          </option>
                        ))}
                      </Select>
                    </Field>
                  )}
                </div>
              </section>
            )}
            <section className="card">
              <div className="section-heading">
                <h2>Rincian muatan</h2>
                <button
                  type="button"
                  disabled={items.length >= 100}
                  onClick={() => {
                    setItems((v) => [...v, blankItem()]);
                    setKey(newId());
                  }}
                >
                  + Tambah barang
                </button>
              </div>
              <p className="muted">
                Berat per unit dalam gram; dimensi per unit dalam milimeter.
                Pemeriksaan ini belum merupakan optimasi 3D atau validasi beban
                as.
              </p>
              {items.map((item, index) => (
                <fieldset className="cargo" key={index}>
                  <legend>Barang {index + 1}</legend>
                  <div className="form-grid">
                    <Field label="Deskripsi barang">
                      <input
                        required
                        maxLength={200}
                        value={item.description}
                        onChange={(e) =>
                          update(index, "description", e.target.value)
                        }
                      />
                    </Field>
                    {auto && (
                      <Field label="Satuan muatan">
                        <Select
                          required
                          value={item.unit_id ?? ""}
                          onChange={(e) =>
                            update(index, "unit_id", e.target.value)
                          }
                        >
                          <option value="">Pilih satuan</option>
                          {masters.units?.map((o) => (
                            <option key={o.id} value={o.id}>
                              {o.code} · {o.name}
                            </option>
                          ))}
                        </Select>
                      </Field>
                    )}
                    <Field label="Jumlah unit">
                      <input
                        type="number"
                        min={1}
                        max={1000}
                        step={1}
                        required
                        value={item.quantity}
                        onChange={(e) =>
                          update(index, "quantity", e.target.valueAsNumber)
                        }
                      />
                    </Field>
                    {(
                      [
                        "unit_weight_g",
                        "length_mm",
                        "width_mm",
                        "height_mm",
                      ] as const
                    ).map((field, i) => (
                      <Field
                        key={field}
                        label={
                          [
                            "Berat per unit (g)",
                            "Panjang (mm)",
                            "Lebar (mm)",
                            "Tinggi (mm)",
                          ][i]
                        }
                      >
                        <input
                          required
                          type="number"
                          min={1}
                          max={field === "unit_weight_g" ? 100000000 : 20000}
                          step={1}
                          value={item[field]}
                          onChange={(e) =>
                            update(index, field, e.target.valueAsNumber)
                          }
                        />
                      </Field>
                    ))}
                  </div>
                  <div className="actions">
                    <label className="check">
                      <input
                        type="checkbox"
                        checked={item.stackable}
                        onChange={(e) =>
                          update(index, "stackable", e.target.checked)
                        }
                      />{" "}
                      Boleh ditumpuk
                    </label>
                    <button
                      type="button"
                      disabled={items.length === 1}
                      onClick={() => {
                        setItems((v) => v.filter((_, i) => i !== index));
                        setKey(newId());
                      }}
                    >
                      Hapus barang {index + 1}
                    </button>
                  </div>
                </fieldset>
              ))}
            </section>
          </div>
          <aside className="card costing">
            <span className="eyebrow">KOMERSIAL</span>
            <h2>Harga jual pelanggan</h2>
            <label className="check">
              <input
                type="checkbox"
                checked={!auto}
                onChange={(e) => {
                  setAuto(!e.target.checked);
                  setKey(newId());
                }}
              />{" "}
              Harga manual tanpa matriks
            </label>
            {auto ? (
              <>
                <ErrorBox error={previewError} />
                {!ready && (
                  <p className="muted">
                    Lengkapi pelanggan, lokasi, kendaraan atau tipe armada, dan
                    satuan muatan untuk melihat tarif dasar.
                  </p>
                )}
                {preview?.items.map((line) => (
                  <div className="cost-line" key={line.index}>
                    <span>
                      {line.description || `Barang ${line.index + 1}`} ·{" "}
                      {line.quantity} unit
                    </span>
                    {line.priced ? (
                      <strong>{money(line.amount_minor ?? 0)}</strong>
                    ) : (
                      <Field
                        label={`Harga manual barang ${line.index + 1} (Rp)`}
                      >
                        <input
                          required
                          inputMode="decimal"
                          placeholder="0,00"
                          value={manualPrices[line.index] ?? ""}
                          onChange={(e) => {
                            setManualPrices((v) => ({
                              ...v,
                              [line.index]: e.target.value,
                            }));
                            setKey(newId());
                          }}
                        />
                      </Field>
                    )}
                  </div>
                ))}
                {!!preview?.unpriced.length && (
                  <>
                    <div className="alert">
                      Tidak ada tarif untuk barang yang ditandai. Isi harga
                      manual dan alasannya; order menunggu persetujuan orang
                      lain sebelum dapat dikonfirmasi.
                    </div>
                    <Field label="Alasan harga manual">
                      <textarea
                        required
                        maxLength={1000}
                        value={priceReason}
                        onChange={(e) => {
                          setPriceReason(e.target.value);
                          setKey(newId());
                        }}
                      />
                    </Field>
                  </>
                )}
                <div className="cost-total">
                  <span>Tarif dasar (otomatis)</span>
                  <strong>
                    {preview
                      ? money(
                          preview.total_minor +
                            preview.unpriced.reduce((sum, i) => {
                              try {
                                return sum + minor(manualPrices[i] ?? "");
                              } catch {
                                return sum;
                              }
                            }, 0),
                        )
                      : "—"}
                  </strong>
                </div>
                <p className="muted">
                  Tarif dasar tidak dapat diubah di sini. Biaya tambahan seperti
                  BBM, uang jalan, dan menginap dicatat pada SPM selama
                  pengiriman berjalan.
                </p>
              </>
            ) : (
              <>
                <Field label="Tarif dasar (Rp)">
                  <input
                    required
                    inputMode="decimal"
                    placeholder="0,00"
                    value={base}
                    onChange={(e) => {
                      setBase(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <Field label="Surcharge fuel / tol (Rp)">
                  <input
                    required
                    inputMode="decimal"
                    value={surcharge}
                    onChange={(e) => {
                      setSurcharge(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <Field label="Aksesorial pelanggan (Rp)">
                  <input
                    required
                    inputMode="decimal"
                    value={accessorial}
                    onChange={(e) => {
                      setAccessorial(e.target.value);
                      setKey(newId());
                    }}
                  />
                </Field>
                <div className="cost-total">
                  <span>Total harga jual</span>
                  <strong>{total ? money(total) : "—"}</strong>
                </div>
                <p className="muted">
                  Harga dimasukkan sesuai kesepakatan pelanggan. Biaya vendor
                  dan persetujuan margin ditentukan pada rencana dispatch.
                </p>
              </>
            )}
            <div className="alert info">
              Draft tidak mengalokasikan kendaraan atau pengemudi.
            </div>
          </aside>
        </div>
      </Form>
    </>
  );
}
export function OrderDetail() {
  const { id } = useParams();
  const state = useData<{ data: Order }>(`orders/${id}`);
  const session = useSession();
  const [action, setAction] = useState<"confirm" | "cancel">();
  const [decision, setDecision] = useState<"approve" | "reject">();
  const [note, setNote] = useState("");
  if (state.loading) return <Loading />;
  if (state.error)
    return (
      <>
        <ErrorBox error={state.error} />
        <button onClick={state.refresh}>Coba lagi</button>
      </>
    );
  const o = state.data!.data;
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">DETAIL ORDER</span>
          <h1 tabIndex={-1}>{o.number}</h1>
          <p>
            {o.customer_name} {o.reference && `· ${o.reference}`}
          </p>
        </div>
        <Badge value={o.status} />
      </div>
      <div className="split">
        <section className="card">
          <h2>Informasi pengiriman</h2>
          <dl className="details">
            {o.customer_id_label && (
              <>
                <dt>Pelanggan (master)</dt>
                <dd>{o.customer_id_label}</dd>
              </>
            )}
            {o.project_id_label && (
              <>
                <dt>Proyek / kontrak</dt>
                <dd>{o.project_id_label}</dd>
              </>
            )}
            {o.service_id_label && (
              <>
                <dt>Layanan</dt>
                <dd>{o.service_id_label}</dd>
              </>
            )}
            <dt>Tahap</dt>
            <dd>{stages[o.stage]}</dd>
            <dt>Asal</dt>
            <dd>{o.origin}</dd>
            <dt>Tujuan</dt>
            <dd>{o.destination}</dd>
            <dt>Dibuat</dt>
            <dd>{dateTime(o.created_at)}</dd>
            {o.price_source && o.price_source !== "MANUAL" && (
              <>
                <dt>Transporter</dt>
                <dd>
                  {o.transporter_vendor_id_label ?? "Armada sendiri"}
                  {o.vehicle_label && ` · ${o.vehicle_label}`}
                  {o.carrier_type_id_label && ` · ${o.carrier_type_id_label}`}
                </dd>
                <dt>Sumber harga</dt>
                <dd>
                  {o.price_source === "MAPPING"
                    ? "Matriks harga transporter"
                    : "Harga manual dengan persetujuan"}
                  {o.price_status === "PENDING" && " · menunggu persetujuan"}
                  {o.price_status === "APPROVED" && " · disetujui"}
                  {o.price_status === "REJECTED" && " · ditolak"}
                </dd>
                {o.price_reason && (
                  <>
                    <dt>Alasan harga manual</dt>
                    <dd>{o.price_reason}</dd>
                  </>
                )}
                {o.price_decision_note && (
                  <>
                    <dt>Catatan keputusan</dt>
                    <dd>{o.price_decision_note}</dd>
                  </>
                )}
              </>
            )}
            <dt>Tarif dasar</dt>
            <dd>{money(o.base_price_minor)}</dd>
            {o.price_source !== "MAPPING" && o.price_source !== "APPROVAL" && (
              <>
                <dt>Surcharge</dt>
                <dd>{money(o.surcharge_minor)}</dd>
                <dt>Aksesorial pelanggan</dt>
                <dd>{money(o.accessorial_minor)}</dd>
              </>
            )}
            <dt>Harga jual total</dt>
            <dd>
              <strong>{money(o.sell_minor)}</strong>
            </dd>
          </dl>
        </section>
        <section className="card">
          <h2>Aksi order</h2>
          <p className="muted">
            Setelah konfirmasi, rincian order dikunci untuk menjaga konsistensi
            komersial dan dispatch.
          </p>
          {canOperate(session) && (
            <div className="stack">
              {o.status === "DRAFT" && (
                <button
                  className="primary"
                  disabled={["PENDING", "REJECTED"].includes(
                    o.price_status ?? "",
                  )}
                  title={
                    o.price_status === "PENDING"
                      ? "Harga manual menunggu persetujuan."
                      : o.price_status === "REJECTED"
                        ? "Harga manual ditolak."
                        : undefined
                  }
                  onClick={() => setAction("confirm")}
                >
                  Konfirmasi order
                </button>
              )}
              {o.status === "DRAFT" &&
                o.price_status === "PENDING" &&
                can(session, "rate.approve") && (
                  <>
                    <button
                      className="primary"
                      disabled={o.price_requested_by === session.id}
                      title={
                        o.price_requested_by === session.id
                          ? "Harga harus disetujui oleh orang lain."
                          : undefined
                      }
                      onClick={() => {
                        setNote("");
                        setDecision("approve");
                      }}
                    >
                      Setujui harga manual
                    </button>
                    <button
                      disabled={o.price_requested_by === session.id}
                      onClick={() => {
                        setNote("");
                        setDecision("reject");
                      }}
                    >
                      Tolak harga manual
                    </button>
                  </>
                )}
              {o.status === "CONFIRMED" && (
                <Link className="button primary" to={`/dispatch?order=${o.id}`}>
                  Buat rencana dispatch
                </Link>
              )}
              {["DRAFT", "CONFIRMED"].includes(o.status) && (
                <button
                  className="danger-button"
                  onClick={() => setAction("cancel")}
                >
                  Batalkan order
                </button>
              )}
            </div>
          )}
          {o.status === "DISPATCHED" && (
            <div className="alert success">
              Order telah dialokasikan. Detail rencana tersedia pada board
              dispatch.
            </div>
          )}
        </section>
      </div>
      <section className="card block">
        <h2>Muatan</h2>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Barang</th>
                <th>Jumlah</th>
                <th>Berat / unit</th>
                <th>Dimensi / unit</th>
                <th>Penumpukan</th>
              </tr>
            </thead>
            <tbody>
              {o.items?.map((i) => (
                <tr key={i.id}>
                  <td>{i.description}</td>
                  <td>{i.quantity}</td>
                  <td>{(i.unit_weight_g / 1000).toLocaleString("id-ID")} kg</td>
                  <td>
                    {i.length_mm} × {i.width_mm} × {i.height_mm} mm
                  </td>
                  <td>{i.stackable ? "Diizinkan" : "Tidak"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <OrderSpmSection orderId={o.id} status={o.status} />
      {decision && (
        <Confirm
          title={
            decision === "approve"
              ? "Setujui harga manual?"
              : "Tolak harga manual?"
          }
          onClose={() => setDecision(undefined)}
          onConfirm={async () => {
            await api(`orders/${id}/price-decision`, "POST", {
              decision,
              note: note || undefined,
            });
            state.refresh();
          }}
        >
          <p>
            Harga {money(o.sell_minor)} diajukan dengan alasan:{" "}
            {o.price_reason ?? "—"}
          </p>
          <Field
            label={
              decision === "reject" ? "Alasan penolakan" : "Catatan (opsional)"
            }
          >
            <textarea
              required={decision === "reject"}
              maxLength={1000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </Field>
        </Confirm>
      )}
      {action && (
        <Confirm
          title={action === "confirm" ? "Konfirmasi order?" : "Batalkan order?"}
          onClose={() => setAction(undefined)}
          onConfirm={async () => {
            await api(`orders/${id}/transition`, "POST", { action });
            state.refresh();
          }}
        >
          <p>
            {action === "confirm"
              ? "Pastikan alamat, muatan dan harga sudah benar. Rincian dikunci setelah konfirmasi."
              : "Order yang dibatalkan tidak dapat dikonfirmasi atau ditugaskan."}
          </p>
        </Confirm>
      )}
    </>
  );
}
