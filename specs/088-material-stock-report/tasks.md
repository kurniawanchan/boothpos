# Tasks: Laporan Bahan dan Statistik Stok di Dashboard

**Input**: Design documents from `/specs/088-material-stock-report/`
**Prerequisites**: plan.md, spec.md, research.md (D1–D11), data-model.md, contracts/api.md, quickstart.md
**Klasifikasi**: INTERNAL

**Tests**: WAJIB (Konstitusi II: setiap perubahan backend disertai tes `tests/Feature/` di MySQL, frontend disertai Vitest di `qa-tests/`). Tulis tes dulu, pastikan GAGAL, baru implementasi.

**Perintah tes** (selalu dengan flag aman; SATU proses tes per DB uji):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Nama>` · `docker compose exec -T node npx vitest run <berkas>`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: boleh paralel (berkas berbeda, tanpa ketergantungan tugas yang belum selesai)
- **[Story]**: US1–US5 sesuai spec.md

---

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 Buat fixture uji bersama `tests/Support/MaterialReportFixtures.php`: dua outlet (A, B), bahan Indomie Soto/Ayam Bawang/Telur/Bubuk Cabai (satu `semi_finished` dengan `standard_cost`, satu tanpa harga vendor), vendor pilihan + harga, grup modifier Rasa/Topping tertaut ke produk dan ke kategori (dengan satu pengecualian 086), pembantu `sellDays(outlet, dates[])` yang membuat pesanan `completed` dengan `business_date` tertentu, dan pembantu `move(material, outlet, type, qty, date)` lewat `MaterialStockService::applyMovement` (gunakan `ModifierMaterialFixtures` 087 sebagai acuan)
- [X] T002 [P] Tambah kunci bahasa laporan bahan (status, alasan penolakan draf PO, judul sheet ekspor, pesan "pilih satu outlet") di `lang/id/reports.php` dan `lang/en/reports.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ Semua user story bergantung pada fase ini.**

- [X] T003 Tes dulu: `tests/Feature/ModifierResolverProductsUsingGroupsTest.php` — `productsUsingGroups([groupIds])` mengembalikan produk bertautan langsung ∪ produk kategori bertautan, tanpa produk yang melepas grup warisan (086), tautan produk menang atas pengecualian, hanya produk aktif mode data aktif
- [X] T004 Implementasi `ModifierResolver::productsUsingGroups(array $groupIds): array` di `app/Services/ModifierResolver.php` (satu kueri untuk tautan, satu untuk kategori, satu untuk pengecualian; tanpa N+1), dengan komentar bahwa ini pembaca balik aturan `inheritedGroups()` (CLAUDE.md 086)
- [X] T005 Tes dulu: `tests/Feature/MaterialReportCoreTest.php` — kerangka `MaterialReport` (research D1–D3, D5): saldo per outlet dari `material_outlet_stock`; `hari_jual` = jumlah `business_date` berbeda dengan pesanan `completed` per outlet di jendela; laju = (−sale − return − waste) ÷ hari_jual; hari tutup tidak menurunkan laju; status `negative/out/low/ok/unused` dengan N bawaan 3; `usage_untracked` untuk bahan di resep aktif/BOM tanpa pemetaan modifier; biaya = `RecipeService::unitCost` (setengah jadi → biaya standar; tanpa harga → null); baris hanya untuk bahan aktif yang punya saldo ATAU pergerakan di outlet itu; `OutletFilter` dan mode DEMO/LIVE dihormati
- [X] T006 Implementasi `app/Services/MaterialReport.php` bagian inti: `positions(array $params): Collection` (saldo, laju, hari tersisa, status, biaya, nilai) memakai agregasi `GROUP BY material_id, outlet_id, type` atas `material_stock_movements` dengan batas `BusinessDay::bounds()`; filter `data_mode` eksplisit pada kueri mentah; urutan bawaan (negative → out → low naik hari → ok → unused, lalu nama)
- [X] T007 Daftarkan berkas kueri mentah baru (`app/Services/MaterialReport.php`) sebagai `filtered` di `app/Support/OutletScopeRegistry.php` dan jalankan `OutletScopeRegistryTest` — *tidak perlu didaftarkan: tabel ber-outlet dibaca lewat model + OutletFilter; kueri mentah hanya ke recipe_lines/BOM/pemetaan (bukan tabel outlet, mode data difilter eksplisit). OutletScopeRegistryTest hijau.*

