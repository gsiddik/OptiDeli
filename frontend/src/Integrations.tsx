import { useEffect, useState } from "react";
import { api, useData } from "./api";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";

type Client = {
  id: string;
  customer_label: string | null;
  name: string;
  token_prefix: string;
  scopes: string[];
  expires_at: string | null;
  revoked_at: string | null;
  rotated_at: string | null;
  last_used_at: string | null;
};
type Hook = {
  id: string;
  customer_label: string | null;
  name: string;
  url: string;
  events: string[];
  active: boolean;
  deliveries: Record<string, number> | never[];
};
type Delivery = {
  id: string;
  event_type: string;
  status: string;
  attempts: number;
  next_attempt_at: string;
  last_status_code: number | null;
  last_error: string | null;
};
type Opt = { value: string; label: string };

const SCOPE_SETS: Opt[] = [
  { value: "spm.read,invoice.read", label: "Baca SPM dan invoice" },
  { value: "spm.read", label: "Baca SPM saja" },
  { value: "invoice.read", label: "Baca invoice saja" },
];
const EVENT_SETS: Opt[] = [
  {
    value: "spm.status_changed,invoice.issued",
    label: "Status SPM berubah dan invoice terbit",
  },
  { value: "spm.status_changed", label: "Status SPM berubah" },
  { value: "invoice.issued", label: "Invoice terbit" },
];
const day = (v: string | null) => (v ? v.slice(0, 16).replace("T", " ") : "–");

function useCustomers() {
  const [options, setOptions] = useState<Opt[]>();
  useEffect(() => {
    let live = true;
    api<{ data: { id: string; code: string; name: string }[] }>(
      "master/customers/options",
    )
      .then(
        (r) =>
          live &&
          setOptions(
            r.data.map((c) => ({
              value: c.id,
              label: `${c.code} · ${c.name}`,
            })),
          ),
      )
      .catch(() => live && setOptions([]));
    return () => {
      live = false;
    };
  }, []);
  return options;
}

/** A value that is shown once (a token or a signing secret) and cannot be read again. */
function Secret({
  title,
  value,
  onClose,
}: {
  title: string;
  value: string;
  onClose: () => void;
}) {
  return (
    <div className="alert" role="status">
      <strong>{title}</strong> — salin sekarang, tidak dapat ditampilkan lagi.
      <pre style={{ whiteSpace: "pre-wrap", wordBreak: "break-all" }}>
        {value}
      </pre>
      <button onClick={onClose}>Sudah saya simpan</button>
    </div>
  );
}

