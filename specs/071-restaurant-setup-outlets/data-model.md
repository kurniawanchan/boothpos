# Data Model: 071

**Klasifikasi**: INTERNAL

**Tidak ada perubahan skema.** `dining_tables.outlet_id` dan `stations.outlet_id` sudah NOT NULL dengan indeks unik per outlet.

| Entitas | Tabel | Aturan baru |
|---|---|---|
| Meja | `dining_tables` | `outlet_id` boleh diubah lewat `PUT` oleh pengguna yang berhak atas outlet tujuan; ditolak (409) bila ada order `open` pada meja |
| Stasiun | `stations` | `outlet_id` boleh diubah; ditolak (409) bila `categories.station_id`/`products.station_id` menunjuknya atau ada tiket dapur dengan item belum selesai |
| Log aktivitas | `activity_logs` | aksi `moved`, entitas `DiningTable`/`Station`, nilai lama/baru `outlet_id`, ditulis dalam transaksi yang sama |

## Aturan

- Outlet tujuan harus termasuk `OutletContext::permittedIds()`; selain itu 403 `outlet_forbidden`.
- Nama meja/stasiun harus unik di outlet tujuan (indeks yang sudah ada; diperiksa lebih dulu menjadi 422).
- Pemindahan tidak menyentuh baris lain: order, tiket dapur, kategori, produk tetap pada outlet masing-masing.
- Mengirim `outlet_id` sama dengan outlet saat ini bukan pemindahan (tanpa pemeriksaan guard, tanpa log).
