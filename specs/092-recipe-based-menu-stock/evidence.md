# Bukti Verifikasi: Stok Menu Berbasis Resep (092)

**Klasifikasi**: INTERNAL

Dokumen ini hanya memuat hasil yang BENAR-BENAR dijalankan di sesi implementasi. Yang belum dijalankan ditulis "BELUM TERVERIFIKASI".

## 1. Tes otomatis

### Baseline sebelum perubahan (T002)

`--filter='Recipe|ModifierMaterial|TabLines|MenuAvailability|StockSource'`: **120 lulus, 0 gagal** (627 assertion, 106 dtk), semua di DB `warkop_wjm_test` dengan flag aman. Tidak ada kegagalan lama yang diketahui pada himpunan ini.

### Setelah perubahan

- Fondasi + US2 (T003–T009, T016–T020): `RecipeMenuStockFormulaTest` (2), `RecipeMenuStockVariantDecouplingTest` (9), `RecipeMenuStockDeductionTest` (6) bersama tes konsumsi lama `RecipeMaterialConsumptionTest` dan `ModifierMaterialConsumptionTest`: **43 lulus, 0 gagal** (160 assertion). Tes konsumsi lama tetap hijau tanpa perubahan harapan, jadi ekstraksi rumus (D2) dan perubahan `tracksOwnStock()` (D6) tidak menggeser perilaku pemotongan.
- Tes baru ditulis lebih dulu dan terbukti GAGAL sebelum implementasi (formula 2/2 gagal; decoupling 5 gagal dari 9; sisanya menguji perilaku lama yang tetap).

- US1 backend (T010, T011, T013): `RecipeMenuStockCapacityTest` (15) dan `RecipeMenuStockPayloadTest` (9) lulus. CATATAN JUJUR: tes kapasitas lulus pada eksekusi PERTAMA karena `MenuStockService` (T008) sudah ditulis di fase fondasi sebelum tesnya; tes payload gagal dulu (1 galat nyata: variabel `$withModifiers` tidak tertangkap closure di `lookupVariants`, ditemukan tes dan diperbaiki). Jumlah kueri daftar POS tidak tumbuh dengan jumlah menu beresep (diuji 1 vs 13 menu beresep, batas +3).
- US3 backend (T021–T023, T025–T027): `RecipeMenuStockGuardTest` (13; 8 gagal dulu sebelum penjaga dipasang), `RecipeMenuStockSelfServiceTest` (4), `RecipeMenuStockLockOrderTest` (3) lulus bersama `SelfServiceDispatchTest`, `SelfServiceSubmitTest`, `SelfServicePublicSurfaceTest`: 47 + 3 lulus.

### Temuan desain saat implementasi (menyimpang dari rencana awal)

1. **REPEATABLE READ membuat penjaga bisa basi (diperbaiki).** Transaksi yang menunggu kunci baris bahan pada isolasi bawaan MySQL tetap memakai snapshot LAMA, sehingga tidak melihat baris tab yang baru di-commit transaksi lain dan dua terminal sama-sama lolos. `MenuStockService::transaction()` menyetel `READ COMMITTED` untuk transaksi terluar (`SET TRANSACTION` hanya sah sebelum transaksi dimulai; dilewati bila sudah ada transaksi, mis. dalam tes). Dipasang di `TabService::addLine/updateLine`, `OrderService::create`, `TabSettlementService::settle/split`, `SelfServiceOrderService::dispatch`. **Belum terbukti di MySQL nyata**: hanya uji dua terminal (T044) yang bisa membuktikannya.
2. **Memo "varian beresep" harus dikunci hari bisnis + mode data** (DEMO/LIVE punya resep sendiri); ditemukan saat menulis tes mode, diperbaiki.
3. **Tahanan menembus `OutletScope` dengan sengaja** (scope itu menyempit mengikuti header `X-Outlet-Id`, padahal tahanan harus mencakup semua tab terbuka outlet pemesanan).
4. **Rute tab adalah `/tabs/{id}/items`**, bukan `/lines` seperti tertulis di contracts/tasks; kontrak dikoreksi di T038.
5. **Pesan mandiri publik tidak menampilkan stok sama sekali dan tidak memeriksa stok saat kirim.** Ditambahkan: kartu menu publik `sold_out` bila semua varian beresep berstok menu 0, dan kiriman publik melebihi stok ditolak 409 SEBELUM pelanggan membayar (tanpa nama bahan). Ini melampaui daftar tugas awal tetapi diperlukan agar pelanggan tidak membayar pesanan yang tak bisa dikirim.
6. Respons 409 dibedakan menurut ada-tidaknya pengguna login (bukan menurut path): permintaan tanpa pengguna mendapat bentuk tanpa nama bahan.

