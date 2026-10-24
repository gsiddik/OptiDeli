import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

afterEach(() => vi.unstubAllGlobals());

describe("Superadmin console", () => {
  it("shows the platform login on /platform without calling the tenant session", async () => {
    const fetch = vi.fn(
      async (_path: string) =>
        new Response(JSON.stringify({ message: "Unauthenticated." }), {
          status: 401,
        }),
    );
    vi.stubGlobal("fetch", fetch);
    render(
      <MemoryRouter initialEntries={["/platform"]}>
        <App />
      </MemoryRouter>,
    );
    await screen.findByRole("heading", { name: "Masuk sebagai Superadmin" });
    const paths = fetch.mock.calls.map((c) => String(c[0]));
    expect(paths.some((p) => p.includes("platform/auth/me"))).toBe(true);
    expect(
      paths.some((p) => p.endsWith("/auth/me") && !p.includes("platform")),
    ).toBe(false);
  });

  it("lists tenants and offers only the actions the platform role holds", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (path: string) => {
        const body = path.includes("platform/auth/me")
          ? {
              data: {
                id: 1,
                name: "Support",
                permissions: ["platform.tenant.view"],
                deployment_mode: "standalone",
                standalone_tenant: "demo",
              },
            }
          : {
              data: [
                {
                  id: "t1",
                  slug: "demo",
                  name: "Demo",
                  active: true,
                  active_members: 3,
                },
              ],
            };
        return new Response(JSON.stringify(body), { status: 200 });
      }),
    );
    render(
      <MemoryRouter initialEntries={["/platform"]}>
        <App />
      </MemoryRouter>,
    );
    await screen.findByText("Demo");
    expect(screen.getByText(/Mode standalone/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Buat tenant" })).toBeNull();
    expect(
      screen.queryByRole("button", { name: "Tangguhkan demo" }),
    ).toBeNull();
  });
});
