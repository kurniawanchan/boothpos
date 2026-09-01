**Klasifikasi: INTERNAL**

# BoothPOS — Backend Laravel

BoothPOS — sistem POS event-based multi-artist untuk toko merchandise, dijual sebagai lisensi sekali bayar dengan instalasi lokal per toko.

Akumulasi Increment 1–3+ (Auth, Artist, Category, Product/Variant, Customer,
Stock, Event/Session, Payment, Order, Preorder/Shipment, Report/Settlement,
Backup) ditambah sesi bootstrap (lihat "Status eksekusi" di bawah): Laravel
13 sungguhan dipasang di sekeliling kode ini, seluruh test dijalankan
terhadap MySQL sungguhan, dan Activity Log (F13.4) beserta Settings admin
CRUD (F14) diimplementasikan.

## Status eksekusi

**Kode ini SUDAH dieksekusi dan diuji sungguhan** — bagian ini dulu
mengatakan sebaliknya; itu sudah tidak berlaku. Ringkasan:

- Laravel 13.29 (PHP 8.4.5) dipasang di sekeliling bundel kode ini tanpa
  mengubah logika bisnis yang sudah ada — lihat "Prasyarat instalasi" di
  bawah untuk detail langkah dan apa yang disatukan dari mana.
- `composer require maatwebsite/excel` (v4.0.2) dijalankan sungguhan.
  `GenericArrayExport` diverifikasi menghasilkan berkas `.xlsx` yang benar
  (signature ZIP valid, dibaca ulang lewat PhpSpreadsheet, isi kolom/baris
  cocok) — bukan asumsi lagi.
- Migration dijalankan terhadap database `boothpos` (MySQL 8.4 sungguhan,
  bukan SQLite) dan `php artisan db:seed` berhasil membuat 5 user + 2 kanal
  pembayaran + pengaturan toko.
- **Seluruh test suite hijau: 167/167 lulus, 0 gagal, 0 galat** (angka saat
  bagian ini terakhir diperbarui, 2026-09-01; sesi bootstrap dulu 120),
  dijalankan terhadap database `boothpos_test` (MySQL sungguhan — WAJIB, karena dua
  migration memakai `DB::statement('ALTER TABLE ... ADD CONSTRAINT ...
  CHECK (...)')` yang sintaksnya MySQL-only dan akan gagal di SQLite).
  Jalankan dengan `php artisan test` setelah menyalin `.env.testing` sendiri
  (lihat "Menjalankan").
- Sesuai prediksi di versi README sebelumnya: hampir seluruh kegagalan yang
  ditemukan adalah masalah asumsi versi Laravel/Sanctum/PHP, BUKAN logika
  bisnis inti. Daftar lengkap bug yang ditemukan dan diperbaiki ada di
  bagian "Bug yang ditemukan saat eksekusi" di bawah — semuanya dibuktikan
  lewat test yang benar-benar gagal lalu lulus setelah diperbaiki, bukan
  ditebak dari membaca kode.

## Lisensi Pro vs Master (multi-artist toggle)

Satu setting (`multi_artist_enabled`, tabel `settings`) membedakan dua
tingkat harga tanpa build kode terpisah:

- **Pro** — `false`. Hanya 1 artist aktif diizinkan (mewakili toko itu
  sendiri). `POST /artists` yang kedua ditolak 403.
- **Master** — `true`. Tidak ada batas jumlah artist.

Cek status: `GET /settings/features`. Ubah lewat `PUT /settings` (endpoint
admin owner/admin — lihat "Settings admin CRUD" di bawah; sebelumnya harus
lewat `Setting::updateOrCreate` di tinker/seeder karena belum ada endpoint,
gap ini sudah ditutup).

Logika ada di satu tempat: `app/Support/LicenseGate.php`. Penegakan
sesungguhnya di `ArtistPolicy::create`, bukan di controller atau frontend.

