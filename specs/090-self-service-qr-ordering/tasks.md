---
description: "Daftar tugas fitur 090-self-service-qr-ordering"
---

# Tasks: Pesan Mandiri via QR Meja, QR per Meja, dan Penanda Dine-In / Take Away

**Klasifikasi**: INTERNAL

**Input**: `/specs/090-self-service-qr-ordering/` — plan.md, spec.md, research.md (D1–D21), data-model.md, contracts/self-service-api.md, quickstart.md

**Tes**: DISERTAKAN. Konstitusi II mewajibkan tes backend (`tests/Feature/`, MySQL), tes frontend (`qa-tests/`), dan bukti peramban/perangkat nyata sebelum "selesai". Tes ditulis lebih dulu dalam tiap fase cerita dan harus gagal sebelum implementasi.

**Organisasi**: dikelompokkan per cerita pengguna agar tiap cerita dapat diimplementasi dan diuji sendiri.

## Format: `[ID] [P?] [Story] Deskripsi + path berkas`

- **[P]**: boleh paralel (berkas berbeda, tidak bergantung tugas yang belum selesai)
- **[USn]**: cerita pengguna dari spec.md
- Komentar kode, teks UI, dan pesan commit berbahasa Indonesia (CLAUDE.md)

## Aturan lingkungan yang berlaku untuk SEMUA tugas tes

- Tes backend di kontainer HANYA dengan: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Nama>` (semua `-e` SEBELUM nama layanan `app`; `-e` sesudah `artisan test` akan salah dibaca sebagai opsi artisan`. Jangan pernah tanpa flag `-e` (pernah menghapus database dev dua kali).
- Bind mount Docker bisa tertinggal beberapa detik setelah berkas ditulis (kontainer pernah membaca versi lama/terpotong): pastikan ukuran berkas di kontainer sama dengan di host sebelum menjalankan tes atau `php -l` di kontainer.
- Jangan menjalankan tes terfilter saat satu proses tes lain masih berjalan pada database tes yang sama (memori `one-test-process-per-test-db`).
- Jangan mengganti nama atau mengurutkan ulang berkas migrasi lama; migrasi baru memakai awalan tanggal setelah `2027_01_02_000001`.

---

## Phase 1: Setup

**Tujuan**: persiapan bersama yang tidak bergantung pada cerita mana pun.

- [X] T001 Pasang paket npm `qrcode` (cek versi stabil terbaru di registry lebih dulu; versi **unverified** pada rencana, jangan menebak), kunci di `package.json` dan `package-lock.json`, lalu pastikan `npm run build` tetap lulus
- [X] T002 [P] Buat kerangka berkas bahasa `lang/id/self_service.php` dan `lang/en/self_service.php` (kunci diisi per cerita; Indonesia adalah sumber kebenaran, Inggris untuk paritas)
- [X] T003 [P] Buat kerangka `specs/090-self-service-qr-ordering/evidence.md` berisi daftar "belum diverifikasi" dari quickstart.md (iOS/Android kamera, HEIC, suara tablet, SC-003, SC-012, angka pembatas, spike dispatch)

---

## Phase 2: Foundational (memblokir SEMUA cerita)

**Tujuan**: skema, token QR, konteks outlet publik, pembatas, dan bukti kelayakan dispatch.

**⚠️ KRITIS**: tidak ada pekerjaan cerita yang dimulai sebelum fase ini selesai, terutama tugas spike dispatch.

