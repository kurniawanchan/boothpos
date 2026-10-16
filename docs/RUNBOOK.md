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
php artisan storage:link
```

**PENTING (ditambahkan 2026-09-01)** — `storage:link` WAJIB dijalankan
sebelum mencoba fitur gambar produk/kategori, QR channel pembayaran, atau
gambar hasil impor massal. Tanpa symlink `public/storage` -> `storage/app/public`,
`qr_image_url`/`image_url` yang dikembalikan API akan menunjuk ke berkas
yang tidak bisa diakses lewat HTTP (404), meski path-nya benar tersimpan
di database. Terverifikasi belum pernah dijalankan di mesin dev ini
sampai sesi ini.

```bash
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
DB_DATABASE=warkop_wjm
DB_USERNAME=<user_aplikasi_anda>
DB_PASSWORD=<password_aplikasi_anda>

BACKUP_EXTERNAL_PATH=/path/ke/flashdisk-atau-hdd   # lokal: folder biasa juga cukup untuk dev
```

Kalau `warkop_wjm`/`<user_aplikasi_anda>` belum ada di container MySQL,
buat sekali (lewat `docker exec -it laradock-mysql-1 mysql -uroot -p...`
atau client MySQL apa pun yang sudah Anda punya) — jangan pakai user
`root` di `.env` aplikasi, buat user khusus yang haknya dibatasi ke
database `warkop_wjm`/`warkop_wjm_test` saja.

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

**Mode C — Docker Compose (opsional, untuk kontributor tanpa PHP/Node/MySQL
terpasang native — spesifikasi lengkap: `specs/015-dockerize-dev-environment/`):**

Ini murni tooling development lokal — sama sekali TIDAK mengubah cara
produk sungguhan di-deploy ke laptop toko (§9 tetap berlaku apa adanya).
Mode A/B di atas terus bekerja tanpa perubahan; ini opsi tambahan, bukan
pengganti.

```bash
cp .env.docker.example .env   # BUKAN .env.example — lihat komentar di
                               # dalam file itu sendiri
docker compose up             # sekali, membangun image kalau belum ada
```

Perintah di atas menjalankan tiga service (`mysql`, `app`, `node`) sekaligus:
migration otomatis dijalankan (idempotent), TAPI seeding data demo tetap
langkah manual yang disengaja:

```bash
docker compose exec app php artisan db:seed                              # akun dev
docker compose exec app php artisan db:seed --class=SakanaFridgeDemoSeeder  # data contoh
```

Buka `http://localhost:8010` (app di Docker; Vite dev Docker di `http://localhost:5180`) (atau `http://localhost:5173` untuk jalur
hot-reload Vite, sama seperti Mode B) di browser.

- **Data bertahan** melewati `docker compose down` + `up` lagi (volume
  MySQL bernama, bukan sekali pakai). Untuk reset bersih total (migration
  dari nol, tanpa data): `docker compose down -v` lalu `docker compose up`
  lagi.
- **Menjalankan test di dalam Docker** (hasilnya harus identik dengan
  native — lihat §5). **PENTING, BUG DITEMUKAN (020-customer-data-import-export)**:
  `docker compose exec app php artisan test` TANPA flag `-e` di bawah ini
  diam-diam menjalankan test melawan `warkop_wjm` (database DEV SUNGGUHAN),
  BUKAN `warkop_wjm_test` — `env_file: .env` pada service `app` di
  `docker-compose.yml` menyuntik `APP_ENV=local`/`DB_DATABASE=warkop_wjm`
  sebagai environment variable level-OS di dalam container, yang menang
  atas `.env.testing` DAN atas `<env force="true">` di `phpunit.xml`
  (sudah dicoba, terbukti tidak cukup — lihat komentar di phpunit.xml).
  Akibatnya `RefreshDatabase` MENGHAPUS seluruh data dev/demo setiap kali
  test dijalankan, tanpa satu pun error. Override env secara eksplisit
  setiap kali menjalankan test di Docker:
  ```bash
  docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test
  docker compose exec node npm test
  ```
  Kalau data dev/demo kamu hilang setelah menjalankan test tanpa flag ini,
  jalankan ulang `php artisan db:seed` + `db:seed --class=SakanaFridgeDemoSeeder`
  (§3 di atas) untuk memulihkannya.
