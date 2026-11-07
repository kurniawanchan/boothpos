# Tasks: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL

**Input**: `specs/093-product-as-ingredient/`: plan.md, spec.md, research.md (D1–D14), data-model.md, contracts/api-changes.md, quickstart.md

**Tes**: DIWAJIBKAN oleh Konstitusi II (backend `tests/Feature/`, web `qa-tests/`, Android `src/test`). Tes ditulis lebih dulu per cerita dan harus gagal sebelum implementasi.

**Format**: `- [ ] T### [P?] [US?] Deskripsi dengan jalur file`. `[P]` = bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai).

**Aturan lingkungan (CLAUDE.md, wajib)**:
- Tes backend di kontainer HANYA dengan: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …` (semua `-e` SEBELUM `app`).
- SATU proses tes pada satu waktu; jangan jalankan tes terfilter saat suite penuh berjalan (memori `one-test-process-per-test-db`).
- Fitur ini MEMBAWA satu migrasi skema. Jangan menjalankannya pada DB dev sebelum T006 lulus di DB uji dan pengguna menyetujui; jangan mengubah nama atau urutan tanggal migrasi lain.
- Teks UI, komentar, dan pesan commit berbahasa Indonesia; komentar menjelaskan MENGAPA.
- Push dan PR hanya dengan izin eksplisit pengembang.

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/093-product-as-ingredient/evidence.md` (bagian: tes otomatis, verifikasi peramban, uji dua koneksi, verifikasi tablet, hal yang belum terverifikasi, risiko APK lama)
- [X] T002 Jalankan baseline tes terkait di kontainer (`--filter=Recipe`, `--filter=ModifierMaterial`, `--filter=RecipeMenuStock`, `--filter=StockLedgerInvariant`, `--filter=MasterDataImportRecipeModifier`) dan catat hasil awal (lulus/gagal sebelum perubahan) di `evidence.md`
- [X] T003 [P] Audit SEMUA pembaca `stock_movements.type` (riset D4, risiko 2): telusuri `app/Http/Controllers/Api/StockController.php`, `app/Http/Controllers/Api/ReportController.php`, `app/Services/LowStockHistory.php`, `app/Services/MasterDataExportService.php`, `resources/js/utils/stockMovements.js`, `resources/js/views`, dan catat per pembaca: aman / perlu cabang tipe baru, di bagian "Audit pembaca tipe" `evidence.md`
- [X] T004 [P] Verifikasi dua asumsi yang belum terbukti dan catat di `evidence.md`: (a) `ImportsRecipesAndModifiers` (`app/Services/Concerns/ImportsRecipesAndModifiers.php`) mengganti baris resep di tempat — apakah baris varian akan terhapus (D11); (b) `android-pos/app/src/main/java/com/warkop/pos/` (`PosStore`, keranjang) tidak mengurangi stok secara lokal dan tidak punya antrean tulis offline (D13)

---

## Phase 2: Foundational (memblokir SEMUA cerita)

**Tujuan**: skema, model, memo "varian dirujuk resep", dan tipe pergerakan baru tersedia untuk semua cerita.

