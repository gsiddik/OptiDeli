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

const CUST = "22222222-2222-4222-8222-222222222222";

function stub() {
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
        return json({
          data: {
            id: 1,
            name: "CFO",
            role: "cfo",
            permissions: ["operations.view", "customer.portal.provision"],
            tenant: { name: "Tenant A", slug: "a", minimum_margin_bps: 1500 },
            deployment_mode: "saas",
          },
        });
      if (path === "master/customers/options")
        return json({ data: [{ id: CUST, code: "CUST", name: "PT Maju" }] });
      if (path === "api-clients" && method === "POST")
        return json({ data: { id: "c1", token: "odcl_RAHASIA" } }, 201);
      if (path === "api-clients" || path === "webhooks")
        return json({ data: [] });
      return json({ data: [], current_page: 1, last_page: 1, total: 0 });
    }),
  );
  return calls;
}

describe("Integrations", () => {
  it("issues an API client and shows its token once", async () => {
    const calls = stub();
    render(
      <MemoryRouter initialEntries={["/integrations"]}>
        <App />
      </MemoryRouter>,
    );
    await userEvent.click(
      await screen.findByRole("button", { name: "+ Klien API" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.selectOptions(
      await within(dialog).findByLabelText("Pelanggan"),
      CUST,
    );
    await userEvent.type(within(dialog).getByLabelText(/Nama klien/), "ERP");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /Simpan/ }),
    );
    expect(await screen.findByText("odcl_RAHASIA")).toBeTruthy();
    await waitFor(() =>
      expect(
        calls.find((c) => c.method === "POST" && c.path === "api-clients")
          ?.body,
      ).toMatchObject({
        customer_id: CUST,
        name: "ERP",
        scopes: ["spm.read", "invoice.read"],
      }),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Sudah saya simpan" }),
    );
    expect(screen.queryByText("odcl_RAHASIA")).toBeNull();
  });
});
