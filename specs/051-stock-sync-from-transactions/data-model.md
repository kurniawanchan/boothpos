# Data Model: Sinkronisasi Stok

Tanpa migrasi dan tanpa tabel baru. Satu-satunya data yang ditulis adalah baris `stock_movements` baru (lewat `StockService`) dan `product_variants.current_stock` (dipelihara `applyMovement`).

## Dibaca

- `product_variants`: `id`, `sku`, `variant_name`, `current_stock`, `product_id`, soft-delete (dibaca `withTrashed` untuk laporan).
- `stock_movements` (LIVE saja): `id`, `variant_id`, `type`, `qty_change`, `reference_type`, `reference_id`, `created_at`.
  - Acuan: `type='adjustment'` dan `reference_type` bukan `stock_sync`, id terbesar per varian.
  - Jendela: `id` antara acuan sebelumnya (manual sebelumnya, atau 0) dan acuan ini.
  - Pengurang: `sale`, `preorder_handover` (negatif) dan `return` (positif).
- `products`, `artists`: nama untuk laporan (`withTrashed`).
- `orders`/`preorders`: hanya lewat `StockMovementReferences` untuk nomor di lembar Sumber (tanpa data pelanggan).

## Ditulis (hanya saat `--apply` disetujui)

Satu `stock_movements` per varian yang berubah:

| Kolom | Nilai |
|---|---|
| `type` | `adjustment` |
| `qty_change` | selisih (≤ 0, tidak pernah nol) |
| `reference_type` | `stock_sync` |
| `reference_id` | id pergerakan acuan (penanda idempoten) |
| `reason` | ringkasan Indonesia: terjual X, serah-terima Y, retur Z; "kekurangan N unit" bila dibatasi ke 0 |
| `user_id` | pengguna dari `--by` |
| `data_mode` | `live` (distempel otomatis di dalam `runAs('live')`) |

Plus satu `activity_logs` `stock_adjusted` per baris (otomatis dari `applyMovement`).

## Baris rencana (dalam memori, juga lembar `Varian`)

`variant_id, sku, nama penjual/produk/varian, current_stock, baseline_id, baseline_at, previous_baseline_id/at, sold, returned, handed_over, taken (= sold + handed_over − returned), new_stock, delta, shortfall, status, note`.

### Status

| Status | Arti | Menulis? |
|---|---|---|
| `akan_diubah` | pengurang > 0, selisih < 0, tanpa kekurangan | ya |
| `perlu_perhatian` | kekurangan > 0 (dibatasi ke 0) atau catatan khusus (beberapa pembaruan manual) | ya bila selisih ≠ 0 |
| `tidak_ada_perubahan` | pengurang ≤ 0 | tidak |
| `sudah_disinkronkan` | sudah ada `stock_sync` untuk acuan ini | tidak |
| `dilewati` | tidak ada pembaruan manual, atau varian dihapus | tidak |

`plan_hash` = sha256 atas baris berdampak (status menulis) yang diurutkan menurut `variant_id`.
