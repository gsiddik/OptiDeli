import React, { useEffect, useRef, useState } from "react";
import { api } from "../../api";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import type { LiveVehicleState } from "../../types/telematics";

export const GISControlTowerMap: React.FC = () => {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<L.Map | null>(null);
  const markersRef = useRef<{ [key: string]: L.CircleMarker }>({});

  const [fleet, setFleet] = useState<LiveVehicleState[]>([]);
  const [selectedVehicle, setSelectedVehicle] =
    useState<LiveVehicleState | null>(null);

  const fetchLiveFleet = async () => {
    try {
      const data = await api<{ data: LiveVehicleState[] }>("telematics/live");
      setFleet(data.data || []);
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    fetchLiveFleet();
    const interval = setInterval(fetchLiveFleet, 8000);
    return () => clearInterval(interval);
  }, []);

  // Inisialisasi Peta Leaflet
  useEffect(() => {
    if (!mapContainerRef.current || mapInstanceRef.current) return;

    // Titik pusat pulau Jawa / Jakarta
    const map = L.map(mapContainerRef.current).setView([-6.2088, 106.8456], 8);

    L.tileLayer(
      "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png",
      {
        attribution: "&copy; OpenStreetMap contributors &copy; CARTO",
        maxZoom: 19,
      },
    ).addTo(map);

    mapInstanceRef.current = map;

    return () => {
      map.remove();
      mapInstanceRef.current = null;
    };
  }, []);

  // Render Marker Armada
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map) return;

    fleet.forEach((v) => {
      const color =
        v.status === "EXCEPTION"
          ? "#f43f5e"
          : v.status === "EN_ROUTE"
            ? "#06b6d4"
            : "#94a3b8";

      if (markersRef.current[v.vehicle_id]) {
        markersRef.current[v.vehicle_id].setLatLng([v.latitude, v.longitude]);
        markersRef.current[v.vehicle_id].setStyle({ color, fillColor: color });
      } else {
        const marker = L.circleMarker([v.latitude, v.longitude], {
          radius: 9,
          fillColor: color,
          color: "#ffffff",
          weight: 2,
          opacity: 1,
          fillOpacity: 0.9,
        }).addTo(map);

        marker.on("click", () => {
          setSelectedVehicle(v);
        });

        markersRef.current[v.vehicle_id] = marker;
      }
    });
  }, [fleet]);

  const handleResolveAlert = async (alertId: string) => {
    await api<unknown>(`telematics/alerts/${alertId}/resolve`, "POST");
    fetchLiveFleet();
    if (selectedVehicle) {
      setSelectedVehicle((prev) =>
        prev
          ? {
              ...prev,
              active_alerts: prev.active_alerts.filter((a) => a.id !== alertId),
            }
          : null,
      );
    }
  };

  return (
    <div className="relative flex h-[620px] w-full overflow-hidden rounded-xl border border-slate-800 bg-slate-900 shadow-2xl">
      {/* Viewport Peta */}
      <div ref={mapContainerRef} className="h-full flex-1 z-0" />

      {/* Header Overlay */}
      <div className="absolute top-4 left-4 z-10 rounded-lg border border-slate-800 bg-slate-950/90 p-3.5 backdrop-blur">
        <h2 className="text-base font-bold text-cyan-400">
          GIS Control Tower & IoT Telematics
        </h2>
        <p className="text-xs text-slate-400">
          Monitoring posisi GPS, BBM, pintu box, dan suhu dingin reefer.
        </p>
        <div className="mt-2 flex gap-3 text-[11px]">
          <span className="flex items-center gap-1 text-cyan-400">
            <span className="h-2 w-2 rounded-full bg-cyan-400" /> Melaju
          </span>
          <span className="flex items-center gap-1 text-rose-400">
            <span className="h-2 w-2 rounded-full bg-rose-500 animate-pulse" />{" "}
            Pengecualian / Insiden
          </span>
          <span className="flex items-center gap-1 text-slate-400">
            <span className="h-2 w-2 rounded-full bg-slate-400" /> Berhenti
          </span>
        </div>
      </div>

      {/* Telematics Diagnostics Drawer (Panel Samping) */}
      {selectedVehicle && (
        <div className="absolute top-0 right-0 z-20 h-full w-80 border-l border-slate-800 bg-slate-950/95 p-5 text-slate-200 backdrop-blur shadow-2xl flex flex-col justify-between">
          <div className="space-y-5">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <p className="font-mono text-base font-bold text-cyan-400">
                  {selectedVehicle.vehicle_id}
                </p>
                <p className="text-xs text-slate-400">
                  Trip: {selectedVehicle.trip_id}
                </p>
              </div>
              <button
                onClick={() => setSelectedVehicle(null)}
                className="rounded p-1 text-slate-400 hover:bg-slate-800 hover:text-white"
              >
                ✕
              </button>
            </div>

            {/* Gauge Diagnostik */}
            <div className="grid grid-cols-2 gap-3 text-xs">
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Kecepatan</span>
                <p className="mt-1 font-mono text-lg font-bold text-slate-100">
                  {selectedVehicle.speed_kmh} km/jam
                </p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Tangki BBM</span>
                <p className="mt-1 font-mono text-lg font-bold text-emerald-400">
                  {selectedVehicle.fuel_level_pct}%
                </p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Suhu Reefer</span>
                <p
                  className={`mt-1 font-mono text-lg font-bold ${
                    selectedVehicle.reefer_temp_celsius &&
                    selectedVehicle.reefer_temp_celsius > -15
                      ? "text-rose-400"
                      : "text-cyan-400"
                  }`}
                >
                  {selectedVehicle.reefer_temp_celsius !== null
                    ? `${selectedVehicle.reefer_temp_celsius}°C`
                    : "Non-Reefer"}
                </p>
              </div>
              <div className="rounded-lg border border-slate-800 bg-slate-900 p-3">
                <span className="text-slate-400">Status Pintu</span>
                <p className="mt-1 font-bold text-slate-200">
                  {selectedVehicle.is_door_open ? (
                    <span className="text-rose-400">Terbuka</span>
                  ) : (
                    <span className="text-emerald-400">Terkunci</span>
                  )}
                </p>
              </div>
            </div>

            {/* Exception Alerts Container */}
            <div>
              <p className="text-xs font-semibold text-slate-300 mb-2">
                Peringatan Aktif
              </p>
              {selectedVehicle.active_alerts.length === 0 ? (
                <p className="text-xs text-slate-500 italic">
                  Tidak ada insiden aktif.
                </p>
              ) : (
                <div className="space-y-2">
                  {selectedVehicle.active_alerts.map((alert) => (
                    <div
                      key={alert.id}
                      className="rounded-lg border border-rose-500/40 bg-rose-950/30 p-2.5 text-xs space-y-1.5"
                    >
                      <div className="flex justify-between items-center">
                        <span className="font-bold text-rose-400">
                          {alert.alert_type}
                        </span>
                        <span className="text-[10px] bg-rose-900/60 text-rose-300 px-1.5 py-0.2 rounded">
                          {alert.severity}
                        </span>
                      </div>
                      <p className="text-slate-300 text-[11px]">
                        {alert.message}
                      </p>
                      <button
                        onClick={() => handleResolveAlert(alert.id)}
                        className="w-full rounded bg-rose-600/80 hover:bg-rose-600 py-1 text-white text-[11px] font-medium transition"
                      >
                        Selesaikan Masalah
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          <div className="text-[10px] text-slate-500 border-t border-slate-800 pt-2">
            Pembaruan terakhir:{" "}
            {new Date(selectedVehicle.recorded_at).toLocaleTimeString("id-ID")}
          </div>
        </div>
      )}
    </div>
  );
};
