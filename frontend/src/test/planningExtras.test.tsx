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
const REQ = "11111111-1111-4111-8111-111111111111";
const SPM = "22222222-2222-4222-8222-222222222222";
const LOC1 = "33333333-3333-4333-8333-333333333333";
const LOC2 = "44444444-4444-4444-8444-444444444444";
const TRIP = "55555555-5555-4555-8555-555555555555";
const STOP = "66666666-6666-4666-8666-666666666666";
const hour = (h: number) => new Date(Date.now() + h * 3600000).toISOString();

const request = (requestedBy = 99) => ({
  id: REQ,
  transport_order_id: SPM,
  spm_number: "SPM-202610-00001",
  spm_status: "DISCHARGED",
  from_status: "DISCHARGED",
  reason: "Berat salah dicatat",
  status: "PENDING",
  requested_by: requestedBy,
  requested_by_name: "Dina",
  decided_by_name: null,
  decision_note: null,
  created_at: "2026-10-26T01:00:00Z",
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
      const key = Object.keys(over).find((k) => path.startsWith(k));
      if (key) return json(over[key], method === "POST" ? 201 : 200);
      if (path === "workqueue") return json({ data: { reopen_requests: 1 } });
      if (path === "fleet")
        return json({
          data: {
            vehicles: [{ id: "v1", plate: "B 1 AA", type: "Box" }],
            drivers: [{ id: "d1", name: "Budi" }],
            allocations: [],
          },
        });
      if (path.startsWith("master/locations/options"))
        return json({
          data: [
            { id: LOC1, code: "POOL", name: "Pool" },
            { id: LOC2, code: "WH", name: "Gudang" },
          ],
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

describe("Schedule and monitoring", () => {
  it("draws every vehicle with its trips and bookings and books a vehicle", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      schedule: {
        data: {
          from: hour(-24),
          to: hour(24 * 6),
          vehicles: [
            { id: "v1", plate: "B 1 AA", type: "Box", status: "AVAILABLE" },
          ],
          items: [
            {
              kind: "TRIP",
              id: TRIP,
              vehicle_id: "v1",
              label: "PJ-202610-00001",
              detail: "SPM-1 · Budi",
              status: "PLANNED",
              starts_at: hour(2),
              ends_at: hour(8),
            },
            {
              kind: "BOOKING",
              id: REQ,
              vehicle_id: "v1",
              label: "Booking",
              detail: "Cadangan",
              status: "BOOKED",
              starts_at: hour(30),
              ends_at: hour(40),
            },
          ],
        },
      },
    });
    open("/schedule");
    expect(await screen.findByText("B 1 AA")).toBeTruthy();
    expect(
      screen.getByRole("button", { name: "PJ-202610-00001" }),
    ).toBeTruthy();
    expect(screen.getByRole("button", { name: "Booking" })).toBeTruthy();

    await userEvent.click(
      screen.getByRole("button", { name: "+ Booking kendaraan" }),
    );
    const dialog = await screen.findByRole("dialog");
    await pick(await within(dialog).findByLabelText("Kendaraan"), "v1");
    await userEvent.type(
      within(dialog).getByLabelText("Mulai"),
      "2026-11-02T08:00",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Selesai"),
      "2026-11-02T16:00",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.method === "POST" && c.path === "reservations")
          ?.body,
      ).toMatchObject({
        vehicle_id: "v1",
      }),
    );
  });

  it("flags the trips that need attention on the board", async () => {
    stub(["operations.view"], {
      monitoring: {
        data: {
          counts: { PENDING_APPROVAL: 1, PLANNED: 1, IN_TRANSIT: 2, LATE: 1 },
          unplanned_awbs: 3,
          trips: [
            {
              id: TRIP,
              number: "PJ-202610-00007",
              spm_id: SPM,
              spm_number: "SPM-202610-00009",
              status: "IN_TRANSIT",
              vehicle: "B 7 XX",
              driver: "Andi",
              starts_at: hour(-6),
              ends_at: hour(-1),
              stops_done: 1,
              stops_total: 3,
              current_stop: {
                type: "DROP",
                location: "Gudang Bandung",
                arrived_at: hour(-5),
                planned: null,
                source: "GEOFENCE",
              },
              awb_delivered: 0,
              awb_total: 2,
              last_signal_at: hour(-5),
              flags: ["LATE", "WAITING"],
            },
          ],
        },
      },
    });
    open("/monitoring");
    expect(await screen.findByText("PJ-202610-00007")).toBeTruthy();
    expect(screen.getByText("Terlambat")).toBeTruthy();
    expect(screen.getByText("Menunggu lama di stop")).toBeTruthy();
    expect(
      screen.getByText(/Bongkar · Gudang Bandung \(di lokasi\)/),
    ).toBeTruthy();
    expect(screen.getByText("3")).toBeTruthy();
  });
});

describe("Route templates", () => {
  it("lists templates and creates one with its stops", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      "route-templates": {
        data: [
          {
            id: REQ,
            code: "JKT-BDG",
            name: "Jakarta-Bandung",
            notes: null,
            active: true,
            stop_count: 2,
            carrier_type_id: null,
            service_id: null,
          },
        ],
      },
    });
    open("/route-templates");
    expect(await screen.findByText("Jakarta-Bandung")).toBeTruthy();
    await userEvent.click(
      screen.getByRole("button", { name: "+ Template rute" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Kode"), "rute-baru");
    await userEvent.type(within(dialog).getByLabelText("Nama"), "Rute baru");
    await pick(await within(dialog).findByLabelText("Lokasi 1"), LOC1);
    await pick(within(dialog).getByLabelText("Lokasi 2"), LOC2);
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.method === "POST" && c.path === "route-templates")
          ?.body,
      ).toMatchObject({
        name: "Rute baru",
        stops: [
          { location_id: LOC1, type: "PICKUP" },
          { location_id: LOC2, type: "DROP" },
        ],
      }),
    );
  });
});

