import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

afterEach(() => vi.unstubAllGlobals());

function stub(status: { sso_enabled: boolean; password_login: boolean }) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (path: string) =>
      path.includes("auth/sso/status")
        ? new Response(JSON.stringify({ data: status }), { status: 200 })
        : new Response(JSON.stringify({ message: "Unauthenticated." }), {
            status: 401,
          }),
    ),
  );
}

describe("Sign-in doors", () => {
  it("offers only OptiNexus when the tenant password login is closed and explains an SSO refusal", async () => {
    stub({ sso_enabled: true, password_login: false });
    render(
      <MemoryRouter initialEntries={["/?sso_error=tenant_not_linked"]}>
        <App />
      </MemoryRouter>,
    );
    const button = await screen.findByRole("link", {
      name: "Masuk dengan OptiNexus",
    });
    expect(button.getAttribute("href")).toBe("/api/v1/auth/sso/redirect");
    expect(screen.queryByLabelText("Tenant")).toBeNull();
    expect(
      screen.getByText(/belum ditautkan ke OptiDeli/).textContent,
    ).toBeTruthy();
    expect(
      screen.getByRole("link", { name: "Masuk sebagai Superadmin" }),
    ).toBeTruthy();
  });

  it("keeps the password form in local mode", async () => {
    stub({ sso_enabled: false, password_login: true });
    render(
      <MemoryRouter>
        <App />
      </MemoryRouter>,
    );
    expect(await screen.findByLabelText("Tenant")).toBeTruthy();
    expect(
      screen.queryByRole("link", { name: "Masuk dengan OptiNexus" }),
    ).toBeNull();
  });
});
