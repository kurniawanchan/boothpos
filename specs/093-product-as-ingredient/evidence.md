# Evidence: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL | **Dimulai**: 2026-11-07

Aturan: hanya hasil yang dijalankan di sesi ini yang ditulis "lulus". Selain itu: **belum diverifikasi**.

## 1. Tes otomatis

| Waktu | Perintah | Hasil |
|---|---|---|
| 2026-11-07 (baseline T002, sebelum perubahan) | `--filter='Recipe\|ModifierMaterial\|StockLedgerInvariant\|MasterDataImportRecipeModifier'` di kontainer dengan semua `-e` | **152 lulus, 759 asersi**, 0 gagal (103 dtk) |
| 2026-11-07 (US1, Foundational) | `--filter='IngredientPickerTest\|IngredientFlagTest\|IngredientRecipeValidationTest\|IngredientSchemaTest\|IngredientVariantsMemoTest\|RecipeApiTest'` | **59 lulus, 293 asersi** |
| 2026-11-07 (web) | `npx vitest run` (kontainer `node`) | **254 berkas, 2155 lulus, 2 dilewati (lama)**, 0 gagal; termasuk 5 tes util, 3 tes laci resep, 3 tes formulir produk baru |
| 2026-11-07 (suite penuh backend, akhir US1) | `php artisan test` (kontainer, semua `-e`) | **2759 lulus, 3 gagal, 1 dilewati** (328 dtk). Lihat rincian di bawah. |
| 2026-11-07 (setelah dokumentasi OpenAPI) | `--filter=OpenApiCoverageTest` | 2 lulus |
| 2026-11-07 (akhir US2) | `--filter='Ingredient\|RecipeMenuStock\|ModifierMaterial\|MaterialRestore\|RecipeMaterialConsumption\|RecipeApiTest\|StockLedgerInvariant\|MenuStock'` | **186 lulus, 828 asersi** |
| 2026-11-07 (akhir US2, suite penuh backend) | `php artisan test` | **2775 lulus, 2 gagal, 1 dilewati** (415 dtk); 2 gagal = kegagalan lama yang sama (QR token, ShiftReportParity) |
| 2026-11-07 (akhir US2, web) | `npx vitest run` | 254 berkas, 2155 lulus, 2 dilewati, 0 gagal |
| 2026-11-07 (akhir US3, suite penuh backend) | `php artisan test` | **2791 lulus, 2 gagal, 1 dilewati** (426 dtk); 2 gagal = kegagalan lama yang sama |
| 2026-11-07 (akhir US3, web) | `npx vitest run` | 254 berkas, **2158 lulus**, 2 dilewati, 0 gagal |
| 2026-11-07 (US3, Android) | `./gradlew --offline :app:testDebugUnitTest --tests '...MenuMapperRecipeStockTest'` | BUILD SUCCESSFUL (2 tes kontrak baru: bahan penghambat berupa produk, kartu produk bahan = `outlet_stock` server). Ini TES UNIT, bukan uji perangkat. |
| 2026-11-07 (akhir US4, suite penuh backend) | `php artisan test` | **2803 lulus, 2 gagal, 1 dilewati** (335 dtk); 2 gagal = kegagalan lama yang sama |
| 2026-11-07 (US4, keserentakan nyata) | `php tests/Concurrency/ingredient_race.php` di kontainer (3 kali berturut-turut) | **SEMUA SKENARIO LULUS di ketiganya** (rincian di bagian 3) |
| 2026-11-07 (akhir US5, suite penuh backend) | `php artisan test` | **2814 lulus, 2 gagal, 1 dilewati** (349 dtk); 2 gagal = kegagalan lama yang sama |
| 2026-11-07 (akhir US5, web) | `npx vitest run` | 254 berkas, **2159 lulus**, 2 dilewati, 0 gagal |
| 2026-11-07 (akhir US6, suite penuh backend) | `php artisan test` | **2836 lulus, 2 gagal, 1 dilewati** (354 dtk); 2 gagal = kegagalan lama yang sama |
| 2026-11-07 (akhir US6, web) | `npx vitest run` | 254 berkas, **2160 lulus**, 2 dilewati, 0 gagal |

