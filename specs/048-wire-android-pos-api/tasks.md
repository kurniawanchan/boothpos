---
description: "Daftar tugas fitur 048 — menghubungkan aplikasi Android POS ke data server"
---

# Tasks: Hubungkan Aplikasi Android POS ke Data Server

**Input**: Dokumen desain di `specs/048-wire-android-pos-api/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Prerequisites**: plan.md, spec.md (ada). Backend dev berjalan (quickstart §1), MySQL `warkop_wjm_test` untuk uji.

**Tests**: DIMINTA — Konstitusi II mewajibkan uji `tests/Feature/` untuk setiap perubahan backend (MySQL, bukan SQLite) dan bukti nyata untuk layar yang disentuh pengguna. Tugas uji ditulis bersama tugas implementasinya; tugas "[Verifikasi USn]" adalah bukti emulator + server nyata.

**Organisasi**: dikelompokkan per cerita pengguna; US1+US2 adalah MVP (irisan S1 di plan.md).

## Format: `[ID] [P?] [Story] Deskripsi`
- **[P]**: dapat berjalan paralel (berkas berbeda, tanpa ketergantungan ke tugas belum selesai)
- **[Story]**: cerita pengguna (US1…US6); fase Setup/Foundational/Polish tanpa label cerita
- Semua jalur relatif terhadap akar repositori `/Users/chan/www/warkop-wjm`

**Aturan keras selama implementasi** (dari konstitusi/CLAUDE.md): jangan menjalankan `php artisan test` di container tanpa `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test`; jangan menyentuh `boothpos-*`; setiap perubahan rute disertai pembaruan `docs/openapi-pos-mvp.yaml` di commit yang sama; teks UI/komentar/commit berbahasa Indonesia; tidak ada rahasia di log.

---

## Phase 1: Setup (infrastruktur bersama)

**Tujuan**: Dependensi, seeder uji, dan pagar mutu sebelum kode fitur.


- [X] T001 Tambahkan plugin dan pustaka kotlinx.serialization + OkHttp ke `android-pos/gradle/libs.versions.toml`, `android-pos/core-network/build.gradle.kts`, `android-pos/core-domain/build.gradle.kts` (versi dipilih dari rilis resmi lalu dicatat di berkas katalog; tandai 'unverified' sampai Gradle berhasil me-resolve; jalankan `./gradlew :core-network:compileDebugKotlin`)
- [X] T002 [P] Tambahkan dependensi uji JVM (junit, kotlinx-coroutines-test) pada `android-pos/app/build.gradle.kts`, `android-pos/core-domain/build.gradle.kts`, `android-pos/core-network/build.gradle.kts` dan buat direktori `src/test/java` di tiap modul
- [X] T003 [P] Buat seeder peran dan staf demo outlet "Warkop WJM 3" di `database/seeders/WarkopPosStaffSeeder.php` (peran Kasir, Waiter, Supervisor, Outlet Manager dengan `all_outlets=false`; staf Rina, Dewi, Andi, Sari, Budi dengan PIN demo; hanya lokal/demo, idempoten) dan daftarkan sebagai seeder opsional di `database/seeders/DatabaseSeeder.php` tanpa dijalankan otomatis
- [X] T004 [P] Buat uji pagar kontrak `tests/Feature/OpenApiCoverageTest.php` yang membandingkan daftar rute `/api/v1` dengan jalur di `docs/openapi-pos-mvp.yaml`, dengan daftar pengecualian eksplisit `tests/Support/openapi-known-gaps.php` yang HANYA boleh menyusut (SC-010)

---

## Phase 2: Foundational (memblokir semua cerita)

**Tujuan**: Perangkat/terminal di server, pengaturan, serta lapisan jaringan, penyimpanan aman, dan batas PosBackend di aplikasi. Tidak ada cerita yang dapat berjalan tanpa ini.


- [X] T005 Buat migrasi `database/migrations/2026_12_21_000001_create_devices_and_counters.php` untuk tabel `devices`, `terminal_counters`, `outlet_queue_counters` sesuai data-model.md (kode unik per outlet, FK ke `outlets`, `data_mode`)
- [X] T006 [P] Buat model `app/Models/Device.php` (trait `HasDataMode`, `BelongsToOutlet`) dan daftarkan tabelnya di `app/Support/OutletScopeRegistry.php`; pastikan `tests/Feature/OutletScopeRegistryTest.php` tetap lulus
- [X] T007 Buat `app/Services/DeviceService.php` (pair: kode otomatis `T##` bila kosong, unik per outlet, outlet dari `OutletContext` bukan body; revoke mencabut token terkait; log audit di transaksi yang sama) dan `app/Http/Controllers/Api/DeviceController.php` + `app/Http/Requests/PairDeviceRequest.php` (hanya owner/admin) lalu rute `POST /devices/pairings`, `GET /devices`, `DELETE /devices/{id}` di `routes/api.php`
- [X] T008 Tulis `tests/Feature/DevicePairingTest.php` (kode otomatis, duplikat kode → 422, non-admin → 403, outlet tak dapat dipilih dari body, outlet lain tak terlihat, cabut perangkat) dan jalankan melawan MySQL `warkop_wjm_test`
- [X] T009 [P] Buat helper pengaturan `app/Support/PosSettings.php` (kunci `tax_rate`, `tax_label`, `cash_out_approval_threshold`, `variance_tolerance`, `edc_duplicate_window_seconds`, `reprint_free_copies` dengan nilai bawaan dari data-model.md; cache dikunci mode data + outlet) dan uji `tests/Feature/PosSettingsTest.php`
- [X] T010 [P] Definisikan `Money` Long-rupiah (`Money.parse`) dan `ApiError` sealed (Network, Unauthorized, Forbidden, Conflict(code,message), Validation(fields), Locked, TooMany(lockedUntil), SchemaOutdated, Server) di `android-pos/core-domain/src/main/java/com/warkop/core/domain/` dan kontrak `PosBackend` di `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt` (PERUBAHAN: antarmuka di modul app karena model domain layar tinggal di sana; core-domain hanya berisi tipe murni tanpa ketergantungan Android)
- [X] T011 [P] Implementasikan klien HTTP di `android-pos/core-network/src/main/java/network/ApiClient.kt` (OkHttp: batas waktu 10/20 dtk, interceptor token + `X-Outlet-Id` + `X-Device-Id`, interceptor log yang menyaring `Authorization`, badan login, PIN) dan `ApiErrorMapper.kt` (tabel pemetaan di contracts/android-client.md)
- [X] T012 [P] Buat `TokenVault` berbasis Android Keystore AES-GCM di `android-pos/app/src/main/java/com/warkop/pos/data/TokenVault.kt` (URL server, token admin, token sesi PIN + kedaluwarsa; tidak pernah menyimpan PIN) dan hapus stub `DataStoreEncryptor` yang tak terpakai di `android-pos/core-domain/src/main/java/util/DataStoreEncryptor.kt`
- [X] T013 Buat `PosApplication` + `AppContainer` (kontainer manual, research D2) di `android-pos/app/src/main/java/com/warkop/pos/PosApplication.kt` dan daftarkan `android:name` di `android-pos/app/src/main/AndroidManifest.xml`; izinkan lalu lintas HTTP ke `10.0.2.2` hanya pada build debug lewat `network_security_config` debug
- [X] T014 Pindahkan data contoh/preset ke source set debug (`android-pos/app/src/debug/java/com/warkop/pos/debug/DebugPresets.kt`, stub kosong di `src/release/.../DebugPresets.kt`) — PERUBAHAN dari rencana awal "SampleBackend": preset hanya menyuntik keadaan layar untuk pembandingan visual, jadi tidak perlu implementasi backend kedua; build rilis tidak memuat data contoh (FR-020). `PosCatalog` yang tersisa dihapus bertahap di US1/US2 saat layar pindah ke data server
- [X] T015 Refaktor `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` agar semua data dan tulis lewat `PosBackend` secara asinkron (viewModelScope), dengan keadaan `Loading/Error(retry)/Ready` per sumber dan API publik yang dipakai layar dipertahankan; jalankan ulang tampilan preset untuk memastikan layar tak berubah
- [X] T016 [P] Tulis uji JVM `android-pos/core-domain/src/test/java/MoneyTest.kt` dan `android-pos/core-network/src/test/java/ApiErrorMapperTest.kt` (angka desimal string → rupiah, pembulatan, setiap kode HTTP → `ApiError`, `Idempotency-Key` yang sama dipakai ulang per niat)

