# Tasks: Stok Menu Berbasis Resep (Web dan Android)

**Klasifikasi**: INTERNAL

**Input**: `specs/092-recipe-based-menu-stock/`: plan.md, spec.md, research.md (D1–D12), data-model.md, contracts/api-changes.md, quickstart.md

**Tes**: DIWAJIBKAN oleh Konstitusi II (backend `tests/Feature/`, web `qa-tests/`, Android `src/test`). Tes ditulis lebih dulu per cerita dan harus gagal sebelum implementasi.

**Format**: `- [ ] T### [P?] [US?] Deskripsi dengan jalur file`. `[P]` = bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai).

**Aturan lingkungan (CLAUDE.md, wajib)**:
- Tes backend di kontainer HANYA dengan: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …` (semua `-e` SEBELUM nama layanan `app`; `-e` sesudah `artisan test` ditolak PHPUnit`.
- SATU proses tes pada satu waktu (jangan jalankan tes terfilter saat suite penuh berjalan).
- Tanpa migrasi pada fitur ini; tidak ada perintah yang menyentuh DB dev kecuali uji dua terminal (T049) dan verifikasi peramban, dan keduanya memakai data uji yang dibuat sendiri.
- Teks UI, komentar, dan pesan commit berbahasa Indonesia; komentar menjelaskan MENGAPA.
- Push dan PR hanya dengan izin eksplisit pengembang.

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/092-recipe-based-menu-stock/evidence.md` (bagian: tes otomatis, verifikasi peramban, uji dua terminal, verifikasi tablet, hal yang belum terverifikasi, risiko APK lama) berlabel INTERNAL
- [X] T002 Jalankan baseline tes terkait di kontainer (`--filter=Recipe`, `--filter=ModifierMaterial`, `--filter=TabLines`, `--filter=MenuAvailability`, `--filter=StockSource`) dan catat hasil awal (lulus/gagal sebelum perubahan, termasuk kegagalan lama yang sudah diketahui) di evidence.md

---

## Phase 2: Foundational (memblokir SEMUA cerita)

**Tujuan**: rumus tunggal, pembaca "varian beresep", penghitung kapasitas, dan pengecualian 409 tersedia untuk semua cerita.

- [X] T003 [P] Tulis `tests/Feature/RecipeMenuStockFormulaTest.php`: rumus `RecipeCost::perPortionNeed` (jumlah ÷ (1 − susut%) ÷ yield × kali pilihan) menghasilkan angka yang SAMA dengan yang dipotong `ModifierMaterialConsumption::consumeOrder` untuk resep bersusut, Portions > 1, dan jumlah pecahan (0,5); tes gagal dulu
- [X] T004 Ekstrak rumus kebutuhan per porsi ke `app/Support/RecipeCost.php::perPortionNeed(line, yield, times)` dan ubah `ModifierMaterialConsumption::addRecipeNeed` (`app/Services/ModifierMaterialConsumption.php`) memakainya; perilaku pemotongan TIDAK berubah (tes 087/089 yang ada tetap hijau, research D2)
- [X] T005 Tambah `ModifierMaterialConsumption::recipeNeed(Collection $items): array` publik di `app/Services/ModifierMaterialConsumption.php`: kebutuhan bahan dari RESEP saja (basis + blok bermodifier), per bahan, tanpa bahan pilihan modifier, memakai `addRecipeNeed` yang sama (satu penerjemah, jangan membuat jalur kedua)
- [X] T006 [P] Tambah `RecipeService::recipeDrivenVariantIds(): array` ber-memo per permintaan di `app/Services/RecipeService.php` (dua kueri: `recipe_variants` + `Recipe::active()`), dan buang memo di semua penulis (`create`, `update`, `removeOne`, `remove`, `applyDue`) (research D6)
- [X] T007 [P] Buat `app/Exceptions/RecipeStockException.php` (409, `code = insufficient_recipe_stock`, membawa `limiting` dan `availablePortions`), meniru pola `BomRuleException`, dan render-nya di `bootstrap/app.php` dengan bentuk contracts/api-changes.md §2 (pesan Indonesia); bentuk respons publik pesan mandiri tanpa `name`/`material_id`
- [X] T008 Buat `app/Services/MenuStockService.php` dengan `forVariants(array $variantIds, int $outletId): array` (kapasitas = min lantai((saldo − tertahan + 0,0005) ÷ kebutuhan per porsi), bahan penghambat deterministik, `unbounded` bila tak ada bahan aktif, saldo minus/tanpa baris = 0, memo per permintaan) sesuai research D3/D4/D9: resep via `activeRecipesForVariants`, saldo via `MaterialOutletStock` (satu kueri), tertahan via satu kueri baris pesanan `open` di outlet itu + `recipeNeed`; semua model ber-`HasDataMode`, tanpa `DB::table()`
- [X] T009 Tambah `MenuStockService::assertCanFulfil(Order $order, Collection $lines): void` di `app/Services/MenuStockService.php`: kunci `material_outlet_stock` bahan terlibat berurutan `material_id` (insertOrIgnore bila belum ada, pola `MaterialStockService`), hitung kebutuhan seluruh baris pesanan ini dan tertahan pesanan `open` lain di outlet yang sama, lempar `RecipeStockException` bila melebihi saldo; hanya kebutuhan RESEP yang dijaga (research D5)

**Checkpoint**: T003 hijau; fondasi siap.

---

## Phase 3: User Story 1 - Kasir melihat stok menu dari bahan resep (P1) 🎯 MVP

**Tujuan**: kartu menu beresep menampilkan porsi dari bahan outlet; menu habis bila ada bahan 0.

**Tes mandiri**: contoh Indomie Kuah (20/10/500/2.000 → 10) menampilkan 10 di `GET /products?pos=1&with_variants=1`; saldo Telur 0 → 0.

### Tes (tulis dulu, harus gagal)

- [X] T010 [P] [US1] `tests/Feature/RecipeMenuStockCapacityTest.php`: contoh spec (10 porsi, penghambat benar), bahan 0 → 0, saldo minus → 0, tanpa baris saldo → 0, susut%, Portions > 1, jumlah pecahan dan toleransi 0,0005, dua resep aktif pada satu varian dijumlahkan, bahan sama di dua resep digabung sebelum dibagi, resep belum berlaku/arsip tidak dihitung, bahan nonaktif/terhapus dilewati, kebutuhan 0 diabaikan, semua bahan nonaktif → `unbounded`, seri penghambat → id terkecil, dua outlet punya saldo berbeda, mode DEMO/LIVE terpisah
- [X] T011 [P] [US1] `tests/Feature/RecipeMenuStockPayloadTest.php`: payload POS varian beresep memuat `track_stock=true`, `outlet_stock=10`, `stock_basis=recipe`, `stock_limiting` benar; varian tanpa resep `stock_basis=variant` dan bidang lama tak berubah; `GET /variants/lookup` bentuk sama; payload pengelolaan (tanpa `pos=1`) tidak memuat tahanan; jumlah kueri sama untuk 5 dan 50 menu beresep (FR-018)
- [X] T012 [P] [US1] `qa-tests/unit/posProductCards.test.js`: kartu dengan `stock_basis=recipe` memakai `outlet_stock` sebagai stok (habis bila 0), `stock_limiting` terbawa ke kartu; payload lama tanpa bidang baru tetap benar

### Implementasi

- [X] T013 [US1] Isi payload POS di `app/Http/Resources/ProductVariantResource.php` (mode `pos`) dan `app/Http/Controllers/Api/ProductController.php` (baris varian `lookupVariants` ~619–622 dan daftar POS): panggil `MenuStockService::forVariants` SEKALI per koleksi (bukan per varian), isi `track_stock`, `outlet_stock`, `stock_basis`, `stock_limiting` menurut contracts/api-changes.md §1; outlet pemesanan dari `OutletContext::orderingId()`, tidak pernah dari body
- [X] T014 [US1] `resources/js/utils/posProductCards.js`: teruskan `stock_basis` dan `stock_limiting` ke kartu dan baris keranjang (`buildProductCards`, `buildSearchCards`, `toCartItem`) tanpa menghitung ulang stok (CLAUDE.md: bentuk kartu hanya di sini)
- [X] T015 [US1] Tampilkan bahan penghambat pada kartu habis/menipis dan di baris keranjang di `resources/js/views/PosView.vue` dan `resources/js/views/TabView.vue`; teks Indonesia di `resources/js/locales/id.json` dan `en.json`; hanya token CSS, tanpa hex

**Checkpoint**: T010–T012 hijau; kartu web menampilkan 10.

---

## Phase 4: User Story 2 - Penjualan memotong bahan, bukan stok varian (P1)

**Tujuan**: baris menu beresep tidak pernah menggerakkan stok varian di jalur mana pun; bahan terpotong tepat sekali.

**Tes mandiri**: jual 2 porsi → Indomie 2, Telur 2, Sayur 100 gr, Kuah 400 ml; stok varian tidak berubah; kirim ulang `local_ref` → tetap sekali.

### Tes (tulis dulu, harus gagal)

- [X] T016 [P] [US2] `tests/Feature/RecipeMenuStockVariantDecouplingTest.php`: varian beresep `tracksOwnStock() = false`; `OrderService::create`, `TabService::addLine/updateLine/removeLine`, `TabSettlementService::settle/split`, dan jalur pesan mandiri tidak membuat `stock_movements` untuk baris itu; `order_items.stock_source = 'modifier'` untuk baris baru; baris tab lama bersnapshot `variant` tetap dikembalikan ke varian (tidak hilang); mengarsipkan resep mengembalikan varian ke stok varian; menu tanpa resep tak berubah; koreksi stok varian beresep → 409 `stock_source_modifier`; laporan stok varian mengecualikan varian beresep
- [X] T017 [P] [US2] `tests/Feature/RecipeMenuStockDeductionTest.php`: contoh spec (2 porsi → 2/2/100/400), satu `sale` per bahan dengan rujukan `order_item`, pesanan dua baris berbagi telur, retry `local_ref` / `client_ref` / `submission_ref` tidak memotong ulang, bahan pilihan modifier tetap dipotong seperti 087/089, biaya resep tidak ditambah ganda ke `order_items.cost_price`

### Implementasi

- [X] T018 [US2] `app/Models/ProductVariant.php`: `tracksOwnStock()` kembali `false` bila `id` ada di `RecipeService::recipeDrivenVariantIds()`; `scopeTracksOwnStock` dan `scopeStockFromVariant` mendapat syarat SQL setara (tidak beresep aktif) (research D6); komentar menjelaskan MENGAPA
- [X] T019 [US2] Telusuri semua pemakai `tracksOwnStock`/`stock_source` (`app/Services/StockService.php`, `StockTransferService.php`, `LowStockHistory.php`, `MasterDataImportService.php`, `MasterDataExportService.php`, `Http/Controllers/Api/StockController.php`, `ReportController.php`) dan pastikan tiap jalur sudah mengikuti aturan baru atau sengaja memakai snapshot; catat hasil dan setiap penyesuaian di evidence.md
- [X] T020 [US2] Pastikan `OrderService::create` dan `TabSettlementService::settle/split` tidak membuat pergerakan stok varian untuk baris beresep dan hanya memotong lewat `consumeOrder` (bukan jalur kedua); perbaiki bila T016/T017 menemukan celah

**Checkpoint**: T016–T017 hijau.

---

## Phase 5: User Story 3 - Pesanan melebihi stok bahan ditolak (P1)

**Tujuan**: tidak ada penjualan berlebih; tab terbuka menahan bahan; penolakan atomik.

**Tes mandiri**: stok 10, pesan 11 → 409; dua tab masing-masing 6 → tab kedua 409; saldo tidak pernah minus.

### Tes (tulis dulu, harus gagal)

- [X] T021 [P] [US3] `tests/Feature/RecipeMenuStockGuardTest.php`: 409 `insufficient_recipe_stock` dengan bahan penghambat di `POST /orders`, `POST /tabs/{id}/items`, `PATCH /tabs/{id}/items/{item}` (naik saja; turun/tetap tidak diperiksa), `settle`, `split`; kebutuhan DIJUMLAHKAN lintas baris berbagi bahan; tahanan tab A mengurangi kapasitas tab B dan stok kartu; hapus baris/ubah turun/batal tab melepas tahanan; pesanan selesai tidak ditahan dua kali; penolakan menggulung semuanya (tanpa baris, tanpa pergerakan, tanpa pembayaran); bahan pilihan modifier tetap `material_warnings`, bukan 409; menu tanpa resep tak berubah; saldo turun lewat koreksi di antara tambah dan bayar → `settle` ditolak; retry `local_ref` tidak diperiksa ulang
- [X] T022 [P] [US3] `tests/Feature/RecipeMenuStockSelfServiceTest.php`: dispatch pesan mandiri yang melebihi bahan digulung penuh (tidak ada order setengah jadi) dan respons publik tidak memuat nama bahan (research D11); ikuti `SelfServicePublicSurfaceTest` untuk pembatas bernama
- [X] T023 [P] [US3] `tests/Feature/RecipeMenuStockLockOrderTest.php`: `assertCanFulfil` mengunci baris saldo berurutan `material_id` (periksa urutan kueri `FOR UPDATE`) dan tidak membuat deadlock pada dua pesanan dengan bahan sama dalam urutan terbalik; label di docblock bahwa ini BUKAN bukti keserentakan sebenarnya (D12)
- [X] T024 [P] [US3] `qa-tests/unit/posCart.test.js` dan `qa-tests/unit/posCartModifiers.test.js`: tombol tambah/qty berhenti di `stock` kartu beresep dan pesan menyebut bahan penghambat; 409 `insufficient_recipe_stock` dipetakan oleh penangan galat pusat ke pesan Indonesia

### Implementasi

- [X] T025 [US3] Panggil `MenuStockService::assertCanFulfil` di `app/Services/TabService.php` (`addLine` setelah `MenuAvailability::assertOrderable` dan sebelum stok varian; `updateLine` hanya bila qty naik), dalam transaksi yang sudah ada
- [X] T026 [US3] Panggil `assertCanFulfil` di `app/Services/OrderService.php::create` (setelah semua baris dibuat, sebelum `consumeOrder`) dan di `app/Services/TabSettlementService.php::settle/split` (sebelum `consumeOrder`); retry yang kembali lebih awal tetap tidak melewati penjaga
- [X] T027 [US3] Pastikan dispatch pesan mandiri (`app/Services/SelfServiceOrderService.php` → `TabService::addLine`/`TabSettlementService::settle`) meneruskan `RecipeStockException` sebagai galat bisnis yang menggulung penuh dan memetakan `code` publik tanpa nama bahan
- [X] T028 [US3] Web: pemetaan 409 `insufficient_recipe_stock` di penangan galat pusat (`resources/js/` api/composable penangan 422/409/403/401) tanpa penangan per layar; `resources/js/stores/posCart.js` menahan qty di `current_stock` kartu beresep; pesan di `resources/js/locales/id.json` dan `en.json`

**Checkpoint**: T021–T024 hijau.

---

## Phase 6: User Story 4 - Web dan Android menampilkan angka yang sama (P2)

**Tujuan**: tablet menampilkan angka identik; angka diperbarui setelah transaksi; APK lama tetap bekerja.

**Tes mandiri**: kartu yang sama di web dan tablet menampilkan 10, lalu 8 setelah menjual 2 dan menyegarkan.

### Tes (tulis dulu, harus gagal)

- [X] T029 [P] [US4] `android-pos/app/src/test/java/com/warkop/pos/data/MenuMapperRecipeStockTest.kt`: DTO varian beresep (`track_stock=true`, `outlet_stock=10`, `stock_basis=recipe`, `stock_limiting`) → `Product.stock = 10` dan bahan penghambat terpetakan; payload lama tanpa bidang baru → perilaku lama (APK lama tidak patah); `track_stock=false` → `stock = null`
- [X] T030 [P] [US4] `qa-tests/unit/posStockRefresh.test.js`: setelah checkout/penyelesaian tab berhasil, daftar menu dimuat ulang dan kartu menampilkan stok baru (memakai API yang di-`vi.mock`)

### Implementasi

- [X] T031 [P] [US4] `android-pos/core-network/src/main/java/com/warkop/core/network/dto/MenuDtos.kt`: tambah `stockBasis` dan `stockLimiting` (nullable, bawaan null) pada DTO varian; jangan ubah `outletStock`/`trackStock`
- [X] T032 [US4] `android-pos/app/src/main/java/com/warkop/pos/data/MenuMapper.kt` dan `android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt`: petakan `stockBasis`/bahan penghambat ke `Product`; rumus `stock` tetap (angka dari server, tanpa menghitung ulang di Kotlin)
- [X] T033 [US4] Android UI (`android-pos/app/src/main/java/com/warkop/pos/ui/` dan layar di `ui/screens/`): tombol tambah/qty berhenti di stok kartu beresep dan pesan menyebut bahan penghambat; petakan 409 `insufficient_recipe_stock` di `RemoteBackend.kt` ke galat yang ditampilkan; istilah baku (`TerminologyScanTest` tetap lulus)
- [X] T034 [US4] Verifikasi dan lengkapi pemuatan ulang menu pasca transaksi (research D8): `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` (`loadMenu(silent = true)` setelah checkout/settle/tambah baris berhasil) dan `resources/js/views/PosView.vue`/`TabView.vue` (muat ulang daftar setelah checkout/settle); jangan menambah polling baru; catat di evidence.md titik mana yang SUDAH ada dan mana yang ditambah
- [X] T035 [US4] Jalankan `./gradlew` unit test Android yang terkait (`MenuMapper*`, `TerminologyScanTest`) dan catat hasil; bila lingkungan Android tidak tersedia di mesin ini, tandai BELUM TERVERIFIKASI di evidence.md (jangan dilaporkan lulus)

**Checkpoint**: T029–T030 hijau.

---

## Phase 7: User Story 5 - Menu tanpa resep tidak berubah (P2)

**Tujuan**: bukti kompatibilitas mundur.

- [X] T036 [US5] `tests/Feature/RecipeMenuStockBackwardCompatTest.php`: menu tanpa resep tetap memotong/mengembalikan stok varian, batas qty varian, 422/409 lama, payload POS identik dengan baseline kecuali `stock_basis: "variant"`; resep diarsipkan → kembali memakai stok varian; produk bersumber `modifier` tanpa resep tak berubah
- [X] T037 [US5] Jalankan seluruh suite backend (SATU proses, flag aman) dan Vitest (`npm test`); semua tes yang ada tetap lulus tanpa mengubah harapan (SC-006); setiap kegagalan lama dicatat apa adanya di evidence.md dan dibedakan dari kegagalan baru

---

## Phase 8: Polish & Cross-Cutting

- [X] T038 [P] Perbarui `docs/openapi-pos-mvp.yaml` di commit yang sama: bidang varian baru (`stock_basis`, `stock_limiting`), respons 409 `insufficient_recipe_stock` pada `POST /orders`, `POST /tabs/{id}/items`, `PATCH /tabs/{id}/items/{item}`, `settle`, `split`
- [X] T039 [P] Catatan bertanggal di `docs/PRD-POS-Event-Multivendor.md` dan CLAUDE.md (bagian Domain rules): pembalikan 087 FR-031 khusus kebutuhan RESEP (keputusan pemilik 2026-11-06), `MenuStockService` satu-satunya penghitung stok menu beresep, varian beresep lepas dari stok varian otomatis, tahanan tab terbuka; jangan menulis ulang riwayat 087
- [X] T040 [P] Tambahkan `MenuStockService` ke `OutletScopeRegistry` bila ia membuat kueri mentah atas tabel ber-outlet (hanya jika ada `DB::table()`; jika tidak ada, catat "tidak perlu" di evidence.md) dan pastikan `OutletScopeRegistryTest` hijau
- [X] T041 Tinjau komentar kode baru (Indonesia, menjelaskan MENGAPA, rujukan D#/FR#) dan hapus kode mati
- [ ] T042 Verifikasi peramban nyata (Konstitusi II, `browser-verify`): dengan data uji contoh spec di dev (DEMO), cek skenario 1–3, 5–8 di quickstart.md pada POS dan Tab; konsol tanpa galat; tangkap bukti ke evidence.md
- [X] T043 Muat 5 dan 50 menu beresep dan catat jumlah kueri sama (SC-007) di evidence.md
- [ ] T044 Uji dua terminal manual (quickstart.md "Uji dua terminal"): 20 kali dua permintaan paralel menambah 6 porsi ke dua tab pada stok 10 dengan saldo direset; harapan tidak pernah dua-duanya berhasil dan saldo tidak pernah minus; catat hasil, JANGAN dilaporkan lulus sebelum dijalankan
- [ ] T045 Verifikasi tablet (quickstart.md "Pemeriksaan klien"): angka sama dengan web, tarik-untuk-segarkan, APK lama; bila perangkat tidak tersedia, tandai BELUM TERVERIFIKASI di evidence.md
- [X] T046 Isi bagian "hal yang belum terverifikasi" dan "risiko" di evidence.md (termasuk dampak D6 pada laporan/koreksi stok varian beresep) dan ringkas untuk pemilik
- [ ] T047 Siapkan commit (Indonesia, satu commit per lapisan bila diminta); push dan PR hanya dengan izin eksplisit pengembang

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → cerita**: Phase 2 memblokir semua cerita.
- **US1, US2, US3** (semua P1) bergantung pada Phase 2. Urutan yang disarankan: **US2 → US1 → US3**, karena payload US1 (`track_stock`) membaca aturan `tracksOwnStock` dari US2 dan penjaga US3 membutuhkan stok varian sudah lepas.
- **US4** bergantung pada US1 (bidang payload) dan US3 (kode 409).
- **US5** (regresi) dijalankan terakhir sebelum Polish; boleh mulai tes kompatibilitas lebih awal.
- **Polish** setelah semua cerita; T042–T045 butuh kode selesai.

### Dalam tiap cerita

Tes → (gagal) → implementasi → tes hijau. Backend sebelum web sebelum Android.

## Parallel Opportunities

- Phase 2: T003, T006, T007 paralel; T004→T005→T008→T009 berurutan (file `ModifierMaterialConsumption`/`MenuStockService` yang sama).
- US1: T010, T011, T012 paralel; T014 dan T015 berbeda file setelah T013.
- US2: T016, T017 paralel.
- US3: T021–T024 paralel.
- US4: T029, T030, T031 paralel; web (T030, T034 bagian web) dan Android (T031–T033, T035) dapat dikerjakan paralel setelah kontrak payload tetap.
- Polish: T038, T039, T040 paralel.

## Implementation Strategy

**MVP (rilis pertama yang berguna)**: Phase 1–2 + **US2 + US1**: angka stok menu dari bahan di web dan tablet (lewat bidang lama) dan stok varian lepas. Pada titik ini penjualan masih boleh minus, jadi belum memenuhi FR-013.
**Penuh untuk keputusan pemilik**: tambah **US3** (penjaga). Jangan merilis US1 tanpa US3 bila pemilik mengandalkan "habis berarti tidak bisa dijual".
Berikutnya US4 (paritas, pemuatan ulang, pesan penghambat), US5 (bukti regresi), lalu Polish.

## Catatan risiko (ke implementasi)

- Mengubah `tracksOwnStock()` (T018) berdampak ke banyak jalur (T019); kerjakan T016 lebih dulu supaya dampak terlihat sebagai tes gagal, bukan temuan lapangan.
- `MenuStockService` dipanggil di payload daftar menu: jaga satu panggilan per koleksi (T011 menguji jumlah kueri).
- Jangan menambah cache lintas permintaan (research D9, Konstitusi V).
