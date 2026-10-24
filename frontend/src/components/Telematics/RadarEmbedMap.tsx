import React, { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "../../api";

interface EmbedSession {
  url: string;
  expires_at: string | null;
}

/**
 * Peta OptiRadar di dalam OptiDeli. OptiDeli tidak menyimpan kredensial Radar: OptiNexus menerbitkan sesi embed
 * singkat (read-only) dan browser hanya menerima URL-nya. Sesi diperbarui sebelum kedaluwarsa.
 */
export const RadarEmbedMap: React.FC = () => {
  const [session, setSession] = useState<EmbedSession | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await api<{ data: EmbedSession }>(
        "telematics/radar-embed",
        "POST",
        {},
      );
      setSession(res.data);
      setError(null);
    } catch (e) {
      setSession(null);
      setError(
        e instanceof ApiError
          ? e.message
          : "Peta OptiRadar tidak dapat dimuat.",
      );
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  // Sesi berumur pendek: minta yang baru 60 detik sebelum habis. URL baru memuat ulang peta, jadi hanya saat perlu.
  useEffect(() => {
    if (!session?.expires_at) return;
    const wait = Math.max(
      30_000,
      new Date(session.expires_at).getTime() - Date.now() - 60_000,
    );
    const timer = setTimeout(load, wait);
    return () => clearTimeout(timer);
  }, [session, load]);

  if (error) {
    return (
      <div className="card" role="alert">
        <h2>Peta OptiRadar</h2>
        <p>{error}</p>
        <button type="button" onClick={load}>
          Coba lagi
        </button>
      </div>
    );
  }

  if (!session) return <p>Memuat peta OptiRadar...</p>;

  return (
    <iframe
      title="OptiRadar"
      src={session.url}
      style={{ width: "100%", height: "calc(100vh - 140px)", border: 0 }}
      referrerPolicy="no-referrer"
      allow="fullscreen"
    />
  );
};