---

## Phase 3: User Story 1 - Masuk PIN dengan staf dan menu asli (P1) 🎯 MVP

**Tujuan**: Terminal disiapkan untuk satu outlet; layar PIN menampilkan staf asli; menu, harga, modifier, dan ketersediaan asli.


- [X] T017 [US1] Buat layar penyiapan terminal di `android-pos/app/src/main/java/com/warkop/pos/setup/TerminalSetupScreen.kt` + `SetupViewModel.kt`: URL server → login admin/owner → pilih outlet dari `GET /me/scope` → `POST /devices/pairings` → simpan di `TokenVault`; tampilkan galat jujur (offline, 401, 403) dengan Coba lagi
- [X] T018 [US1] Implementasikan `RemoteBackend` bagian identitas di `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`: `POST /auth/login`, `GET /me/scope`, `GET /staff/roster`, `POST /auth/pin-sessions`, `POST /auth/token-refresh`, `GET /auth/me` beserta DTO di `android-pos/core-network/src/main/java/network/dto/AuthDtos.kt`
- [X] T019 [US1] Ganti sumber layar PIN di `android-pos/app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt` dan `PosStore.submitPin` dengan roster + login PIN sungguhan: hapus aturan demo "000000", tampilkan `locked`/`hasPin`, sisa percobaan dari `attempts_remaining`, pesan kunci 15 menit dari `locked_until`, dan arahkan sesudah masuk menurut peran/izin dari `/auth/me`
- [X] T020 [US1] Implementasikan siklus sesi di `PosStore`/`RemoteBackend`: perpanjang token PIN sebelum kedaluwarsa, 401 → coba perpanjang sekali → layar PIN tanpa menghapus order terbuka; keluar/kunci mencabut token (`POST /auth/logout`)
- [X] T021 [US1] Implementasikan pemuatan menu di `RemoteBackend` (`GET /products?pos=1&with_variants=1&with_modifiers=1`) dengan DTO `android-pos/core-network/src/main/java/network/dto/MenuDtos.kt` dan pemetaan ke `Product`/`ModGroup`/`MenuCategory` (aturan wajib/min/maks modifier, `image_url`, ikon bawaan bila foto tak ada) di `android-pos/app/src/main/java/com/warkop/pos/data/MenuMapper.kt`
- [X] T022 [US1] Polling ketersediaan (`GET /menu/availability?pos=1`, `If-None-Match`, ±5 dtk, berhenti saat layar tak terlihat) di `android-pos/app/src/main/java/com/warkop/pos/data/AvailabilityPoller.kt` dan terapkan penanda habis ke kartu menu
- [X] T023 [US1] Alihkan `android-pos/app/src/main/java/com/warkop/pos/ui/screens/EditorScreen.kt`, `ui/Sheets.kt` (lembar modifier), dan `model/PosCatalog.kt` agar membaca katalog dari `PosStore` (bukan objek statis); keadaan gagal memuat menampilkan pesan + Coba lagi, bukan daftar kosong
- [X] T024 [US1] [Verifikasi US1] Jalankan backend dev (quickstart §1), seed staf (T003), pasang APK ke emulator `wjm_tablet`, selesaikan penyiapan terminal, masuk sebagai Rina, ubah harga/habis di back office dan buktikan muncul ≤ 10 dtk; kumpulkan tangkapan layar + `adb logcat -d -s AndroidRuntime:E` kosong; catat hasil di `specs/048-wire-android-pos-api/evidence-us1.md`

