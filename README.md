# OptiDeli

Transport Management System untuk first mile, middle mile dan last mile, bagian dari ekosistem Opti.

**Tahap 1:** fondasi, order dan dispatch sesuai prioritas yang disetujui pemilik. Ini belum merupakan implementasi lengkap tujuh domain enterprise. [Spesifikasi asli](docs/product/Spesifikasi%20Produk%20TMS%20OptiDeli.docx), [keputusan arsitektur](docs/architecture/DECISIONS.md), dan [checkpoint](docs/status/CHECKPOINT.md) mencatat kebutuhan, keputusan dan bukti pengujian.

## Kemampuan tahap pertama

- Laravel 13 / PHP 8.5 / PostgreSQL; React 19 + TypeScript + Vite, Node 24.
- Sesi lokal dengan CSRF, role dan isolasi tenant; binding satu tenant pada deployment standalone.
- Draft order, rincian kargo/unit, komponen harga jual, konfirmasi dan pembatalan sebelum dispatch.
- Register armada/pengemudi, dokumen, verifikasi kesiapan lokal dan penguncian aset teralokasi.
- Rencana dispatch immutable; margin default 15%; override berjenjang dengan reason code wajib dan pemisahan pemohon/approver.
- CFO/Owner dapat menetapkan threshold per tahap; perubahan menginvalidasi rencana lama.
- Idempotency, row locks, constraint alokasi, audit dan transactional outbox.
- Sidebar collapse/expand, kelompok menu, pencarian, breadcrumb, tombol kembali dan tampilan mobile mengikuti pola OptiFleet dengan palet navy/cyan/oranye.

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

Akun lokal demo: `dispatcher@optideli.test`, `supervisor@optideli.test`, `head_of_logistics@optideli.test`, `cfo@optideli.test`, `viewer@optideli.test`. Tenant `demo`; password sesuai nilai yang Anda berikan saat seed. Seeder default tidak membuat akun atau password. DemoSeeder menolak environment di luar local/testing dan tidak mereset password akun yang sudah ada.

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

E2E menggunakan API/database nyata, menambah data berlabel E2E pada tenant demo, dan memeriksa order → konfirmasi → margin lock → persetujuan Supervisor → dispatch, CSRF, logout dan viewport 1440/820/390. Gunakan tenant demo/disposable saja. Tidak memakai mock API; screenshot ditulis ke direktori ignored `frontend/test-results`. Workflow CI memverifikasi backend PostgreSQL dan frontend; browser E2E dijalankan terpisah dan tidak diklaim telah berjalan di GitHub sebelum hasil runner tersedia.

## Aturan komersial dan operasional

Semua amount API berupa integer **minor units IDR**: satu unit = Rp 0,01. UI menerima rupiah desimal dan mengonversi secara eksak. Harga jual mencakup tarif dasar + fuel/toll surcharge + aksesorial pelanggan; biaya vendor mencakup tarif dasar + aksesorial vendor yang disetujui. Margin = `(sell − vendor cost) / sell × 100%`; server membandingkan batas dengan perkalian integer.

Bila di bawah threshold tahap: ≥10% membutuhkan Supervisor; ≥5% membutuhkan Head of Logistics; <5%, termasuk rugi, membutuhkan CFO/Owner. Reason code: Customer Retention, Backhaul atau Contract Commitment. Approver tidak boleh pemohon yang sama. Perubahan kebijakan membutuhkan alasan dan invalidasi snapshot rencana.

Kesiapan armada harus baru (maksimal 15 menit), status AVAILABLE, dokumen/SIM mencakup akhir perjalanan dan verifikasi kelayakan pengemudi mencakup jadwal operasi. Ini adalah **verifikasi lokal manual**, belum merupakan HOS engine maupun pemeriksaan live OptiFleet. Pemeriksaan berat, volume dan dimensi tidak membuktikan feasibility 3D/axle; pernyataan pemeriksaan keselamatan petugas wajib. Hazmat ditolak sampai modul khusus tersedia.

CP1 memakai satu alokasi aktif per order/kendaraan/pengemudi. Penyelesaian trip, pelepasan alokasi, penjadwalan ulang dan multi-leg/consolidation mengikuti tahap berikutnya; aset yang sudah dispatch belum dapat didaur ulang melalui UI tahap ini. Tenant mutation lock sengaja konservatif untuk konsistensi; throughput enterprise belum dibenchmark.

## Batasan dan tahapan berikut

Dock/yard, tender/vendor/rate card, ePOD, aksesorial pasca-dispatch/payment hold, freight audit, telematika, 3D optimization, ESG dan EDI belum diimplementasikan. OptiNexus/OIDC, MFA, lisensi/node binding dan adapter ekosistem belum tervalidasi; memilih identity mode yang belum didukung menolak akses, tanpa fallback diam-diam. Outbox hanya menyimpan event dan belum mengirim ke sistem luar. SaaS/local tenant isolation yang teruji tidak berarti SSO atau kontrol lisensi production sudah siap.

Threshold dinamis per tahap sudah tersedia; penyesuaian otomatis berbasis OTIF menunggu definisi metrik, jendela waktu dan angka policy yang disetujui. Daftar ini tetap menjadi backlog sesuai tahap pemilik.

Aset asli `backend/Logo OptiDeli - 1.jpg` yang diunggah pemilik digunakan pada `frontend/public/brand/Logo OptiDeli - 1.jpg`; checksum keduanya identik. CSS hanya membatasi tampilan whitespace gambar, tanpa menggambar ulang atau memodifikasi aset. Favicon sengaja tidak disediakan. Jangan menggambar ulang logo atau mengambil aset pihak lain sebagai pengganti.

Tidak ada lisensi distribusi open-source yang diberikan; kepemilikan dan ketentuan distribusi menunggu keputusan pemilik repository.
