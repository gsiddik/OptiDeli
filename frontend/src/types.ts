export type Session = {
  id: number;
  name: string;
  role: string;
  permissions: string[];
  tenant: {
    name: string;
    slug: string;
    minimum_margin_bps: number;
    stage_margin_bps: Record<string, number>;
  };
  deployment_mode: string;
  identity_mode?: string;
};
export type Page<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};
export type Item = {
  id?: string;
  description: string;
  quantity: number;
  unit_weight_g: number;
  length_mm: number;
  width_mm: number;
  height_mm: number;
  stackable: boolean;
  hazmat_class?: string | null;
};
export type Order = {
  id: string;
  number: string;
  customer_name: string;
  reference?: string;
  stage: string;
  origin: string;
  destination: string;
  currency: string;
  base_price_minor: number;
  surcharge_minor: number;
  accessorial_minor: number;
  sell_minor: number;
  status: string;
  created_at: string;
  items?: Item[];
  plans?: Plan[];
};
export type Vehicle = {
  id: string;
  plate: string;
  type: string;
  status: string;
  capacity_g: number;
  length_mm: number;
  width_mm: number;
  height_mm: number;
  documents_valid_until: string;
  readiness_checked_at: string;
};
export type Driver = {
  id: string;
  name: string;
  license_number: string;
  license_valid_until: string;
  eligibility_verified_until: string;
  active: boolean;
};
export type Plan = {
  id: string;
  order_id: string;
  vehicle_id: string;
  driver_id: string;
  cost_minor: number;
  required_level: number;
  threshold_bps: number;
  requested_by: number;
  approved_by: number | null;
  status: string;
  starts_at: string;
  ends_at: string;
  reason_code: string | null;
  load_safety_attestation: string;
};
export type Fleet = {
  vehicles: Vehicle[];
  drivers: Driver[];
  allocations: { vehicle_id: string; driver_id: string }[];
};
export const stages: Record<string, string> = {
  FIRST_MILE: "First mile",
  MIDDLE_MILE: "Middle mile",
  LAST_MILE: "Last mile",
  E2E: "End-to-end",
};
export const roles: Record<string, string> = {
  dispatcher: "Dispatcher",
  supervisor: "Dispatch Supervisor",
  head_of_logistics: "Head of Logistics",
  cfo: "CFO / Owner",
  viewer: "Viewer",
  finance: "Finance / Freight Auditor",
  finance_supervisor: "Finance Supervisor",
  vendor: "Vendor",
};
export const levels = [
  "Otomatis",
  "Supervisor",
  "Head of Logistics",
  "CFO / Owner",
];
