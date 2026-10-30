# Data Model: 086

**Klasifikasi**: INTERNAL

## Tabel baru: `modifier_group_product_exclusions`

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `product_id` | FK → `products.id`, `ON DELETE CASCADE` | item yang melepas grup |
| `modifier_group_id` | FK → `modifier_groups.id`, `ON DELETE CASCADE` | grup yang dilepas |
| `created_at` | timestamp nullable | kapan dilepas (untuk audit manual) |

Kunci primer komposit (`product_id`, `modifier_group_id`). Tanpa `data_mode`: seperti tabel pivot lain, hanya dijangkau lewat induk (produk, grup) yang sudah ber-mode.

Migrasi: nama berawalan tanggal baru di akhir urutan (awalan tanggal migrasi bersifat menentukan urutan FK, jangan diubah/diurutkan ulang). Hanya `create`, tanpa menyentuh tabel lain, dan `down()` menghapus tabel.

## Relasi

- `Product::excludedModifierGroups(): BelongsToMany` → `ModifierGroup` lewat tabel di atas.
- Tabel tautan item `modifier_group_product` dan tabel kategori `modifier_group_category` TIDAK berubah.

## Aturan

- Grup efektif item = (grup tautan item) ∪ (grup kategori − grup yang dikecualikan item). Tautan item menang atas pengecualian untuk grup yang sama.
- Pengecualian berkaitan dengan pasangan item + grup, bukan kategori: bertahan bila item pindah kategori.
- Menghapus grup atau item menghapus baris pengecualian (cascade).
- Satu pasangan paling banyak satu baris (kunci komposit).
- Pesanan, tab, dan struk menyimpan snapshot modifier mereka sendiri dan tidak dibaca ulang dari tabel ini.

## Bentuk JSON baru

`GET /products/{id}` (detail halaman Produk), tambahan:

```json
"modifier_excluded": [
  { "group_id": 12, "name": "Suhu", "options": ["Panas", "Dingin"] }
]
```

`modifier_links` hanya memuat grup efektif (baris `source: category` untuk grup yang dikecualikan tidak ada).

`PUT /products/{id}/modifier-links`, tambahan permintaan:

```json
{ "links": [ ... ], "excluded_group_ids": [12] }
```

Tanpa `excluded_group_ids` = pengecualian tidak berubah; `[]` = semua dipulihkan.
