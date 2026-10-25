# Referensi wilayah (provinsi, kabupaten/kota)

`wilayah_kemendagri.csv` berisi 38 provinsi dan 514 kabupaten/kota dengan kode wilayah Kemendagri (`32` = Jawa Barat,
`32.73` = Kota Bandung), dimuat ke master data tenant lewat tombol "Muat data wilayah resmi" (`RegionalReference`). Hanya dua
tingkat teratas yang dibawa; kecamatan dan desa tidak diperlukan oleh OptiDeli.

* Sumber: <https://github.com/cahyadsn/wilayah> (`db/wilayah.sql`, ditulis "sesuai Kepmendagri No 300.2.2-2138 Tahun 2025",
  terakhir diubah 2026-02-13), diambil 2026-10-26. Spasi berlebih pada nama dirapikan; selebihnya tidak diubah.
* Lisensi sumber: MIT, copyright (c) 2025 cahya dsn. Pemberitahuan lisensi yang menyertai sumber:

  > Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation
  > files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy,
  > modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software
  > is furnished to do so, subject to the following conditions: The above copyright notice and this permission notice shall be
  > included in all copies or substantial portions of the Software. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY
  > KIND, EXPRESS OR IMPLIED.
* Memperbarui: ganti berkas CSV dengan hasil olahan versi terbaru sumber (format `kode,nama`), lalu muat ulang; kode yang sudah ada
  tidak diubah, kode baru ditambahkan.
