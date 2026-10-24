# OptiRadar di dalam OptiDeli (K5)

OptiDeli menampilkan peta OptiRadar lewat iFrame. OptiDeli **tidak** menyimpan kredensial Radar.

1. Browser memanggil `POST /api/v1/telematics/radar-embed` (sesi + tenant; hanya baca, di luar grup WriteAccess).
2. OptiDeli meminta OptiNexus `POST /api/v1/embed/sessions` (`tenant_id` = `tenants.optinexus_tenant_id`, `target=optiradar`, TTL 600 dtk) dengan scope service account `embed.create`.
3. OptiNexus memeriksa langganan OptiRadar tenant, meminta token read-only singkat ke OptiRadar, dan mengembalikan URL `https://<radar>/?embed=1&token=...`.
4. Halaman `/radar` memuat URL itu di iframe dan memperbarui sesi sebelum kedaluwarsa.

Galat: 409 `RADAR_EMBED_NOT_AVAILABLE` (tenant belum terhubung ke OptiNexus / service account belum diset), 403 `RADAR_EMBED_NOT_ENTITLED`, 503 `RADAR_EMBED_UNAVAILABLE`.

Operasional: beri service account OptiDeli scope `embed.create` secara eksplisit di OptiNexus; atur `web.frameAncestors` di OptiRadar ke origin OptiDeli.