- Jangan jalankan Mode A/B (native `php artisan serve`/`npm run dev`) dan
  Mode C bersamaan — keduanya memakai port host yang sama (8000/5173) dan
  akan langsung gagal jelas ("port is already allocated"), bukan berjalan
  diam-diam berdampingan.

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
DB_DATABASE=warkop_wjm_test
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
`warkop_wjm` produksi tanpa yakin.

**Lewat layar (Pengaturan → Cadangan & pemulihan database)**, owner/admin
bisa membuat cadangan, mengunduhnya, memulihkan dari daftar, atau memulihkan
dari berkas `.sql` yang diunggah (mis. dari laptop lain). Layar memakai logika
yang SAMA dengan `app:backup` (satu `BackupService`), jadi butuh `mysqldump`/
`mysql` di server yang sama seperti perintahnya. Bedanya dengan CLI: pemulihan
lewat layar SELALU membuat cadangan pengaman dari kondisi saat ini lebih dulu,
meminta mengetik `RESTORE`, dan dibatalkan (tanpa mengubah apa pun) bila cadangan
pengaman itu gagal. Untuk pemulihan bencana (database rusak sampai `mysqldump`
pun gagal) tetap gunakan `app:restore` — sengaja tanpa langkah pengaman itu.
**Lisensi ikut dipulihkan.** Pemulihan mengganti SELURUH database, termasuk
`license_activations` yang terikat ke sidik jari mesin. Memulihkan cadangan dari
perangkat lain (atau yang dibuat sebelum aplikasi diaktivasi) membuat aplikasi
terkunci (423) sampai diaktivasi ulang dengan kunci lisensi — siapkan kuncinya
atau minta kunci baru ke vendor. Ini disengaja (keputusan product owner, 026);
dialog pemulihan memperingatkannya. Berkas unggahan dibatasi 50 MB; kedua image
Docker memasang `docker/php/uploads.ini` (`upload_max_filesize = 64M`) supaya
PHP tidak menolaknya lebih dulu.
Setiap cadangan juga bisa dihapus dari daftar (hanya salinan lokal; salinan di
`BACKUP_EXTERNAL_PATH` tidak ikut terhapus). Folder cadangan lokal bisa diatur lewat `BACKUP_PATH` (default
`storage/app/backups`).

Kedua perintah butuh `mysqldump`/`mysql` tersedia di `PATH` proses PHP
yang menjalankan `artisan` — ini asumsi yang BENAR untuk instalasi toko
sungguhan (server lokal dengan MySQL terpasang normal) DAN untuk container
`app` dev (image `docker/php/Dockerfile` kini memasang `default-mysql-client`
+ konfigurasi `ssl=0`; kalau Anda baru menarik perubahan ini, jalankan
`docker compose up -d --build app` sekali). Di host macOS dev, `mysqldump`
TIDAK ada secara langsung. **Jangan pasang `mysql-client` lewat Homebrew
untuk mengakalinya** — itu sempat terjadi di sesi sebelumnya dan sudah
di-uninstall lagi atas permintaan eksplisit. Kalau perlu menguji
`app:backup`/`app:restore` di mesin seperti ini, proxy sementara ke
container yang sudah jalan, dan jangan commit proxy itu sebagai bagian
dari kode produk:

```bash
docker exec -e MYSQL_PWD='<password>' laradock-mysql-1 \
  mysqldump -u<user> warkop_wjm > /tmp/manual-check.sql
```

Yang BELUM dikerjakan (catat sebagai tugas lanjutan sebelum event
pertama, bukan sesuatu yang diam-diam dianggap selesai):
- Penjadwalan otomatis harian — `routes/console.php` belum berisi
  `Schedule::command('app:backup')`.
- Uji pemulihan dari media eksternal fisik sungguhan (flashdisk/HDD
  nyata) — yang sudah diverifikasi baru penyalinan ke folder lokal
  pengganti.

