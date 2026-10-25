# Data Model: 070

**Klasifikasi**: INTERNAL

**Tidak ada perubahan skema.** Fitur memakai tabel yang sudah ada.

| Entitas | Tabel | Dipakai untuk |
|---|---|---|
| Item menu | `products` (+`variants` di `product_variants`) | himpunan tersaring (tab, q, kategori, status) |
| Outlet pembawa | `outlet_item_settings` (`is_carried`; tanpa baris = dibawa) | filter Outlet, bagian Ketersediaan saat buat |
| Harga per outlet | `variant_outlet_prices` (`variant_id`, `outlet_id`, `price`) + `price_histories` | `variants.*.outlet_prices` saat buat |
| Tanda 86 | `item_availability_marks` (`outlet_id` NULL = global) | hitungan "86 hari ini" per outlet |

## Aturan

- **Himpunan tersaring** = produk yang lolos `tab`, `q`, `category_id[]` (+anak kategori), `status[]`, dan `outlet_id[]` (dipotong `permittedIds()`; item dibawa bila ADA outlet terpilih yang tidak memiliki baris `is_carried = 0`). Daftar, `counts.menu/retail`, dan angka kepala semuanya dihitung darinya.
- **`outlet_prices` (buat)**: tiap `outlet_id` harus (a) diizinkan bagi pengguna, (b) termasuk `outlet_ids` yang dikirim (atau semua outlet bila `outlet_ids` tidak dikirim), (c) unik per varian; `price` numerik ≥ 0 (maks sama dengan PUT outlet-prices). Harga yang sama dengan harga dasar tidak membuat baris (`writeOutletPrice` mengabaikan perubahan nol).
- **`outlet_ids` (buat)**: bila dikirim wajib berisi minimal satu outlet yang diizinkan.
- **Transisi/status**: tak ada perubahan; item baru tetap draft bila rilis menu aktif.
- **Mode data**: seluruh model sudah memakai `HasDataMode`; tidak ada model baru.