---

## Phase 4: User Story 2 - Satu order dari meja sampai lunas (P1) 🎯 MVP

**Tujuan**: Denah nyata, order dengan pajak/antrean/struk dari server, kirim dapur, bayar tunai, aman diulang.


- [X] T025 [US2] [Server] Migrasi `database/migrations/2026_12_21_000002_add_pos_columns_to_orders.php` (`tax_amount`, `tax_rate_snapshot`, `receipt_no` unik lintas mode, `queue_no`, `guest_count`, `prebill_at`, `prebill_count`, `device_id`) dan perbarui `app/Models/Order.php` (casts, bukan fillable untuk `receipt_no`/`queue_no`/`tax_*`)
- [X] T026 [P] [US2] [Server] Buat `app/Support/TaxCalculator.php` (pajak eksklusif setelah diskon, pembulatan per order, tarif dari `PosSettings`) dan uji `tests/Feature/TaxCalculatorTest.php` (tarif 0 = tak berubah, 10% atas Rp 60.000, diskon sebelum pajak, pembulatan)
- [X] T027 [US2] [Server] Terapkan pajak di `app/Services/TabService.php::recalculate`, `app/Services/PaymentSummary.php`, dan jalur total `OrderService`/laporan penjualan agar semua memakai total yang sama; tulis `tests/Feature/TabTaxTest.php` dan jalankan ulang `TabLinesTest`, `TabLifecycleTest`, `TabSettlementTest`, `SalesTotalsCharacterizationTest` untuk membuktikan instalasi tarif 0 tidak berubah
- [X] T028 [P] [US2] [Server] Buat `app/Services/ReceiptNumbering.php` (nomor struk per terminal `T01-000124` dengan kunci baris `terminal_counters`; antrean `A-024` per outlet per hari bisnis lewat `BusinessDay`) dan uji `tests/Feature/ReceiptNumberingTest.php` (urut tanpa celah, unik, dua alokasi serentak tak bentrok)
- [X] T029 [US2] [Server] Perluas `TabService::open` dan `app/Http/Requests/OpenTabRequest.php`: `guest_count` 1–99, `device_id` diturunkan dari header `X-Device-Id` yang divalidasi milik outlet pengguna, `queue_no` untuk order counter; alokasikan `receipt_no` saat order lunas di transaksi yang sama; uji di `tests/Feature/TabOpenPosFieldsTest.php`
- [X] T030 [US2] [Server] Tambahkan `POST /tabs/{order}/prebill` (`app/Http/Controllers/Api/TabController.php`, `TabService::prebill`), perluas `app/Http/Resources/TabResource.php` (`tax_amount`, `tax_rate`, `queue_no`, `receipt_no`, `guest_count`, `prebill_at`, `device`), `app/Support/FloorQuery.php` (`guest_count`, `bill_printed`, `device_code`; `version` tetap bebas jam), dan `OrderController::receipt` (`receipt_no`, `queue_no`, `tax`, `copy_no`); uji `tests/Feature/TabPrebillTest.php`, `FloorPosFieldsTest.php`
- [X] T031 [P] [US2] [Server] Perluas `GET /settings/features` di `app/Http/Controllers/Api/SettingsController.php` (`tax_rate`, `tax_label`, `variance_tolerance`, `cash_out_approval_threshold`) dan uji `tests/Feature/SettingsFeaturesPosTest.php` (terbaca semua peran, tanpa data sensitif)
- [X] T032 [US2] [Server] Perbarui `docs/openapi-pos-mvp.yaml`: tambahkan `/tabs*`, `/floor`, `/tickets*`, `/ticket-items*`, `/stations*`, `/dining-tables*`, `/modifier-groups*`, `/devices*`, `/staff/roster`, `/auth/pin-*`, `/auth/token-refresh`, prebill, dan bidang baru; kurangi daftar pengecualian di `tests/Support/openapi-known-gaps.php`; pastikan `OpenApiCoverageTest` lulus
- [X] T033 [US2] Implementasikan denah di `RemoteBackend` (`GET /floor?version=`) dengan `FloorPoller` (±3 dtk, hanya saat Meja/Order terlihat) di `android-pos/app/src/main/java/com/warkop/pos/data/FloorPoller.kt` dan `core-network/.../dto/FloorDtos.kt`; ganti `TablesScreen` agar area, meja, status, jumlah tamu, total, dan 'perangkat lain' berasal dari snapshot server
- [X] T034 [US2] Implementasikan pembukaan order di `RemoteBackend`/`PosStore`: pilih jumlah tamu → `POST /tabs` dengan `local_ref` UUID per niat dan `guest_count`; order counter mendapat `queue_no` dari server; meja terisi oleh perangkat lain menampilkan konflik 409 dengan pesan server
- [X] T035 [US2] Implementasikan baris order di `RemoteBackend`: `POST/PATCH/DELETE /tabs/{id}/items` dengan header `Idempotency-Key` (satu UUID per niat, dipakai ulang saat coba-ulang), `modifier_option_ids` dari pilihan, dan catatan; total/pajak/diskon dari jawaban server menimpa angka sementara; gabungan baris identik mengikuti aturan server, bukan klien
- [X] T036 [US2] Implementasikan kirim ke dapur (`POST /tabs/{id}/send` dengan `Idempotency-Key`) dan tampilkan status kirim/`prep_status`/stasiun dari `TabResource` pada panel order di `ui/screens/EditorScreen.kt`; status cetak ditampilkan 'belum tersedia' sampai modul printer ada (asumsi spec)
- [X] T037 [US2] Implementasikan pembayaran tunai: `POST /tabs/{id}/payments` dengan `client_ref` UUID per niat; timeout → status 'tidak diketahui' → `GET /tabs/{id}` mencari pembayaran ber-`client_ref` sebelum mengulang; layar `DoneScreen` di `ui/screens/TenderScreen.kt` memakai kembalian, `receipt_no`, `queue_no` dari server; tombol Bayar menolak bila shift tak terbuka (pesan server `shift_required`)
- [ ] T038 [US2] Implementasikan struk dan daftar order: `GET /orders/{id}/receipt` untuk `ReceiptDialog` di `ui/Sheets.kt`, dan `GET /tabs` + `GET /orders` untuk `ui/screens/OrdersScreen.kt` (tab Terbuka/Selesai dengan detail dari server) — sebagian: daftar penjualan & struk dari server; nomor di daftar masih nomor order (lihat evidence-us2.md)
- [ ] T039 [US2] Buat komponen keadaan galat bersama (pesan ramah + Coba lagi, indikator offline, penonaktifan aksi tulis yang dijelaskan) di `android-pos/app/src/main/java/com/warkop/pos/ui/ErrorState.kt` dan pakai di layar Meja, Editor, Bayar, Order; 503 `schema_outdated` → pesan 'Server perlu diperbarui' — sebagian: keadaan galat di denah, PIN, dan katalog; daftar Order dan layar bayar masih memakai toast
- [ ] T040 [US2] [Verifikasi US2] Jalankan skrip S1 quickstart: order meja 3 (2 tamu) dengan item bermodifier wajib, kirim dapur, bayar tunai Rp 100.000; bandingkan order/baris/tiket/pembayaran/stok dan total ke rupiah dengan back office (SC-003); putuskan jaringan emulator saat bayar lalu pulihkan dan buktikan tepat satu pembayaran (SC-004); ukur siklus < 90 dtk (SC-001); simpan bukti di `specs/048-wire-android-pos-api/evidence-us2.md` — SEBAGIAN: sudah diperiksa counter tanpa modifier + bayar tunai + putus jaringan saat bayar; BELUM: meja dengan modifier wajib, perbandingan ke back office (SC-003), kirim tab meja. Lihat evidence-us2.md