- [X] T004 Migrasi `database/migrations/2027_01_03_000001_create_self_service_orders_table.php` sesuai data-model.md (kolom, indeks `(outlet_id,status,id)`, UNIQUE `receipt_token`, `local_ref`, `(outlet_id,business_date,reference_no)`, FK ke outlets/dining_tables/payment_channels/payment_proofs/users/orders)
- [X] T005 Migrasi `database/migrations/2027_01_03_000002_add_source_to_orders_table.php`: `orders.source` ENUM(`pos`,`self_service`) NOT NULL DEFAULT `pos`, `orders.self_service_order_id` FK null, indeks `(source, business_date)`; `down()` aman
- [X] T006 Migrasi `database/migrations/2027_01_03_000003_add_qr_nonce_to_dining_tables_table.php`: `qr_nonce` char(16) NOT NULL, isi acak untuk SEMUA baris lama (termasuk mode DEMO dan LIVE, tanpa global scope)
- [X] T007 Cari tempat kunci menu `dining_setup` dideklarasikan (backend: katalog menu/`User::canAccessMenu`; frontend: `resources/js/utils/navigation.js`; `lang/*/roles.php`) lalu daftarkan kunci baru `self_service` di tempat yang sama, dan tambah migrasi `database/migrations/2027_01_03_000004_add_self_service_menu_key_to_default_roles.php` (owner, admin, kasir; pola `2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.php`)
- [X] T008 Model `app/Models/SelfServiceOrder.php` (`HasDataMode`, `BelongsToOutlet`, `StampsBusinessDay`, casts JSON/decimal, konstanta status, relasi ke order/table/proof/channel) dan tambah di `app/Models/Order.php` konstanta `SOURCE_POS`/`SOURCE_SELF_SERVICE`, relasi `selfServiceOrder`, TANPA menambah `source` ke `$fillable`
- [X] T009 Daftarkan tabel baru di `app/Support/OutletScopeRegistry.php` dan pastikan `OutletScopeRegistryTest` lulus
- [X] T010 [P] Buat `app/Support/SelfServiceQrToken.php`: `issue(DiningTable): string` dan `verify(string): DiningTable` (HMAC-SHA256 dengan `APP_KEY`, `hash_equals`, memuat table_id/outlet_id/mode/nonce; cek meja ada & aktif, outlet cocok, mode sama, nonce sama; semua kegagalan melempar satu pengecualian seragam) + `rotate(DiningTable)`
- [X] T011 [P] Buat `app/Support/SelfServiceSettings.php` (satu-satunya pembaca `self_service_base_url`, penurunan URL QR, penolakan `localhost`/`127.0.0.1`/`::1`), dan izinkan kunci itu di `app/Http/Requests/UpdateSettingsRequest.php` (hanya owner/admin) beserta validasi URL http/https
- [X] T012 Daftarkan pembatas bernama `self-service-read` (120/mnt/IP), `self-service-submit` (5/mnt per IP+meja DAN 20/jam per IP), `self-service-proof` (10/mnt/IP) di `app/Providers/AppServiceProvider.php` dan pastikan renderer 429 tunggal di `bootstrap/app.php` dipakai (angka awal ASUMSI)
- [X] T013 Middleware `app/Http/Middleware/ResolveSelfServiceTable.php`: verifikasi token dari path, `OutletContext::bind(null, $outletId)`, simpan meja pada atribut permintaan, reset konteks di `finally` (pola `ResolveOutletContext`); daftarkan aliasnya di `bootstrap/app.php`
- [X] T014 Kerangka grup rute publik `/api/v1/self-service/{token}/…` di `routes/api.php` (di luar `auth:sanctum`, DI DALAM `EnsureInstallationIsActivated`) dengan komentar pengecualian Konstitusi IV merujuk plan.md Complexity Tracking
- [X] T015 [P] Tes `tests/Feature/SelfServiceQrTokenTest.php`: id diubah → ditolak, tanda tangan salah, nonce diputar → QR lama mati, mode berbeda, meja nonaktif/terhapus, outlet tidak cocok, respons ke luar seragam, instalasi belum aktif → ditolak (FR-026)
- [X] T016 **SPIKE** `tests/Feature/SelfServiceDispatchSpikeTest.php`: buktikan di MySQL bahwa urutan `TabService` (buat order `open` tanpa `table_id`/`device_id`) → `addLine` → `KitchenService::send` → `TabSettlementService::settle` (QRIS `verified`) → set `table_id` setelah `completed` berhasil, termasuk saat meja SUDAH punya tab kasir `open`, dan bahwa tiket tampil pada umpan stasiun dengan meja yang benar. Bila gagal: BERHENTI dan perbarui research D3 sebelum lanjut

**Checkpoint**: skema, token, middleware, pembatas, dan spike lulus; cerita boleh dimulai.

---

## Phase 3: User Story 1 — Pelanggan memesan dan membayar QRIS dari ponsel (P1) 🎯 MVP

**Tujuan**: dari memindai QR sampai pesanan tertahan dengan bukti bayar, sebagai tamu.

**Uji mandiri**: buka alamat halaman untuk satu meja pada lebar 360–430 px, pilih dua item, lampirkan foto, kirim → satu `self_service_orders` berstatus `awaiting_verification` dengan total hitungan server; tidak ada baris `orders`, stok, atau tiket dapur.

### Tes (tulis dulu, harus gagal)

- [X] T017 [P] [US1] `tests/Feature/SelfServiceContextMenuTest.php`: payload `context` (outlet, meja, pajak, kanal QRIS), `menu` hanya menu outlet itu, daftar putih field (TIDAK ada `cost_price`, `current_stock`, `on_hand`, `home_outlet`), item habis ditandai, tanpa kanal QRIS → `qris_unavailable`
- [X] T018 [P] [US1] `tests/Feature/SelfServiceSubmitTest.php`: harga/pajak/total dari server, field diskon/outlet/table/harga ditolak 422, modifier dan aturan jumlah, `local_ref` idempoten (kirim ulang → entitas sama), item tidak tersedia → 409 `item_unavailable` berisi rincian, bukti wajib dan sekali pakai, meja nonaktif, mode salah, 429 pada kelebihan laju, TIDAK ada `orders`/`stock_movements`/`kitchen_tickets` baru, nomor mandiri unik per outlet per hari
- [X] T019 [P] [US1] `tests/Feature/SelfServiceProofUploadTest.php`: hanya JPEG/PNG ≤ 5 MB, MIME dari isi berkas, disimpan di disk privat dengan nama acak, `uploaded_by` null, `captured_via` `webcam|upload`, perintah pembersih menghapus bukti yatim > 24 jam dan TIDAK menyentuh yang tertaut

### Implementasi backend

