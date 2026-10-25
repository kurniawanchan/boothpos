# Quickstart: verifikasi 072

1. Backend: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test --filter=SettingsFeatures -e CACHE_STORE=array -e SESSION_DRIVER=array`
2. Frontend: `npm test` (Vitest).
3. Browser (`http://localhost:8010`), login `owner`:
   - Pengaturan > Identitas toko: isi nama + unggah logo → sidebar, judul tab, ikon tab berubah tanpa muat ulang.
   - Pengaturan > Umum: ubah Application name → judul tab ikut berubah; samakan dengan nama toko → nama muncul sekali.
   - Kosongkan nama toko → sidebar & judul kembali ke nama aplikasi; hapus logo → kotak huruf.
   - Login sebagai `kasir01`: sidebar/judul tampil sama (bukan 403).
   - POS di jendela 1366×768 dengan katalog besar: halaman tidak menggulir, Total/Bayar terlihat, daftar produk menggulir, pencarian tetap di atas.
   - Periksa layar lain (Dashboard, Penjualan, Produk, Pengaturan) masih menggulir normal; konsol tanpa galat.