**Checkpoint**: `MaterialReportCoreTest`, `ModifierResolverProductsUsingGroupsTest`, `OutletScopeRegistryTest` hijau.

---

## Phase 3: User Story 1 - Melihat bahan mana yang mengancam penjualan (Priority: P1) 🎯 MVP

**Goal**: Tab Laporan → Bahan menampilkan bahan berisiko per outlet, hari tersisa, dan pilihan/menu yang terancam.

**Independent Test**: data saldo + pemakaian di dua outlet → urutan risiko, hari tersisa, dan menu terdampak sesuai (spec US1 skenario 1–4).

### Tests for User Story 1 ⚠️

- [X] T008 [P] [US1] Tes `tests/Feature/MaterialReportPositionEndpointTest.php` untuk `GET /reports/materials/position` (contracts §1): bentuk baris dan `summary` (dihitung SEBELUM saringan status), `at_risk_options` (porsi = floor(saldo ÷ qty), minus = 0) dan `at_risk_products` hanya untuk status berisiko, saringan `status[]`/`kind[]`/`search`, validasi `window_days`/`risk_days`/`target_days`, 403 tanpa menu `reports`, header `X-Outlet-Id` menyempitkan, "semua outlet" = baris per outlet (tidak dijumlah), bahan tanpa pemakaian = `unused` dan `days_left` null
- [X] T009 [P] [US1] Tes Vitest `qa-tests/unit/materialReport.test.js` untuk `resources/js/utils/materialReport.js`: label/nada status, format hari tersisa ("tidak terpakai", "pemakaian tidak tercatat", "±1,5 hari"), simpan/baca preferensi `localStorage` dalam try/catch (bawaan 7/3/7 saat gagal)
- [X] T010 [P] [US1] Tes Vitest `qa-tests/component/MaterialReportTab.test.js`: kartu ringkasan, tabel risiko (minus di atas, merah), saringan status/jenis/cari, ubah N/jendela memuat ulang dengan parameter, outlet navbar memuat ulang, klik baris membuka rincian menu, keadaan galat + "Coba lagi"

### Implementation for User Story 1

- [X] T011 [US1] Lengkapi `MaterialReport`: `atRisk()` memakai `modifier_option_materials` (pilihan aktif) → grup → `ModifierResolver::productsUsingGroups()` dan menghitung porsi per pilihan, hanya untuk baris berstatus negative/out/low, di `app/Services/MaterialReport.php`
- [X] T012 [US1] Buat `app/Http/Controllers/Api/MaterialReportController.php::position()` (validasi parameter sesuai data-model, gerbang `reports`, `summary` + `params` + `computed_at`) dan rute `GET /reports/materials/position` di `routes/api.php` (SEBELUM rute `reports/{report}` agar tidak tertangkap)
- [X] T013 [P] [US1] Klien `getMaterialPosition(params)` di `resources/js/api/reports.js`
- [X] T014 [P] [US1] Util `resources/js/utils/materialReport.js` (label/nada status, format hari, preferensi per pengguna)
- [X] T015 [US1] Komponen `resources/js/views/reports/MaterialsTab.vue` (ringkasan, saringan, tabel risiko dengan pilihan/menu terancam; token desain, tanpa hex) dan daftarkan tab "Bahan" di `resources/js/views/ReportsView.vue` (pola `StockByOutletTab`, hanya untuk menu `reports`)
- [X] T016 [P] [US1] Kunci locale tab Bahan di `resources/js/locales/id.json` dan `resources/js/locales/en.json`

**Checkpoint**: US1 berdiri sendiri — tab Bahan menampilkan risiko yang benar.

---

## Phase 4: User Story 2 - Saran pembelian bahan → draf PO (Priority: P1)

**Goal**: Saran jumlah beli per bahan berisiko + vendor/harga; pilih saran → satu draf PO per vendor untuk outlet tunggal.

**Independent Test**: laju dan saldo diketahui → saran = max(0, target × laju − saldo) dibulatkan ke atas 3 desimal; draf PO per vendor terbentuk, baris tanpa vendor ditolak (spec US2 skenario 1–4).

### Tests for User Story 2 ⚠️

