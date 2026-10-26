import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

afterEach(() => vi.unstubAllGlobals());

const SPM = "11111111-1111-4111-8111-111111111111";

function stub() {
  const paths: string[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      paths.push(path);
      const json = (data: unknown) =>
        new Response(JSON.stringify(data), { status: 200 });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({
          data: {
            id: 9,
            name: "Ani",
            role: "customer",
            permissions: ["customer.portal"],
            tenant: { name: "Tenant A", slug: "a", minimum_margin_bps: 1500 },
            deployment_mode: "saas",
          },
        });
      if (path === "customer/portal")
        return json({
          data: {
            customer: { code: "CUST", name: "PT Maju" },
            open_spm: 1,
            unpaid_invoices: 1,
            outstanding_minor: 2600000000,
          },
        });
      if (path.startsWith("customer/spm?"))
        return json({
          data: [
            {
              id: SPM,
              number: "SPM-202610-00001",
              status: "IN_TRANSIT",
              origin_text: "Pool",
              destination_text: "Gudang",
              reference: "PO-9",
              planned_delivery_at: null,
              awbs: [{ number: "AWB-1", status: "ON_TRIP" }],
            },
          ],
        });
      if (path === `customer/spm/${SPM}`)
        return json({
          data: {
            spm: {},
            awbs: [
              {
                id: "a",
                number: "AWB-1",
                status: "ON_TRIP",
                destination_text: "Gudang",
                consignee_name: "Budi",
                delivered_at: null,
                lines: [{ material: "Palet", quantity: "2", po_number: null }],
              },
            ],
            history: [
              { to_status: "PLANNED", created_at: "2026-10-01T08:00:00Z" },
            ],
          },
        });
      if (path === "customer/invoices") return json({ data: [] });
      return json({ data: [] });
    }),
  );
  return paths;
}

describe("Customer portal", () => {
  it("shows only the customer's own shipments and never asks for internal data", async () => {
    const paths = stub();
    render(
      <MemoryRouter initialEntries={["/"]}>
        <App />
      </MemoryRouter>,
    );
    expect(await screen.findByText("PT Maju")).toBeTruthy();
    expect(screen.getByText(/1 pengiriman berjalan/)).toBeTruthy();
    await userEvent.click(
      await screen.findByRole("button", { name: "SPM-202610-00001" }),
    );
    expect(await screen.findByText(/Budi/)).toBeTruthy();
    expect(screen.queryByText("Kontrak kendaraan")).toBeNull();
    expect(
      paths.filter(
        (p) =>
          !p.startsWith("customer/") && !p.startsWith("auth/") && p !== "csrf",
      ),
    ).toEqual([]);
  });
});
