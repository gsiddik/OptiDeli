import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
const password =
  process.env.OPTIDELI_E2E_PASSWORD ??
  readFileSync(
    new URL("../../.local/demo-password", import.meta.url),
    "utf8",
  ).trim();
const tenant = process.env.OPTIDELI_E2E_TENANT ?? "demo";
test("real order, margin approval, dispatch, responsive navigation and CSRF boundary", async ({
  page,
  browser,
  baseURL,
}) => {
  const run = Date.now().toString();
  const failures: string[] = [];
  page.on("pageerror", (e) => failures.push(e.message));
  await page.goto("/");
  await page.getByLabel("Tenant", { exact: true }).fill(tenant);
  await page
    .getByLabel("Email", { exact: true })
    .fill("dispatcher@optideli.test");
  await page.getByLabel("Kata sandi").fill(password);
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Ringkasan transportasi" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "+ Buat order" }).click();
  await page
    .getByLabel("Nama pelanggan", { exact: true })
    .fill(`E2E Customer ${run}`);
  await page.getByLabel("Alamat / hub asal").fill("Bekasi Hub");
  await page.getByLabel("Alamat / hub tujuan").fill("Jakarta Hub");
  await page.getByLabel("Deskripsi barang").fill("E2E carton");
  await page.getByLabel("Tarif dasar (Rp)", { exact: true }).fill("1000000");
  await page.getByRole("button", { name: "Simpan draft order" }).click();
  await expect(page.getByRole("heading", { name: /^OD-/ })).toBeVisible();
  const orderId = page.url().split("/").pop()!;
  await page
    .getByRole("button", { name: "Konfirmasi order", exact: true })
    .click();
  await page.getByRole("button", { name: "Ya, lanjutkan" }).click();
  await expect(page.getByText("CONFIRMED", { exact: true })).toBeVisible();
  const csrf = await (await page.request.get("/api/v1/csrf")).json();
  const headers = { "X-CSRF-TOKEN": csrf.token, Accept: "application/json" };
  const vehicle = await page.request.post("/api/v1/fleet/vehicles", {
    headers,
    data: {
      plate: `E2E-${run}`,
      type: "Box",
      capacity_g: 5000000,
      length_mm: 4000,
      width_mm: 2000,
      height_mm: 2000,
      documents_valid_until: new Date(Date.now() + 365 * 86400000)
        .toISOString()
        .slice(0, 10),
    },
  });
  expect(vehicle.status()).toBe(201);
  const vehicleId = (await vehicle.json()).data.id;
  const driver = await page.request.post("/api/v1/fleet/drivers", {
    headers,
    data: {
      name: `Driver ${run}`,
      license_number: `SIM-${run}`,
      license_valid_until: new Date(Date.now() + 365 * 86400000)
        .toISOString()
        .slice(0, 10),
      eligibility_verified_until: new Date(Date.now() + 86400000).toISOString(),
    },
  });
  expect(driver.status()).toBe(201);
  const driverId = (await driver.json()).data.id;
  // Cookie-authenticated mutation without the CSRF token must fail on the running server.
  const denied = await page.request.post(
    `/api/v1/orders/${orderId}/transition`,
    { headers: { Accept: "application/json" }, data: { action: "cancel" } },
  );
  expect(denied.status()).toBe(419);
  await page.getByRole("link", { name: "Buat rencana dispatch" }).click();
  await page.getByLabel("Kendaraan", { exact: true }).selectOption(vehicleId);
  await page.getByLabel("Pengemudi", { exact: true }).selectOption(driverId);
  await page.getByLabel("Tarif dasar vendor (Rp)").fill("890000");
  await page
    .getByLabel("Pernyataan pemeriksaan keselamatan muatan")
    .fill(
      "Petugas telah memeriksa penempatan, pengikatan dan distribusi beban muatan.",
    );
  await page.getByLabel("Reason code wajib").selectOption("BACKHAUL");
  await page.getByRole("button", { name: "Ajukan persetujuan margin" }).click();
  await expect(
    page.getByText("PENDING APPROVAL", { exact: true }),
  ).toBeVisible();
  const plans = await (await page.request.get("/api/v1/dispatch/plans")).json();
  const planId = plans.data.find(
    (p: { order_id: string }) => p.order_id === orderId,
  ).id;
  const blocked = await page.request.post(
    `/api/v1/dispatch/plans/${planId}/confirm`,
    { headers },
  );
  expect(blocked.status()).toBe(422);
  const approverContext = await browser.newContext({ baseURL });
  const approver = await approverContext.newPage();
  await approver.goto("/");
  await approver.getByLabel("Tenant", { exact: true }).fill(tenant);
  await approver
    .getByLabel("Email", { exact: true })
    .fill("supervisor@optideli.test");
  await approver.getByLabel("Kata sandi").fill(password);
  await approver.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    approver.getByRole("heading", { name: "Ringkasan transportasi" }),
  ).toBeVisible();
  await approver
    .getByRole("link", { name: "Board dispatch", exact: true })
    .click();
  const row = approver.getByRole("row").filter({ hasText: planId.slice(0, 8) });
  await row.getByRole("button", { name: "Setujui margin" }).click();
  await approver.getByRole("button", { name: "Ya, lanjutkan" }).click();
  await expect(row.getByText("APPROVED", { exact: true })).toBeVisible();
  await approverContext.close();
  await page.getByRole("button", { name: "Muat ulang", exact: true }).click();
  const dispatchRow = page
    .getByRole("row")
    .filter({ hasText: planId.slice(0, 8) });
  await dispatchRow
    .getByRole("button", { name: "Konfirmasi dispatch" })
    .click();
  await page.getByRole("button", { name: "Ya, lanjutkan" }).click();
  await expect(
    dispatchRow.getByText("DISPATCHED", { exact: true }),
  ).toBeVisible();
  await page.goto(`/orders/${orderId}`);
  await expect(page.getByText("DISPATCHED", { exact: true })).toBeVisible();
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 960 });
    await page.goto("/");
    await expect(
      page.getByRole("heading", { name: "Ringkasan transportasi" }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
    if (width === 390) {
      await page.getByRole("button", { name: "Buka menu" }).click();
      await expect(
        page.getByRole("link", { name: "Board dispatch", exact: true }),
      ).toBeVisible();
      await page
        .getByRole("link", { name: "Board dispatch", exact: true })
        .click();
      await expect(
        page.getByRole("heading", { name: "Board alokasi & persetujuan" }),
      ).toBeVisible();
      await expect(
        page
          .getByRole("navigation", { name: "Navigasi utama" })
          .getByRole("link", { name: "Board dispatch", exact: true }),
      ).not.toBeInViewport();
    } else {
      await page.getByRole("button", { name: "Perkecil sidebar" }).click();
      await expect(
        page.getByRole("button", { name: "Perluas sidebar" }),
      ).toBeVisible();
      await page.getByRole("button", { name: "Perluas sidebar" }).click();
    }
    await expect
      .poll(() => page.getByText("Memuat data…", { exact: true }).count())
      .toBe(0);
    await page.evaluate(() => {
      (document.activeElement as HTMLElement)?.blur();
      window.scrollTo(0, 0);
    });
    await page.screenshot({
      path: `test-results/optideli-${width}.png`,
      fullPage: true,
    });
  }
  expect(failures).toEqual([]);
  await page.getByRole("button", { name: "Keluar", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Masuk", exact: true }),
  ).toBeVisible();
  await page.getByLabel("Tenant", { exact: true }).fill(tenant);
  await page
    .getByLabel("Email", { exact: true })
    .fill("dispatcher@optideli.test");
  await page.getByLabel("Kata sandi").fill(password);
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Board alokasi & persetujuan" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Keluar", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Masuk", exact: true }),
  ).toBeVisible();
});
