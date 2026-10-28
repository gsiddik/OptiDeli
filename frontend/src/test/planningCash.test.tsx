import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { pick } from "./pick";

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
const TRIP = "11111111-1111-4111-8111-111111111111";
const SPM = "22222222-2222-4222-8222-222222222222";
const VCH = "33333333-3333-4333-8333-333333333333";
const STOP1 = "44444444-4444-4444-8444-444444444444";
const STOP2 = "55555555-5555-4555-8555-555555555555";
const LINE = "66666666-6666-4666-8666-666666666666";
const AWB = "77777777-7777-4777-8777-777777777777";
const CODE = "88888888-8888-4888-8888-888888888888";
const BOX = "99999999-9999-4999-8999-999999999999";

const trip = (status: string, createdBy = 99) => ({
  trip: {
    id: TRIP,
    number: "PJ-202610-00001",
    transport_order_id: SPM,
    spm_number: "SPM-202610-00001",
    status,
    vehicle_label: "B 1 AA · Box",
    driver_label: "Budi",
    starts_at: "2026-10-27T01:00:00Z",
    ends_at: "2026-10-27T05:00:00Z",
    awb_count: 1,
    vendor_label: null,
    sell_minor: 200000000,
    cost_minor: 100000000,
    required_level: 1,
    reason_code: "STRATEGIC",
    cancellation_reason: null,
    notes: null,
    created_by: createdBy,
  },
  spm: { id: SPM, number: "SPM-202610-00001", status: "PLANNED" },
  awbs: [
    {
      id: AWB,
      number: "AWB-202610-00001",
      status: "ON_TRIP",
      destination_text: "Gudang Bandung",
      consignee_name: "Toko A",
      weight_kg: 100,
    },
  ],
  stops: [
    {
      id: STOP1,
      sequence: 1,
      type: "PICKUP",
      location_label: "Pool Jakarta",
      planned_from: null,
      planned_to: null,
      arrived_at: null,
      departed_at: null,
      time_source: null,
      awb_numbers: [],
    },
    {
      id: STOP2,
      sequence: 2,
      type: "DROP",
      location_label: "Gudang Bandung",
      planned_from: null,
      planned_to: null,
      arrived_at: null,
      departed_at: null,
      time_source: null,
      awb_numbers: ["AWB-202610-00001"],
    },
  ],
});
const voucher = (status: string, requestedBy = 99) => ({
  voucher: {
    id: VCH,
    number: "CV-202610-00001",
    status,
    trip_id: TRIP,
    trip_number: "PJ-202610-00001",
    spm_number: "SPM-202610-00001",
    driver_label: "Budi",
    cashbox_label: null,
    purpose: "Uang jalan",
    requested_minor: 40000000,
    approved_minor: status === "REQUESTED" ? null : 40000000,
    disbursed_minor: ["DISBURSED"].includes(status) ? 40000000 : null,
    actual_minor: null,
    returned_minor: null,
    reimbursed_minor: null,
    requested_by: requestedBy,
    status_reason: null,
    created_at: "2026-10-26T01:00:00Z",
  },
  lines: [
    {
      id: LINE,
      charge_code_id: CODE,
      charge_code_label: "TOLL · Tol",
      kind: "UNPLAN",
      planned_minor: null,
      requested_minor: 40000000,
      approved_minor: status === "REQUESTED" ? null : 40000000,
      actual_minor: null,
      receipt_ref: null,
      reason: "Tol pergi-pulang",
    },
  ],
  ledger: [],
});
const spmDetail = () => ({
  spm: {
    id: SPM,
    number: "SPM-202610-00001",
    order_id: "a",
    order_number: "OD-1",
    sequence: 1,
    status: "PLANNED",
    customer_label: "PT Maju",
    origin_text: "Pool",
    destination_text: "Gudang",
    planned_pickup_at: null,
    reference: null,
    notes: null,
    status_reason: null,
    planned_delivery_at: null,
    carrier_type_id: null,
    vendor_id: null,
  },
  order: {
    id: "a",
    number: "OD-1",
    customer_name: "PT Maju",
    status: "DISPATCHED",
  },
  lines: [
    {
      id: LINE,
      line_no: 1,
      po_number: null,
      so_number: null,
      material: "Paket A",
      quantity: "1.000",
      pallets: null,
      volume_cbm: "1.000",
      weight_kg: "100.000",
      seal_number: null,
      damage_qty: "0",
      loss_qty: "0",
      over_qty: "0",
      notes: null,
      awb_id: null,
    },
  ],
  awbs: [
    {
      id: AWB,
      number: "AWB-202610-00001",
      status: "PENDING",
      destination_text: "Gudang Bandung",
      destination_location_id: "b",
      consignee_name: "Toko A",
      consignee_phone: null,
      notes: null,
      line_ids: [],
      weight_kg: 100,
      trip_id: null,
      trip_number: null,
    },
  ],
  charges: [],
  history: [],
  totals: { sell_minor: 0 },
  siblings: [
    { id: SPM, number: "SPM-202610-00001", sequence: 1, status: "PLANNED" },
  ],
});

