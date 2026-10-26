import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

afterEach(() => vi.unstubAllGlobals());

const ID = "11111111-1111-4111-8111-111111111111";

function stub(gate: { open: boolean; blockers: string[] }) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string) => {
      const path = url.replace(/^.*\/api\/v1\//, "");
      const json = (data: unknown) =>
        new Response(JSON.stringify(data), { status: 200 });
      if (path === "csrf") return json({ token: "t" });
      if (path.startsWith("auth/me"))
        return json({
          data: {
            id: 1,
            name: "CFO",
            role: "cfo",
            permissions: [
              "operations.view",
              "cutover.manage",
              "cutover.approve",
            ],
            tenant: { name: "Tenant A", slug: "a", minimum_margin_bps: 1500 },
            deployment_mode: "saas",
          },
        });
      if (path === `cutover/${ID}`)
        return json({
          data: {
            cutover: {
              id: ID,
              code: "CUT-A",
              name: "Cut-over PT Maju",
              status: "PARALLEL",
              legacy_system: "Sistem Lama A",
              customer_label: "PT Maju",
              project_label: null,
              tolerance_minor: 0,
              legacy_frozen_at: null,
              checks_done: 0,
              checks_total: 1,
            },
            checks: [
              {
                code: "MASTER_LOADED",
                label: "Master data termuat",
                done: false,
                done_by_name: null,
                note: null,
              },
            ],
            reconciliation: [
              {
                period: "2026-09",
                status: "DIFFERENT",
                deli: {
                  spm_count: 2,
                  sell_minor: 300000000,
                  buy_minor: 200000000,
                },
                legacy: {
                  spm_count: 3,
                  sell_minor: 300000000,
                  buy_minor: 200000000,
                  source_note: null,
                },
                diff: { spm_count: -1, sell_minor: 0, buy_minor: 0 },
                accept_note: null,
              },
            ],
            gate,
          },
        });
      return json({ data: [] });
    }),
  );
}

const open = () =>
  render(
    <MemoryRouter initialEntries={[`/cutover/${ID}`]}>
      <App />
    </MemoryRouter>,
  );

describe("Cutover", () => {
  it("keeps the gate shut while something blocks it and offers to accept a difference", async () => {
    stub({
      open: false,
      blockers: ["Selisih bulan 2026-09 belum dijelaskan."],
    });
    open();
    expect(
      await screen.findByText("Selisih bulan 2026-09 belum dijelaskan."),
    ).toBeTruthy();
    const approve = screen.getByRole("button", { name: "Setujui cut-over" });
    expect((approve as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByRole("button", { name: "Terima selisih" })).toBeTruthy();
  });

  it("lets the approver pass the gate once it is open", async () => {
    stub({ open: true, blockers: [] });
    open();
    const approve = await screen.findByRole("button", {
      name: "Setujui cut-over",
    });
    expect((approve as HTMLButtonElement).disabled).toBe(false);
    expect(screen.getByText("Semua syarat terpenuhi.")).toBeTruthy();
  });
});
