import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
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
});
afterEach(() => vi.unstubAllGlobals());

const session = (permissions: string[], id = 1) => ({
  id,
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
const C1 = "11111111-1111-4111-8111-111111111111";
const L1 = "44444444-4444-4444-8444-444444444444";
const L2 = "55555555-5555-4555-8555-555555555555";
const U1 = "66666666-6666-4666-8666-666666666666";
const U2 = "77777777-7777-4777-8777-777777777777";
const V1 = "88888888-8888-4888-8888-888888888888";
const VH1 = "99999999-9999-4999-8999-999999999999";
const VH2 = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const T1 = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
const O1 = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";
const options: Record<string, unknown[]> = {
  customers: [{ id: C1, code: "C1", name: "PT Maju Jaya" }],
  customer_projects: [],
  services: [],
  locations: [
    { id: L1, code: "POOL", name: "Pool Jakarta" },
    { id: L2, code: "WH", name: "Gudang Bandung" },
  ],
  units: [
    { id: U1, code: "PALLET", name: "Pallet" },
    { id: U2, code: "DRUM", name: "Drum" },
  ],
  carrier_types: [{ id: T1, code: "CDE", name: "CDE" }],
};
const preview = (unpriced: number[]) => ({
  items: [
    {
      index: 0,
      description: "Palet kimia",
      unit_id: U1,
      quantity: 4,
      priced: !unpriced.includes(0),
      amount_minor: unpriced.includes(0) ? null : 460000000,
    },
  ],
  total_minor: unpriced.includes(0) ? 0 : 460000000,
  unpriced,
  warnings: [],
  carrier_type_id: T1,
});

function stub(unpriced: number[] = []) {
  const posted: Record<string, unknown>[] = [];
  const previews: Record<string, unknown>[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const json = (data: unknown, status = 200) =>
        new Response(JSON.stringify(data), { status });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({
          data: session(["operations.view", "operations.manage"]),
        });
      const opt = path.match(/^master\/([a-z_]+)\/options$/);
      if (opt) return json({ data: options[opt[1]] ?? [] });
      if (path === "vendors")
        return json({ data: [{ id: V1, code: "VND", name: "CV Vendor" }] });
      if (path === "fleet")
        return json({
          data: {
            vehicles: [
              {
                id: VH1,
                plate: "B 1 VND",
                vendor_id: V1,
                carrier_type_id: T1,
                carrier_type_label: "CDE",
                active: true,
                verification_status: "VERIFIED",
              },
              {
                id: VH2,
                plate: "B 2 OWN",
                vendor_id: null,
                carrier_type_id: T1,
                carrier_type_label: "CDE",
                active: true,
                verification_status: "VERIFIED",
              },
            ],
          },
        });
      if (path === "orders/price-preview") {
        previews.push(JSON.parse(String(init?.body)));
        return json({ data: preview(unpriced) });
      }
      if (path === "orders" && init?.method === "POST") {
        posted.push(JSON.parse(String(init.body)));
        return json({ data: { id: O1 } }, 201);
      }
      return json({ data: [], current_page: 1, last_page: 1, total: 0 });
    }),
  );
  return { posted, previews };
}

async function fillForm() {
  render(
    <MemoryRouter initialEntries={["/orders/new"]}>
      <App />
    </MemoryRouter>,
  );
  await screen.findByRole("heading", {
    level: 1,
    name: "Buat order pengiriman",
  });
  await waitFor(() =>
    expect(
      (screen.getByLabelText("Pelanggan (master data)") as HTMLElement).tagName,
    ).toBeTruthy(),
  );
  await pick(screen.getByLabelText("Pelanggan (master data)"), C1);
  await pick(screen.getByLabelText("Lokasi asal (master data)"), L1);
  await pick(screen.getByLabelText("Lokasi tujuan (master data)"), L2);
  await pick(screen.getByLabelText("Transporter"), V1);
  await waitFor(async () =>
    expect(await optionsOf(screen.getByLabelText("Kendaraan"))).toContain(
      "B 1 VND · CDE",
    ),
  );
  await pick(screen.getByLabelText("Kendaraan"), VH1);
  await userEvent.type(
    screen.getByLabelText("Deskripsi barang"),
    "Palet kimia",
  );
  await pick(screen.getByLabelText("Satuan muatan"), U1);
  fireEvent.change(screen.getByLabelText("Jumlah unit"), {
    target: { value: "4" },
  });
}

describe("Order form priced from the transporter mapping", () => {
  it("shows the base tariff read-only and sends the transporter, vehicle and units, not a price", async () => {
    const { posted, previews } = stub();
    await fillForm();
    expect(await screen.findByText("Tarif dasar (otomatis)")).toBeTruthy();
    await waitFor(() => expect(previews.length).toBeGreaterThan(0));
    expect(previews.at(-1)).toMatchObject({
      customer_id: C1,
      transporter_vendor_id: V1,
      vehicle_id: VH1,
      items: [{ quantity: 4, unit_id: U1 }],
    });
    await waitFor(() =>
      expect(screen.getAllByText(/4\.600\.000/).length).toBeGreaterThan(0),
    );
    expect(screen.queryByLabelText("Tarif dasar (Rp)")).toBeNull();
    expect(screen.queryByLabelText("Surcharge fuel / tol (Rp)")).toBeNull();

    fireEvent.submit(
      screen
        .getByRole("button", { name: /Simpan draft order/ })
        .closest("form")!,
    );
    await waitFor(() => expect(posted.length).toBe(1));
    expect(posted[0]).toMatchObject({
      price_mode: "MAPPING",
      transporter_vendor_id: V1,
      vehicle_id: VH1,
      customer_id: C1,
      items: [{ unit_id: U1, quantity: 4 }],
    });
    expect(posted[0]).not.toHaveProperty("base_price_minor");
    expect(posted[0]).not.toHaveProperty("surcharge_minor");
  });

  it("asks for a manual price and a reason for a line without a tariff", async () => {
    const { posted } = stub([0]);
    await fillForm();
    const manual = await screen.findByLabelText("Harga manual barang 1 (Rp)");
    await userEvent.type(manual, "500000");
    await userEvent.type(
      screen.getByLabelText("Alasan harga manual"),
      "Belum ada di matriks",
    );
    fireEvent.submit(
      screen
        .getByRole("button", { name: /Simpan draft order/ })
        .closest("form")!,
    );
    await waitFor(() => expect(posted.length).toBe(1));
    expect(posted[0]).toMatchObject({
      price_mode: "MAPPING",
      price_reason: "Belum ada di matriks",
      items: [{ manual_price_minor: 50000000 }],
    });
  });
});
