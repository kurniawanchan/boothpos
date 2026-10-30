---
description: "Task list for 084 — Perbaikan UI/UX Kasir, Pesanan, Shift, dan Koneksi"
---

# Tasks: Perbaikan UI/UX Kasir, Pesanan, Shift, dan Koneksi

Label: INTERNAL

**Input**: `specs/084-ui-ux-kasir-pesanan-improvements/` — plan.md, spec.md, research.md (D1–D13), data-model.md, contracts/api-changes.md, quickstart.md

**Tests**: DIMINTA oleh Konstitusi II (backend di MySQL nyata, `qa-tests/` untuk web, JUnit JVM untuk Android). Tes ditulis dulu dan harus gagal sebelum implementasi.

**Organization**: dikelompokkan per user story (US1–US6) agar tiap cerita bisa dikerjakan dan diuji sendiri.

## Konvensi

- `APOS/` = `android-pos/app/src/main/java/com/warkop/pos/`
- `ATEST/` = `android-pos/app/src/test/java/com/warkop/pos/`
- Perintah tes backend di dalam kontainer WAJIB: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=...` (lihat CLAUDE.md; tanpa itu DB dev terhapus).
- Komentar kode, teks UI, dan pesan commit berbahasa Indonesia; komentar menjelaskan *mengapa*.
- Jangan menyentuh `android/` (shell WebView lama). Jangan menambah heks mentah di UI web (pakai token `@theme`).
- Tugas bertanda **[PERANGKAT]** tidak dapat dibuktikan di JVM/browser; hasilnya dicatat di `evidence.md`, tidak diklaim lulus.

## Format: `[ID] [P?] [Story] Deskripsi dengan path`

---

## Phase 1: Setup

**Purpose**: kondisi awal yang diperlukan sebelum menyentuh kode.

- [ ] T001 (**TIDAK DITANGKAP: garis awal sebelum perubahan tidak dijalankan; tidak ada pembanding "sebelum".**) Jalankan suite dasar sebagai garis awal dan catat hasilnya di `specs/084-ui-ux-kasir-pesanan-improvements/evidence.md`: `npm test`, backend (perintah kontainer di atas, penuh), dan `cd android-pos && ./gradlew :app:testDebugUnitTest` (catat bila gradle/JDK tidak tersedia). Catat kegagalan yang sudah ada (mis. `ShiftReportParityTest` yang diketahui) agar tidak dianggap regresi.
- [X] T002 [P] Pastikan `.env.testing` menunjuk `warkop_wjm_test` dan kontainer `warkop_wjm-mysql-1` berjalan (`docker compose ps`); jangan menyentuh kontainer/DB `boothpos-*`.

---

## Phase 2: Foundational (memblokir cerita lain)

**Purpose**: potongan murni yang dipakai lebih dari satu cerita dan keputusan data yang masih terbuka (R3).

- [X] T003 Tentukan bentuk data "jumlah varian per produk" untuk aturan "Standard": baca `APOS/model/PosModels.kt` (`Product`, `ProductEntry`, `ReceiptView`), `APOS/data/ReceiptMapper.kt`, `app/Http/Resources/` untuk resource struk, lalu tulis keputusannya (klien membawa `variantCount`, atau server mengirim `variant_label` kosong) sebagai catatan di `research.md` D4 sebelum T004.
- [X] T004 (**DIBATALKAN: aturan "Standard" dipindah ke SERVER (`ProductVariant::lineName()`, snapshot nama baris) sehingga struk web/Android/KOT ikut; kartu Android sudah menyembunyikan varian tunggal (`MenuMapper`). Lihat `tests/Feature/StandardVariantNameTest.php`.**) Tulis tes JVM dulu: `ATEST/model/VariantLabelTest.kt` — satu varian bernama "Standard" (juga " standard ", "STANDARD") → `null`; dua varian atau nama lain → nama apa adanya; nama kosong → `null`.
- [X] T005 (**DIBATALKAN: lihat T004.**) Implementasikan `APOS/model/VariantLabel.kt` (`fun shown(name: String?, variantCount: Int): String?`) sampai T004 lulus.
- [X] T006 [P] Buat `APOS/ui/LiveClock.kt`: komposabel `LiveClock(pattern, ...)` yang berdetak (`LaunchedEffect` yang menunda sampai batas detik/menit berikutnya) dan fungsi murni `nextTickDelayMs(nowMs, stepMs)`; tes `ATEST/ui/LiveClockTest.kt` untuk fungsi murninya.

**Checkpoint**: `VariantLabel` dan `LiveClock` tersedia; keputusan data "Standard" tercatat.

---

## Phase 3: User Story 1 — Kasir: modifier, Meja, QRIS, "Standard", layar awal (P1) 🎯 MVP

**Goal**: kasir login → mendarat di Kasir → pilih menu (modifier di web) → pilih/ganti Meja → bayar; QRIS langsung Terverifikasi; label "Standard" tunggal tak tampil.

**Independent Test**: login `kasir01`; tambah item bermodifier di web `PosView`, bayar, cek modifier di struk/detail Penjualan; di Android pilih Meja pada pesanan Kasir lalu cek layar Meja; bayar QRIS dan pastikan Terverifikasi tanpa langkah verifikasi.

### Tes (tulis dulu, harus gagal)

- [X] T007 [P] [US1] `tests/Feature/OrderModifierTest.php`: `POST /orders` dengan `modifier_option_ids` valid → `sell_price` baris = dasar + delta, `order_items.modifiers` berisi snapshot, `total_amount` dan pajak benar; opsi grup lain/nonaktif → 422; grup wajib kosong → 422; tanpa field → perilaku lama; klien tidak bisa menetapkan harga.
- [X] T008 [P] [US1] `tests/Feature/QrisAutoVerifyTest.php`: pembayaran kanal QRIS via `POST /tabs/{order}/payments` → `verification = verified`; kanal non-QRIS tidak berubah; baris lama `pending` tetap bisa `verify`; `POST /sessions/{id}/close` tidak terblokir oleh QRIS baru.
- [X] T009 [P] [US1] `tests/Feature/TabMoveOpenTest.php` (jika belum ada tes `move`; cek `tests/Feature/` dulu): pindah tab Kasir (counter) ke meja bebas → `table_id` terisi dan `service_type` dine_in; meja terisi → 409 `table_occupied`; beda outlet → 409 `outlet_mismatch`; meja nonaktif → 422.
- [X] T010 [P] [US1] `qa-tests/unit/posCartModifiers.test.js`: baris keranjang dikunci `variant_id` + opsi terurut (opsi berbeda → baris terpisah, sama → qty bertambah), harga tampilan = dasar + delta, increment/decrement/remove per kunci baris.
- [X] T011 [P] [US1] `ATEST/state/LandingDestTest.kt` dan `ATEST/ui/QrisTextsTest.kt` (perbarui `QrisSheetTextTest.kt` yang ada): instruksi = "Jangan lupa untuk cek bukti transaksi di aplikasi QRIS"; tujuan pasca-login/buka shift = Kasir (`Dest.EDITOR`).

### Server

- [X] T012 [US1] `app/Http/Requests/StoreOrderRequest.php`: tambah `items.*.modifier_option_ids` (`sometimes|array`, `*` integer `exists:modifier_options,id`).
- [X] T013 [US1] `app/Services/OrderService.php`: per item panggil `ModifierResolver::resolve($variant, $ids)`; pakai `unit_price` untuk `lineValue`/`sell_price` (baris ±99, 126, 212) dan simpan `snapshot` ke `order_items.modifiers`; pastikan diskon per item dan pajak (`TaxCalculator`) memakai harga baru; tangkap `InputRejectedException` → 422 seperti `TabController`.
- [X] T014 [US1] QRIS terverifikasi sejak dibuat: di `app/Services/PaymentRecorder.php` (satu-satunya pencipta `payments`) atau pemanggilnya di `app/Http/Controllers/Api/TabPaymentController.php`, set `verification = verified` untuk kanal QRIS; jangan menyentuh rute `verify`/`fail` dan aturan `pending_payments` di `CashierSessionController.php:263`. Tambahkan komentar *mengapa* (kebijakan pemilik 2026-10-30; baris warisan tetap).
- [X] T015 [US1] Perbarui `docs/openapi-pos-mvp.yaml` untuk `modifier_option_ids` pada `POST /orders` dan `verification` QRIS pada pembayaran tab (commit yang sama dengan T012–T014).

### Web (modifier)

- [X] T016 [P] [US1] `resources/js/api/orders.js` dan produk API: kirim `modifier_option_ids` per item pada `createOrder`; produk POS diminta dengan `with_modifiers=1` (lihat pola `TabView.vue:97`).
- [X] T017 [US1] `resources/js/stores/posCart.js`: kunci baris `variant_id` + opsi terurut; simpan `modifiers` dan `modifier_option_ids`; `add/increment/decrement/remove` bekerja per kunci baris (tetap kompatibel untuk varian tanpa modifier).
- [X] T018 [US1] `resources/js/views/PosView.vue`: produk yang punya grup efektif membuka `ModifierPickerModal` (`components/restaurant/ModifierPickerModal.vue`, aturan `utils/modifierRules.js`) sebelum masuk keranjang; muat modal secara dinamis bila hanya dipakai di sini (Konstitusi V).
- [X] T019 [P] [US1] `resources/js/components/pos/PosCartPanel.vue` (dan `components/payment/PosPaymentModal.vue` bila menampilkan baris): tampilkan nama modifier di bawah item, pakai token `@theme`; teks di `resources/js/locales/id.json` dan `en.json`.
- [X] T020 [US1] Verifikasi struk web dan detail Penjualan untuk pesanan dari `PosView`: `components/receipt/ReceiptModal.vue:141` dan `components/sales/OpenOrderDetail.vue:213` sudah membaca `modifiers`; tambah tes komponen di `qa-tests/component/` bila celah ditemukan.

### Android

- [X] T021 [US1] Layar awal Kasir: ubah tujuan pasca-PIN/buka shift dari `Dest.TABLES` ke Kasir (`newCounter()`/`Dest.EDITOR`) di `APOS/state/PosStore.kt` (±baris 540, 541, 615, 622); jaga alur peran yang memang harus ke tempat lain bila ada.
- [X] T022 [P] [US1] `APOS/data/PosBackend.kt` + `APOS/data/RemoteBackend.kt` (+ DTO di `android-pos/core-network`): tambah `moveTab(tabId, tableId)` → `POST /tabs/{id}/move`; peta galat 409/422 ke pesan yang ada.
- [X] T023 [US1] `APOS/state/PosStore.kt`: aksi `changeTable(tableId)` memanggil `moveTab`, memperbarui `activeId`/denah (`fetchFloorNow`), dan menampilkan pesan bila ditolak.
- [X] T024 [US1] Pemilih **Meja** di `APOS/ui/screens/EditorScreen.kt` (daftar meja aktif dan bebas + meja saat ini, bersumber dari denah); setelah memilih, `Order.label` dan layar Meja mencerminkannya.
- [X] T025 [US1] QRIS di Android: di `APOS/ui/screens/TenderScreen.kt` hapus pil "Menunggu verifikasi" dan pemblokiran non-tunai oleh QRIS baru (baris ±57–108), tombol "Verifikasi" hanya untuk tender berstatus `PENDING` warisan; `APOS/state/PosStore.kt` (±1189–1233) tidak lagi mencatat `pending` untuk QRIS baru dan pesan "QRIS dicatat · menunggu verifikasi." menjadi "QRIS dicatat."; `APOS/ui/QrisTexts.kt` `INSTRUCTION` = "Jangan lupa untuk cek bukti transaksi di aplikasi QRIS" (perbarui komentar kelasnya).
- [X] T026 [US1] Terapkan `VariantLabel.shown` di kartu produk, keranjang, dan baris pesanan Android: `APOS/ui/screens/EditorScreen.kt`, `APOS/ui/Sheets.kt` (±746), `APOS/data/MenuMapper.kt`; struk lewat `APOS/data/ReceiptMapper.kt` + `APOS/print/Documents.kt` (`struk`, `prebill`) sesuai keputusan T003.
- [X] T027 [US1] Pastikan modifier tampil konsisten di Android: baris keranjang (`EditorScreen.kt`), ringkasan Tender (`TenderScreen.kt`), detail Pesanan (`OrdersScreen.kt`), struk (`Documents.struk` sudah mencetak `line.mods` — tambahkan tes `ATEST/print/DocumentsModifiersTest.kt`).
- [ ] T028 [US1] **[PERANGKAT]** uji manual di tablet: mendarat di Kasir, pemilih Meja, QRIS tanpa verifikasi; catat di `evidence.md`.

**Checkpoint**: US1 berdiri sendiri; web PosView bermodifier lengkap, Android Kasir sesuai.

---

## Phase 4: User Story 2 — Pesanan: info lengkap, ubah Meja, bersih saat tutup shift (P2)

**Goal**: pesanan Selesai menampilkan rincian penuh; Meja bisa diubah untuk Terbuka dan Selesai dan sinkron dengan layar Meja; pesanan shift yang ditutup hilang dari daftar aktif.

**Independent Test**: buka pesanan Selesai (semua rincian tampil); ubah meja pesanan Terbuka dan Selesai lalu lihat layar Meja; tutup shift dan cek daftar Pesanan kosong tanpa mengubah Penjualan.

### Tes (tulis dulu)

- [X] T029 [P] [US2] `tests/Feature/TabMoveClosedTest.php`: `move` pada pesanan selesai mengubah hanya `table_id` (tanpa cek meja bebas, `service_type` tetap), outlet sama diwajibkan (409), meja nonaktif 422, void/batal tetap 409, log aktivitas `table_changed` ada di transaksi yang sama, peran/outlet tak berhak → 403/404 (periksa `MoveTabRequest::authorize`, kontroler, kebijakan).
- [X] T030 [P] [US2] `tests/Feature/ClosedTabsSessionScopeTest.php`: `GET /tabs?status=closed&session_id=X` hanya pesanan shift X; tanpa `session_id` perilaku lama; pesanan outlet lain tidak muncul (OutletScope); total Penjualan/laporan tidak berubah setelah shift ditutup.
- [X] T031 [P] [US2] `ATEST/state/ShiftCloseClearTest.kt`: setelah tutup shift `closedCache`/daftar aktif kosong; setelah buka shift terbaca ulang.

### Server

- [X] T032 [US2] `app/Services/TabService.php::move()` (±549): terima pesanan berstatus selesai (lihat konstanta status `Order::STATUS_*`), lewati `assertTableFree` dan jangan ubah `service_type` untuk selesai, tulis `ActivityLogger` `table_changed` di dalam `DB::transaction` yang sama; pesanan dibatalkan/void tetap `TabConflictException`.
- [X] T033 (**TIDAK PERLU: daftar Selesai Android memakai `GET /orders` yang SUDAH mendukung `session_id`; tidak ada perubahan server.**) [US2] `app/Http/Controllers/Api/TabController.php` (±baris 30–55): terapkan filter `session_id` juga pada daftar selesai (atau verifikasi sudah ada; bila sudah, tambahkan hanya tes T030); eager-load relasi yang dibaca `present()` (jangan hilang diam-diam).
- [X] T034 [US2] Perbarui `docs/openapi-pos-mvp.yaml` untuk `move` pesanan selesai dan `session_id` pada daftar selesai.

### Android

- [X] T035 [US2] `APOS/ui/screens/OrdersScreen.kt` (detail Selesai ±70–82): tampilkan nomor, Meja/Kasir, kasir, jam, item + modifier + catatan, subtotal, diskon, pajak (hanya bila `tax_amount > 0` dan `tax_show`, lewat helper pajak yang ada), total, pembayaran per metode, cetak ulang; sumber data `APOS/data/OrderMapper.kt` — tambahkan field yang belum dipetakan.
- [X] T036 [US2] Pemilih Meja pada detail pesanan Terbuka dan Selesai di `OrdersScreen.kt` memakai `changeTable` (T023); setelah ubah, denah dan daftar disegarkan.
- [X] T037 [US2] `APOS/state/PosStore.kt` (`closedOrders` ±285, `closedCache` ±1406, tutup/buka shift ±600–625): saat shift ditutup kosongkan `closedCache` dan daftar terbuka milik shift itu; baca daftar selesai dengan `session_id` shift terbuka; pesanan Terbuka saat tutup shift mengikuti aturan server yang ada (jangan diubah).
- [ ] T038 [US2] **[PERANGKAT]** uji manual: rincian Selesai, ubah meja, tutup shift; catat di `evidence.md`.

**Checkpoint**: US1 dan US2 berfungsi tanpa saling bergantung.

---

## Phase 5: User Story 3 — Tutup shift: kursor benar + cetak X/Z (P2)

**Goal**: isian uang tidak membuat kursor melompat; Laporan X dan Z tercetak.

**Independent Test**: ketik/sunting/hapus/tempel nominal >3 digit; cetak X dan Z (shift berjalan dan sudah ditutup).

### Tes (tulis dulu)

- [X] T039 [P] [US3] `ATEST/state/CountInputOffsetTest.kt`: fungsi pemetaan offset tampilan↔digit untuk "1.234.567" (kursor di akhir tetap di akhir; menghapus di tengah; menempel "1.500.000" → 7 digit; kosong; batas 9 digit; nol di depan).
- [X] T040 [P] [US3] `ATEST/print/ShiftDocumentsTest.kt`: `Documents.xReport` dan `Documents.zReport` memuat judul, outlet, kasir, jam buka/tutup, penjualan, baris "Tunai", "EDC", "QRIS", kas masuk/keluar, dan (Z) selisih kas; tanpa karakter non-ASCII mentah; label lama ("Transfer", "Tunai bersih") tidak muncul.

### Implementasi

- [X] T041 [US3] `APOS/state/CountInput.kt`: tambah `ThousandsOffsetMapping` murni (tampilan↔digit) tanpa mengubah `typed`/`total`/`MAX_DIGITS`; komentar *mengapa* (kursor melompat karena nilai String berformat ditulis ulang, `CountEntry.kt:58`).
- [X] T042 [US3] `APOS/ui/CountEntry.kt` (±58): ganti `value = input.typedDisplay` dengan `TextFieldValue` + `VisualTransformation` memakai T041, sehingga kursor tetap di posisi digit saat mengetik/menyunting/menghapus/menempel.
- [X] T043 [US3] `APOS/print/Documents.kt`: tambah `xReport(...)` dan `zReport(...)` mengikuti pola `struk`/`prebill` (58 mm, kolom `PrintLine`), memakai `ShiftReport`/hasil tutup dari `APOS/model/PosModels.kt` dan `APOS/data/ShiftMapper.kt`.
- [X] T044 [US3] `APOS/ui/screens/ShiftScreens.kt` (±187, ±296): tombol "Cetak X-report" dan cetak Z mengirim dokumen ke `PrintQueue` (pola cetak struk di `APOS/state/PosStore.kt`/`ReprintRules.kt`), menampilkan galat/ulang bila printer tak tersambung; hapus pesan "Cetak belum tersedia".
- [ ] T045 [US3] **[PERANGKAT]** uji manual di tablet + TM-58V: kursor, cetak X, cetak Z; catat di `evidence.md`.

**Checkpoint**: tutup shift dan laporan siap dipakai.

---

## Phase 6: User Story 4 — Struk tanpa "?" dan status Koneksi printer (P2)

**Goal**: karakter non-ASCII tercetak terbaca; layar Koneksi menunjukkan Menyambung/Tersambung/Terputus/Gagal dengan indikator progres.

**Independent Test**: cetak struk bernama menu ber-aksen/tanda kutip tipografis; putuskan dan sambungkan printer sambil melihat status.

### Tes (tulis dulu)

- [X] T046 [P] [US4] `ATEST/print/EscPosTranslitTest.kt`: kutip tipografis → `'`/`"`, tanda hubung panjang → `-`, `…` → `...`, `·` → `-`, `×` → `x`, "é"/"ü" → "e"/"u", emoji/CJK → dibuang; tidak pernah menghasilkan `?` dari masukan tanpa `?`; `?` asli di masukan tetap.
- [ ] T047 (**PARTIAL: dipenuhi `PrinterStatusTextTest` (nama keadaan + progres, murni). Transisi `connect()` memakai Bluetooth dan tak teruji di JVM — butuh perangkat (T051).**) [P] [US4] `ATEST/print/PrinterStatusTest.kt`: `connect()` memancarkan `Connecting` lebih dulu, lalu `Connected`; kegagalan/timeout → `Error` (label "Gagal tersambung"); putus → `Disconnected`.

