# Data Model: Pengisian awal katalog Odoo WJM

**Klasifikasi**: INTERNAL — contoh nilai di dokumen ini generik, bukan data pelanggan.

**Fitur**: [spec.md](./spec.md) · **Riset**: [research.md](./research.md)

Tidak ada tabel, kolom, atau migrasi baru. Fitur ini menambah satu berkas data (`catalog.json` + foto) dan menulis baris ke tabel yang sudah ada.

## 1. Berkas katalog — `database/seeders/data/wjm-odoo/catalog.json`

Kontrak formal: [contracts/catalog.schema.json](./contracts/catalog.schema.json). Validasi dijalankan `App\Support\WjmCatalog` (research D10).

### 1.1 Akar

| Kunci | Jenis | Aturan |
|---|---|---|
| `format` | int | Harus `1` |
| `outlet_name` | string | Nama outlet tujuan bawaan: "Warkop WJM 3" (FR-003) |
| `source` | objek | Lihat 1.2 |
| `approval` | objek | Lihat 1.3 |
| `categories` | larik | 10 entri (1.4) |
| `attributes` | larik | 11 entri sumber, termasuk yang tidak dipakai (1.5) |
| `option_groups` | larik | Grup pilihan dari atribut "opsi saja" (1.6) |
| `items` | larik | Item menu (1.7) |
| `excluded` | larik | Baris sumber yang dikecualikan beserta alasannya (1.8) |

Kunci yang dilarang di mana pun dalam berkas: `responsible`, `user`, `email`, `phone` (SC-006). Tes memindai berkasnya.

### 1.2 `source`

| Kunci | Jenis | Aturan |
|---|---|---|
| `system` | string | `"Odoo"` |
| `exported_on` | tanggal | Tanggal berkas ekspor |
| `files` | objek | Nama berkas `categories`, `attributes`, `products` (hanya nama, bukan isinya) |
| `rows` | objek | `categories`, `attribute_values`, `products`: jumlah baris sumber. Dipakai pertanggungjawaban laporan (SC-005) |

Invarian: `len(items) + len(excluded[source=products]) == rows.products`; `len(categories) == rows.categories`; total nilai di `attributes` `== rows.attribute_values`.

### 1.3 `approval` — transisi status

```text
draft ──(pemilik menjawab pertanyaan, catalog-mapping.md diberi status Disetujui, JSON dibangkitkan ulang)──▶ approved
```

| Kunci | `draft` | `approved` |
|---|---|---|
| `status` | `"draft"` | `"approved"` |
| `approved_on` | `null` | tanggal (YYYY-MM-DD) |
| `approved_by_role` | `null` | peran, mis. `"pemilik"`, bukan nama orang |

Pengisian sungguhan pada status `draft` ditolak; pratinjau boleh (research D9, D10).

### 1.4 Kategori

| Kunci | Jenis | Aturan |
|---|---|---|
| `name` | string | Unik; nama persis dari sumber |
| `order` | int | Urutan sumber, mulai 0 (jadi `display_order`) |
| `station` | enum | `"Bar"` atau `"Dapur"` (FR-005) |

### 1.5 Atribut sumber

| Kunci | Jenis | Aturan |
|---|---|---|
| `name` | string | Nama atribut Odoo |
| `creates_variants` | bool | `true` = "Instantly"; `false` = "Never (option)" |
| `values` | larik string | Semua nilai sumber, termasuk yang dikecualikan |

### 1.6 Grup pilihan

| Kunci | Jenis | Aturan |
|---|---|---|
| `name` | string | Nama grup bawaan, sama dengan nama atribut |
| `from_attribute` | string | Harus atribut dengan `creates_variants = false` |
| `required` | bool | `true` → min 1, maks 1; `false` → min 0, maks 1 |
| `options` | larik string | Harus subset `values` atributnya; tanpa tambahan harga |
| `attach_to` | larik string | Nama item; boleh kosong (FR-009) |