- [X] T020 [US1] `app/Services/SelfServiceOrderService.php` — `submit()`: validasi ketersediaan (`MenuAvailability::assertOrderable`), harga outlet (`PriceService::applyOutletPrice`), modifier (`ModifierResolver`), pajak (`TaxCalculator::compute`), potret `lines`/total, nomor mandiri `SS-YYMMDD-NNN` (unik, dicoba ulang saat bentrok), idempotensi `local_ref`, tautkan bukti (sekali pakai), `ActivityLogger` `self_service_submitted` dalam transaksi yang sama (user null, tanpa data pribadi)
- [X] T021 [US1] `app/Http/Requests/SubmitSelfServiceOrderRequest.php`: aturan sesuai kontrak (items 1–50, qty 1–99, `modifier_selections`, `service_type` in `dine_in,takeaway`, `proof_token`, `local_ref` uuid) dan tolak field terlarang
- [X] T022 [US1] `app/Http/Controllers/Api/SelfServicePublicController.php` aksi `context`, `menu` (memakai `ProductCatalogQuery`, `OutletMenuService`, `ModifierResolver::present`; resource daftar putih `app/Http/Resources/SelfServiceMenuResource.php`, eager-load tanpa N+1), `uploadProof` (memakai ulang `PaymentProof` dan aturan `PaymentProofController`), `submit`
- [X] T023 [US1] `app/Console/Commands/PurgeOrphanSelfServiceProofs.php` (`self-service:purge-orphan-proofs`, > 24 jam, `payment_id` kosong dan tak tertaut ke entitas) dan jadwalkan di tempat penjadwalan yang dipakai proyek (cek `routes/console.php`/`bootstrap/app.php`)
- [X] T024 [US1] Isi rute publik `context`, `menu`, `proofs`, `orders` di `routes/api.php` dengan pembatas bernama dan middleware `ResolveSelfServiceTable`; isi pesan Indonesia/Inggris di `lang/*/self_service.php`

### Implementasi frontend (mobile-first, token desain `@theme`, tanpa hex mentah)

- [X] T025 [P] [US1] `resources/js/api/selfService.js` (publik; periksa `resources/js/api/client.js` agar interseptor 401/login TIDAK menendang halaman publik) dan `resources/js/stores/selfService.js` (keranjang + pilihan layanan + `local_ref` disimpan di `localStorage` dengan try/catch, bertahan saat muat ulang, dibersihkan setelah kirim — FR-013)
- [X] T026 [P] [US1] `resources/js/utils/selfServiceCart.js` (jumlah baris dan total pratinjau, hanya tampilan; pakai ulang `utils/modifierRules.js` dan `utils/money.js`) dan `resources/js/utils/imageDownscale.js` (kanvas → JPEG ≤ 5 MB, sisi terpanjang dibatasi)
- [X] T027 [US1] Rute publik `/pesan/:token` di `resources/js/router/index.js` di luar `AppShell` dan terbuka tanpa login; pastikan guard tidak mengalihkan ke `/login` dan `useBranding` tetap aman tanpa `AppShell`
- [X] T028 [US1] `resources/js/views/SelfOrderView.vue`: kerangka langkah Menu → Keranjang → Bayar, header outlet + meja, pesan galat QR/meja/QRIS tidak tersedia
- [X] T029 [P] [US1] `resources/js/components/selfservice/SelfMenuGrid.vue` dan kartu produk: pencarian dan kategori, memakai `buildProductCards`/`toCartItem` dari `utils/posProductCards.js` (JANGAN `map` inline), foto dengan cadangan inisial, status habis tidak bisa dipilih
- [X] T030 [P] [US1] `resources/js/components/selfservice/SelfVariantSheet.vue` (lembar bawah: varian, modifier, jumlah, catatan; aturan min/maks dari `modifierRules.js`)
- [X] T031 [P] [US1] `resources/js/components/selfservice/SelfCartSheet.vue` (ubah jumlah, hapus, pilih Dine-In/Take Away bawaan Dine-In, ringkasan subtotal/pajak/total, TANPA kolom diskon)
- [X] T032 [P] [US1] `resources/js/components/selfservice/SelfPaymentStep.vue`: hanya QRIS, gambar QRIS dapat diperbesar, nominal, `ProofPicker` (kamera via `capture` dan unggah galeri, pratinjau, ganti, `imageDownscale`), tombol kirim nonaktif sampai ada bukti
- [X] T033 [US1] Alur kirim di `SelfOrderView.vue`: unggah bukti → kirim → tampilan konfirmasi sementara (nomor mandiri + status "Menunggu verifikasi"; struk penuh menyusul di US3); petakan 409 `item_unavailable` (tandai item, keranjang tetap), 429, dan 422 ke pesan Indonesia lewat penanganan galat pusat
- [X] T034 [P] [US1] Tes Vitest `qa-tests/unit/selfServiceCart.test.js`, `qa-tests/unit/imageDownscale.test.js`, `qa-tests/component/SelfOrderView.test.js` (draf bertahan muat ulang, tanpa bukti tidak bisa kirim, tidak ada UI diskon, hanya QRIS, teks Indonesia)

