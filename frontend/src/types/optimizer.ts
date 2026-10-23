export interface VehicleDimension {
  length_cm: number;
  width_cm: number;
  height_cm: number;
}

export interface AxleLimits {
  front_max_kg: number;
  rear_max_kg: number;
}

export interface VehicleSpec {
  type: string;
  max_weight_kg: number;
  container_dimensions: VehicleDimension;
  axle_limits: AxleLimits;
}

export interface CargoItemInput {
  id: string;
  name: string;
  length_cm: number;
  width_cm: number;
  height_cm: number;
  weight_kg: number;
  quantity: number;
  is_hazmat: boolean;
  stackable: boolean;
}

export interface PlacedCargoItem extends CargoItemInput {
  volume_m3: number;
  position: {
    x: number;
    y: number;
    z: number;
  };
}

export interface OptimizationResult {
  is_valid_dispatch: boolean;
  summary: {
    total_placed_items: number;
    total_unplaced_items: number;
    total_weight_kg: number;
    max_weight_kg: number;
    weight_utilization_pct: number;
    total_volume_m3: number;
    container_volume_m3: number;
    volume_utilization_pct: number;
  };
  axle_distribution: {
    front_axle_load_kg: number;
    front_max_kg: number;
    front_status: "OK" | "OVERLOAD";
    rear_axle_load_kg: number;
    rear_max_kg: number;
    rear_status: "OK" | "OVERLOAD";
  };
  container_dimensions: VehicleDimension;
  placed_items: PlacedCargoItem[];
  unplaced_items: CargoItemInput[];
}
