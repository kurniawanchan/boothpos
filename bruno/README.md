# Koleksi Bruno — BoothPOS

Cara pakai:

1. Buka Bruno, "Open Collection", pilih folder `bruno/` ini.
2. Pilih environment "local" (pojok kanan atas) — isinya `base_url` ke
   `http://localhost:8000/api/v1`. Sesuaikan bila port berbeda.
3. Jalankan `php artisan db:seed` di proyek Laravel Anda dulu — koleksi ini
   memakai kredensial dummy dari seeder (`owner` / `kasir01`, password
   `password123` untuk semua akun).
4. Folder diberi nomor urut (01, 02, dst) dan tiap request juga diberi
   nomor urut — jalankan berurutan, atau pakai fitur "Run" Bruno untuk
   menjalankan satu folder sekaligus sebagai smoke test.

## Yang divalidasi koleksi ini

Bukan sekadar contoh request per endpoint — dirangkai jadi satu alur nyata
dari nol sampai laporan, termasuk skenario negatif kritis:

- Login tiga peran, termasuk password salah
- Artist → Kategori → Produk (memverifikasi `code_prefix` hasil generator)
  → Pelanggan, termasuk unggah gambar produk/kategori (Task 5)
- Otorisasi peran ditolak (kasir coba buat artist, coba void order, coba
  buka laporan profit, coba kelola vendor/impor/settings)
- Event → Sesi kasir → tolak buka sesi kedua saat masih ada yang terbuka
- Bukti pembayaran wajib untuk transaksi non-tunai; kanal pembayaran QR
  bisa diberi/diganti gambar (Task 4)
- Order: harga dari server bukan klien, idempotensi, stok tidak cukup,
  void mengembalikan stok
- Preorder: stok TIDAK berkurang saat dipesan, status tidak boleh lompat,
  tidak bisa diserahkan sebelum lunas, efek stok naik-turun di titik yang
  benar
- Rekap artist dan laporan profit, termasuk pembatasan akses dan daftar
  transaksi per baris order pada laporan penjualan
- Settings admin CRUD (F14) dan jalur resmi upgrade lisensi Pro→Master,
  diikuti pembacaan log aktivitas (F13.4) yang ditulis perubahan itu
- Impor/ekspor Excel master data (PRD 7.15): unduh template, pratinjau
  (`dry_run`), impor sungguhan, ekspor round-trip, dan satu baris yang
  sengaja tidak valid untuk memverifikasi pola semua-atau-tidak-sama-sekali
- Vendor, Bahan Baku, dan BOM (pasca-MVP): vendor → bahan → harga vendor →
  BOM varian → rincian modal (`cost-breakdown`), serta guard hapus 409
  saat vendor/bahan masih dipakai
- Refinements UI/UX 009 (pasca-MVP, 2026-09-04): laporan penjualan dengan
  `group_by=customer`, drilldown level-varian pada laporan stok per artist
  (`?artist_id=`), riwayat transaksi gabungan order+preorder per pelanggan
  (`GET /customers/{id}/transactions`), dan guard hapus 409 untuk
  event/pelanggan yang masih punya transaksi — plus jalur sukses (204)
  lewat event/pelanggan sekali-pakai yang dibuat lalu langsung dihapus
  agar tidak mengganggu `{{event_id}}`/`{{customer_id}}` yang dipakai
  folder-folder lain

## Yang TIDAK divalidasi koleksi ini

Ini pelengkap PHPUnit feature test yang sudah ada di `tests/Feature/` —
bukan pengganti. Assertion di sini sengaja ringan (status code, beberapa
field kunci); pemeriksaan detail bisnis logic yang menyeluruh tetap ada
di PHPUnit. Belum pernah dijalankan sungguhan di sini — sama seperti kode
PHP-nya, sandbox pembuat koleksi ini tidak punya server Laravel untuk
dites langsung.

Request upload bukti pembayaran butuh berkas `sample-proof.jpg` manual —
lihat `04-Payment/CATATAN.md`. Request unggah gambar produk/kategori/QR
kanal pembayaran butuh `sample-image.jpg` — lihat `02-MasterData/CATATAN.md`
dan `04-Payment/CATATAN.md`. Request impor Excel butuh dua workbook
`.xlsx` contoh disiapkan manual — lihat
`09-MasterData-ImportExport/CATATAN.md`.

## Struktur folder (per 2 September 2026)

`01-Auth` sampai `07-Report` adalah cakupan MVP asli. `08-Settings-ActivityLog`,
`09-MasterData-ImportExport`, dan `10-Vendor-Material-BOM` adalah
kapabilitas pasca-MVP yang ditambahkan pada 2026-09-01/02 — lihat catatan
bertanggal di `CLAUDE.md`/`README.md`/PRD §10.2 untuk konteksnya.

Request baru untuk fitur 009-ui-ux-refinements (2026-09-04) ditambahkan
sebagai nomor urut baru pada folder yang sudah ada, BUKAN folder baru,
karena semuanya adalah tambahan pada endpoint yang sudah ada
(`/reports/sales`, `/reports/stock-by-artist`) atau endpoint kecil satu
tujuan (`DELETE /events/{id}`, `DELETE /customers/{id}`,
`GET /customers/{id}/transactions`) yang paling pas ditaruh dekat request
sejenis yang sudah ada:
- `02-MasterData/10-11`: buat lalu hapus satu pelanggan sekali-pakai
  (`{{throwaway_customer_id}}`) untuk memverifikasi jalur hapus **sukses**
  (204) tanpa menyentuh `{{customer_id}}` bersama yang dipakai
  05-Order/06-Preorder/07-Report.
- `03-Event-Session/5-6`: pola yang sama untuk event
  (`{{throwaway_event_id}}`).
- `07-Report/7-9`: `group_by=customer`, drilldown stok per artist
  (`?artist_id=`), dan riwayat transaksi pelanggan — ditaruh di folder
  Report karena butuh `{{event_id}}`/`{{customer_id}}`/`{{artist_id}}`
  yang baru terisi penuh (order DAN preorder) setelah folder 05-06
  selesai jalan.
- `07-Report/10-11`: guard hapus **gagal** (409) untuk event dan
  pelanggan bersama — sengaja ditaruh PALING AKHIR di seluruh koleksi
  (bukan di folder asalnya) karena baru pada titik ini `{{event_id}}`/
  `{{customer_id}}` sudah pasti punya order/preorder untuk memicu 409-nya.
  Jangan pindahkan ke lebih awal — akan false-negative (200/204) kalau
  dijalankan sebelum 05-Order/06-Preorder membuat transaksinya.
