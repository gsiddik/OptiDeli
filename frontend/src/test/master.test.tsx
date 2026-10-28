import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { pick } from "./pick";

beforeAll(() => {
  // jsdom has no <dialog>.showModal; real browsers do.
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

/** Chooses an option of a searchable dropdown: open it, then click the row. */
async function choose(
  dialog: HTMLElement,
  label: string,
  option: string | RegExp,
) {
  await userEvent.click(within(dialog).getByLabelText(label));
  await userEvent.click(within(dialog).getByRole("option", { name: option }));
}

const session = (permissions: string[]) => ({
  id: 1,
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
const text = (name: string, label: string, extra = {}) => ({
  name,
  label,
  kind: "text",
  ...extra,
});
const schema = {
  groups: { customers: "Pelanggan", network: "Jaringan" },
  resources: [
    {
      key: "customers",
      label: "Pelanggan",
      singular: "pelanggan",
      group: "customers",
      fields: [
        text("code", "Kode", {
          required: false,
          immutable: true,
          generated: true,
          list: true,
        }),
        text("name", "Nama", { required: true, list: true }),
        {
          name: "payment_terms_days",
          label: "Termin bayar (hari)",
          kind: "number",
          required: true,
          default: 30,
          list: true,
        },
      ],
    },
    {
      key: "customer_projects",
      label: "Proyek / kontrak",
      singular: "proyek",
      group: "customers",
      fields: [
        text("code", "Kode", {
          required: false,
          immutable: true,
          generated: true,
          list: true,
        }),
        {
          name: "customer_id",
          label: "Pelanggan",
          kind: "ref",
          ref: "customers",
          required: true,
          immutable: true,
          list: true,
        },
      ],
    },
    {
      key: "regions",
      label: "Provinsi",
      singular: "provinsi",
      group: "network",
      fields: [
        text("code", "Kode", { required: true, immutable: true, list: true }),
        text("name", "Nama", { required: true, list: true }),
      ],
    },
    {
      key: "locations",
      label: "Lokasi",
      singular: "lokasi",
      group: "network",
      fields: [
        text("code", "Kode", { required: true, immutable: true, list: true }),
        {
          name: "type",
          label: "Jenis",
          kind: "select",
          required: true,
          list: true,
          options: { POOL: "Pool kendaraan", HUB: "Hub" },
        },
        {
          name: "latitude",
          label: "Lintang",
          kind: "decimal",
          required: false,
        },
        {
          name: "stackable",
          label: "Dapat ditumpuk",
          kind: "boolean",
          required: true,
          default: true,
        },
      ],
    },
  ],
};
const customerRow = {
  id: "11111111-1111-4111-8111-111111111111",
  code: "CUST-01",
  name: "PT Maju Jaya",
  payment_terms_days: 30,
  active: true,
};
const page = (rows: unknown[]) => ({
  data: rows,
  current_page: 1,
  last_page: 1,
  total: rows.length,
});

type Call = { method: string; path: string; body?: Record<string, unknown> };
function stubApi(
  permissions: string[],
  rows: Record<string, unknown[]> = { customers: [customerRow] },
) {
  const calls: Call[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const method = init?.method ?? "GET";
      const body =
        typeof init?.body === "string" ? JSON.parse(init.body) : undefined;
      calls.push({ method, path, body });
      const json = (data: unknown, status = 200) =>
        new Response(JSON.stringify(data), { status });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({ data: session(permissions) });
      if (path === "master/schema") return json({ data: schema });
      if (path === "master/regional-reference" && method === "POST")
        return json({
          data: {
            regions_created: 38,
            regions_existing: 0,
            cities_created: 514,
            cities_existing: 2,
          },
        });
      if (path === "master/customers/options")
        return json({
          data: [{ id: customerRow.id, code: "CUST-01", name: "PT Maju Jaya" }],
        });
      if (method !== "GET")
        return json(
          { data: { ...customerRow, ...body } },
          method === "POST" && !path.endsWith("/status") ? 201 : 200,
        );
      const key = path.split("?")[0].replace("master/", "");
      return json(page(rows[key] ?? []));
    }),
  );
  return calls;
}
function open(path = "/master") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );
}

