# Data Model: Nama Varian di Ekspor Pre-order

Tanpa perubahan skema dan tanpa migrasi. Hanya bentuk BARIS EKSPOR yang bertambah.

## Sumber data (sudah ada)

- `preorder_items`: `preorder_id`, `variant_id` (FK `restrictOnDelete`), `sku_snapshot`, `name_snapshot`, `qty`, `sell_price`.
- `product_variants`: `variant_name` (bisa kosong), soft-delete, `HasDataMode`.

## Baris ekspor (satu per pre-order)

| Kunci | Isi | Perubahan |
|---|---|---|
| `products` | `sku_snapshot` item, dipisah `,` | tidak berubah |
| **`variant_names`** | `variant_name` item, dipisah ` \| `, urutan = urutan item | **baru, hanya-ekspor** |
| `quantities`, `unit_prices` | seperti sekarang | tidak berubah |
| `created_at`, `updated_at` | hanya-ekspor, di ujung | tidak berubah |

### Aturan

- Jumlah bagian `variant_names` = jumlah item = jumlah SKU di `products`.
- Varian tanpa nama atau tak ditemukan → bagian kosong (posisi dipertahankan).
- Pre-order tanpa item → `variant_names` = string kosong.
- Importer: kunci `variant_names` tidak pernah dibaca; `template()` tidak memuatnya.