### Implementasi

- [X] T048 [US4] `APOS/print/EscPos.kt` (±47): ganti `else '?'` dengan fungsi transliterasi murni (tabel eksplisit → NFD buang diakritik → buang sisanya); komentar *mengapa* (printer 58 mm tanpa code page yang tervalidasi; lihat research D5).
- [X] T049 [US4] `APOS/print/PrinterService.kt` (±32–88): tambah `PrinterStatus.Connecting`; set sebelum soket dibuka; batas waktu → `Error`; pastikan `status` StateFlow berubah ≤2 dtk.
- [X] T050 [US4] `APOS/ui/screens/PrinterScreen.kt` (+ `PrinterPanel` yang dipanggil `ShiftScreens.kt` ±323): tampilkan `LinearProgressIndicator` tak tentu saat `Connecting` dan teks status "Menyambung" / "Tersambung" / "Terputus" / "Gagal tersambung".
- [ ] T051 [US4] **[PERANGKAT]** uji manual dengan printer: karakter, status koneksi; catat di `evidence.md`.

**Checkpoint**: cetak dan status printer terbaca.

---

## Phase 7: User Story 5 — Navbar, istilah baku, navigasi (P3)

**Goal**: navbar baru; istilah seragam; menu berurutan Kasir, Meja, Pesanan, Shift, Koneksi.