- [X] T005 [P] Tulis `tests/Feature/IngredientSchemaTest.php`: kolom `products.use_as_ingredient` (bawaan false) dan `ingredient_unit`; `recipe_lines.variant_id` FK + `material_id` nullable; CHECK menolak baris dengan keduanya terisi dan keduanya kosong (kueri mentah); ENUM `stock_movements.type` menerima `recipe_sale|recipe_return|recipe_waste`; baris resep lama tetap valid
- [X] T006 Buat migrasi `database/migrations/2027_01_04_000001_add_product_as_ingredient.php` (tanggal SETELAH `2027_01_03_000004`): tambah dua kolom `products`; `recipe_lines.material_id` nullable + `variant_id` FK `restrictOnDelete` + indeks `idx_recipe_lines_variant` + CHECK XOR via `DB::statement`; `ALTER TABLE stock_movements MODIFY type ENUM(...)` dengan tiga tipe baru; `down()` menolak bila ada baris bertipe baru atau baris resep ber-`variant_id` (preseden `2026_12_30_000002`). Jalankan T005 hingga hijau di DB uji
- [X] T007 [P] Perbarui model `app/Models/Product.php` (`$fillable` + cast boolean untuk `use_as_ingredient`, `ingredient_unit`), `app/Models/RecipeLine.php` (`variant_id` di `$fillable`, relasi `variant()` ke `ProductVariant` `withTrashed`, helper `isProduct()`), dan konstanta tipe pergerakan di `app/Models/StockMovement.php` (`RECIPE_SALE`, `RECIPE_RETURN`, `RECIPE_WASTE`)
- [X] T008 [P] Tulis `tests/Feature/IngredientVariantsMemoTest.php`: himpunan `IngredientVariants` memuat varian yang dirujuk resep TIDAK-arsip (termasuk berlaku-masa-depan), tidak memuat resep arsip; kunci memo = hari bisnis + mode data; dibuang (`forget`) oleh `RecipeService::create/update/removeOne/remove`; jumlah kueri tetap
- [X] T009 Buat `app/Support/IngredientVariants.php` meniru `app/Support/RecipeDrivenVariants.php` (`ids()`, `has()`, `forget()`, dua kueri, kunci hari bisnis + mode), daftarkan scoped di `app/Providers/AppServiceProvider.php` seperti `RecipeDrivenVariants`, dan panggil `forget()` dari semua penulis resep di `app/Services/RecipeService.php` serta event model `app/Models/Recipe.php`
- [X] T010 [P] Tambah kunci bahasa (id + en) untuk semua pesan di `contracts/api-changes.md` §3 dan §5 di `lang/id/products.php`, `lang/en/products.php`, `lang/id/orders_payments.php`, `lang/en/orders_payments.php` (`recipe_ingredient_required`, `…_not_flagged`, `…_self`, `…_recursive`, `…_untracked`, `…_no_shrinkage`, `…_whole_quantity`, `recipe_product_is_ingredient`, `ingredient_in_use`, `insufficient_stock_reserved`)

**Checkpoint**: T005 dan T008 hijau; skema dan memo siap.

---

## Phase 3: User Story 1 - Pemilik menandai produk sebagai bahan dan memakainya di resep (P1) 🎯 MVP

**Tujuan**: penanda di formulir produk; pilihan bahan resep memuat produk bertanda; resep menyimpan baris varian tanpa membuat saldo baru.

**Tes mandiri**: tandai Telur (200) dan Indomie Goreng (100); simpan resep Indomie Kuah berisi keduanya; `variant_outlet_stock` tidak bertambah baris, tidak ada catatan `materials` baru.

### Tes (tulis dulu, harus gagal)

- [X] T011 [P] [US1] `tests/Feature/IngredientFlagTest.php`: `POST/PUT /products` menerima `use_as_ingredient` dan `ingredient_unit`; ditolak 422 bila produk tak melacak stok, bersumber `modifier`, atau punya varian dengan resep tidak-arsip; peran tanpa menu `products` 403; perubahan penanda tercatat di `activity_logs` dalam transaksi yang sama; produk tetap bisa dijual langsung
- [X] T012 [P] [US1] `tests/Feature/IngredientPickerTest.php`: `GET /recipe-ingredients` hanya memuat varian produk bertanda yang aktif, melacak stok, bersumber `variant`, tidak beresep; menghormati `exclude_product_id` dan `search`; `available = on_hand − held` di outlet terpilih; outlet dari konteks server, bukan body; tanpa N+1 (jumlah kueri tetap)
- [X] T013 [P] [US1] `tests/Feature/IngredientRecipeValidationTest.php` (bagian US1): baris `variant_id` tersimpan, bentuk `blocks[]` dan bentuk lama `lines[]`; tepat satu dari `material_id`/`variant_id`; campuran bahan baku + varian dalam satu resep; baris `shrinkage_pct ≠ 0` ditolak; `quantity ÷ yield` bukan bulat ≥ 1 ditolak (`recipe_ingredient_whole_quantity`); baris varian kembar dalam satu blok ditolak; penyajian `presentLines` untuk baris varian (nama, sku, unit, `kind: product`, biaya dari `cost_price` varian, null bila 0); tanpa saldo atau catatan bahan baku baru
- [X] T014 [P] [US1] `qa-tests/unit/recipeIngredients.test.js` dan `qa-tests/component/RecipeDrawerIngredient.test.js`: pemetaan opsi produk ke pilihan bahan (grup "Produk"), aturan bulat tampilan, kolom susut disembunyikan untuk baris produk, baris dengan produk yang tak lagi bertanda tetap dirender dengan penanda; API di-`vi.mock`

### Implementasi