- [X] T017 [P] [US2] Perluas `tests/Feature/MaterialReportPositionEndpointTest.php`: `suggestion` (qty, vendor pilihan, harga, biaya; saldo minus menambah saran; tanpa harga → biaya null; tidak ada `suggestion` bila qty 0), `target_days` mengubah qty
- [X] T018 [P] [US2] Tes `tests/Feature/MaterialPurchaseDraftsTest.php` untuk `POST /reports/materials/purchase-drafts` (contracts §4): satu PO `draft` per vendor pilihan berisi baris `material` + qty + harga vendor; outlet = outlet tunggal dari `X-Outlet-Id` (body `outlet_id` diabaikan/ditolak); tanpa outlet tunggal → 422; `refused` untuk tanpa vendor/nonaktif/tak terlihat tanpa menggagalkan kelompok lain; semua ditolak → 422; stok bahan TIDAK berubah; log aktivitas PO tertulis; `Idempotency-Key` sama → tidak ada PO ganda; 403 tanpa `reports` atau tanpa izin buat PO
- [X] T019 [P] [US2] Tes Vitest perluas `qa-tests/component/MaterialReportTab.test.js`: kolom saran, centang saran, tombol "Buat draf PO" tersembunyi tanpa izin PO, mengirim baris terpilih (qty bisa diubah), menampilkan hasil `created` (tautan ke PO) dan `refused` dengan alasan, pesan "pilih satu outlet" saat semua outlet

### Implementation for User Story 2

- [X] T020 [US2] Tambah `suggestion` ke `MaterialReport::positions()` (research D8, vendor `is_preferred`) di `app/Services/MaterialReport.php`
- [X] T021 [US2] Buat `app/Services/MaterialPurchaseDrafts.php`: validasi baris, tolak per baris dengan alasan, kelompokkan per vendor pilihan, panggil `PurchaseOrderService::create` per kelompok dalam transaksi per kelompok, outlet dari `OutletContext::singleId()`
- [X] T022 [US2] `MaterialReportController::purchaseDrafts()` + rute `POST /reports/materials/purchase-drafts` dengan middleware idempotensi yang ada (`EnsureIdempotent`) di `routes/api.php`; gerbang `reports` + `can('create', PurchaseOrder::class)`
- [X] T023 [P] [US2] Klien `createMaterialPurchaseDrafts(lines, key)` di `resources/js/api/reports.js` (kirim `Idempotency-Key`)
- [X] T024 [US2] UI saran + pilih + "Buat draf PO" + hasil di `resources/js/views/reports/MaterialsTab.vue`; locale di `resources/js/locales/{id,en}.json`

**Checkpoint**: US1 + US2 = MVP keputusan "apa yang harus dibeli".

---

## Phase 5: User Story 3 - Statistik bahan dan stok di Dashboard (Priority: P2)

**Goal**: Kartu statistik bahan & stok di Dashboard yang sama angkanya dengan laporan dan bisa diklik.

**Independent Test**: data dengan jumlah risiko diketahui → angka kartu = jumlah baris laporan pada saringan tujuan; Kasir melihat hitungan tanpa rupiah (spec US3).

### Tests for User Story 3 ⚠️

- [X] T025 [P] [US3] Tes `tests/Feature/DashboardMaterialStatsTest.php` untuk `GET /dashboard/materials` (contracts §6): `counts` = ringkasan `position` dengan bawaan (7/3) untuk outlet yang sama (paritas), `low_stock_products` = `LowStockHistory::current()` yang ada, `wasted_value` sesuai periode `today|7d|30d`, rupiah TIDAK ADA bagi pengguna tanpa `reports`, kunci cache memuat mode + outlet + periode + tanggal bisnis (ganti outlet → angka berbeda), `computed_at` ada, 403 tanpa menu `dashboard`
- [X] T026 [P] [US3] Tes Vitest `qa-tests/component/DashboardMaterialStats.test.js`: kartu menampilkan hitungan, rupiah hanya bila ada, klik kartu menavigasi ke Laporan → Bahan dengan `status` yang sesuai, memuat ulang saat outlet navbar/periode berganti, galat tidak memecah Dashboard

### Implementation for User Story 3

