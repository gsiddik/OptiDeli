import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

beforeAll(() => {
  HTMLDialogElement.prototype.showModal ??= function showModal(
    this: HTMLDialogElement,
  ) {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close ??= function close(
    this: HTMLDialogElement,
  ) {
    this.removeAttribute("open");
  };
});
afterEach(() => vi.unstubAllGlobals());

const session = (permissions: string[]) => ({
  id: 7,
  name: "Operator",
  role: "optinexus",
  permissions,
  tenant: {
    name: "Tenant A",
    slug: "a",
    minimum_margin_bps: 1500,
    stage_margin_bps: {},
  },
  deployment_mode: "saas",
});
const S1 = "11111111-1111-4111-8111-111111111111";
const O1 = "22222222-2222-4222-8222-222222222222";
const row = (over = {}) => ({
  id: S1,
  number: "SPM-202610-00001",
  order_id: O1,
  order_number: "OD-1",
  sequence: 1,
  status: "DRAFT",
  customer_label: "PT Maju",
  origin_text: "Pool Jakarta",
  destination_text: "Gudang Bandung",
  planned_pickup_at: null,
  reference: null,
  notes: null,
  status_reason: null,
  planned_delivery_at: null,
  carrier_type_id: null,
  vendor_id: null,
  ...over,
});
const detail = (status: string, buy = true) => ({
  spm: row({ status }),
  order: {
    id: O1,
    number: "OD-1",
    customer_name: "PT Maju",
    status: "CONFIRMED",
  },
  awbs: [],
  lines: [
    {
      id: "33333333-3333-4333-8333-333333333333",
      line_no: 1,
      po_number: "PO-1",
      so_number: null,
      material: "Palet",
      quantity: "4.000",
      pallets: null,
      volume_cbm: "4.000",
      weight_kg: "100.000",
      seal_number: null,
      damage_qty: "0.000",
      loss_qty: "0.000",
      over_qty: "0.000",
      notes: null,
    },
  ],
  charges: [
    {
      id: "44444444-4444-4444-8444-444444444444",
      side: "SELL",
      charge_code_label: "FREIGHT · Ongkos angkut",
      kind: "PLAN",
      source: "RATE",
      basis: "TRIP",
      quantity: "1.000",
      unit_price_minor: 200000000,
      amount_minor: 200000000,
      rate_label: "JKT v1",
      markup_code: null,
      reason: null,
      status: "PROPOSED",
    },
  ],
  history: [
    {
      id: "55555555-5555-4555-8555-555555555555",
      from_status: null,
      to_status: "DRAFT",
      reason: null,
      actor_name: "Operator",
      created_at: "2026-10-26T01:00:00Z",
    },
  ],
  totals: buy
    ? { sell_minor: 200000000, buy_minor: 150000000, margin_minor: 50000000 }
    : { sell_minor: 200000000 },
  siblings: [{ id: S1, number: "SPM-202610-00001", sequence: 1, status }],
});

function stub(permissions: string[], status = "DRAFT", buy = true) {
  const calls: { method: string; path: string; body?: unknown }[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const method = init?.method ?? "GET";
      calls.push({
        method,
        path,
        body:
          typeof init?.body === "string" ? JSON.parse(init.body) : undefined,
      });
      const json = (data: unknown, st = 200) =>
        new Response(JSON.stringify(data), { status: st });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({ data: session(permissions) });
      if (path === `spm/${S1}/status`)
        return json({
          data: { spm: detail("PLANNED"), warnings: ["Tarif belum ada"] },
        });
      if (path === `orders/${O1}/spm` && method === "POST")
        return json({ data: detail("DRAFT") }, 201);
      if (path === `spm/${S1}`) return json({ data: detail(status, buy) });
      if (path.startsWith("spm"))
        return json({
          data: [row()],
          current_page: 1,
          last_page: 1,
          total: 1,
        });
      if (path === `orders/${O1}`)
        return json({
          data: {
            id: O1,
            number: "OD-1",
            customer_name: "PT Maju",
            reference: null,
            status: "CONFIRMED",
            stage: "FIRST_MILE",
            origin: "A",
            destination: "B",
            created_at: "2026-10-26T01:00:00Z",
            base_price_minor: 1,
            surcharge_minor: 0,
            accessorial_minor: 0,
            sell_minor: 1,
            items: [],
          },
        });
      if (path.startsWith("master/") || path === "vendors")
        return json({ data: [] });
      return json({ data: [], current_page: 1, last_page: 1, total: 0 });
    }),
  );
  return calls;
}
const open = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );

describe("SPM", () => {
  it("lists SPMs with their order and status", async () => {
    stub(["operations.view"]);
    open("/spm");
    expect(
      await screen.findByRole("link", { name: "SPM-202610-00001" }),
    ).toBeTruthy();
    expect(screen.getByRole("link", { name: "OD-1" })).toBeTruthy();
    expect(screen.getByText("DRAFT")).toBeTruthy();
  });

  it("offers only the steps the user may take and plans with a confirmation", async () => {
    const calls = stub(["operations.view", "spm.manage"]);
    open(`/spm/${S1}`);
    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: "SPM-202610-00001",
      }),
    ).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Ganti tujuan" })).toBeNull();
    await userEvent.click(
      screen.getByRole("button", { name: "Rencanakan & hitung tarif" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Ya, lanjutkan/ }),
    );
    await waitFor(() =>
      expect(calls.find((c) => c.path === `spm/${S1}/status`)?.body).toEqual({
        to: "PLANNED",
      }),
    );
    expect(await screen.findByText("Tarif belum ada")).toBeTruthy();
  });

  it("cancelling asks for a reason", async () => {
    const calls = stub(["operations.view", "spm.manage"]);
    open(`/spm/${S1}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "Batalkan SPM" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText(/Alasan/),
      "Pelanggan membatalkan",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(calls.find((c) => c.path === `spm/${S1}/status`)?.body).toEqual({
        to: "CANCELLED",
        reason: "Pelanggan membatalkan",
      }),
    );
  });

  it("shows buying prices and margin only when they come back from the server", async () => {
    stub(["operations.view", "rate.buy.view"], "PLANNED", true);
    open(`/spm/${S1}`);
    expect(await screen.findByText("Total beli")).toBeTruthy();
    expect(screen.getByText("Margin")).toBeTruthy();
  });

  it("hides them from a user without the permission", async () => {
    stub(["operations.view"], "PLANNED", false);
    open(`/spm/${S1}`);
    await screen.findByText("Total jual");
    expect(screen.queryByText("Total beli")).toBeNull();
    expect(screen.queryByText("Margin")).toBeNull();
  });

  it("makes an SPM from the order page", async () => {
    const calls = stub(["operations.view", "spm.manage"]);
    open(`/orders/${O1}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "+ Buat SPM" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.some((c) => c.method === "POST" && c.path === `orders/${O1}/spm`),
      ).toBe(true),
    );
    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: "SPM-202610-00001",
      }),
    ).toBeTruthy();
  });

  it("prints a sheet with the lines", async () => {
    stub(["operations.view"]);
    open(`/spm/${S1}/print`);
    expect(
      await screen.findByRole("heading", { name: "SURAT PERINTAH MUAT" }),
    ).toBeTruthy();
    expect(screen.getByText("Palet")).toBeTruthy();
    expect(screen.getByRole("button", { name: /Cetak/ })).toBeTruthy();
  });
});
