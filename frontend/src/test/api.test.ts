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

it("uses browser-generated multipart boundaries for private evidence uploads", async () => {
  vi.resetModules();
  const { api: uploadApi } = await import("../api");
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ token: "upload-token" }), { status: 200 }),
    )
    .mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { id: "proof" } }), { status: 201 }),
    );
  vi.stubGlobal("fetch", fetcher);
  const form = new FormData();
  form.append("receiver_name", "Receiver");
  form.append(
    "file",
    new File(["%PDF-1.4"], "proof.pdf", { type: "application/pdf" }),
  );
  await uploadApi("trips/example/pods", "POST", form, "upload-idempotency-key");
  const options = fetcher.mock.calls[1][1];
  expect(options.body).toBe(form);
  expect(options.headers["Content-Type"]).toBeUndefined();
  expect(options.headers["X-CSRF-TOKEN"]).toBe("upload-token");
});

it("rejects a successful HTML error page instead of treating it as session data", async () => {
  vi.stubGlobal(
    "fetch",
    vi
      .fn()
      .mockResolvedValue(
        new Response("<html>server error</html>", { status: 200 }),
      ),
  );
  await expect(api("auth/me")).rejects.toThrow("Respons server tidak valid");
});
