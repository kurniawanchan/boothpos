# BoothPOS — Backend Laravel

BoothPOS — sistem POS event-based multi-artist untuk toko merchandise, dijual sebagai lisensi sekali bayar dengan instalasi lokal per toko.

Akumulasi Increment 1–3+ (Auth, Artist, Category, Product/Variant, Customer,
Stock, Event/Session, Payment, Order, Preorder/Shipment, Report/Settlement,
Backup). Cakupan lengkap ada di laporan sesi terpisah yang menyertai zip ini.

## Lisensi Pro vs Master (multi-artist toggle)

Satu setting (`multi_artist_enabled`, tabel `settings`) membedakan dua
tingkat harga tanpa build kode terpisah:

- **Pro** — `false`. Hanya 1 artist aktif diizinkan (mewakili toko itu
  sendiri). `POST /artists` yang kedua ditolak 403.
- **Master** — `true`. Tidak ada batas jumlah artist.

Cek status: `GET /settings/features`. Ubah lewat `Setting::updateOrCreate`
(belum ada endpoint admin untuk ini — lihat Remaining Issues).

Logika ada di satu tempat: `app/Support/LicenseGate.php`. Penegakan
sesungguhnya di `ArtistPolicy::create`, bukan di controller atau frontend.

**Dua bug ditemukan dan diperbaiki saat menulis test fitur ini** (bukan
lewat review kode statis):
1. `(bool) Setting::get(...)` salah untuk string `"false"` — di PHP itu
   truthy. Diganti `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.
2. `Setting::get()` memakai `Cache::rememberForever` tanpa invalidasi —
   upgrade Pro ke Master tidak akan langsung berlaku tanpa restart
   aplikasi. Ditambal lewat model event `saved`/`deleted` di `Setting`.

## Data dummy untuk testing manual

```bash
php artisan db:seed
```

Membuat 5 user (owner, admin, kasir01, kasir02, inventory — password
`password123` untuk semua), 2 kanal pembayaran (BCA, Mandiri), dan
pengaturan nama toko. **Hanya untuk lokal/dev** — kredensial ini ada di
kode sumber, jangan dipakai di lingkungan yang bisa diakses orang lain.

## Koleksi Bruno

Ada di folder `bruno/` — bukan cuma daftar endpoint, tapi satu alur
end-to-end dari login sampai laporan, termasuk skenario negatif (lihat
`bruno/README.md`). Belum pernah dijalankan sungguhan, sama seperti kode
PHP-nya.

## Prasyarat instalasi (belum pernah dijalankan di sandbox pembuat kode ini)

```bash
composer create-project laravel/laravel boothpos
cd boothpos
php artisan install:api        # memasang Sanctum + routes/api.php
composer require maatwebsite/excel
```

Set `APP_NAME=BoothPOS` di `.env` — ini nama produk, bukan nama toko.
Nama toko (dicetak di struk) diatur terpisah lewat tabel `settings`
(`store_name`), diisi masing-masing pembeli BoothPOS sesuai bisnisnya
sendiri saat instalasi — lihat `DatabaseSeeder`.

Salin seluruh folder `app/`, `database/`, `routes/`, `tests/`, `config/backup.php`
dari paket ini ke proyek, timpa `routes/api.php` bawaan.

Tambahkan ke `.env`:
```
BACKUP_EXTERNAL_PATH=/path/ke/flashdisk-atau-hdd
```

## Menjalankan

```bash
php artisan migrate
php artisan test
```

Saya tidak dapat menjalankan ini di sandbox (tidak ada akses packagist.org,
tidak ada PHP terpasang). **Kode ini belum pernah tereksekusi.** Jalankan
`php artisan test` sebagai langkah pertama begitu Anda menyalinnya, dan
laporkan kegagalan — kemungkinan besar karena asumsi versi Laravel/Sanctum,
bukan logika bisnis (yang sudah ditinjau manual baris per baris).

## Urutan migration penting

File migration diberi prefix tanggal untuk memastikan urutan foreign key
benar. Jangan mengubah urutan nama file. Satu migration (`orders_and_payments`)
sengaja membuat kolom `payments.preorder_id` TANPA constrained(), karena
tabel `preorders` belum ada di titik itu — constraint-nya ditambahkan di
migration `preorders_tables` lewat `Schema::table('payments', ...)`.

## Gap yang diketahui — lihat laporan sesi untuk daftar lengkap

Ringkasan singkat, detail dan status ada di `LAPORAN-SESI-3.md`:

1. Frontend Vue (POS screen, webcam, katalog cetak) — di luar cakupan sesi ini.
2. `maatwebsite/excel` adalah ASUMSI pustaka, belum terverifikasi jalan.
3. `BackupPos` command belum pernah dieksekusi — WBS 9.2 (uji pemulihan)
   WAJIB dilakukan manual sebelum event pertama.
4. Activity log (F13.4) belum diimplementasikan.
5. Guard "hapus artist/kategori dengan produk aktif" sudah aktif sekarang
   (tabel products sudah ada), tapi belum ada test baru yang memverifikasi
   ulang skenario ini dengan produk sungguhan — test lama di Increment 1-2
   ditulis sebelum tabel products ada.
