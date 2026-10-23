export interface CarbonEmissionRecord {
  id: string;
  trip_id: string;
  vehicle_id: string;
  fleet_ownership: "INTERNAL" | "VENDOR";
  fuel_type: "DIESEL_B35" | "DIESEL_EURO4" | "CNG" | "EV";
  distance_km: number;
  cargo_weight_ton: number;
  ton_km: number;
  emission_factor_g_per_ton_km: number;
  total_co2e_kg: number;
  scope: "SCOPE_1" | "SCOPE_3";
  recorded_at: string;
}

export interface EsgSummary {
  total_emissions_ton_co2e: number;
  total_emissions_kg_co2e: number;
  total_ton_km: number;
  average_intensity_g_per_ton_km: number;
  scope_breakdown: {
    scope_1_kg: number;
    scope_1_pct: number;
    scope_3_kg: number;
    scope_3_pct: number;
  };
  total_trips_logged: number;
}
