# Implementation Plan: Pesan Mandiri via QR Meja, QR per Meja, dan Penanda Dine-In / Take Away

**Klasifikasi**: INTERNAL

**Branch**: `090-self-service-qr-ordering` | **Date**: 2026-11-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/090-self-service-qr-ordering/spec.md`

## Summary

Pelanggan memindai QR meja dan memesan sendiri lewat halaman publik mobile-first (menu seperti POS, tanpa diskon, hanya QRIS dengan foto bukti). Kiriman **ditahan** di entitas baru `self_service_orders` (keputusan pemilik: opsi B). Staf memeriksa di tab **Self-Service** pada halaman Penjualan web, memverifikasi pembayaran, lalu **Kirim ke dapur**: saat itulah order nyata, stok, pembayaran terverifikasi, dan tiket dapur dibuat **lewat layanan yang sudah ada** (`TabService`, `KitchenService`, `TabSettlementService`, `PaymentRecorder`), tanpa jalur tulis baru. Pesanan membawa penanda permanen `orders.source = self_service`. Tambahan: QR per meja (ditandatangani, bisa dicabut) + filter/paginasi/QR massal di Restaurant Setup, pilihan Dine-In/Take Away di POS web dan Android, dan notifikasi suara "Pesanan dari meja X" di Android lewat polling 5 detik dan antrean ucapan di atas mesin TTS yang sudah ada. Dasar keputusan: [research.md](./research.md).

## Technical Context

**Language/Version**: PHP / Laravel (versi persis mengikuti `composer.json`; tidak diubah); JavaScript Vue 3 + Vite + Tailwind v4 (CSS-first) + Pinia; Kotlin + Jetpack Compose (`android-pos/`).

**Primary Dependencies**: yang sudah ada. **Baru: satu paket npm `qrcode`** untuk gambar QR di klien (versi **unverified**, dikunci saat implementasi; diimpor dinamis). `html2canvas`/pembuat PDF yang sudah dipakai `utils/pdfCapture.js` dipakai ulang untuk struk dan lembar QR. Tidak ada dependensi Composer atau Gradle baru.

**Storage**: MySQL 8 (wajib; SQLite ditolak). Tabel baru `self_service_orders`; kolom baru `orders.source`, `orders.self_service_order_id`, `dining_tables.qr_nonce`; pengaturan `self_service_base_url`; kunci menu `self_service`. Foto bukti di disk privat `local` (`payment_proofs`).

**Testing**: PHPUnit (`tests/Feature/`, MySQL `warkop_wjm_test`, flag aman kontainer), Vitest (`qa-tests/`), JUnit Android. Verifikasi peramban ponsel nyata dan tablet dilakukan manusia (Konstitusi II) dan dicatat di `evidence.md`.

**Target Platform**: server Laravel tunggal (laptop toko atau server bersama); peramban ponsel pelanggan (Chrome/Safari terkini, lebar ≥ 360 px); tablet Android POS yang ada.

**Project Type**: web (Laravel API + Vue SPA) + aplikasi mobile Android terpisah.

**Performance Goals**: menu pertama terlihat ≤ 3 dtk (SC-003); pengumuman Android ≤ 10 dtk (SC-012) lewat polling 5 dtk; daftar meja berpaginasi (≤ 100/halaman).

**Constraints**: rute publik tanpa token (pengecualian Konstitusi IV, dicatat); alamat QR harus bukan `localhost`; harga/total selalu dihitung server; tanpa cache menu pada MVP; polling Android hanya saat aplikasi di depan.

**Scale/Scope**: satu organisasi, beberapa outlet, puluhan meja per outlet, ratusan pesanan mandiri per hari per outlet; ±10 tablet per outlet.

## Constitution Check

*GATE: lulus sebelum riset. Diperiksa ulang setelah desain (di bawah).*

| Prinsip | Penilaian | Bukti / catatan |
|---|---|---|
| I. Kode bersih, satu jalur tulis | **Lulus** | Entitas baru hanya ditulis `SelfServiceOrderService`. Stok, pembayaran, tiket dapur, penjualan memakai `StockService`, `PaymentRecorder`, `KitchenService`, `TabService`, `TabSettlementService` — tidak ada jalur kedua (research D3). Aturan "siapa boleh verifikasi QRIS" diekstrak jadi satu metode, bukan disalin (D4). Pajak lewat `TaxCalculator`; token QR satu kelas. |
| II. Standar pengujian | **Lulus bersyarat** | Tes backend di MySQL + Vitest + JUnit direncanakan (D21). Verifikasi peramban ponsel & tablet **belum** terjadi; dijadwalkan di quickstart dan harus dilakukan manusia sebelum "selesai". |
| III. Konsistensi UX | **Lulus** | Token desain `@theme`, tanpa hex mentah; UI Indonesia; galat 422/409/403 memakai penanganan pusat; tab Self-Service dan tombol Restaurant Setup disembunyikan penuh bagi peran tanpa menu `self_service`/`dining_setup`. Halaman publik di luar `AppShell` tetap memakai token yang sama. |
| IV. Keamanan | **Pelanggaran terkendali → Complexity Tracking** | Rute publik tanpa token adalah pengecualian baru (preseden 073). Mitigasi: token QR HMAC + nonce + mode; outlet dari token, tidak pernah dari body; daftar putih field; pembatas bernama; bukti bayar di disk privat dan hanya lewat jalur berotorisasi; uang dihitung server; potret order tak berubah; log audit dalam transaksi. |
| V. Performa | **Lulus** | Eager-load pada daftar/menu; paginasi **opt-in** pada `GET /dining-tables` agar bentuk lama tak berubah; `qrcode` diimpor dinamis dan diperiksa di keluaran build; tidak ada cache hingga SC-003 terukur (bila ditambah: kunci mode + outlet, dengan waktu hitung). |
| Lingkungan (MySQL 8, satu server) | **Lulus** | Berjalan di bentuk (a) dan (b); satu server/satu database. |
| Disiplin dokumen | **Lulus bersyarat** | `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama dengan rute; catatan bertanggal ditambah ke PRD (kapabilitas baru) dan ke CLAUDE.md (baris fitur 090). |

