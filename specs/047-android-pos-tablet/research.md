**Classification**: INTERNAL

# Research & Decisions — 047 Android POS (online-first)

**D1 — Arah: online-first (arahan pemilik).** Bangun terhadap API yang ada; hal yang belum ada ditandai dan dibuat sesudah fase ini. *Alternatif*: local-first penuh (desain) — ditolak sementara karena membutuhkan B16 (sinkron batch) dan DB lokal terenkripsi; ditunda, antarmuka repositori dibuat agar sumber data dapat diganti.

**D2 — Lokasi kode: `android-pos/` mandiri di akar repositori ini.** Alasan: pemilik akan memindahkannya ke repositori lain; mandiri berarti tanpa referensi keluar folder; spec/gap disalin ke `android-pos/docs/` saat implementasi. *Alternatif*: pakai `android/` (WebView 008) — ditolak: tujuannya berbeda, belum pernah dibangun, `applicationId` installer.

**D3 — Tanpa Room pada fase ini.** Server = sumber kebenaran; DataStore cukup untuk sesi/pengaturan. Room + outbox masuk bersama B16. *Alternatif*: Room sekarang — menambah risiko sinkron tanpa API pendukung.

**D4 — Pemisahan modul**: `core-domain` Kotlin-JVM (uji tanpa SDK), `core-network`, `core-printing`, `core-ui`, `app`. Alasan: lapisan desain (presentation → ViewModel/UiState → use case → domain → repository), uji domain cepat, kode printing dapat dipakai ulang.

**D5 — Jaringan**: Retrofit + OkHttp + kotlinx.serialization. Uang diterima sebagai string 2 desimal → `BigDecimal`/`Money` (tanpa float). Interceptor: `Authorization: Bearer`, `X-Outlet-Id`, `Accept: application/json`; pemetaan galat: 401 → logout, 403 `outlet_forbidden`, 422 galat per-bidang, 409 konflik bisnis (mis. `table_occupied`, stok), 423 instalasi belum diaktivasi, 503 `schema_outdated`. Pagination `{data, meta}`.

**D6 — Sinkron tampilan: polling berbasis versi.** `GET /floor?version=` (`unchanged`) ±3 dtk saat layar meja terlihat; `GET /menu/availability?pos=1&since=etag` ±5 dtk (304). Berhenti saat layar tak terlihat. Tanpa websocket (tak ada).

**D7 — Idempotensi**: `local_ref` (buat tab), `client_ref` (bayar, split, `POST /orders`) dibuat SEKALI per niat pengguna dan dipakai ulang pada percobaan ulang. Tambah/ubah baris dan `send` TIDAK idempoten di server → klien: satu permintaan sekaligus per tab (antrean serial), tombol nonaktif saat berjalan, dan setelah galat jaringan **muat ulang tab** sebelum mengizinkan ulang (jangan menebak). Diminta di B15.

**D8 — Autentikasi**: login username+kata sandi (`POST /auth/login`); token Sanctum disimpan terenkripsi (Keystore-wrapped); kunci layar idle 5 menit menyembunyikan UI dan meminta kata sandi/PIN (PIN saat B1). Tidak ada refresh. *Gap*: B1.

**D9 — Izin di klien hanya menyembunyikan**: baca `menu_keys` dan `permissions` dari login/`me`; `floor` → denah/tab; `pos`/`sales` → bayar; `settings` ≈ owner/admin (menyetujui sementara). Server tetap menolak (403).

**D10 — Pajak**: API tidak punya pajak; total = nilai server. Tidak menghitung pajak lokal (uang dihitung server). Tampilan pajak/struk pajak = "menunggu API" (B8); `Bayar` tidak diblokir karena tarif kosong pada fase ini (aturan desain "tarif hilang → nonaktif" ikut B8).

**D11 — Metode bayar**: API menerima `cash | bank_transfer | qr_ewallet` (+ `channel_id` dan bukti opsional). Dipetakan: Tunai → `cash`; QRIS → `qr_ewallet` (tanpa alur "tertunda→verifikasi" sampai B7; pembayaran non-tunai tercatat `pending` verifikasi di server dan dapat diverifikasi lewat `POST /orders/{id}/payments/{id}/verify` oleh pengguna yang berhak); EDC → belum ada metode (B6): untuk sementara dicatat sebagai `bank_transfer` ber-channel EDC **hanya bila** channel itu dibuat pemilik di Pengaturan; jika tidak, opsi EDC tampil "menunggu API". *Dicatat sebagai ASSUMPTION*.

**D12 — Cetak**: ESC/POS dibuat sendiri (renderer kecil: teks, rata, tebal, garis, potong), dikirim lewat soket TCP LAN port 9100 (printer Kasir & Dapur). Bluetooth/USB menunggu OQ-049. Antrean cetak lokal (coba ulang 3×, lalu tetap antre, penanda di bilah atas). Tanpa QR pada templat. Lebar 80 mm (58 mm templat kedua).

**D13 — Nomor struk/antrean/cetak ulang/pra-bill**: tidak ada di API (B12/B13). Sementara: nomor struk = `order_number` server; "SALINAN n" dihitung lokal per perangkat (tidak otoritatif); pra-bill = cetak lokal tanpa status meja.

**D14 — Tema**: token warna/tipografi/bentuk persis README desain (seed #EC3013, Roboto Flex atau Roboto sistem), tema terang/gelap mengikuti sistem (jadwal 18:00–06:00 = rekomendasi desain, bukan wajib). Warna sukses/peringatan khusus ditambahkan di luar skema bawaan.

**D15 — Adaptif**: `WindowSizeClass` (kompak <600, sedang 600–839, luas 840–1199, besar ≥1200) menentukan Rail/Bottom-nav, kolom grid (2/3/4/5), lebar panel pesanan (320/380/420dp) dan sheet (bawah vs samping).

**D16 — Pustaka & versi**: tidak ada versi dikarang; selain Kotlin 2.0.20 dan AGP 8.6.0 (sudah ada di `android/` repo ini), semua versi dipatok pada sinkron Gradle pertama dari rilis resmi terbaru yang stabil dan dicatat di `gradle/libs.versions.toml`.

**D17 — Verifikasi build**: tidak ada SDK/Gradle di mesin ini. Opsi (butuh persetujuan pemilik karena mengunduh perangkat lunak besar): pasang Android Studio atau `cmdline-tools` + Gradle. Sampai itu, status verifikasi dilaporkan jujur ("ditulis, belum dikompilasi").

**D18 — Bahasa**: seluruh teks UI Bahasa Indonesia (sesuai desain); string di `strings.xml`; komentar kode menjelaskan "mengapa".
