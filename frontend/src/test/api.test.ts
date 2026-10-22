import { afterEach, expect, it, vi } from "vitest";
import { api } from "../api";
afterEach(() => vi.unstubAllGlobals());
it("clears the old CSRF token on logout so the next login fetches a new one", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ token: "old-session-token" }), {
        status: 200,
      }),
    )
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ token: "new-session-token" }), {
        status: 200,
      }),
    )
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ data: {} }), { status: 200 }),
    );
  vi.stubGlobal("fetch", fetcher);
  await api("auth/logout", "POST");
  await api("auth/login", "POST", {
    tenant: "demo",
    email: "test@example.test",
    password: "test-only",
  });
  expect(fetcher.mock.calls.map((call) => call[0])).toEqual([
    "/api/v1/csrf",
    "/api/v1/auth/logout",
    "/api/v1/csrf",
    "/api/v1/auth/login",
  ]);
  expect(fetcher.mock.calls[3][1].headers["X-CSRF-TOKEN"]).toBe(
    "new-session-token",
  );
});
