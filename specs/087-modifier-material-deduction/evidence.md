# Evidence: 087 Pilihan Modifier Memakai Bahan Baku

**Klasifikasi**: INTERNAL

## Verifikasi awal

- **U2 (T002)** — 2026-10-31: `grep -n -i "modifier" app/Support/MasterDataSheets.php` tidak menghasilkan apa pun. Grup modifier tidak termasuk lembar master data; FR-007 tetap di luar cakupan.
- **U1 (T003)** — 2026-10-31: respons settle/split di Android dibaca lewat `RemoteBackend` → `ApiClient` (`android-pos/core-network/src/main/java/com/warkop/core/network/ApiClient.kt:29`: `ignoreUnknownKeys = true   // server boleh menambah kolom`). Bidang tingkat atas `material_warnings` diabaikan oleh APK lama. T053 tidak memerlukan perubahan parser.

## Konfirmasi pemilik (U3, T004)

- 2026-10-31: FR-018 (bahan dari baris "buang" dicatat sebagai `waste`) — **disetujui**.
- 2026-10-31: FR-023 (biaya bahan pilihan masuk `cost_price` baris saat selesai) — **disetujui**.

## Hasil suite backend

Semua di kontainer dengan `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array`.

| Tanggal | Saringan | Hasil |
|---|---|---|
| 2026-10-31 | `Material\|StockTransfer\|PurchaseOrder` (fondasi, T011) | 102 lulus |
| 2026-10-31 | `ModifierOptionMaterialMappingTest\|ModifierGroupsTest` (US1) | 27 lulus |
| 2026-10-31 | `ModifierMaterialConsumptionTest` (US2 ritel) | 10 lulus |
| 2026-10-31 | `TabMaterialConsumptionTest\|MaterialConsumptionIdempotencyTest` (US2 tab, idempotensi) | 7 lulus |
| 2026-10-31 | `MaterialRestoreTest\|MaterialWasteTest` (US3) | 10 lulus (1 gagal awal = bug tes: grup wajib Rasa Kuah tidak dipilih; diperbaiki) |
| 2026-10-31 | Regresi `Order\|Tab\|Refund\|Split\|Settle\|Void\|Override\|OutletScopeRegistry\|Kitchen\|Modifier\|Material\|Shift` | 882 lulus, 1 gagal: `ShiftReportParityTest::the summary keeps every existing key and value` |
| 2026-10-31 | `ShiftReportParityTest` di commit dasar `61ef05b5` (worktree sementara, tanpa perubahan 087) | gagal yang SAMA (`assertJsonMissingPath('expected_cash')`) → **bukan dari 087** |
| 2026-10-31 | `MaterialNegativeStockTest\|MaterialMovementsEndpointTest\|OutletScopeRegistryTest\|MaterialTest\|MaterialStockPerOutletTest` | 33 lulus (sebelumnya `OutletScopeRegistryTest` menangkap kueri mentah baru di `MaterialController`; didaftarkan `filtered`) |
| 2026-10-31 | Suite penuh (T054, sebelum US6) | 2373 lulus, 1 dilewati, 2 gagal (3041 dtk): `ShiftReportParityTest` (sudah ada sebelum 087, lihat di atas) dan **`StockLedgerInvariantTest::every application call ... names an outlet`** — temuan 087 sungguhan: tiga panggilan `MaterialStockService::applyMovement` di `ModifierMaterialConsumption` memakai argumen posisi, bukan `outletId:` bernama (aturan 045). Diperbaiki dengan argumen bernama; penjaga diperluas ke `applyLineMovementIfTracked` (US6). Ulang: 6 lulus. |

Catatan kualitas tes: `ModifierMaterialConsumptionTest::test_demo_sales_never_touch_live_material_stock` lemah — tidak memastikan checkout DEMO itu sendiri berhasil, jadi bisa lulus secara trivial. Isolasi mode dijamin oleh `HasDataMode` pada `ModifierOptionMaterial`/`Material`, tetapi belum dibuktikan ujung-ke-ujung.

## Hasil Vitest

- 2026-10-31: `modifierMaterials`, `materialWarnings`, `materialsNegative` — 15 lulus.
- 2026-10-31: suite penuh — 225 berkas, 1872 lulus, 2 dilewati, 0 gagal.
- 2026-10-31: `npm run build` sukses tanpa galat.

