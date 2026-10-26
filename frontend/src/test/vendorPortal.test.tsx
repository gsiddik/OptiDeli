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

const CDD = "33333333-3333-4333-8333-333333333333";
const VEHICLE = "11111111-1111-4111-8111-111111111111";
const TABLE = "44444444-4444-4444-8444-444444444444";

const session = (permissions: string[]) => ({
  id: 5,
  name: "Admin Vendor",
  role: "vendor",
  permissions,
  tenant: { name: "Tenant A", slug: "a", minimum_margin_bps: 1500 },
  deployment_mode: "saas",
});

type Call = { method: string; path: string; body?: unknown };

function stub(permissions: string[], over: Record<string, unknown> = {}) {
  const calls: Call[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const method = init?.method ?? "GET";
      calls.push({
        method,
        path,
        body:
          init?.body instanceof FormData
            ? Object.fromEntries(init.body.entries())
            : typeof init?.body === "string"
              ? JSON.parse(init.body)
              : undefined,
      });
      const json = (data: unknown, st = 200) =>
        new Response(JSON.stringify(data), { status: st });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({ data: session(permissions) });
      const key = Object.keys(over)
        .filter((k) => path.startsWith(k))
        .sort((a, b) => b.length - a.length)[0];
      if (key) return json(over[key], method === "GET" ? 200 : 201);
      return json({ data: [] });
    }),
  );
  return calls;
}

const portal = {
  data: {
    vendor: { id: "v", name: "CV Sewa", code: "SEWA", active: true },
    trips: [],
    spm_trips: [
      {
        id: "t1",
        number: "PJ-202610-00001",
        spm_number: "SPM-202610-00001",
        route: "Pool → Gudang",
        plate: "B 2 VND",
        driver_name: "Budi",
        status: "PLANNED",
        starts_at: "2026-10-30T08:00:00Z",
        cost_minor: 130000000,
      },
    ],
    invoices: [],
    disputes: [],
    pods: [],
  },
};
const fleet = {
  data: {
    vehicles: [
      {
        id: VEHICLE,
        plate: "B 1234 XY",
        carrier_type_id: CDD,
        carrier_type_label: "CDD Box",
        capacity_g: 2500000,
        length_mm: 4200,
        width_mm: 2000,
        height_mm: 2000,
        active: true,
        verification_status: "PENDING",
        verification_note: null,
        documents: [
          { id: "d1", kind: "STNK", number: null, valid_until: "2027-08-01" },
        ],
        missing_documents: ["KIR"],
      },
    ],
    drivers: [],
  },
};
const options = {
  data: {
    carrier_types: [{ id: CDD, label: "CDD · CDD Box" }],
    charge_codes: [{ id: "c1", label: "FREIGHT · Freight" }],
    locations: [],
    bases: { TRIP: "Per trip" },
  },
};

const open = () =>
  render(
    <MemoryRouter initialEntries={["/"]}>
      <App />
    </MemoryRouter>,
  );