**Independent Test**: periksa navbar (teks, nama, jam berdetak, hamburger) dan seluruh teks layar terhadap tabel istilah.

### Tes (tulis dulu)

- [X] T052 [P] [US5] `ATEST/ui/TerminologyScanTest.kt` (pola tes pemindai sumber repo): pindai string literal di `APOS/ui/`, `APOS/print/`, `APOS/model/`, `APOS/state/`; gagal bila memuat "Counter", "Order ", "Perangkat", "Transfer", atau "Tunai bersih" sebagai teks tampilan (pengecualian: pengenal kode `OrderKind.COUNTER`, `Dest.SYNC`, nama kolom `bank_transfer`, komentar).
- [X] T053 [P] [US5] `ATEST/ui/NavOrderTest.kt`: `NAV` = Kasir, Meja, Pesanan, Shift, Koneksi (urutan dan label); BottomNav juga memuat Koneksi.
- [X] T054 [P] [US5] `ATEST/state/RailPreferenceTest.kt`: sakelar hamburger menyimpan/memulihkan keadaan per perangkat (pola `SplitPreference`).

### Implementasi

- [X] T055 [US5] `APOS/ui/Shell.kt`: `NAV` diurutkan Kasir, Meja, Pesanan, Shift, **Koneksi** (pindahkan dari dasar rail ke daftar dan tambahkan ke `BottomNav`); label "Kasir", "Pesanan", "Koneksi"; FAB "Order baru" → "Pesanan baru".
- [X] T056 [US5] `APOS/ui/Shell.kt` `Header`: teks di samping logo menjadi "Shift dibuka {jam buka}" (ubah slot teks `APOS/ui/StoreMark.kt` atau parameter-nya agar tidak menampilkan nama toko ganda); `ConnChip` menampilkan nama pengguna (ikon/nada tetap menandai offline); tambahkan `LiveClock` (T006) di header; subjudul tak lagi mengulang "Shift dibuka".
- [X] T057 [US5] `APOS/ui/screens/PinScreen.kt` (±211–232): ganti `remember { ...format(Date()) }` dengan `LiveClock` agar jam layar PIN berdetak.
- [X] T058 [US5] Hamburger: `Icon(Icons.Filled.Menu)` di `Rail` (`Shell.kt` ±56) menjadi tombol yang menampilkan/menyembunyikan rail; keadaan di `APOS/state/RailPreference.kt` (baru, pola `SplitPreference.kt`) dan `PosStore`; layar compact tak memakai rail (tercatat di spec).
- [X] T059 [US5] Seragamkan istilah di Android: `APOS/ui/screens/ShiftScreens.kt` ("Transfer" → "EDC" di baris ±77, 131, 175, 218; "Tunai bersih" → "Tunai"; judul "Perangkat & sinkron" → "Koneksi"), `APOS/ui/screens/OrdersScreen.kt` (judul "Pesanan"), `APOS/model/PosModels.kt` (`Order.label` counter → "Kasir {nomor}", `TenderMethod.TRANSFER` label "EDC" — periksa dulu R2: apakah `bank_transfer` memang EDC dan berbeda dari `TenderMethod.EDC`), `APOS/data/ReceiptMapper.kt` (±53 "Transfer bank" → "EDC"), `APOS/print/Documents.kt` (±119 "Counter" → "Kasir"), `APOS/model/PosCatalog.kt` (alasan batal "Order ganda" → "Pesanan ganda", "tipe order" → "tipe pesanan"), `APOS/state/PosStore.kt` (pesan "Order …" → "Pesanan …"), `APOS/ui/screens/TenderScreen.kt` ("Order counter baru" → "Pesanan kasir baru"), `APOS/ui/Shell.kt` banner ("Perangkat offline…" → "Tablet ini offline …", "Order baru" → "Pesanan baru").
- [ ] T060 (**TIDAK DIUBAH: "Transfer" di web adalah metode `bank_transfer`, bukan EDC; lihat evidence.md temuan 6.**) [US5] Selaraskan salinan terkait di web yang menampilkan istilah yang sama bila ada dalam alur POS: `resources/js/locales/id.json` ("method_transfer": "Transfer" ±310 → "EDC" hanya bila itu metode yang sama dengan EDC Android; konfirmasi dulu pada R2, jika ragu biarkan dan catat di `evidence.md`).
- [ ] T061 [US5] **[PERANGKAT]** uji manual: hamburger, jam berdetak, nama pengguna, urutan menu; catat di `evidence.md`.

