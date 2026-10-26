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
const schema = {
  groups: { customers: "Pelanggan" },
  resources: [
    {
      key: "customers",
      label: "Pelanggan",
      singular: "pelanggan",
      group: "customers",
      fields: [
        {
          name: "code",
          label: "Kode",
          kind: "text",
          required: true,
          immutable: true,
          list: true,
        },
        { name: "name", label: "Nama", kind: "text", required: true },
      ],
    },
  ],
};
const JOB = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const job = (over = {}) => ({
  id: JOB,
  resource: "customers",
  filename: "pelanggan.csv",
  status: "PREVIEW",
  total_rows: 3,
  rows_new: 2,
  rows_update: 0,
  rows_unchanged: 0,
  rows_error: 1,
  ignored_columns: ["Catatan"],
  already_imported_at: null,
  result: null,
  ...over,
});

function stub(permissions: string[]) {
  const calls: { method: string; path: string; body?: unknown }[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const method = init?.method ?? "GET";
      calls.push({ method, path, body: init?.body });
      const json = (data: unknown, status = 200) =>
        new Response(JSON.stringify(data), { status });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({ data: session(permissions) });
      if (path === "master/schema") return json({ data: schema });
      if (path === "master/customers/imports")
        return json({ data: job() }, 201);
      if (path.startsWith(`master/imports/${JOB}/rows`))
        return json({
          data: [
            {
              row: 3,
              action: "ERROR",
              code: "BAD CODE",
              name: "PT Buruk",
              errors: {
                code: ["Kode hanya boleh huruf, angka, titik, minus."],
              },
            },
          ],
          current_page: 1,
          last_page: 1,
          total: 1,
        });
      if (path === `master/imports/${JOB}/commit`)
        return json({
          data: job({
            status: "COMMITTED",
            result: { created: 2, updated: 0, unchanged: 0, skipped_errors: 1 },
          }),
        });
      if (path === `master/imports/${JOB}/discard`)
        return json({ data: job({ status: "DISCARDED" }) });
      return json({ data: [], current_page: 1, last_page: 1, total: 0 });
    }),
  );
  return calls;
}

function open() {
  render(
    <MemoryRouter initialEntries={["/master"]}>
      <App />
    </MemoryRouter>,
  );
}

describe("Master data import", () => {
  it("offers template and export downloads, and the import only to those who can manage", async () => {
    stub(["operations.view"]);
    open();
    const template = await screen.findByRole(
      "link",
      { name: "Template Excel" },
      { timeout: 4000 },
    );
    expect(template.getAttribute("href")).toBe(
      "/api/v1/master/customers/template?format=xlsx",
    );
    expect(screen.getByRole("link", { name: "Ekspor CSV" })).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: "Impor Excel / CSV" }),
    ).toBeNull();
  });

  it("previews the file, blocks apply until failed rows are skipped, then applies", async () => {
    const calls = stub(["operations.view", "master.manage"]);
    open();
    await userEvent.click(
      await screen.findByRole("button", { name: "Impor Excel / CSV" }),
    );
    const dialog = await screen.findByRole("dialog");
    const file = new File(["Kode;Nama\n"], "pelanggan.csv", {
      type: "text/csv",
    });
    await userEvent.upload(within(dialog).getByLabelText("Berkas impor"), file);

    expect(await within(dialog).findByText("Baru: 2")).toBeTruthy();
    expect(within(dialog).getByText("Gagal: 1")).toBeTruthy();
    expect(within(dialog).getByText(/Kolom diabaikan: Catatan/)).toBeTruthy();
    expect(await within(dialog).findByText(/Kode hanya boleh/)).toBeTruthy();
    const apply = within(dialog).getByRole("button", {
      name: "Terapkan impor",
    });
    expect((apply as HTMLButtonElement).disabled).toBe(true);

    await userEvent.click(
      within(dialog).getByLabelText(/Lewati baris yang gagal/),
    );
    expect((apply as HTMLButtonElement).disabled).toBe(false);
    await userEvent.click(apply);

    expect(
      await within(dialog).findByText(/Diterapkan: 2 ditambahkan/),
    ).toBeTruthy();
    const commit = calls.find(
      (c) => c.path === `master/imports/${JOB}/commit`,
    )!;
    expect(JSON.parse(String(commit.body))).toEqual({ skip_errors: true });
  });

  it("cancelling a preview discards the staged job", async () => {
    const calls = stub(["operations.view", "master.manage"]);
    open();
    await userEvent.click(
      await screen.findByRole("button", { name: "Impor Excel / CSV" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.upload(
      within(dialog).getByLabelText("Berkas impor"),
      new File(["x"], "pelanggan.csv"),
    );
    await within(dialog).findByText("Baru: 2");
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Batal" }),
    );

    await waitFor(() =>
      expect(
        calls.some((c) => c.path === `master/imports/${JOB}/discard`),
      ).toBe(true),
    );
  });
});
