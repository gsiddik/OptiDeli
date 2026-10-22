export type Trip = {
  id: string;
  order_id: string;
  plan_id: string;
  vehicle_id: string;
  driver_id: string;
  vendor_id: string | null;
  contract_reference: string | null;
  status: string;
  active: boolean;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  order?: {
    number: string;
    origin: string;
    destination: string;
    sell_minor: number;
  };
  plan?: {
    vendor_base_minor: number;
    vendor_accessorial_minor: number;
    cost_minor: number;
  };
  legs?: Leg[];
  pods?: Pod[];
  claims?: Claim[];
};
export type Leg = {
  id: string;
  sequence: number;
  origin: string;
  destination: string;
  planned_eta: string;
  status: string;
  started_at: string | null;
  arrived_at: string | null;
};
export type Pod = {
  id: string;
  trip_id: string;
  receiver_name: string;
  outcome: string;
  delivered_units: number;
  status: string;
  submitted_by: number;
};
export type Claim = {
  id: string;
  trip_id: string;
  category: string;
  amount_minor: number;
  reason: string;
  evidence_reference: string;
  status: string;
  submitted_by: number;
};
export type Vendor = {
  id: string;
  name: string;
  code: string;
  active: boolean;
};
export type Dock = { id: string; name: string; hub: string };
export type Appointment = {
  id: string;
  trip_id: string;
  dock_id: string;
  starts_at: string;
  ends_at: string;
  status: string;
  gate_in_at: string | null;
  dock_in_at: string | null;
  dock_out_at: string | null;
  gate_out_at: string | null;
};
export type Dispute = {
  id: string;
  invoice_id: string;
  message: string;
  status: string;
  vendor_response: string | null;
  resolution: string | null;
  system_key: string | null;
};
export type Invoice = {
  id: string;
  trip_id: string;
  vendor_id: string;
  number: string;
  base_minor: number;
  accessorial_minor: number;
  rounding_minor: number;
  total_minor: number;
  status: string;
  hold_reason: string;
  approval_tier: string;
  version: number;
  submitted_by: number;
  trip?: Trip;
  plan?: { vendor_base_minor: number; vendor_accessorial_minor: number };
  claims?: Claim[];
  disputes?: Dispute[];
  approvals?: { authority: string; actor_id: number; note: string }[];
};
export const canOperate = (role: string) =>
  ["dispatcher", "supervisor", "head_of_logistics", "cfo"].includes(role);
export const canFinance = (role: string) =>
  ["finance", "finance_supervisor", "cfo"].includes(role);
export const canVerify = (role: string) =>
  ["supervisor", "head_of_logistics", "cfo"].includes(role);