## Project Structure

### Documentation (this feature)

```text
specs/090-self-service-qr-ordering/
├── plan.md              # File ini
├── research.md          # Keputusan D1–D21 + 3 pertanyaan terbuka
├── data-model.md        # Entitas, status, kolom baru
├── quickstart.md        # Skenario verifikasi A–G
├── contracts/
│   └── self-service-api.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks (BUKAN keluaran perintah ini)
```

### Source Code (repository root)

```text
app/
├── Models/SelfServiceOrder.php                      # HasDataMode, BelongsToOutlet; daftar di OutletScopeRegistry
├── Services/SelfServiceOrderService.php             # submit / verify / reject / dispatch (satu-satunya penulis entitas)
├── Support/
│   ├── SelfServiceQrToken.php                       # tanda tangan + verifikasi token QR
│   └── SelfServiceSettings.php                      # pembaca self_service_base_url
├── Http/Controllers/Api/
│   ├── SelfServicePublicController.php              # context, menu, proofs, orders, receipts (publik)
│   ├── SelfServiceOrderController.php               # daftar/detail/verify/reject/dispatch/alerts (staf)
│   ├── DiningTableController.php                    # + paginasi/filter opt-in, qr-batch, qr/rotate
│   ├── OrderController.php, TabController.php       # + service_type
│   └── PaymentProofController.php                   # + akses staf self_service pada file bukti
├── Http/Requests/                                   # SubmitSelfServiceOrderRequest, Verify…, Reject…, QrBatchRequest
├── Http/Resources/SelfServiceOrderResource.php      # dan resource struk publik (daftar putih)
├── Console/Commands/PurgeOrphanSelfServiceProofs.php
└── Providers/AppServiceProvider.php                 # pembatas bernama baru

database/migrations/                                 # self_service_orders; orders.source/self_service_order_id;
                                                     # dining_tables.qr_nonce (isi acak baris lama); menu key self_service
routes/api.php                                       # grup publik + grup staf
docs/openapi-pos-mvp.yaml                            # diperbarui bersama rute
lang/id/self_service.php (+ pasangan bahasa lain bila proyek mewajibkan)

resources/js/
├── router/index.js                                  # rute publik /pesan/:token dan /pesan/struk/:receiptToken (di luar AppShell)
├── views/SelfOrderView.vue, SelfOrderReceiptView.vue
├── components/selfservice/                          # kartu menu mobile, keranjang, pembayaran QRIS, bukti, data diri,
│                                                    # SelfServiceReceiptDocument.vue, panel staf (daftar + laci detail)
├── components/restaurant/                           # + kolom filter, paginasi, aksi QR, lembar QR massal
├── api/selfService.js, stores/selfService.js        # draf keranjang bertahan di localStorage
├── utils/selfServiceCart.js, utils/pdfCapture.js    # pdfCapture dipakai ulang, tidak digandakan
├── views/SalesView.vue                              # tampilan ketiga "Self-Service" + lencana
├── views/DiningSetupView.vue                        # filter, paginasi, Buat QR, QR massal
└── views/PosView.vue                                # toggle Dine-In / Take Away

android-pos/app/src/main/java/com/warkop/pos/
├── audio/OrderCallQueue.kt, SelfServicePhrase.kt    # FIFO di atas SpeechEngine; QueueAnnouncer tidak diubah
├── state/SelfServicePoller.kt, SelfServicePreference.kt
├── data/ (mapper alerts), PosStore.kt               # kursor, spanduk, pengaturan suara
└── ui/screens/EditorScreen.kt                       # pilihan Dine-In / Take Away

tests/Feature/SelfService*Test.php, DiningTableListTest.php, ServiceTypeTest.php
qa-tests/                                            # Vitest: keranjang, validasi, tab staf, toggle
android-pos/app/src/test/…                           # OrderCallQueue, kursor, preferensi, TerminologyScanTest
```