### Setelah menarik perubahan kode (migrasi database)

Cabang baru sering membawa migrasi. Terapkan sebelum mencoba layar baru:

```bash
php artisan migrate                              # native
docker compose exec app php artisan migrate      # Docker dev
```

Pada Docker, `docker/php/entrypoint.sh` hanya menjalankan migrasi saat
container **dimulai** — container yang sudah berjalan sebelum `git pull`
tidak mendapat migrasi baru sampai di-restart (`docker compose restart app`)
atau perintah di atas dijalankan. Gejalanya: layar yang memakai kolom baru
gagal. Sejak fitur 035 API menjawab **503 `schema_outdated`** dengan pesan
ramah (bukan galat SQL), dialog menampilkan "Coba lagi", dan owner/admin
melihat banner "Database perlu diperbarui" di atas halaman. Setelah migrasi
diterapkan, "Coba lagi" langsung bekerja tanpa memuat ulang halaman. Aplikasi
sendiri TIDAK PERNAH menjalankan migrasi dari request web.

## 8. Troubleshooting cepat

| Gejala | Penyebab | Solusi |
|---|---|---|
| Migration gagal dengan error dekat `CHECK` / `ALTER TABLE` | `DB_CONNECTION` masih `sqlite` (default `.env.example`) | Set ke `mysql`, lihat §2 |
| `php artisan test` gagal total sejak migration awal | `.env.testing` belum dibuat, atau menunjuk ke database yang sama dengan `.env` aplikasi | Buat `.env.testing` terpisah, lihat §5 |
| Layar/dialog gagal dengan "Database perlu diperbarui" (503 `schema_outdated`) | Migrasi belum diterapkan (container berjalan sebelum `git pull`) | Terapkan migrasi, lihat bagian "Setelah menarik perubahan kode" di atas |
| Login selalu 422 "field required" | Mengirim `email` alih-alih `username` | Field login adalah `username`, lihat §4 |
| `app:backup` gagal "mysqldump: command not found" | Tidak ada `mysqldump` di `PATH` | Untuk instalasi toko sungguhan: pasang MySQL client normal di server. Untuk mesin dev dengan MySQL di Docker: lihat §7, JANGAN `brew install mysql-client` |
| Upload bukti pembayaran / backup melewatkan file bukti pembayaran | Path disk `local` berbeda antar versi Laravel (`storage/app` vs `storage/app/private`) | Sudah diperbaiki di `BackupPos` sesi ini (ambil path dari disk, bukan hardcode) — kalau muncul lagi, cek `config/filesystems.php` |
| Buka `http://127.0.0.1:8000` tapi dapat halaman kosong / error manifest Vite | Belum pernah `npm run build` — `public/build/manifest.json` belum ada | Jalankan `npm run build` sekali (§3 Mode A), atau pakai Mode B (`npm run dev` + akses lewat port Vite, bukan port Laravel) |
| Buka lewat port Vite (`5173`) tapi request API gagal/CORS | `php artisan serve` di terminal lain belum jalan — proxy `vite.config.js` menunjuk ke `127.0.0.1:8000` | Pastikan kedua proses (§3 Mode B) jalan bersamaan |
| Komponen Vue tidak update setelah edit source | Masih menjalankan build lama (Mode A) alih-alih dev server | Untuk development harian pakai Mode B (`npm run dev`), bukan build-ulang manual tiap kali |

## 9. Perbedaan dev vs instalasi toko sungguhan