---

## Phase 5: User Story 3 - Shift, kas, dan laporan nyata (P2)

**Tujuan**: Modal per pecahan, kas masuk/keluar, X-report, tutup buta; seluruhnya dari server.


- [X] T041 [US3] [Server] Migrasi `database/migrations/2026_12_22_000001_create_cash_movements_and_shift_columns.php` (`cash_movements`, `session_opening_denominations`, kolom penutupan pada `cashier_sessions` per data-model.md) dan model `app/Models/CashMovement.php` (`HasDataMode`, `BelongsToOutlet`, terdaftar di `OutletScopeRegistry`) — migrasi `2026_12_22_000001`, model `CashMovement` + `SessionOpeningDenomination`; `outlet_id`/`data_mode` dari trait; `override_id` tanpa FK sampai US4
- [X] T042 [US3] [Server] Ubah `POST /sessions` (`app/Http/Controllers/Api/CashierSessionController.php`, `app/Http/Requests/OpenSessionRequest.php`) agar menerima `opening_denominations[]` dan menurunkan `opening_cash` dari jumlahnya (tetap menerima `opening_cash` lama); uji `tests/Feature/SessionOpeningDenominationsTest.php` — `opening_denominations[]` menurunkan `opening_cash` di server; `opening_cash` lama tetap; uji `SessionOpeningDenominationsTest` (9)
- [X] T043 [US3] [Server] Buat `app/Services/ShiftReportService.php` (satu perhitungan untuk `summary()`, X-report, dan penutupan: tunai diterima menurut `payments.session_id`, kembalian dikurangi, kas masuk/keluar, void/batal, rincian per metode) dan alihkan `CashierSessionController::summary()` ke sana; uji karakterisasi `tests/Feature/ShiftReportParityTest.php` agar angka lama tak berubah — `ShiftReportService` (satu rumus expected cash untuk summary, X-report, Z); karakterisasi `ShiftReportParityTest` (3)
- [X] T044 [US3] [Server] Buat `app/Services/CashMovementService.php` + `CashMovementController` + `RecordCashMovementRequest` (masuk/keluar, alasan wajib, jumlah > 0, hanya shift terbuka milik pengguna, ambang persetujuan dibaca dari `PosSettings` — sebelum S3 selesai, kas keluar di atas ambang dijawab 409 `override_required`) dan rute `POST/GET /sessions/{id}/cash-movements`; uji `tests/Feature/CashMovementTest.php` — `CashMovementService` (kunci sesi, alasan per arah, ambang → 409 `override_required`) + `POST/GET /sessions/{id}/cash-movements` (Idempotency-Key); uji `CashMovementTest` (9)
- [X] T045 [US3] [Server] Tambahkan `GET /sessions/{id}/x-report` (dihitung langsung, tidak disimpan) dan perluas `POST /sessions/{id}/close` (`counted_by_denomination`, `edc_batch_total`, toleransi dari `PosSettings`, 409 `pending_payments` bila ada QRIS tertunda, 409 `override_required` bila |selisih| > toleransi, simpan `z_report`); uji `tests/Feature/ShiftCloseBlindCountTest.php` dengan 10 skenario hitung manual (SC-007) — `GET /sessions/{id}/x-report` (tidak tersimpan) + `close` blind count (`counted_by_denomination`, `pending_payments`, `override_required` > toleransi, `z_report`); uji `ShiftCloseBlindCountTest` (14, termasuk 10 skenario tangan)
- [X] T046 [US3] [Server] Perbarui `docs/openapi-pos-mvp.yaml` untuk rute S2 dan kurangi pengecualian di `tests/Support/openapi-known-gaps.php` — OpenAPI untuk x-report, cash-movements, close/open baru; `OpenApiCoverageTest` lulus
- [X] T047 [US3] Implementasikan di `RemoteBackend`/`PosStore`: buka shift dengan pecahan (`OpenShiftScreen`), kas masuk/keluar (`CashOutSheet`), X-report (`XReportScreen`), pusat shift (`ShiftHubScreen` dari ringkasan server, termasuk grafik per jam dan komposisi pembayaran yang dihitung server), tutup shift buta + hasil (`CloseShiftScreen`, `CloseResultScreen`); hilangkan semua angka tetap/contoh di `ui/screens/ShiftScreens.kt` — KODE SELESAI dan membangun (core-network 29/29 lulus). Verifikasi emulator (T048) BELUM lulus; lihat evidence-us3.md.
- [X] T048 [US3] [Verifikasi US3] Skrip S2 quickstart di emulator: modal Rp 150.000, 2 penjualan tunai, 1 kas keluar, X-report, tutup buta; 'seharusnya' cocok hitungan manual; pembayaran tanpa shift ditolak; simpan bukti di `specs/048-wire-android-pos-api/evidence-us3.md` — SELESAI dengan satu catatan: langkah 4c (pembayaran tanpa shift → shift_required) disetujui pengguna sebagai tercakup oleh ShiftRequiredTest + guard klien (tidak bisa dicapai di perangkat karena tutup shift ditolak selama ada tab terbuka). Bukti: evidence-us3.md.

