---
description: "Task list — outlet di Restaurant Setup: info + filter pada tab Meja/Stasiun, kolom Outlet di formulir ubah"
---

# Tasks: Outlet di Restaurant Setup

**Klasifikasi**: INTERNAL

**Input**: `/specs/071-restaurant-setup-outlets/` — plan.md, spec.md, research.md (D1–D7), data-model.md, contracts/api-restaurant-setup-071.md, quickstart.md

**Tests**: DISERTAKAN — Konstitusi II mewajibkan tes backend (`tests/Feature/`) dan frontend (`qa-tests/`) plus uji layar nyata. Tulis tes dulu, pastikan GAGAL, baru implementasi.

**Perintah tes** (semua `-e` SEBELUM nama layanan `app`; setelah `artisan test` opsi `-e` ditolak):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Nama>` · frontend: `npx vitest run <berkas>` / `npm test`

## Format: `[ID] [P?] [Story] Deskripsi + path`

- **[P]**: boleh paralel (berkas berbeda, tak bergantung tugas yang belum selesai)
- Komentar kode, teks UI, dan komit dalam bahasa Indonesia; komentar menjelaskan *mengapa*

## Phase 1: Setup

- [X] T001 Catat garis dasar: jalankan `DiningTablesTest`, `StationsTest`, `OutletScopeRegistryTest` dan `npm test` lalu simpan hasilnya di bagian catatan `specs/071-restaurant-setup-outlets/quickstart.md` (kegagalan lama diketahui: `ShiftReportParityTest`)
- [X] T002 Pastikan opsi klien `skipOutletHeader` tersedia (research D1): bila `resources/js/api/client.js` di cabang ini belum memuatnya, ambil HANYA dua berkas dari cabang 070 — `git checkout origin/070-products-outlet-filter-add-form -- resources/js/api/client.js qa-tests/unit/apiClientOutletHeader.test.js` — lalu jalankan `npx vitest run qa-tests/unit/apiClientOutletHeader.test.js`; bila PR #56 sudah digabung ke `develop-warkop-wjm`, cukup `git merge develop-warkop-wjm` dan lewati pengambilan berkas

---

## Phase 2: Foundational (blokir semua cerita)

**Purpose**: Penyempit outlet di server dan penerusan opsi di klien — dipakai US1 (filter) dan US2 (daftar dimuat ulang).

- [X] T003 Tes unit `tests/Feature/OutletContextNarrowedIdsTest.php`: `narrowedIds([..])` = `array_intersect(effectiveIds(), diminta)` — pengguna tanpa penugasan luas hanya mendapat outlet miliknya, header `X-Outlet-Id` ikut menyempitkan, daftar kosong/semua asing → `[]` (GAGAL dulu)
- [X] T004 Tambah `narrowedIds(array $requested): array` di `app/Support/OutletContext.php` (komentar: Konstitusi IV — permintaan hanya menyempit; satu implementasi untuk kedua daftar) — T003 hijau
- [X] T005 [P] Perbarui `resources/js/api/diningTables.js` dan `resources/js/api/stations.js`: `listDiningTables(params = {}, options = {})` dan `listStations(params = {}, options = {})` meneruskan `skipOutletHeader` (hanya bila `options.skipOutletHeader`, sama dengan pola `resources/js/api/products.js` di 070); panggilan lain (`listStations` dari ProductFormDrawer/dapur) TIDAK berubah; perluas `qa-tests/unit/` yang ada untuk dua berkas ini atau tambah `qa-tests/unit/restaurantApiOutlet.test.js`
- [X] T006 [P] Komposable `resources/js/composables/useOutletPageFilter.js` + tes `qa-tests/unit/useOutletPageFilter.test.js`: state `ids` (array id outlet; `[]` = semua), awal dari `outletStore.selected`, mengikuti bila `outletStore.selected` berganti, hanya id yang ada di `outletStore.outlets` yang berlaku, `reset()` kembali ke titik awal (bilah atas atau semua), `active` = beda dari titik awal, `visible` = `outletStore.multi` (research D2)

**Checkpoint**: fondasi siap.

---

## Phase 3: User Story 1 — Info outlet dan filter pada tab Meja dan Stasiun (Priority: P1) 🎯 MVP

**Goal**: Kolom Outlet pada kedua tabel dan filter Outlet pilihan-banyak (dibagi antara tab Meja dan Stasiun).

**Independent Test**: dua outlet dengan meja/stasiun berbeda → kolom menunjukkan outletnya → pilih satu outlet di filter → hanya baris outlet itu; satu outlet → kolom dan filter hilang.

### Tests (tulis dulu, harus GAGAL)

- [X] T007 [P] [US1] Tes Feature `tests/Feature/RestaurantSetupOutletFilterTest.php`: `GET /dining-tables?outlet_id[]=` dan `GET /stations?outlet_id[]=` — satu outlet, dua outlet (tanpa duplikat), outlet di luar penugasan dipotong (hasil kosong, bukan galat), tanpa parameter = perilaku lama, `outlet_id[]=abc` → 422, header `X-Outlet-Id` tetap menyempitkan, tiap baris tetap membawa `outlet_id`, jumlah query tidak tumbuh dengan jumlah baris (pola `ProductsOutletsListTest`)
- [X] T008 [P] [US1] Tes Vitest `qa-tests/component/RestaurantSetupOutletFilter.test.js` (pola `TablesAdminOutlet.test.js`): filter tampil hanya bila `outletStore.multi` dan hanya di tab Meja/Stasiun (tidak di Modifiers); memilih outlet memanggil `listDiningTables`/`listStations` dengan `outlet_id[]` dan `{ skipOutletHeader: true }`; terisi dari bilah atas dan mengikuti saat berganti; pilihan dibagi saat pindah tab; "Semua outlet" yang disengaja bertahan walau bilah atas memilih satu outlet; hasil kosong → pesan kosong dengan tombol atur ulang
- [X] T009 [P] [US1] Perluas `qa-tests/component/TablesAdmin.test.js` dan `StationsAdmin.test.js`: kolom Outlet (kode tebal + nama dari `outletStore.outlets`) hanya saat multi-outlet; tidak ada kolom di satu outlet; baris dengan `outlet_id` tak dikenal menampilkan "—"

### Implementation

- [X] T010 [US1] `app/Http/Controllers/Api/DiningTableController.php::index` dan `StationController.php::index`: terima `outlet_id[]` (validasi array integer → 422) dan sempitkan dengan `OutletContext::narrowedIds()` (`whereIn outlet_id`, kosong → hasil kosong); tanpa parameter tak berubah — T007 hijau
- [X] T011 [US1] `resources/js/views/DiningSetupView.vue`: pasang `useOutletPageFilter`, tampilkan `BaseMultiSelect` "Semua outlet" di atas isi tab Meja/Stasiun (bukan Modifiers), kirim `outletIds` sebagai prop ke `TablesAdmin`/`StationsAdmin`, tombol "Atur ulang filter" saat aktif; perbarui docblock berkas
- [X] T012 [P] [US1] `resources/js/components/restaurant/TablesAdmin.vue`: prop `outletIds`; `load()` memanggil `listDiningTables({ outlet_id: outletIds }, { skipOutletHeader: true })` (tanpa `outlet_id` bila kosong) dan memuat ulang saat prop berubah; kolom Outlet (hanya `outletStore.multi`) dengan sel kode+nama; keadaan kosong menyebut filter bila aktif
- [X] T013 [P] [US1] `resources/js/components/restaurant/StationsAdmin.vue`: perubahan yang sama dengan T012 untuk stasiun
- [X] T014 [P] [US1] Teks baru (kolom Outlet, filter "Semua outlet", pesan kosong terfilter) di `resources/js/locales/id.json` dan `en.json` (bagian `dining_setup`); pakai ulang `frame.outlet_label` — T008/T009 hijau, `npx vitest run qa-tests/component/TablesAdmin*.test.js qa-tests/component/StationsAdmin.test.js` tetap hijau

**Checkpoint**: US1 mandiri dan dapat didemokan (MVP).

---

## Phase 4: User Story 2 — Ubah outlet meja/stasiun di formulir ubah (Priority: P1)

**Goal**: Kolom Outlet di Edit table / Edit station yang memindahkan item, dengan penolakan jelas bila tak boleh.

**Independent Test**: Edit table → Outlet = outlet lain → Save → meja pindah; meja bertab terbuka dan stasiun yang dipakai ditolak dengan pesan alasan; riwayat tak berubah.

### Tests (tulis dulu, harus GAGAL)

- [X] T015 [P] [US2] Tes Feature `tests/Feature/OutletMoveGuardTest.php` (unit service): meja → blocker bila ada order `open`, bebas bila hanya order selesai/batal; stasiun → blocker `in_use` bila `categories.station_id` ATAU `products.station_id` (termasuk produk soft-deleted dan tanpa global scope) menunjuknya, blocker `open_tickets` bila ada tiket dengan item `new|preparing|ready`, bebas bila semua tiket `served|cancelled`
- [X] T016 [P] [US2] Tes Feature `tests/Feature/DiningTableMoveTest.php`: pindah ke outlet lain yang diizinkan → 200, `outlet_id` baru, muncul di daftar outlet baru dan tidak di lama; log `moved` (lama → baru, pengguna) ditulis; order lama tetap `outlet_id` semula; tab terbuka → 409 pesan `table_move_open_tab` dan meja tidak berubah; outlet tujuan tidak diizinkan → 403 `outlet_forbidden`; outlet tidak ada → 422; nama sama di tujuan → 422 pada `name`; `outlet_id` sama dengan sekarang → 200 tanpa log; perubahan lain (nama, kursi) dalam permintaan yang gagal tidak tersimpan (atomik); pengguna tanpa menu `dining_setup` ditolak seperti sebelumnya
- [X] T017 [P] [US2] Tes Feature `tests/Feature/StationMoveTest.php`: kasus yang sama untuk stasiun — pindah berhasil + log; dipakai kategori → 409 `station_move_in_use`; dipakai produk → 409; tiket dapur belum selesai → 409 `station_move_open_tickets`; tiket lama selesai tetap di outlet lama; nama sama di tujuan → 422; outlet tak diizinkan → 403; mode DEMO/LIVE tidak bocor
- [X] T018 [P] [US2] Tes Vitest `qa-tests/component/RestaurantEditOutlet.test.js` (pola `TablesAdminOutlet.test.js`): formulir Edit table/Edit station multi-outlet menampilkan `BaseSelect` Outlet terisi outlet saat ini; mengganti dan menyimpan mengirim `outlet_id`; tidak diganti → payload TANPA `outlet_id`; tidak tampil di satu outlet; galat 422 pada `name`/`outlet_id` tampil di bidangnya dan modal tetap terbuka; formulir buat tidak berubah (kolom Outlet tetap hanya saat membuat)

### Implementation

- [X] T019 [US2] Service `app/Services/OutletMoveGuard.php`: `tableBlocker(DiningTable): ?string` dan `stationBlocker(Station): ?string` (kode alasan `open_tab` / `in_use` / `open_tickets`), memakai `withoutGlobalScopes()` pada `Order`, `Category`, `Product`, `KitchenTicket`, `KitchenTicketItem` dan `KitchenTicketItem::UNFINISHED`; komentar: satu implementasi aturan (Konstitusi I), mengapa tanpa scope (FK tak peduli mode/outlet) — T015 hijau
- [X] T020 [P] [US2] Pesan penolakan di `lang/id/restaurant.php` dan `lang/en/restaurant.php`: `table_move_open_tab`, `station_move_in_use`, `station_move_open_tickets` (menyebut apa yang harus diselesaikan dulu)
- [X] T021 [US2] `app/Http/Requests/UpdateDiningTableRequest.php` dan `UpdateStationRequest.php`: aturan `outlet_id` (`sometimes`, integer, `exists:outlets,id`); ubah pemeriksaan keunikan nama di `StoreDiningTableRequest::after()` dan `StoreStationRequest::after()` agar outlet efektif pada ubah = `outlet_id` yang dikirim bila ada, selain itu outlet saat ini (research D6); `UpdateStationRequest` perlu mewarisi/menyalin pemeriksaan nama per outlet (saat ini hanya aturan dasar) — tes T016/T017 bagian 422 hijau
- [X] T022 [US2] `DiningTableController::update`: bila `outlet_id` dikirim dan berbeda → 403 bila `! isPermitted`, 409 dari `OutletMoveGuard::tableBlocker`, lalu dalam `DB::transaction` set `outlet_id` eksplisit (tidak fillable), simpan, `ActivityLogger` aksi `moved` (deskripsi menyebut kode outlet lama → baru, `oldValues/newValues` memuat `outlet_id`); `outlet_id` dikeluarkan dari `$request->payload()` untuk `update($data)`; perubahan lain ikut transaksi yang sama — T016 hijau
- [X] T023 [US2] `StationController::update`: logika yang sama dengan T022 memakai `stationBlocker` — T017 hijau
- [X] T024 [P] [US2] `resources/js/components/restaurant/TablesAdmin.vue`: `BaseSelect` Outlet (`v-if outletStore.multi`) juga saat mengubah; `openEdit` mengisi `form.outlet_id = table.outlet_id`; payload memuat `outlet_id` hanya bila berbeda dari nilai awal (simpan nilai awal); perbarui komentar "outlet hanya dikirim saat membuat"; pesan 409 sudah ditoast global
- [X] T025 [P] [US2] `resources/js/components/restaurant/StationsAdmin.vue`: perubahan yang sama dengan T024 untuk stasiun — T018 hijau
- [X] T026 [P] [US2] Teks yang dibutuhkan formulir ubah (bila ada) di `resources/js/locales/id.json` dan `en.json`

**Checkpoint**: US1 + US2 berfungsi bersama.

---

## Phase 5: Polish & Cross-Cutting

- [X] T027 [P] Perbarui `docs/openapi-pos-mvp.yaml` dalam komit yang sama: `outlet_id[]` pada `GET /dining-tables` dan `GET /stations`; `outlet_id` + respons 403/409/422 pada `PUT /dining-tables/{id}` dan `PUT /stations/{id}`; selaraskan `contracts/api-restaurant-setup-071.md` bila ada selisih
- [X] T028 [P] Catatan bertanggal di `CLAUDE.md` (Domain rules, "Outlets"): outlet meja/stasiun kini bisa dipindah lewat `PUT` dengan `OutletMoveGuard`; daftar Restaurant Setup tanpa header outlet; komentar lama "outlet tetap sejak dibuat" di `DiningTableResource`/`StationResource` diperbarui
- [X] T029 Jalankan seluruh suite backend (kontainer, flag `-e` sebelum `app`) dan `npm test`; bandingkan dengan garis dasar T001; laporkan jujur kegagalan lama vs baru; pastikan `OutletScopeRegistryTest` tetap hijau (tanpa kueri mentah baru)
- [ ] T030 Uji layar nyata di browser (`http://localhost:8010`, Restaurant Setup, outlet WJM 2 dan WJM 3) mengikuti `quickstart.md` skenario 1–5, termasuk lebar tablet; periksa konsol bebas galat; catat hasil di `quickstart.md` (bila browser MCP tidak tersambung, catat sebagai belum dilakukan — jangan dianggap lulus)
- [X] T031 Verifikasi data nyata (baca saja, via `php artisan tinker` seperti 070): untuk tiap filter outlet jumlah baris API = hitungan manual; SC-005: order/tiket lama tidak berubah `outlet_id` setelah satu pemindahan di basis data TES (bukan data dev)
- [X] T032 `npm run build` dan periksa tidak ada heks mentah baru (token Tailwind saja)

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → cerita**: T002 (`skipOutletHeader`) memblokir T005/T008/T011–T013; T003–T004 memblokir T010.
- **US1** (T007–T014) dan **US2** (T015–T026) berbeda berkas di backend; di frontend keduanya menyentuh `TablesAdmin.vue`/`StationsAdmin.vue` → kerjakan US1 dulu (T012/T013 sebelum T024/T025).
- US2 backend (T015–T023) tidak bergantung pada US1 dan boleh paralel dengan US1 frontend bila dua orang.
- **Polish** setelah cerita yang dipilih selesai.