## Migrasi DB dev

- 2026-10-31: pemilik menyetujui ("go ahead, migrate the dev DB"). `migrate:status` → tepat tiga migrasi 087 tertunda, target `warkop_wjm`.
- Cadangan sebelum migrasi: `php artisan app:backup` → `storage/app/backups/2026-10-31_094420` (peringatan alat: `BACKUP_EXTERNAL_PATH` tidak diset, cadangan hanya ada di laptop ini).
- `php artisan migrate --force`: ketiganya DONE.
- Skema terverifikasi: `chk_material_outlet_stock_nonneg` tidak ada; `chk_modifier_option_materials_qty_pos` ada; `material_stock_movements.type` = enum dengan `sale`, `return`, `waste`. Data dev: 2 bahan (keduanya DEMO), 8 pilihan modifier, 0 pemetaan.

## Uji asap API di DB dev (hanya baca)

Lewat HTTP kernel di `tinker` dengan `Sanctum::actingAs` pengguna owner/admin pertama (tidak menulis token; kata sandi seed `owner` tidak berlaku di DB dev ini dan tidak ditebak).

| Mode | Permintaan | Hasil |
|---|---|---|
| LIVE | `GET /modifier-groups` | 200; 5 pilihan, semua membawa kunci `materials` |
| LIVE | `GET /materials?stock_status=negative` / `=bogus` | 200 (0 baris) / 422 |
| LIVE | `GET /materials` | 200, kosong — benar: kedua bahan dev ber-mode DEMO (cakupan mode bekerja) |
| DEMO | `GET /materials/{id}` | 200; `used_by_modifier_options: []` |
| DEMO | `GET /materials/{id}/movements` | 200; 1 baris `adjustment` berbentuk sesuai kontrak (outlet, saldo, rujukan `manual_adjustment`, pengguna) |

Tidak ada transaksi uji yang ditulis ke DB dev.

## Peramban

**Belum diverifikasi.** Alat otomasi peramban (Playwright / Chrome DevTools MCP) gagal tersambung pada sesi ini, dan repo tidak memuat Playwright. Migrasi DB dev sudah dijalankan (di atas), jadi pemeriksaan bisa dilakukan manual: `quickstart.md` langkah 1–7 dan 9.

## Tablet

Belum diverifikasi di perangkat. Dari kode: parser `ApiClient` mengabaikan bidang tak dikenal (U1), jadi pelunasan/split dari APK lama tidak galat karena `material_warnings`; Android tidak menampilkan peringatan itu (P3, tidak dikerjakan).

## Konkurensi manual

Belum dijalankan (butuh peramban/dua klien). Yang terbukti lewat tes: urutan tulis `material_id ASC, order_item_id ASC` (`ModifierMaterialConsumptionTest::test_movements_are_written_in_material_then_line_order`), dan kunci baris sudah ada di `MaterialStockService`.

## US6 — verifikasi awal (T057, 2026-10-31)

- **(a)** `ProductVariantResource` dipakai `ProductResource` untuk daftar Produk (pengelolaan) DAN `GET /products?pos=1` (POS web + Android). Maka `track_stock` efektif hanya saat `pos=1`; pengelolaan tetap mentah + `stock_tracked`. `GET /variants/lookup` (`ProductController` ±516–560) dibangun tangan dan **tidak mengirim `track_stock` sama sekali** — kartu hasil pencarian POS menganggap semua varian dilacak (`track_stock !== false`). Harus ditambah `track_stock` efektif.
- **(b)** `PUT /products/{id}` (`ProductController::update`) menulis langsung dalam `DB::transaction` dengan log `updated` (sebelum/sesudah fillable). TIDAK lewat rilis menu `menu_change`. `stock_source_changed` ditulis di transaksi yang sama bila nilainya berubah.
- **(c)** Penulis stok varian (`applyMovement*`): `OrderService` (jual :227, void :275), `TabService` (tambah :203, ubah qty :246, `returnStock` :457), `RefundService` :96, `StockController::adjust` :94, `StockTransferService` :236, `MasterDataImportService` :1271/:1366 (kolom stok absolut), `WjmCatalogSeedService` :431 (stok awal). Dua terakhir belum ada di tabel D20: impor master data menolak nilai stok untuk produk `modifier` (galat baris); katalog WJM hanya membuat produk `variant`, jadi stok awalnya tetap benar.

