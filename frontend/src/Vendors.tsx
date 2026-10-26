import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { Action } from "./RecordEditor";
import { SeatsCard } from "./Seats";
import type { Vendor } from "./opsTypes";
import { can, canOperate } from "./opsTypes";
type Account = {
  id: number;
  name: string;
  email: string;
  active: boolean | number;
};

/** The sign-in accounts of one vendor. Each active one takes a seat of the tenant; switching one off frees it. */
function Accounts({
  vendorId,
  onChange,
}: {
  vendorId: string;
  onChange: () => void;
}) {
  const state = useData<{ data: Account[] }>(`vendors/${vendorId}/accounts`);
  const [failure, setFailure] = useState<string>();
  async function toggle(a: Account) {
    setFailure(undefined);
    try {
      await api(`vendors/${vendorId}/accounts/${a.id}/active`, "PUT", {
        active: !a.active,
      });
      state.refresh();
      onChange();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }
  if (state.loading) return <Loading />;
  return (
    <div className="block">
      <ErrorBox error={failure ?? state.error} />
      {state.data?.data.length ? (
        <ul className="plain">
          {state.data.data.map((a) => (
            <li key={a.id}>
              {a.name} · {a.email}{" "}
              <Badge value={a.active ? "ACTIVE" : "INACTIVE"} />{" "}
              <button onClick={() => void toggle(a)}>
                {a.active ? "Nonaktifkan" : "Aktifkan"}
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <Empty>Belum ada akun untuk vendor ini.</Empty>
      )}
    </div>
  );
}

export function VendorsPage() {
  const state = useData<{ data: Vendor[] }>("vendors");
  const session = useSession();
  const [open, setOpen] = useState<string>();
  const [seatsKey, setSeatsKey] = useState(0);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">MITRA TRANSPORTASI</span>
          <h1 tabIndex={-1}>Vendor & akses portal</h1>
          <p>
            Daftar mitra dan akun portal vendor yang dibatasi pada
            perusahaannya.
          </p>
        </div>
        {canOperate(session) && (
          <Action
            label="Daftarkan vendor"
            path="vendors"
            fields={[
              { name: "name", label: "Nama perusahaan vendor" },
              { name: "code", label: "Kode vendor" },
            ]}
            onDone={state.refresh}
            primary
          />
        )}
      </div>
      <ErrorBox error={state.error} />
      <SeatsCard key={seatsKey} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Vendor</th>
                      <th>Kode</th>
                      <th>Status</th>
                      <th>Portal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((v) => (
                      <tr key={v.id}>
                        <td>{v.name}</td>
                        <td>{v.code}</td>
                        <td>
                          <Badge value={v.active ? "ACTIVE" : "INACTIVE"} />
                        </td>
                        <td>
                          {can(session, "vendor.portal.provision") && (
                            <button
                              aria-expanded={open === v.id}
                              onClick={() =>
                                setOpen(open === v.id ? undefined : v.id)
                              }
                            >
                              Akun
                            </button>
                          )}
                          {open === v.id && (
                            <Accounts
                              vendorId={v.id}
                              onChange={() => setSeatsKey((k) => k + 1)}
                            />
                          )}
                          {can(session, "vendor.portal.provision") && (
                            <Action
                              label={`Buat akun ${v.code}`}
                              path={`vendors/${v.id}/account`}
                              fields={[
                                { name: "name", label: "Nama pengguna vendor" },
                                { name: "email", label: "Email akun baru" },
                                {
                                  name: "password",
                                  label:
                                    "Kata sandi awal (min. 12 karakter, huruf besar/kecil, angka, simbol)",
                                  kind: "password",
                                },
                              ]}
                              onDone={() => {
                                state.refresh();
                                setSeatsKey((k) => k + 1);
                              }}
                            />
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && <Empty>Belum ada vendor.</Empty>}
            </>
          )
        )}
        <p className="muted block">
          Provisioning akun lokal hanya oleh CFO/Owner. Kredensial tidak dikirim
          otomatis. SSO/invitation email mengikuti integrasi identitas
          berikutnya.
        </p>
      </section>
    </>
  );
}
