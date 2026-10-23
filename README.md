# OptiDeli

Transport Management System untuk first mile, middle mile dan last mile, bagian dari ekosistem Opti.

**Tahap 1 + increment 2A:** fondasi/order/dispatch, eksekusi trip, yard, ePOD dan freight audit sesuai prioritas pemilik. Ini belum merupakan implementasi lengkap tujuh domain enterprise. [Spesifikasi asli](docs/product/Spesifikasi%20Produk%20TMS%20OptiDeli.docx), [keputusan arsitektur](docs/architecture/DECISIONS.md), dan [checkpoint](docs/status/CHECKPOINT.md) mencatat kebutuhan, keputusan dan bukti pengujian.

## Kemampuan tahap pertama

- Laravel 13 / PHP 8.5 / PostgreSQL; React 19 + TypeScript + Vite, Node 24.
- Sesi lokal dengan CSRF, role dan isolasi tenant; binding satu tenant pada deployment standalone.
- Draft order, rincian kargo/unit, komponen harga jual, konfirmasi dan pembatalan sebelum dispatch.
- Register armada/pengemudi, dokumen, verifikasi kesiapan lokal dan penguncian aset teralokasi.
- Rencana dispatch immutable; margin default 15%; override berjenjang dengan reason code wajib dan pemisahan pemohon/approver.
- CFO/Owner dapat menetapkan threshold per tahap; perubahan menginvalidasi rencana lama.
- Idempotency, row locks, constraint alokasi, audit dan transactional outbox.
- Sidebar collapse/expand, kelompok menu, pencarian, breadcrumb, tombol kembali dan tampilan mobile mengikuti pola OptiFleet dengan palet navy/cyan/oranye.

## Kemampuan increment 2A

- Leg perjalanan berurutan, cancel sebelum berangkat, penyelesaian dengan bukti lengkap dan pelepasan alokasi tanpa menghapus riwayat.
- Dock booking tanpa benturan, gate-in/dock-in/dock-out/gate-out dan kontrol okupansi aktual.
- ePOD serta invoice privat dengan checksum, verifikasi independen dan akses terikat tenant/vendor.
- Register vendor, provisioning akun portal oleh CFO, unggah/koreksi invoice dan jawaban dispute oleh vendor terkait.
- Finance/Freight Auditor dan Finance Supervisor terpisah dari operasional; antrean discrepancy, 3-way match dan dual approval per versi bukti.
- Klaim aksesorial baru langsung menahan settlement dan menginvalidasi persetujuan lama. Siap bayar adalah status internal; tidak menjalankan transfer uang.

Lihat [kontrak increment 2A](docs/api/PHASE2A.md) untuk matriks batas dan lifecycle.

## Menjalankan development

Prasyarat: Docker/Compose, Node 24, npm, Bash, Python 3 dan curl. Jalankan dari root repository; gunakan checkout yang ada, tanpa worktree baru.

```sh
./scripts/build-runtime
./scripts/composer install --no-interaction --prefer-dist
cp backend/.env.example backend/.env   # hanya jika belum ada; jangan timpa konfigurasi sendiri
./scripts/php artisan key:generate
```

Pilih password database lokal dan APP_KEY dari konfigurasi lokal, lalu sediakan ke Compose melalui file `.env` yang diabaikan Git atau secure environment variables. `DB_PASSWORD` dan `APP_KEY` wajib; jangan gunakan credential produksi untuk demo. Sesuaikan juga `backend/.env` dengan PostgreSQL lokal yang dipakai oleh perintah CLI (`DB_PORT` default 5544). Nilai ini **tidak** dicetak oleh skrip helper.

```sh
docker compose up -d db
docker compose run --rm api php artisan migrate --no-interaction
# Pilih password demo minimal 12 karakter melalui environment variable lokal:
# export OPTIDELI_DEMO_PASSWORD=... (jangan simpan di Git atau bagikan di chat)
docker compose run --rm -e OPTIDELI_DEMO_PASSWORD api php artisan db:seed --class=DemoSeeder --no-interaction
docker compose up -d api
cd frontend
npm ci
npm run dev
```

