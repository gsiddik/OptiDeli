export interface VendorRateCard {
  id: string;
  tenant_id: string;
  vendor_id: string;
  vehicle_type: string;
  zone_origin: string;
  zone_destination: string;
  base_rate_minor_units: number;
  rate_per_km_minor_units: number;
  min_distance_km: number;
  is_active: boolean;
}

export type TenderOfferStatus =
  "PENDING" | "OFFERED" | "ACCEPTED" | "REJECTED" | "EXPIRED" | "BYPASSED";

export interface TenderOffer {
  id: string;
  vendor_id: string;
  sequence: number;
  offered_cost_minor_units: number;
  status: TenderOfferStatus;
  offered_at: string | null;
  expires_at: string | null;
  rejection_reason?: string | null;
}

export interface TenderDispatch {
  id: string;
  order_id: string;
  tendering_mode: "WATERFALL" | "BROADCAST";
  status: "RUNNING" | "ACCEPTED" | "EXHAUSTED" | "CANCELLED";
  current_sequence: number;
  timeout_minutes_per_vendor: number;
  accepted_vendor_id: string | null;
  winning_cost_minor_units: number | null;
  started_at: string;
  offers: TenderOffer[];
}

export const formatRupiahFromMinor = (minorUnits: number): string => {
  const rupiah = minorUnits / 100;
  return new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(rupiah);
};