function Deliveries({ id, onChange }: { id: string; onChange: () => void }) {
  const state = useData<{ data: Delivery[] }>(`webhooks/${id}/deliveries`);
  const [failure, setFailure] = useState<string>();
  if (state.loading) return <Loading />;
  return (
    <div className="block">
      <ErrorBox error={failure ?? state.error} />
      {state.data?.data.length ? (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Event</th>
                <th>Status</th>
                <th>Percobaan</th>
                <th>Berikutnya</th>
                <th>Hasil terakhir</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {state.data.data.map((d) => (
                <tr key={d.id}>
                  <td>{d.event_type}</td>
                  <td>
                    <Badge value={d.status} />
                  </td>
                  <td>{d.attempts}</td>
                  <td>
                    {d.status === "PENDING" ? day(d.next_attempt_at) : "–"}
                  </td>
                  <td>{d.last_error ?? d.last_status_code ?? "–"}</td>
                  <td>
                    {d.status === "DEAD" && (
                      <button
                        onClick={() =>
                          void api(`webhook-deliveries/${d.id}/retry`, "POST")
                            .then(() => {
                              state.refresh();
                              onChange();
                            })
                            .catch((e: unknown) =>
                              setFailure(
                                e instanceof Error ? e.message : "Gagal.",
                              ),
                            )
                        }
                      >
                        Ulangi
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>Belum ada pengiriman.</Empty>
      )}
    </div>
  );
}

/** API clients and webhooks of the customers: who may read what, and where changes are announced. */
export function IntegrationsPage() {
  const customers = useCustomers();
  const clients = useData<{ data: Client[] }>("api-clients");
  const hooks = useData<{ data: Hook[] }>("webhooks");
  const [dialog, setDialog] = useState<"client" | "hook" | null>(null);
  const [secret, setSecret] = useState<{ title: string; value: string }>();
  const [open, setOpen] = useState<string>();
  const [failure, setFailure] = useState<string>();

  async function act<T>(run: () => Promise<T>, then?: (r: T) => void) {
    setFailure(undefined);
    try {
      const r = await run();
      then?.(r);
      clients.refresh();
      hooks.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Permintaan gagal.");
    }
  }

  const customerField: EditorField = {
    name: "customer_id",
    label: "Pelanggan",
    kind: "select",
    options: customers ?? [],
    value: "",
  };
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">INTEGRASI</span>
          <h1 tabIndex={-1}>Integrasi pelanggan</h1>
          <p>
            Token API baca-saja dan webhook untuk sistem pelanggan. Pelanggan
            hanya melihat SPM dan invoice miliknya sendiri. Dokumentasi:
            docs/integration/CUSTOMER_API.md.
          </p>
        </div>
        <div className="actions">
          <button onClick={() => setDialog("client")}>+ Klien API</button>
          <button className="primary" onClick={() => setDialog("hook")}>
            + Webhook
          </button>
        </div>
      </div>
      <ErrorBox error={failure ?? clients.error ?? hooks.error} />
      {secret && (
        <Secret
          title={secret.title}
          value={secret.value}
          onClose={() => setSecret(undefined)}
        />
      )}
      <section className="card">
        <h2>Klien API</h2>
        {clients.loading ? (
          <Loading />
        ) : clients.data?.data.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Klien</th>
                  <th>Pelanggan</th>
                  <th>Scope</th>
                  <th>Berlaku sampai</th>
                  <th>Terakhir dipakai</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {clients.data.data.map((c) => (
                  <tr key={c.id}>
                    <td>
                      {c.name}
                      <br />
                      <small className="muted">{c.token_prefix}…</small>
                    </td>
                    <td>{c.customer_label}</td>
                    <td>{c.scopes.join(", ")}</td>
                    <td>{c.expires_at ? c.expires_at.slice(0, 10) : "–"}</td>
                    <td>{day(c.last_used_at)}</td>
                    <td>
                      <Badge value={c.revoked_at ? "REVOKED" : "ACTIVE"} />
                    </td>
                    <td>
                      {!c.revoked_at && (
                        <div className="actions">
                          <button
                            onClick={() =>
                              void act(
                                () =>
                                  api<{ data: { token: string } }>(
                                    `api-clients/${c.id}/rotate`,
                                    "POST",
                                  ),
                                (r) =>
                                  setSecret({
                                    title: `Token baru ${c.name}`,
                                    value: r.data.token,
                                  }),
                              )
                            }
                          >
                            Ganti token
                          </button>
                          <button
                            onClick={() =>
                              void act(() =>
                                api(`api-clients/${c.id}/revoke`, "POST"),
                              )
                            }
                          >
                            Cabut
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
          <Empty>Belum ada klien API.</Empty>
        )}
      </section>
      <section className="card block">
        <h2>Webhook</h2>
        {hooks.loading ? (
          <Loading />
        ) : hooks.data?.data.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Webhook</th>
                  <th>Pelanggan</th>
                  <th>Event</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {hooks.data.data.map((h) => (
                  <tr key={h.id}>
                    <td>
                      {h.name}
                      <br />
                      <small className="muted">{h.url}</small>
                      {open === h.id && (
                        <Deliveries id={h.id} onChange={hooks.refresh} />
                      )}
                    </td>
                    <td>{h.customer_label}</td>
                    <td>{h.events.join(", ")}</td>
                    <td>
                      <Badge value={h.active ? "ACTIVE" : "INACTIVE"} />
                    </td>
                    <td>
                      <div className="actions">
                        <button
                          aria-expanded={open === h.id}
                          onClick={() =>
                            setOpen(open === h.id ? undefined : h.id)
                          }
                        >
                          Pengiriman
                        </button>
                        <button
                          onClick={() =>
                            void act(
                              () =>
                                api<{ data: { secret: string } }>(
                                  `webhooks/${h.id}/rotate-secret`,
                                  "POST",
                                ),
                              (r) =>
                                setSecret({
                                  title: `Secret baru ${h.name}`,
                                  value: r.data.secret,
                                }),
                            )
                          }
                        >
                          Ganti secret
                        </button>
                        <button
                          onClick={() =>
                            void act(() =>
                              api(`webhooks/${h.id}/active`, "PUT", {
                                active: !h.active,
                              }),
                            )
                          }
                        >
                          {h.active ? "Nonaktifkan" : "Aktifkan"}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada webhook.</Empty>
        )}
      </section>
      {dialog === "client" && customers && (
        <EditorDialog
          title="Klien API baru"
          fields={[
            customerField,
            {
              name: "name",
              label: "Nama klien (mis. ERP pelanggan)",
              value: "",
            },
            {
              name: "scopes",
              label: "Hak akses",
              kind: "select",
              options: SCOPE_SETS,
              value: SCOPE_SETS[0].value,
            },
            {
              name: "expires_at",
              label: "Berlaku sampai (opsional)",
              kind: "date",
              required: false,
              value: "",
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            const r = (await submitRecord(
              "api-clients",
              { ...body, scopes: String(body.scopes).split(",") },
              key,
            )) as { data: { token: string } };
            setDialog(null);
            setSecret({ title: "Token klien API", value: r.data.token });
            clients.refresh();
          }}
        />
      )}
      {dialog === "hook" && customers && (
        <EditorDialog
          title="Webhook baru"
          fields={[
            customerField,
            { name: "name", label: "Nama", value: "" },
            { name: "url", label: "URL tujuan (https)", value: "" },
            {
              name: "events",
              label: "Event",
              kind: "select",
              options: EVENT_SETS,
              value: EVENT_SETS[0].value,
            },
          ]}
          onClose={() => setDialog(null)}
          onSubmit={async (body, key) => {
            const r = (await submitRecord(
              "webhooks",
              { ...body, events: String(body.events).split(",") },
              key,
            )) as { data: { secret: string } };
            setDialog(null);
            setSecret({ title: "Secret penandatangan", value: r.data.secret });
            hooks.refresh();
          }}
        />
      )}
    </>
  );
}