**Checkpoint**: istilah dan navigasi seragam.

---

## Phase 8: User Story 6 — Teks layar Kasir, Meja, Pembayaran (P3)

**Goal**: semua perubahan teks/tata letak kosmetik sesuai daftar.

**Independent Test**: bandingkan tiap layar dengan daftar.

### Tes (tulis dulu)

- [X] T062 [P] [US6] `ATEST/ui/CounterLabelsTest.kt` (perbarui yang ada, `ui/CounterLabels.kt`): judul kasir = nomor saja; tak ada "Counter · panggil dengan nomor antrean"; petunjuk "Pilih menu untuk menambah item" dan "Pilih dan tahan menu untuk menandai habis."
- [ ] T063 (**TIDAK DIBUAT: tata letak Compose tak bisa diuji murni tanpa perangkat; perubahannya (blok tunai di kolom 1, ubin sebaris) diperiksa di T067.**) [P] [US6] `ATEST/ui/TendersLayoutTest.kt`: "Terima Tunai" berada di kolom pertama; baris EDC: logo dan label sebaris (fungsi tata letak murni yang diekstrak, pola `DenominationLayout.kt`/`QrisSheetLayout.kt`).

### Implementasi

- [X] T064 [US6] `APOS/ui/screens/EditorScreen.kt` (±192): judul "Counter {nomor}" → "{nomor}"; hapus teks "Counter · panggil dengan nomor antrean · {nama}" (dine-in tetap sesuai desain); petunjuk "Ketuk menu untuk menambah item" → "Pilih menu untuk menambah item"; "Tahan kartu menu untuk menandai habis." → "Pilih dan tahan menu untuk menandai habis."; perbarui `APOS/ui/CounterLabels.kt` bila di sana.
- [X] T065 [P] [US6] `APOS/ui/screens/TablesScreen.kt` (±62): hapus teks "Denah dari server · diperbarui tiap 3 dtk" (pertahankan kondisi server tak terjangkau "Denah terakhir · server tidak terjangkau" — itu peringatan, bukan keterangan; catat di komentar).
- [X] T066 [US6] `APOS/ui/screens/TenderScreen.kt` (±105 dst.): pindahkan "Terima tunai" ke kolom pertama; logo EDC dan label "EDC" sebaris; perbarui tes tata letak (T063).
- [ ] T067 [US6] **[PERANGKAT]** periksa visual di tablet (lebar dan compact); catat di `evidence.md`.