---

## Phase 6: User Story 4 - Persetujuan atasan dan non-tunai (P2)

**Tujuan**: OverrideGate sekali pakai terikat aksi; EDC; QRIS tertunda → verifikasi; void terkirim; batal order; 86 dengan persetujuan.


- [X] T049 [US4] [Server] Migrasi `database/migrations/2026_12_23_000001_create_overrides_and_payment_edc.php` (`overrides` per data-model.md; `payments.approval_code`; nilai metode `edc`) dan model `app/Models/Override.php` (`HasDataMode`, terdaftar di `OutletScopeRegistry`) — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T050 [US4] [Server] Perluas `app/Support/PermissionCatalog.php` dengan izin penyetuju (`order.void`, `order.cancel`, `cash.out.approve`, `order.reprint.approve`, `payment.qris.verify`, `order.refund`, `menu.availability.set`, `shift.variance.approve`, `discount_limit_pct`) dan tambahkan ke seeder peran di `WarkopPosStaffSeeder.php`; uji bawaan peran di `tests/Feature/RolePermissionCatalogTest.php` — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T051 [US4] [Server] Buat `app/Services/OverrideGate.php` (issue: verifikasi PIN penyetuju dengan kunci 5-salah yang sama, penyetuju ≠ peminta, satu outlet, memegang izin aksi, token 120 dtk terikat `subject_hash`; consume: sekali pakai, hash cocok, belum kedaluwarsa; audit di transaksi yang sama) dan `app/Http/Controllers/Api/OverrideController.php` (`POST /overrides`, `GET /overrides/approvers`); uji `tests/Feature/OverrideGateTest.php` mencakup setiap kode galat — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T052 [US4] [Server] Wajibkan `override_token` pada aksi sensitif lewat satu helper (`OverrideGate::requireFor`) di: hapus/void baris terkirim (`TabLineController::destroy` + `reason_code`, `kitchen_status`, `stock_decision`, kembalikan stok sesuai keputusan lewat `StockService`), `POST /tabs/{id}/cancel` (blok bila ada pembayaran), kas keluar di atas ambang, tutup shift dengan selisih, `POST /products/{id}/sold-out`; satu berkas uji per aksi `tests/Feature/Override*Test.php` membuktikan tak dapat dijalankan tanpa persetujuan sah (SC-006) — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T053 [US4] [Server] Metode EDC dan QRIS di `app/Services/PaymentService.php`/`PaymentRecorder.php` (EDC: `approval_code` ^[A-Z0-9]{6,12}$, tanpa nomor kartu, deteksi ganda dalam `edc_duplicate_window_seconds` → 409 `duplicate_edc` kecuali `confirm_duplicate`; QRIS: disimpan `pending`, memblokir non-tunai lain 409 `pending_qris`; non-tunai tak boleh melebihi sisa) dan perluas `POST …/verify` (`reference` ≥ 4, `override_token`) + tambah `POST …/fail`; uji `tests/Feature/PaymentEdcQrisTest.php` — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T054 [US4] [Server] Perbarui `docs/openapi-pos-mvp.yaml` untuk rute dan galat S3 dan kurangi pengecualian di `tests/Support/openapi-known-gaps.php` — SELESAI (server, commit 7a9979e). Subjek memuat id objek (koreksi tinjauan).
- [X] T055 [US4] Implementasikan dialog persetujuan di aplikasi: `Sheet.Override` memanggil `GET /overrides/approvers` dan `POST /overrides`, menyimpan `override_token` hanya di memori untuk satu aksi, lalu mengulang aksi dengan token; hilangkan perbandingan PIN '000000' di `PosStore.approve`; tangani `invalid_pin`/`same_person`/`override_expired` di `ui/Sheets.kt` — KODE SELESAI (dialog persetujuan, PIN hanya di POST, token hanya di memori satu aksi). Verifikasi emulator di T057.
- [ ] T056 [US4] Implementasikan di aplikasi: void baris terkirim (alasan, status dapur, keputusan stok), batal order, tandai habis dengan persetujuan, pembayaran EDC (kode approval, konfirmasi ganda), QRIS catat tertunda + verifikasi/tidak ditemukan, pemblokiran non-tunai/tutup shift oleh QRIS tertunda, pada `PosStore`, `ui/Sheets.kt`, `ui/screens/TenderScreen.kt`
- [ ] T057 [US4] [Verifikasi US4] Skrip S3 quickstart di emulator: void terkirim disetujui Sari, PIN Rina sendiri sebagai penyetuju ditolak, QRIS tertunda memblokir EDC dan tutup shift, EDC ganda < 2 menit meminta konfirmasi; simpan bukti di `specs/048-wire-android-pos-api/evidence-us4.md`