**Checkpoint**: US1 berjalan dan teruji sendiri (kecuali verifikasi peramban ponsel nyata → tugas verifikasi manusia di fase Polish).

---

## Phase 4: User Story 2 — Staf membuat QR per meja (P1)

**Tujuan**: QR yang sah dapat dibuat, dicetak, dan dicabut dari Restaurant Setup.

**Uji mandiri**: buat QR Meja 5, pindai dengan ponsel → halaman US1 terbuka untuk meja dan outlet yang benar; ubah id pada alamat → ditolak.

- [X] T035 [P] [US2] `tests/Feature/DiningTableQrTest.php`: `POST /dining-tables/qr-batch` dengan `ids` dan dengan `filter`, batas 200, gerbang `dining_setup` (403 tanpa menu), dipersempit `OutletContext`, `POST /dining-tables/{id}/qr/rotate` mematikan QR lama, log `table_qr_generated`/`table_qr_rotated`, alamat dasar tidak valid → 409 `qr_base_url_invalid`
- [X] T036 [P] [US2] Tes Vitest `qa-tests/unit/tableQrUrl.test.js` dan `qa-tests/component/TableQrSheet.test.js` (label outlet + meja, `qrcode` dimuat dinamis, peringatan bila alamat dasar belum siap)
- [X] T037 [US2] `DiningTableController` aksi `qrBatch` dan `rotateQr`, `app/Http/Requests/QrBatchRequest.php` (otorisasi `dining_setup` di `authorize()`), tambah `qr_ready` pada `app/Http/Resources/DiningTableResource.php`, rute di `routes/api.php`, log lewat `ActivityLogger` tanpa data pribadi
- [X] T038 [US2] Kolom "Alamat dasar Self-Service" di layar Pengaturan (`resources/js/views/SettingsView.vue` atau subfolder `views/settings/`), terlihat hanya bagi owner/admin, dengan contoh dan peringatan `localhost`; hubungkan ke `resources/js/api/settings.js`
- [X] T039 [P] [US2] Tambah `qrBatch` dan `rotateQr` di `resources/js/api/diningTables.js`
- [X] T040 [P] [US2] `resources/js/components/restaurant/TableQrSheet.vue`: QR dirender dari `import('qrcode')` dinamis, label outlet + nomor meja di bawah QR, unduh PNG, cetak
- [X] T041 [US2] `resources/js/components/restaurant/TableQrBulkSheet.vue`: lembar siap cetak banyak QR; unduh PDF berhalaman A4 lewat `resources/js/utils/qrSheetPdf.js` (jsPDF langsung, bukan `pdfCapture`: pdfCapture memotret SATU elemen jadi SATU halaman raksasa, tak bisa dicetak untuk 50–200 QR; lembar ini tanpa `<img>` lintas-origin sehingga bug gambar-tertukar tidak berlaku). PNG satu QR tetap lewat `pdfCapture`
- [X] T042 [US2] `resources/js/components/restaurant/TablesAdmin.vue`: aksi baris "Buat QR" dan "Buat ulang QR" (konfirmasi + peringatan QR lama mati), dihapus dari UI bagi peran tanpa `dining_setup`

**Checkpoint**: US2 dan US1 bersama membentuk jalur pelanggan yang bisa dicoba.

---

## Phase 5: User Story 6 — Staf memeriksa, memverifikasi, lalu mengirim ke dapur (P1)

**Tujuan**: tab Self-Service di Penjualan web, verifikasi, tolak, dan Kirim ke dapur.

**Uji mandiri**: dengan satu kiriman dari US1, buka tab Self-Service sebagai kasir → belum ada tiket; verifikasi → Kirim ke dapur → tiket, stok, pembayaran terverifikasi, order `source = self_service`.

### Tes (tulis dulu)

- [X] T043 [P] [US6] `tests/Feature/SelfServiceOrderListTest.php`: daftar tab + `meta.counts`, filter `status[]`/`table_id`/`q`, paginasi, dipersempit outlet, 403 tanpa menu `self_service`, outlet lain → 404
- [X] T044 [P] [US6] `tests/Feature/SelfServiceVerifyRejectTest.php`: referensi < 4 karakter → 422, hanya pemegang izin verifikasi QRIS, klik ganda aman, dua staf bersamaan (satu menang, lainnya 409 `invalid_transition`), Tolak butuh alasan, Tolak TIDAK membuat order/stok/tiket/pembayaran, log tanpa data pribadi
- [X] T045 [P] [US6] `tests/Feature/SelfServiceDispatchTest.php`: sukses (tiket per stasiun berisi meja + `service_type`, stok `sale` bergerak, bahan modifier terpotong saat completed, satu pembayaran `qr_ewallet` `verified` dengan bukti tertaut dan referensi, order `completed`, `source = self_service`, `table_id` terisi), belum verifikasi → 409, `no_open_shift`, `total_changed`, `item_unavailable`, meja dengan tab kasir terbuka tidak terganggu, dispatch ganda tidak menggandakan, kirim bersamaan
- [X] T046 [P] [US6] `tests/Feature/SelfServiceProofAccessTest.php`: pemegang menu `self_service` boleh membuka foto bukti pesanan outlet-nya, peran lain 403, tanpa token 401, bukti dari outlet lain 404
- [X] T047 [P] [US6] `tests/Feature/SelfServiceReportIsolationTest.php`: kiriman `awaiting_verification`/`verified`/`rejected` TIDAK muncul di `SalesFigures`, `DashboardService`, `ShiftReportService`, `SalesTransactionsService`, laporan stok/bahan (SC-011)