- [X] T015 [US1] Tambah `use_as_ingredient`/`ingredient_unit` ke `app/Http/Requests/StoreProductRequest.php` dan `app/Http/Requests/UpdateProductRequest.php` dan ke `app/Http/Resources/ProductResource.php`; larangan menyalakan penanda (stok tak dilacak / sumber `modifier` / varian beresep tidak-arsip) di service produk yang dipanggil `app/Http/Controllers/Api/ProductController.php` (bukan di controller), tulis `ActivityLogger` aksi `ingredient_flag_changed` di transaksi yang sama
- [X] T016 [US1] Perluas `app/Services/RecipeService.php::validate` untuk baris `variant_id` (aturan US1 di data-model.md: XOR, varian ada dan aktif, produk bertanda untuk baris BARU atau berubah, tanpa susut, kuantitas bulat), tulis baris varian di `writeBody`/`blocksFrom`/`snapshot`, dan perluas `presentLines`/`cost`/`variantCost` memakai `cost_price` varian (D10). Aturan rekursi dan larangan lain milik US6
- [X] T017 [US1] Tambah `recipe-ingredients` ke `app/Http/Controllers/Api/RecipeController.php` (`ingredients`), rute `GET /recipe-ingredients` di `routes/api.php` (izin menu `products`), dan hitung `held` memakai penghitung tahanan varian dari `app/Services/MenuStockService.php` (stub yang mengembalikan 0 diperbolehkan sampai US3; tandai TODO bertanggal) — hasilkan bentuk respons `contracts/api-changes.md` §2
- [X] T018 [P] [US1] `resources/js/api/recipes.js`: fungsi `listRecipeIngredients(params)`; muatan baris resep membawa `variant_id` atau `material_id`
- [X] T019 [US1] `resources/js/views/products/ProductFormDrawer.vue`: toggle "Gunakan sebagai bahan" + isian label satuan (muncul hanya bila menyala), disembunyikan bagi peran tanpa izin, memakai token desain; kunci locale `resources/js/locales/id.json` dan `en.json`
- [X] T020 [US1] `resources/js/components/product/RecipeDrawer.vue` dan `RecipeSection.vue`: grup "Produk" di pilihan bahan (nama, SKU, satuan, stok tersedia), baris produk menyembunyikan susut dan memakai input bilangan bulat, baris lama yang produknya tak lagi bertanda ditandai "tidak lagi tersedia untuk resep baru"; error 422 dipetakan lewat penangan pusat, bukan per layar

**Checkpoint**: T011–T014 hijau; resep dengan bahan produk tersimpan dari UI.

---

## Phase 4: User Story 2 - Penjualan menu beresep memotong saldo produk bahan (P1)

**Tujuan**: pesanan selesai memotong saldo varian bahan lewat `StockService`; void/refund memulihkan dari buku besar.

**Tes mandiri**: Goreng 100, Telur 200; jual 1 Kuah → 99 dan 199; void → 100 dan 200 walau resep diubah setelah jual.

### Tes (tulis dulu, harus gagal)

- [X] T021 [P] [US2] `tests/Feature/IngredientConsumptionTest.php`: `OrderService::create`, `TabSettlementService::settle`, dan `split` memotong varian bahan sebesar `per porsi × qty` dengan tipe `recipe_sale`, `reference_type=order_item`, `reference_id` = id baris menu; satu pesanan 2 Kuah + 1 Telur langsung → Telur −3 dengan dua sumber berbeda; bahan baku + varian dalam satu resep atomik (gagal satu → tidak ada yang berubah); blok bermodifier hanya bila pilihannya dipesan; pesanan lama tidak berubah saat resep diedit
- [X] T022 [P] [US2] `tests/Feature/IngredientRestoreTest.php`: `OrderService::void` dan `RefundService` memulihkan `recipe_sale − recipe_return` per (varian, baris) sebagai `recipe_return`, TIDAK memakai resep saat ini; `wasteLine` menulis `recipe_waste` dan tidak pernah kembali; void dua kali tidak memulihkan dua kali
- [X] T023 [P] [US2] `tests/Feature/IngredientIdempotencyTest.php`: pengiriman ulang dengan `local_ref`/`client_ref`/`submission_ref` yang sama tidak memotong lagi; `consumeOrder` dipanggil dua kali pada pesanan yang HANYA berbahan varian tidak memotong dua kali (sabuk pengaman buku besar `stock_movements`)

### Implementasi