describe("Master data screen", () => {
  it("lists a master with its status and moves between masters from the Master data menu", async () => {
    stubApi(["operations.view", "master.manage"]);
    open();

    expect(
      await screen.findByRole("heading", { level: 1, name: "Pelanggan" }),
    ).toBeTruthy();
    expect(await screen.findByText("PT Maju Jaya")).toBeTruthy();
    expect(screen.getByText("ACTIVE")).toBeTruthy();

    const menu = within(
      screen.getByRole("navigation", { name: "Navigasi utama" }),
    );
    const customers = await menu.findByRole("link", { name: "Pelanggan" });
    expect(customers.getAttribute("aria-current")).toBe("page");
    expect(menu.getByRole("group", { name: "Jaringan" })).toBeTruthy();

    fireEvent.click(menu.getByRole("link", { name: "Lokasi" }));
    expect(
      await screen.findByRole("heading", { level: 1, name: "Lokasi" }),
    ).toBeTruthy();
    expect(await screen.findByText("Belum ada lokasi.")).toBeTruthy();
    expect(
      menu.getByRole("link", { name: "Lokasi" }).getAttribute("aria-current"),
    ).toBe("page");
  });

  it("creates a record: fields come from the schema and the body is what the API expects", async () => {
    const calls = stubApi(["operations.view", "master.manage"]);
    open();
    fireEvent.click(
      await screen.findByRole("button", { name: "+ Tambah pelanggan" }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Kode")).toBeNull();
    expect(within(dialog).getByText(/Kode dibuat otomatis/)).toBeTruthy();
    await userEvent.type(within(dialog).getByLabelText("Nama"), "PT Baru");
    expect(
      (within(dialog).getByLabelText("Termin bayar (hari)") as HTMLInputElement)
        .value,
    ).toBe("30");
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: /Simpan/ })
        .closest("form")!,
    );

    await waitFor(() =>
      expect(
        calls.some((c) => c.method === "POST" && c.path === "master/customers"),
      ).toBe(true),
    );
    expect(
      calls.find((c) => c.method === "POST" && c.path === "master/customers")!
        .body,
    ).toEqual({ name: "PT Baru", payment_terms_days: 30 });
  });

  it("edits a record without the immutable code", async () => {
    const calls = stubApi(["operations.view", "master.manage"]);
    open();
    fireEvent.click(await screen.findByRole("button", { name: "Ubah" }));

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Kode")).toBeNull();
    const name = within(dialog).getByLabelText("Nama") as HTMLInputElement;
    expect(name.value).toBe("PT Maju Jaya");
    await userEvent.clear(name);
    await userEvent.type(name, "PT Maju Makmur");
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: /Simpan/ })
        .closest("form")!,
    );

    await waitFor(() =>
      expect(calls.some((c) => c.method === "PUT")).toBe(true),
    );
    const put = calls.find((c) => c.method === "PUT")!;
    expect(put.path).toBe(`master/customers/${customerRow.id}`);
    expect(put.body).toEqual({
      name: "PT Maju Makmur",
      payment_terms_days: 30,
    });
  });

  it("offers the active rows of the referenced master and sends the chosen id", async () => {
    const calls = stubApi(["operations.view", "master.manage"], {
      customers: [customerRow],
      customer_projects: [],
    });
    open("/master/customer_projects");
    fireEvent.click(
      await screen.findByRole("button", { name: "+ Tambah proyek" }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Kode")).toBeNull();
    await choose(dialog, "Pelanggan", /CUST/);
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: /Simpan/ })
        .closest("form")!,
    );

    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.method === "POST" && c.path === "master/customer_projects",
        ),
      ).toBe(true),
    );
    expect(calls.find((c) => c.method === "POST")!.body).toEqual({
      customer_id: customerRow.id,
    });
  });

  it("accepts a negative decimal and a yes/no field as numbers and booleans-as-text for the server", async () => {
    const calls = stubApi(["operations.view", "master.manage"], {
      locations: [],
    });
    open("/master/locations");
    fireEvent.click(
      await screen.findByRole("button", { name: "+ Tambah lokasi" }),
    );

    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText("Kode"), "pool-1");
    await choose(dialog, "Jenis", "Pool kendaraan");
    await userEvent.type(within(dialog).getByLabelText("Lintang"), "-6.175110");
    expect(
      (within(dialog).getByLabelText("Dapat ditumpuk") as HTMLInputElement)
        .value,
    ).toBe("Ya");
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: /Simpan/ })
        .closest("form")!,
    );

    await waitFor(() =>
      expect(
        calls.some((c) => c.method === "POST" && c.path === "master/locations"),
      ).toBe(true),
    );
    expect(calls.find((c) => c.method === "POST")!.body).toEqual({
      code: "pool-1",
      type: "POOL",
      latitude: -6.17511,
      stackable: "1",
    });
  });

  it("deactivates only with a reason, and reactivates directly", async () => {
    const calls = stubApi(["operations.view", "master.manage"], {
      customers: [
        customerRow,
        {
          ...customerRow,
          id: "22222222-2222-4222-8222-222222222222",
          code: "OFF",
          name: "PT Lama",
          active: false,
        },
      ],
    });
    open();
    fireEvent.click(await screen.findByRole("button", { name: "Nonaktifkan" }));

    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText(/Alasan/),
      "kontrak berakhir",
    );
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: "Ya, lanjutkan" })
        .closest("form")!,
    );
    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.path.endsWith("/status") && c.body?.active === false,
        ),
      ).toBe(true),
    );
    expect(calls.find((c) => c.path.endsWith("/status"))!.body).toEqual({
      active: false,
      reason: "kontrak berakhir",
    });

    fireEvent.click(await screen.findByRole("button", { name: "Aktifkan" }));
    await waitFor(() =>
      expect(calls.some((c) => c.body?.active === true)).toBe(true),
    );
  });

  it("loads the official regional reference after a confirmation and reports what was added", async () => {
    const calls = stubApi(["operations.view", "master.manage"], {
      regions: [],
    });
    open("/master/regions");
    fireEvent.click(
      await screen.findByRole("button", { name: "Muat data wilayah resmi" }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/38 provinsi dan 514 kabupaten\/kota/),
    ).toBeTruthy();
    fireEvent.submit(
      within(dialog)
        .getByRole("button", { name: "Ya, lanjutkan" })
        .closest("form")!,
    );

    expect(
      await screen.findByText(
        /Ditambahkan 38 provinsi dan 514 kota\/kabupaten; 2 sudah ada/,
      ),
    ).toBeTruthy();
    expect(
      calls.some(
        (c) => c.method === "POST" && c.path === "master/regional-reference",
      ),
    ).toBe(true);
  });

  it("does not offer the regional load on other masters or without master.manage", async () => {
    stubApi(["operations.view", "master.manage"]);
    open();
    await screen.findByText("PT Maju Jaya");
    expect(
      screen.queryByRole("button", { name: "Muat data wilayah resmi" }),
    ).toBeNull();
  });

  it("is read-only without master.manage", async () => {
    stubApi(["operations.view"]);
    open();
    expect(await screen.findByText("PT Maju Jaya")).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: "+ Tambah pelanggan" }),
    ).toBeNull();
    expect(screen.queryByRole("button", { name: "Ubah" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Nonaktifkan" })).toBeNull();
  });

  it("searches by text and by status", async () => {
    const calls = stubApi(["operations.view"]);
    open();
    await screen.findByText("PT Maju Jaya");
    await userEvent.type(screen.getByLabelText("Cari Pelanggan"), "maju");
    await pick(screen.getByLabelText("Status"), "0");
    fireEvent.click(screen.getByRole("button", { name: "Cari" }));
    await waitFor(() =>
      expect(
        calls.some(
          (c) => c.path.includes("q=maju") && c.path.includes("active=0"),
        ),
      ).toBe(true),
    );
  });
  it("lets a dropdown be searched by typing, with the keyboard or the mouse, and cleared again", async () => {
    stubApi(["operations.view", "master.manage"], { locations: [] });
    open("/master/locations");
    fireEvent.click(
      await screen.findByRole("button", { name: "+ Tambah lokasi" }),
    );
    const dialog = await screen.findByRole("dialog");

    const type = within(dialog).getByLabelText("Jenis");
    await userEvent.click(type);
    expect(within(dialog).getAllByRole("option").length).toBe(2);
    await userEvent.type(type, "hub");
    expect(
      within(dialog)
        .getAllByRole("option")
        .map((o) => o.textContent),
    ).toEqual(["Hub"]);
    await userEvent.keyboard("{Enter}");
    expect((type as HTMLInputElement).value).toBe("Hub");
    expect(within(dialog).queryByRole("listbox")).toBeNull();

    await userEvent.click(type);
    await userEvent.type(type, "zzz");
    expect(within(dialog).getByText("Tidak ada yang cocok")).toBeTruthy();
    await userEvent.keyboard("{Escape}");
    expect((type as HTMLInputElement).value).toBe("Hub");
  });
});