describe("Vendor portal", () => {
  it("shows the SPM trips assigned to the vendor", async () => {
    stub(["vendor.portal"], { "vendor/portal": portal });
    open();
    expect(await screen.findByText("PJ-202610-00001")).toBeTruthy();
    expect(screen.getByText("B 2 VND")).toBeTruthy();
    expect(screen.getByText(/1\.300\.000/)).toBeTruthy();
  });

  it("registers a vehicle with its type", async () => {
    const calls = stub(["vendor.portal"], {
      "vendor/portal": portal,
      "vendor/fleet": fleet,
      "vendor/rate-options": options,
    });
    open();
    await userEvent.click(
      await screen.findByRole("tab", { name: "Kendaraan & pengemudi" }),
    );
    expect(await screen.findByText("B 1234 XY")).toBeTruthy();
    expect(screen.getByText("KIR belum diunggah")).toBeTruthy();
    expect(screen.getByText("Menunggu verifikasi")).toBeTruthy();

    await userEvent.click(screen.getByRole("button", { name: "+ Kendaraan" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Nomor kendaraan"),
      "B 9 ZZ",
    );
    await userEvent.selectOptions(
      within(dialog).getByLabelText(/Tipe kendaraan/),
      CDD,
    );
    await userEvent.type(within(dialog).getByLabelText(/Kapasitas/), "2500");
    await userEvent.type(within(dialog).getByLabelText(/Panjang/), "4200");
    await userEvent.type(within(dialog).getByLabelText(/Lebar/), "2000");
    await userEvent.type(within(dialog).getByLabelText(/Tinggi/), "2000");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.method === "POST" && c.path === "vendor/vehicles")
          ?.body,
      ).toMatchObject({ plate: "B 9 ZZ", carrier_type_id: CDD }),
    );
  });

  it("proposes a rate line per vehicle type", async () => {
    const calls = stub(["vendor.portal"], {
      "vendor/portal": portal,
      "vendor/rate-options": options,
      [`vendor/rates/${TABLE}`]: { data: { lines: [] } },
      "vendor/rates": {
        data: [
          {
            id: TABLE,
            code: "VR-SEWA-0001",
            name: "Tarif CV Sewa",
            version: 1,
            status: "DRAFT",
            state: "DRAFT",
            origin: "VENDOR",
            valid_from: "2026-10-01",
            valid_until: null,
            line_count: 0,
            review_note: null,
          },
        ],
      },
    });
    open();
    await userEvent.click(await screen.findByRole("tab", { name: "Tarif" }));
    await userEvent.click(
      await screen.findByRole("button", { name: "VR-SEWA-0001 v1" }),
    );
    expect(await screen.findByText("Belum ada baris tarif.")).toBeTruthy();
    expect(
      (
        screen.getByRole("button", {
          name: "Ajukan ke tenant",
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
    await userEvent.click(
      screen.getByRole("button", { name: "+ Baris tarif" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.selectOptions(
      within(dialog).getByLabelText("Tipe kendaraan"),
      CDD,
    );
    await userEvent.selectOptions(
      within(dialog).getByLabelText("Kode biaya"),
      "c1",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Tarif (Rp)"),
      "1500000",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find(
          (c) =>
            c.method === "POST" && c.path === `vendor/rates/${TABLE}/lines`,
        )?.body,
      ).toMatchObject({
        carrier_type_id: CDD,
        charge_code_id: "c1",
        unit_price_minor: 150000000,
      }),
    );
  });
});

describe("Fleet verification", () => {
  const queue = {
    data: {
      vehicles: [
        {
          id: VEHICLE,
          plate: "B 1234 XY",
          vendor_label: "CV Sewa",
          carrier_type_label: "CDD Box",
          capacity_g: 2500000,
          verification_status: "PENDING",
          verification_note: null,
          documents: [
            { id: "d1", kind: "STNK", number: null, valid_until: "2027-08-01" },
          ],
          missing_documents: ["KIR"],
        },
      ],
      drivers: [],
    },
  };

  it("keeps verification closed until the documents are complete and rejects with a reason", async () => {
    const calls = stub(["operations.view", "vendor.fleet.verify"], {
      "fleet-verification": queue,
    });
    render(
      <MemoryRouter initialEntries={["/vendor-fleet"]}>
        <App />
      </MemoryRouter>,
    );
    expect(await screen.findByText("B 1234 XY")).toBeTruthy();
    const verify = screen.getByRole("button", { name: "Verifikasi" });
    expect((verify as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText("KIR belum diunggah")).toBeTruthy();

    await userEvent.click(screen.getByRole("button", { name: "Tolak" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText(/Alasan/),
      "KIR belum ada",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find(
          (c) =>
            c.method === "POST" &&
            c.path === `fleet-verification/vehicles/${VEHICLE}`,
        )?.body,
      ).toMatchObject({ decision: "REJECT", note: "KIR belum ada" }),
    );
  });
});
