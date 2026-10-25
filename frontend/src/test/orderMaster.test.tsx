import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

beforeAll(() => {
  HTMLDialogElement.prototype.showModal ??= function showModal(
    this: HTMLDialogElement,
  ) {
    this.setAttribute("open", "");
  };
});
afterEach(() => vi.unstubAllGlobals());

const session = {
  id: 1,
  name: "Operator",
  role: "optinexus",
  permissions: ["operations.view", "operations.manage"],
  tenant: {
    name: "Tenant A",
    slug: "a",
    minimum_margin_bps: 1500,
    stage_margin_bps: {},
  },
  deployment_mode: "saas",
};
const C1 = "11111111-1111-4111-8111-111111111111";
const C2 = "22222222-2222-4222-8222-222222222222";
const P1 = "33333333-3333-4333-8333-333333333333";
const L1 = "44444444-4444-4444-8444-444444444444";
const L2 = "55555555-5555-4555-8555-555555555555";
const S1 = "66666666-6666-4666-8666-666666666666";
const options: Record<string, unknown[]> = {
  customers: [
    { id: C1, code: "C1", name: "PT Maju Jaya" },
    { id: C2, code: "C2", name: "PT Dua" },
  ],
  customer_projects: [
    { id: P1, code: "P1", name: "Proyek 1", customer_id: C1 },
  ],
  services: [{ id: S1, code: "EXP", name: "Express" }],
  locations: [
    { id: L1, code: "POOL", name: "Pool Jakarta" },
    { id: L2, code: "WH", name: "Gudang Bandung" },
  ],
};

function stub() {
  const posted: Record<string, unknown>[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const json = (data: unknown, status = 200) =>
        new Response(JSON.stringify(data), { status });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me")) return json({ data: session });
      const opt = path.match(/^master\/([a-z_]+)\/options$/);
      if (opt) return json({ data: options[opt[1]] ?? [] });
      if (path === "orders" && init?.method === "POST") {
        posted.push(JSON.parse(String(init.body)));
        return json(
          { data: { id: "99999999-9999-4999-8999-999999999999" } },
          201,
        );
      }
      return json({ data: [], current_page: 1, last_page: 1, total: 0 });
    }),
  );
  return posted;
}

describe("Order form with master data", () => {
  it("fills the text from the chosen master, locks it, and sends the ids", async () => {
    const posted = stub();
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
        screen.getByRole("option", { name: "C1 · PT Maju Jaya" }),
      ).toBeTruthy(),
    );

    const project = screen.getByLabelText(
      "Proyek / kontrak",
    ) as HTMLSelectElement;
    expect(project.disabled).toBe(true);

    await userEvent.selectOptions(
      screen.getByLabelText("Pelanggan (master data)"),
      C1,
    );
    const name = screen.getByLabelText("Nama pelanggan") as HTMLInputElement;
    expect(name.value).toBe("PT Maju Jaya");
    expect(name.readOnly).toBe(true);
    expect(project.disabled).toBe(false);
    expect(screen.getByRole("option", { name: "P1 · Proyek 1" })).toBeTruthy();
    await userEvent.selectOptions(project, P1);

    await userEvent.selectOptions(screen.getByLabelText("Layanan"), S1);
    await userEvent.selectOptions(
      screen.getByLabelText("Lokasi asal (master data)"),
      L1,
    );
    await userEvent.selectOptions(
      screen.getByLabelText("Lokasi tujuan (master data)"),
      L2,
    );
    expect(
      (screen.getByLabelText("Alamat / hub asal") as HTMLTextAreaElement).value,
    ).toBe("Pool Jakarta");
    expect(
      (screen.getByLabelText("Alamat / hub tujuan") as HTMLTextAreaElement)
        .readOnly,
    ).toBe(true);

    await userEvent.type(screen.getByLabelText("Tarif dasar (Rp)"), "1000000");
    fireEvent.submit(
      screen
        .getByRole("button", { name: /Simpan draft order/ })
        .closest("form")!,
    );

    await waitFor(() => expect(posted.length).toBe(1));
    expect(posted[0]).toMatchObject({
      customer_id: C1,
      project_id: P1,
      service_id: S1,
      origin_location_id: L1,
      destination_location_id: L2,
      customer_name: "PT Maju Jaya",
      origin: "Pool Jakarta",
      destination: "Gudang Bandung",
    });
  });

  it("changing the customer clears the project and unlocks manual entry again", async () => {
    stub();
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
      expect(screen.getByRole("option", { name: "C2 · PT Dua" })).toBeTruthy(),
    );

    await userEvent.selectOptions(
      screen.getByLabelText("Pelanggan (master data)"),
      C1,
    );
    await userEvent.selectOptions(
      screen.getByLabelText("Proyek / kontrak"),
      P1,
    );
    await userEvent.selectOptions(
      screen.getByLabelText("Pelanggan (master data)"),
      C2,
    );

    expect(
      (screen.getByLabelText("Proyek / kontrak") as HTMLSelectElement).value,
    ).toBe("");
    await userEvent.selectOptions(
      screen.getByLabelText("Pelanggan (master data)"),
      "",
    );
    expect(
      (screen.getByLabelText("Nama pelanggan") as HTMLInputElement).readOnly,
    ).toBe(false);
  });
});