### Implementasi backend

- [X] T048 [US6] Aturan "pengguna ini boleh memverifikasi QRIS" = izin `payment.qris.verify`; penerjemah aksi → izin sudah satu-satunya di `OverrideGate`. Ditambah `OverrideGate::holds($user, 'qris_verify')` (dipakai `requireFor` dan layanan baru) — tanpa salinan peta aksi→izin (Konstitusi I). `Payment::mayVerify` (aturan "bukan pembayaran milik sendiri") tak relevan: pembayaran pesanan mandiri belum ada saat verifikasi dan tak punya pencatat
- [X] T049 [US6] `SelfServiceOrderService::verify()` dan `reject()` (kunci baris `lockForUpdate`, transisi sah saja, `ActivityLogger` dalam transaksi yang sama)
- [X] T050 [US6] `SelfServiceOrderService::dispatch()` sesuai research D3 (syarat shift terbuka lewat `ShiftSource`; buat order `open` tanpa meja lewat metode baru di `app/Services/TabService.php`; `addLine` per baris; bandingkan total → `total_changed`; `KitchenService::send`; `TabSettlementService::settle` dengan QRIS terverifikasi + bukti; set `table_id`, `source` (lewat `forceFill`), `self_service_order_id`, status `dispatched`)
- [X] T051 [US6] `app/Http/Controllers/Api/SelfServiceOrderController.php` (list, show, verify, reject, dispatch), `app/Http/Requests/` (`VerifySelfServiceOrderRequest`, `RejectSelfServiceOrderRequest`, otorisasi menu `self_service` di `authorize()`), `app/Http/Resources/SelfServiceOrderResource.php`, rute staf di `routes/api.php` dalam grup `auth:sanctum` + `ResolveOutletContext`
- [X] T052 [US6] Perluas `app/Http/Controllers/Api/PaymentProofController.php::show` agar pemegang menu `self_service` dapat membuka bukti yang tertaut ke pesanan mandiri di outlet yang dapat dilihatnya (tetap lewat jalur berotorisasi, tidak ada URL publik)
- [X] T053 [US6] Tambah field `source` + `service_type` dan filter `source=self_service` di `app/Services/SalesTransactionsService.php` dan `app/Http/Resources/OrderResource.php`; tambah `source`/`service_type` dan tag "Mandiri" pada `app/Http/Resources/TicketResource.php` agar layar dapur dan cetak tiket (`resources/js/components/restaurant/PrintableTicket.vue`) menampilkan meja + Dine-In/Take Away (FR-032)

### Implementasi frontend

- [X] T054 [P] [US6] `resources/js/api/selfServiceOrders.js` (list, show, verify, reject, dispatch) dan perluasan pemuat foto bukti
- [X] T055 [US6] `resources/js/views/SalesView.vue`: tampilan ketiga `self-service` pada saklar yang ada (`?view=self-service` lewat `useQueryState`), lencana jumlah menunggu, hanya untuk pemegang menu `self_service` (disembunyikan penuh bagi lainnya)
- [X] T056 [US6] `resources/js/components/selfservice/SelfServiceOrdersPanel.vue`: tabel via `usePaginatedList`, filter status dan meja, penyegaran otomatis (`usePolling`/`useAutoRefresh`), penanda status dan "belum dikirim ke dapur"
- [X] T057 [US6] `resources/js/components/selfservice/SelfServiceOrderDrawer.vue`: detail pesanan, detail pembayaran, foto bukti dapat diperbesar, aksi Verifikasi (input referensi ≥ 4 karakter), Tolak (pakai ulang `components/restaurant/ReasonDialog.vue`), Kirim ke dapur (aktif hanya setelah terverifikasi); petakan 409 `no_open_shift`/`total_changed`/`item_unavailable`/`invalid_transition` ke pesan jelas
- [X] T058 [P] [US6] Tes Vitest `qa-tests/component/SelfServiceOrdersPanel.test.js` dan `SelfServiceOrderDrawer.test.js` (tombol Kirim terkunci sebelum verifikasi, tab tersembunyi tanpa menu, pesan galat)

**Checkpoint**: tiga cerita P1 selesai = **MVP** yang dapat dipakai di lapangan.

---

## Phase 6: User Story 3 — Struk setelah kirim, simpan sebagai gambar atau PDF (P2)

**Uji mandiri**: selesaikan satu pesanan → struk tampil, simpan gambar dan PDF, isi sama dengan layar; buka ulang tautan → status terbaru.

