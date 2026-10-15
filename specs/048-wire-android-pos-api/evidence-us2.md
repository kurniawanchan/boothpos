**Classification**: INTERNAL

# Bukti US2 — satu order sampai lunas dari server (2026-10-15, sebagian)

Lingkungan: backend dev Docker (app :8010, MySQL :3308), emulator `wjm_tablet` (cold restart karena koneksi aplikasi
ke 10.0.2.2 timeout padahal shell bisa terhubung — lihat catatan), APK debug, terminal T01 (perangkat 1), shift Rina.

## Yang diperiksa
| Langkah | Log `PosApi` | Basis data (`orders`, `order_items`, `payments`, `kitchen_tickets`) |
|---|---|---|
| Buka counter | `POST /tabs → 201` | order 2012, antrean **A-001** dari server |
| Tambah Kopi Tubruk (tanpa modifier) | `POST /tabs/2012/items → 201` | 1 baris, total Rp 8.000 dari server |
| Kirim ke dapur | `POST /tabs/2012/send → 200` | 1 tiket dapur |
| Bayar tunai Rp 8.000 (uang pas) | `POST /tabs/2012/payments → 200` | `completed`, struk **T01-000001**, pajak 0, kembalian 0, 1 pembayaran tunai 8.000 |
| Buka counter, tambah, kirim, bayar; **putus jaringan saat bayar**, nyalakan lagi, bayar ulang | `POST /tabs/2013/payments → 200` (setelah pulih) | `completed`, struk **T01-000002**, **tepat 1** pembayaran (ref `submission_ref` tercatat) |
| Daftar Order → Selesai | `GET /orders → 200` | 2 penjualan dari server ditampilkan |

Galat selama putus jaringan (log `gagal (ConnectException)`) tercatat untuk polling denah dan ketersediaan. Percobaan
bayar pertama selama putus tidak tertangkap log terpisah (buffer log dibersihkan sebelum percobaan kedua); basis data
menunjukkan hanya satu pembayaran, jadi tidak ada ganda.

Pemeriksaan galat fatal: `AndroidRuntime:E` untuk `com.warkop.pos` = **0** baris pada run ini.

## Yang BELUM diperiksa (T040 tetap terbuka)
- Meja dengan item ber-modifier wajib (mis. Es Kopi Susu Aren: level gula + es) dan pembayaran lebih dari total (kembalian).
- Perbandingan total tablet vs back office untuk 50 order (SC-003).
- Pengiriman tab meja (bukan counter) dan denah yang berubah dari terminal lain.
- Pengukuran siklus < 90 detik (SC-001).

## Celah yang diketahui (dicatat, bukan disembunyikan)
- **Daftar Selesai** menampilkan nomor order (`TRX-…`), bukan nomor struk terminal (`T01-…`): endpoint `GET /orders` belum memuat `receipt_no`. Perlu perubahan OrderResource + OpenAPI.
- **Cetak ulang** dan **Refund** di daftar Selesai masih tombol lokal yang memberi pesan "belum tersambung" (cerita 4/5).
- **Void, batal, diskon, bagi bill, EDC, QRIS, verifikasi QRIS**: belum tersambung ke server; aksi-aksi ini menampilkan pesan dan tidak mengubah data (sengaja, agar tidak ada angka lokal yang bisa salah).
- **Kas masuk/keluar dan tutup shift**: belum tersambung (cerita 3). Pusat shift masih memakai angka lokal dari transaksi yang diterima aplikasi.
- **Peran waiter**: membuka tab memerlukan shift terbuka milik sesi; waiter tanpa shift akan diberi pesan. Belum diuji.
- **Cetak struk/KOT**: belum ada printer; status menampilkan "belum tersedia" (tidak lagi "tercetak").
- **Koneksi emulator**: setelah sesi lama, koneksi aplikasi ke 10.0.2.2 timeout (ECONNABORTED) sementara `adb shell` bisa terhubung; cold restart emulator memperbaikinya. Belum dipastikan penyebab akarnya.