## US6 — hasil tes (T070, 2026-10-31)

- Backend US6: `ProductStockSourceTest` (9) + `StockSourceModulesTest` (6) → 15 lulus. Dua gagal awal = bug tes (kunci `variant_id` pada `GET /variants/lookup`; laporan stok ringkasan tidak memuat SKU → uji memakai `?outlet_id=`). Tidak ada perubahan kode produksi dari keduanya.
- `StockLedgerInvariantTest` diperluas ke `applyLineMovementIfTracked`; 6 lulus.
- Vitest penuh: 226 berkas, 1875 lulus, 2 dilewati, **1 gagal**: `DashboardViewShell.test.js › a failing summary shows an error with Retry in the KPI region only` (1059 ms). Dijalankan sendiri 3× → 21/21 lulus tiap kali; tidak ada berkas dasbor yang diubah 087. Dinilai **flaky karena waktu** saat suite backend berjalan bersamaan; tidak dihitung lulus.
- `npm run build` sukses.
- Suite backend penuh dengan US6: lihat di bawah (sedang berjalan).
- **Celah yang diketahui (belum ditutup):** impor master data `initial_stock` untuk varian BARU pada produk yang di berkas yang sama diset `modifier` masih membukukan stok awal varian; lembar `stock` hanya memeriksa sumber stok yang SUDAH tersimpan (bukan perubahan sumber dalam berkas yang sama).

## US6 — migrasi DB dev (T071, 2026-10-31)

- Persetujuan pemilik ("y") untuk migrasi keempat. Dijalankan saat suite penuh US6 masih berjalan (atas permintaan "continue" berulang): migrasi ini sudah teruji di DB uji (setiap tes migrate fresh), `down()` aman selama belum ada produk `modifier`.
- Cadangan: `storage/app/backups/2026-10-31_121837`. `migrate --force`: `2026_12_30_000004_add_stock_source_to_products_and_order_items` DONE.
- Data: 413 produk, semua `stock_source = variant` (tidak ada perubahan perilaku); 36 baris `order_items` lama ber-`stock_source` NULL (aturan lama berlaku).
- Uji asap hanya-baca (LIVE dan DEMO, `Sanctum::actingAs` owner/admin, tanpa token): `GET /products?with_variants=1` 200 dengan `stock_source`, `track_stock`, `stock_tracked`; `GET /stock/low` 200; `GET /reports/stock-by-outlet` 200.
- Peramban dan tablet: **belum diverifikasi** (alat peramban tidak tersambung di sesi ini).

## Bug dilaporkan pemilik: POS masih menampilkan stok 0 (2026-10-31)

- **Gejala:** Indomie Kuah (produk 789, DEMO) sudah `stock_source = modifier`, tetapi kartu POS tetap "Stok 0 / Habis" dan tidak bisa dijual.
- **Bukti per lapis:** DB — `products.stock_source = modifier` tersimpan; varian aktif 2375 `track_stock = 1`, stok 0. API — `GET /products?pos=1&with_variants=1` dan `GET /variants/lookup?pos=1` mengirim `track_stock: false` untuk semua varian (server benar). Klien — `posProductCards.js` menjumlah `outlet_stock ?? current_stock` semua varian dan `out_of_stock = total <= 0` tanpa membaca `track_stock`; `PosView.vue`, `ProductVariantPickerModal.vue`, `PosCartPanel.vue` membandingkan `current_stock <= 0` langsung.
- **Akar masalah:** POS ritel tidak pernah menerapkan aturan 028 "menu masak tidak pernah habis" (hanya `TabView.vue:121`). research D19 keliru mengasumsikan sebaliknya tanpa memeriksa.
- **Perbaikan:** satu aturan `stockOf` (null = tanpa stok sendiri) + `isOutOfStock` di `posProductCards.js`, dipakai kartu, kartu pencarian, item keranjang, pemilih varian, tombol + keranjang. Efek samping yang disengaja: pemilih varian kini menampilkan saldo outlet shift (`outlet_stock`), sama dengan kartu, bukan total semua outlet.
- **Tes:** gagal dulu (3 dari 4 tes baru `posProductCards.test.js` merah), lalu lulus; ditambah tes komponen pemilih varian dan keranjang. POS: 5 berkas + 2 tes komponen → 28/12/52 lulus. `npm run build` ulang.
- **Belum diverifikasi di peramban** oleh saya (tidak ada alat peramban); pemilik diminta memuat ulang POS.

