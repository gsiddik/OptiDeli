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
const SPM = "11111111-1111-4111-8111-111111111111";
const CUST = "22222222-2222-4222-8222-222222222222";
const TPL = "33333333-3333-4333-8333-333333333333";
const INV = "44444444-4444-4444-8444-444444444444";

const spmDetail = (status: string, vendor = false) => ({
  spm: {
    id: SPM,
    number: "SPM-202610-00001",
    order_id: "a",
    order_number: "OD-1",
    sequence: 1,
    status,
    customer_label: "PT Maju",
    origin_text: "Pool",
    destination_text: "Gudang",
    planned_pickup_at: null,
    reference: null,
    notes: null,
    status_reason: null,
    planned_delivery_at: null,
    carrier_type_id: null,
    vendor_id: vendor ? "v1" : null,
  },
  order: {
    id: "a",
    number: "OD-1",
    customer_name: "PT Maju",
    status: "DISPATCHED",
  },
  lines: [],
  awbs: [],
  charges: [
    {
      id: "c1",
      side: "SELL",
      charge_code_label: "FREIGHT · Ongkos angkut",
      kind: "PLAN",
      source: "RATE",
      basis: "TRIP",
      quantity: "1",
      unit_price_minor: 200000000,
      amount_minor: 200000000,
      rate_label: "T1",
      markup_code: null,
      reason: null,
      status: "PROPOSED",
    },
  ],
  history: [],
  totals: { sell_minor: 200000000 },
  siblings: [],
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
      const key = Object.keys(over)
        .filter((k) => path.startsWith(k))
        .sort((x, y) => y.length - x.length)[0];
      if (key) return json(over[key], method === "POST" ? 201 : 200);
      if (path === "master/customers/options")
        return json({ data: [{ id: CUST, code: "CUST", name: "PT Maju" }] });
      if (path === "master/invoice_templates/options")
        return json({
          data: [{ id: TPL, code: "PERIODE", name: "Per periode" }],
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

describe("Verification on the SPM page", () => {
  it("offers to verify the waiting selling charges to those who may", async () => {
    const calls = stub(["operations.view", "selling.verify"], {
      [`spm/${SPM}`]: { data: spmDetail("DISCHARGED") },
      [`spm/${SPM}/charges/verify`]: {
        data: { decided: 1, total_minor: 200000000, status: "VERIFIED" },
      },
    });
    open(`/spm/${SPM}`);
    await userEvent.click(
      await screen.findByRole("button", { name: /Verifikasi 1 biaya jual/ }),
    );
    await waitFor(() =>
      expect(
        calls.find(
          (c) => c.method === "POST" && c.path === `spm/${SPM}/charges/verify`,
        )?.body,
      ).toEqual({ side: "SELL", decision: "VERIFY" }),
    );
    expect(await screen.findByText(/1 biaya diverifikasi/)).toBeTruthy();
  });

  it("rejecting needs a reason", async () => {
    const calls = stub(["operations.view", "selling.verify"], {
      [`spm/${SPM}`]: { data: spmDetail("DISCHARGED") },
      [`spm/${SPM}/charges/verify`]: {
        data: { decided: 1, total_minor: 1, status: "REJECTED" },
      },
    });
    open(`/spm/${SPM}`);
    await userEvent.click(
      await screen.findByRole("button", { name: /Tolak biaya jual/ }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText(/Alasan/),
      "Tarif keliru",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find(
          (c) => c.method === "POST" && c.path === `spm/${SPM}/charges/verify`,
        )?.body,
      ).toEqual({ note: "Tarif keliru", side: "SELL", decision: "REJECT" }),
    );
  });

  it("shows nothing to a user without the permission or before discharge", async () => {
    stub(["operations.view"], {
      [`spm/${SPM}`]: { data: spmDetail("DISCHARGED") },
    });
    open(`/spm/${SPM}`);
    await screen.findByRole("heading", { level: 1, name: "SPM-202610-00001" });
    expect(
      screen.queryByRole("button", { name: /Verifikasi 1 biaya/ }),
    ).toBeNull();
  });

  it("records the vendor bill when the three agree", async () => {
    const calls = stub(["operations.view", "cost.verify"], {
      [`spm/${SPM}/vendor-bill`]: {
        data: {
          agreed_minor: 110000000,
          unverified_count: 0,
          awb_total: 1,
          awb_delivered: 1,
          already_billed: 0,
          ready: true,
        },
      },
      [`spm/${SPM}`]: { data: spmDetail("DISCHARGED", true) },
    });
    open(`/spm/${SPM}`);
    const button = await screen.findByRole("button", {
      name: "Catat tagihan vendor",
    });
    await waitFor(() =>
      expect((button as HTMLButtonElement).disabled).toBe(false),
    );
    await userEvent.click(button);
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Nomor invoice vendor"),
      "INV-77",
    );
    await userEvent.type(
      within(dialog).getByLabelText(/Jumlah invoice vendor/),
      "1100000",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find(
          (c) => c.method === "POST" && c.path === `spm/${SPM}/vendor-bill`,
        )?.body,
      ).toEqual({ vendor_invoice_number: "INV-77", amount_minor: 110000000 }),
    );
  });
});

describe("Billing", () => {
  const ready = {
    data: [
      {
        spm_id: SPM,
        spm_number: "SPM-202610-00001",
        customer_id: CUST,
        customer_label: "PT Maju",
        project_id: null,
        project_label: null,
        route: "Pool → Gudang",
        total_minor: 220000000,
        charges: [
          {
            id: "c1",
            charge_code: "FREIGHT",
            name: "Ongkos angkut",
            kind: "PLAN",
            amount_minor: 200000000,
          },
          {
            id: "c2",
            charge_code: "UNLOADING",
            name: "Uang bongkar",
            kind: "UNPLAN",
            amount_minor: 20000000,
          },
        ],
      },
    ],
  };

  it("lists what is ready to bill and issues an invoice for the chosen SPMs", async () => {
    const calls = stub(["operations.view", "billing.issue"], {
      "billing/ready": ready,
      "billing/batches": {
        data: {
          batch: {
            id: "b1",
            number: "BB-202610-00001",
            total_minor: 220000000,
          },
          invoices: [
            { id: INV, number: "AR-000001" },
            { id: "x", number: "AR-000002" },
          ],
        },
      },
    });
    open("/billing");
    expect(
      await screen.findByRole("link", { name: "SPM-202610-00001" }),
    ).toBeTruthy();
    expect(screen.getByText(/UNLOADING .*\(Unplan\)/)).toBeTruthy();
    const issue = screen.getByRole("button", {
      name: /Terbitkan invoice \(0 SPM\)/,
    });
    expect((issue as HTMLButtonElement).disabled).toBe(true);

    await userEvent.click(screen.getByLabelText("Pilih SPM-202610-00001"));
    await userEvent.click(
      screen.getByRole("button", { name: /Terbitkan invoice \(1 SPM\)/ }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.selectOptions(
      await within(dialog).findByLabelText("Template invoice"),
      TPL,
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /^Terbitkan$/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.method === "POST" && c.path === "billing/batches")
          ?.body,
      ).toEqual({
        customer_id: CUST,
        spm_ids: [SPM],
        contract_charge_ids: [],
        template_id: TPL,
        due_date: null,
      }),
    );
    expect(await screen.findByText(/BB-202610-00001: 2 invoice/)).toBeTruthy();
  });

  it("bills a fixed-cost contract month by its charge ids", async () => {
    const calls = stub(["operations.view", "billing.issue"], {
      "billing/ready": {
        data: [
          {
            type: "CONTRACT",
            spm_id: null,
            spm_number: "Kontrak FX-1 · 2026-09",
            customer_id: CUST,
            customer_label: "PT Maju",
            project_id: null,
            project_label: null,
            route: "Kontrak FX-1",
            delivered_at: null,
            total_minor: 1000000,
            charges: [
              {
                id: "cc1",
                charge_code: "FIXCOST",
                name: "Biaya tetap",
                kind: "PLAN",
                amount_minor: 1000000,
              },
            ],
          },
        ],
      },
      "billing/batches": {
        data: {
          batch: { id: "b1", number: "BB-1", total_minor: 1000000 },
          invoices: [{ id: INV, number: "AR-000001" }],
        },
      },
      "billing/templates": { data: [{ id: TPL, name: "Standar" }] },
    });
    open("/billing");
    await userEvent.click(
      await screen.findByLabelText("Pilih Kontrak FX-1 · 2026-09"),
    );
    await userEvent.click(
      screen.getByRole("button", { name: /Terbitkan invoice \(1 SPM\)/ }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Terbitkan/ }),
    );
    await waitFor(() => {
      const body = calls.find(
        (c) => c.method === "POST" && c.path === "billing/batches",
      )?.body as Record<string, unknown>;
      expect(body.spm_ids).toEqual([]);
      expect(body.contract_charge_ids).toEqual(["cc1"]);
    });
  });

  it("is closed to those who may not bill", async () => {
    stub(["operations.view"]);
    open("/billing");
    await screen.findByRole("heading", { level: 1, name: "Penagihan" });
    expect(
      within(
        screen.getByRole("navigation", { name: "Navigasi utama" }),
      ).queryByRole("link", { name: "Penagihan" }),
    ).toBeNull();
  });

  it("lays the invoice out in the columns of its template", async () => {
    stub(["operations.view", "billing.issue"], {
      [`billing/invoices/${INV}`]: {
        data: {
          document: {
            id: INV,
            number: "AR-000001",
            status: "OPEN",
            amount_minor: 244200000,
            subtotal_minor: 220000000,
            tax_minor: 24200000,
            issue_date: "2026-10-27",
            due_date: "2026-11-10",
            counterparty_name: "PT Maju",
            void_reason: null,
            paid_minor: 0,
          },
          lines: [
            {
              id: "l1",
              spm_number: "SPM-202610-00001",
              order_number: "OD-1",
              route: "Pool → Gudang",
              date: "2026-10-26",
              description: "Ongkos angkut",
              quantity: "1",
              unit_price_minor: 200000000,
              amount_minor: 200000000,
              kind: "PLAN",
            },
          ],
          customer: {
            name: "PT Maju",
            tax_id: "012345678901000",
            address: null,
          },
          batch_number: "BB-1",
          columns: ["spm", "description", "amount"],
          footer: "Terima kasih",
          template: "Per SPM",
        },
      },
    });
    open(`/billing/invoices/${INV}`);
    expect(
      await screen.findByRole("heading", { name: "INVOICE AR-000001" }),
    ).toBeTruthy();
    expect(screen.getByRole("columnheader", { name: "SPM" })).toBeTruthy();
    expect(screen.queryByRole("columnheader", { name: "Rute" })).toBeNull();
    expect(screen.getByText("PPN")).toBeTruthy();
    expect(screen.getByText("Terima kasih")).toBeTruthy();
  });
});

describe("Reports", () => {
  it("opens the contribution P&L by a dimension", async () => {
    const calls = stub(
      ["operations.view", "report.pnl.view", "finance.access"],
      {
        reports: {
          data: [
            {
              key: "pnl",
              name: "P&L kontribusi",
              description: "Margin operasional",
              permission: "report.pnl.view",
            },
            {
              key: "spm_status",
              name: "Status SPM",
              description: "Per status",
              permission: "operations.view",
            },
          ],
        },
        "reports/pnl": {
          data: {
            rows: [
              {
                key: "k",
                label: "PT Maju",
                spm_count: 2,
                sell_minor: 440000000,
                buy_minor: 110000000,
                margin_minor: 330000000,
                margin_bps: 7500,
                sell_plan_minor: 0,
                sell_unplan_minor: 0,
                unverified_sell_minor: 0,
                unverified_buy_minor: 110000000,
              },
            ],
            totals: {
              sell_minor: 440000000,
              buy_minor: 110000000,
              margin_minor: 330000000,
              margin_bps: 7500,
              unverified_sell_minor: 0,
              unverified_buy_minor: 110000000,
            },
          },
        },
      },
    );
    open("/reports");
    await userEvent.click(
      await screen.findByRole("button", { name: /P&L kontribusi/ }),
    );
    expect(await screen.findByText("PT Maju")).toBeTruthy();
    expect(screen.getAllByText("75%").length).toBe(2);
    await userEvent.selectOptions(screen.getByLabelText("Per"), "lane");
    await waitFor(() =>
      expect(
        calls.some((c) => c.path.startsWith("reports/pnl?group_by=lane")),
      ).toBe(true),
    );
    expect(
      screen.getByRole("link", { name: "Ekspor Excel" }).getAttribute("href"),
    ).toContain("format=xlsx");
  });
});