- [X] T027 [US3] `MaterialReport::dashboardStats(string $period)` di `app/Services/MaterialReport.php` dan `DashboardController::materials()` dengan cache 30 dtk berkunci lengkap (pola `DashboardService::summary`), rupiah dibuang sesudah cache untuk non-`reports`; rute `GET /dashboard/materials` di `routes/api.php`
- [X] T028 [P] [US3] Klien `getDashboardMaterials(period)` di `resources/js/api/dashboard.js`
- [X] T029 [US3] Komponen `resources/js/components/dashboard/DashboardMaterialStats.vue` dan pasang di `resources/js/views/DashboardView.vue` di bawah `DashboardKpis` (tanpa mengubah kartu yang ada); klik → `router.push({ name: 'reports', query: { tab: 'materials', status } })`. `resources/js/views/ReportsView.vue` HARI INI tidak membaca `query.tab` — tambahkan (tab awal dari `query.tab` bila dikenal, selain itu bawaan lama) dengan tes di `qa-tests/component/MaterialReportTab.test.js`; `MaterialsTab.vue` membaca `query.status` saat dibuka
- [X] T030 [P] [US3] Locale kartu Dashboard di `resources/js/locales/{id,en}.json`

**Checkpoint**: Dashboard dan laporan selalu sama angkanya.

---

## Phase 6: User Story 4 - Pemakaian, pemborosan, dan selisih (Priority: P2)

**Goal**: Per periode tanggal bisnis: total per jenis, tren harian, top 10, rincian menu per bahan.

**Independent Test**: penjualan/buang/koreksi/terima PO di periode → angka sama dengan buku besar; void menurunkan pemakaian bersih (spec US4).

### Tests for User Story 4 ⚠️

- [X] T031 [P] [US4] Tes `tests/Feature/MaterialReportUsageTest.php` untuk `GET /reports/materials/usage` (contracts §2): `sold_net` = −(sale + return) (void/refund mengurangi), `wasted`, `adjusted_in/out` terpisah, `purchased`, pindah masuk/keluar/batal; nilai rupiah; `totals` hanya rupiah; `trend` per tanggal bisnis (batas jam tutup buku); `top_used`/`top_wasted` maksimal 10; PARITAS dengan `GET /materials/movements` untuk outlet dan periode yang sama; validasi rentang ≤ 366 hari
- [X] T032 [P] [US4] Tes `tests/Feature/MaterialUsageByMenuTest.php` untuk `GET /reports/materials/{material}/usage-by-menu` (contracts §3): produk dari `order_items` rujukan, pilihan = snapshot baris ∩ pemetaan bahan, baris yang sudah dihapus tidak memecah respons, outlet lain tidak terlihat
- [X] T033 [P] [US4] Tes Vitest perluas `qa-tests/component/MaterialReportTab.test.js` dan buat `qa-tests/component/MaterialUsageByMenuModal.test.js`: sub-tab Pemakaian (periode, total, tren, top 10), rincian menu per bahan

### Implementation for User Story 4

- [X] T034 [US4] `MaterialReport::usage(array $params)` dan `usageByMenu(Material, array $params)` di `app/Services/MaterialReport.php` (agregasi satu kueri per bentuk; tren per tanggal bisnis)
- [X] T035 [US4] `MaterialReportController::usage()` dan `usageByMenu()` + rute di `routes/api.php`
- [X] T036 [P] [US4] Klien `getMaterialUsage(params)` dan `getMaterialUsageByMenu(id, params)` di `resources/js/api/reports.js`
- [X] T037 [US4] Sub-tab Pemakaian di `resources/js/views/reports/MaterialsTab.vue` dan modal `resources/js/components/report/MaterialUsageByMenuModal.vue`; locale `resources/js/locales/{id,en}.json`

---

## Phase 7: User Story 5 - Ekspor laporan (Priority: P3)

**Goal**: Excel tiga sheet dengan saringan aktif, aman dari formula.

**Independent Test**: isi berkas = layar untuk saringan yang sama; sel diawali `=` tetap teks (spec US5).

