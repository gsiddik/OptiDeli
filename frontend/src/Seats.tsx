import { useData } from "./api";
import { useSession } from "./App";
import { can } from "./opsTypes";

type Summary = {
  internal: number;
  vendor: number;
  total: number;
  peak_this_month: number;
  included: number | null;
  above_included: number | null;
};

/** The tenant's seats: who takes one, what the subscription includes and what is above it. Informational: nobody is refused for being over. */
export function SeatsCard() {
  const session = useSession();
  const allowed =
    can(session, "access.user.manage") ||
    can(session, "vendor.portal.provision");
  return allowed ? <SeatsBody /> : null;
}

function SeatsBody() {
  const state = useData<{ data: Summary }>("seats");
  const d = state.data?.data;
  if (!d) return null;
  return (
    <section className="card block" aria-label="Kursi">
      <h2>Kursi</h2>
      <p>
        <strong>{d.total}</strong> kursi dipakai: {d.internal} staf tenant dan{" "}
        {d.vendor} pengguna vendor. Puncak bulan ini{" "}
        <strong>{d.peak_this_month}</strong>.
      </p>
      <p className="muted">
        {d.included === null
          ? "Jumlah kursi yang termasuk dalam langganan belum diketahui."
          : d.above_included
            ? `Langganan menyertakan ${d.included} kursi; ${d.above_included} kursi di atasnya dikenai biaya tambahan oleh OptiNexus.`
            : `Langganan menyertakan ${d.included} kursi; pemakaian masih dalam batas itu.`}{" "}
        Menambah pengguna tidak pernah ditolak; pengguna pelanggan tidak
        mengambil kursi.
      </p>
    </section>
  );
}