- [X] T024 [US2] `app/Services/ModifierMaterialConsumption.php`: `addRecipeNeed`/`needFor`/`recipeNeed` juga menghasilkan `variantNeed[variant_id][order_item_id] = int` (dibulatkan; log peringatan bila selisih > 1e-6, D7) dan peta varian; pertahankan bentuk kembalian lama untuk pemanggil yang ada (tambahkan elemen, jangan ubah urutan) dan sesuaikan `MenuStockService` pemanggilnya
- [X] T025 [US2] `app/Services/ModifierMaterialConsumption.php::consumeOrder`: tulis kebutuhan varian lewat `StockService::applyMovement(type: recipe_sale)` berurutan `variant_id` lalu `order_item_id`; perluas sabuk pengaman "sudah dipotong" ke `stock_movements` (tipe `recipe_sale` per baris); kumpulkan galat stok negatif sebagai 409 (penjaga US4 mencegahnya lebih dulu)
- [X] T026 [US2] `app/Services/ModifierMaterialConsumption.php::restoreOrder` dan `wasteLine`: pulihkan/buang varian dari buku besar `recipe_sale`/`recipe_return` (dikelompokkan `variant_id`, `reference_id`), tipe `recipe_return`/`recipe_waste`, outlet pesanan; perbarui panggilan di `app/Services/OrderService.php`, `app/Services/RefundService.php`, `app/Services/TabService.php` bila signature berubah
- [X] T027 [US2] Tambah label dan warna tipe baru di `resources/js/utils/stockMovements.js` (`recipe_sale`, `recipe_return`, `recipe_waste`) dan kunci `master_data.type_recipe_*` di `resources/js/locales/id.json`, `en.json`, `lang/id/master_data.php`, `lang/en/master_data.php`; masukkan ke `MOVEMENT_TYPES` untuk filter

**Checkpoint**: T021–T023 hijau; skenario quickstart 4, 8, 9, 15 lolos di tes.

---

## Phase 5: User Story 3 - Stok menu memperhitungkan saldo bersama dan tahanan (P1)

**Tujuan**: kartu menu dan kartu produk bahan menampilkan angka yang memperhitungkan tab terbuka; POS web dan Android identik.

**Tes mandiri**: Goreng 100, Telur 50 → Kuah 50; jual 10 Telur langsung → 40; tab terbuka 5 Kuah → 35 dan Telur langsung tersedia 35.

### Tes (tulis dulu, harus gagal)

- [X] T028 [P] [US3] `tests/Feature/IngredientCapacityTest.php`: `MenuStockService::forVariants` memakai bahan varian + bahan baku sebagai penghambat (terendah menang, seri jatuh ke id terkecil secara deterministik antar jenis); tahanan tab terbuka dikurangkan; `stock_limiting` memuat `ingredient_type`, `variant_id` (null untuk bahan baku), `material_id` null untuk bahan varian; varian bahan 0/minus → 0 porsi
- [X] T029 [P] [US3] `tests/Feature/IngredientPayloadTest.php`: `GET /products?pos=1`, `GET /variants/lookup`, dan menu publik pesan mandiri: `outlet_stock` varian produk bahan = saldo − tahanan; `stock_basis` tetap; klien lama tetap benar (field baru aditif); jumlah kueri tidak bertambah per varian (prime sekali)
- [X] T030 [P] [US3] `qa-tests/unit/posProductCards.test.js`: kartu produk bahan memakai `outlet_stock` dari server tanpa menghitung ulang; `stock_limiting` varian dibawa ke kartu

### Implementasi

- [X] T031 [US3] `app/Services/MenuStockService.php`: tambah penghitung tahanan varian (`heldVariantsBy(outletId, exceptOrderId)`) memakai `recipeNeed` terperluas dari baris tab `open` (OutletScope dilewati dengan sengaja seperti `heldBy`, dengan komentar MENGAPA); `forVariants` dan `perPortionNeeds` memuat bahan varian (saldo `VariantOutletStock` − tahanan); jaga jumlah kueri tetap terhadap banyaknya varian; hilangkan stub T017
- [X] T032 [US3] `app/Services/MenuStockService.php::prime/forRequest` dan `app/Support/OutletPriceBook.php::orderingStockOrNull`: muat tahanan varian SEKALI per permintaan dan kurangkan dari `outlet_stock` varian yang ada di `IngredientVariants`; `app/Http/Resources/ProductVariantResource.php` dan `app/Http/Controllers/Api/SelfServicePublicController.php` memakainya (menu publik `sold_out`, tanpa nama bahan di respons publik, aturan 092)
- [X] T033 [US3] `app/Services/MenuStockService.php::assertCanFulfil` dan `assertQuantitiesAvailable`: sertakan kebutuhan varian (saldo varian − tahanan pesanan LAIN), lempar `RecipeStockException` 409 dengan `limiting.variant_id`; render di `bootstrap/app.php` tetap satu renderer
- [X] T034 [US3] Periksa pembaca `stock_limiting.material_id` di `resources/js` dan `android-pos/` (riset, risiko 5) dan tangani `material_id` null + `variant_id`; catat hasil di `evidence.md`; sesuaikan `resources/js/views/PosView.vue` dan `TabView.vue` bila menampilkan nama penghambat
- [X] T035 [P] [US3] Tes kontrak Android: `android-pos/app/src/test/` — `MenuMapper` memetakan `outlet_stock` varian bahan apa adanya (tanpa perhitungan lokal) dan tidak gagal pada `stock_limiting` berbentuk baru; jika T004(b) menemukan pengurangan lokal, catat sebagai temuan di `evidence.md` dan hentikan untuk keputusan pemilik

