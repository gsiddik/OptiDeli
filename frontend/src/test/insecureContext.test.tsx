import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { newId } from "../uuid";

/**
 * `crypto.randomUUID` exists only in a secure context (HTTPS or localhost). An installation reached over plain http on a real host
 * name (http://deli.localtest.me:5200 in the local trial) does not have it, and a call during render used to blank the whole page.
 * These tests take it away and render every screen.
 */
const everything = [
  "enterprise.manage",
  "operations.manage",
  "operations.view",
  "execution.verify",
  "finance.access",
  "policy.manage",
  "access.role.view",
  "access.role.manage",
  "access.user.manage",
  "dispatch.approve.level1",
  "dispatch.approve.level2",
  "dispatch.approve.level3",
  "freight.approve.finance",
  "vendor.portal.provision",
];
const session = {
  id: 1,
  name: "Operator",
  role: "optinexus",
  permissions: everything,
  tenant: {
    name: "Tenant A",
    slug: "a",
    minimum_margin_bps: 1500,
    stage_margin_bps: {},
  },
  deployment_mode: "saas",
  identity_mode: "optinexus",
};

const realCrypto = globalThis.crypto;
function withoutRandomUuid() {
  vi.stubGlobal("crypto", {
    getRandomValues: (a: Uint8Array<ArrayBuffer>) =>
      realCrypto.getRandomValues(a),
  });
}
/** An empty but correctly shaped answer for every endpoint the screens read. */
function answer(url: string): unknown {
  const path = url.replace(/^.*\/api\/v1\//, "");
  if (path.includes("auth/me")) return { data: session };
  if (path.startsWith("yard")) return { data: { docks: [], appointments: [] } };
  if (path.startsWith("fleet"))
    return { data: { vehicles: [], drivers: [], allocations: [] } };
  if (path.startsWith("commercial/policy"))
    return { data: { minimum_margin_bps: 1500, stage_margin_bps: {} } };
  if (path.startsWith("dashboard"))
    return {
      data: {
        orders: [],
        approval_pending: 0,
        fleet_available: 0,
        outbox_pending: 0,
      },
    };
  return { data: [], current_page: 1, last_page: 1, total: 0 };
}
function api() {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async (path: string) =>
        new Response(JSON.stringify(answer(path)), { status: 200 }),
    ),
  );
}
afterEach(() => vi.unstubAllGlobals());
// jsdom has no <dialog>.showModal; real browsers do.
HTMLDialogElement.prototype.showModal ??= function showModal(
  this: HTMLDialogElement,
) {
  this.setAttribute("open", "");
};
HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement) {
  this.removeAttribute("open");
};

describe("without crypto.randomUUID (plain http on a real host name)", () => {
  it("newId still returns a unique version 4 UUID", () => {
    withoutRandomUuid();
    const ids = new Set(Array.from({ length: 200 }, () => newId()));
    expect(ids.size).toBe(200);
    for (const id of ids)
      expect(id).toMatch(
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
      );
  });

  it("newId falls back even when there is no crypto at all", () => {
    vi.stubGlobal("crypto", undefined);
    expect(newId()).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });

  const screens: [string, string][] = [
    ["/orders/new", "Buat order pengiriman"],
    ["/dispatch", "Board alokasi & persetujuan"],
    ["/yard", "Reservasi & pergerakan yard"],
    ["/vendors", "Vendor & akses portal"],
    ["/fleet", "Armada & pengemudi"],
    ["/trips", "Perjalanan transportasi"],
    ["/freight", "Antrean audit & discrepancy"],
    ["/policy", "Kebijakan margin"],
    ["/access", "Akses & role"],
    ["/audit", "Jejak audit"],
    ["/orders", "Pesanan transportasi"],
    ["/", "Ringkasan transportasi"],
  ];
  it.each(screens)("%s renders its heading", async (path, heading) => {
    withoutRandomUuid();
    api();
    render(
      <MemoryRouter initialEntries={[path]}>
        <App />
      </MemoryRouter>,
    );
    await waitFor(() =>
      expect(
        screen.getByRole("heading", { level: 1, name: heading }),
      ).toBeTruthy(),
    );
  });

  const actions: [string, string, string][] = [
    ["/yard", "Daftarkan dock", "Nama hub"],
    ["/vendors", "Daftarkan vendor", "Nama perusahaan vendor"],
  ];
  it.each(actions)(
    "%s: the register form opens (%s)",
    async (path, button, field) => {
      withoutRandomUuid();
      api();
      render(
        <MemoryRouter initialEntries={[path]}>
          <App />
        </MemoryRouter>,
      );
      fireEvent.click(await screen.findByRole("button", { name: button }));
      await waitFor(() => expect(screen.getByLabelText(field)).toBeTruthy());
    },
  );

  it("shows a message instead of a blank page when a screen fails to render", async () => {
    withoutRandomUuid();
    vi.stubGlobal(
      "fetch",
      vi.fn(async (path: string) =>
        path.includes("auth/me")
          ? new Response(JSON.stringify({ data: session }), { status: 200 })
          : new Response(JSON.stringify({ data: { unexpected: true } }), {
              status: 200,
            }),
      ),
    );
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    render(
      <MemoryRouter initialEntries={["/orders"]}>
        <App />
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
    expect(
      screen.getByRole("navigation", { name: "Navigasi utama" }),
    ).toBeTruthy();
  });
});
