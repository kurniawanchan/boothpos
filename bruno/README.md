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
  → Pelanggan
- Otorisasi peran ditolak (kasir coba buat artist, coba void order, coba
  buka laporan profit)
- Event → Sesi kasir → tolak buka sesi kedua saat masih ada yang terbuka
- Bukti pembayaran wajib untuk transaksi non-tunai
- Order: harga dari server bukan klien, idempotensi, stok tidak cukup,
  void mengembalikan stok
- Preorder: stok TIDAK berkurang saat dipesan, status tidak boleh lompat,
  tidak bisa diserahkan sebelum lunas, efek stok naik-turun di titik yang
  benar
- Rekap artist dan laporan profit, termasuk pembatasan akses

## Yang TIDAK divalidasi koleksi ini

Ini pelengkap PHPUnit feature test yang sudah ada di `tests/Feature/` —
bukan pengganti. Assertion di sini sengaja ringan (status code, beberapa
field kunci); pemeriksaan detail bisnis logic yang menyeluruh tetap ada
di PHPUnit. Belum pernah dijalankan sungguhan di sini — sama seperti kode
PHP-nya, sandbox pembuat koleksi ini tidak punya server Laravel untuk
dites langsung.

Request upload bukti pembayaran butuh berkas `sample-proof.jpg` manual —
lihat `04-Payment/CATATAN.md`.