**Rincian 3 kegagalan suite penuh (akhir US1):**
- `OpenApiCoverageTest::every route is documented` — DISEBABKAN fitur ini (rute `GET /recipe-ingredients` belum didokumentasikan). Diperbaiki di `docs/openapi-pos-mvp.yaml`; lulus sesudahnya.
- `SelfServiceQrTokenTest::a freshly issued token opens the right table without login` dan `ShiftReportParityTest::the summary keeps every existing key and value` — **sudah gagal SEBELUM fitur ini**: diuji ulang pada pohon bersih (`git stash -u`, dua tes itu saja) dan tetap gagal (2 gagal, 10 lulus); perubahan dipulihkan sesudahnya. Penyebabnya belum diselidiki (di luar cakupan 093).

### Temuan selama implementasi

- **BUG ditemukan & diperbaiki (RecipeService::blocksFrom):** `FormRequest::validated()` menyusun ulang larik bersarang menurut urutan ATURAN (semua `material_id` dulu), sehingga resep campuran bahan baku + produk tersimpan dengan urutan baris terbalik. Diperbaiki dengan `ksort` pada kunci kirim (`orderedLines`); dicakup tes `test_the_blocks_shape_works_and_material_and_product_lines_mix_in_one_recipe`.
- Migrasi `2027_01_04_000001` lolos di DB uji (CHECK XOR, FK RESTRICT, ENUM tiga tipe). **Belum dijalankan di DB dev.**
- **US2:** pemakaian bahan produk ditulis lewat `StockService::applyMovement` (tipe `recipe_sale`/`recipe_return`/`recipe_waste`, rujukan = baris menu). Sabuk pengaman idempotensi diperluas ke `stock_movements`. Bahan produk TIDAK dilewati walau varian nonaktif (beda dari bahan baku nonaktif).
- **Perilaku lama yang ditemukan (di luar 093, tidak diubah):** `OrderService::void` pada pesanan yang sudah di-refund tidak ditolak (200). Pemulihan bahan produk aman karena membaca buku besar (`sale − return = 0`), tetapi pemulihan stok penjualan langsung lewat jalur lama mungkin ganda; perlu dicek terpisah.
- **US3 (menggantikan catatan sementara US2):** `assertCanFulfil` kini memperhitungkan varian bahan (saldo − tahanan pesanan lain) dan menolak 409 `insufficient_recipe_stock` dengan `limiting.ingredient_type=variant` + `variant_id`. Stok menu = kapasitas terendah di antara bahan baku DAN produk (seri jatuh ke bahan baku). Tahanan varian dihitung dari baris tab terbuka (`heldAll`, satu hitungan untuk bahan baku + varian). `outlet_stock` varian bahan di payload POS = saldo − tahanan lewat SATU aturan `MenuStockService::availableStockForRequest` (dipakai `ProductVariantResource` dan `GET /variants/lookup`). Stub `heldVariantsBy` sudah diganti.
- **US4 selesai:** `StockService::applyMovement` (tipe `sale` negatif, varian dalam `IngredientVariants`) menolak 409 `insufficient_stock_reserved` bila saldo sesudahnya < tahanan (termasuk tahanan pesanan yang sama); kekurangan murni tetap seperti lama (409 dengan galat `qty_change`, tanpa `code`). `MenuStockService::lockIngredients` mengambil kunci (bahan baku → saldo → varian id naik → saldo) sebelum pergerakan stok di `OrderService::create` (perulangan dipecah: sisipkan baris → kunci → potong), `TabService::addLine` dan `updateLine`; `transaction()` memakai `DB::transaction($work, 3)`. Sisa celah: bahan dari pilihan modifier BARU di `updateLine` baru terkunci oleh penjaga di akhir.
- **Menu publik pesan mandiri:** hanya menampilkan `sold_out` (tanpa angka stok) dan memakai `forVariants`, jadi menu beresep dengan bahan produk habis otomatis `sold_out`; produk bahan yang dijual langsung di menu publik tidak mendapat angka tahanan (memang tak ada angka stok di sana).
- **Pembaca `stock_limiting` (T034):** web hanya membaca `.name` (PosView, PosCartPanel); Android DTO hanya `name/unit/on_hand/per_portion` dan klien mengabaikan kunci tak dikenal, jadi `material_id: null` aman. Tidak ada perubahan kode klien.
- `MenuStockService::heldVariantsBy` masih **stub kosong** (T017, bertanggal) sampai T031; kolom `held` di `GET /recipe-ingredients` karenanya selalu 0 saat ini.

### US5 (riwayat, jejak audit, impor/ekspor)