- [X] T059 [P] [US3] `tests/Feature/SelfServiceReceiptTest.php`: bentuk respons daftar putih, pemetaan status (menunggu/terverifikasi/diproses/ditolak + alasan), `pos_order_number` muncul setelah dispatch, token acak tak bisa ditebak, tidak membocorkan HPP/telepon/surel/data pelanggan lain, `StoreIdentity` outlet transaksi
- [X] T060 [US3] Aksi `receipt` di `SelfServicePublicController` + `app/Http/Resources/SelfServiceReceiptResource.php` (identitas toko lewat `app/Support/StoreIdentity.php`, pajak tampil sesuai `tax_show`), rute `GET /self-service/receipts/{receiptToken}` dengan `self-service-read`
- [X] T061 [P] [US3] Rute publik `/pesan/struk/:receiptToken` + `resources/js/views/SelfOrderReceiptView.vue` (penyegaran status berkala yang ringan, pesan ditolak + alasan)
- [X] T062 [US3] `resources/js/components/selfservice/SelfServiceReceiptDocument.vue` (SATU tata letak struk) dan tombol Simpan gambar / Simpan PDF lewat `utils/pdfCapture.js` yang dimuat dinamis; gambar (logo) ditukar menurut `src`, bukan indeks (aturan 029)
- [X] T063 [US3] Setelah kirim di `SelfOrderView.vue` arahkan ke struk dan simpan `receipt_token` terakhir di `localStorage` (try/catch) agar dapat dibuka ulang dari perangkat yang sama
- [X] T064 [P] [US3] Tes Vitest `qa-tests/component/SelfOrderReceiptView.test.js` dan tes regresi gambar umpan untuk dokumen struk (pola `qa-tests/unit/pdfCapture.test.js`)

---

## Phase 7: User Story 4 — Data diri atau lanjut sebagai tamu (P2)

**Uji mandiri**: satu pesanan sebagai tamu (tanpa pelanggan), satu dengan data diri (tertaut ke pelanggan, tanpa duplikat).

- [X] T065 [P] [US4] `tests/Feature/SelfServiceCustomerTest.php`: tamu vs data diri, nama + telepon wajib bila mengisi, format telepon/surel, data pribadi TIDAK ada di log aktivitas dan respons publik, dispatch mencocokkan telepon ternormalisasi (tanpa duplikat), pelanggan baru tidak melihat data pelanggan lama
- [X] T066 [US4] Cek apakah normalisasi telepon sudah ada (impor pelanggan `app/Services/CustomerExportImportService.php`); pakai ulang bila ada, bila tidak buat satu `app/Support/PhoneNormalizer.php` yang dipakai bersama
- [X] T067 [US4] Blok `customer` pada `SubmitSelfServiceOrderRequest` dan `SelfServiceOrderService::submit()` (simpan di entitas, bukan di `customers`); cari-atau-buat `Customer` saat `dispatch()` dan set `orders.customer_id`
- [X] T068 [P] [US4] `resources/js/components/selfservice/SelfCustomerStep.vue` (pilihan "Lanjut sebagai tamu", kolom nama/telepon/surel, validasi, pemberitahuan singkat kegunaan data) dan pasang di langkah Bayar
- [X] T069 [US4] Tampilkan nama pelanggan (telepon disamarkan di daftar) di panel dan laci staf (`SelfServiceOrdersPanel.vue`, `SelfServiceOrderDrawer.vue`)
- [X] T070 [P] [US4] Tes Vitest `qa-tests/component/SelfCustomerStep.test.js`

---

## Phase 8: User Story 5 — Dine-In / Take Away di POS web dan Android (P2)

**Uji mandiri**: transaksi Dine-In dan Take Away di POS web dan di Android tampil benar di detail, struk, dan daftar penjualan; transaksi lama tak berubah.

- [X] T071 [P] [US5] `tests/Feature/ServiceTypeTest.php`: `POST /orders` dengan `service_type`, `POST /tabs` override dan bawaan turunan (meja → `dine_in`, tanpa meja → `takeaway`), nilai tak sah → 422, order lama tetap `retail`, daftar/detail/struk memuat `service_type`
- [X] T072 [US5] Terima `service_type` di `app/Http/Requests/StoreOrderRequest.php` + `app/Services/OrderService.php::create` dan di `app/Http/Requests/OpenTabRequest.php` + `app/Services/TabService.php::open` (opsional; tanpa nilai perilaku lama tetap)
- [X] T073 [US5] Audit pengelompokan menurut `retail` di `app/Services/DashboardService.php`, `app/Services/DashboardOrdersQuery.php`, `app/Http/Requests/DashboardQueryRequest.php`; sesuaikan label/tes agar order baru Dine-In/Take Away tampil benar dan riwayat `retail` tetap (FR-031)
- [X] T074 [P] [US5] `resources/js/components/pos/ServiceTypeToggle.vue` dan pasang di `resources/js/views/PosView.vue` (bawaan Dine-In, kirim lewat `resources/js/api/orders.js`), tes Vitest
- [X] T075 [P] [US5] Tampilkan `service_type` di detail transaksi/struk web (`resources/js/views/SalesView.vue`, komponen di `resources/js/components/receipt/`, `components/sales/TransactionItemsModal.vue`) dengan label "Dine-In"/"Take Away"
- [X] T076 [P] [US5] Android: model + pilihan Dine-In/Take Away di `android-pos/app/src/main/java/com/warkop/pos/ui/screens/EditorScreen.kt` dan model di `model/PosModels.kt`; kirim lewat pembuatan tab di `data/RemoteBackend.kt` (tab meja tetap Dine-In kecuali diubah eksplisit); tes JUnit di `android-pos/app/src/test/`
- [X] T077 [US5] Android: cetak/tampilkan `service_type` pada struk (`data/ReceiptMapper.kt`, `print/` dokumen) dan jalankan `TerminologyScanTest` agar istilah baku tidak dilanggar