**Dua bug ditemukan dan diperbaiki saat menulis test fitur ini** (bukan
lewat review kode statis):
1. `(bool) Setting::get(...)` salah untuk string `"false"` — di PHP itu
   truthy. Diganti `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.
2. `Setting::get()` memakai `Cache::rememberForever` tanpa invalidasi —
   upgrade Pro ke Master tidak akan langsung berlaku tanpa restart
   aplikasi. Ditambal lewat model event `saved`/`deleted` di `Setting`.

## Activity Log (F13.4)

Log aktivitas untuk tindakan sensitif — hapus data, penyesuaian stok, ubah
harga — sesuai PRD 7.13, prioritas M. Sebelumnya belum diimplementasikan
sama sekali; sekarang:

- Migration `activity_logs` (mengikuti `docs/schema-pos-mvp.sql`), model
  `ActivityLog`, dan service tunggal `App\Services\ActivityLogger` sebagai
  satu-satunya jalur penulisan.
- Ditulis DI DALAM transaksi database yang sama dengan tindakan sensitifnya
  (hapus artist/kategori/produk, ubah produk/varian, penyesuaian stok
  manual) — kalau tindakannya batal, baris log ikut batal, tidak pernah ada
  log yang mengklaim sesuatu terjadi padahal tidak.
- Penyesuaian stok manual (`type: adjustment`) yang dicatat, BUKAN setiap
  pergerakan stok — penjualan/pembelian/preorder tidak membanjiri log ini,
  sesuai kata "penyesuaian" di PRD, bukan "seluruh pergerakan".
- Dibaca lewat `GET /activity-logs` (owner/admin saja), bisa difilter per
  `entity_type`, `entity_id`, `user_id`, `action`, dan rentang tanggal.

## Settings admin CRUD

`GET /settings` dan `PUT /settings` (owner/admin, lewat `SettingPolicy`).
Menutup gap yang disebutkan versi README sebelumnya — `Setting::
updateOrCreate` sudah lama ada sebagai kapabilitas model, tapi tidak ada
endpoint untuk mencapainya. `PUT /settings` menerima bentuk BULK
(`{"settings": [{"key", "value", "type"?, "group"?}, ...]}`) karena layar
pengaturan biasanya menyimpan beberapa field sekaligus, dan ini juga
satu-satunya jalur admin untuk upgrade Pro ke Master (`multi_artist_
enabled`). Setiap perubahan tercatat di Activity Log.

## Ekspor & impor Excel master data (PRD 7.15)

**Catatan cakupan — ditambahkan 2026-09-01.** Impor Excel semula dipotong
dari MVP (PRD 10.2) dan README versi sebelumnya ikut menyebutnya begitu.
Keputusan itu dibatalkan atas permintaan eksplisit pemilik produk; catatan
lamanya tidak dihapus dari PRD, hanya diberi catatan bertanggal.

```
GET  /api/v1/exports/{artists|categories|products|stock}   -> .xlsx per entitas
GET  /api/v1/imports/master-data/template                  -> workbook 4 sheet + contoh
POST /api/v1/imports/master-data                           -> multipart: file, dry_run?
```

Ketiganya digerbang `canManageMasterData()` (owner/admin/inventory) —
sengaja lebih ketat daripada endpoint daftar yang boleh dibaca kasir,
karena menarik seluruh master data sebagai satu berkas adalah permukaan
ekstraksi data massal, bukan kebutuhan operasional kasir.

Keputusan desain yang paling mungkin mengejutkan pembaca berikutnya:

1. **Satu berkas, empat sheet** (`artists`, `categories`, `products`,
   `stock`), diproses dalam urutan dependensi itu berapa pun urutan fisik
   sheet di berkas. Nama sheet dicocokkan tanpa memandang huruf besar/kecil;
   sheet lain diabaikan dan dilaporkan di `ignored_sheets`.
2. **Semua-atau-tidak sama sekali.** Validasi penuh dulu, lalu satu
   transaksi. Ini menyimpang dari kriteria penerimaan F15.5 secara sadar —
   alasannya ditulis lengkap di PRD 7.15 dan di docblock
   `MasterDataImportService`. Pratinjau (F15.4) lewat `dry_run=1`,
   memakai jalur validasi yang sama persis.
3. **Kolom stok bersifat ABSOLUT, bukan selisih.** `current_stock` pada
   sheet `stock` adalah jumlah akhir yang diinginkan; server menghitung
   selisihnya dan menerapkannya sebagai satu `stock_movements` bertipe
   `adjustment` lewat `StockService::applyMovement()` — tidak pernah
   menulis `current_stock` langsung (F15.8). Baris yang angkanya sudah
   sama tidak menghasilkan movement sama sekali.
4. **Stok awal varian BARU sebaiknya diisi lewat kolom `initial_stock` di
   sheet `products`, bukan di sheet `stock`** — SKU dihasilkan server, jadi
   saat menyusun berkas pemilik toko belum tentu tahu SKU varian yang belum
   dibuat. Sheet `stock` TETAP boleh menunjuk SKU yang baru akan dibuat
   berkas yang sama (SKU-nya deterministik: `code_prefix` + urutan 4
   digit); penyelesaiannya ditunda sampai sheet `products` diterapkan, dan
   SKU yang tetap tidak ketemu membatalkan seluruh impor. Berkat itu
   **template bawaan bisa langsung diimpor apa adanya** — ada test yang
   menjaganya (`test_the_shipped_template_imports_as_is`), karena template
   yang baris contohnya sendiri gagal validasi adalah paper cut termahal
   yang bisa dipunyai fitur ini.
5. **Kunci upsert**: `artists.code`, `categories.code`, `stock.sku`, dan
   untuk produk `sku` bila diisi — bila kosong, `code_prefix`
   (artist_code + category_code + product_segment) plus `variant_name`.
   Satu baris sheet `products` = satu VARIAN, karena harga melekat pada
   varian.
6. **Sel kosong berarti "jangan diubah"**, bukan "kosongkan nilainya".
7. **Kode tetap dihasilkan server.** `code_prefix` lewat
   `ProductCodeGenerator::buildCodePrefix()`, SKU lewat `nextVariantSku()`.
   SKU yang tidak dikenal pada sheet `products` DITOLAK, tidak dipakai
   membuat varian baru.
8. **Gerbang lisensi Pro tidak bisa dilewati lewat impor.** `ArtistPolicy`
   tidak ikut jalur ini, jadi kuota `LicenseGate` diperiksa eksplisit di
   `MasterDataImportService` — tanpa itu satu berkas berisi 20 artist jadi
   jalan pintas upgrade Pro ke Master secara gratis.

Berkas hasil ekspor sengaja memakai nama sheet dan judul kolom yang sama
persis dengan yang diterima impor (satu sumber: `App\Support\
MasterDataSheets`), jadi alur "ekspor → sunting di Excel → unggah lagi"
bekerja tanpa menyunting format. Ada test yang membuktikannya
(`test_a_stock_export_can_be_edited_and_imported_back`).

Berkas sumber impor yang BERHASIL diterapkan disimpan di
`storage/app/private/imports/<uuid>.xlsx` sebagai jejak audit; pratinjau
dan berkas yang ditolak tidak meninggalkan sampah.

## Vendor, bahan baku, dan BOM (ditambahkan pasca-MVP, 2026-09-01)

**Catatan cakupan.** PRD §10.2 sebelumnya mencoret "vendor management" dan
"bahan baku, produksi, markup" dari MVP. Modul ini dibangun atas permintaan
eksplisit pemilik produk, dan **BUKAN kebangkitan salah satu butir yang
dicoret itu** — cakupannya sengaja jauh lebih sempit (tidak ada purchase
order, tidak ada penjadwalan produksi) dan tidak berkorespondensi dengan
nomor F- manapun di PRD; ini kapabilitas baru, bukan pemulihan kapabilitas
lama. Catatan lama di PRD tidak dihapus, hanya diberi catatan bertanggal
(lihat PRD §10.2).

```
GET|POST|PUT|DELETE /api/v1/vendors[/{vendor}]
GET|POST|PUT|DELETE /api/v1/materials[/{material}]
POST/PUT/DELETE      /api/v1/materials/{material}/vendor-prices[/{vendorPrice}]
GET/POST/PUT/DELETE  /api/v1/variants/{variant}/bom[/{bomLine}]
GET                  /api/v1/variants/{variant}/cost-breakdown
```

Digerbang `canManageMasterData()`, sama seperti Produk/Kategori/Stok.

Keputusan desain yang paling mungkin mengejutkan pembaca berikutnya:

1. **BOM diikat ke VARIAN, bukan produk induk.** Varian ukuran/warna
   berbeda dari produk yang sama (mis. keychain kecil vs besar) bisa punya
   kebutuhan bahan berbeda, dan `ProductVariant` sudah jadi entitas
   kelas satu untuk data per-SKU (harga, stok) di kodebase ini — BOM
   per-varian konsisten dengan pola yang sudah ada.
2. **Satu bahan boleh dijual banyak vendor** (`vendor_material_prices`,
   unique pada `(vendor_id, material_id)`), dengan satu flag `is_preferred`
   per bahan. Menandai satu harga preferred otomatis melepas tanda dari
   harga lain milik bahan yang sama (`MaterialController`, bukan
   constraint DB — "preferred" adalah keputusan bisnis yang bisa berubah).
3. **`bom_cost` TIDAK PERNAH menimpa `cost_price`.** `cost_price` sudah
   dipakai laporan laba dan settlement artist di seluruh kodebase ini;
   menimpanya otomatis dari BOM berisiko nyata merusak logika yang sudah
   diuji di tempat lain. `bom_cost` selalu berupa field terpisah, read-only,
   di `GET /variants/{variant}/cost-breakdown` — pemilik toko membandingkan
   keduanya sendiri. Lihat dokblok `App\Services\BomCostCalculator`.
4. **Pemilihan harga saat >1 vendor menjual bahan yang sama**: vendor
   `is_preferred` bila ada, kalau tidak ada yang ditandai maka harga
   TERMURAH dipakai — estimasi modal yang defensif, bukan rekomendasi
   belanja. Aturan ini didokumentasikan satu kali di
   `BomCostCalculator`/`Material::referencePrice()`, tidak diduplikasi.
5. **Delete guard mengikuti pola Artist/Category**: vendor yang masih
   punya baris harga terdaftar, atau bahan yang masih dipakai baris harga
   ATAU baris BOM, tidak bisa dihapus (409).
6. **Diimpor/diekspor lewat workbook gabungan yang sama** dengan impor
   master data lain (lihat bagian di atas), sebagai empat sheet tambahan:
   `vendors`, `materials`, `vendor_prices`, `bom`. `vendor_prices`/`bom`
   mereferensikan vendor/bahan/varian lewat `code`/`sku` — pola yang sama
   dengan `artist_code`/`category_code` pada sheet `products`. Baris `bom`
   boleh menunjuk SKU yang baru dibuat sheet `products` pada berkas yang
   sama (diselesaikan saat `apply()`, pola yang sama dengan sheet `stock`).

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
`bruno/README.md`). **Belum dijalankan sungguhan lewat Bruno pada sesi
ini** — verifikasi sesi ini memakai `php artisan test` (120 test lulus)
dan pemanggilan endpoint manual (tinker/artisan) sebagai gantinya, yang
menutupi jalur yang sama tapi bukan alat yang sama. Menjalankan koleksi
Bruno langsung tetap tugas terbuka sebelum uji lapangan penuh (WBS 9.5).

## Prasyarat instalasi

Sudah dijalankan dan terverifikasi pada sesi ini — bukan lagi asumsi.
Ringkasan langkah yang benar-benar dipakai untuk membawa bundel kode ini
(yang tidak menyertakan `artisan`/`bootstrap/`/`public/`/`vendor/`) menjadi
aplikasi Laravel yang bisa dijalankan:

```bash
# 1. Skeleton Laravel dibuat terpisah, lalu Sanctum dipasang di dalamnya
composer create-project laravel/laravel <tmp-dir>
cd <tmp-dir>
php artisan install:api        # memasang Sanctum + routes/api.php + migration personal_access_tokens