**Checkpoint**: T028–T030, T035 hijau; kartu menu dan kartu bahan sama di web dan tablet menurut payload.

---

## Phase 6: User Story 4 - Aman terhadap pesanan serentak dan kiriman ulang (P1)

**Tujuan**: tidak ada overselling bahan bersama; tahanan menjaga penjualan langsung; urutan kunci tunggal; deadlock aman.

**Tes mandiri**: Telur tersisa 1, dua koneksi menyelesaikan 1 Kuah serentak → tepat satu berhasil, saldo 0.

### Tes (tulis dulu, harus gagal)

- [X] T036 [P] [US4] `tests/Feature/IngredientHoldTest.php`: penjualan langsung varian produk bahan yang menembus tahanan resep tab terbuka → 409 `insufficient_stock_reserved` (`variant_id`, `sku`, `available`, `reserved`); tanpa tahanan, kekurangan murni tetap `ValidationException` lama; varian di luar `IngredientVariants` tidak menambah kueri; pesanan yang sama menahan kebutuhan resepnya sendiri; resep tak bertanda tetapi masih dirujuk tetap dijaga
- [X] T037 [P] [US4] `tests/Feature/IngredientLockOrderTest.php` (perluas pola `tests/Feature/RecipeMenuStockLockOrderTest.php`): pemindai sumber/urutan memastikan penjaga mengunci berurutan material → saldo material → varian (id naik) → saldo varian, dan `OrderService::create` memanggil penjaga SEBELUM pergerakan stok; `MenuStockService::transaction` memakai percobaan ulang deadlock
- [X] T038 [P] [US4] `tests/Feature/IngredientConcurrencyTest.php`: dua koneksi MySQL nyata (proses terpisah, READ COMMITTED) — 20 penyelesaian paralel atas sisa 1 bahan → tepat satu berhasil, saldo tidak minus, 0 pemotongan ganda; jual langsung vs jual menu berebut sisa terakhir → hanya satu lolos. Bila tidak dapat dijalankan, catat "belum diverifikasi" di `evidence.md`, jangan dilewati diam-diam

### Implementasi

- [X] T039 [US4] `app/Services/StockService.php::applyMovement` (tipe `sale`, `qtyChange < 0`, varian `IngredientVariants::has`): setelah kunci varian + saldo, baca tahanan varian (resolusi lazy `app(MenuStockService::class)` untuk memutus ketergantungan melingkar, dengan komentar MENGAPA) dan tolak bila `saldo sesudah < tahanan` dengan 409 `insufficient_stock_reserved`; tipe `recipe_*` hanya diperiksa terhadap saldo ≥ 0; ketiga tipe baru diterima tanpa pengecualian log aktivitas
- [X] T040 [US4] `app/Services/MenuStockService.php::lockBalances`: kunci varian (id naik) lalu saldo varian (id naik) SETELAH material dan saldo material; `transaction()` memakai `DB::transaction($work, 3)` untuk percobaan ulang deadlock; dokumentasikan urutan kunci global di docblock kelas
- [X] T041 [US4] `app/Services/OrderService.php::create`: pecah perulangan baris menjadi (1) sisipkan semua baris, (2) `assertCanFulfil` (mengunci), (3) pergerakan stok penjualan langsung; pastikan `TabService::addLine/updateLine`, `TabSettlementService`, dan dispatch pesan mandiri (`app/Services/SelfServiceOrderService.php`) berjalan di `MenuStockService::transaction()` dan memanggil penjaga sebelum pergerakan; sesuaikan tes yang bergantung pada urutan lama