### Berkas yang sama (jangan paralel)

`DiningTableController.php`: T010 → T022 · `StationController.php`: T010 → T023 · `StoreDiningTableRequest.php`/`StoreStationRequest.php`/`Update*Request.php`: T021 · `TablesAdmin.vue`: T012 → T024 · `StationsAdmin.vue`: T013 → T025 · `locales`: T014, T026 (edit berurutan) · `docs/openapi-pos-mvp.yaml`: T027.

## Parallel Examples

```text
Setelah T002: T003 → T004 ; T005 ‖ T006
US1: T007 ‖ T008 ‖ T009 → T010 → T011 → T012 ‖ T013 ‖ T014
US2 backend (paralel dengan US1 frontend): T015 ‖ T016 ‖ T017 ‖ T018 → T019 → T020 ‖ T021 → T022 → T023
US2 frontend (setelah T012/T013): T024 ‖ T025 ‖ T026
Polish: T027 ‖ T028 ‖ T032
```

## Implementation Strategy

1. **MVP = US1** (T001–T014): kolom + filter — nilai terbesar, tanpa risiko data (hanya membaca).
2. **US2** (T015–T026): pemindahan menyentuh data hidup; kerjakan setelah US1 dan wajib melewati seluruh tes penjaga sebelum UI dihubungkan.
3. Tiap fase berhenti di checkpoint dan divalidasi dengan tesnya sendiri; tes ditulis lebih dulu dan harus gagal untuk alasan yang benar.

## Catatan risiko (dari research.md)

- D1: PR #56 belum digabung → T002 memastikan `skipOutletHeader`; bila diambil per berkas, merge berikutnya bersih karena isinya identik.
- D5/D6: `UpdateStationRequest` kemungkinan belum memeriksa nama per outlet pada ubah (aturan dasar saja) — T021 menanganinya; periksa dulu isinya sebelum mengubah.
- Pemindahan menyentuh data hidup: uji pemindahan hanya di basis data TES; data dev tidak diubah kecuali atas permintaan.
