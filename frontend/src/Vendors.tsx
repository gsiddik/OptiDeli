import { useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { Action } from "./RecordEditor";
import type { Vendor } from "./opsTypes";
import { can, canOperate } from "./opsTypes";
export function VendorsPage() {
  const state = useData<{ data: Vendor[] }>("vendors");
  const session = useSession();
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
                              onDone={state.refresh}
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