**Checkpoint**: T036–T038 hijau (T038 bukti dua koneksi dicatat); quickstart 7, 14, 15 lolos.

---

## Phase 7: User Story 5 - Operasi stok lain memakai saldo bersama dan terlacak (P2)

**Tujuan**: semua operasi menunjuk satu saldo; riwayat menyebut sumber dan menu penyebab; impor/ekspor tidak merusak baris varian.

**Tes mandiri**: Telur: terima 100, koreksi −5, jual Kuah, void → satu riwayat dengan sumber berbeda dan saldo akhir benar.

### Tes (tulis dulu, harus gagal)

- [X] T042 [P] [US5] `tests/Feature/IngredientHistoryTest.php`: `GET /stock/movements` dan riwayat per varian untuk tipe `recipe_*` memuat `reference = {type: order, id, number, menu}` (nama menu dari `name_snapshot`), filter tipe menerima nilai baru; penerimaan, koreksi, opname, retur pembelian, transfer pada produk bahan mengubah saldo yang sama tanpa baris saldo kedua
- [X] T043 [P] [US5] Perluas `tests/Feature/StockLedgerInvariantTest.php`: invarian buku besar (jumlah `qty_change` = saldo; `stock_before/after` rantai benar) mencakup `recipe_*`; tidak ada jalur penulisan saldo varian selain `StockService::applyMovement` (pemindai sumber diperluas ke `ModifierMaterialConsumption`)
- [X] T044 [P] [US5] `tests/Feature/IngredientImportExportTest.php`: ekspor `recipes` melewati baris varian; impor yang menimpa resep di tempat mempertahankan baris varian; impor ulang berkas hasil ekspor tidak mengubah resep; lembar yang ada tetap berfungsi

### Implementasi

- [X] T045 [US5] `app/Services/StockMovementReferences.php`: cabang `recipe_*` (reference_id = id baris pesanan → nomor pesanan + `name_snapshot`), penjaga lama "pesanan harus memuat varian pergerakan" diganti "baris pesanan ada"; satu kueri per jenis (tanpa N+1); `app/Http/Controllers/Api/StockController.php` meneruskan `menu`
- [X] T046 [US5] Terapkan hasil audit T003: perbaiki pembaca yang salah menafsirkan tipe baru (laporan stok, `app/Services/LowStockHistory.php`, ekspor `stock`) dan tambahkan tes untuk tiap perbaikan di berkas tes yang relevan
- [X] T047 [US5] Lindungi baris varian di jalur Excel: `app/Services/MasterDataExportService.php` / `app/Support/MasterDataSheets.php` (ekspor hanya baris bahan baku) dan `app/Services/Concerns/ImportsRecipesAndModifiers.php` (impor mempertahankan baris varian yang ada, preseden blok bermodifier 089); sesuaikan setelah hasil T004(a)
- [X] T048 [P] [US5] `resources/js/views` layar Stok dan riwayat varian: tampilkan tipe `recipe_*` dengan label, nomor pesanan, dan nama menu penyebab (komponen tautan riwayat yang ada, token desain)

**Checkpoint**: T042–T044 hijau; quickstart 4, 5, 8, 16 lolos.

---

## Phase 8: User Story 6 - Rekursi dilarang dan penyuntingan penanda aman (P2)

**Tujuan**: larangan dua arah, penjagaan 409, dan perilaku mematikan penanda.

**Tes mandiri**: pilih Indomie Kuah sebagai bahan → 422; matikan penanda Telur → resep lama utuh dan tetap memotong; nonaktifkan Telur → 409.

### Tes (tulis dulu, harus gagal)

- [X] T049 [P] [US6] `tests/Feature/IngredientRecursionTest.php`: menu beresep dipilih sebagai bahan → 422 `recipe_ingredient_recursive`; menandai menu beresep → 422; resep untuk produk bertanda → 422 `recipe_product_is_ingredient`; produk resep memilih variannya sendiri → 422 `recipe_ingredient_self`; resep berlaku-masa-depan dihitung "beresep"; resep arsip tidak
- [X] T050 [P] [US6] `tests/Feature/IngredientGuardsTest.php`: mematikan pelacakan stok, memindah ke sumber `modifier`, menonaktifkan/menghapus varian atau produk yang dirujuk resep tidak-arsip → 409 `ingredient_in_use` memuat daftar resep; mematikan penanda diizinkan dan resep lama tetap memotong stok serta tetap bisa diedit barisnya; menyalakan lagi memulihkan pilihan; hapus permanen varian berriwayat tetap ditolak (`VariantDeletionService`)