**Checkpoint**: seluruh cerita selesai.

---

## Phase 9: Polish & Lintas-fitur

- [X] T068 [P] Dokumentasi: catatan bertanggal 2026-10-30 di `docs/PRD-POS-Event-Multivendor.md` dan `CLAUDE.md` (QRIS otomatis terverifikasi; modifier di POS web; istilah baku; X/Z tercetak); `docs/RUNBOOK.md` bila ada langkah baru. Jangan menulis ulang sejarah.
- [ ] T069 (**PARTIAL: suite Android penuh (444) dan Vitest penuh dijalankan; backend hanya himpunan lebar (suite penuh >1 jam tidak dijalankan). Hasil di evidence.md.**) Jalankan suite penuh: backend (perintah kontainer), `npm test`, `npm run build` (periksa chunk modifier), `./gradlew :app:testDebugUnitTest`; bandingkan dengan garis awal T001 dan tulis hasil apa adanya (termasuk kegagalan) di `evidence.md`.
- [ ] T070 (**TIDAK DAPAT DILAKUKAN di sesi ini: MCP browser gagal tersambung. Hanya uji komponen/unit + uji API.**) Verifikasi browser nyata (Konstitusi II): alur Kasir web dengan modifier hingga struk/detail Penjualan; cek konsol tanpa galat; hasil di `evidence.md`.
- [ ] T071 (**PARTIAL: `git diff` ditinjau — tidak ada `Payment::create` baru, satu-satunya perubahan pembuat payments adalah kunci `verification` di `PaymentRecorder`; regresi lewat himpunan lebar di T069.**) Regresi tanpa-regresi (FR-029/030): pembayaran tunai/EDC/QRIS, cetak struk, buka/tutup shift, konsistensi Kasir–Meja–Pesanan–Shift; tinjau `git diff` untuk memastikan tak ada `Payment::create` baru di luar `PaymentRecorder` dan tak ada tulis `order_items`/stok di luar jalur resmi.
- [X] T072 Rekap `evidence.md`: tandai tiap butir **terbukti** (JVM/browser/backend) vs **belum dibuktikan** ([PERANGKAT]: T028, T038, T045, T051, T061, T067); jangan mengklaim selesai untuk yang kedua.