---

## Phase 9: User Story 8 — Notifikasi suara di Android POS (P2)

**Uji mandiri**: dengan tablet terbuka di outlet yang sama, kirim pesanan Meja 5 dari ponsel → "Pesanan dari meja 5" terdengar ≤ 10 detik dan spanduk tampil.

- [X] T078 [P] [US8] `tests/Feature/SelfServiceAlertsTest.php`: `GET /self-service/alerts?after_id=` hanya outlet perangkat, urut naik, `waiting_count`, `last_id`, tanpa data pelanggan, 403 tanpa menu `self_service`/`pos`, tidak membocorkan outlet lain
- [X] T079 [US8] Aksi `alerts` di `SelfServiceOrderController` + rute (bentuk respons per kontrak; kueri berindeks `(outlet_id,status,id)`)
- [X] T080 [P] [US8] `android-pos/.../audio/OrderCallQueue.kt` (FIFO di atas `SpeechEngine`, satu ucapan selesai baru berikutnya, kegagalan tidak menahan antrean) dan `audio/SelfServicePhrase.kt` ("Pesanan dari meja {nama}"); JUnit; `QueueAnnouncer` TIDAK diubah
- [X] T081 [P] [US8] `android-pos/.../state/SelfServicePreference.kt` (suara hidup/mati per perangkat, pola `SplitPreference`) + JUnit
- [X] T082 [US8] Android: `selfServiceAlerts(afterId)` di `data/PosBackend.kt` + `data/RemoteBackend.kt`, DTO dengan `ignoreUnknownKeys`, mapper `data/SelfServiceAlertMapper.kt`, tes mapper
- [X] T083 [US8] `android-pos/.../state/SelfServicePoller.kt` (5 dtk hanya saat aplikasi di depan, mundur eksponensial saat gagal, kursor `after_id` dipertahankan per perangkat, kunjungan pertama/pulih dari offline memajukan kursor TANPA suara dan hanya menampilkan `waiting_count`) + JUnit dengan jam palsu
- [X] T084 [US8] Hubungkan di `state/PosStore.kt` dan `ui/PosApp.kt`: mulai/berhenti bersama siklus foreground, spanduk pemberitahuan yang dapat ditutup (meja, nomor, total), jumlah menunggu, hormati pencabutan terminal dan pergantian mode (`TerminalRevokedRule`, `modeChanged`)
- [X] T085 [US8] Sakelar suara notifikasi di layar pengaturan/Koneksi tablet (cari layar yang sudah memuat pengaturan perangkat) dan teks baku; jalankan `TerminologyScanTest`

---

## Phase 10: User Story 7 — Filter dan paginasi daftar meja (P3)

**Uji mandiri**: pada outlet dengan lebih dari satu halaman meja, cari/saring/pindah halaman → hasil, total, dan pilihan konsisten; QR massal sesuai pilihan.

- [X] T086 [P] [US7] `tests/Feature/DiningTableListTest.php`: paginasi **opt-in** (tanpa `page` bentuk lama persis, dengan `page` envelope `{data, meta}` + `meta.areas`), `per_page` maks 100, filter `q`, `area`, `is_active`, `outlet_id[]`, tanpa N+1 (`withExists`)
- [X] T087 [US7] Perluas `DiningTableController::index` untuk paginasi + filter opt-in tanpa mengubah konsumen lain (lantai/tab/POS memakai `?active=1` tanpa `page`)
- [X] T088 [P] [US7] Perbarui `resources/js/api/diningTables.js` (param daftar) dan tes Vitest bentuk respons ganda
- [X] T089 [US7] `resources/js/components/restaurant/TablesAdmin.vue` + `resources/js/views/DiningSetupView.vue`: bilah filter (pencarian, area, status, outlet lewat `useOutletPageFilter`), paginasi via `usePaginatedList`, pilihan antar halaman dengan opsi jelas "hanya yang terlihat" vs "semua hasil filter"
- [X] T090 [US7] Hubungkan pilihan ke QR massal (`qrBatch` dengan `ids` atau `filter`), tampilkan jumlah meja yang akan dibuatkan QR sebelum dibuat, dan tes Vitest

---

## Phase 11: Polish & Lintas-Cerita