### Implementasi

- [X] T051 [US6] `app/Services/RecipeService.php::validate`: tambahkan larangan rekursi dan diri-sendiri (D8 butir 2–3), baris lama yang sudah ada tetap lolos bila hanya penanda yang berubah; muatan error memakai kunci bahasa T010
- [X] T052 [US6] Penjagaan 409 `ingredient_in_use`: service produk di `app/Http/Controllers/Api/ProductController.php` (ubah `track_stock`, `stock_source`, nonaktifkan, hapus produk), `app/Services/VariantDeletionService.php` dan jalur nonaktif varian; kembalikan `{message, code, recipes[]}` lewat satu renderer (`bootstrap/app.php`); bahan varian yang dirujuk TIDAK pernah dilewati diam-diam saat potong
- [X] T053 [P] [US6] `resources/js/components/product/RecipeDrawer.vue` dan `resources/js/views/products/ProductFormDrawer.vue`: tampilkan pesan 422/409 di tempatnya, nonaktifkan opsi yang pasti ditolak di pilihan bahan (menu beresep tidak ditawarkan), dan di formulir tampilkan alasan toggle ditolak; `qa-tests/component` untuk keduanya

**Checkpoint**: T049–T050 hijau; quickstart 10–13 lolos.

---

## Phase 9: User Story 7 - Layar pengelolaan menampilkan peran produk bahan (P3)

**Tujuan**: lencana "Bahan", daftar "Dipakai di", saldo/tahanan/tersedia, koreksi/opname jelas.

**Tes mandiri**: daftar produk menampilkan lencana pada Telur; detail menyebut "Dipakai di: Indomie Kuah"; layar koreksi menampilkan saldo, tahanan, tersedia.

- [ ] T054 [P] [US7] `tests/Feature/IngredientVisibilityTest.php`: `ProductResource` detail memuat `ingredient_used_in` (resep tidak-arsip yang merujuk); daftar produk memuat `use_as_ingredient`; layar stok memuat `held` dan `available` untuk varian dalam `IngredientVariants`; tanpa N+1
- [ ] T055 [P] [US7] `qa-tests/component/ProductIngredientBadge.test.js`: lencana "Bahan", daftar "Dipakai di", dan blok saldo/tahanan/tersedia di dialog koreksi/opname
- [ ] T056 [US7] `app/Http/Resources/ProductResource.php` dan `app/Http/Controllers/Api/StockController.php`: tambahkan `ingredient_used_in` dan `held`/`available` (dimuat sekali per permintaan lewat `MenuStockService`)
- [ ] T057 [US7] `resources/js/views/ProductDetailView.vue`, daftar produk, dan dialog koreksi/opname stok di `resources/js/views`: lencana, "Dipakai di", dan keterangan bahwa koreksi memengaruhi saldo bersama; teks Indonesia di `resources/js/locales/id.json`/`en.json`

**Checkpoint**: T054–T055 hijau.

---

## Phase 10: Polish & Cross-Cutting

- [ ] T058 [P] Perbarui `docs/openapi-pos-mvp.yaml` di commit yang sama dengan perubahan rute/respons (Konstitusi): `use_as_ingredient`, `ingredient_unit`, `ingredient_used_in`, `GET /recipe-ingredients`, `variant_id` pada baris resep, tipe pergerakan baru, `stock_limiting` baru, kode 409 `ingredient_in_use` dan `insufficient_stock_reserved`
- [ ] T059 [P] Catatan bertanggal 2026-11-07 di `docs/PRD-POS-Event-Multivendor.md` (kapabilitas baru pascaMVP, bukan menulis ulang riwayat) dan satu butir "Produk sebagai bahan resep (093)" di bagian Domain rules `CLAUDE.md` yang memuat keputusan: rekursi dilarang, jumlah bulat, tipe `recipe_*`, urutan kunci, satu saldo
- [ ] T060 Jalankan suite penuh backend di kontainer (SATU proses), `npm test`, dan `npm run build`; catat angka lulus/gagal APA ADANYA di `evidence.md`, termasuk kegagalan lama yang sudah ada sebelum fitur ini (baseline T002)
- [ ] T061 Verifikasi peramban nyata (Konstitusi II) untuk quickstart 1–3, 10–13: formulir produk, laci resep, riwayat stok, dengan konsol diperiksa; catat hasil dan tangkapan di `evidence.md`; bila tidak dapat dijalankan tulis "belum diverifikasi"
- [ ] T062 Uji dua koneksi nyata atas DB uji (quickstart 14) dan catat hasil di `evidence.md`; SATU proses tes, tanpa menyentuh DB dev
- [ ] T063 Verifikasi tablet Android (quickstart 17): angka kartu identik dengan web sebelum/sesudah jual langsung dan tab terbuka; bila tidak ada perangkat, catat "belum diverifikasi" dan jangan menyatakan selesai
- [ ] T064 Jalankan migrasi pada DB dev HANYA setelah persetujuan pengguna, dengan cadangan lebih dulu (`php artisan app:backup`); catat hasilnya; pastikan angka stok dan resep lama identik sebelum/sesudah (SC-009)
- [ ] T065 Jalankan quickstart.md dari awal sampai akhir dan perbarui bagian "yang belum terverifikasi" di `evidence.md`; commit per cerita (pesan Indonesia, atribusi sesuai pengingat), tanpa push