---

## Dependensi & urutan

- **Setup (P1)** → **Foundational (P2)** → cerita. T003 memblokir T026 (aturan "Standard" pada struk); T006 memblokir T056–T057.
- **US1** (MVP) tidak bergantung pada cerita lain; menyediakan `changeTable` (T023) yang dipakai **US2** (T036).
- **US2** bergantung pada T023 (Android) dan server T032–T033 (independen dari US1 server).
- **US3**, **US4** independen dari US1/US2 (file berbeda); US3 memakai label "EDC"/"Tunai" — selaraskan dengan T059 (kerjakan keduanya sebelum rilis; tes T040 menjadi penjaga).
- **US5** menyentuh `Shell.kt` dan banyak string: kerjakan **setelah** US1–US4 agar konflik gabung minimal; **US6** setelah US5 (berbagi `EditorScreen.kt`/`TenderScreen.kt`).
- File yang sama → tidak paralel: `PosStore.kt` (T021, T023, T025, T037, T058), `EditorScreen.kt` (T024, T026, T064), `Shell.kt` (T055, T056, T058), `TenderScreen.kt` (T025, T066), `ShiftScreens.kt` (T044, T059).

## Peluang paralel

- Setelah Foundational: tim server (T007–T015, T029–T034), web (T010, T016–T020), dan Android murni (T039–T041, T046–T049, T052–T054) bisa jalan bersamaan karena berkas berbeda.
- Dalam US1: T007–T011 semua [P] (berkas tes berbeda); T016 dan T019 [P].
- Dalam US3/US4: tes T039, T040, T046, T047 [P].

## Strategi implementasi

1. **MVP = US1** (Phase 1–3): server modifier + QRIS, web modifier, Android Kasir/Meja/QRIS. Hentikan dan validasi (T028, T070).
2. Tambah **US2 → US3 → US4** sebagai kenaikan terpisah (masing-masing dapat dirilis).
3. **US5 → US6** terakhir (banyak sentuhan berkas bersama, risiko konflik terbesar).
4. Rilis Android memerlukan rekaman perangkat (T072); bila belum ada, rilis hanya sisi server/web dan catat sisanya sebagai belum diverifikasi.

## Catatan risiko yang diteruskan dari plan

- **R1** QRIS tanpa verifikasi = kontrol keuangan dilepas (keputusan pemilik; catat di PRD, T068).
- **R2** "Transfer" = EDC belum dikonfirmasi (T059/T060 menahan sampai dicek).
- **R5** tanpa adb/printer: T028, T038, T045, T051, T061, T067 tidak dapat diklaim lulus dari sesi ini.
