import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { optionsOf, pick } from "./pick";

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
const T1 = "11111111-1111-4111-8111-111111111111";
const FREIGHT = "22222222-2222-4222-8222-222222222222";
const L1 = "33333333-3333-4333-8333-333333333333";
const table = (over = {}) => ({
  id: T1,
  code: "JKT-BDG",
  version: 1,
  name: "Tarif Jakarta-Bandung",
  side: "SELL",
  status: "DRAFT",
  customer_label: null,
  project_label: null,
  vendor_label: null,
  valid_from: "2026-01-01",
  valid_until: null,
  notes: null,
  status_reason: null,
  created_by: 7,
  line_count: 1,
  ...over,
});
const line = {
  id: "44444444-4444-4444-8444-444444444444",
  charge_code_id: FREIGHT,
  charge_code_label: "FREIGHT · Ongkos angkut",
  origin_city_label: "31.71 · Jakarta",
  destination_city_label: null,
  carrier_type_label: null,
  service_label: null,
  basis: "TRIP",
  unit_price_minor: 150000000,
  min_charge_minor: 0,
};
const opt = (id: string, code: string, name: string) => ({ id, code, name });

function stub(permissions: string[], tableOver = {}) {
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
      const json = (data: unknown, status = 200) =>
        new Response(JSON.stringify(data), { status });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({ data: session(permissions) });
      if (path === "master/charge_codes/options")
        return json({ data: [opt(FREIGHT, "FREIGHT", "Ongkos angkut")] });
      if (path === "master/locations/options")
        return json({
          data: [opt(L1, "POOL", "Pool"), opt(T1, "WH", "Gudang")],
        });
      if (path.startsWith("master/")) return json({ data: [] });
      if (path === "vendors") return json({ data: [] });
      if (path === "tariffs/resolve")
        return json({
          data: {
            lines: [
              {
                charge_code: { code: "FREIGHT", name: "Ongkos angkut" },
                buy: { source: "TABLE", amount_minor: 100000000 },
                sell: {
                  source: "MARKUP",
                  amount_minor: 110000000,
                  markup: { code: "M10" },
                },
                margin_minor: 10000000,
              },
            ],
            totals: {
              buy_minor: 100000000,
              sell_minor: 110000000,
              margin_minor: 10000000,
            },
            warnings: ["Peringatan uji"],
          },
        });
      if (path.startsWith(`tariffs/${T1}/lines`) && method === "POST")
        return json({ data: line }, 201);
      if (path === `tariffs/${T1}/imports`)
        return json(
          {
            data: {
              id: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
              resource: "rate_lines",
              filename: "baris.csv",
              status: "PREVIEW",
              total_rows: 2,
              rows_new: 2,
              rows_update: 0,
              rows_unchanged: 0,
              rows_error: 0,
              ignored_columns: [],
              already_imported_at: null,
              result: null,
            },
          },
          201,
        );
      if (path.startsWith("master/imports/"))
        return json({ data: [], current_page: 1, last_page: 1, total: 0 });
      if (path === `tariffs/${T1}/approve`)
        return json({ data: table({ status: "APPROVED" }) });
      if (path === `tariffs/${T1}`)
        return json({
          data: {
            table: table(tableOver),
            lines: [line],
            versions: [
              {
                id: T1,
                version: 1,
                status: "DRAFT",
                valid_from: "2026-01-01",
                valid_until: null,
              },
            ],
          },
        });
      if (path.startsWith("tariffs"))
        return json({
          data: [table(tableOver)],
          current_page: 1,
          last_page: 1,
          total: 1,
        });
      return json({ data: [] });
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

describe("Tariffs", () => {
  it("lists tariffs; buying tariffs and creation depend on the permissions", async () => {
    stub(["operations.view", "rate.view"]);
    open("/tariffs");
    expect(
      await screen.findByRole("link", { name: "JKT-BDG v1" }),
    ).toBeTruthy();
    expect(screen.queryByRole("button", { name: "+ Tarif jual" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Markup" })).toBeNull();
    expect(screen.queryByRole("combobox", { name: "Sisi" })).toBeNull();
  });

  it("the author of a draft cannot approve it", async () => {
    const calls = stub([
      "operations.view",
      "rate.view",
      "rate.manage",
      "rate.approve",
    ]);
    open(`/tariffs/${T1}`);
    const approve = await screen.findByRole("button", { name: "Setujui" });
    expect((approve as HTMLButtonElement).disabled).toBe(true);
    expect(await screen.findByText(/Anda penyusun draf ini/)).toBeTruthy();
    expect(calls.some((c) => c.path.endsWith("/approve"))).toBe(false);
  });

  it("someone else approves a draft", async () => {
    const calls = stub(["operations.view", "rate.view", "rate.approve"], {
      created_by: 99,
    });
    open(`/tariffs/${T1}`);
    const approve = await screen.findByRole("button", { name: "Setujui" });
    expect((approve as HTMLButtonElement).disabled).toBe(false);
    await userEvent.click(approve);
    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.method === "POST" && c.path === `tariffs/${T1}/approve`,
        ),
      ).toBe(true),
    );
  });

  it("adds a line and sends the price in minor units", async () => {
    const calls = stub(["operations.view", "rate.view", "rate.manage"]);
    open(`/tariffs/${T1}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "+ Baris tarif" }),
    );
    const dialog = await screen.findByRole("dialog");
    await pick(within(dialog).getByLabelText("Kode biaya"), FREIGHT);
    await userEvent.type(
      within(dialog).getByLabelText("Tarif per satuan (Rp)"),
      "1500000",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.method === "POST" && c.path === `tariffs/${T1}/lines`,
        ),
      ).toBe(true),
    );
    const body = calls.find((c) => c.path === `tariffs/${T1}/lines`)!
      .body as Record<string, unknown>;
    expect(body).toMatchObject({
      charge_code_id: FREIGHT,
      basis: "TRIP",
      unit_price_minor: 150000000,
    });
  });

  it("a buying tariff shows the base tariff column and sends the margin as mode and value", async () => {
    const calls = stub(
      ["operations.view", "rate.view", "rate.manage", "rate.buy.view"],
      {
        side: "BUY",
      },
    );
    open(`/tariffs/${T1}`);
    expect(
      await screen.findByRole("columnheader", { name: "Tarif awal" }),
    ).toBeTruthy();
    expect(screen.getByRole("columnheader", { name: "Margin" })).toBeTruthy();
    expect(
      screen.getByRole("columnheader", { name: "Tarif dasar (otomatis)" }),
    ).toBeTruthy();
    await userEvent.click(
      await screen.findByRole("button", { name: "+ Baris tarif" }),
    );
    const dialog = await screen.findByRole("dialog");
    await pick(within(dialog).getByLabelText("Kode biaya"), FREIGHT);
    await userEvent.type(
      within(dialog).getByLabelText("Tarif per satuan (Rp)"),
      "1000000",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Margin (% dari tarif awal)"),
      "15.5",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.method === "POST" && c.path === `tariffs/${T1}/lines`,
        ),
      ).toBe(true),
    );
    const body = calls.find((c) => c.path === `tariffs/${T1}/lines`)!
      .body as Record<string, unknown>;
    expect(body).toMatchObject({
      unit_price_minor: 100000000,
      margin_mode: "PERCENT",
      margin_value: 1550,
    });
    expect(body).not.toHaveProperty("margin_percent");
  });

  it("tests a tariff and shows buy, sell, margin and warnings only to those who may see buying prices", async () => {
    stub(["operations.view", "rate.view", "rate.buy.view"]);
    open("/tariffs/test");
    await screen.findByRole("heading", { level: 1, name: /Tarif jual/ });
    await waitFor(async () =>
      expect(await optionsOf(screen.getByLabelText("Lokasi asal"))).toContain(
        "POOL · Pool",
      ),
    );
    await pick(screen.getByLabelText("Lokasi asal"), L1);
    await pick(screen.getByLabelText("Lokasi tujuan"), T1);
    await userEvent.click(screen.getByRole("button", { name: "Hitung tarif" }));
    const result = await screen.findByLabelText("Hasil tarif");
    expect(within(result).getByText("Peringatan uji")).toBeTruthy();
    expect(within(result).getByText("Markup M10")).toBeTruthy();
    expect(within(result).getByText("Margin")).toBeTruthy();
  });

  it("imports lines into a draft through the shared import dialog", async () => {
    const calls = stub(["operations.view", "rate.view", "rate.manage"]);
    open(`/tariffs/${T1}`);
    await userEvent.click(
      await screen.findByRole("button", { name: "Impor Excel / CSV" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.upload(
      within(dialog).getByLabelText("Berkas impor"),
      new File(["x"], "baris.csv"),
    );
    expect(await within(dialog).findByText("Baru: 2")).toBeTruthy();
    expect(
      calls.some(
        (c) => c.method === "POST" && c.path === `tariffs/${T1}/imports`,
      ),
    ).toBe(true);
  });
});
