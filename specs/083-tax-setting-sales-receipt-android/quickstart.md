# Quickstart: memverifikasi Pengaturan Pajak (083)

Hanya setelah implementasi selesai. Jalankan backend lewat container dengan penimpaan aman (CLAUDE.md):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
  php artisan test --filter=TaxSettingTest -e CACHE_STORE=array -e SESSION_DRIVER=array
npm test
```

## Skenario browser (login `owner`, outlet apa pun)

1. Pengaturan → bagian **Pajak**: isi tarif 10, label PPN, tampilkan aktif, simpan. Muat ulang: nilai bertahan.
2. POS: tambah item Rp 7.000 → baris "PPN (10%) Rp 700", total Rp 7.700.
3. Buat satu pesanan terbayar (butuh shift terbuka). Buka di **Penjualan**: detail memuat Subtotal, Diskon, PPN (10%) Rp 700, Total Rp 7.700; struk memuat baris yang sama.
4. Kembali ke Pajak, matikan tampilan, simpan. POS, pembayaran, detail Penjualan, dan struk TIDAK menampilkan baris pajak; total tetap Rp 7.700.
5. Ubah tarif ke 11. Pesanan langkah 3 tetap menampilkan 10% dan Rp 700 (saat tampilan aktif); pesanan baru memakai 11%.
6. Isi tarif 101, -1, `abc`, label 31 karakter: ditolak dengan pesan, nilai lama utuh.
7. Login `kasir01`: bagian Pajak tidak tampil di Pengaturan.
8. Konsol browser bersih pada semua layar di atas.

## Android (butuh tablet; belum bisa diverifikasi tanpa `adb`)

1. Atur pajak seperti langkah 1 di web, tarik-untuk-segarkan di tablet.
2. Editor menampilkan baris pajak dan total sama dengan web; struk cetak/layar sama.
3. Matikan tampilan di web, segarkan tablet: baris hilang, total sama.
4. Mode pesawat lalu buat order: memakai nilai terakhir; server tetap penentu angka akhir.
5. Catatan: tablet dengan APK lama tetap mencetak baris pajak (contracts/tax-setting-api.md, Kompatibilitas).
