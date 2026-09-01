**Klasifikasi: INTERNAL**

# BoothPOS — Runbook

Dokumen operasional: urutan perintah yang benar-benar dipakai untuk
menjalankan, menguji, dan mencadangkan BoothPOS (backend Laravel +
frontend Vue) di mesin development. Semua perintah di bawah sudah
diverifikasi jalan sungguhan — backend pada sesi 2026-08-31, frontend
ditambahkan pada sesi 2026-09-01 (lihat `README.md` bagian "Status
eksekusi" untuk kronologi lengkap dan bug yang ditemukan). Untuk
PENJELASAN kenapa sesuatu didesain begitu, baca `README.md` — dokumen ini
murni "langkah apa, urutan apa".

## 1. Prasyarat

- PHP 8.3 atau 8.4 (`php -v`)
- Composer 2.x (`composer -V`)
- Node.js 20+ dan npm (`node -v`, `npm -v`) — dibutuhkan untuk build
  frontend Vue. Diverifikasi jalan dengan Node v22.22.3 / npm 11.18.0.
- MySQL 8 yang bisa diakses dari host — di mesin dev ini disediakan lewat
  container Docker `laradock-mysql-1` (proyek `talenta-docker-dev`/laradock),
  ter-expose di `127.0.0.1:3306`. **Jangan pasang MySQL/mysql-client di
  Mac lokal** — pakai container yang sudah ada.
- Akses `docker` di host (hanya dibutuhkan untuk task administratif DB
  sesekali, lihat §7 — aplikasi sendiri terhubung lewat PDO biasa, tidak
  butuh docker untuk jalan sehari-hari).

## 2. Setup pertama kali

Hanya perlu sekali per mesin/clone baru.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

**PENTING** — `.env.example` bawaan skeleton Laravel default ke
`DB_CONNECTION=sqlite`. BoothPOS TIDAK BISA pakai SQLite: dua migration
(`orders_and_payments`, `preorders`) memakai
`DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')` yang
sintaksnya MySQL-only dan akan gagal total di SQLite. Timpa bagian
`DB_*` di `.env` menjadi:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=boothpos
DB_USERNAME=<user_aplikasi_anda>
DB_PASSWORD=<password_aplikasi_anda>

BACKUP_EXTERNAL_PATH=/path/ke/flashdisk-atau-hdd   # lokal: folder biasa juga cukup untuk dev
```

Kalau `boothpos`/`<user_aplikasi_anda>` belum ada di container MySQL,
buat sekali (lewat `docker exec -it laradock-mysql-1 mysql -uroot -p...`
atau client MySQL apa pun yang sudah Anda punya) — jangan pakai user
`root` di `.env` aplikasi, buat user khusus yang haknya dibatasi ke
database `boothpos`/`boothpos_test` saja.

Lalu:

```bash
php artisan migrate
php artisan db:seed
composer require maatwebsite/excel   # sekali saja kalau vendor/ belum ada paket ini
```

`db:seed` membuat 5 user dev (lihat §4), 2 kanal pembayaran (BCA,
Mandiri), dan pengaturan toko dummy — **hanya untuk lokal**, jangan
dipakai di instalasi yang bisa diakses orang lain.

Lalu pasang dependency frontend (sekali saja, atau setiap kali
`package.json` berubah):

```bash
npm install
```

## 3. Menjalankan aplikasi

Frontend Vue sudah ada (SPA, dibangun dengan Vite, dilayani Laravel dari
satu origin yang sama — PRD §9, tidak ada server Node terpisah di
produksi). Ada dua mode:

**Mode A — sekali build, satu server (paling mendekati instalasi toko
sungguhan):**

```bash
npm run build      # sekali, ulangi tiap kali ada perubahan kode frontend
php artisan serve
```

Buka `http://127.0.0.1:8000` di browser — halaman login Vue langsung
tampil, dan API di baliknya diakses lewat origin yang sama (`/api/v1/...`),
persis seperti yang akan terjadi di laptop toko sungguhan.

**Mode B — dev server dengan hot-reload (untuk mengedit komponen Vue):**

```bash
# terminal 1
php artisan serve
# terminal 2
npm run dev
```

Buka `http://127.0.0.1:5173` (port default Vite) — `vite.config.js` sudah
memproxy `/api/*` ke `127.0.0.1:8000` supaya tidak perlu konfigurasi CORS
sama sekali. Perubahan komponen langsung terlihat tanpa refresh manual.

Login lewat UI: field **Username** (bukan email) + password — lihat
akun dev di §4. Semua endpoint API ada di bawah prefix `/api/v1` (lihat
`docs/openapi-pos-mvp.yaml` untuk kontrak lengkap). Untuk uji API murni
tanpa UI, tetap bisa lewat `curl`, koleksi Bruno (§6), atau Postman.

## 4. Login & kredensial dev

Login pakai field **`username`**, BUKAN `email`:

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"owner","password":"password123"}'
```

Respons berisi `token` (Sanctum). Pakai di request berikutnya:

```bash
curl -s http://127.0.0.1:8000/api/v1/auth/me \
  -H "Authorization: Bearer <token>"