function stub(permissions: string[], over: Record<string, unknown> = {}) {
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
      if (path in over)
        return json({ data: over[path] }, method === "POST" ? 201 : 200);
      if (path === "workqueue")
        return json({ data: { vouchers_to_approve: 2, spm_draft: 0 } });
      if (path === "master/charge_codes/options")
        return json({ data: [{ id: CODE, code: "TOLL", name: "Tol" }] });
      if (path === "fleet")
        return json({
          data: {
            vehicles: [{ id: "v1", plate: "B 1 AA", type: "Box" }],
            drivers: [{ id: "d1", name: "Budi" }],
            allocations: [],
          },
        });
      if (path.startsWith("master/") || path === "vendors")
        return json({ data: [] });
      if (path === "cash/overview")
        return json({
          data: {
            cashboxes: [
              {
                id: BOX,
                code: "KAS",
                name: "Kas utama",
                active: true,
                balance_minor: 450000000,
              },
            ],
            drivers: [
              { driver_id: "d1", driver_name: "Budi", balance_minor: 15000000 },
            ],
            approval_limit_minor: 500000000,
          },
        });
      if (path.startsWith("cash/vouchers?"))
        return json({ data: [], current_page: 1, last_page: 1, total: 0 });
      if (path.startsWith("spm-trips/") && path.includes("/stops/"))
        return json({
          data: { ...trip("IN_TRANSIT"), warnings: ["Peringatan stop"] },
        });
      if (path.startsWith("spm-trips/") && method === "POST")
        return json({ data: trip("PLANNED") });
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

describe("Trip planning", () => {
  it("records arrival at the next stop and shows the warning from the server", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      [`spm-trips/${TRIP}`]: trip("IN_TRANSIT"),
    });
    open(`/planning/${TRIP}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "Catat tiba" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.some(
          (c) =>
            c.method === "POST" &&
            c.path === `spm-trips/${TRIP}/stops/${STOP1}/arrive`,
        ),
      ).toBe(true),
    );
    expect(await screen.findByText("Peringatan stop")).toBeTruthy();
  });

  it("a trip held for margin cannot be approved by its planner", async () => {
    stub(["operations.view", "spm.manage", "dispatch.approve.level1"], {
      [`spm-trips/${TRIP}`]: trip("PENDING_APPROVAL", 7),
    });
    open(`/planning/${TRIP}`);
    const approve = await screen.findByRole("button", {
      name: "Setujui margin",
    });
    expect((approve as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText(/Margin di bawah threshold/)).toBeTruthy();
  });

  it("groups lines into an AWB and plans a trip for the chosen AWBs", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      [`spm/${SPM}`]: spmDetail(),
    });
    open(`/spm/${SPM}`);
    await userEvent.click(await screen.findByLabelText("Pilih baris 1"));
    await userEvent.click(
      screen.getByRole("button", { name: "+ AWB dari baris terpilih" }),
    );
    let dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `spm/${SPM}/awbs`)?.body,
      ).toMatchObject({
        line_ids: [LINE],
      }),
    );

    await userEvent.click(
      await screen.findByLabelText("Pilih AWB-202610-00001"),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Rencanakan perjalanan" }),
    );
    dialog = await screen.findByRole("dialog");
    await pick(within(dialog).getByLabelText("Kendaraan"), "v1");
    await pick(within(dialog).getByLabelText("Pengemudi"), "d1");
    await userEvent.type(
      within(dialog).getByLabelText("Mulai"),
      "2026-10-27T08:00",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Selesai"),
      "2026-10-27T12:00",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(calls.find((c) => c.path === "spm-trips")?.body).toMatchObject({
        spm_id: SPM,
        awb_ids: [AWB],
        vehicle_id: "v1",
        driver_id: "d1",
      }),
    );
  });

  it("lists the work queue", async () => {
    stub(["operations.view", "cash.approve"]);
    open("/planning");
    expect(await screen.findByText("Antrean kerja")).toBeTruthy();
    expect(screen.getByText(/Uang jalan menunggu ACC/)).toBeTruthy();
  });
});

describe("Driver cash", () => {
  it("the requester cannot approve; someone else approves with amounts per line", async () => {
    stub(["operations.view", "cash.view", "cash.approve"], {
      [`cash/vouchers/${VCH}`]: voucher("REQUESTED", 7),
    });
    open(`/cash/${VCH}`);
    const own = await screen.findByRole("button", { name: "Setujui" });
    expect((own as HTMLButtonElement).disabled).toBe(true);
  });

  it("approves with the amount cut on a line", async () => {
    const calls = stub(["operations.view", "cash.view", "cash.approve"], {
      [`cash/vouchers/${VCH}`]: voucher("REQUESTED"),
    });
    open(`/cash/${VCH}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "Setujui" }),
    );
    const dialog = await screen.findByRole("dialog");
    const field = within(dialog).getByLabelText(/Disetujui TOLL/);
    await userEvent.clear(field);
    await userEvent.type(field, "300000");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Lanjutkan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `cash/vouchers/${VCH}/approve`)?.body,
      ).toEqual({
        lines: [{ id: LINE, approved_minor: 30000000 }],
      }),
    );
  });

  it("settles with the actual amount, a receipt and the money returned", async () => {
    const calls = stub(["operations.view", "cash.view", "cash.settle"], {
      [`cash/vouchers/${VCH}`]: voucher("DISBURSED"),
    });
    open(`/cash/${VCH}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "Realisasi" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.clear(within(dialog).getByLabelText(/Realisasi TOLL/));
    await userEvent.type(
      within(dialog).getByLabelText(/Realisasi TOLL/),
      "280000",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Nota TOLL · Tol"),
      "Struk tol 1-4",
    );
    await userEvent.clear(within(dialog).getByLabelText(/dikembalikan/));
    await userEvent.type(
      within(dialog).getByLabelText(/dikembalikan/),
      "20000",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Lanjutkan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `cash/vouchers/${VCH}/settle`)?.body,
      ).toEqual({
        lines: [
          { id: LINE, actual_minor: 28000000, receipt_ref: "Struk tol 1-4" },
        ],
        returned_minor: 2000000,
      }),
    );
  });

  it("asks for driver cash from the trip page", async () => {
    const calls = stub(["operations.view", "cash.view", "cash.request"], {
      [`spm-trips/${TRIP}`]: trip("PLANNED"),
      [`spm-trips/${TRIP}/cash-vouchers`]: voucher("REQUESTED"),
    });
    open(`/planning/${TRIP}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "+ Minta uang jalan" }),
    );
    const dialog = await screen.findByRole("dialog");
    await pick(await within(dialog).findByLabelText("Kode biaya 1"), CODE);
    await userEvent.type(
      within(dialog).getByLabelText("Jumlah 1 (Rp)"),
      "400000",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Alasan 1"),
      "Tol pergi-pulang",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Ajukan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `spm-trips/${TRIP}/cash-vouchers`)?.body,
      ).toMatchObject({
        lines: [
          {
            charge_code_id: CODE,
            requested_minor: 40000000,
            reason: "Tol pergi-pulang",
          },
        ],
      }),
    );
  });

  it("shows cashbox balances and the cash drivers are expected to hold", async () => {
    stub(["operations.view", "cash.view", "cash.manage"]);
    open("/cash");
    await userEvent.click(
      await screen.findByRole("button", { name: /Kas & saldo/ }),
    );
    expect(await screen.findByText("Kas utama")).toBeTruthy();
    expect(screen.getByText("Budi")).toBeTruthy();
    expect(
      screen.getByRole("button", { name: /Batas persetujuan/ }),
    ).toBeTruthy();
  });
});