- [X] T091 [P] Perbarui `docs/openapi-pos-mvp.yaml` untuk SEMUA rute baru/berubah di kontrak (publik + staf + `GET/POST /dining-tables`, `/orders`, `/tabs`, `/settings`) pada commit yang sama dengan rutenya (Konstitusi)
- [X] T092 [P] Catatan bertanggal 2026-11-03 di `docs/PRD-POS-Event-Multivendor.md` (kapabilitas baru, di luar cakupan MVP asli) dan butir fitur 090 di `CLAUDE.md` (entitas penahan, `orders.source`, token QR + nonce, pengecualian Konstitusi IV, bucket `retail` kini riwayat, kunci menu `self_service`, syarat shift saat dispatch)
- [X] T093 [P] Catatan di `docs/RUNBOOK.md`: mengatur alamat dasar Self-Service (LAN/domain), jadwal `self-service:purge-orphan-proofs`, pemulihan bila QR bocor (Buat ulang QR)
- [X] T094 Tinjauan keamanan permukaan publik: uji tanpa kredensial bahwa tidak ada HPP/stok/pelanggan/staf yang bocor (SC-010), setel angka pembatas, catat sisa temuan; pertimbangkan tinjauan `security-review`
- [X] T095 Perbarui spec.md bagian Asumsi dengan lima asumsi tambahan dari plan.md (shift terbuka, nomor mandiri `SS-…`, `total_changed`, izin verifikasi, tab hanya web) sesuai jawaban pemilik
- [ ] T096 Jalankan seluruh tes: backend di kontainer dengan flag aman (satu proses saja), `npm test`, `npm run build` (periksa `qrcode` ada di chunk terpisah), `cd android-pos && ./gradlew test`; catat hasil apa adanya (termasuk kegagalan lama `ShiftReportParityTest` bila masih ada) di `evidence.md`
- [ ] T097 Verifikasi manusia quickstart A–G di ponsel nyata (iOS Safari + Android Chrome: kamera, unggah, simpan gambar/PDF, lebar 360 px) dan tablet nyata (suara, ≤ 10 dtk, mute, tanpa putar ulang), konsol peramban bersih; isi `evidence.md` — yang tidak dijalankan ditulis **belum diverifikasi**

---

## Dependencies & Urutan Eksekusi

### Antar fase
- **Setup (1)** → **Foundational (2)** → semua cerita. Spike dispatch di Foundational menentukan apakah desain D3 berlaku.
- **US1 (3)** butuh Foundational. **US2 (4)** butuh Foundational (independen dari US1; keduanya dapat paralel).
- **US6 (5)** butuh US1 (kiriman ada) dan spike lulus.
- **US3 (6)** butuh US1 (token struk dari kiriman); status penuh juga memakai US6.
- **US4 (7)** butuh US1 (kirim) dan US6 (pencocokan pelanggan saat dispatch).
- **US5 (8)** independen dari cerita lain (hanya Foundational tidak diperlukan; boleh mulai kapan saja), tetapi tugas tiket/struk di US6 memakai `service_type`.
- **US8 (9)** butuh US1 (entitas) dan aksi staf `SelfServiceOrderController` dari US6.
- **US7 (10)** independen; tugas pengaitan QR massal butuh US2.
- **Polish (11)** setelah cerita yang dikirim.

### Dalam cerita
Tes → model/layanan → controller/rute → UI. Tugas berberkas sama berurutan; `[P]` hanya untuk berkas berbeda.

### Peluang paralel
- Foundational: token, pengaturan, dan tes token (`[P]`) berjalan bersama setelah migrasi.
- US1 backend dan frontend dapat dikerjakan paralel begitu kontrak (contracts/self-service-api.md) dikunci.
- US2, US5, dan US7 dapat dikerjakan orang berbeda paralel dengan US1/US6.
- Android (US5 Android, US8) paralel dengan web setelah kontrak `alerts`/`service_type` dikunci.

### Contoh paralel — US1
```
Tes: SelfServiceContextMenuTest | SelfServiceSubmitTest | SelfServiceProofUploadTest
UI : SelfMenuGrid | SelfVariantSheet | SelfCartSheet | SelfPaymentStep | selfServiceCart.js | imageDownscale.js
```

---

## Strategi Implementasi

### MVP (tiga cerita P1)
1. Setup + Foundational (termasuk spike).
2. US1 + US2 (jalur pelanggan dan QR).
3. US6 (verifikasi dan dispatch).
4. **BERHENTI dan validasi**: quickstart skenario A–D pada ponsel dan peramban nyata; tanpa ini fitur tidak boleh dinyatakan siap.

### Pengiriman bertahap
MVP → US3 (struk) → US4 (data diri) → US5 (Dine-In/Take Away) → US8 (notifikasi) → US7 (filter/paginasi) → Polish.

### Catatan risiko yang harus diingat
- Spike dispatch (Foundational) adalah gerbang desain; jangan mengerjakan US6 sebelum lulus.
- Pembatas laju, SC-003, SC-012, kamera/HEIC, dan suara tablet **belum terbukti** sampai verifikasi manusia.
- Semua penulisan stok/pembayaran/tiket hanya lewat layanan yang sudah ada; jangan membuat jalur tulis kedua (Konstitusi I).
