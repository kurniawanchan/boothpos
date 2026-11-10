# Data Model: Produk Sebagai Bahan Resep dengan Stok Bersama

**Klasifikasi**: INTERNAL | **Date**: 2026-11-07

Prinsip: **tidak ada tabel saldo baru**. Hanya tiga tabel yang ada diubah.

## Perubahan skema (satu migrasi, urutan tanggal setelah `2027_01_03_000004`)

### `products`

| Kolom | Tipe | Aturan |
|---|---|---|
| `use_as_ingredient` | boolean, bawaan `false` | Penanda tingkat produk. Menyala = semua varian dapat dipilih sebagai bahan resep BARU. |
| `ingredient_unit` | varchar(20), null | Label satuan tampilan (mis. "pcs", "bungkus"). Kosong → UI menampilkan "unit". Tidak ada konversi. |

Keduanya masuk `$fillable` dan `StoreProductRequest`/`UpdateProductRequest`. `ProductResource` mengirimnya.

### `recipe_lines`

| Perubahan | Rincian |
|---|---|
| `material_id` | menjadi **nullable** |
| `variant_id` | bigint unsigned **nullable**, FK `product_variants(id)` `restrictOnDelete` |
| CHECK | `((material_id IS NULL) <> (variant_id IS NULL))` — tepat satu terisi (via `DB::statement`, MySQL 8) |
| indeks | `idx_recipe_lines_variant (variant_id)` untuk pencarian "varian ini dipakai resep mana" |

`quantity` (decimal 12,4) dan `shrinkage_pct` (decimal 5,2) tetap. Baris varian: `shrinkage_pct = 0` dan `quantity ÷ yield` bilangan bulat ≥ 1 (validasi service, bukan CHECK, karena bergantung pada `recipes.yield`).

Baris lama tidak berubah (`variant_id` null, `material_id` terisi). Migrasi tidak menyentuh data.

### `stock_movements`

`type` ENUM diperluas dengan: `recipe_sale`, `recipe_return`, `recipe_waste`. `down()` menolak bila ada baris bertipe baru (preseden `2026_12_30_000002`).

| Tipe | Arti | Tanda `qty_change` | `reference_type` / `reference_id` |
|---|---|---|---|
| `recipe_sale` | pemakaian sebagai bahan saat pesanan selesai | negatif | `order_item` / id baris MENU penyebab |
| `recipe_return` | pemulihan karena void/refund | positif | `order_item` / id baris menu penyebab |
| `recipe_waste` | bahan terpakai walau pesanan tak selesai (batal "buang") | negatif | `order_item` / id baris menu penyebab |

`variant_id` pada baris ini = varian BAHAN (bukan menu). Menu penyebab dibaca lewat `reference_id` → `order_items`.

## Entitas (ringkas)

- **Produk bahan**: `Product.use_as_ingredient = true` (+ varian yang melacak stok, bersumber `variant`, tidak beresep).
- **Baris bahan resep**: `RecipeLine` dengan tepat satu dari `material_id` / `variant_id`.
- **Saldo bersama**: `VariantOutletStock` (tidak berubah).
- **Tahanan varian**: turunan, tidak disimpan — Σ kebutuhan varian-bahan dari `order_items` milik `orders.status = open` di outlet itu.
- **`IngredientVariants`** (Support, memo per permintaan): himpunan `variant_id` yang dirujuk baris resep milik resep tidak-arsip; kunci memo = hari bisnis + mode data; dibuang oleh semua penulis resep (sama dengan `RecipeDrivenVariants`).

## Aturan validasi (sumber: spec FR dan research D7–D8)

Menandai produk bahan (`use_as_ingredient: false → true`), semua 422 kecuali dinyatakan lain:
- semua varian aktif harus `track_stock = true`;
- `stock_source = variant`;
- tidak ada varian dengan resep tidak-arsip.

Baris resep dengan `variant_id`:
- varian ada, tidak dihapus, tidak nonaktif, milik produk `use_as_ingredient = true` (khusus baris BARU atau berubah);
- produk pemilik varian ≠ produk resep;
- varian tidak punya resep tidak-arsip; produk resep tidak bertanda bahan;
- `shrinkage_pct = 0`; `quantity ÷ yield` bulat ≥ 1;
- tidak ada dua baris varian yang sama dalam satu blok.

Penjagaan terhadap perubahan yang merusak resep (409): mematikan pelacakan stok, memindah ke sumber `modifier`, menonaktifkan/menghapus varian atau produk yang dirujuk resep tidak-arsip.

Mematikan penanda (`true → false`) **diizinkan**: resep lama tetap utuh dan tetap memotong; hanya hilang dari pilihan resep baru (FR-004).

## Transisi keadaan

```
produk biasa ──(nyalakan penanda, lolos validasi)──▶ produk bahan
produk bahan ──(matikan penanda)──▶ produk biasa yang MASIH dirujuk resep lama (tetap dipotong)
produk bahan dirujuk resep ──(nonaktifkan / hapus / matikan stok)──▶ DITOLAK 409
menu beresep ──(nyalakan penanda)──▶ DITOLAK 422
```

## Aliran pergerakan stok

| Operasi | Penulis | Tipe | Catatan |
|---|---|---|---|
| Jual langsung | `StockService::applyMovementIfTracked` | `sale` | menolak bila saldo sesudah < tahanan (varian dalam `IngredientVariants`) |
| Pesanan beresep selesai | `ModifierMaterialConsumption::consumeOrder` → `StockService::applyMovement` | `recipe_sale` | didahului `assertCanFulfil` (409) |
| Void / refund | `restoreOrder` (buku besar `recipe_sale − recipe_return`) | `recipe_return` | resep saat ini diabaikan |
| Batal "buang" | `wasteLine` | `recipe_waste` | tidak pernah kembali |
| Terima barang / koreksi / opname / transfer / retur beli | jalur yang sudah ada | tipe lama | saldo yang sama; tanpa perubahan |

## Pembaca yang terdampak (wajib diaudit)

`StockController` (daftar & riwayat), `StockMovementReferences`, `LowStockHistory`, laporan stok di `ReportController`, `MaterialReport` (tidak terdampak: ledger bahan baku), ekspor master data `stock`, `utils/stockMovements.js`, locale `master_data.type_*`.