---

## Phase 7: User Story 5 - Pajak, diskon, bagi bill, pra-bill, struk resmi (P3)

**Tujuan**: Diskon beralasan dengan batas peran, bagi per item/rata, cetak ulang SALINAN n, refund penuh.


- [ ] T058 [US5] [Server] Buat `POST/DELETE /tabs/{id}/discounts` (`app/Http/Controllers/Api/TabDiscountController.php`, `app/Services/DiscountService.php`: persen, alasan wajib, lingkup order/baris, batas `discount_limit_pct` per peran, di atas batas tanpa token → 409 `override_required`, diskon dihitung sebelum pajak lewat `TaxCalculator`); uji `tests/Feature/TabDiscountTest.php`
- [ ] T059 [P] [US5] [Server] Perluas `POST /tabs/{id}/split` dengan `mode: equal, parts` (2–5, bagian per orang dari total termasuk pajak, sisa pembulatan ke bagian terakhir, tanpa pembayaran) di `app/Http/Controllers/Api/TabPaymentController.php`; uji `tests/Feature/TabSplitEqualTest.php`
- [ ] T060 [P] [US5] [Server] Buat `POST /orders/{id}/reprints` (`app/Services/ReprintService.php`: salinan pertama gratis menurut `reprint_free_copies`, berikutnya wajib `override_token`, menambah `copy_no`) dan `POST /orders/{id}/refund` (`app/Services/RefundService.php`: order lunas, online saja, token wajib, kembalikan stok lewat `StockService`, balikkan kas tunai shift, tidak menyentuh laporan lama); uji `tests/Feature/ReprintTest.php`, `RefundTest.php`
- [ ] T061 [US5] [Server] Perbarui `docs/openapi-pos-mvp.yaml` untuk rute S4 dan kosongkan `tests/Support/openapi-known-gaps.php`
- [ ] T062 [US5] Implementasikan di aplikasi: diskon order/baris (`DiscountSheet`, `LineActionsSheet`), bagi per item dan rata (`SplitSheet`), pra-bill (`PosStore.printPrebill` → `POST /tabs/{id}/prebill`), cetak ulang dan refund di `OrdersScreen`, semuanya memakai angka server
- [ ] T063 [US5] [Verifikasi US5] Order Rp 60.000 pajak 10% → total Rp 66.000 identik di tablet, struk, dan laporan back office; uji diskon di atas batas, bagi bill, cetak ulang ke-2 butuh persetujuan; simpan bukti di `specs/048-wire-android-pos-api/evidence-us5.md`

