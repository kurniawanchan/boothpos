---
description: "Task list — filter outlet Produk, formulir tambah setara halaman item, angka kepala mengikuti filter"
---

# Tasks: Filter outlet Produk, formulir tambah setara halaman item, angka kepala mengikuti filter

**Klasifikasi**: INTERNAL

**Input**: `/specs/070-products-outlet-filter-add-form/` — plan.md, spec.md, research.md (D1–D8), data-model.md, contracts/api-products-070.md, quickstart.md

**Tests**: DISERTAKAN — Konstitusi II mewajibkan tes backend (`tests/Feature/`) dan frontend (`qa-tests/`) untuk setiap perubahan, ditambah uji layar nyata. Tulis tes dulu, pastikan GAGAL, baru implementasi.

**Perintah tes** (CLAUDE.md; flag WAJIB di kontainer, jangan dilewati):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Nama>` (semua `-e` SEBELUM nama layanan `app`; setelah `artisan test` opsi `-e` ditolak) · frontend: `npm test -- <berkas>`

## Format: `[ID] [P?] [Story] Deskripsi + path`

- **[P]**: boleh paralel (berkas berbeda, tak bergantung tugas yang belum selesai)
- Semua komentar kode, teks UI, dan komit dalam bahasa Indonesia; komentar menjelaskan *mengapa* (gaya repo ini)

## Phase 1: Setup

**Purpose**: Landasan bersama; tidak ada dependensi atau migrasi baru.

- [X] T001 Catat garis dasar sebelum perubahan: jalankan `ProductCatalogTest`, `ProductsOutletsListTest`, `ProductDetailOutletsTest`, `ProductOutletsApiTest` dan `npm test` lalu simpan hasil (hijau/merah) di `specs/070-products-outlet-filter-add-form/quickstart.md` bagian catatan, supaya kegagalan lama tidak tertukar dengan regresi (catatan: `ShiftReportParityTest` diketahui gagal sejak 065)

---

## Phase 2: Foundational (blokir semua cerita)

**Purpose**: Dua fondasi yang dipakai US1 dan US2: satu "himpunan tersaring" di server, dan opsi klien tanpa header outlet.

**⚠️ CRITICAL**: US1 dan US2 tidak boleh mulai sebelum T002–T004 selesai.

- [X] T002 Refactor `app/Services/ProductCatalogQuery.php`: pisahkan `base()` menjadi `filtered(array $f): Builder` (hanya klausa `where`/`whereExists` untuk tab, q, category, status, outlet, availability, quality — tanpa `with`) dan `base()` = `filtered()` + eager-load; perilaku `page()` TIDAK berubah (tes `ProductCatalogTest`, `ProductsOutletsListTest` tetap hijau). Komentar: mengapa satu definisi (angka kepala = baris di bawahnya, SC-002)
- [X] T003 [P] Tambah opsi per-permintaan di `resources/js/api/client.js` agar sebuah panggilan TIDAK menyertakan `X-Outlet-Id` (mis. `config.skipOutletHeader`), dengan komentar alasan (D1: header hanya menyempitkan sehingga filter halaman tak bisa memilih outlet lain); tes unit di `qa-tests/unit/apiClientOutletHeader.test.js` (header ada secara bawaan, hilang dengan opsi)
- [X] T004 [P] Perbarui `resources/js/api/products.js`: `listProducts(params)` dan `productSummary(params)` memakai opsi dari T003 dan menyerialkan array (`outlet_id[]`, `category_id[]`, `status[]`) dengan benar; perluas tes yang ada di `qa-tests/unit/` bila ada untuk berkas ini

**Checkpoint**: fondasi siap — US1 dan US2 dapat dikerjakan.

---

## Phase 3: User Story 1 — Filter outlet di baris filter (Priority: P1) 🎯 MVP

**Goal**: Filter Outlet pilihan-banyak di samping Kategori/Status; daftar, jumlah item, dan hitungan tab mengikuti; status di URL; terisi dari bilah atas.

**Independent Test**: dua outlet dengan menu berbeda → pilih satu outlet → daftar, "N items", hitungan tab menyempit; segarkan → bertahan; Reset → kembali; satu outlet → filter tersembunyi.

### Tests (tulis dulu, harus GAGAL)

- [X] T005 [P] [US1] Tes Feature `tests/Feature/ProductCatalogOutletFilterTest.php`: (a) `counts.menu`/`counts.retail` mengikuti `outlet_id[]`; tab bahan/modifier tidak berubah; (b) dua outlet terpilih → item dihitung sekali; (c) `outlet_id` di luar `permittedIds()` dipotong (pengguna tak melihat outlet terlarang, FR-007) dan tanpa header `X-Outlet-Id` filter dua outlet bekerja; (d) dengan header menyempit ke satu outlet, hasil tetap dipotong (Konstitusi IV)
- [X] T006 [P] [US1] Tes Vitest `qa-tests/component/ProductsViewOutletFilter.test.js` (pakai `productsViewHarness.js`, pola `ProductsViewOutlets.test.js`): filter tampil hanya bila `outletStore.multi`; memilih outlet memanggil `listProducts` dengan `outlet_id[]` dan tanpa header; terisi dari `outletStore.selected` dan mengikuti saat berganti; tersimpan/dipulihkan dari `?outlet=`; Reset mengosongkannya; nol hasil → EmptyState dengan reset

### Implementation

- [X] T007 [US1] `app/Services/ProductCatalogQuery.php::counts()` menerima filter (`array $f = []`) dan memakai `filtered()` hanya dengan `outlet_id` untuk `menu`/`retail`; ubah pemanggil di `app/Http/Controllers/Api/ProductController.php::catalog()` (`'counts' => $query->counts($filters)`) — T005 hijau
- [X] T008 [US1] `resources/js/views/ProductsView.vue`: tambah state `outlet` (array) di `useQueryState`; kendali `BaseMultiSelect` "Semua outlet" di `FilterBar` (opsi dari `outletStore.outlets`, tampil bila `outletStore.multi`); `listParams()` memakai `outlet_id` dari filter dan opsi tanpa header (T003/T004); `watch` pada `outletStore.selected` mengisi ulang filter; sertakan `outlet` di `filtersActive`, `resetFilters`, dan chip bila perlu; hapus ketergantungan lama `outlet_id: outletStore.selected` pada `listParams`
- [X] T009 [P] [US1] Teks baru `products.filter_outlet_all` (+ aria) di `resources/js/locales/id.json` dan `en.json`
- [X] T010 [US1] Sesuaikan tes lama yang berasumsi header menyempitkan daftar: `qa-tests/component/ProductsViewOutlets.test.js` dan `ProductsView.test.js` — T006 hijau, seluruh `npm test` hijau

**Checkpoint**: US1 mandiri dan dapat didemokan (MVP).

---

## Phase 4: User Story 2 — Angka kepala mengikuti filter (Priority: P1)

**Goal**: Tiga kartu dihitung dari himpunan tersaring (outlet, kategori, status, q); kembali ke total tanpa filter; dimuat ulang saat filter berubah.

**Independent Test**: catat tiga angka tanpa filter → pilih outlet → cocok dengan hitungan manual → lepas filter → kembali.

### Tests (tulis dulu, harus GAGAL)

- [X] T011 [P] [US2] Tes Feature `tests/Feature/ProductSummaryFilterTest.php`: (a) `active.count/total/draft/discontinued` menurut outlet; (b) 86 pada item outlet lain tidak dihitung, tanda global (`outlet_id` NULL) dihitung di setiap outlet (D4); (c) item tanpa harga hanya di outlet lain tidak muncul di `quality`; (d) filter kategori, status, `q` menyempitkan; (e) `quality` dan `availability` DIABAIKAN (D3); (f) tanpa filter hasil identik dengan sebelumnya; (g) nilai tak valid → 422; (h) jumlah query tetap (tak tumbuh dengan jumlah item, pola `ProductsOutletsListTest`)
- [X] T012 [P] [US2] Tes Vitest `qa-tests/component/ProductsViewFigures.test.js`: `productSummary` dipanggil ulang saat outlet/kategori/status/q berubah dengan parameter yang sama dengan daftar; TIDAK dipanggil ulang karena perubahan quality/availability/tab/sort/halaman; respons usang tidak menimpa yang baru; kartu menampilkan keadaan memuat dan tidak menahan angka lama (FR-006)
- [X] T013 [P] [US2] Perluas `qa-tests/component/ProductFigures.test.js`: keadaan memuat per kartu dan keterangan "dari N item" mengikuti himpunan tersaring

### Implementation

- [X] T014 [US2] `app/Http/Controllers/Api/ProductController.php::summary()`: validasi dengan `ProductCatalogRequest` (hanya `outlet_id`, `category_id`, `status`, `q`/`search` yang dipakai) dan teruskan ke `ProductCatalogQuery::summary(array $f)`
- [X] T015 [US2] `app/Services/ProductCatalogQuery.php::summary(array $f = [])`: hitung `active` dari `filtered()` (tab menu, tanpa `quality`/`availability`); `sold_out_today` = produk dalam himpunan dengan tanda aktif yang `outlet_id` NULL atau termasuk outlet terpilih; `quality` lewat `qualityQuery()` dibatasi `whereIn products.id` subkueri himpunan; `unreleased` tetap global (D3); `computed_at` tetap. Komentar BUG/alasan: kartu tidak boleh menyaring dirinya sendiri — T011 hijau
- [X] T016 [US2] `resources/js/views/ProductsView.vue`: `loadSummary(params)` dengan penjaga urutan respons, dipicu `watch` pada filter outlet/kategori/status/q (bukan quality/availability/tab/sort/halaman); `productSummary` memakai parameter yang sama dengan `listParams()` kecuali yang dikecualikan; hindari panggilan ganda saat filter berubah serentak
- [X] T017 [P] [US2] `resources/js/components/product/ProductFigures.vue`: keadaan memuat per kartu saat muat ulang (tanpa kedip angka lama yang menyesatkan) — T012/T013 hijau

**Checkpoint**: US1 + US2 berfungsi bersama dan konsisten (SC-002).

---

## Phase 5: User Story 3 — Varian & harga di formulir tambah setara halaman item (Priority: P2)

**Goal**: Tabel varian di drawer tambah menerima harga per outlet dan menyimpannya atomik bersama item.

**Independent Test**: tambah item dua varian dengan harga khusus satu outlet → halaman item menampilkan varian, harga dasar, dan harga outlet persis.

### Tests (tulis dulu, harus GAGAL)

- [X] T018 [P] [US3] Tes Feature `tests/Feature/ProductStoreOutletPricesTest.php`: (a) `variants.*.outlet_prices` tertulis ke `variant_outlet_prices` dengan `price_histories.source = 'created'` dan log audit; (b) rilis menu MATI dan AKTIF → item (draft saat aktif) tetap menyimpan harga langsung, tanpa `menu_change` harga baru; (c) `GET /products/{id}` `outlet_prices` cocok; (d) outlet tak diizinkan → 422; (e) outlet di luar `outlet_ids` → 422; (f) outlet ganda pada satu varian → 422; (g) harga negatif/bukan angka → 422; (h) kegagalan di tengah → tidak ada produk/varian/harga tersimpan (atomik); (i) harga sama dengan dasar tidak membuat baris; (j) mode DEMO/LIVE benar
- [X] T019 [P] [US3] Perluas `qa-tests/component/ProductVariantsTable.test.js`: mode `create` multi-outlet menampilkan kolom per outlet (kode outlet di kepala), hanya outlet yang dicentang aktif, sel kosong = ikut harga dasar, tanpa kolom bila satu outlet, kolom biaya tetap tersembunyi di mode buat dan menurut `canSeeCost` di mode ubah
- [X] T020 [P] [US3] Perluas `qa-tests/component/ProductFormCreate.test.js`: payload `POST /products` memuat `outlet_prices` hanya untuk nilai terisi; galat 422 `variants.N.outlet_prices.*` tampil di sel yang tepat; data formulir tetap setelah galat

### Implementation

- [X] T021 [US3] `app/Http/Requests/StoreProductRequest.php`: aturan `variants.*.outlet_prices` (array), `.*.outlet_id` (integer, diizinkan lewat `OutletContext::isPermitted`, pola `OutletCarriedRule`), `.*.price` (numeric, min 0, max sama dengan `VariantOutletPriceController`); validasi lintas-aturan: outlet harus termasuk `outlet_ids` yang dikirim (atau semua outlet bila tidak dikirim) dan unik per varian — pesan Indonesia di `lang/`
- [X] T022 [US3] `app/Http/Controllers/Api/ProductController.php::store()`: dalam transaksi yang sudah ada, setelah varian dibuat, tulis tiap `outlet_prices` lewat `PriceService::writeOutletPrice($variant, $outletId, $price, $user, 'created')` (JANGAN `VariantOutletPrice::create` langsung, Konstitusi I); komentar mengapa langsung (sejajar harga dasar `created`, item masih draft) — T018 hijau
- [X] T023 [US3] `resources/js/views/products/ProductVariantsTable.vue`: kolom harga per outlet di mode `create` bila `outletStore.multi` (prop `outlets` = outlet dicentang), `row.outlet_prices` = peta `{outlet_id: harga}`; perbarui komentar kepala berkas (mode buat kini punya kolom outlet)
- [X] T024 [US3] `resources/js/views/products/ProductFormDrawer.vue`: simpan `outlet_prices` per baris varian dan kirim sebagai `variants[].outlet_prices` hanya untuk nilai terisi; buang entri outlet yang kemudian dilepas centangnya; petakan galat `variants.N.outlet_prices.M.price` ke sel — T019/T020 hijau
- [X] T025 [P] [US3] Teks baru (kepala kolom, tooltip "kosong = ikut harga dasar", galat) di `resources/js/locales/id.json` dan `en.json` (pakai ulang kunci `products.outlet_price_*` yang ada bila cocok)

**Checkpoint**: US3 mandiri; harga outlet tersimpan atomik.

---

## Phase 6: User Story 4 — Bagian Ketersediaan di formulir tambah (Priority: P2)

**Goal**: Bagian Ketersediaan per outlet (dijual/tidak + status) menggantikan blok Status dan fieldset "Outlet aktif" terpisah.

**Independent Test**: tambah item, centang hanya WJM 2 → halaman item menampilkan ketersediaan hanya WJM 2; tanpa centang → ditolak.

### Tests (tulis dulu, harus GAGAL)

- [X] T026 [P] [US4] Tambah ke `tests/Feature/ProductStoreOutletPricesTest.php` (atau berkas baru `ProductStoreAvailabilityTest.php`): `outlet_ids: []` saat membuat → 422 `outlet_ids`; `outlet_ids` berisi satu outlet → hanya outlet itu membawa; tanpa `outlet_ids` → semua outlet (perilaku lama); outlet tak diizinkan → 422 (tetap)
- [X] T027 [P] [US4] Tes Vitest `qa-tests/component/ProductFormAvailability.test.js` (pola `ProductFormOutlets.test.js`): satu baris per outlet dengan kotak centang + chip Ada/Tidak dijual; status draft/aktif tetap (catatan rilis saat rilis aktif); keterangan penandaan 86 tersedia setelah simpan; tanpa centang → galat di bagian itu dan POST tidak dikirim; satu outlet → hanya status; kuota/channel "—"

### Implementation

- [X] T028 [US4] `app/Http/Requests/StoreProductRequest.php` (setelah T021): `outlet_ids` yang dikirim saat membuat wajib berisi ≥ 1 (`min:1`) tanpa mengubah `OutletCarriedRule` yang dipakai UpdateProductRequest — T026 hijau
- [X] T029 [US4] `resources/js/views/products/ProductFormDrawer.vue`: gabungkan fieldset `data-testid="active-outlets"` dan blok Status (`isCreate`) menjadi satu `ProductFormBlock` "Ketersediaan" bertata letak `AvailabilitySection` (baris per outlet + `AvailabilityChips`, lalu pilihan status dan catatan rilis); validasi klien ≥ 1 outlet; keterangan 86; tampilkan hanya status bila satu outlet; perbarui komentar kepala berkas (ketersediaan kini memuat outlet)
- [X] T030 [US4] Hubungkan T023: centang di Ketersediaan menentukan kolom harga outlet aktif di `ProductVariantsTable`; melepas centang menghapus harga outlet itu dari payload — T027 hijau
- [X] T031 [P] [US4] Teks baru bagian Ketersediaan (judul, keterangan 86, galat tanpa outlet) di `resources/js/locales/id.json` dan `en.json`; sesuaikan `qa-tests/component/ProductFormOutlets.test.js` bila selektor lama berubah

**Checkpoint**: keempat cerita berfungsi.

---

## Phase 7: Polish & Cross-Cutting

- [X] T032 [P] Perbarui `docs/openapi-pos-mvp.yaml` dalam komit yang sama: parameter `GET /products/summary`, perilaku `counts` menurut outlet, `variants[].outlet_prices` dan respons 422 pada `POST /products` (Konstitusi: kontrak bergerak bersama kode); selaraskan `contracts/api-products-070.md` bila ada selisih
- [X] T033 [P] Catatan bertanggal di `CLAUDE.md` (bagian Domain rules, "Outlets"/"Products"): filter outlet halaman Produk tidak memakai header, angka kepala satu himpunan tersaring, `outlet_prices` saat buat lewat `PriceService` source `created`
- [X] T034 Jalankan seluruh suite backend (kontainer, dengan flag `-e` WAJIB) dan `npm test`; bandingkan dengan garis dasar T001; laporkan jujur kegagalan lama vs baru
- [ ] T035 Uji layar nyata di browser (`http://localhost:8010/products`, outlet WJM 2 dan WJM 3) mengikuti `quickstart.md` skenario 1–6, termasuk lebar ponsel/tablet untuk baris filter dan drawer; periksa konsol bebas galat; catat hasil di `quickstart.md`
- [X] T036 Verifikasi SC-006 dan SC-005: hitungan item, harga, dan stok sebelum/sesudah identik tanpa filter; ukur muat ulang angka kepala pada ±250 item di bawah 2 dtk
- [X] T037 [P] `npm run build` dan periksa tidak ada heks mentah baru, tidak ada chunk awal yang membengkak (Konstitusi V)

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → cerita**: Phase 2 (T002–T004) memblokir US1 dan US2. US3 dan US4 TIDAK bergantung pada Phase 2 backend (hanya `ProductCatalogQuery` yang disentuh US1/US2) — boleh dimulai paralel setelah T001.
- **US1** (T005–T010) lalu **US2** (T011–T017): US2 memakai `filtered()` (T002) dan pola `watch` US1 (T008); kerjakan US1 dulu agar `ProductsView.vue` tidak bentrok.
- **US3** (T018–T025): independen dari US1/US2. **US4** (T026–T031) bergantung pada T021 (berkas `StoreProductRequest` yang sama) dan T029–T030 menyentuh `ProductFormDrawer.vue`/`ProductVariantsTable.vue` yang sama dengan T023–T024 → kerjakan US3 sebelum US4 untuk menghindari konflik.
- **Polish** setelah cerita yang dipilih selesai.