## Insiden uji: dua proses tes di DB uji yang sama (2026-10-31)

- Saat suite penuh US6 berjalan, saya menjalankan tes lain di `warkop_wjm_test` secara bersamaan. Keduanya memakai `RefreshDatabase` pada DB yang sama, sehingga suite penuh rusak: dari 1 gagal (`ProductImportNewColumnsTest` urutan kolom, perlu pembaruan tes karena `stock_source` disengaja ditaruh di akhir) menjadi 112 gagal. Hasil run itu TIDAK sah dan dihentikan (termasuk proses PHPUnit yang tertinggal di kontainer, PID 335/343).
- `ProductImportNewColumnsTest` dijalankan sendiri setelah pembaruan tes: 4 lulus.
- Pelajaran: satu proses tes per DB uji; jangan menjalankan tes terfilter selama suite penuh berjalan.

## Permintaan pemilik di luar 087: pajak yang tidak ditampilkan tidak dihitung (2026-10-31)

- **Laporan:** keranjang Indomie Kuah Rp 8.000 qty 1 → total Rp 16.000. Bukti DB dev: `tax_rate = 100`, `tax_show = 0`; `TaxCalculator::compute(8000)` = pajak 8.000. Sesuai aturan 083 ("toggle hanya tampilan, pajak tetap ditagih") — bukan bug 087.
- **Perintah pemilik:** "fix: is not show tax, then tax calculation is not calculated" → MEMBALIK aturan 083 untuk order BARU.
- **Perubahan:** server `PosSettings::effectiveTaxRate()` (tampil ? tarif : 0) sebagai tarif bawaan `TaxCalculator::compute()` (snapshot `rate` ikut 0); web getter `effectiveTaxRate` dipakai `cartTotals` di `PosView` dan `PosCartPanel`. `taxRate()`/`tax_rate` tetap tarif tersimpan. Android tidak berubah (pajak selalu dari server). Teks bantuan Pengaturan → Pajak, OpenAPI, dan CLAUDE.md diperbarui dengan catatan bertanggal.
- **Tes:** web — 2 tes baru gagal dulu lalu lulus; berkas pajak terkait 25+5 lulus; `npm run build` ulang. Server — `TaxSettingTest::test_a_hidden_tax_is_still_charged_and_stored` diganti `test_a_hidden_tax_is_not_charged` + `test_switching_tax_display_off_affects_only_new_orders`; dijalankan SESUDAH suite penuh selesai: `--filter=Tax` 59 lulus (319 asersi). Tidak ada tes di luar berkas pajak yang menyentuh `tax_show`.
- **Catatan cakupan:** ini perubahan aturan 083, bukan bagian 087; sebaiknya commit terpisah.

## Suite backend penuh US6 (T070, 2026-10-31)

- Run bersih (satu proses tes): **2389 lulus, 1 dilewati, 1 gagal** (3411 dtk). Satu-satunya kegagalan `ShiftReportParityTest` (`expected_cash` ada di ringkasan) sudah dibuktikan gagal di commit dasar 61ef05b5 (worktree sementara) — bukan dari 087.
- Suite ini dimulai SEBELUM perubahan pajak; tes pajak dijalankan terpisah sesudahnya (lihat bagian pajak di atas).
- Vitest penuh: 1875 lulus; satu tes `DashboardViewShell` kadang gagal saat run penuh, lulus 3/3 bila dijalankan sendiri.

## Batch permintaan pemilik 2026-10-31 malam (15 butir, lanjutan 087)

Keputusan pemilik: butir 8 = "tiap pilihan = 1 porsi". Butir 6 dan 12 memakai bawaan yang direkomendasikan
(stok = jumlah porsi pilihan; hapus varian hanya bila tanpa riwayat) — pemilik tidak menjawab dua pertanyaan itu.

