import type { Locator } from "@playwright/test";

/**
 * Chooses an option of a searchable dropdown: open it, then click the row whose value (or, with `{ label }`, whose text) matches.
 * The dropdowns of OptiDeli can be searched by typing, so they are not native selects and `selectOption` does not apply.
 */
export async function pick(
  dropdown: Locator,
  option: string | { label: string },
): Promise<void> {
  await dropdown.click();
  const list = dropdown
    .page()
    .locator(`[id="${await dropdown.getAttribute("aria-controls")}"]`);
  const row =
    typeof option === "string"
      ? list.locator(`[role="option"][data-value="${option}"]`)
      : list.getByRole("option", { name: option.label, exact: true });
  await row.click();
}
