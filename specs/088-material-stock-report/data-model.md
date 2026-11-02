# Data Model: Laporan Bahan (088)

**Klasifikasi**: INTERNAL

Fitur ini **tidak menambah tabel atau kolom**. Semua entitas di bawah adalah bentuk TURUNAN yang dihitung `MaterialReport` dari tabel yang sudah ada (research D1). Semua kueri dibatasi `OutletFilter` (outlet yang berlaku) dan mode data aktif (`HasDataMode` pada `materials`, `orders`; filter eksplisit pada kueri mentah).

## Sumber (sudah ada)

| Tabel | Dipakai untuk |
|---|---|
| `materials` (`kind`, `unit`, `is_active`, `standard_cost`) | identitas bahan, jenis, aktif |
| `material_outlet_stock` (`material_id`, `outlet_id`, `current_stock`) | saldo saat ini per outlet |
| `material_stock_movements` (`type`, `qty_change`, `outlet_id`, `reference_type/id`, `created_at`) | laju, pemakaian periode, tren, rincian menu |
| `orders` (`outlet_id`, `business_date`, `status`) | `hari_jual` per outlet (D2) |
| `order_items` (`product_id`/`variant_id`, `modifiers` snapshot) | rincian "menu yang memakai" (rujukan `order_item`) |
| `modifier_option_materials`, `modifier_options`, `modifier_groups` + tautan produk/kategori/pengecualian | pilihan & menu yang terancam (D4) |
| `vendor_material_prices` (`is_preferred`, `price`, `vendor_id`) | biaya satuan, vendor saran |
| `recipe_lines` (resep aktif), `product_variant_bom_lines` | penanda `usage_untracked` (D3) |

## Bentuk turunan

### MaterialPosition (satu per bahan aktif per outlet yang terlihat)

| Field | Tipe | Aturan |
|---|---|---|
| material_id, code, name, unit, kind | — | dari `materials` |
| outlet_id, outlet_code | int, string | outlet saldo |
| on_hand | decimal(3) string | `material_outlet_stock.current_stock`; 0 bila tak ada baris tetapi ada pemakaian di jendela |
| daily_usage | decimal(3) string \| null | D2; null bila `hari_jual = 0` atau pemakaian bersih ≤ 0 |
| days_left | decimal(1) \| null | `on_hand / daily_usage`; 0 bila on_hand ≤ 0; null bila daily_usage null |
| status | enum `negative\|out\|low\|ok\|unused` | D3 |
| usage_untracked | bool | D3 |
| unit_cost | money string \| null | D5 |
| stock_value | money string \| null | on_hand × unit_cost (minus → negatif apa adanya) |
| at_risk_options | list `{option_id, option, group, portions}` | D4; hanya untuk status negative/out/low |
| at_risk_products | list `{product_id, name}` | D4 |
| suggestion | `{qty, vendor_id, vendor, unit_price, cost}` \| null | D8; hanya bila qty > 0 |

Urutan bawaan: negative → out → low (hari tersisa naik) → ok → unused; lalu nama.

Baris yang tampil: bahan aktif yang punya baris saldo DI outlet itu ATAU punya pergerakan di jendela acuan outlet itu. Bahan tanpa keduanya di outlet itu tidak tampil untuk outlet itu.

### MaterialUsagePeriod (satu per bahan per outlet di periode tanggal bisnis)

| Field | Aturan |
|---|---|
| sold_net | −(Σ sale + Σ return) — jumlah yang benar-benar terpakai karena penjualan |
| wasted | −Σ waste |
| adjusted_in / adjusted_out | Σ adjustment positif / −Σ adjustment negatif |
| purchased | Σ purchase |
| transferred_in / transferred_out / transfer_cancelled | Σ per jenis |
| *_value | jumlah × unit_cost (D5); null bila tanpa biaya |

Plus agregat periode: total per jenis (qty tidak dijumlah lintas bahan karena satuan berbeda — hanya rupiah yang dijumlah), tren harian `{date, sold_value, wasted_value}` per tanggal bisnis, top 10 menurut `sold_net_value` dan `wasted_value`.

### MaterialUsageByMenu (rincian satu bahan, periode)

`{product_id, product, options[] , qty}` dari pergerakan `sale`/`return` dengan `reference_type = order_item` → `order_items` → produk; pilihan = id pilihan di snapshot baris ∩ pilihan yang memetakan bahan ini.

### DashboardMaterialStats

`{counts: {negative, out, low, at_risk_products}, stock_value?, wasted_value?, low_stock_products, computed_at}` — dari MaterialPosition (bawaan jendela 7, N=3) + `LowStockHistory::current()` yang sudah ada untuk produk. `stock_value`/`wasted_value` hanya untuk pengguna dengan menu `reports`.

### PurchaseDraftRequest / Result

- Masukan: `lines[{material_id, qty>0}]` (≤ 200 baris).
- Keluaran: `created[{purchase_order_id, po_number, vendor_id, vendor, line_count, total}]`, `refused[{material_id, reason: no_vendor|inactive_material|not_visible}]`.
- Outlet: `OutletContext::singleId()` (bukan dari body). PO dibuat lewat `PurchaseOrderService::create` berstatus `draft`.

## Aturan validasi

- `window_days` 1–90 (bawaan 7); `risk_days` 1–60 (bawaan 3); `target_days` 1–90 (bawaan 7); `date_from ≤ date_to`, rentang ≤ 366 hari.
- `status[]` ⊆ {negative, out, low, ok, unused}; `kind[]` ⊆ {ingredient, semi_finished, packaging}.
- Draf PO: `qty` 0.001–999999; bahan harus aktif dan terlihat; tanpa outlet tunggal → 422.