| # | Permintaan | Hasil | Bukti |
|---|---|---|---|
| 1 | Filter lengkap daftar bahan | Jenis, status, stok (ada/habis/minus) | `MaterialNegativeStockTest` (5), `materialsNegative.test.js` |
| 2 | Koreksi stok bahan masuk log Inventori → Stok | Tab Bahan di layar Stok; `GET /materials/movements`; kolom `reason` baru | `MaterialStockLedgerTest` (2), `StockMaterialLedger.test.js` (3) |
| 3 | PO menambah stok bahan | Sudah benar (`purchase` ke outlet PO). **Bug ditemukan:** penambahan ke saldo minus yang hasilnya masih minus ditolak — diperbaiki (FR-014a) | `MaterialStockNegativeGuardTest` (gagal dulu, lalu lulus), `PurchaseOrderStockReceivingTest` |
| 4 | Dropdown tidak bisa digulung | Akar: `BaseMultiSelect` menutup panel pada scroll di dalam daftarnya (bug 036 yang tak disalin) + daftar flex tanpa `min-h-0` | `BaseMultiSelect.test.js` gagal di berkas asli, lulus dengan perbaikan |
| 5 | Filter outlet POS ikut navbar | Mulai dari outlet navbar dan mengikutinya | `PosView.test.js` (gagal dulu) |
| 6 | Stok produk berbahan | `materialPortions` di kartu; tidak memblokir jual | `posProductCards.test.js`, `ModifierPortionAndStockTest` |
| 7 | Stok + pemakaian di popup | Per pilihan "Bahan: stok N · pakai M" (merah bila habis) | `ModifierPickerModal.test.js` (gagal dulu) |
| 8 | >1 pilihan = tambah total | `portion_per_option` (migrasi baru), harga dasar per pilihan tambahan | `ModifierPortionAndStockTest` (6, gagal dulu), `modifierRules.test.js` |
| 9 | Pesan minus padahal stok 15 | **Bukan bug pemotongan:** 15 bungkus Ayam Bawang ada di outlet WJ3, penjualan di WDM (saldo 0 → −1). Daftar bahan menampilkan TOTAL saat WDM belum punya baris saldo — diperbaiki; peringatan kini menyebut outlet | DB dev (hanya baca): `material_outlet_stock` WJ3=15, WDM=−1; `materialsNegative.test.js`, `materialWarnings.test.js` |
| 10 | Jelaskan jenis bahan | Hanya pengelompokan; `semi_finished` + biaya standar dipakai resep. Tidak memengaruhi pemotongan | `RecipeService::unitCost` |
| 11 | Sumber stok & status di grid fakta | Ditambahkan | `ProductDetailView.test.js` |
| 12 | Hapus varian permanen | `DELETE /variants/{id}/permanent` (hanya tanpa riwayat) di dua laci | `VariantPermanentDeleteTest` (5), `VariantsDrawer.test.js` |
| 13 | Foto produk di kepala detail | Ditambahkan (placeholder bila kosong) | `ProductDetailView.test.js` |
| 14 | Kode di bawah nama, satu tingkat lebih kecil | 26px nama → 20px kode mono | `ProductDetailView.test.js` |
| 15 | Hapus resep | `DELETE /products/{id}/recipes` (semua versi, isi di log) + tombol di laci & bagian Resep | `RecipeApiTest` (+2), `RecipeSection.test.js` (+2) |

- Uji terkait backend (filter Modifier|Tab|Order|Material|Lookup|PosMenu|Product): 887 lulus, 1 gagal = `ModifierGroupsTest` mematok kunci opsi persis; kunci baru (`materials`, `portions`) aditif → tes diperbarui, lulus.
- `OutletScopeRegistryTest` menangkap kueri mentah `VariantDeletionService` → dicatat `exempt` (penjaga hapus melihat semua outlet, seperti penghapusan perangkat).
- Vitest penuh: 1909 lulus, 2 dilewati. `npm run build` ulang.
- Insiden kecil: skrip penyunting memotong `StockView.vue` menjadi kosong; dipulihkan dari HEAD (berkas belum diubah di cabang ini, 397 baris = isi sebelum suntingan) lalu suntingan diulang.
- **Belum:** migrasi `000005` (reason) dan `000006` (portion_per_option) belum dijalankan di DB dev (perlu persetujuan pemilik); verifikasi peramban/tablet belum (tidak ada alat peramban). Android: pratinjau harga porsi di `Sheets.kt:153` masih `harga + Σ delta` (server tetap menagih benar); stok per pilihan belum tampil di tablet.
- **Migrasi DB dev (persetujuan pemilik "yes", 2026-10-31):** cadangan `storage/app/backups/2026-10-31_160605`; `000005` (reason) dan `000006` (portion_per_option) DONE. Uji asap hanya-baca (owner/admin): `GET /materials/movements`, `GET /materials?stock_status=available`, `GET /modifier-groups` → 200.
- **Suite backend penuh sesudah batch (2026-11-01):** 2406 lulus, 1 dilewati, 1 gagal (3430 dtk). Satu-satunya kegagalan `ShiftReportParityTest` (`expected_cash`) — sudah dibuktikan gagal di commit dasar 61ef05b5; bukan dari batch ini.