- Web (T012, T014, T015, T024, T028, T030): `npm test` penuh di kontainer node: **251 berkas, 2121 tes lulus, 2 dilewati (sudah ada sebelumnya)**. Tes baru: `posProductCards` (+7), `posStockRefresh` (3), `PosCartPanel` (+3), `errors` (+2). Satu ekspektasi LAMA disentuh: `posProductCards.test.js` "maps every other field exactly as before" memakai `toEqual` pada bentuk kartu pencarian persis, jadi dua bidang TAMBAHAN (`stock_basis: 'variant'`, `stock_limiting: null`) ditambahkan ke objek harapan; nilai bidang lama tidak berubah.
- Vitest melaporkan 1 "Error" tak tertangani dari `qa-tests/component/RestaurantSetupOutletFilter.test.js` (ApiError status null); ia muncul juga saat berkas itu dijalankan sendirian dan tidak menyentuh berkas yang diubah fitur ini. BELUM dibandingkan dengan checkout bersih, jadi hanya dicatat sebagai temuan terpisah, bukan dinyatakan "sudah ada sebelumnya".
- T030 hanya mengunci PREDIKAT `hasRecipeStock`; bahwa POS/Tab benar-benar memuat ulang daftar setelah bayar BELUM diverifikasi di peramban (T042).

- Android (T029, T031–T035): `./gradlew --offline testDebugUnitTest` (semua modul): **626 tes, 0 gagal, 0 galat** (111 berkas hasil), termasuk `MenuMapperRecipeStockTest` (5, baru) dan `MenuMapperEntriesTest`/`MenuMapperCategoriesTest` lama tanpa perubahan. Kode utama dan kode tes Android terkompilasi, jadi perubahan `PosStore`/`MenuMapper`/DTO valid. **Keputusan T033:** tablet TIDAK menambah batas qty di sisi klien, karena aturan 075 menyatakan angka stok Android hanya tampilan dan penentu tetap server; 409 `insufficient_recipe_stock` sampai ke kasir lewat jalur galat yang sudah ada (`ApiError.Conflict` → `say(...)`, pesan server menyebut bahan penghambat). `PosStore.refreshRecipeStock()` memuat ulang katalog (senyap) setelah baris tab berubah, setelah pembayaran tunai/EDC/QRIS, dan setelah 409 bahan, hanya bila katalog memuat menu beresep. **Layar tablet tidak dijalankan di perangkat (T045).**
- OpenAPI (T038): `docs/openapi-pos-mvp.yaml` valid YAML dan `OpenApiCoverageTest` lulus; ditambah `stock_basis`, `stock_limiting`, skema `InsufficientRecipeStock`, dan catatan 409 di `/orders`, `/tabs/{id}/items` (POST, PATCH), `/tabs/{id}/payments`, `/tabs/{id}/split`. Rute tab yang benar adalah `/tabs/{id}/items`, bukan `/lines`.

### Jumlah kueri (T043, SC-007)

`RecipeMenuStockPayloadTest::test_the_query_count_does_not_grow_with_the_number_of_recipe_menus`: daftar POS dengan 1 menu beresep vs 13 menu beresep, selisih jumlah kueri ≤ 3 (lulus). Diukur lewat log kueri pada satu permintaan HTTP di DB uji, bukan waktu tempuh pada data sebesar produksi; kesan kecepatan bagi kasir BELUM diukur.

### Suite backend penuh (T037) dan regresi

`php artisan test` penuh (satu proses, flag aman, 378 dtk): **2722 lulus, 1 dilewati, 3 gagal** (21573 assertion). Ketiga kegagalan:

1. `RecipeMaterialConsumptionTest::test_a_shortfall_still_completes_the_sale_with_a_warning...` (089): **kegagalan YANG DIHARAPKAN.** Tes itu mengunci perilaku yang justru dibalik oleh keputusan pemilik 2026-11-06 (kekurangan bahan RESEP kini 409, bukan minus + peringatan). Harapannya DIUBAH, bukan dilonggarkan: kini menguji penolakan 409 (saldo tetap 1, tanpa order), dan perilaku lama (minus + peringatan menyebut bahan dan outlet) dipertahankan untuk kekurangan bahan PILIHAN MODIFIER lewat tes baru bersebelahan. Berkas itu kini 19 dari 19 lulus. **Inilah satu-satunya ekspektasi lama yang berubah isinya akibat fitur ini** (selain penambahan dua bidang pada ekspektasi bentuk kartu pencarian di Vitest).
2. `SelfServiceQrTokenTest::test_a_freshly_issued_token_opens_the_right_table_without_login` (`data.available` false) dan
3. `ShiftReportParityTest::test_the_summary_keeps_every_existing_key_and_value` (`expected_cash` ada di ringkasan): **sudah gagal SEBELUM fitur ini.** Dibuktikan dengan menyisihkan sementara semua perubahan berkas terlacak (`git stash`), menjalankan kedua tes pada kode yang di-commit, dan memulihkannya: keduanya gagal sama persis (2 gagal), lalu `git stash pop` mengembalikan 35 berkas yang sama. Tidak berkaitan dengan stok (kas ringkasan shift dan ketersediaan meja QR); tidak diperbaiki di sini.

`OutletScopeRegistryTest` dan `OpenApiCoverageTest` lulus di suite penuh; `MenuStockService` memakai Eloquent (satu `withoutGlobalScope(OutletScope)` yang disengaja dan terdokumentasi pada Order) dan satu `DB::table('recipe_variants')` yang disaring lewat id resep ber-mode, sehingga tidak perlu didaftarkan (T040).

### Audit pemakai aturan stok varian (T019)

- `StockService::applyMovementIfTracked/sourceForNewLine`: otomatis mengikuti `tracksOwnStock()`; tidak diubah.
- `StockController::adjust` (409 `stock_source_modifier`): otomatis berlaku untuk varian beresep; terbukti oleh tes.
- `StockController` daftar mutasi dan `LowStockHistory`: memakai `scopeStockFromVariant`, yang kini juga mengecualikan varian beresep.
- `StockTransferService` (baris ~211): memakai `tracksOwnStock()`, jadi transfer stok varian beresep ditolak seperti sumber `modifier`.
- `MasterDataImportService` (baris ~1062): lembar `stock` menolak baris varian yang tak lagi punya stok sendiri; otomatis.
- `ReportController` (dua kueri mentah laporan stok per outlet dan total): memakai filter SQL `products.stock_source` langsung, jadi DITAMBAH `whereNotIn` himpunan varian beresep.
- `MasterDataExportService`/`MasterDataSheets`: hanya membaca kolom `stock_source` produk; tidak berubah (resep otomatis bukan nilai kolom itu).

## 2. Verifikasi peramban (T042)

BELUM TERVERIFIKASI.

## 3. Uji dua terminal (T044)

BELUM TERVERIFIKASI.

## 4. Verifikasi tablet (T045)

BELUM TERVERIFIKASI.

## 5. Hal yang belum terverifikasi

- **Keserentakan MySQL sebenarnya (T044)**: pengalihan READ COMMITTED dan kunci bahan hanya terbukti BENTUKNYA oleh tes (PHPUnit satu proses di dalam transaksi pembungkus, sehingga pengalihan isolasi dilewati). Uji dua terminal belum dijalankan karena akan membuat resep/tab/saldo uji di DB dev; menunggu izin pengguna.
- **Peramban nyata (T042)**: alat peramban (chrome-devtools, playwright) gagal terhubung di sesi ini. Pemuatan ulang menu setelah bayar, pesan "Maksimal N porsi", dan konsol tanpa galat BELUM diperiksa.
- **Tablet (T045)**: tidak dijalankan di perangkat; hanya tes unit Android dan kompilasi.
- Dua kegagalan backend lama (di atas) dan satu "Error" Vitest lama `RestaurantSetupOutletFilter.test.js` belum dibandingkan dengan checkout bersih untuk yang terakhir.

## 6. Risiko dan dampak

- D6: varian beresep berperilaku seperti sumber `modifier` untuk laporan dan koreksi stok varian.
- Pembalikan 087 FR-031 khusus kebutuhan resep (keputusan pemilik 2026-11-06).

## 7. Catatan lingkungan sesi

- Disk data host penuh (100%, 2,8 GiB bebas) membuat MySQL kontainer `warkop_wjm-mysql-1` gagal start (`Cannot resize redo log file`). Dengan persetujuan pengguna, HANYA `docker builder prune` dijalankan (3,67 GB cache build); image, kontainer, dan volume tidak disentuh. Data dev diperiksa utuh setelah start ulang.