---

## Phase 8: User Story 6 - Perangkat, koneksi, dan ketahanan (P3)

**Tujuan**: Heartbeat, layar Perangkat nyata, ketahanan jaringan dan sesi.


- [ ] T064 [US6] [Server] Buat `POST /devices/heartbeat` (`DeviceController::heartbeat`, `DeviceService`: simpan `app_version`, `last_seen_at`, `clock_skew_seconds`, kembalikan `server_time_ms`, `skew_seconds`, rilis menu); uji `tests/Feature/DeviceHeartbeatTest.php`
- [ ] T065 [US6] Implementasikan heartbeat 60 dtk di aplikasi (`android-pos/app/src/main/java/com/warkop/pos/data/HeartbeatWorker.kt`) dan ganti angka tetap di `SyncScreen` (`ui/screens/ShiftScreens.kt`: koneksi, selisih jam, versi rilis menu, printer = 'belum tersedia') dengan nilai nyata; hapus antrean cetak/sinkron buatan di `PosStore`
- [ ] T066 [US6] Uji ketahanan di emulator: putus jaringan di tengah tiap langkah tulis (buka order, tambah baris, kirim, bayar, tutup shift), token kedaluwarsa di tengah order, staf dinonaktifkan saat sesi berjalan; buktikan tidak ada duplikat, order tidak hilang, dan kembali ke layar PIN dengan benar; simpan bukti di `specs/048-wire-android-pos-api/evidence-us6.md`

---

## Phase 9: Polish & lintas-cerita

**Tujuan**: Dokumentasi, keamanan, performa, dan verifikasi akhir 'semua layar bekerja'.


