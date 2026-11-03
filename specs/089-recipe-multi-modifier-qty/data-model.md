# Data Model: 089

**Klasifikasi**: INTERNAL (struktur data pelanggan nyata tidak dimuat)

## Perubahan skema

### `recipes` (diubah)

| Kolom | Perubahan | Catatan |
|---|---|---|
| `version` | **dihapus** | beserta unique `uniq_recipes_product_version` |
| `name` | **baru**, `varchar(100)`, wajib untuk resep baru | pembeda antar resep satu produk ("Dasar", "Topping standar"); migrasi mengisi "Resep" |
| `archived_at` | **baru**, `timestamp` null | resep lama hasil migrasi yang tak lagi tertaut ke varian mana pun; baca-saja, tidak memotong stok |
| `yield`, `effective_from`, `note`, `cost_applied_at`, `created_by`, `data_mode` | tetap | `yield` = Portions di UI; nama kolom/kunci API tidak berubah |

Indeks `idx_recipes_effective (product_id, effective_from)` tetap.

### `recipe_variants` (tetap)

Unique `(recipe_id, variant_id)` tetap. Yang berubah adalah INVARIAN: sebuah varian boleh tertaut ke banyak resep aktif. Aturan "satu aktif per varian" ada pada kode lama (`activeFor`), bukan pada skema, sehingga tidak ada perubahan struktur.

### `recipe_lines` (tetap)

`quantity`, `shrinkage_pct`, `material_id` (restrict), `display_order`.

### `modifier_options` (diubah)

| Kolom | Tipe | Default | Catatan |
|---|---|---|---|
| `min_qty` | `tinyint unsigned` | 1 | berlaku saat pilihan dipilih; CHECK `min_qty >= 1` |
| `max_qty` | `tinyint unsigned` null | 1 | null = tanpa batas atas (validasi API membatasi 50); CHECK `max_qty IS NULL OR max_qty >= min_qty` |

Baris lama mendapat 1/1 sehingga perilaku "ada atau tidak ada" tidak berubah.

### `modifier_option_materials` (tidak berubah)

Unique `(modifier_option_id, material_id)` sudah benar per pilihan; satu bahan di banyak pilihan sudah didukung skema. Masalahnya hanya di validasi request (research D7).

### `order_items.modifiers` (JSON, tanpa migrasi kolom)

Tiap elemen snapshot kini:

```json
{"group_id": 3, "group": "Level Pedas", "option_id": 11, "option": "2", "qty": 2, "price_delta": "6000.00"}
```

- `qty` baru (int ≥ 1); elemen lama tanpa `qty` dibaca 1 oleh SEMUA pembaca (`ModifierSnapshot`, `ModifierMaterialConsumption`, struk, KOT, tablet).
- `price_delta` = kontribusi total pilihan itu (sudah × qty dan sudah memuat harga dasar ekstra grup `portion_per_option`).

## Migrasi data

Satu migrasi, satu transaksi, tanggal BARU setelah migrasi terakhir (awalan tanggal berurutan FK jangan diubah):

1. Hitung versi aktif lama tiap varian (aturan `activeFor` lama).
2. Hapus baris `recipe_variants` yang bukan versi aktif varian itu; catat tiap pasangan yang dilepas ke `activity_logs` (`recipe_migrated`).
3. `archived_at = now()` untuk resep tanpa tautan varian tersisa dan bukan resep masa depan.
4. Isi `name`; hapus `version` dan indeks uniknya.

Satu arah: `down()` tidak memulihkan versi (alasan dicatat di migrasi). Pemulihan data dev memakai cadangan (`app:backup`).

## Entitas dan relasi (ringkas)

```text
Product 1─* Recipe *─* ProductVariant        (recipe_variants; satu varian boleh banyak resep aktif)
Recipe  1─* RecipeLine *─1 Material
ModifierGroup 1─* ModifierOption(min_qty, max_qty) 1─* ModifierOptionMaterial *─1 Material
Order 1─* OrderItem(variant_id, qty, modifiers[{option_id, qty, price_delta}])
OrderItem ─(sale/return/waste)→ MaterialStockMovement(reference_type=order_item)   # tidak berubah
```

## Aturan validasi

- Resep: `Portions > 0`; minimal satu baris; `quantity > 0`; `0 ≤ susut ≤ 99.99`; minimal satu varian milik produk yang sama; tanggal berlaku maksimum 30 hari ke belakang; varian ber-BOM lengkap ditolak 409 `cost_source_conflict`; `name` wajib dan ≤ 100.
- Pilihan modifier: `min_qty ≥ 1`; `max_qty` kosong atau ≥ `min_qty` dan ≤ 50; bahan tidak kembar DI DALAM satu pilihan.
- Pesanan: tiap `qty` pilihan dalam `[min_qty, max_qty]` pilihan itu; id pilihan harus berasal dari grup efektif produk; `modifier_selections` dan `modifier_option_ids` tidak boleh dikirim bersamaan.

## Transisi status

Tidak ada status baru. Pergerakan stok bahan tetap `sale` (selesai), `return` (void/refund), `waste` (buang) dan append-only.
