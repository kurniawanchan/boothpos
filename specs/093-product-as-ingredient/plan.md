# Implementation Plan: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL

**Branch**: `093-product-as-ingredient` | **Date**: 2026-11-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/093-product-as-ingredient/spec.md`

## Summary

Baris resep kini boleh merujuk **varian produk** (bukan hanya bahan baku). Varian itu memakai **saldo yang sudah ada** (`variant_outlet_stock`), sehingga penjualan langsung dan pemakaian resep memotong angka yang sama. Tidak ada tabel saldo baru.

Pendekatan teknis (alasan lengkap di [research.md](research.md)):

1. **Penanda** `products.use_as_ingredient` (+ label satuan opsional `ingredient_unit`) di tingkat produk.
2. **`recipe_lines`** mendapat `variant_id` nullable; `material_id` menjadi nullable; CHECK memastikan TEPAT SATU terisi.
3. **Penerjemah tunggal** `ModifierMaterialConsumption` (087/089/092) diperluas menghasilkan kebutuhan **varian** di samping kebutuhan bahan baku; penulisnya tetap `StockService::applyMovement()` (satu-satunya penulis stok varian) dengan tiga tipe pergerakan baru `recipe_sale`, `recipe_return`, `recipe_waste` (ENUM diperluas).
4. **Tahanan** (open-tab hold) diperluas ke varian: `MenuStockService` menghitung kebutuhan varian-bahan dari baris tab terbuka, dipakai oleh penjaga kapasitas, angka stok menu, DAN penjualan langsung produk bahan (jalur tulis `applyMovement` untuk `sale` menolak bila saldo sesudahnya di bawah tahanan).
5. **Rekursi dilarang** dua arah (menu beresep tidak boleh menjadi bahan; produk bahan tidak boleh diberi resep aktif).
6. **Jumlah bulat** untuk bahan produk (stok varian bilangan bulat): kebutuhan per porsi harus bilangan bulat ≥ 1, tanpa susut; tidak ada akumulasi pecahan.
7. **Android tanpa perubahan kode fungsional**: tablet hanya membaca `outlet_stock`/`track_stock` dari server, yang kini sudah memperhitungkan saldo bersama dan tahanan.

## Technical Context

**Language/Version**: PHP 8.x / Laravel 13 (backend), Vue 3 + Pinia + Tailwind v4 (web), Kotlin + Jetpack Compose (`android-pos/`)

**Primary Dependencies**: Eloquent, Laravel Excel (impor/ekspor master data), Vitest, PHPUnit

**Storage**: MySQL 8 (wajib; CHECK dan ENUM lewat `DB::statement`). Tabel diubah: `products`, `recipe_lines`, `stock_movements`. Tabel baru: tidak ada.

**Testing**: `tests/Feature/` (MySQL nyata, perintah kontainer dengan `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array`), `qa-tests/` (Vitest), uji perangkat Android manual

**Target Platform**: server Laravel + SPA web; tablet Android (POS)

**Project Type**: web-service + SPA + aplikasi mobile (tiga bagian yang sudah ada)

**Performance Goals**: daftar menu POS tetap jumlah kueri tetap terhadap banyaknya varian (Konstitusi V); tahanan varian dihitung SEKALI per permintaan (`prime`), bukan per varian

**Constraints**: stok varian bilangan bulat; satu penulis stok per jenis saldo; semua penulisan di dalam transaksi READ COMMITTED terluar untuk jalur yang menjaga tahanan; tidak ada migrasi yang mengubah angka lama

**Scale/Scope**: puluhan produk bahan per outlet, ratusan menu; satu server per organisasi

## Constitution Check

*GATE: lulus sebelum Phase 0; diperiksa ulang setelah Phase 1.*

| Prinsip | Penilaian | Catatan |
|---|---|---|
| I. Kode bersih, satu jalur tulis | **Lulus** | Saldo varian tetap ditulis HANYA `StockService::applyMovement()`; penerjemah pesanan→stok tetap SATU (`ModifierMaterialConsumption`), bukan kelas kedua. Penambahan kecil: `IngredientVariants` (himpunan varian yang dirujuk resep, meniru `RecipeDrivenVariants`) dan metode tahanan varian di `MenuStockService`. |
| II. Pengujian | **Lulus (rencana)** | Tes `tests/Feature/` di MySQL nyata untuk: validasi dua arah, pemotongan, void/refund dari buku besar, tahanan, keserentakan, idempotensi, impor/ekspor. Uji peramban untuk laci resep dan formulir produk WAJIB sebelum "selesai"; hasilnya dicatat di `evidence.md` (belum ada). |
| III. Konsistensi UX | **Lulus** | Token desain, salinan Indonesia, `BaseSelect` yang sama dipakai pilihan bahan; 409 untuk konflik, 422 untuk bentuk; kontrol disembunyikan bagi peran tanpa izin (menu `products`). |
| IV. Keamanan | **Lulus** | Angka stok/kebutuhan selalu dihitung server; outlet dari konteks server (bukan body); penanda dan perubahan resep ditulis ke `ActivityLogger` dalam transaksi yang sama; pergerakan stok = potret tak berubah (jumlah yang dipotong disimpan, bukan diturunkan ulang dari resep). Pilihan bahan hanya membaca produk yang boleh dilihat pengguna. |
| V. Performa | **Lulus** | Tidak ada kueri per varian: tahanan dan himpunan bahan-produk dimuat sekali per permintaan. Angka ketersediaan DIHITUNG langsung (tanpa cache) dengan sengaja, sehingga aturan "kunci cache" tidak berlaku; memo `IngredientVariants` dikunci hari bisnis + mode data (seperti `RecipeDrivenVariants`). |

**Pemeriksaan ulang pasca-desain (Phase 1)**: tetap lulus. Tidak ada pelanggaran yang perlu dicatat di Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/093-product-as-ingredient/
├── plan.md              # berkas ini
├── research.md          # keputusan D1–D14
├── data-model.md        # perubahan skema dan aturan keadaan
├── quickstart.md        # skenario verifikasi
├── contracts/
│   └── api-changes.md   # perubahan endpoint dan payload
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks (belum dibuat)
```