- **Hipotesis T004(a) TERBUKTI lalu ditutup:** impor resep memang menghapus baris produk (blok dasar) dan merusak blok bermodifier yang berisi baris produk. Perbaikan: `applyRecipes` mempertahankan baris produk di blok dasar dan blok bermodifier; ekspor `recipes` melewati baris produk (sebelumnya akan menulis `material_code` kosong). Dibuktikan `IngredientImportExportTest` (5 tes, termasuk ekspor lalu impor ulang tidak mengubah resep).
- **Riwayat:** `StockMovementReferences::resolveRecipeUse` — tipe `recipe_*` menunjuk baris pesanan menu (nomor pesanan + `menu` dari `name_snapshot`); baris yang hilang → `null`, bukan rujukan salah. UI riwayat varian dan layar Stok menampilkan "untuk {menu}".
- **Jejak audit satu saldo:** `IngredientHistoryTest` membuktikan satu riwayat memuat jual langsung, pemakaian resep, pembelian, koreksi, dan pemulihan pada SATU baris saldo (100 − 3 + 20 − 5 = saldo akhir, `current_stock` varian ikut sama).
- **Invarian buku besar:** `StockLedgerInvariantTest` diperluas — acak 80 pergerakan termasuk tipe `recipe_*` tetap menjaga saldo = Σ buku besar dan rantai `stock_before/after` tak putus; pemindai sumber menjamin hanya `StockService` menulis saldo/buku besar varian.
- **Audit pembaca tipe (T046):** hasil audit T003 menyisakan hanya `StockMovementReferences` dan label, keduanya sudah ditangani (T045, T027); tidak ada pembaca lain yang perlu diubah.
- **Belum:** uji peramban layar riwayat/Stok dan formulir; ekspor/impor pada peramban nyata.

### US6 (rekursi dan penjagaan)

- **Rekursi dilarang dua arah** (sebagian besar sudah ada sejak US1; US6 menambah bukti): menu beresep (aktif atau berlaku-masa-depan) tidak bisa jadi bahan; produk bertanda bahan tidak bisa diberi resep; produk/varian lain dari produk yang sama ditolak; siklus dua langkah tak bisa dibangun dari kedua urutan. Resep arsip tidak menjaga. 7 tes `IngredientRecursionTest`.
- **409 `ingredient_in_use`** (dengan daftar resep) untuk: ubah ke sumber stok `modifier`, nonaktifkan produk, matikan pelacakan stok varian, nonaktifkan varian (PUT varian dan batch varian), diskontinu produk (saat diminta), hapus permanen varian (sebelumnya kunci asing RESTRICT akan menjadi galat 500), dan IMPOR Excel (empat titik simpan di `applyProducts`; seluruh impor dibatalkan). Hanya PERUBAHAN nilai yang ditolak: mengirim ulang nilai yang sama lolos. Produk yang tak dipakai resep tidak tersentuh sama sekali. Mematikan penanda tetap selalu boleh dan resep lama tetap memotong (diuji lewat checkout sesudahnya). 11 tes `IngredientGuardsTest` + 3 tes impor.
- **Urutan pemeriksaan:** guard 409 dijalankan SEBELUM pemeriksaan penanda 422, karena bila resep memakai produk itu, 409 yang menyebut resepnya lebih berguna daripada 422 umum.
- **Celah yang diketahui:** (1) diskontinu yang sudah ANTRE di rilis menu tidak dibatalkan saat rilis diterapkan (hanya dicek saat diminta); (2) bahan dari pilihan modifier baru di `updateLine` baru terkunci oleh penjaga di akhir (lihat US4).
- **Temuan alat uji:** `PhpSpreadsheet::fromArray` menganggap angka `0` sebagai null dan TIDAK menulis selnya, jadi tes impor yang mengirim `0` untuk kolom boolean diam-diam mengirim sel kosong. Nilai 0 harus berupa teks `'0'`.

## 2. Verifikasi peramban

Belum diverifikasi.

## 3. Uji dua koneksi MySQL (keserentakan)

**Dijalankan sesi ini (T038):** `tests/Concurrency/ingredient_race.php` memakai proses PHP terpisah (koneksi MySQL terpisah, READ COMMITTED, semua mulai di penghalang bersama) terhadap `warkop_wjm_test`; menolak berjalan bila nama database tidak mengandung "test". Bukan bagian suite PHPUnit.