describe("Re-open queue", () => {
  it("the requester cannot decide; someone else approves", async () => {
    stub(["operations.view", "spm.manage", "spm.reopen"], {
      "spm-reopen-requests": {
        data: [request(7)],
        current_page: 1,
        last_page: 1,
        total: 1,
      },
    });
    open("/reopen");
    const approve = await screen.findByRole("button", { name: "Setujui" });
    expect((approve as HTMLButtonElement).disabled).toBe(true);
  });

  it("approves a request from another person", async () => {
    const calls = stub(["operations.view", "spm.manage", "spm.reopen"], {
      "spm-reopen-requests": {
        data: [request(99)],
        current_page: 1,
        last_page: 1,
        total: 1,
      },
    });
    open("/reopen");
    await userEvent.click(
      await screen.findByRole("button", { name: "Setujui" }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Berat salah dicatat/)).toBeTruthy();
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Lanjutkan/ }),
    );
    await waitFor(() =>
      expect(
        calls.some(
          (c) =>
            c.method === "POST" &&
            c.path === `spm-reopen-requests/${REQ}/approve`,
        ),
      ).toBe(true),
    );
  });

  it("a discharged SPM offers a request, not a direct re-open", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      [`spm/${SPM}`]: {
        data: {
          spm: {
            id: SPM,
            number: "SPM-202610-00001",
            order_id: "a",
            order_number: "OD-1",
            sequence: 1,
            status: "DISCHARGED",
            customer_label: null,
            origin_text: "A",
            destination_text: "B",
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
            customer_name: "PT",
            status: "DISPATCHED",
          },
          lines: [],
          awbs: [],
          charges: [],
          history: [],
          totals: { sell_minor: 0 },
          siblings: [],
          reopen_request: null,
        },
      },
    });
    open(`/spm/${SPM}`);
    expect(screen.queryByRole("button", { name: "Buka kembali" })).toBeNull();
    await userEvent.click(
      await screen.findByRole("button", { name: "Ajukan buka kembali" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/Alasan/), "Nota salah");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `spm/${SPM}/reopen-requests`)?.body,
      ).toEqual({ reason: "Nota salah" }),
    );
  });
});

describe("Geofence on the trip page", () => {
  const trip = {
    data: {
      trip: {
        id: TRIP,
        number: "PJ-202610-00001",
        transport_order_id: SPM,
        spm_number: "SPM-1",
        status: "IN_TRANSIT",
        vehicle_label: "B 1 AA · Box",
        driver_label: "Budi",
        starts_at: hour(-2),
        ends_at: hour(3),
        awb_count: 1,
        vendor_label: null,
        sell_minor: null,
        cost_minor: null,
        required_level: 0,
        reason_code: null,
        cancellation_reason: null,
        notes: null,
        created_by: 99,
      },
      spm: { id: SPM, number: "SPM-1", status: "LOADING" },
      awbs: [],
      stops: [
        {
          id: STOP,
          sequence: 1,
          type: "PICKUP",
          location_label: "Pool",
          planned_from: null,
          planned_to: null,
          arrived_at: hour(-1),
          departed_at: null,
          time_source: "GEOFENCE",
          awb_numbers: [],
        },
      ],
    },
  };

  it("shows where each time came from and the geofence decisions", async () => {
    stub(["operations.view", "spm.manage"], {
      [`spm-trips/${TRIP}/signals`]: {
        data: [
          {
            id: "e1",
            event: "ENTER",
            source: "GEOFENCE",
            occurred_at: hour(-1),
            distance_m: 40,
            applied: true,
            ignored_reason: null,
          },
          {
            id: "e2",
            event: "ENTER",
            source: "RADAR",
            occurred_at: hour(-1),
            distance_m: null,
            applied: false,
            ignored_reason: "NOT_NEXT_STOP",
          },
        ],
      },
      [`spm-trips/${TRIP}`]: trip,
    });
    open(`/planning/${TRIP}`);
    expect(
      await screen.findByText("Kejadian geofence & jam stop"),
    ).toBeTruthy();
    expect(screen.getAllByText("Geofence").length).toBeGreaterThan(0);
    expect(screen.getByText(/Diabaikan: NOT_NEXT_STOP/)).toBeTruthy();
  });

  it("sends a position to see where the geofence would put the vehicle", async () => {
    const calls = stub(["operations.view", "spm.manage"], {
      [`spm-trips/${TRIP}/signals`]: { data: [] },
      [`spm-trips/${TRIP}/position`]: {
        data: {
          applied: false,
          action: null,
          reason: "Di luar radius",
          distance_m: 5000,
        },
      },
      [`spm-trips/${TRIP}`]: trip,
    });
    open(`/planning/${TRIP}`);
    await userEvent.click(
      await screen.findByRole("button", { name: /Kirim posisi/ }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/Lintang/), "-6.2");
    await userEvent.type(within(dialog).getByLabelText(/Bujur/), "106.8");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    await waitFor(() =>
      expect(
        calls.find((c) => c.path === `spm-trips/${TRIP}/position`)?.body,
      ).toEqual({ latitude: -6.2, longitude: 106.8 }),
    );
    expect(
      await screen.findByText(/Posisi tidak mengubah stop: Di luar radius/),
    ).toBeTruthy();
  });
});