### 1.7 Item

| Kunci | Jenis | Aturan |
|---|---|---|
| `name` | string | Persis dari sumber; (`category`, `name`) unik |
| `category` | string | Harus ada di `categories` |
| `price` | int | Harga jual sumber, ≥ 0 |
| `odoo_variant_count` | int | Kolom "# Product Variants" sumber |
| `variants` | larik | ≥ 1 entri, lihat di bawah |
| `variant_count_override` | string\|null | Alasan bila `len(variants) ≠ odoo_variant_count` (FR-023) |
| `photo` | string\|null | Nama berkas di `photos/`; JPEG/PNG ≤ 5 MB |
| `photo_issue` | enum\|null | `"truncated"` (sel berisi pesan batas Odoo) atau `"missing"`; wajib bila `photo = null` |
| `odoo_kit` | bool | Item kit/BoM Odoo; hanya untuk laporan |

Varian:

| Kunci | Jenis | Aturan |
|---|---|---|
| `name` | string | ≤ 100 karakter; unik di dalam item; `"Standard"` untuk item tanpa atribut |
| `price` | int | ≥ 0; sama dengan `price` item kecuali ditetapkan lain di tabel pemetaan |
| `values` | objek | `{atribut: nilai}`; setiap pasangan harus ada di `attributes` dengan `creates_variants = true`. Kosong untuk `"Standard"` |

### 1.8 Dikecualikan

| Kunci | Jenis | Aturan |
|---|---|---|
| `source` | enum | `products`, `attributes`, atau `attribute_values` |
| `name` | string | Nama baris sumber |
| `reason` | string | Alasan dalam Bahasa Indonesia |

## 2. Pemetaan ke tabel yang ada (per mode)

Semua baris dicap `data_mode` oleh `HasDataMode` di dalam `ModeGate::runAs($mode)`.

| Tabel | Isi | Penulis (jalur sah) | Kunci cocok (research D7) |
|---|---|---|---|
| `outlets` | "Warkop WJM 3", hanya bila mode tanpa outlet | Eloquent `Outlet` setelah `LicenseGate::canCreateOutlet()`; hook `created` → `OutletMenuService::materializeForOutlet()` | Nama (tanpa peka huruf besar/kecil) atau `--outlet` |
| `stations` | "Bar", "Dapur" di outlet tujuan | Eloquent `Station`, `outlet_id` di-set eksplisit (D6) | (`outlet_id`, `name`) |
| `categories` | 10 baris: `code` dari `freeCategoryCode()`, `name`, `display_order`, `station_id`, `is_active` | Eloquent `Category` | `name` |
| `products` | 1 baris per item: `home_outlet_id`, `category_id`, `code_prefix`, `product_segment`, `name`, `image_path`, `is_active=true` | Eloquent `Product`; kode lewat `ProductCodeGenerator`; hook `creating` mengisi `published_at` | (`name`, nama kategori), termasuk yang terhapus |
| `product_variants` | 1 baris per varian: `sku`, `variant_name`, `sell_price`, `cost_price=0`, `track_stock=false`, `is_active=true` | `$product->variants()->create()`; SKU dari `nextVariantSku()` | Mengikuti produknya |
| `price_history` | Baris `created` per varian | Hook → `PriceService::recordCreated()` (`user_id` null di CLI) | — |
| `variant_outlet_stock` | Saldo 0 per varian | Hook → `OutletStockSync::variantCreated()` | — |
| `modifier_groups` / `modifier_options` / `modifier_group_product` | Grup "Rasa" dan opsinya; tautan bila `attach_to` terisi | `ModifierGroupService::create()`, `setAttachments()` | Nama + himpunan opsi identik |
| `outlet_item_settings` | Item WJM dijual hanya di outlet tujuan; di DEMO, item contoh ditarik dari outlet tujuan | `OutletMenuService::setCarried($p, [$target])` dan `setCarried($p, [], [$target])` | — |
| `activity_logs` | 1 baris per mode yang `applied`: `action=catalog_seeded`, `entity_type=Outlet`, jumlah di `new_values` | `ActivityLogger::log(userId: null, …)` di transaksi yang sama | — |
| disk `public` `products/` | 1 berkas per produk berfoto | `ImageUploadService::storeLocalFile()` → `store()` | — |

