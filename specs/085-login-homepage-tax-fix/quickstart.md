# Quickstart: memverifikasi 085

**Klasifikasi**: INTERNAL

## Pajak

1. Di Web: Pengaturan → Pajak, isi tarif `100`, simpan. Buka POS, tambahkan item berharga 17.000, nyalakan "tampilkan pajak". Harapan: pajak 17.000, total 34.000 di keranjang dan pembayaran.
2. Ulangi di tablet (APK terbaru; cek nomor build). Harapan: angka sama.
3. Bayar; periksa detail pesanan dan struk (layar dan cetak): pajak 17.000, tarif 100%.
4. Ubah tarif ke `2.5`; buat pesanan baru dengan dasar 1.001. Harapan: pajak 25 di Web dan Android; pesanan lama tetap 100%.
5. Matikan "tampilkan pajak": baris pajak hilang, total tetap memuat pajak.

Tes otomatis (kontainer, DB uji; flag `-e` WAJIB sebelum nama layanan):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=TaxParity
npm test -- taxParity
cd android-pos && ./gradlew testDebugUnitTest --tests '*TaxParity*'
```

## Beranda login

1. Buka tablet pada layar PIN (lanskap). Harapan: dua kolom; Info Outlet berfoto di kiri atas; kartu pengguna di kiri bawah; PIN di kanan.
2. Perhatikan jam selama 10 detik: berdetak tiap detik. Matikan Wi-Fi: chip berubah offline.
3. Tidak ada ikon roda gigi; tombol "Ganti outlet / terminal" jelas dan membuka layar penyiapan.
4. Pilih staf, ketik PIN 6 digit: masuk. PIN salah: galat hanya di kolom PIN.
5. Putar ke potret / layar sempit: satu kolom, semua tetap bisa dipakai.

Bukti perangkat dicatat di `evidence.md` setelah dijalankan; sebelum itu statusnya "belum diverifikasi di perangkat".
