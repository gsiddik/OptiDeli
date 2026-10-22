import { test, expect, type BrowserContext, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
const password =
  process.env.OPTIDELI_E2E_PASSWORD ??
  readFileSync(
    new URL("../../.local/demo-password", import.meta.url),
    "utf8",
  ).trim();
const proof = {
  name: "evidence.pdf",
  mimeType: "application/pdf",
  buffer: Buffer.from("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
};
test("execution, yard, private ePOD, vendor correction and independent dual freight approval", async ({
  browser,
  baseURL,
}) => {
  test.setTimeout(180000);
  const contexts: BrowserContext[] = [];
  const failures: string[] = [];
  const run = Date.now().toString();
  async function login(
    role: string,
    secret = password,
    email = `${role}@optideli.test`,
  ) {
    const c = await browser.newContext({
      baseURL,
      timezoneId: "Asia/Bangkok",
      viewport: { width: 1440, height: 1000 },
    });
    contexts.push(c);
    const p = await c.newPage();
    p.on("pageerror", (e) => failures.push(e.message));
    await p.goto("/");
    await p
      .getByLabel("Tenant", { exact: true })
      .fill(process.env.OPTIDELI_E2E_TENANT ?? "demo");
    await p.getByLabel("Email", { exact: true }).fill(email);
    await p.getByLabel("Kata sandi").fill(secret);
    await p.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(
      p.getByRole("button", { name: "Keluar", exact: true }),
    ).toBeVisible();
    return p;
  }
  async function save(p: Page) {
    await p
      .getByRole("button", { name: "Simpan & lanjutkan", exact: true })
      .click();
    await expect(p.getByRole("dialog")).toHaveCount(0);
  }
  async function action(p: Page, name: string) {
    await p.getByRole("button", { name, exact: true }).click();
    await save(p);
  }
  async function localFuture(p: Page, hours: number) {
    return p.evaluate((h) => {
      const d = new Date(Date.now() + h * 3600000);
      return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
    }, hours);
  }
  const ops = await login("head_of_logistics");
  const token = (await (await ops.request.get("/api/v1/csrf")).json()).token;
  async function post(path: string, data: object = {}) {
    const response = await ops.request.post(`/api/v1/${path}`, {
      headers: {
        "X-CSRF-TOKEN": token,
        Accept: "application/json",
        "Idempotency-Key": crypto.randomUUID(),
      },
      data,
    });
    expect(response.ok(), await response.text()).toBe(true);
    return (await response.json()).data;
  }
  const order = await post("orders", {
    customer_name: `Execution ${run}`,
    stage: "MIDDLE_MILE",
    origin: "Hub A",
    destination: "Hub B",
    currency: "IDR",
    base_price_minor: 2000000000,
    surcharge_minor: 0,
    accessorial_minor: 0,
    items: [
      {
        description: "Carton",
        quantity: 1,
        unit_weight_g: 1000,
        length_mm: 100,
        width_mm: 100,
        height_mm: 100,
        stackable: true,
      },
    ],
  });
  await post(`orders/${order.id}/transition`, { action: "confirm" });
  const valid = new Date(Date.now() + 365 * 86400000)
    .toISOString()
    .slice(0, 10);
  const vehicle = await post("fleet/vehicles", {
    plate: `P2-${run}`,
    type: "Box",
    capacity_g: 1000000,
    length_mm: 4000,
    width_mm: 2000,
    height_mm: 2000,
    documents_valid_until: valid,
  });
  const driver = await post("fleet/drivers", {
    name: `P2 ${run}`,
    license_number: `P2-${run}`,
    license_valid_until: valid,
    eligibility_verified_until: new Date(Date.now() + 86400000).toISOString(),
  });
  const plan = await post("dispatch/plans", {
    order_id: order.id,
    vehicle_id: vehicle.id,
    driver_id: driver.id,
    vendor_base_minor: 1600000000,
    vendor_accessorial_minor: 0,
    starts_at: new Date(Date.now() + 3600000).toISOString(),
    ends_at: new Date(Date.now() + 6 * 3600000).toISOString(),
    load_safety_attestation:
      "Operator verified load securement and weight distribution.",
  });
  const trip = await post(`dispatch/plans/${plan.id}/confirm`);
  const vendor = await post("vendors", {
    name: `Vendor ${run}`,
    code: `P2-${run}`,
  });
  await post(`trips/${trip.id}/vendor`, {
    vendor_id: vendor.id,
    contract_reference: `PO-${run}`,
  });
  await ops.goto(`/trips/${trip.id}`);
  await ops.getByRole("button", { name: "Tambah leg", exact: true }).click();
  await ops
    .getByLabel("ETA leg", { exact: true })
    .fill(await localFuture(ops, 3));
  await save(ops);
  await expect(ops.getByText("PLANNED", { exact: true })).toBeVisible();
  await ops.goto("/yard");
  await ops
    .getByRole("button", { name: "Daftarkan dock", exact: true })
    .click();
  await ops.getByLabel("Nama hub", { exact: true }).fill("E2E Hub");
  await ops.getByLabel("Nama pintu dock", { exact: true }).fill(`Dock-${run}`);
  await save(ops);
  await ops
    .getByRole("button", { name: "Reservasi slot", exact: true })
    .click();
  await ops
    .getByLabel("Dock", { exact: true })
    .selectOption({ label: `E2E Hub · Dock-${run}` });
  await ops.getByLabel("Trip aktif", { exact: true }).selectOption(trip.id);
  await ops
    .getByLabel("Mulai slot", { exact: true })
    .fill(await localFuture(ops, 1));
  await ops
    .getByLabel("Akhir slot", { exact: true })
    .fill(await localFuture(ops, 2));
  await save(ops);
  for (const name of [
    "Catat gate-in",
    "Masuk dock",
    "Selesai bongkar/muat",
    "Catat gate-out",
  ]) {
    await ops
      .getByRole("row")
      .filter({ hasText: `Dock-${run}` })
      .getByRole("button", { name, exact: true })
      .click();
    await save(ops);
  }
  await expect(
    ops
      .getByRole("row")
      .filter({ hasText: `Dock-${run}` })
      .getByText("GATE OUT", { exact: true }),
  ).toBeVisible();
  await ops.goto(`/trips/${trip.id}`);
  for (const name of ["Mulai perjalanan", "Catat berangkat", "Catat tiba"])
    await action(ops, name);
  await expect(
    ops.locator(".page-heading").getByText("ARRIVED", { exact: true }),
  ).toBeVisible();
  await ops.getByRole("button", { name: "Unggah ePOD", exact: true }).click();
  await ops
    .getByLabel("Nama penerima", { exact: true })
    .fill("Receiving Officer");
  await ops.getByLabel("Jumlah unit diterima", { exact: true }).fill("1");
  await ops
    .getByLabel("Hasil pengiriman", { exact: true })
    .selectOption("DELIVERED");
  await ops
    .getByLabel("Bukti PDF/JPEG/PNG (maks. 10 MB)", { exact: true })
    .setInputFiles(proof);
  await save(ops);
  const supervisor = await login("supervisor");
  await supervisor.goto(`/trips/${trip.id}`);
  await supervisor
    .getByRole("button", { name: "Tinjau ePOD", exact: true })
    .click();
  await supervisor
    .getByLabel("Keputusan", { exact: true })
    .selectOption("verify");
  await supervisor
    .getByLabel("Catatan tinjauan", { exact: true })
    .fill("Receiving evidence checked independently.");
  await save(supervisor);
  await ops.reload();
  await action(ops, "Selesaikan & lepas alokasi");
  await expect(
    ops.locator(".page-heading").getByText("COMPLETED", { exact: true }),
  ).toBeVisible();
  const cfo = await login("cfo");
  await cfo.goto("/vendors");
  await cfo
    .getByRole("button", { name: `Buat akun ${vendor.code}`, exact: true })
    .click();
  await cfo
    .getByLabel("Nama pengguna vendor", { exact: true })
    .fill("Vendor AP Officer");
  const email = `vendor.${run}@optideli.test`;
  const vendorPassword = `Aa1!${password}`;
  await cfo.getByLabel("Email akun baru", { exact: true }).fill(email);
  await cfo
    .getByLabel(
      "Kata sandi awal (min. 12 karakter, huruf besar/kecil, angka, simbol)",
      { exact: true },
    )
    .fill(vendorPassword);
  await save(cfo);
  const portal = await login("vendor", vendorPassword, email);
  await expect(
    portal.getByRole("heading", { name: "Invoice & sanggahan" }),
  ).toBeVisible();
  async function invoice(button: string, base: string, extra: string) {
    await portal.getByRole("button", { name: button, exact: true }).click();
    await portal
      .getByLabel("Nomor invoice vendor", { exact: true })
      .fill(`INV-${run}`);
    await portal
      .getByLabel("Tarif dasar invoice (Rp)", { exact: true })
      .fill(base);
    await portal
      .getByLabel("Aksesorial invoice (Rp)", { exact: true })
      .fill(extra);
    await portal
      .getByLabel("Referensi dokumen invoice", { exact: true })
      .fill(`Original invoice ${run}`);
    await portal
      .getByLabel("Berkas invoice PDF/JPEG/PNG (maks. 10 MB)", { exact: true })
      .setInputFiles(proof);
    await save(portal);
  }
  await invoice("Unggah invoice", "16000001", "0");
  await expect(
    portal.getByText("BASE_RATE_MISMATCH", { exact: true }),
  ).toBeVisible();
  await portal
    .getByRole("button", { name: "Jawab sanggahan", exact: true })
    .click();
  await portal
    .getByLabel("Jawaban resmi vendor", { exact: true })
    .fill("We accept the discrepancy and will submit a corrected invoice.");
  await save(portal);
  await invoice("Unggah invoice koreksi", "16000000", "0");
  await expect(
    portal.getByText("READY FOR PAYMENT", { exact: true }),
  ).toBeVisible();
  await ops
    .getByRole("button", { name: "Ajukan aksesorial", exact: true })
    .click();
  await ops
    .getByLabel("Kategori biaya", { exact: true })
    .selectOption("DETENTION");
  await ops.getByLabel("Nilai klaim (Rp)", { exact: true }).fill("20000");
  await ops
    .getByLabel("Alasan klaim", { exact: true })
    .fill("Verified waiting time at the receiving hub.");
  await ops
    .getByLabel("Referensi bukti / laporan yard", { exact: true })
    .fill(`Yard-${run}`);
  await save(ops);
  await portal.reload();
  await expect(
    portal.getByText("UNVERIFIED_ACCESSORIAL", { exact: true }),
  ).toBeVisible();
  const finance = await login("finance");
  await finance.goto(`/trips/${trip.id}`);
  await finance
    .getByRole("button", { name: "Verifikasi aksesorial", exact: true })
    .click();
  await finance.getByLabel("Keputusan", { exact: true }).selectOption("verify");
  await finance
    .getByLabel("Catatan tinjauan", { exact: true })
    .fill("Yard supporting reference independently checked.");
  await save(finance);
  await portal.reload();
  await invoice("Unggah invoice koreksi", "16000000", "20000");
  const records = (
    await (await portal.request.get("/api/v1/vendor/portal")).json()
  ).data;
  const current = records.invoices.find(
    (i: { status: string }) => i.status === "ON_HOLD",
  );
  expect(current.approval_tier).toBe("DUAL");
  const fs = await login("finance_supervisor");
  await fs.goto(`/freight/${current.id}`);
  await fs
    .getByRole("button", { name: "Setujui sesuai otoritas", exact: true })
    .click();
  await fs
    .getByLabel("Catatan tinjauan", { exact: true })
    .fill("Finance supervisor approves validated accessorial cost.");
  await save(fs);
  await expect(
    fs.getByText("FINANCE SUPERVISOR", { exact: true }),
  ).toBeVisible();
  await expect(
    fs.locator(".page-heading").getByText("ON HOLD", { exact: true }),
  ).toBeVisible();
  await ops.goto(`/freight/${current.id}`);
  await ops
    .getByRole("button", { name: "Setujui sesuai otoritas", exact: true })
    .click();
  await ops
    .getByLabel("Catatan tinjauan", { exact: true })
    .fill("Logistics manager independently approves the operational cost.");
  await save(ops);
  await expect(
    ops
      .locator(".page-heading")
      .getByText("READY FOR PAYMENT", { exact: true }),
  ).toBeVisible();
  await ops.screenshot({
    path: "test-results/p2-freight-1440.png",
    fullPage: true,
  });
  expect((await portal.request.get("/api/v1/orders")).status()).toBe(403);
  expect(
    (await portal.request.get(`/api/v1/files/invoices/${current.id}`)).status(),
  ).toBe(200);
  await portal.setViewportSize({ width: 390, height: 960 });
  await portal.reload();
  await expect(
    portal.getByRole("heading", { name: "Invoice & sanggahan" }),
  ).toBeVisible();
  expect(
    await portal.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
  await portal.screenshot({
    path: "test-results/p2-vendor-390.png",
    fullPage: true,
  });
  expect(failures).toEqual([]);
  for (const c of contexts) await c.close();
});