### Source Code (repository root)

```text
app/
├── Models/            Product (flag + unit), RecipeLine (variant), StockMovement (tipe baru)
├── Services/
│   ├── RecipeService.php                 validasi dua jenis bahan, aturan bulat, biaya, presentasi
│   ├── ModifierMaterialConsumption.php   kebutuhan varian, potong/pulih/buang via StockService
│   ├── MenuStockService.php              stok menu dari saldo varian, tahanan varian, penjaga
│   ├── StockService.php                  penjagaan tahanan pada `sale`; tipe `recipe_*` diterima
│   ├── StockMovementReferences.php       resolusi `recipe_*` → nomor pesanan + nama menu
│   ├── VariantDeletionService.php        tolak hapus varian yang dirujuk resep
│   └── (ProductService / ProductController) larangan ubah flag/ stok yang merusak resep
├── Support/           IngredientVariants (baru), OutletPriceBook (ketersediaan dikurangi tahanan)
├── Http/Controllers/Api/  ProductController, RecipeController (+ pilihan bahan produk), StockController
├── Http/Requests/     Store/UpdateProductRequest (use_as_ingredient, ingredient_unit), recipe requests
└── Http/Resources/    ProductResource, ProductVariantResource
database/migrations/   satu migrasi skema (products, recipe_lines, stock_movements ENUM)
docs/openapi-pos-mvp.yaml   diperbarui di commit yang sama
lang/id, lang/en       pesan baru
resources/js/
├── views/products/ProductFormDrawer.vue         toggle + label satuan
├── components/product/RecipeDrawer.vue          grup "Produk" di pilihan bahan, baris tanpa susut
├── components/product/RecipeSection.vue         tampilan baris produk bahan
├── utils/stockMovements.js, locales/{id,en}.json   tipe & label pergerakan baru
├── api/recipes.js                               muatan baris `variant_id`
tests/Feature/         Recipe*/Ingredient* baru; StockLedgerInvariantTest diperluas
qa-tests/              unit util + komponen
android-pos/           tanpa perubahan kode; verifikasi payload + perangkat
```

**Structure Decision**: tiga bagian yang sudah ada (Laravel API, SPA Vue, aplikasi `android-pos/`) dipertahankan; fitur ini menambah kolom dan logika di service yang sudah menjadi pemilik tunggal masing-masing concern, bukan modul baru.

## Complexity Tracking

Tidak ada pelanggaran Konstitusi. Catatan bentuk (bukan pelanggaran): satu kolom polimorfik `recipe_lines.variant_id` (XOR dengan `material_id`) dipilih daripada tabel baris kedua karena yang terakhir menggandakan urutan, blok, snapshot, dan penyajian resep (lihat research D2).