**Structure Decision**: aplikasi web yang ada (Laravel `app/` + Vue `resources/js/`) ditambah modul Android yang ada (`android-pos/`). Tidak ada proyek baru. Halaman publik hidup di SPA yang sama sebagai rute di luar `AppShell` agar tidak ada server frontend kedua (CLAUDE.md: satu mesin, tanpa tier tambahan) dan memakai token desain yang sama.

## Phasing (urutan implementasi; dirinci oleh `/speckit-tasks`)

1. **Fondasi**: migrasi, `SelfServiceQrToken`, pembatas, rute publik `context`, kunci menu, pengaturan alamat dasar.
2. **US2 + US7**: QR per meja, rotasi, QR massal, filter + paginasi Restaurant Setup (dapat diuji tanpa pelanggan).
3. **US1**: menu publik, bukti, kirim, idempotensi, halaman mobile.
4. **US6 (bukti kelayakan dulu)**: tes yang membuktikan `addLine` → `send` → `settle` berjalan untuk order tanpa `table_id`/`device_id` — **risiko teknis terbesar**; baru kemudian verifikasi, tolak, dispatch, tab Self-Service.
5. **US3 + US4**: struk (gambar/PDF) dan data diri.
6. **US5**: `service_type` di POS web dan Android.
7. **US8**: poller, antrean suara, spanduk, preferensi.
8. **Penutup**: OpenAPI, catatan bertanggal PRD/CLAUDE.md, `evidence.md`, verifikasi manusia.

## Pemeriksaan ulang Konstitusi setelah desain

Tidak ada prinsip yang berubah penilaiannya. Satu hal baru muncul dari desain: dispatch membuat order `open` tanpa meja lalu mengisi `table_id` setelah `completed` (research D3). Ini dicatat di bawah, dan **harus** dibuktikan oleh tes sebelum dianggap aman.

## Complexity Tracking

| Pelanggaran / kerumitan | Mengapa perlu | Alternatif lebih sederhana ditolak karena |
|---|---|---|
| Rute publik tanpa token (Konstitusi IV) | Pelanggan tidak punya akun; inti fitur | Login/OTP pelanggan menambah gesekan besar dan di luar cakupan spec. Dimitigasi: token QR HMAC + nonce + mode, daftar putih field, pembatas bernama, bukti privat, outlet dari token. Dicatat seperti 073. |
| Entitas penahan `self_service_orders` + materialisasi saat dispatch | `orders.session_id` NOT NULL, stok terpotong saat baris dibuat, satu order `open` per meja, ±41 pembaca status order | Status order baru: menyentuh semua laporan dan memotong stok terlalu dini. Order `open` tanpa meja: tampil di lantai kasir dan memotong stok. |
| Dispatch: order `open` tanpa `table_id`, meja diisi setelah `completed` | Menghindari tabrakan `uniq_orders_open_table_key` bila meja punya tab kasir (Asumsi spec) | Membatalkan dispatch bila meja terisi melanggar spec; mengubah indeks unik berisiko bagi alur tab (028). |
| Paket npm baru `qrcode` | Belum ada pembuat QR di repo | Menulis encoder QR sendiri tidak masuk akal dan rawan salah. Dimuat dinamis supaya tak membebani bundel awal. |

## Asumsi tambahan dari perencanaan (belum ada di spec; perlu konfirmasi)

1. Staf yang menekan **Kirim ke dapur** harus punya **shift terbuka di outlet pesanan** (syarat teknis: pesanan wajib punya shift).
2. Struk sebelum dispatch memakai **nomor mandiri** `SS-…`; nomor order POS menyusul setelah dispatch (menggantikan "penomoran yang sama", FR-016, demi tidak membuat celah pada urutan resmi saat ditolak).
3. Bila harga atau pajak berubah antara kirim dan dispatch, dispatch **ditolak** (`total_changed`) dan staf menolak pesanan; pelanggan tidak pernah ditagih selisih.
4. **Hanya pemegang izin verifikasi QRIS** yang bisa memverifikasi pesanan mandiri; persetujuan penyelia untuk kasir biasa tidak ikut (D4).
5. Tab Self-Service hanya di web; Android hanya memberi tahu (sudah di spec).
