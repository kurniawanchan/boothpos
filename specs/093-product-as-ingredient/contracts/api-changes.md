# Perubahan Kontrak API: Produk Sebagai Bahan Resep

**Klasifikasi**: INTERNAL | **Date**: 2026-11-07

`docs/openapi-pos-mvp.yaml` WAJIB diubah di commit yang sama dengan setiap perubahan di bawah (Konstitusi, Documentation & Change Discipline). Semua perubahan **aditif**: klien lama (APK lama, web lama) tetap benar.

Konvensi kode status: `422` bentuk/validasi, `409` konflik aturan bisnis, `403` peran. Izin: menu `products` (sama dengan resep hari ini).

## 1. Produk

`POST /products`, `PUT /products/{id}` menerima field opsional:

| Field | Tipe | Aturan |
|---|---|---|
| `use_as_ingredient` | boolean | Aturan validasi di data-model.md. 422 bila produk tak melacak stok / bersumber `modifier` / punya varian beresep. |
| `ingredient_unit` | string ≤ 20, nullable | Label tampilan. |

Respons `ProductResource` menambah `use_as_ingredient`, `ingredient_unit`, dan (hanya di detail produk) `ingredient_used_in`: daftar `[{recipe_id, recipe_name, product_id, product_name}]` untuk resep tidak-arsip yang merujuk produk ini.

Penolakan perubahan yang merusak resep (`409`, kode `ingredient_in_use`): mengubah `track_stock` → false, `stock_source` → `modifier`, menonaktifkan produk/varian, atau menghapus, selama dirujuk resep tidak-arsip. Badan: `{message, code: "ingredient_in_use", recipes: [{id, name, product_name}]}`.

## 2. Pilihan bahan untuk resep (BARU)

`GET /recipe-ingredients?exclude_product_id=&search=&per_page=`

Respons (amplop paginasi standar `{data, meta}`):

```json
{ "data": [{
  "variant_id": 123, "product_id": 45,
  "product_name": "Telur", "variant_name": "Standard", "sku": "TLR-001",
  "unit": "pcs",
  "on_hand": 200, "held": 5, "available": 195,
  "is_active": true
}] }
```

- Outlet = outlet terpilih (konteks server, tidak dari body); `available = on_hand − held`.
- Hanya varian: produk `use_as_ingredient = true`, varian aktif, melacak stok, bersumber `variant`, tidak beresep.
- `exclude_product_id` mengecualikan produk resep yang sedang disunting.

## 3. Resep

`POST /products/{id}/recipes`, `PUT /recipes/{id}`: setiap baris di `blocks[].lines[]` (dan bentuk lama `lines[]`) memuat **tepat satu** dari `material_id` ATAU `variant_id`.

| Kondisi | Kode | Pesan (kunci lang) |
|---|---|---|
| keduanya/ tak satu pun terisi | 422 | `recipe_ingredient_required` |
| varian bukan milik produk bertanda bahan | 422 | `recipe_ingredient_not_flagged` |
| varian milik produk resep itu sendiri | 422 | `recipe_ingredient_self` |
| varian beresep / menu beresep jadi bahan | 422 | `recipe_ingredient_recursive` |
| varian tak melacak stok / bersumber modifier | 422 | `recipe_ingredient_untracked` |
| `shrinkage_pct` ≠ 0 pada baris varian | 422 | `recipe_ingredient_no_shrinkage` |
| `quantity ÷ yield` bukan bilangan bulat ≥ 1 | 422 | `recipe_ingredient_whole_quantity` |
| produk resep bertanda bahan | 422 | `recipe_product_is_ingredient` |

Respons baris (`presentLines`): untuk baris varian `material_id: null`, `variant_id`, `name` ("Telur" atau "Telur – Standard"), `sku`, `unit` (label satuan), `kind: "product"`, `quantity`, `shrinkage_pct: "0.00"`, dan bila boleh melihat biaya `unit_cost`/`line_cost` dari `cost_price` varian (null bila 0). Baris bahan baku tidak berubah. Baris yang produknya sudah tak bertanda: `ingredient_flagged: false` (UI menandainya).

## 4. Stok menu dan payload POS (perilaku, bentuk tetap)

`GET /products?pos=1`, `GET /variants/lookup`:
- `outlet_stock` varian **menu beresep**: tetap `portions`, kini juga memperhitungkan bahan varian (saldo − tahanan).
- `outlet_stock` varian **produk bahan** (dirujuk resep tidak-arsip): saldo outlet − tahanan.
- `stock_limiting` menambah `ingredient_type: "material" | "variant"` dan `variant_id` (null untuk bahan baku); `material_id` null untuk bahan varian. Klien lama yang mengabaikan field baru tetap benar; pembaca yang mengasumsikan `material_id` non-null perlu diperiksa (research, risiko 5).

## 5. Konflik stok saat transaksi

| Situasi | Kode | Badan |
|---|---|---|
| Pesanan beresep melebihi saldo bahan varian (setelah tahanan) | 409 `insufficient_recipe_stock` | bentuk 092 yang sudah ada; `limiting.variant_id` terisi |
| Penjualan langsung produk bahan menembus tahanan resep | 409 `insufficient_stock_reserved` | `{message, code, variant_id, sku, available, reserved}` |
| Kekurangan stok murni (tanpa tahanan) | tetap seperti sekarang (`ValidationException` `qty_change`) | tidak berubah |

## 6. Riwayat stok

`GET /stock/movements`, riwayat per varian: `type` kini dapat bernilai `recipe_sale | recipe_return | recipe_waste`; `reference` untuk tipe ini = `{type: "order", id, number, menu}` (nama menu penyebab dari `name_snapshot`). Filter tipe menerima nilai baru.

## 7. Impor/Ekspor (perilaku)

- `GET /exports/recipes` mengabaikan baris varian (sheet hanya memuat bahan baku).
- Impor `recipes` yang menimpa resep di tempat mempertahankan baris varian yang ada; tidak ada kolom baru.
