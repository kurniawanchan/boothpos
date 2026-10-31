**Klasifikasi**: INTERNAL

# Quickstart: verifikasi navigasi detail pre-order

## Tes otomatis

```bash
# Backend (host/native; di container WAJIB pakai -e, lihat CLAUDE.md)
php artisan test --filter=PreorderNeighborsTest
php artisan test --filter=PreorderSearchTest        # urutan daftar + pengikat tidak merusak filter/sort lama
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderNeighborsTest

# Frontend
npm test -- PreordersView localeKeys
```

## Verifikasi browser (wajib, Prinsip II)

Data: `php artisan db:seed --class=SakanaFridgeDemoSeeder` (mode DEMO) — atau buat ≥ 30 pre-order agar daftar punya > 1 halaman (`per_page` 25).

1. Buka `/preorders`, klik nomor baris ke-2. Header menampilkan `‹ <nomor baris 1>` dan `<nomor baris 3> ›`.
2. Klik **Berikutnya** → detail baris 3 terbuka, panel tidak tertutup, baris 3 tersorot di daftar, label tombol ikut berganti.
3. Buka baris ke-25 (terakhir halaman 1) → **Berikutnya** menampilkan nomor baris pertama halaman 2; klik → terbuka.
4. Baris pertama halaman 1 → **Sebelumnya** nonaktif dengan tooltip "pre-order pertama".
5. Filter status "Deposit paid" + urutkan kolom Pelanggan → tekan **Berikutnya** berulang dari baris 1 sampai akhir: urutan sama persis dengan daftar, tak ada yang terlewat/berulang.
6. Urutkan per Status (banyak nilai sama) → maju 3× lalu mundur 3× kembali ke pre-order awal.
7. Dari detail, ubah status sehingga pre-order keluar dari filter → tombol menjadi nonaktif "tidak ada di daftar saat ini".
8. Klik **Berikutnya** cepat 4× → selama tetangga dimuat tombol nonaktif, jadi klik tambahan diabaikan; panel, judul, label tombol, dan baris tersorot selalu menunjuk pre-order yang SAMA (tidak pernah tertimpa respons lama). (Diubah saat implementasi: menonaktifkan tombol selama memuat lebih jujur daripada menebak tetangga dari pre-order yang belum dimuat.)
9. Buka form pengiriman di panel → kedua tombol nonaktif; tutup form → aktif lagi.
10. Fokus di luar kolom isian, tekan → / ← → berpindah; ketik di kolom isian mana pun di panel → panah tidak berpindah pre-order.
11. Buka pre-order dari Pelanggan → Riwayat transaksi (`?preorder_id=`) → tombol bekerja sesuai daftar saat ini, atau nonaktif bila pre-order itu tidak lolos filter.
12. Ganti bahasa ke Indonesia/Inggris → label/tooltip ikut. Konsol browser bersih (tanpa error/warning baru).
