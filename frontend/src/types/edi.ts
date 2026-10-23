export interface EdiTransmission {
  id: string;
  tenant_id: string;
  transaction_set: "204" | "214" | "210";
  direction: "INBOUND" | "OUTBOUND";
  reference_id: string;
  raw_content: string;
  parsed_payload: Record<string, unknown> | null;
  status: "PROCESSED" | "TRANSMITTED" | "FAILED";
  transmitted_at: string;
}