| Skenario | Hasil (3 dari 3 putaran) |
|---|---|
| A: 20 pesanan menu serentak, bahan produk tersisa 1 | tepat 1 berhasil, 19 ditolak, saldo 0, 1 pergerakan `recipe_sale` |
| B: 10 pesanan menu vs 10 penjualan langsung, sisa 1 | tepat 1 dari 20 lolos, saldo 0 |
| C: stok 5, 20 pesanan | tepat 5 berhasil, saldo 0 |
| D: dua menu, bahan yang sama dalam urutan baris terbalik, 40 pesanan (stok 30) | tepat 30 berhasil, tanpa deadlock pada kunci stok, bahan A dan B dipotong sebesar jumlah sukses, tidak ada saldo minus |

**Batas bukti (jangan dibaca lebih):** (1) bukan kontrol negatif: tidak diperlihatkan bahwa harness GAGAL bila `lockIngredients` dilepas; (2) skenario tab-terbuka-menahan vs penjualan langsung serentak belum ada (hanya diuji berurutan di `IngredientHoldTest`); (3) hanya `OrderService::create`, belum jalur tab/pesan mandiri; (4) satu mesin, Docker; beban 20–40 pekerja.

### TEMUAN BUG LAMA di luar 093 (tidak diubah)

`OrderService::generateOrderNumber()` memakai `COUNT(*) + 1` yang tidak atomik. Pada pesanan serentak ia menghasilkan **nomor kembar** (`UniqueConstraintViolationException` pada `orders.orders_order_number_unique`) dan **deadlock pada INSERT `orders`** (kunci unik). Terlihat di harness: 2–9 pengulangan per skenario. Pekerja harness mengulang hanya untuk dua kasus itu supaya bukti stok tidak tertutup; deadlock pada kunci stok tidak diulang dan akan tampil sebagai galat tak terduga. **Di produksi, dua kasir yang menyelesaikan penjualan pada detik yang sama bisa mendapat galat 500/409; perlu keputusan pemilik** (perbaikan: nomor dari urutan atomik atau percobaan ulang dengan kunci unik).

## 4. Verifikasi tablet Android

Belum diverifikasi.

## 5. Temuan Phase 1

### Audit pembaca tipe `stock_movements.type` (T003)

| Pembaca | Hasil |
|---|---|
| `StockController::movements` | Filter `type` bebas (`where('type', …)`), tanpa daftar tetap → aman. Referensi lewat `StockMovementReferences`. |
| `StockMovementReferences` | **Perlu cabang** untuk `recipe_*` (penjaga "pesanan memuat varian pergerakan" akan menjadikan referensi null; `reference_id` = id baris pesanan menu). |
| `LowStockHistory` | Membaca `stock_after` tanpa filter tipe → aman. |
| `VariantDeletionService` | Memeriksa ada tidaknya baris pergerakan varian → aman (baris `recipe_*` ikut mencegah hapus permanen, benar). |
| `MasterDataImportService` (lembar `stock`) | Menulis koreksi `adjustment` absolut → aman. |
| `ReportController`, `MaterialReport` | Tidak membaca `stock_movements` (laporan bahan memakai buku besar bahan baku) → tidak terdampak. |
| `resources/js/utils/stockMovements.js` + locale `master_data.type_*` | **Perlu tipe/label baru** (T027). |

### Asumsi yang diperiksa (T004)

- **(a) Impor resep** (`app/Services/Concerns/ImportsRecipesAndModifiers.php::applyRecipes`): resep dibangun dari `variant_ids` + baris bahan baku saja (`material_id`), lalu `RecipeService::update` mengganti isi. Untuk resep tanpa blok bermodifier, baris varian akan **terhapus** diam-diam. Untuk resep dengan blok bermodifier, pemetaan "keep" hanya membawa `material_id` (baris varian jadi `material_id: null`). **Terbukti perlu perlindungan (T044/T047)**.
- **(b) Android** (`android-pos/.../state/PosStore.kt`): tidak ada pengurangan stok lokal; tidak ditemukan antrean tulis offline (pencarian `outbox|retryQueue|pendingOrders|WorkManager` kosong). `refreshRecipeStock()` memuat ulang SELURUH menu (termasuk kartu produk bahan) setelah kirim/EDC/QRIS bila ada produk `stockBasis == "recipe"`; produk bahan hanya relevan bila ada menu beresep, jadi kartu bahan ikut segar. Belum diverifikasi di perangkat.

## 6. Hal yang belum terverifikasi

Peramban, dua koneksi MySQL nyata, tablet, migrasi pada DB dev.

## 7. Risiko APK lama

APK lama membaca `outlet_stock`/`track_stock` dan mengabaikan field baru (`ignoreUnknownKeys`); belum diuji dengan APK lama.
