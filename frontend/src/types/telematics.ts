export interface TelemetryAlert {
  id: string;
  trip_id: string;
  vehicle_id: string;
  alert_type: "OVERSPEED" | "REEFER_TEMP_BREACH" | "DOOR_OPEN_ENROUTE";
  severity: "WARNING" | "CRITICAL";
  message: string;
  metric_value: number;
  threshold_value: number;
  is_resolved: boolean;
  triggered_at: string;
}

export interface LiveVehicleState {
  vehicle_id: string;
  trip_id: string;
  latitude: number;
  longitude: number;
  speed_kmh: number;
  fuel_level_pct: number;
  reefer_temp_celsius: number | null;
  is_door_open: boolean;
  recorded_at: string;
  status: "EN_ROUTE" | "IDLE" | "EXCEPTION";
  active_alerts: TelemetryAlert[];
}
