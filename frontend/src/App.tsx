import { createContext, useContext, useEffect, useState } from "react";
import {
  Link,
  NavLink,
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { api, ApiError, csrf, useData } from "./api";
import { ErrorBox, Field, Form, Icon, Loading } from "./components";
import type { Session } from "./types";
import { roles } from "./types";
import { OrderCreate, OrderDetail, OrderList } from "./Orders";
import { DispatchBoard } from "./Dispatch";
import { PolicyPage } from "./Policy";
import { TripList, TripDetail } from "./Trips";
import { YardPage } from "./Yard";
import { VendorsPage } from "./Vendors";
import { FreightPage, FreightDetail } from "./Freight";
import { VendorPortal } from "./VendorPortal";
import { FleetPage } from "./Fleet";
import { AuditPage, Dashboard, Roadmap } from "./Overview";
const SessionContext = createContext<Session | null>(null);
export function useSession() {
  const s = useContext(SessionContext);
  if (!s) throw new Error("Sesi diperlukan");
  return s;
}
function Brand() {
  const [missing, setMissing] = useState(false);
  return (
    <Link to="/" className="brand" aria-label="OptiDeli beranda">
      {!missing ? (
        <div className="brand-image">
          <img
            src="/brand/Logo%20OptiDeli%20-%201.jpg"
            alt="OptiDeli"
            onError={() => setMissing(true)}
          />
        </div>
      ) : (
        <span>
          Opti<span>Deli</span>
        </span>
      )}
    </Link>
  );
}
function Login({ onLogin }: { onLogin: () => void }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [tenant, setTenant] = useState("");
  return (
    <main className="login">
      <section className="login-story">
        <Brand />
        <span className="eyebrow">TRANSPORT MANAGEMENT SYSTEM</span>
        <h1>
          Satu alur.
          <br />
          Setiap perjalanan.
        </h1>
        <p>
          Kelola pesanan, kesiapan armada, dan keputusan dispatch dengan kontrol
          operasional yang jelas.
        </p>
        <div className="stages">
          <span>First mile</span>
          <span>Middle mile</span>
          <span>Last mile</span>
        </div>
      </section>
      <section className="login-form">
        <span className="eyebrow">SELAMAT DATANG</span>
        <h2>Masuk ke ruang operasional</h2>
        <p className="muted">Gunakan akun dan tenant perusahaan Anda.</p>
        <Form
          label="Masuk"
          onSubmit={async () => {
            await api("auth/login", "POST", { email, password, tenant });
            await csrf();
            onLogin();
          }}
        >
          <Field label="Tenant">
            <input
              autoComplete="organization"
              value={tenant}
              onChange={(e) => setTenant(e.target.value)}
              required
              maxLength={80}
            />
          </Field>
          <Field label="Email">
            <input
              autoComplete="username"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </Field>
          <Field label="Kata sandi">
            <input
              autoComplete="current-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              maxLength={1024}
            />
          </Field>
        </Form>
        <small className="muted">
          Akses dibatasi sesuai tenant dan kewenangan akun.
        </small>
      </section>
    </main>
  );
}
const navigation = [
  {
    group: "OPERASIONAL",
    items: [
      { to: "/", label: "Ringkasan", icon: "home" },
      { to: "/orders", label: "Pemesanan", icon: "orders" },
      { to: "/dispatch", label: "Board dispatch", icon: "dispatch" },
      { to: "/fleet", label: "Armada & pengemudi", icon: "fleet" },
      { to: "/trips", label: "Eksekusi perjalanan", icon: "dispatch" },
      { to: "/yard", label: "Dock & yard", icon: "fleet" },
      { to: "/vendors", label: "Vendor & portal", icon: "fleet" },
      { to: "/freight", label: "Freight audit", icon: "audit" },
    ],
  },
  {
    group: "GOVERNANCE",
    items: [
      { to: "/policy", label: "Kebijakan margin", icon: "audit" },
      { to: "/audit", label: "Jejak audit", icon: "audit" },
      { to: "/roadmap", label: "Cakupan produk", icon: "roadmap" },
    ],
  },
];
function Shell({ onLogout }: { onLogout: () => Promise<void> }) {
  const session = useSession();
  const location = useLocation();
  const navigate = useNavigate();
  const [compact, setCompact] = useState(() => {
    try {
      return localStorage.getItem("optideli.sidebar") === "compact";
    } catch {
      return false;
    }
  });
  const [mobile, setMobile] = useState(false);
  const [groups, setGroups] = useState<Record<string, boolean>>({});
  const [query, setQuery] = useState("");
  const [logoutError, setLogoutError] = useState<unknown>();
  useEffect(() => {
    setMobile(false);
    document.querySelector<HTMLElement>("main h1")?.focus();
  }, [location.pathname]);
  useEffect(() => {
    try {
      localStorage.setItem(
        "optideli.sidebar",
        compact ? "compact" : "expanded",
      );
    } catch {
      /* Preference is optional. */
    }
  }, [compact]);
  const section = navigation
    .flatMap((g) => g.items)
    .find((i) => i.to !== "/" && location.pathname.startsWith(i.to));
  const current =
    location.pathname === "/orders/new"
      ? "Buat order"
      : /^\/orders\/[^/]+$/.test(location.pathname)
        ? "Detail order"
        : (section?.label ?? "Ringkasan");
  return (
    <div className={`shell ${compact ? "compact" : ""}`}>
      <a href="#content" className="skip">
        Lewati navigasi
      </a>
      {mobile && (
        <button
          className="overlay"
          aria-label="Tutup menu"
          onClick={() => setMobile(false)}
        />
      )}
      <aside className={mobile ? "open" : ""}>
        <Brand />
        <div className="sidebar-tools">
          <button
            onClick={() => setCompact((v) => !v)}
            aria-label={compact ? "Perluas sidebar" : "Perkecil sidebar"}
            aria-expanded={!compact}
          >
            <Icon name="menu" />
            <span>{compact ? "Perluas" : "Perkecil menu"}</span>
          </button>
        </div>
        <div className="nav-search">
          <input
            aria-label="Cari menu"
            placeholder="Cari menu…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <nav aria-label="Navigasi utama">
          {navigation.map((group) => {
            const items = group.items.filter((i) =>
              i.label.toLowerCase().includes(query.toLowerCase()),
            );
            return (
              items.length > 0 && (
                <div className="nav-group" key={group.group}>
                  <button
                    className="group-toggle"
                    onClick={() =>
                      setGroups((v) => ({
                        ...v,
                        [group.group]: !(v[group.group] ?? true),
                      }))
                    }
                    aria-expanded={groups[group.group] ?? true}
                  >
                    <span>{group.group}</span>
                    <span>{(groups[group.group] ?? true) ? "−" : "+"}</span>
                  </button>
                  {((groups[group.group] ?? true) || query || compact) &&
                    items.map((item) => (
                      <NavLink
                        end={item.to === "/"}
                        title={item.label}
                        to={item.to}
                        key={item.to}
                      >
                        <Icon name={item.icon} />
                        <span>{item.label}</span>
                      </NavLink>
                    ))}
                </div>
              )
            );
          })}
        </nav>
        <div className="sidebar-foot">
          <span className="status-dot" />
          <span>Workspace operasional</span>
        </div>
      </aside>
      <div className="workspace">
        <header>
          <div className="header-left">
            <button
              className="mobile-toggle"
              onClick={() => setMobile((v) => !v)}
              aria-expanded={mobile}
              aria-label="Buka menu"
            >
              <Icon name="menu" />
            </button>
            <strong>{session.tenant.name}</strong>
            <span className="header-separator" />
            <span className="muted">Transport Management</span>
          </div>
          <div className="identity">
            <div>
              <strong>{session.name}</strong>
              <small>{roles[session.role] ?? session.role}</small>
            </div>
            <button
              onClick={() => {
                setLogoutError(undefined);
                void onLogout().catch(setLogoutError);
              }}
            >
              Keluar
            </button>
          </div>
        </header>
        <main id="content">
          <div className="breadcrumb-row">
            <nav className="breadcrumb" aria-label="Breadcrumb">
              <Link to="/">Beranda</Link>
              {section && (
                <>
                  <span>/</span>
                  <Link to={section.to}>{section.label}</Link>
                </>
              )}
              {current !== section?.label && location.pathname !== "/" && (
                <>
                  <span>/</span>
                  <span aria-current="page">{current}</span>
                </>
              )}
            </nav>
            {location.pathname !== "/" && (
              <button
                className="back"
                onClick={() => {
                  if (location.key !== "default") navigate(-1);
                  else
                    navigate(
                      section?.to === location.pathname
                        ? "/"
                        : (section?.to ?? "/"),
                    );
                }}
              >
                <Icon name="back" /> Kembali
              </button>
            )}
          </div>
          <ErrorBox error={logoutError} />
          <Outlet />
        </main>
        <footer>
          OptiDeli <span>First mile · Middle mile · Last mile</span>
        </footer>
      </div>
    </div>
  );
}
export default function App() {
  const auth = useData<{ data: Session }>("auth/me");
  const [loggedOut, setLoggedOut] = useState(false);
  if (auth.loading) return <Loading />;
  if (loggedOut || !auth.data) {
    if (auth.error && !loggedOut && !auth.error.includes("Unauthenticated"))
      return (
        <div className="login-error">
          <ErrorBox error={auth.error} />
          <div className="actions">
            <button onClick={auth.refresh}>Coba lagi</button>
            <button
              onClick={() => {
                void api("auth/logout", "POST")
                  .then(() => setLoggedOut(true))
                  .catch(() => auth.refresh());
              }}
            >
              Kembali ke login
            </button>
          </div>
        </div>
      );
    return (
      <Login
        onLogin={() => {
          setLoggedOut(false);
          auth.refresh();
        }}
      />
    );
  }
  if (auth.data.data.role === "vendor")
    return (
      <SessionContext.Provider value={auth.data.data}>
        <VendorPortal
          onLogout={async () => {
            await api("auth/logout", "POST");
            setLoggedOut(true);
          }}
        />
      </SessionContext.Provider>
    );
  return (
    <SessionContext.Provider value={auth.data.data}>
      <Routes>
        <Route
          element={
            <Shell
              onLogout={async () => {
                await api("auth/logout", "POST");
                setLoggedOut(true);
              }}
            />
          }
        >
          <Route index element={<Dashboard />} />
          <Route path="orders" element={<OrderList />} />
          <Route path="orders/new" element={<OrderCreate />} />
          <Route path="orders/:id" element={<OrderDetail />} />
          <Route path="dispatch" element={<DispatchBoard />} />
          <Route path="fleet" element={<FleetPage />} />
          <Route path="trips" element={<TripList />} />
          <Route path="trips/:id" element={<TripDetail />} />
          <Route path="yard" element={<YardPage />} />
          <Route path="vendors" element={<VendorsPage />} />
          <Route path="freight" element={<FreightPage />} />
          <Route path="freight/:id" element={<FreightDetail />} />
          <Route path="policy" element={<PolicyPage />} />
          <Route path="audit" element={<AuditPage />} />
          <Route path="roadmap" element={<Roadmap />} />
          <Route path="*" element={<NotFound />} />
        </Route>
        <Route path="login" element={<Navigate to="/" replace />} />
      </Routes>
    </SessionContext.Provider>
  );
}
function NotFound() {
  return (
    <section className="card">
      <h1 tabIndex={-1}>Halaman tidak ditemukan</h1>
      <p>Alamat ini tidak tersedia.</p>
      <Link to="/">Kembali ke ringkasan</Link>
    </section>
  );
}
// Exported for tests which exercise loading/error states without bypassing the server authorization boundary.
export { ApiError };