---

## Dependencies & Execution Order

### Urutan fase

- **Phase 1 (Setup)**: T003 dan T004 menghasilkan temuan yang menentukan T046, T047, T035.
- **Phase 2 (Foundational)**: memblokir semua cerita. Urutan: T005 → T006 (migrasi) → T007/T009; T008 sebelum T009; T010 bebas.
- **US1 (P1, MVP)** → dasar semua cerita lain (butuh resep dengan baris varian).
- **US2** bergantung US1 (T016) dan Foundational. **US3** bergantung US1 dan T024 (bentuk kebutuhan varian dari US2). **US4** bergantung US2 dan US3 (penjaga dan tahanan).
- **US5** bergantung US2 (tipe `recipe_*` tertulis) dan hasil T003/T004. **US6** bergantung US1 (`validate` di berkas yang sama dengan T016: kerjakan berurutan, bukan paralel). **US7** bergantung US3 (`held`).
- **Polish** terakhir; T064 hanya dengan persetujuan.

### Berkas yang sama (jangan paralel)

- `app/Services/RecipeService.php`: T009, T016, T051.
- `app/Services/ModifierMaterialConsumption.php`: T024, T025, T026.
- `app/Services/MenuStockService.php`: T017 (stub), T031, T032, T033, T040.
- `app/Services/StockService.php`: T039. `app/Services/OrderService.php`: T026, T041.
- `resources/js/components/product/RecipeDrawer.vue`: T020, T053.

### Peluang paralel

- Setup: T003 ‖ T004.
- Foundational: T005 ‖ T007 ‖ T008 ‖ T010 (berkas berbeda), T009 setelah T008.
- US1 tes T011–T014 serentak; US2 tes T021–T023 serentak; US3 tes T028–T030 ‖ T035; US4 tes T036–T038 serentak; US5 tes T042–T044 serentak, T048 ‖ T045; US6 tes T049 ‖ T050, T053 ‖ T051; US7 T054 ‖ T055.
- Backend (T015–T017) dan web (T018–T020) dalam satu cerita bisa dibagi dua orang setelah kontrak `api-changes.md` disepakati.

## Implementation Strategy

### MVP (US1 saja)

Selesaikan Phase 1–2 dan US1: pemilik bisa menandai produk dan menyimpan resep dengan bahan produk. **Belum berguna sendirian**: tanpa US2 resep itu tidak memotong stok. MVP yang bernilai sebenarnya = **US1 + US2 + US3 + US4** (semua P1) dan sebaiknya dirilis bersama karena penjualan tanpa penjaga tahanan (US4) membuka risiko overselling.

### Pengiriman bertahap

1. Foundational + US1 → resep bisa disimpan (tidak dirilis).
2. + US2 → potong/pulihkan benar (uji di DB uji).
3. + US3 → angka kartu benar.
4. + US4 → aman serentak → **rilis P1**.
5. + US5, US6 → lengkap dan ketat (P2).
6. + US7 → kejelasan layar (P3), lalu Polish.

### Catatan risiko untuk pelaksana

- Jangan menulis `variant_outlet_stock` atau `stock_movements` di luar `StockService::applyMovement`.
- Jangan menggunakan tipe `sale`/`return` untuk pemakaian resep (tabrakan makna `reference_id`, riset D3).
- Urutan kunci global material → saldo material → varian → saldo varian adalah aturan, bukan saran (riset D6).
- Hasil T038, T061, T063 TIDAK boleh dilaporkan "lulus" tanpa bukti sesi ini; tulis "belum diverifikasi" bila tidak dijalankan.