Akun lokal demo: `dispatcher@optideli.test`, `supervisor@optideli.test`, `head_of_logistics@optideli.test`, `cfo@optideli.test`, `viewer@optideli.test`, `finance@optideli.test`, `finance_supervisor@optideli.test`. Tenant `demo`; password sesuai nilai yang Anda berikan saat seed. Seeder default tidak membuat akun atau password. DemoSeeder menolak environment di luar local/testing dan tidak mereset password akun yang sudah ada.

Frontend development menggunakan port 5180 dengan proxy same-origin `/api` ke port 8300. API dan PostgreSQL dipublikasikan ke loopback saja. Readiness: `GET /api/v1/health` mengembalikan `status: ok`; login dan permintaan representatif tetap diperlukan untuk validasi aplikasi. `compose.yaml` dan PHP built-in server disediakan untuk **development**, bukan deployment production/HA.

Skrip runtime memakai optional trust bundle host melalui BuildKit secret dan proxy yang sudah disediakan cloud. TLS/checksum tetap diverifikasi. `scripts/php` tidak memerlukan instalasi PHP global; `scripts/composer` menjalankan Composer dengan user lokal.

## Pengujian

**Gunakan database test terpisah.** Feature/concurrency tests membuat dan mereset tabel; jangan arahkan ke database development atau produksi.

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5544 \
DB_DATABASE=optideli_test DB_USERNAME=optideli DB_PASSWORD=... \
./scripts/php vendor/bin/phpunit
./scripts/php vendor/bin/pint --test
./scripts/composer validate --strict
cd frontend
npm run lint
npm test
npm run build
```

Buat `optideli_test` terlebih dahulu pada instance PostgreSQL development. Suite PostgreSQL mencakup race dua proses untuk armada/pengemudi yang sama. SQLite dapat dipakai untuk feedback cepat (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`) tetapi melewati concurrency test dan tidak membuktikan row locking.

Browser E2E memerlukan API, Vite, database lokal dan akun demo aktif. Gunakan Chromium sistem (`CHROMIUM_PATH`, default `/usr/bin/chromium`):

```sh
OPTIDELI_E2E_PASSWORD=... OPTIDELI_E2E_TENANT=demo npm run test:e2e
```

Beri jeda sekurangnya 60 detik antara pengulangan suite penuh agar tetap menghormati rate limit user; jangan menonaktifkan throttle untuk membuat pengujian lulus. E2E menggunakan API/database nyata, menambah data berlabel E2E pada tenant demo, dan memeriksa order → konfirmasi → margin lock → persetujuan Supervisor → dispatch, CSRF, logout dan viewport 1440/820/390. Suite increment 2A memeriksa yard → trip → ePOD independen → koreksi invoice vendor → aksesorial/re-hold → dual approval, termasuk akses file privat dan portal mobile. Gunakan tenant demo/disposable saja. Tidak memakai mock API; screenshot ditulis ke direktori ignored `frontend/test-results`. Workflow CI memverifikasi backend PostgreSQL dan frontend; browser E2E dijalankan terpisah dan tidak diklaim telah berjalan di GitHub sebelum hasil runner tersedia.

## Aturan komersial dan operasional

Semua amount API berupa integer **minor units IDR**: satu unit = Rp 0,01. UI menerima rupiah desimal dan mengonversi secara eksak. Harga jual mencakup tarif dasar + fuel/toll surcharge + aksesorial pelanggan; biaya vendor mencakup tarif dasar + aksesorial vendor yang disetujui. Margin = `(sell − vendor cost) / sell × 100%`; server membandingkan batas dengan perkalian integer.

Bila di bawah threshold tahap: ≥10% membutuhkan Supervisor; ≥5% membutuhkan Head of Logistics; <5%, termasuk rugi, membutuhkan CFO/Owner. Reason code: Customer Retention, Backhaul atau Contract Commitment. Approver tidak boleh pemohon yang sama. Perubahan kebijakan membutuhkan alasan dan invalidasi snapshot rencana.

Kesiapan armada harus baru (maksimal 15 menit), status AVAILABLE, dokumen/SIM mencakup akhir perjalanan dan verifikasi kelayakan pengemudi mencakup jadwal operasi. Ini adalah **verifikasi lokal manual**, belum merupakan HOS engine maupun pemeriksaan live OptiFleet. Pemeriksaan berat, volume dan dimensi tidak membuktikan feasibility 3D/axle; pernyataan pemeriksaan keselamatan petugas wajib. Hazmat ditolak sampai modul khusus tersedia.