- [X] T038 [P] [US5] Tes `tests/Feature/MaterialReportExportTest.php` untuk `GET /reports/materials/export` (contracts §5): tiga sheet, baris = `position`/`usage` untuk saringan yang sama, nama bahan `=SUM(1)` tersimpan sebagai teks, 403 tanpa `reports`
- [X] T039 [US5] `app/Exports/MaterialReportExport.php` (multi-sheet, tiap sheet turunan `SafeStringExport`) dan `MaterialReportController::export()` + rute di `routes/api.php`
- [X] T040 [P] [US5] Tombol ekspor di `resources/js/views/reports/MaterialsTab.vue` — *memakai tombol ekspor kepala halaman yang ada; MaterialsTab mengekspos `exportParams()` (tes di `ReportsViewMaterialsLink.test.js`)* memakai pola unduhan ekspor laporan yang ada di `resources/js/api/reports.js`; tes ditambahkan ke `qa-tests/component/MaterialReportTab.test.js`

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T041 [P] Perbarui `docs/openapi-pos-mvp.yaml` untuk enam endpoint (contracts §1–§6) dan validasi YAML
- [ ] T042 [P] Catatan bertanggal di `CLAUDE.md` (Domain rules: laporan bahan, satu sumber `MaterialReport`, aturan laju/status, draf PO dari saran) dan `docs/PRD-POS-Event-Multivendor.md`
- [ ] T043 Uji kinerja: seed 300 bahan × 3 outlet × 90 hari pergerakan di DB uji, ukur `position` < 3 dtk (SC-005) dan pastikan jumlah kueri tidak tumbuh per bahan, di `tests/Feature/MaterialReportPerformanceTest.php`
- [ ] T044 Jalankan suite backend penuh dan Vitest penuh dengan flag aman; kegagalan yang diklaim sudah ada dibuktikan di commit dasar; `npm run build`; catat di `specs/088-material-stock-report/evidence.md`
- [ ] T045 Verifikasi peramban nyata sesuai `specs/088-material-stock-report/quickstart.md` langkah 1–7 (konsol bersih); bila alat peramban tidak tersedia, catat "belum diverifikasi" di `evidence.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** → **Foundational (Phase 2)** → user stories.
- **US1 (Phase 3)** butuh Phase 2. **US2** butuh `positions()` dari US1/T006 dan UI tab dari T015. **US3** butuh `positions()` (T006, T011) dan rute tab (T015) untuk klik-menuju. **US4** hanya butuh Phase 2 + tab (T015). **US5** butuh US1 + US4 (isi sheet).
- **Polish** sesudah story yang diinginkan selesai.

### Within Each User Story

- Tes ditulis dan GAGAL dulu → service → controller/rute → klien → UI.
- Satu proses PHPUnit pada satu waktu (DB uji bersama); Vitest boleh berjalan bersamaan.

### Parallel Opportunities

- T002 bersama T001.
- Di US1: T008, T009, T010 paralel; T013, T014, T016 paralel sesudah T012.
- Di US2: T017, T018, T019 paralel.
- US3 (backend T025/T027) dan US4 (backend T031–T035) bisa dikerjakan bergantian dengan pekerjaan frontend masing-masing secara paralel.

---

## Parallel Example: User Story 1

```bash
# Tes ditulis bersamaan (berkas berbeda):
Task: "T008 MaterialReportPositionEndpointTest.php"
Task: "T009 qa-tests/unit/materialReport.test.js"
Task: "T010 qa-tests/component/MaterialReportTab.test.js"

# Sesudah T012 (rute):
Task: "T013 api/reports.js getMaterialPosition"
Task: "T014 utils/materialReport.js"
Task: "T016 locales id/en"
```

---

## Implementation Strategy

### MVP First (US1 + US2)

1. Phase 1–2 (fixture, pembaca balik grup, inti `MaterialReport`).
2. US1 → validasi mandiri (risiko benar per outlet).
3. US2 → saran + draf PO (keputusan "apa yang dibeli" lengkap).
4. Stop & demo ke pemilik.

### Incremental Delivery

1. MVP (US1 + US2) → demo.
2. US3 Dashboard → demo (paritas angka).
3. US4 Pemakaian → demo.
4. US5 Ekspor → Polish (OpenAPI, CLAUDE.md, kinerja, suite penuh, peramban).

---

## Notes

- Tidak ada migrasi di fitur ini; jangan menambah tabel ringkasan (research D1).
- Outlet draf PO SELALU dari server (`OutletContext::singleId()`), tidak pernah dari body (Konstitusi IV).
- Rupiah hanya untuk menu `reports` (research D6); kontrol tanpa akses disembunyikan, bukan dinonaktifkan.
- Tablet Android tidak berubah.
