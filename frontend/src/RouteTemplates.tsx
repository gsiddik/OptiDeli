import { useEffect, useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { Modal } from "./Cash";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Template = {
  id: string;
  code: string;
  name: string;
  notes: string | null;
  active: boolean;
  stop_count: number;
  carrier_type_id: string | null;
  service_id: string | null;
};
type Stop = {
  location_id: string;
  type: "PICKUP" | "DROP";
  offset_minutes: number;
  location_label?: string;
};
type Opt = { value: string; label: string };

/** Route templates: the order and the timing of the stops that a trip can start from. */
export function RouteTemplatesPage() {
  const session = useSession();
  const canEdit = can(session, "spm.manage");
  const state = useData<{ data: Template[] }>("route-templates");
  const [dialog, setDialog] = useState<{ id?: string } | null>(null);
  const [locations, setLocations] = useState<Opt[]>([]);
  useEffect(() => {
    let live = true;
    api<{ data: { id: string; code: string; name: string }[] }>(
      "master/locations/options",
    )
      .then(
        (r) =>
          live &&
          setLocations(
            r.data.map((o) => ({
              value: o.id,
              label: `${o.code} · ${o.name}`,
            })),
          ),
      )
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">PERENCANAAN</span>
          <h1 tabIndex={-1}>Template rute</h1>
          <p>
            Urutan stop dan menit sejak berangkat. Dipilih saat merencanakan
            perjalanan, atau disimpan dari perjalanan yang sudah ada.
          </p>
        </div>
        {canEdit && (
          <button className="primary" onClick={() => setDialog({})}>
            + Template rute
          </button>
        )}
      </div>
      <ErrorBox error={state.error} />
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : state.data?.data.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Kode</th>
                  <th>Nama</th>
                  <th>Stop</th>
                  <th>Status</th>
                  {canEdit && <th>Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {state.data.data.map((t) => (
                  <tr key={t.id}>
                    <td>{t.code}</td>
                    <td>{t.name}</td>
                    <td>{t.stop_count}</td>
                    <td>
                      <Badge value={t.active ? "ACTIVE" : "INACTIVE"} />
                    </td>
                    {canEdit && (
                      <td>
                        <div className="actions">
                          <button onClick={() => setDialog({ id: t.id })}>
                            Ubah
                          </button>
                          <button
                            onClick={() =>
                              void api(
                                `route-templates/${t.id}/status`,
                                "POST",
                                {
                                  active: !t.active,
                                },
                              ).then(state.refresh)
                            }
                          >
                            {t.active ? "Nonaktifkan" : "Aktifkan"}
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty>Belum ada template rute.</Empty>
        )}
      </section>
      {dialog && (
        <TemplateDialog
          id={dialog.id}
          locations={locations}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            state.refresh();
          }}
        />
      )}
    </>
  );
}

function TemplateDialog({
  id,
  locations,
  onClose,
  onDone,
}: {
  id?: string;
  locations: Opt[];
  onClose: () => void;
  onDone: () => void;
}) {
  const existing = useData<{
    data: { template: Template; stops: Stop[] };
  }>(id ? `route-templates/${id}` : "route-templates");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [stops, setStops] = useState<Stop[]>([
    { location_id: "", type: "PICKUP", offset_minutes: 0 },
    { location_id: "", type: "DROP", offset_minutes: 120 },
  ]);
  const [loaded, setLoaded] = useState(!id);
  useEffect(() => {
    const d = existing.data?.data;
    if (id && d && "template" in d && !loaded) {
      setCode(d.template.code);
      setName(d.template.name);
      setStops(
        d.stops.map((s) => ({
          location_id: s.location_id,
          type: s.type,
          offset_minutes: s.offset_minutes,
        })),
      );
      setLoaded(true);
    }
  }, [existing.data, id, loaded]);
  const set = (i: number, patch: Partial<Stop>) =>
    setStops((all) => all.map((s, j) => (j === i ? { ...s, ...patch } : s)));
  if (!loaded) return null;
  return (
    <Modal
      title={id ? "Ubah template rute" : "Template rute baru"}
      onClose={onClose}
    >
      <Form
        label="Simpan"
        onSubmit={async () => {
          const body = {
            name,
            ...(id ? {} : { code }),
            stops: stops.map((s, i) => ({
              ...s,
              type: i === 0 ? "PICKUP" : "DROP",
              offset_minutes: Number(s.offset_minutes),
            })),
          };
          await api(
            id ? `route-templates/${id}` : "route-templates",
            id ? "PUT" : "POST",
            body,
          );
          onDone();
        }}
      >
        {!id && (
          <Field label="Kode">
            <input
              required
              value={code}
              onChange={(e) => setCode(e.target.value)}
            />
          </Field>
        )}
        <Field label="Nama">
          <input
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </Field>
        {stops.map((s, i) => (
          <fieldset key={i} className="form-grid">
            <legend>{i === 0 ? "Stop muat" : `Stop bongkar ${i}`}</legend>
            <Field label={`Lokasi ${i + 1}`}>
              <Select
                required
                value={s.location_id}
                onChange={(e) => set(i, { location_id: e.target.value })}
              >
                <option value="">Pilih…</option>
                {locations.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={`Menit sejak berangkat ${i + 1}`}>
              <input
                type="number"
                min={0}
                required
                value={s.offset_minutes}
                onChange={(e) =>
                  set(i, { offset_minutes: Number(e.target.value) })
                }
              />
            </Field>
          </fieldset>
        ))}
        <div className="actions">
          <button
            type="button"
            onClick={() =>
              setStops((all) => [
                ...all,
                {
                  location_id: "",
                  type: "DROP",
                  offset_minutes: (all.at(-1)?.offset_minutes ?? 0) + 60,
                },
              ])
            }
          >
            + Stop bongkar
          </button>
          {stops.length > 2 && (
            <button
              type="button"
              onClick={() => setStops((all) => all.slice(0, -1))}
            >
              Hapus stop terakhir
            </button>
          )}
        </div>
      </Form>
    </Modal>
  );
}