### Berkas yang sama (jangan paralel)

`ProductCatalogQuery.php`: T002 → T007 → T015 · `ProductController.php`: T007 → T014 → T022 · `ProductsView.vue`: T008 → T016 · `StoreProductRequest.php`: T021 → T028 · `ProductFormDrawer.vue`: T024 → T029 → T030 · `ProductVariantsTable.vue`: T023 → T030 · `locales`: T009, T025, T031 (edit berurutan atau gabungkan).

## Parallel Examples

```text
Setelah T002: T003 ‖ T004 (klien) ‖ T005 ‖ T006 (tes US1) ‖ T011 ‖ T012 ‖ T013 (tes US2)
US3 (paralel dengan US1/US2 bila dua orang): T018 ‖ T019 ‖ T020 → T021 → T022 ; T023 → T024
Polish: T032 ‖ T033 ‖ T037
```

## Implementation Strategy

1. **MVP = US1** (filter Outlet): T001–T010 → demo; nilai terbesar dan perubahan server paling kecil.
2. **US2** menyusul (T011–T017) karena angka kepala yang tak cocok daftar adalah keluhan yang sama; rilis US1+US2 bersama bila memungkinkan.
3. **US3 → US4** (formulir tambah): rilis bersama sebagai satu perbaikan formulir.
4. Setiap fase berhenti di checkpoint dan divalidasi dengan tesnya sendiri sebelum lanjut; tes ditulis lebih dulu dan harus gagal untuk alasan yang benar.

## Catatan risiko (dari research.md)

- D5 diverifikasi: `price_histories.source` enum memuat `'created'` (migrasi) — tidak perlu migrasi.
- D7: tidak ada tes lama yang mengirim `outlet_ids: []` saat membuat produk (pencarian di `tests/`), jadi `min:1` aman; tetap jalankan T034.
- Saat rilis menu aktif, harga outlet `created` ditulis langsung (D5) — dikunci oleh T018(b).
