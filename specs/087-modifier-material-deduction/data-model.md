# Data Model: Pilihan Modifier Memakai Bahan Baku

**Klasifikasi**: CONFIDENTIAL (struktur data internal)

**Feature**: [spec.md](./spec.md) · Keputusan: [research.md](./research.md)

## Entitas baru

### `modifier_option_materials` (model `ModifierOptionMaterial`, `HasDataMode`)

| Kolom | Tipe | Aturan |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `modifier_option_id` | FK `modifier_options` | `cascadeOnDelete` (pilihan sendiri tidak pernah dihapus keras, hanya dinonaktifkan; cascade untuk hapus grup) |
| `material_id` | FK `materials` | `restrictOnDelete`; penjaga 409 di service karena bahan memakai soft delete (D10) |
| `quantity` | DECIMAL(12,4) | > 0 (CHECK `chk_modifier_option_materials_qty_pos`), satuan = `materials.unit` |
| `data_mode` | ENUM('demo','live') | indeks `idx_modifier_option_materials_data_mode` |
| `created_at`, `updated_at` | timestamp | |

Indeks: UNIQUE `uniq_modifier_option_material (modifier_option_id, material_id)`; INDEX `(material_id)` untuk "dipakai oleh".

**Catatan grup dihapus**: `ModifierGroupService::delete` memanggil `$group->options()->delete()` (`ModifierGroupService.php:92`). FK `modifier_options.modifier_group_id` adalah RESTRICT, jadi opsi memang dihapus keras di jalur itu; cascade di sini membuang pemetaannya juga. Riwayat movement tidak bergantung pada pemetaan.

**Validasi tulis** (`StoreModifierGroupRequest` + service):
- `options.*.materials`: `sometimes|array|max:20`
- `options.*.materials.*.material_id`: `required|integer|distinct` per pilihan; di service: bahan ada pada mode berjalan (global scope `HasDataMode`), tidak terhapus, `is_active = true` → selain itu 422 `options.{i}.materials.{j}.material_id`
- `options.*.materials.*.quantity`: `required|numeric|gt:0|max:99999999.9999`, disimpan 4 desimal

### Relasi model

- `ModifierOption::materials(): HasMany<ModifierOptionMaterial>` (dengan `material` eager-load untuk resource)
- `ModifierOptionMaterial::material(): BelongsTo` (`withTrashed()` untuk tampilan)
- `Material::modifierUsages(): HasMany<ModifierOptionMaterial>`

## Entitas yang diubah

### `material_stock_movements.type`

```
ENUM('purchase','adjustment','transfer_out','transfer_in','transfer_cancel','sale','return','waste')
```

| Jenis | Tanda `qty_change` | Penulis | `reference_type` / `reference_id` | Boleh minus |
|---|---|---|---|---|
| `sale` | − | `consumeOrder` (saat selesai) | `order_item` / id baris | ya |
| `return` | + | `restoreOrder` (void/refund pesanan selesai) | `order_item` / id baris | — |
| `waste` | − | `wasteLine` (batal "buang", void tab) | `order_item` / id baris | ya |
| lainnya | tetap | tetap | tetap | tidak (aturan lama) |

`down()` migrasi: menolak berjalan bila sudah ada baris `sale|return|waste` (pola penjaga migrasi Stage 5), supaya rollback tidak memotong enum dengan data hidup.

### `material_outlet_stock`

- Lepas `chk_material_outlet_stock_nonneg`. `current_stock` boleh < 0; hanya `MaterialStockService` yang menulisnya, dan hanya untuk `sale`/`waste` boleh menghasilkan minus (D6).
- `down()`: menolak bila ada saldo < 0.

### `order_items.cost_price` (perilaku, bukan skema)

Ditambah biaya bahan pilihan sekali, saat pesanan selesai (D9). `orders.total_cost` ditulis ulang = Σ `cost_price × qty` di transaksi yang sama.

## Aturan hitung

Untuk pesanan P yang menjadi selesai:

```
untuk tiap baris L di P, untuk tiap s di L.modifiers (option_id):
    untuk tiap pemetaan m (option_id = s.option_id, material aktif, mode berjalan):
        need[m.material_id][L.id] += m.quantity × L.qty
round(need, 3)                                  -- presisi saldo bahan
urutkan material_id ASC, lalu L.id ASC          -- urutan kunci tetap (D5)
applyMovement(material, P.outlet_id, 'sale', −need, 'order_item', L.id, user)
```

Pengembalian untuk baris L (void/refund):

```
net[mat] = −Σ qty_change WHERE reference=(order_item, L.id) AND material=mat AND type IN ('sale','return')
net > 0  → applyMovement(mat, P.outlet_id, 'return', +net, 'order_item', L.id, user)
```

## Transisi status yang memicu bahan

```
            addLine/updateLine/removeLine('return')/move/merge     → (tidak ada movement bahan)
   [open] ─────────────────────────────────────────────────────────────┐
     │  removeLine('waste') / voidTab baris sudah dimasak  → waste     │
     │                                                                 │
     ├── settle lunas ───────────────► [completed] ──► sale (sekali)   │
     ├── split ──► pesanan baru ─────► [completed] ──► sale (sekali)   │
     └── voidTab ────────────────────► [voided]   (waste per baris dimasak)
 checkout ritel ────────────────────► [completed] ──► sale (sekali)
 [completed] ── void / refund ───────► return (net per baris, sekali)
```

## Tambahan US6 (2026-10-31)

### `products.stock_source`

`ENUM('variant','modifier') NOT NULL DEFAULT 'variant'`. Produk lama = `variant`. Diubah lewat formulir produk; log `stock_source_changed`.

### `order_items.stock_source`

`ENUM('variant','modifier') NULL`. Snapshot saat baris dibuat: `variant` bila stok varian bergerak untuk baris itu, `modifier` bila tidak (produk bersumber Modifier, atau varian `track_stock` mati). NULL = baris lama (perilaku lama). Dibaca hanya oleh `StockService::applyLineMovementIfTracked` (pengembalian). Disalin saat split mengklon baris.

### Aturan

```
tracksOwnStock(variant) = variant.track_stock AND variant.product.stock_source = 'variant'

jual/tambah qty  : bila tracksOwnStock → movement 'sale' varian; line.stock_source = variant|modifier
kembali/kurangi  : bila line.stock_source = 'variant' (atau NULL dan variant.track_stock) → movement 'return' varian
bahan modifier   : SELALU sesuai consumeOrder/restoreOrder/wasteLine (FR-025 = gabungan), tidak tergantung stock_source
tampilan POS     : track_stock efektif = tracksOwnStock (FR-031)
```