## Android (2026-11-01) — dukungan stok bahan di tablet

Lingkup pilihan pemilik: alur kasir + layar "Stok Bahan" hanya baca (tanpa koreksi dari tablet).

- **Server:** `GET /materials/movements` kini boleh dibaca pemegang menu `pos` (Kasir/Waiter/Supervisor tidak punya menu Bahan/Stok). Hanya baca, dibatasi outlet, tanpa angka biaya. `MaterialStockLedgerTest` (gagal dulu, lalu lulus; 2). OpenAPI diperbarui.
- **core-network:** DTO opsi (`materials`, `portions`), grup (`portion_per_option`), produk (`stock_source`), `material_warnings` di `TabDto`/`SplitItemsResponseDto`, `MaterialDtos.kt`; endpoint `materials`, `materials/movements`. `MaterialDtosTest` 4 (termasuk server lama tanpa bidang baru).
- **Pemilih modifier (`Sheets.kt`):** harga berjalan lewat `ModifierPricing.unitPrice` (dulu `harga + Σ selisih`, jadi 2 rasa tampil Rp 8.000 padahal ditagih Rp 16.000), label "+Rp" per pilihan porsi, "N porsi", baris stok per bahan (merah bila habis, tetap bisa dipilih). `ModifierPricingTest` 6.
- **Kartu Kasir:** item bersumber `modifier` menampilkan porsi dari bahan (`entryStock`); status habis tetap dari server. `MenuMapperEntriesTest` +1, `CounterLabelsTest` +1.
- **Peringatan minus:** `MaterialWarning.message()` (teks sama dengan web, menyebut outlet); `PosStore.materialNotice` dari `onSaleClosed` (semua jalur tutup) dan split, spanduk bertahan sampai "Tutup". `MaterialWarningTest` 2.
- **Layar "Bahan":** saldo outlet tablet (0 bila belum ada baris, tidak pernah total), minus di atas dan merah, cari, riwayat per bahan (jenis, jumlah bertanda, saldo sebelum→sesudah, nomor pesanan/alasan, pengguna, jam WIB). `MaterialMapperTest` 4, `PosStoreMaterialsTest` 2, `NavAndClockTest` diperbarui.
- **Hasil:** `:app:testDebugUnitTest` 482 lulus, `:core-network:testDebugUnitTest` 81 lulus, 0 gagal; `assembleDebug` berhasil (`app/build/outputs/apk/debug/app-debug.apk`).
- **Temuan lama, DIPERBAIKI (persetujuan pemilik "yes fix"):** `ModifierGroupDto.maxSelect` dulu `Int = 1`, sehingga `max_select: null` (tanpa batas) dipaksa `coerceInputValues` menjadi 1 dan grup tanpa batas di tablet hanya bisa dipilih 1. Kini `Int? = 1` (null eksplisit = tanpa batas, kunci hilang = 1), `ModGroup.UNLIMITED`, `allows()`, teks aturan "Pilih bebas" / "Pilih minimal N". Tes gagal dulu (`MaterialDtosTest`), lalu lulus; + `ModifierPricingTest`, `MenuMapperEntriesTest`.
- **Belum diverifikasi di perangkat** (tidak ada tablet/emulator yang dijalankan di sesi ini).
- **Bug dilaporkan pemilik di tablet (2026-11-01, foto layar): "tidak bisa kembali" dari Stok Bahan.** Akar: `MaterialsScreen` tidak dibungkus `PosShell`, jadi tampil layar penuh tanpa rail/bilah navigasi. Diperbaiki (judul dari `PosShell`); `NavShellScanTest` baru memastikan SETIAP tujuan `NAV_SPECS` dirender di dalam `PosShell` — gagal dulu ("[MATERIALS]"), lalu lulus. `:app:testDebugUnitTest` 485 lulus, `assembleDebug` berhasil.
