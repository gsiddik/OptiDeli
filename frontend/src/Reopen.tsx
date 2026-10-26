import { useState } from "react";
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
import { Modal } from "./Cash";
import { dateTime } from "./commercial";
import { can } from "./opsTypes";
import type { Page } from "./types";

type Request = {
  id: string;
  transport_order_id: string;
  spm_number: string | null;
  spm_status: string | null;
  from_status: string;
  reason: string;
  status: "PENDING" | "APPROVED" | "REJECTED" | "CANCELLED";
  requested_by: number;
  requested_by_name: string | null;
  decided_by_name: string | null;
  decision_note: string | null;
  created_at: string;
};

/** The queue of requests to re-open an SPM: decided by someone other than the requester. */
export function ReopenQueuePage() {
  const session = useSession();
  const [status, setStatus] = useState("PENDING");
  const [page, setPage] = useState(1);
  const state = useData<Page<Request>>(
    `spm-reopen-requests?status=${status}&page=${page}`,
  );
  const [decision, setDecision] = useState<{
    request: Request;
    approve: boolean;
  } | null>(null);
  const [note, setNote] = useState("");
  const decide = can(session, "spm.reopen");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">SPM</span>
          <h1 tabIndex={-1}>Antrean buka kembali SPM</h1>
          <p>
            SPM yang sudah selesai bongkar hanya dibuka kembali lewat permintaan
            beralasan yang disetujui orang lain.
          </p>
        </div>
      </div>
      <div className="actions">
        <select
          aria-label="Status"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="PENDING">Menunggu keputusan</option>
          <option value="APPROVED">Disetujui</option>
          <option value="REJECTED">Ditolak</option>
          <option value="CANCELLED">Ditarik</option>
          <option value="">Semua</option>
        </select>
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
                      <th>SPM</th>
                      <th>Status SPM</th>
                      <th>Alasan</th>
                      <th>Pemohon</th>
                      <th>Keputusan</th>
                      <th>Status</th>
                      {decide && <th>Aksi</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((r) => (
                      <tr key={r.id}>
                        <td>
                          <Link to={`/spm/${r.transport_order_id}`}>
                            {r.spm_number}
                          </Link>
                        </td>
                        <td>{r.spm_status}</td>
                        <td>{r.reason}</td>
                        <td>
                          {r.requested_by_name}
                          <br />
                          <small className="muted">
                            {dateTime(r.created_at)}
                          </small>
                        </td>
                        <td>
                          {r.decided_by_name
                            ? `${r.decided_by_name}${r.decision_note ? `: ${r.decision_note}` : ""}`
                            : "—"}
                        </td>
                        <td>
                          <Badge value={r.status} />
                        </td>
                        {decide && (
                          <td>
                            {r.status === "PENDING" && (
                              <div className="actions">
                                <button
                                  className="primary"
                                  disabled={r.requested_by === session.id}
                                  title={
                                    r.requested_by === session.id
                                      ? "Diputuskan oleh orang lain."
                                      : undefined
                                  }
                                  onClick={() => {
                                    setNote("");
                                    setDecision({ request: r, approve: true });
                                  }}
                                >
                                  Setujui
                                </button>
                                <button
                                  disabled={r.requested_by === session.id}
                                  onClick={() => {
                                    setNote("");
                                    setDecision({ request: r, approve: false });
                                  }}
                                >
                                  Tolak
                                </button>
                              </div>
                            )}
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && <Empty>Tidak ada permintaan.</Empty>}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
      {decision && (
        <Modal
          title={`${decision.approve ? "Setujui" : "Tolak"} buka kembali ${decision.request.spm_number}`}
          onClose={() => setDecision(null)}
        >
          <Form
            label="Lanjutkan"
            onSubmit={async () => {
              await api(
                `spm-reopen-requests/${decision.request.id}/${decision.approve ? "approve" : "reject"}`,
                "POST",
                { note: note || null },
              );
              setDecision(null);
              state.refresh();
            }}
          >
            <p>Alasan pemohon: {decision.request.reason}</p>
            <Field
              label={
                decision.approve
                  ? "Catatan (opsional)"
                  : "Catatan penolakan (minimal 5 karakter)"
              }
            >
              <textarea
                required={!decision.approve}
                minLength={decision.approve ? undefined : 5}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </Field>
          </Form>
        </Modal>
      )}
    </>
  );
}