Tidak disentuh: `stock_movements`, `material_*`, `product_variant_bom_lines`, `variant_outlet_prices`, `menu_changes`, `menu_releases`, `approvals`, `settings`.

## 3. Status satu jalan per mode

```text
            ┌─ validasi gagal / draf / outlet ambigu ─▶ refused   (tanpa perubahan)
mulai ──────┤
            └─ valid ─▶ menulis (1 transaksi) ─┬─ sukses, bukan pratinjau ─▶ applied   (+1 log aktivitas)
                                              ├─ sukses, pratinjau ────────▶ previewed (rollBack)
                                              └─ galat ────────────────────▶ failed    (rollBack + hapus foto)
```

Validasi katalog berlaku untuk semua mode: bila gagal, semua mode yang diminta `refused`. Resolusi outlet berlaku per mode. Saat LIVE dan DEMO diminta bersama, LIVE diproses lebih dulu; kegagalan satu mode tidak membatalkan mode lain yang sudah `applied`.

## 4. Laporan (keluaran service)

Per mode: `mode`, `status`, `outlet {code, name, created}`, dan `counts` berupa {`created`, `existing`} untuk `stations`, `categories`, `items`, `variants`, `option_groups`, `photos`. Lalu daftar:

- `excluded` (dari katalog)
- `without_photo` (+ jenis masalah)
- `zero_price`
- `renamed_groups`
- `kit_items`
- `withdrawn` (hanya DEMO)
- `deleted_by_owner` (item cocok yang terhapus)
- `problems` (validasi/outlet)

Ditambah baris `source_accounting`: produk = dibuat + sudah ada + dikecualikan = `source.rows.products`; kategori dan nilai atribut dengan cara yang sama.

## 5. Aturan pembangkitan `catalog.json` dari `catalog-mapping.md`

Alat dev `tools/build_catalog.py` (research D2) membaca tiga XLSX dan tabel pemetaan:

- **Status persetujuan**: baris `**Status**:` (`DRAF` → `draft`, `Disetujui` → `approved`), baris `**Disetujui oleh (peran)**:` dan `**Tanggal**:`.
- **Kategori item**: judul `### <Kategori> (<Bar|Dapur>)`, sekaligus stasiun kategori.
- **Varian** (kolom "Varian usulan"):
  - `Standard` → satu varian `Standard`.
  - `<Atribut>: <nilai>, <nilai>, …` → satu varian per nilai yang disebut (subset boleh); nama varian = nilai.
  - `<Atribut A> × <Atribut B>` → semua kombinasi (A berurutan lebih dulu); nama = label pendek tiap nilai (teks sebelum " (") digabung spasi. Teks setelah `→` hanya informasi; alat memastikan isinya sama dengan nama hasil hitungan.
- **Harga varian khusus**: bagian `## Harga varian khusus` (tabel Item | Varian | Harga). Tanpa baris → harga item.
- **Tautan grup pilihan**: bagian `## Grup pilihan` (tabel Grup | Item).
- **Penyimpangan jumlah varian**: token `≠Odoo: <alasan>` di kolom Catatan → `variant_count_override`.
- **Foto**: diambil dari kolom Image XLSX, didedup dengan SHA-256 (12 heksa pertama sebagai nama berkas). Sel berisi pesan batas Odoo → `photo_issue = "truncated"`; sel kosong → `"missing"`.
- **Dibuang**: kolom Responsible, stok, harga pokok, satuan, BoM, dan kolom internal lain. Berkas sumber tidak disalin.