```

Akun dev (password sama untuk semua: `password123`):

| Username    | Role     |
|-------------|----------|
| `owner`     | owner    |
| `admin`     | admin    |
| `kasir01`   | cashier  |
| `kasir02`   | cashier  |
| `inventory` | inventory|

## 5. Menjalankan test

```bash
php artisan test
```

Tidak butuh flag tambahan — `phpunit.xml` sudah set `APP_ENV=testing`,
yang otomatis membuat Laravel memuat `.env.testing` (bukan `.env`).
**Anda harus membuat `.env.testing` sendiri** (di-gitignore, tidak pernah
dikomit) menunjuk ke database TERPISAH dari database aplikasi — test
suite menjalankan `RefreshDatabase` berulang kali dan akan menghapus
seluruh isi database yang dipakainya:

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=boothpos_test
DB_USERNAME=<user_aplikasi_anda>
DB_PASSWORD=<password_aplikasi_anda>
BACKUP_EXTERNAL_PATH=<folder lokal apa saja untuk uji backup, lihat §7>
```

Hasil yang sudah diverifikasi terakhir: **167/167 test lulus, 484
assertion**, terhadap MySQL 8.4 sungguhan (sebelumnya 122; bertambah oleh
ekspor/impor Excel master data dan perbaikan rekap artist).
Kalau ada test yang gagal
setelah Anda clone ulang, kemungkinan besar karena `.env.testing` belum
dibuat/salah host-port, bukan bug logika bisnis (histori bug yang pernah
ditemukan ada di `README.md`).

### 5b. Test frontend

```bash
npm test          # sekali jalan (Vitest)
npm run test:watch   # mode watch untuk development
```

Hasil yang sudah diverifikasi: **44/44 test lulus** (unit + component,
Vitest + Testing Library, environment `jsdom`). Tidak butuh MySQL/backend
berjalan — test ini murni terhadap komponen/store/composable Vue, API
di-mock di level test.

## 6. Uji API manual lewat Bruno

Koleksi ada di `bruno/` — alur end-to-end dari login sampai laporan,
termasuk skenario negatif. Buka dengan aplikasi Bruno, pilih environment
`bruno/environments/local.bru`, sesuaikan base URL kalau port
`php artisan serve` Anda bukan `8000`. **Belum pernah dijalankan
langsung lewat Bruno** (verifikasi sejauh ini pakai `php artisan test` +
`curl` manual) — kalau Anda menjalankannya dan menemukan selisih dengan
perilaku sungguhan, itu bug baru yang belum tercatat.

## 7. Backup & restore (WBS 9.2)

```bash
php artisan app:backup
```

Membuat `storage/app/backups/<timestamp>/database.sql` (dump MySQL penuh
lewat `mysqldump`) + `payment-proofs.tar.gz`, lalu menyalin keduanya ke
`BACKUP_EXTERNAL_PATH`.

```bash
php artisan app:restore storage/app/backups/<timestamp>/database.sql
php artisan app:restore <path> --force   # lewati konfirmasi interaktif, untuk automasi
```

**MENIMPA seluruh isi database tujuan** — jangan jalankan ke database
`boothpos` produksi tanpa yakin.

Kedua perintah butuh `mysqldump`/`mysql` tersedia di `PATH` proses PHP
yang menjalankan `artisan` — ini asumsi yang BENAR untuk instalasi toko
sungguhan (server lokal dengan MySQL terpasang normal). Di mesin dev ini
yang hanya punya MySQL di dalam container Docker, `mysqldump` TIDAK ada
di host secara langsung. **Jangan pasang `mysql-client` lewat Homebrew
untuk mengakalinya** — itu sempat terjadi di sesi sebelumnya dan sudah
di-uninstall lagi atas permintaan eksplisit. Kalau perlu menguji
`app:backup`/`app:restore` di mesin seperti ini, proxy sementara ke
container yang sudah jalan, dan jangan commit proxy itu sebagai bagian
dari kode produk:

```bash
docker exec -e MYSQL_PWD='<password>' laradock-mysql-1 \
  mysqldump -u<user> boothpos > /tmp/manual-check.sql
```

Yang BELUM dikerjakan (catat sebagai tugas lanjutan sebelum event
pertama, bukan sesuatu yang diam-diam dianggap selesai):
- Penjadwalan otomatis harian — `routes/console.php` belum berisi
  `Schedule::command('app:backup')`.
- Uji pemulihan dari media eksternal fisik sungguhan (flashdisk/HDD
  nyata) — yang sudah diverifikasi baru penyalinan ke folder lokal
  pengganti.

## 8. Troubleshooting cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| Migration gagal dengan error dekat `CHECK` / `ALTER TABLE` | `DB_CONNECTION` masih `sqlite` (default `.env.example`) | Set ke `mysql`, lihat §2 |
| `php artisan test` gagal total sejak migration awal | `.env.testing` belum dibuat, atau menunjuk ke database yang sama dengan `.env` aplikasi | Buat `.env.testing` terpisah, lihat §5 |
| Login selalu 422 "field required" | Mengirim `email` alih-alih `username` | Field login adalah `username`, lihat §4 |
| `app:backup` gagal "mysqldump: command not found" | Tidak ada `mysqldump` di `PATH` | Untuk instalasi toko sungguhan: pasang MySQL client normal di server. Untuk mesin dev dengan MySQL di Docker: lihat §7, JANGAN `brew install mysql-client` |
| Upload bukti pembayaran / backup melewatkan file bukti pembayaran | Path disk `local` berbeda antar versi Laravel (`storage/app` vs `storage/app/private`) | Sudah diperbaiki di `BackupPos` sesi ini (ambil path dari disk, bukan hardcode) — kalau muncul lagi, cek `config/filesystems.php` |
| Buka `http://127.0.0.1:8000` tapi dapat halaman kosong / error manifest Vite | Belum pernah `npm run build` — `public/build/manifest.json` belum ada | Jalankan `npm run build` sekali (§3 Mode A), atau pakai Mode B (`npm run dev` + akses lewat port Vite, bukan port Laravel) |
| Buka lewat port Vite (`5173`) tapi request API gagal/CORS | `php artisan serve` di terminal lain belum jalan — proxy `vite.config.js` menunjuk ke `127.0.0.1:8000` | Pastikan kedua proses (§3 Mode B) jalan bersamaan |
| Komponen Vue tidak update setelah edit source | Masih menjalankan build lama (Mode A) alih-alih dev server | Untuk development harian pakai Mode B (`npm run dev`), bukan build-ulang manual tiap kali |

## 9. Perbedaan dev vs instalasi toko sungguhan

Runbook ini ditulis untuk mesin development (Docker MySQL, database
`boothpos`/`boothpos_test` terpisah, `BACKUP_EXTERNAL_PATH` menunjuk ke
folder lokal). Untuk instalasi toko sungguhan (satu server lokal per
toko, sesuai model lisensi BoothPOS):
- MySQL terpasang native di server yang sama, `mysqldump`/`mysql` di
  `PATH` secara normal — tidak perlu trik docker-exec di §7.
- `BACKUP_EXTERNAL_PATH` WAJIB menunjuk ke flashdisk/HDD fisik yang
  benar-benar tercolok, bukan folder di disk yang sama dengan aplikasi
  (lihat peringatan di `config/backup.php`).
- Kredensial seeder (`password123` untuk semua akun) HARUS diganti saat
  provisioning — `DatabaseSeeder` tidak punya pengaman otomatis
  "hanya jalan di local", itu tanggung jawab proses instalasi.
- Frontend WAJIB dalam mode build (§3 Mode A) — `npm run dev` (Mode B)
  adalah alat development, bukan sesuatu yang dijalankan di laptop toko.
  Node/npm hanya dibutuhkan sekali saat instalasi (untuk `npm install` +
  `npm run build`); server yang berjalan sehari-hari di toko cukup
  `php artisan serve` (atau setara) melayani hasil build statis di
  `public/build/`.