Runbook ini ditulis untuk mesin development (Docker MySQL, database
`warkop_wjm`/`warkop_wjm_test` terpisah, `BACKUP_EXTERNAL_PATH` menunjuk ke
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

## 10. Deployment toko via Docker (opsional, `specs/016-docker-store-deployment/`)

**PENTING** — ini BUKAN Mode C di §3. Mode C (`docker-compose.yml`,
feature 015) murni tooling development lokal (bind-mount source, hot-reload).
Bagian ini memakai berkas yang sama sekali berbeda
(`docker-compose.store.yml`, `docker/store/`) yang dirancang sebagai
JALUR DEPLOYMENT SUNGGUHAN — image sudah membawa `vendor/`/`public/build/`
hasil build, tanpa bind-mount, tanpa clone source code di laptop toko.
Instalasi native (§2-§9 di atas) tetap didukung penuh dan tidak berubah —
ini alternatif, bukan pengganti.

### 10.1 Setup pertama kali

Operator TIDAK perlu clone repo ini — cukup image (registry atau file
offline) + satu berkas konfigurasi.

```bash
cp .env.store.example .env
# WAJIB diganti sebelum dipakai sungguhan: DB_PASSWORD, BACKUP_EXTERNAL_PATH
```

**Jalur registry** (kalau venue punya internet stabil):
```bash
docker compose -f docker-compose.store.yml pull
docker compose -f docker-compose.store.yml up -d
```

**Jalur offline** (tanpa internet sama sekali — file `.tar` dipindah lewat
USB/download, dibuat oleh maintainer lewat `docker/store/package-release.sh`):
```bash
docker load -i boothpos-store-<versi>.tar
docker compose -f docker-compose.store.yml up -d
```

Kedua jalur berakhir sama: migration jalan otomatis (idempotent, sama
seperti Mode C dev), buka `http://localhost:8000`. Seeding data
(`db:seed`/`SakanaFridgeDemoSeeder`) TETAP langkah manual yang disengaja,
persis seperti instalasi native — lihat §9, kredensial dev
(`password123`) HARUS diganti saat provisioning toko sungguhan.

### 10.2 Update ke versi baru

Sepenuhnya manual — TIDAK ada pengecekan versi otomatis di dalam app
(sesuai desain produk ini yang memang tanpa cloud tier).

**Jalur registry**:
```bash
docker compose -f docker-compose.store.yml pull app
docker compose -f docker-compose.store.yml up -d app
```

**Jalur offline**:
```bash
docker load -i boothpos-store-<versi-baru>.tar
# ubah tag image `app` di docker-compose.store.yml ke versi baru
docker compose -f docker-compose.store.yml up -d app
```

Service `mysql` dan volume-nya TIDAK PERNAH ikut tersentuh oleh kedua
jalur update di atas — data toko aman terlepas dari perubahan image `app`.
Kalau update bermasalah, image tag versi sebelumnya masih tersimpan lokal
(hasil `docker load`/`pull` sebelumnya) — arahkan kembali tag image di
compose file ke versi lama lalu `up -d app` lagi sebagai rollback manual.
Migration bersifat searah (tidak ada auto-rollback skema), sama seperti
keterbatasan instalasi native saat ini.

### 10.3 Backup & restore

Perintah PERSIS SAMA dengan §7 — tidak dimodifikasi sama sekali untuk
jalur Docker ini:

```bash
docker compose -f docker-compose.store.yml exec app php artisan app:backup
docker compose -f docker-compose.store.yml exec app php artisan app:restore <path> [--force]
```

Image `app` sudah membawa `mysqldump`/`mysql` client bawaan (tidak perlu
trik `docker exec` ke container lain seperti di §7 untuk mesin dev), dan
`BACKUP_EXTERNAL_PATH` di `.env` di-bind-mount ke path host yang sama
persis lewat `docker-compose.store.yml` — arahkan ke titik mount drive
eksternal fisik yang sungguhan tercolok di laptop toko, bukan folder di
disk yang sama (persis peringatan §9).

## 11. Varian restoran (fitur 028 — meja, tab terbuka, modifier, dapur/bar)

Salinan proyek ini berjalan terpisah dari instalasi BoothPOS lain: port **app 8010 / Vite 5180 / MySQL host 3308**,
database **`warkop_wjm`** (dev) dan **`warkop_wjm_test`** (test), project Docker `warkop_wjm`. Jangan pernah menjalankan
perintah terhadap container/database instalasi lain dari sini.

### 11.1 Setup awal restoran (sekali per mode DEMO/LIVE — tanpa kode)

1. **Tidak ada event.** Sejak fitur 046 modul Event dihapus: shift dibuka langsung (kas awal + outlet) tanpa event, jadi
   tidak ada lagi langkah "buat event Operasional Restoran".
2. **Satu outlet.** Tanpa lisensi multi-outlet, instalasi boleh punya tepat satu outlet aktif. Buat `Restoran`; semua menu
   memakai outlet ini. Laporan rekap outlet akan menampilkan satu baris — itu wajar.
3. Kategori & menu (varian = ukuran). **Hidangan yang dibuat saat dipesan**: matikan "Lacak stok" pada variannya supaya
   tidak ada stok palsu dan penjualan tidak pernah terblokir "stok habis".
4. *Pengaturan Restoran*: **Meja** (nama/area/kursi), **Stasiun** (Dapur, Bar), **Modifier** (grup + opsi + lampiran ke
   produk/kategori). Tetapkan stasiun pada kategori atau produk di layar Kategori/Produk (stasiun produk menang atas
   kategori; menu tanpa stasiun langsung dianggap siap saji).
5. Buka sesi kasir (*Sesi Kasir*).

### 11.2 Hak akses (kunci menu, dapat diatur per peran di layar Peran)

| Kunci | Untuk | Default |
|---|---|---|
| `floor` | lantai, buka/ubah/kirim/bayar/pisah/gabung/pindah tab, tandai diantar | Owner, Admin, Kasir |
| `kitchen` | layar dapur/bar, mulai/siap, daftar stasiun (baca) | Owner, Admin, Kasir |
| `dining_setup` | meja, stasiun, modifier | Owner, Admin, Inventory |
| `settings` (owner/admin) | batalkan item yang sudah dimasak, batalkan tab, serah-terima tab | Owner, Admin |

Peran "Dapur" atau "Pelayan" tidak di-seed: buat sendiri di layar Peran dengan mencentang kunci yang perlu.

### 11.3 Pembaruan layar & performa

Layar lantai, dapur, dan tab memakai **polling pendek (±3–4 dtk)** dengan token versi — bukan koneksi yang dibiarkan
terbuka — karena server PHP dijalankan dengan `php artisan serve`. Konsekuensinya: tablet/monitor harus berada di
jaringan lokal yang sama dan tidak butuh internet; layar yang tersembunyi berhenti menarik data dan pulih sendiri saat
tampil lagi.

**Beberapa worker (sudah diterapkan, diukur 2026-10-04).** Server bawaan PHP hanya melayani satu permintaan pada satu
waktu: tanpa worker tambahan, satu ekspor/laporan yang berjalan 4 dtk menahan poll ringan layar dapur selama ±3,5 dtk.
Kedua entrypoint (`docker/php/`, `docker/store/`) kini mengekspor `PHP_CLI_SERVER_WORKERS` (bawaan **4**) dan menjalankan
`artisan serve --no-reload` — `--no-reload` WAJIB, sebab tanpanya `artisan serve` mengabaikan variabel itu. Hasil ukur:
poll ringan selesai ±7 ms dengan 4 worker; 5 proses `php -S` berjalan (1 induk + 4 worker); 60/60 permintaan serentak
berstatus 200. Cache/session memakai driver `file` yang dipakai bersama semua worker. Untuk mematikannya, set
`PHP_CLI_SERVER_WORKERS=1` di `.env`. Catatan: dokumentasi PHP menandai mode worker server bawaan sebagai
*experimental* dan server ini tidak ditujukan untuk produksi — keduanya sudah menjadi cara layanan ini dijalankan, dan
pengaturan ini hanya menambah proses, bukan mengubah arsitektur.

### 11.4 Mengatasi masalah

- **Tab tidak bisa dibayar / shift tidak bisa ditutup:** tab terbuka menahan shift. Bayar, batalkan (owner/admin), atau
  serahkan ke shift lain (owner/admin). Layar Sesi Kasir menampilkan daftar tab penghalangnya.
- **Tab yang sudah ada pembayaran sebagian** tidak bisa dipisah, digabung (sebagai sumber), atau dibatalkan — selesaikan
  pembayaran atau (untuk satu item) hapus baris selama total tidak turun di bawah yang sudah dibayar.
- Menjalankan test di dalam container: **selalu**
  `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test`.

## 12. Outlet (fitur 045): migrasi bertahap, rehearsal, dan backup

**Dated note — 2026-10-11 (konstitusi 2.0.0).** Fitur 045 menambah outlet (penjual yang diganti nama). Migrasinya dijalankan per tahap; setiap tahap wajib direhearsal di salinan data sebelum dirilis ke toko sungguhan.

Langkah sebelum setiap tahap:

1. `docker compose exec app php artisan app:backup` — catat folder cadangan yang dicetak.
2. `docker compose exec app php artisan outlets:reconcile --before` — cuplikan keadaan sebelum migrasi.
3. Jalankan migrasi (`php artisan migrate --force`) — di dev, lalu rehearsal (langkah 5).
4. `docker compose exec app php artisan outlets:reconcile --after` lalu `--compare`. Kode keluar ≠ 0 berarti ada selisih: **jangan rilis** — pulihkan dari cadangan dan periksa.
5. Rehearsal di salinan: `specs/045-outlets-per-outlet-menu/rehearse.sh`. Skrip hanya menulis ke basis data `warkop_wjm_rehearsal` (dibuat ulang setiap kali, lalu dihapus), tidak pernah ke `warkop_wjm` atau `warkop_wjm_test`.

Pemulihan: langkah yang bisa dibalik (ganti nama tabel/kolom, penambahan kolom nullable) dicatat per migrasi di `specs/045-outlets-per-outlet-menu/release-notes.md`; untuk migrasi yang menulis saldo stok atau tabel baru, pemulihan = `app:restore` dari cadangan langkah 1.

Peran dan akses outlet: setiap pengguna bisa dibatasi ke satu atau beberapa outlet; administrator selalu melihat semua outlet. Instalasi satu outlet tidak menampilkan kendali outlet sama sekali.

### 12.1 Tahap lanjutan (stok per outlet, transfer, laporan) — dicatat 2026-10-12

- Urutan migrasi 045: `…_12_01` ganti nama penjual→outlet, `…_12_02` penugasan + `outlet_id` operasional, `…_12_03` menu per outlet, `…_12_04` saldo stok per outlet, `…_12_05` transfer + izin transfer. Rehearsal penuh dari cadangan sebelum 045 memakan ±14 detik (data demo) dan `outlets:reconcile` hijau (hitungan, uang, saldo, I1/I2).
- Rehearsal dari **cadangan lama** (`app:backup`) tertahan masalah yang sudah ada sebelumnya: berkas dump memuat kolom `orders.open_table_key` (generated) di `INSERT INTO orders VALUES`, sehingga `app:restore`/`mysql` ke MySQL 8 gagal (error 3105) begitu ada order. Jalan keluar sementara: untuk rehearsal, buang definisi GENERATED pada `open_table_key` di berkas SQL sebelum dimuat; untuk pemulihan nyata, ambil dump dengan `mysqldump` dari kontainer MySQL (bukan klien MariaDB). Perbaikan permanen (`--complete-insert`/lewati kolom generated) dicatat di `release-notes.md`.
- Demo multi-outlet opsional: `docker compose exec app php artisan db:seed --class=WarkopOutletsDemoSeeder` (setelah `SakanaFridgeDemoSeeder`) — tiga outlet "Warkop WJM", pengguna contoh ditugaskan, meja, saldo per outlet, satu transfer dalam perjalanan. Idempoten.
- Setelah mengaktifkan lebih dari satu outlet: tinjau penugasan di layar Pengguna (migrasi menugaskan pengguna non-admin ke SEMUA outlet yang ada; persempit sesuai kebutuhan).



## 12. Event dan Pre-order dihapus (fitur 046, 2026-10-12)

- **Yang berubah untuk pengguna:** menu *Events* dan *Pre-orders* hilang (alamat lamanya, termasuk yang membawa
  `?event_id=`/`?preorder_id=`, mengalihkan ke Dashboard). Shift dibuka tanpa event. Laporan (Rekap Outlet, Modal & Untung,
  Modal Outlet), Dashboard "Rincian periode", dan halaman Penjualan memakai **rentang tanggal bisnis** (bawaan hari ini);
  tidak ada lagi filter/kolom event dan tidak ada angka pre-order di mana pun.
- **Yang TIDAK berubah di data:** tabel `events`, `preorders`, `preorder_items`, `preorder_notifications`, `shipments`,
  `payments.preorder_id`, dan baris buku besar stok lama tetap ada sebagai riwayat diam (tidak dikonversi, dihapus, atau
  dibalik). Tidak ada layar maupun API yang membacanya. Pengiriman e-mail pre-order dan konfigurasi surat terkait sudah tidak dipakai.
- **Pemutakhiran:** `app:backup` → ambil kode baru → `php artisan migrate` (dua migrasi: `event_id` menjadi nullable pada tiga
  tabel; kunci menu `events`/`preorders` dicabut dari peran). Sebelum/sesudah, `php artisan outlets:reconcile --before` /
  `--after` / `--compare` harus bersih. Bukti uji coba pada salinan: `specs/046-hide-events-preorders/evidence-upgrade.md`.
- **Pembatalan (rollback):** pulihkan dari `app:backup` yang diambil sebelum pemutakhiran. `down()` migrasi nullable sengaja
  berhenti bila sudah ada baris tanpa event (tidak mengarang event); kunci menu yang dicabut tidak dapat dipulihkan otomatis.
- **Alamat tersimpan/saran:** pengguna yang menyimpan alamat `/events` atau `/preorders` akan mendarat di Dashboard.

## 13. Aplikasi Android POS (fitur 048, 2026-10-16)

Aplikasi tablet kasir ada di `android-pos/` (Kotlin, Compose). Ia online-first dan memanggil API yang sama dengan SPA. Spesifikasi: `specs/048-wire-android-pos-api/`.

### 13.1 Menyiapkan terminal
1. Buka aplikasi, masukkan alamat server (di emulator: `http://10.0.2.2:8010`; di perangkat sungguhan: alamat LAN host).
2. Masuk dengan akun **owner atau admin** (kata sandi), lalu pilih outlet.
3. Daftarkan terminal (mis. `T01`). Server membuat `devices` per outlet dan mengembalikan id perangkat; aplikasi menyimpannya di Keystore.
4. Pilih staf dan masuk dengan PIN 6 digit. Staf dibaca dari server; PIN tidak pernah disimpan di perangkat.

### 13.2 Emulator
- Emulator mencapai host lewat `10.0.2.2` (bukan `localhost`). Backend dev berjalan di `http://localhost:8010` pada host, sehingga alamat di aplikasi adalah `http://10.0.2.2:8010`.
- Cold boot (`-no-snapshot`) memakan beberapa GB ruang disk dan CPU; pastikan ruang bebas dan beban host cukup sebelum menguji.

### 13.3 Membangun APK
```bash
cd android-pos
./gradlew :app:assembleDebug        # APK: app/build/outputs/apk/debug/app-debug.apk
./gradlew :core-network:testDebugUnitTest :app:testDebugUnitTest
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

### 13.4 Mencabut dan memasang ulang perangkat
- Cabut: `DELETE /devices/{id}` (owner/admin). Server mengisi `revoked_at`; perangkat tidak dihapus dari basis data (jejak audit). Permintaan dari perangkat yang dicabut ditolak (422 pada `device_id`).
- Pasang ulang: jalankan penyiapan lagi (13.1) untuk membuat pasangan baru. Heartbeat (`POST /devices/heartbeat`, tiap 60 dtk) menandai `last_seen_at` dan selisih jam; selisih di atas 5 dtk ditampilkan sebagai peringatan.

### 13.5 Membaca log
```bash
adb logcat -s PosApi            # permintaan API (metode, path, status); tanpa token atau PIN
adb logcat -s AndroidRuntime:E  # galat fatal; harus kosong pada pengujian
```
- Tag `PosApi` hanya tercatat pada build debug (`debuggable`).
- Galat `ANR` ("tidak merespons") dicatat di `/data/anr/traces.txt`; ambil dengan `adb shell cat /data/anr/traces.txt`.
