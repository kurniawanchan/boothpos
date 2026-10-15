# Quickstart: memverifikasi fitur 048 secara nyata

**Classification**: INTERNAL. Syarat: Docker Desktop, Android SDK (mesin ini punya), emulator `wjm_tablet`.

## 1. Jalankan backend dev (jangan menyentuh `boothpos-*`)
```bash
docker compose up -d mysql app            # proyek warkop_wjm; MySQL host 3308, app 8010
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan db:seed --class=SakanaFridgeDemoSeeder   # data demo
# seeder peran/staf untuk outlet "Warkop WJM 3" (dibuat di S1): Rina, Dewi (Kasir), Andi (Waiter), Sari (Supervisor), Budi (Outlet Manager)
```
Uji backend (aman, DB `warkop_wjm_test`):
```bash
php artisan test --filter='PinLoginTest|IdempotencyKeyTest|<uji baru irisan>'
```
> JANGAN menjalankan `php artisan test` di dalam container tanpa `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` (aturan CLAUDE.md).

## 2. Emulator → server host
Emulator mengakses host lewat `http://10.0.2.2:8010` (bukan `localhost`). Masukkan alamat itu di layar penyiapan terminal.

## 3. Skrip alur penerimaan (tiap irisan memakai bagiannya)
**S1** — penyiapan: login `admin` → pilih outlet → daftarkan T01. PIN: pilih Rina, masuk. Verifikasi layar PIN memuat 5 staf dari server (ubah nama staf di back office → muncul setelah muat ulang). Menu: harga/habis sama dengan back office. Order: meja 3, 2 tamu, tambah Es Kopi Susu Aren (modifier wajib) + 2 item, kirim dapur, bayar tunai Rp 100.000. Cek di back office: order, baris, tiket dapur, pembayaran, stok; total identik sampai rupiah (SC-003). Matikan Wi-Fi emulator tepat saat bayar lalu nyalakan: tepat satu pembayaran (SC-004).
**S2** — buka shift modal per pecahan; 2 penjualan tunai + 1 kas keluar; X-report; tutup shift hitung buta; "seharusnya" = hitung manual (SC-007).
**S3** — void item terkirim (persetujuan Sari); PIN Rina sendiri sebagai penyetuju ditolak; QRIS tertunda memblokir EDC dan tutup shift; EDC ganda dalam 2 menit meminta konfirmasi.
**S4** — pajak/diskon/bagi bill/cetak ulang; heartbeat tampil di layar Perangkat.

## 4. Bukti yang wajib dikumpulkan sebelum menyatakan selesai
- Keluaran `php artisan test` (irisan + suite terkait) pada MySQL.
- Tangkapan layar emulator tiap layar utama dengan data server + `adb logcat -d -s AndroidRuntime:E` kosong.
- Daftar rute vs `docs/openapi-pos-mvp.yaml` lulus (SC-010).
