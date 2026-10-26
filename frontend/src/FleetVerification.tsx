import { useState } from "react";
import { useData } from "./api";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { EditorDialog, submitRecord } from "./RecordEditor";
import { docLabel } from "./VendorFleetPanel";
import type { FleetDocument } from "./VendorFleetPanel";

type Item = {
  id: string;
  vendor_label: string | null;
  verification_status: "PENDING" | "VERIFIED" | "REJECTED";
  verification_note: string | null;
  documents: FleetDocument[];
  missing_documents: string[];
  plate?: string;
  carrier_type_label?: string | null;
  capacity_g?: number;
  name?: string;
  license_number?: string;
  phone?: string | null;
};
type Queue = { vehicles: Item[]; drivers: Item[] };

const STATUSES = [
  ["PENDING", "Menunggu verifikasi"],
  ["REJECTED", "Ditolak"],
  ["VERIFIED", "Terverifikasi"],
] as const;

/** The tenant's review of the vehicles and drivers its vendors registered: look at the documents, then verify or send back with a reason. */
export function FleetVerificationPage() {
  const [status, setStatus] = useState<(typeof STATUSES)[number][0]>("PENDING");
  const state = useData<{ data: Queue }>(`fleet-verification?status=${status}`);
  const [rejecting, setRejecting] = useState<{
    type: "vehicles" | "drivers";
    item: Item;
  }>();
  const [failure, setFailure] = useState<string>();

  async function verify(type: "vehicles" | "drivers", item: Item) {
    setFailure(undefined);
    try {
      await submitRecord(
        `fleet-verification/${type}/${item.id}`,
        { decision: "VERIFY" },
        crypto.randomUUID(),
      );
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }

  const section = (
    title: string,
    type: "vehicles" | "drivers",
    items: Item[],
  ) => (
    <section className="card block" aria-label={title}>
      <h2>{title}</h2>
      {items.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>{type === "vehicles" ? "Kendaraan" : "Pengemudi"}</th>
                <th>Vendor</th>
                <th>Dokumen</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((i) => (
                <tr key={i.id}>
                  <td>
                    {type === "vehicles" ? i.plate : i.name}
                    <br />
                    <small className="muted">
                      {type === "vehicles"
                        ? `${i.carrier_type_label ?? "tipe belum diisi"} · ${(i.capacity_g ?? 0) / 1000} kg`
                        : `SIM ${i.license_number}${i.phone ? ` · ${i.phone}` : ""}`}
                    </small>
                  </td>
                  <td>{i.vendor_label}</td>
                  <td>
                    <ul className="plain">
                      {i.documents.map((d) => (
                        <li key={d.id}>
                          <a href={`/api/v1/fleet-documents/${d.id}`}>
                            {docLabel[d.kind]}
                          </a>
                          {d.number && ` ${d.number}`}
                          {d.valid_until &&
                            ` s.d. ${d.valid_until.slice(0, 10)}`}
                        </li>
                      ))}
                      {i.missing_documents.map((k) => (
                        <li key={k} className="muted">
                          {docLabel[k]} belum diunggah
                        </li>
                      ))}
                    </ul>
                  </td>
                  <td>
                    <Badge value={i.verification_status} />
                    {i.verification_note && (
                      <>
                        <br />
                        <small className="muted">{i.verification_note}</small>
                      </>
                    )}
                  </td>
                  <td>
                    {i.verification_status !== "VERIFIED" && (
                      <div className="actions">
                        <button
                          className="primary"
                          disabled={i.missing_documents.length > 0}
                          onClick={() => void verify(type, i)}
                        >
                          Verifikasi
                        </button>
                        <button onClick={() => setRejecting({ type, item: i })}>
                          Tolak
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Tidak ada data.</Empty>
      )}
    </section>
  );

  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">VENDOR</span>
          <h1 tabIndex={-1}>Verifikasi armada vendor</h1>
          <p>
            Periksa dokumen kendaraan (STNK, KIR) dan pengemudi (SIM, KTP) yang
            didaftarkan vendor. Hanya yang terverifikasi yang dapat ditugaskan
            ke SPM.
          </p>
        </div>
      </div>
      <div className="tabs" role="group" aria-label="Status verifikasi">
        {STATUSES.map(([key, label]) => (
          <button
            key={key}
            aria-pressed={status === key}
            onClick={() => setStatus(key)}
          >
            {label}
          </button>
        ))}
      </div>
      <ErrorBox error={failure ?? state.error} />
      {state.loading ? (
        <Loading />
      ) : (
        state.data && (
          <>
            {section("Kendaraan", "vehicles", state.data.data.vehicles)}
            {section("Pengemudi", "drivers", state.data.data.drivers)}
          </>
        )
      )}
      {rejecting && (
        <EditorDialog
          title="Tolak dan kembalikan ke vendor"
          fields={[
            {
              name: "note",
              label: "Alasan (minimal 5 karakter)",
              kind: "textarea",
              value: "",
            },
          ]}
          onClose={() => setRejecting(undefined)}
          onSubmit={async (body, key) => {
            await submitRecord(
              `fleet-verification/${rejecting.type}/${rejecting.item.id}`,
              body,
              key,
              { decision: "REJECT" },
            );
            setRejecting(undefined);
            state.refresh();
          }}
        />
      )}
    </>
  );
}