- [ ] T067 [P] Tambahkan catatan bertanggal ke `docs/PRD-POS-Event-Multivendor.md` dan `CLAUDE.md` (kapabilitas baru: aplikasi Android POS, perangkat/terminal, persetujuan di tempat, pajak eksklusif, penomoran struk/antrean) tanpa menulis ulang riwayat
- [ ] T068 [P] Tambahkan bagian 'Android POS' ke `docs/RUNBOOK.md` (menyiapkan terminal, 10.0.2.2 untuk emulator, membangun APK, rotasi/pencabutan perangkat, cara membaca log) dan perbarui `specs/047-android-pos-tablet/api-gap.md` (tandai B2–B14, B17–B19, B21 selesai)
- [ ] T069 Audit keamanan sisi klien: pastikan tidak ada URL/kredensial di `BuildConfig`/repo, token hanya di `TokenVault`, log tidak memuat `Authorization`/PIN/nomor kartu (`grep` + uji `android-pos/core-network/src/test/java/LogRedactionTest.kt`), dan `SampleBackend`/preset tidak dapat aktif di build rilis (`./gradlew :app:assembleRelease` + periksa manifes)
- [ ] T070 [P] Audit performa: tidak ada N+1 pada resource/endpoint baru (hitung kueri di `tests/Feature/*Performance*Test.php` mengikuti pola `TabPerformanceTest`), cache denah/menu dikunci mode data + outlet + pengguna, polling berhenti di latar
- [ ] T071 Jalankan seluruh suite backend melawan MySQL (`php artisan test`, bukan di dalam container tanpa `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test`) dan perbaiki regresi; catat hasil di `specs/048-wire-android-pos-api/evidence-final.md`
- [ ] T072 Verifikasi akhir 'semua layar bekerja' (/goal): jalankan seluruh 28 layar tablet dan 8 layar telepon terhadap server nyata di emulator, bandingkan dengan `design/screens`, pastikan `logcat` bebas `AndroidRuntime` error dan tak ada data contoh; selesaikan sisa selisih visual yang tercatat di `specs/047-android-pos-tablet/design-fidelity.md` (ikon kunci chip offline, lingkaran dekoratif antrean, tablet 8"/13")

---

## Dependencies & Execution Order

### Ketergantungan antar-fase
- **Setup (T001–T004)** → tanpa ketergantungan; mulai segera.
- **Foundational (T005–T016)** → bergantung Setup; **memblokir semua cerita** (perangkat server, pengaturan, lapisan jaringan, Keystore, refaktor `PosStore` ke `PosBackend`).
- **US1 (T017–T024)** → bergantung Foundational. Irisan terkecil yang bernilai.
- **US2 (T025–T040)** → bergantung US1 (butuh sesi PIN dan menu). Bagian server US2 (migrasi, pajak, penomoran) **dapat dikerjakan paralel dengan US1** karena berkas berbeda.
- **US3 (T041–T048)** → bergantung US2 (butuh order dan pembayaran untuk angka shift).
- **US4 (T049–T057)** → bergantung US2; bagian "override_token wajib" juga menyentuh aksi US3 (kas keluar, tutup shift) — kerjakan setelah US3 agar tidak berebut `CashMovementService`.
- **US5 (T058–T063)** → bergantung US2 (pajak) dan US4 (`OverrideGate` untuk diskon/cetak ulang/refund).
- **US6 (T064–T066)** → bergantung Foundational (perangkat); dapat paralel dengan US3–US5.
- **Polish (T067–T072)** → setelah cerita yang dikirim.

### Di dalam tiap cerita
- Migrasi → model → service → controller/rute → uji → OpenAPI → klien Android → verifikasi emulator.
- Uji server ditulis bersama implementasinya dan HARUS lulus di MySQL sebelum klien memakainya.
- Verifikasi emulator (tugas terakhir tiap cerita) menjadi syarat cerita dinyatakan selesai.

## Peluang paralel
- Foundational: model `Device`, `PosSettings`, kontrak `PosBackend`, klien HTTP, `TokenVault`, uji JVM berada di berkas berbeda.
- US1 klien (penyiapan terminal, PIN, menu) vs US2 server (pajak, penomoran, prebill) — dua orang/agen dapat bekerja bersamaan.
- US6 (heartbeat) paralel dengan US3–US5 setelah Foundational.
- Contoh: `T006, T009, T010, T011, T012, T016` (Foundational) dikerjakan bersamaan, lalu `T015` (refaktor `PosStore`) setelah `T014` (`SampleBackend`) dan `T010` (`PosBackend`) selesai.

## Strategi implementasi

### MVP dulu (US1 + US2)
1. Setup → Foundational.
2. US1: penyiapan terminal, PIN asli, menu asli → **verifikasi emulator**.
3. US2: denah, order, kirim dapur, bayar tunai, struk → **verifikasi emulator (SC-001, SC-003, SC-004)**.
4. BERHENTI dan demonstrasikan: aplikasi sudah dapat berjualan tunai dengan data nyata.

### Pengiriman bertahap
S1 (US1+US2) → S2 (US3) → S3 (US4) → S4 (US5+US6) → Polish. Tiap irisan menyertakan uji server, OpenAPI, klien, dan bukti emulator sebelum lanjut.

## Catatan
- Task "[Verifikasi USn]" adalah bukti, bukan formalitas: tangkapan layar emulator, `logcat` bebas error `AndroidRuntime`, dan hasil `php artisan test` ditempel di berkas `evidence-*.md` (Konstitusi II).
- Versi pustaka yang bertanda *unverified* di plan/research ditetapkan pada T001 dan dicatat di `libs.versions.toml`.
- Mode offline penuh, antrean cetak ke server, dan printer fisik DI LUAR cakupan (spec Assumptions).