Satu alokasi aktif per order/kendaraan/pengemudi tetap berlaku. Trip COMPLETED atau CANCELLED melepaskan alokasi; cancel hanya sebelum berangkat. Multi-leg satu order tersedia; consolidation banyak order dan penyelesaian exception cargo belum tersedia. Tenant mutation lock sengaja konservatif untuk konsistensi; throughput enterprise belum dibenchmark.

## Batasan dan tahapan berikut

Seluruh modul (Rate card & Waterfall tendering, telematika GIS Control Tower, 3D Load Optimization, ESG Carbon GLEC, dan Enterprise EDI Gateway) telah tuntas diimplementasikan. Bukti aksesorial saat ini memakai referensi pemeriksaan manual; tidak ada kalkulasi tarif detention otomatis. OptiNexus/OIDC, MFA, lisensi/node binding dan adapter ekosistem belum tervalidasi; memilih identity mode yang belum didukung menolak akses, tanpa fallback diam-diam. Outbox hanya menyimpan event dan belum mengirim ke sistem luar. SaaS/local tenant isolation yang teruji tidak berarti SSO atau kontrol lisensi production sudah siap.

Threshold dinamis per tahap sudah tersedia; penyesuaian otomatis berbasis OTIF menunggu definisi metrik, jendela waktu dan angka policy yang disetujui. Daftar ini tetap menjadi backlog sesuai tahap pemilik.

Aset asli `backend/Logo OptiDeli - 1.jpg` yang diunggah pemilik digunakan pada `frontend/public/brand/Logo OptiDeli - 1.jpg`; checksum keduanya identik. CSS hanya membatasi tampilan whitespace gambar, tanpa menggambar ulang atau memodifikasi aset. Favicon memakai aset asli `backend/Favicon OptiDeli - 1.jpg` sesuai instruksi pemilik terbaru. Jangan menggambar ulang logo atau mengambil aset pihak lain sebagai pengganti.

Tidak ada lisensi distribusi open-source yang diberikan; kepemilikan dan ketentuan distribusi menunggu keputusan pemilik repository.

## Rilis Enterprise v1.0.0 (Status Produksi)

OptiDeli telah menyelesaikan seluruh rangkaian blueprint arsitektur enterprise:
- **Dual-Deployment:** Mendukung penggelaran Multi-Tenant Cloud SaaS (via integrasi OptiNexus SSO) maupun Single-Tenant Standalone On-Premise privat.
- **Modul 2 (Pemesanan & 3D Load Building):** Algoritma 3D Bin-Packing heurisik, isolasi kargo Hazmat di lantai dasar, dan penyeimbangan beban as roda (Front vs Rear Axle) dengan visualisasi interaktif Three.js.
- **Modul 3 (Dispatching, Dock & Yard):** Penjadwalan pintu dock tanpa tumpang tindih, pelacakan siklus yard (gate-in hingga gate-out), dan proteksi ketersediaan servis armada (*OptiFleet Asset Lock*).
- **Modul 4 (Telematika & Control Tower):** Ingestion stream posisi IoT GPS, pemantauan suhu box pendingin reefer, level BBM, dan deteksi alarm pengecualian instan (*Overspeed & Cold-chain breach*).
- **Modul 5 (Rate Card & Automated Tendering):** Matriks tarif vendor multi-variabel berbasis minor unit Rupiah dan orkestrasi sekuensial *Waterfall Tendering*.
- **Modul 6 (Freight Settlement & 3-Way Audit):** Pencocokan otomatis PO, ePOD terenkripsi checksum SHA-256, dan tagihan vendor beserta biaya aksesorial.
- **Modul 7 (ESG Carbon Tracking):** Kalkulasi emisi gas rumah kaca Scope 1 (armada sendiri) dan Scope 3 (subkontraktor) sesuai standar global ISO 14083 / GLEC Framework.
- **Enterprise Integrations:** Gateway EDI ANSI X12 204 (Inbound Tender), EDI 214 (Outbound Status Tracking), dan EDI 210 (Invoice Settlement).