# 2. Infrastruktur skeleton (artisan, bootstrap/, public/, resources/,
#    config/*.php dasar, composer.json/lock, phpunit.xml, tests/TestCase.php,
#    storage/*/.gitignore) disalin ke proyek ini TANPA menimpa app/Models,
#    app/Http, app/Services, app/Policies, database/migrations, database/
#    factories, database/seeders/DatabaseSeeder.php, routes/api.php,
#    tests/Feature, atau config/backup.php milik bundel ini.
#    Migration users/cache/jobs bawaan skeleton DIBUANG (proyek ini
#    punya migration users sendiri; cache/session/queue dikonfigurasi
#    'file'/'sync', bukan 'database', supaya tidak perlu tabel tambahan
#    di luar docs/schema-pos-mvp.sql — lihat .env.example).
#    Migration personal_access_tokens Sanctum DIPERTAHANKAN, diberi ulang
#    tanggal 2026_09_30 (sebelum 2026_10_01_...) supaya konsisten dengan
#    konvensi "tabel infrastruktur duluan" — aman karena kolomnya
#    polimorfik (morphs), tidak ada FK keras ke users.

# 3. Dependencies
cd <proyek-ini>
composer install
composer require maatwebsite/excel
```

Set `APP_NAME=BoothPOS` di `.env` — ini nama produk, bukan nama toko.
Nama toko (dicetak di struk) diatur terpisah lewat tabel `settings`
(`store_name`), diisi masing-masing pembeli BoothPOS sesuai bisnisnya
sendiri saat instalasi — lihat `DatabaseSeeder`, atau `PUT /settings`
setelah instalasi berjalan.

Tambahkan ke `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=boothpos
DB_USERNAME=<user_aplikasi>
DB_PASSWORD=<password_aplikasi>

