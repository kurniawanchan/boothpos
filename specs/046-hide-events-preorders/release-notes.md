**Classification**: INTERNAL

# Catatan rilis — fitur 046: Event dan Pre-order dihapus

## Untuk pengguna
- Menu **Events** dan **Pre-orders** hilang (semua peran). Alamat lama (`/events`, `/preorders`, termasuk `?event_id=`/`?preorder_id=`) mengalihkan ke Dashboard.
- **Shift dibuka tanpa event** (kas awal + outlet). Penjualan, tab, struk, dan draf tidak lagi menampilkan event.
- **Laporan** (Rekap Outlet, Modal & Untung, Modal Outlet), Dashboard (bagian "Rincian periode") dan halaman **Penjualan** memakai **rentang tanggal bisnis**, bawaan **hari ini**; hanya dari penjualan selesai. Tidak ada tab/angka pre-order, biaya event, atau laba bersih per event.
- Riwayat pelanggan hanya memuat penjualan. Form Produk tak lagi punya "Produk pre-order"/perkiraan tiba.

## Untuk pengelola / pemasang
- Dua migrasi: `2026_12_10_000001` (`event_id` nullable pada `cashier_sessions`, `orders`, `outlet_settlements`; FK dipertahankan) dan `2026_12_10_000002` (cabut kunci menu `events`/`preorders` dari peran tersimpan; idempoten).
- **Tidak ada tabel dihapus dan tidak ada baris dihapus/dikonversi**: `events`, `preorders`, `preorder_items`, `preorder_notifications`, `shipments`, `payments.preorder_id`, dan baris stok lama tetap sebagai riwayat diam.
- API: semua rute `/events*`, `/preorders*`, `/shipments*`, `/reports/preorders`, ekspor `preorder` → **404**. `POST /sessions` mengabaikan `event_id`. Laporan menerima `date_from`/`date_to` (bawaan hari bisnis berjalan, rentang terbalik 422). Rekap tidak lagi menulis `outlet_settlements`.
- Excel master data: kolom `is_preorder`/`preorder_eta` dihapus dari sheet `products`; berkas lama yang masih membawanya tetap bisa diimpor.
- Dependensi `jszip` dihapus; bundel utama 240,38 kB (sebelumnya 240,97 kB).
- **Rollback:** pulihkan dari `app:backup` sebelum pemutakhiran. `down()` migrasi nullable berhenti bila sudah ada baris tanpa event; kunci menu yang dicabut tidak dipulihkan otomatis.

## Bukti
- Gladi pada salinan: total identik, rekonsiliasi bersih (`evidence-upgrade.md`). Dev DB: total = `baseline.md`.
- Backend penuh dan frontend penuh hijau (lihat `evidence-final.md`). Browser: tangkapan layar di `evidence/`.

## Keputusan dan catatan terbuka
- Bagian Dashboard "Per event" **tidak dihapus**, melainkan diubah menjadi "Rincian periode" (panel yang sama, berskala tanggal) agar pintasan, grafik kategori/outlet, statistik pelanggan, dan stok menipis tidak ikut hilang.
- Halaman Penjualan: tanpa tanggal server memuat semua; SPA mengirim hari ini secara bawaan (mengosongkan tanggal = semua).
- Ditemukan dan diperbaiki: `BulkPriceOutletsTest` memakai `now()->toDateString()` (UTC) alih-alih `BusinessDay::today()` → gagal antara 00:00–07:00 WIB.
- Masih terbuka (bukan 046): `app:backup` memuat kolom turunan `orders.open_table_key` sehingga dump tidak bisa dipulihkan ke MySQL 8 (dari 045); gladi memakai `mysqldump --complete-insert`.
- Penelusuran manual semua peran/bahasa di browser belum penuh: yang diperiksa — login, Dashboard, Laporan, Sesi Kasir, pengalihan `/preorders?…`, tanpa menu Events/Pre-orders (owner, bahasa Inggris).
