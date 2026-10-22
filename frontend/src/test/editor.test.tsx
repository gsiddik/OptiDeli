import { expect, it, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { RecordEditor } from "../RecordEditor";
it("serializes currency and evidence without converting the upload to JSON", async () => {
  const save = vi.fn().mockResolvedValue(undefined);
  const file = new File(["%PDF-1.4"], "invoice.pdf", {
    type: "application/pdf",
  });
  render(
    <RecordEditor
      fields={[
        { name: "base_minor", label: "Tarif dasar", kind: "money" },
        { name: "file", label: "Bukti", kind: "file" },
      ]}
      onSubmit={save}
    />,
  );
  fireEvent.change(screen.getByLabelText("Tarif dasar"), {
    target: { value: "1234,56" },
  });
  await userEvent.upload(screen.getByLabelText("Bukti"), file);
  fireEvent.submit(
    screen.getByRole("button", { name: "Simpan" }).closest("form")!,
  );
  await waitFor(() => expect(save).toHaveBeenCalledTimes(1));
  expect(save.mock.calls[0][0]).toEqual({ base_minor: 123456, file });
  expect(save.mock.calls[0][1]).toMatch(/^[a-f0-9-]{36}$/);
});