BACKUP_EXTERNAL_PATH=/path/ke/flashdisk-atau-hdd
```

Untuk test (`php artisan test`), buat `.env.testing` terpisah menunjuk ke
database test (BUKAN database aplikasi di atas — test suite menjalankan
`migrate:fresh` berulang kali):
```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=boothpos_test
DB_USERNAME=<user_aplikasi>
DB_PASSWORD=<password_aplikasi>
```
`phpunit.xml` sengaja TIDAK memaksa `DB_CONNECTION=sqlite` (beda dari
default skeleton Laravel) supaya `.env.testing` di atas benar-benar
dipakai — dua migration transaksi memakai `CHECK` constraint MySQL-only
yang akan gagal total di SQLite.

`.env` dan `.env.testing` tidak pernah dikomit ke git (lihat `.gitignore`)
— jangan salin kredensial database ke dokumen atau commit message manapun.

## Menjalankan

```bash
php artisan migrate
php artisan db:seed
php artisan test
```

Hasil sesi ini: migration jalan bersih (16 tabel awal + `personal_access_
tokens` + `activity_logs` = 18), seeder jalan bersih, **120/120 test
lulus** terhadap MySQL 8.4 sungguhan. Kegagalan yang ditemukan saat
eksekusi pertama (lihat bagian berikut) semuanya masalah bootstrap/versi,
bukan logika bisnis inti — logika bisnis inti (transaksi, stok, harga,
lisensi) yang sudah ditinjau baris per baris di sesi-sesi sebelumnya lolos
tanpa perlu diubah.

## Bug yang ditemukan saat eksekusi (bukan lagi ASUMSI — dibuktikan lewat test)

Seluruhnya ditemukan lewat test yang gagal, diperbaiki, lalu test yang
sama dijalankan ulang sampai lulus — bukan dugaan dari membaca kode.

**Bootstrap/infrastruktur (persis seperti diprediksi versi README
sebelumnya — masalah versi Laravel, bukan bisnis):**
1. `app/Http/Controllers/Controller.php` tidak ada sama sekali di bundel
   ini. Skeleton Laravel 11+ menghasilkan base Controller kosong (tanpa
   trait), padahal 5 controller di bundel ini memanggil `$this->
   authorize(...)` yang butuh trait `AuthorizesRequests`. Ditambal dengan
   menambahkan file ini kembali dengan trait tersebut.
2. `Artist::products()` dan `Category::products()` (relasi HasMany) tidak
   pernah ditulis, padahal controller masing-masing memanggil
   `withCount('products')`/`loadCount('products')` — fatal 500 di setiap
   endpoint list/show artist dan kategori.
3. `Product`/`ProductVariant::$fillable` sengaja mengecualikan
   `code_prefix`/`product_segment`/`sku` dengan niat mencegah mass-
   assignment dari klien — tapi SATU-SATUNYA jalur pembuatan produk/varian
   di seluruh kodebase (controller, factory, test) menulis kolom itu lewat
   `create()`, sehingga setiap insert gagal "doesn't have a default
   value". Perlindungan sesungguhnya sudah cukup di lapisan validasi
   (FormRequest tidak menyediakan rule untuk kolom ini), jadi kolom
   ditambahkan kembali ke `$fillable`.
4. `Event::$fillable` tidak menyertakan `status` — `updateStatus()`
   menulis status baru lewat `update(['status' => ...])` yang diam-diam
   tidak berefek apa pun (bukan error, hanya tidak tersimpan). Event
   secara efektif TIDAK PERNAH berpindah status meski API merespons 200.
5. `BackupPos` men-hardcode `storage_path('app/payment-proofs')`; sejak
   Laravel 11 root disk `local` pindah ke `storage/app/private`. Cadangan
   diam-diam melewati SELURUH bukti pembayaran tanpa galat apa pun.
   Diselesaikan dengan meminta path dari disk itu sendiri.
6. `BackupPos` memanggil `proc_open($command, [2 => [...]], $pipes, null,
   ['MYSQL_PWD' => $dbPass])` — argumen environment ke-5 pada `proc_open`
   MENGGANTI seluruh environment child process, bukan menambahkannya
   (beda dari `exec()`/`shell_exec()` yang otomatis mewarisi penuh).
   Akibatnya proses `mysqldump` tidak punya `PATH` sama sekali dan gagal
   "command not found" walau mysqldump terpasang benar. Baru ketahuan
   setelah benar-benar dieksekusi — lihat "Cadangan & pemulihan" di bawah.
   Diperbaiki dengan `getenv() + ['MYSQL_PWD' => $dbPass]` (menggabung,
   bukan mengganti). `RestorePos` (baru) memakai pola yang sama sejak awal.
7. Dua bug di test itu sendiri (bukan kode aplikasi): `AuthTest::
   test_logout_revokes_current_token` butuh `Auth::forgetGuards()` di
   antara dua panggilan HTTP simulasi dalam satu method test (guard
   Sanctum meng-cache user yang sudah ter-resolve selama container
   aplikasi masih hidup, dan container itu bertahan sepanjang satu method
   test — bukan bug produksi, karena request sungguhan selalu boot ulang
   aplikasi dari nol). `StockTest::makeVariant()` meng-hardcode SKU yang
   sama untuk setiap pemanggilan, baru menabrak unique constraint saat ada
   test yang memanggilnya dua kali.
8. `ArtistTest::test_code_must_be_unique` gagal karena setup-nya tidak
   mengaktifkan Master, sehingga kuota lisensi Pro (yang memang sengaja
   dicek sebelum validasi lain) menghalangi permintaan kedua sebelum aturan
   unique sempat diuji — bukan validasi unique-nya yang rusak.

**Access control (ditemukan lewat security review, diverifikasi langsung
ke kode sebelum diperbaiki — satu temuan diinvestigasi dan SENGAJA
dibiarkan karena bertentangan dengan test yang sudah ada):**
9. `CashierSessionController::summary()` tidak punya pemeriksaan otorisasi
   sama sekali (celah IDOR) — padahal `close()` di controller yang sama,
   untuk resource yang sama persis, sudah menegakkannya. Ditambal dengan
   guard yang sama: pemilik sesi atau owner/admin.
10. `ReportController::artistSettlements()` tidak punya gerbang owner/
    admin — padahal mengembalikan `payable_amount`/`deduction` per artist
    (data komersial sesensitif laporan profit), dan sibling-nya di
    controller yang sama (`profit()`, `recordSettlementPayment()`) sudah
    menegakkannya, sejalan dengan PRD 7.13 (kasir tidak boleh mengakses
    laporan modal/keuntungan).
11. `ReportController::export()` — `match()` tidak punya cabang `'profit'`
    walau route mengizinkan nilai itu, sehingga selalu jatuh ke `default`
    dan diam-diam menghasilkan file kosong. Diperbaiki, dan `export()`
    sekarang meneruskan galat 403 dari `profit()`/`artistSettlements()`
    apa adanya alih-alih diam-diam mengekspor data kosong (yang kalau
    tidak diperbaiki bisa jadi jalan pintas melewati gerbang akses laporan).
12. `ShipmentController::store()`/`update()` DIPERTIMBANGKAN untuk digerbang
    owner/admin/inventory (PRD 7.11 punya ASSUMPTION soal ongkos kirim
    diinput admin), tapi `PreorderTest::
    test_shipment_can_only_be_created_for_courier_fulfillment` sudah
    menjalankan endpoint ini sebagai kasir dan mengharapkan 409 (bukan
    403) — bukti konkret bahwa akses kasir di sini disengaja, konsisten
    dengan seluruh endpoint preorder lain. **Sengaja tidak diubah.**
13. `StockController::adjust()` sempat dicurigai tidak punya pemeriksaan
    peran — ternyata SUDAH digerbang lewat `StockAdjustmentRequest::
    authorize()` (`canManageMasterData()`), dan sudah ada test yang
    memverifikasinya. Tidak ada perubahan; dicatat di sini supaya jelas
    ini sudah diperiksa, bukan terlewat.

**Sesi 2026-09-01:**
14. `ReportController::artistSettlements()` menghilangkan artist yang belum
    punya penjualan di event tersebut. Penyebabnya di hulu:
    `SettlementService::recalculateForEvent()` membangun baris dari
    `GROUP BY order_items.artist_id`, jadi artist tanpa `order_items` tidak
    pernah punya baris `artist_settlements`, dan laporan hanya membaca
    baris yang ada. Operator karena itu tidak bisa membedakan "artist ini
    belum laku" dari "artist ini tidak ikut event". Diperbaiki di sisi
    LAPORAN (left join seluruh artist aktif ke settlement-nya), bukan
    dengan menyemai baris kosong — supaya `artist_settlements` tetap
    bermakna "catatan status pembayaran ke artist". `id` bernilai `null`
    hanya untuk baris nol itu; `artist_id` selalu terisi dan sekarang
    dipakai sebagai `row-key` tabel rekap di `ReportsView.vue`.
15. Bug laten di endpoint yang sama, ikut tertutup oleh perbaikan #14:
    `$s->artist->name` fatal error bila artist-nya sudah di-soft-delete
    padahal masih punya baris settlement. Sekarang artist terhapus/nonaktif
    tetap dilaporkan selama punya baris settlement di event itu — uangnya
    memang tetap wajib dibayar.
16. `GET /products` tidak pernah bisa memuat varian: `ProductResource`
    sudah mendukungnya lewat `whenLoaded('variants')` tapi `index()` hanya
    eager-load `artist`/`category`. Layar kasir menyiasatinya dengan
    memanggil detail tiap produk satu per satu (N+1). Ditutup lewat opsi
    `?with_variants=1` — opt-in, bukan default, supaya payload layar
    Kelola Produk tidak ikut membengkak.
17. `ProductVariant` menyatakan `use HasFactory` tapi tidak pernah punya
    `ProductVariantFactory`-nya sendiri — setiap test sebelum modul
    vendor/BOM (2026-09-01) membuat varian manual lewat
    `$product->variants()->create([...])`. Test BOM/harga vendor yang
    tidak selalu butuh detail produk induk butuh `ProductVariant::factory()`
    berdiri sendiri, jadi celah itu ditutup di
    `database/factories/ProductVariantFactory.php` alih-alih menduplikasi
    pola manual di setiap test baru. Bukan bug yang berdampak ke produksi
    (tidak ada kode aplikasi yang memanggil factory ini), tapi dicatat
    supaya tidak dikira sengaja dihindari.

## Cadangan & pemulihan (WBS 9.2)

**Dieksekusi sungguhan pada sesi ini — bukan lagi kewajiban yang ditunda.**

```bash
php artisan app:backup                     # buat cadangan baru
php artisan app:restore <path/database.sql>   # pulihkan (MENIMPA data tujuan)
php artisan app:restore <path> --force     # lewati konfirmasi (automasi)
```

Hasil verifikasi: `php artisan app:backup` dijalankan terhadap database
`boothpos` dev, menghasilkan `storage/app/backups/<timestamp>/database.sql`
(dump MySQL penuh, 22 tabel, lengkap dengan `DROP TABLE IF EXISTS` per
tabel) dan `payment-proofs.tar.gz`, keduanya juga tersalin ke
`BACKUP_EXTERNAL_PATH`. Dump tersebut dipulihkan ke database `boothpos_test`
lewat `php artisan app:restore` (perintah baru, simetris dengan
`app:backup`, memakai perbaikan bug #6 di atas sejak awal) — jumlah baris
dan isi data (username, role, key/value pengaturan) dikonfirmasi cocok
persis dengan sumbernya. Arsip `payment-proofs.tar.gz` dikonfirmasi bisa
diekstrak dan isinya cocok dengan lokasi penyimpanan bukti pembayaran yang
sesungguhnya (lihat bug #5). Test suite (120/120) tetap lulus setelah
`boothpos_test` dipulihkan-lalu-di-reset ulang oleh `RefreshDatabase`.

CATATAN LINGKUNGAN — mesin pengembangan sesi ini hanya punya MySQL di
dalam kontainer Docker (`laradock-mysql-1`), tanpa `mysql`/`mysqldump` di
host langsung. Untuk memverifikasi `BackupPos`/`RestorePos` TANPA memasang
software MySQL apa pun secara permanen di host, verifikasi memakai dua
skrip shim sementara (proxy ke `docker exec` container yang sudah ada)
yang HANYA ditaruh di direktori kerja sesi, tidak pernah dikomit, dan
dihapus setelah verifikasi selesai — bukan bagian dari produk. Kode
`BackupPos`/`RestorePos` sendiri TETAP mengasumsikan `mysqldump`/`mysql`
tersedia langsung di `PATH` server tempat BoothPOS berjalan, yang memang
seharusnya benar untuk instalasi toko sungguhan (server lokal dengan MySQL
terpasang normal, bukan di dalam kontainer terpisah). Jangan mengira
pendekatan shim ini sebagai bagian dari mekanisme cadangan produk.

Yang belum dilakukan (di luar cakupan sesi ini, catat sebagai tugas
lanjutan sebelum event pertama): uji pemulihan dari media eksternal
sungguhan (flashdisk/HDD nyata, bukan direktori lokal pengganti), dan
penjadwalan otomatis harian (`routes/console.php` belum berisi
`Schedule::command('app:backup')` — WBS 9.1 menyebut "cadangan berjalan
terjadwal", tapi penjadwalan konkretnya belum ditambahkan di sesi ini).

## Urutan migration penting

File migration diberi prefix tanggal untuk memastikan urutan foreign key
benar. Jangan mengubah urutan nama file. Satu migration (`orders_and_payments`)
sengaja membuat kolom `payments.preorder_id` TANPA constrained(), karena
tabel `preorders` belum ada di titik itu — constraint-nya ditambahkan di
migration `preorders_tables` lewat `Schema::table('payments', ...)`.

Dua migration ditambahkan pada sesi bootstrap ini, mengikuti aturan yang
sama:
- `2026_09_30_000001_create_personal_access_tokens_table` (Sanctum) —
  diberi tanggal SEBELUM `2026_10_01_000000_create_users_table` mengikuti
  konvensi "tabel infrastruktur duluan"; aman di posisi manapun karena
  kolomnya polimorfik (morphs), tidak ada FK keras ke `users`.
- `2026_10_06_000001_create_activity_logs_table` (F13.4) — ditambahkan di
  AKHIR urutan (setelah `preorders_tables`), bukan di dekat `settings` yang
  disarankan `docs/schema-pos-mvp.sql`, karena satu-satunya dependensi
  tabel ini adalah `users` yang sudah ada sejak awal, dan menambah file di
  akhir tidak menyisipkan apa pun di tengah urutan yang sudah teruji.

## Gap yang diketahui

1. Frontend Vue (POS screen, webcam, katalog cetak) — di luar cakupan
   backend, belum dibangun.
2. Koleksi Bruno belum dijalankan sungguhan lewat aplikasi Bruno (lihat
   "Koleksi Bruno" di atas) — API-nya sendiri sudah terverifikasi lewat
   120 test otomatis dan pemanggilan manual.
3. Penjadwalan otomatis `app:backup` (`Schedule::command` di
   `routes/console.php`) belum ditambahkan — perintahnya sudah ada dan
   terverifikasi jalan, tapi belum dipicu otomatis harian.
4. Uji pemulihan dari media eksternal FISIK (flashdisk/HDD sungguhan)
   belum dilakukan — verifikasi sesi ini memakai direktori lokal sebagai
   pengganti `BACKUP_EXTERNAL_PATH`.
5. `docs/schema-pos-mvp.sql` belum diperbarui untuk mencerminkan
   penyimpangan yang sudah didokumentasikan di migration
   `payment_proofs` sendiri (kolom `proof_token`, `payment_id` nullable)
   — gap dokumentasi lama, bukan baru, dicatat ulang di sini karena masih
   berlaku.
6. Guard "hapus artist/kategori dengan produk aktif" — SUDAH diverifikasi
   lulus lewat `ArtistDeleteGuardTest`/`CategoryDeleteGuardTest` pada sesi
   ini (bagian dari 120 test yang lulus), menutup gap yang disebutkan
   versi README sebelumnya.
