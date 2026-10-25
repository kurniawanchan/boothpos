# Research: Pengisian awal katalog Odoo WJM

**Klasifikasi**: INTERNAL

**Fitur**: [spec.md](./spec.md) · **Tanggal**: 2026-10-24

Semua keputusan di bawah berdasar pembacaan kode di cabang ini. Rujukan berkas memakai jalur relatif repo.

## D1 — Titik masuk: perintah Artisan, ditambah seeder tipis

- **Decision**: Logika di `app/Services/WjmCatalogSeedService.php`, dengan dua pemanggil tipis:
  - `php artisan app:seed-wjm-catalog` dengan opsi `--mode`, `--outlet`, `--dry-run`, `--catalog`, dan `--force`.
  - `php artisan db:seed --class=WjmCatalogSeeder`, yang mengisi kedua mode dengan aturan bawaan.

  Keduanya tidak didaftarkan di `DatabaseSeeder` (FR-001).
- **Rationale**: Pengguna meminta "data seeder", tetapi FR-002/003/022 butuh opsi (pilih mode, pilih outlet, pratinjau), dan `db:seed` tidak bisa menerima opsi khusus. Satu service dengan dua pemanggil memenuhi Konstitusi I. Penamaan mengikuti perintah yang ada (`app:backup`, `app:restore`, `app:business-date-dry-run`).
- **Alternatives considered**:
  - Hanya seeder, dengan opsi lewat variabel lingkungan: rapuh dan tidak terdokumentasi di `--help`.
  - Hanya perintah: tidak menjawab kata "seeder" di permintaan; biaya seeder tipis ±20 baris.

## D2 — Sumber data: `catalog.json` yang dibangkitkan dari tabel pemetaan yang disetujui

- **Decision**: Pengisian membaca `database/seeders/data/wjm-odoo/catalog.json` beserta `database/seeders/data/wjm-odoo/photos/*.jpg` (57 berkas unik, dinamai hash isi). Berkas ini dibangkitkan oleh alat khusus pengembang, `specs/068-odoo-catalog-seeder/tools/build_catalog.py` (Python 3, pustaka standar saja, dijalankan dengan `python3 -I`). Masukan alat itu adalah tiga ekspor XLSX (di luar repo) dan keputusan di `catalog-mapping.md`.
  - `catalog-mapping.md` adalah sumber keputusan yang dibaca manusia dan disetujui pemilik.
  - `catalog.json` adalah artefak hasil bangkitan yang di-commit.
- **Rationale**:
  - FR-020 melarang berkas ekspor mentah (yang memuat nama staf) disimpan bersama kode. Alat membuang kolom "Responsible" dan kolom internal Odoo.
  - Dokumen yang disetujui pemilik menjadi satu-satunya tempat keputusan, sehingga JSON tidak bisa diam-diam melenceng: setelah jawaban pemilik, ubah markdown lalu bangkitkan ulang.
  - JSON netral bahasa, mudah ditinjau di diff, dan dibaca PHP tanpa dependensi.
- **Alternatives considered**:
  - Seeder membaca XLSX langsung saat berjalan: melanggar FR-020, dan nama staf ikut terbawa.
  - JSON disunting tangan sebagai sumber: dua dokumen (markdown untuk pemilik, JSON untuk sistem) mudah tidak sinkron.
  - Larik PHP di dalam seeder (gaya `WarkopMenuDemoSeeder`): 79 item × varian × foto terlalu besar untuk konstanta, dan sulit dibangkitkan ulang.
  - Alat ekstraksi sebagai perintah Artisan: menambah kode aplikasi permanen untuk pekerjaan sekali jalan.

## D3 — Jalur tulis: pakai service yang sudah sah, bukan `MasterDataImportService`

- **Decision**: Setiap jenis data ditulis lewat jalur yang sudah ada:

  | Data | Jalur |
  |---|---|
  | Kode produk/SKU | `ProductCodeGenerator::buildCodePrefix()`, `nextVariantSku()` |
  | Riwayat harga awal | hook `ProductVariant::created` → `PriceService::recordCreated()` |
  | Saldo outlet varian | hook `ProductVariant::created` → `OutletStockSync::variantCreated()` |
  | `published_at` | hook `Product::creating` (item aktif langsung terbit) |
  | Grup pilihan | `ModifierGroupService::create()` dan `setAttachments()` |
  | Outlet yang menjual | `OutletMenuService::setCarried()` |
  | Foto | `ImageUploadService` (cek MIME dari isi berkas, batas 5 MB, nama acak, disk `public`) |
  | Log aktivitas | `ActivityLogger::log()` (user boleh `null`) |
  | Stok | tidak ditulis sama sekali |

- **Rationale**: Konstitusi I: tidak ada jalur kedua. Pembuatan produk di `ProductController::store()` dan `MasterDataImportService` juga mengisi `sell_price` saat create, sementara riwayatnya dicatat hook, jadi pola ini sah.
- **Alternatives considered**: Menyusun berkas XLSX lalu memanggil `MasterDataImportService::import()`. Ditolak karena:
  - butuh `User` (CLI tidak punya);
  - mencocokkan baris lewat SKU dan meng-*upsert*, padahal FR-015/016 meminta cocok lewat nama+kategori dan tidak mengubah apa pun;
  - tidak punya sheet grup pilihan, stasiun, maupun `track_stock`, sehingga butuh tulisan susulan.

## D4 — Kode kategori dan segmen produk: dialokasikan di `ProductCodeGenerator`

- **Decision**: `ProductCodeGenerator` ditambah dua metode:
  - `freeCategoryCode(string $name): string`. Kata adalah deret huruf; tanda seperti "&" diabaikan. Kandidat berurutan: huruf pertama dua kata pertama (satu kata → dua huruf pertamanya: "Coffee" → `CO`, "Mie & Pangsit Level" → `MP`), lalu huruf pertama + `1`…`9`, lalu huruf pertama + `A`…`Z`, lalu pindai `AA`…`Z9`. Kode dicek lintas mode dan termasuk yang terhapus (`withTrashed()->withoutGlobalScopes()`), karena `categories.code` unik di seluruh tabel.
  - `segmentCandidates(string $name): list<string>`. Kandidat berurutan:
    1. inisial tiga kata pertama; bila kurang dari tiga kata, sisanya diisi huruf berikutnya dari kata terakhir ("Dimsum Isi 4" → `DIS`, "Basreng" → `BAS`);
    2. huruf pertama kata pertama + dua huruf pertama kata terakhir ("Roti Bakar Coklat" → `RCO`);
    3. tiga huruf pertama (sama dengan `deriveSegmentFromName()`);
    4. dua huruf pertama + `A`…`Z`.

    Dengan urutan item katalog, 10 item "Roti/Pisang Bakar …" mendapat `PBC`, `PKE`, `PBK`, `RBB`, `RBC`, `RCO`, `RKE`, `RBK`, `RBS`, `RBT`.
  - `firstFreeCodePrefix(string $outletCode, string $categoryCode, string $name): array{string, string}` mengembalikan [segmen, code_prefix] pertama yang bebas. Pengecekannya sama dengan `buildCodePrefix()` (private `prefixTaken()` dipakai bersama).

  Service memakai kandidat segmen pertama yang membuat `buildCodePrefix()` tidak bertabrakan (cek lintas mode, termasuk terhapus). Bila mode LIVE dan DEMO diisi bersamaan, LIVE diproses lebih dulu agar mendapat kode inisial yang rapi.
- **Rationale**:
  - FR-014: 7 item "Roti Bakar …" semuanya menghasilkan `ROT` dengan aturan lama, dan `buildCodePrefix()` sengaja melempar galat bila bertabrakan (F19.5). Alokasi deterministik membuat masukan dan keadaan DB yang sama menghasilkan kode yang sama.
  - Logika kode tetap di satu kelas (Konstitusi I).
- **Alternatives considered**:
  - Kode dihitung di alat Python lalu disimpan di JSON: dua implementasi aturan kode (Python dan PHP), dan tidak tahu kode yang sudah ada di DB tujuan.
  - Menambah sufiks angka pada segmen: bertentangan dengan konvensi segmen huruf.

## D5 — Outlet tujuan, dan pembuatan outlet bila mode kosong

- **Decision**: Urutan resolusi (FR-003):
  1. `--outlet=KODE`, harus aktif di mode itu. Opsi ini hanya sah bersama satu `--mode`, karena kode outlet unik lintas mode.
  2. Outlet bernama persis "Warkop WJM 3" (tanpa peka huruf besar/kecil).
  3. Satu-satunya outlet aktif.
  4. Mode tanpa outlet sama sekali: buat "Warkop WJM 3" setelah `LicenseGate::canCreateOutlet()`. Kode outlet: `WJ3`, lalu `WJM`, lalu `WJ` + `A`…`Z`/`0`…`9` pertama yang bebas lintas mode, termasuk yang terhapus.
  5. Selain itu: mode berhenti tanpa perubahan dan menampilkan daftar outlet aktif.

  Stasiun "Bar" dan "Dapur" yang belum ada di outlet tujuan dibuat.
- **Rationale**: Di DB dev, DEMO sudah punya `WJ3` "Warkop WJM 3", sedangkan LIVE belum punya outlet. `outlets.code` unik lintas mode, jadi LIVE tidak bisa memakai `WJ3` dan jatuh ke `WJM`. Membuat outlet pertama tidak butuh lisensi Master.
- **Alternatives considered**: Selalu membuat outlet baru (bisa membentur lisensi dan menggandakan outlet), atau selalu wajib `--outlet` (merepotkan untuk instalasi satu outlet).

## D6 — Atribut terjaga: service tidak boleh bergantung pada mode *unguarded*

- **Decision**: Service mengisi atribut di luar `$fillable` secara eksplisit, misalnya `$station->outlet_id = $outlet->id` sebelum `save()`. `BelongsToOutlet` menghormati nilai yang sudah di-set. Mode data datang dari `ModeGate::runAs()`, bukan dari input.
- **Rationale**: `db:seed` menjalankan seeder di dalam `Model::unguarded()`, tetapi perintah Artisan tidak. `WarkopMenuDemoSeeder` bisa mengoper `outlet_id` lewat `firstOrCreate` hanya karena *unguarded*. Lewat perintah, nilai itu dibuang diam-diam, lalu `OutletDefaults::forNewRecord()` menebak outlet. Tes menjalankan kedua pemanggil untuk memastikan hasilnya sama.
- **Alternatives considered**: `Model::unguarded()` di dalam service: membuka semua atribut, termasuk yang sengaja dikunci (`published_at`).

## D7 — Pencocokan dan jalan ulang

- **Decision**: Semua pencocokan berlaku di dalam mode yang diisi (cakupan `DataModeScope`):
  - **Kategori**: cocok bila namanya persis sama.
  - **Item**: cocok bila nama persis sama dan nama kategorinya sama, termasuk item yang sudah dihapus pemilik (dicatat "dihapus pemilik — tidak dibuat ulang").
  - **Stasiun**: cocok bila outlet dan namanya sama.
  - **Grup pilihan**: cocok bila namanya sama dan himpunan opsi aktifnya identik. Bila nama sama tetapi opsinya beda, buat grup baru bernama "Rasa (WJM)", atau "Rasa (WJM 2)" bila nama itu pun sudah dipakai dengan opsi lain.

  Apa pun yang cocok dilewati utuh: varian, foto, tautan grup, dan harganya tidak disentuh (FR-016). Nama yang sudah ada dimuat sekali per mode, tidak dicek per item.
- **Rationale**: Hanya kode yang unik di DB; nama tidak. Item dari pengisian sebelumnya, maupun buatan pemilik, harus dikenali tanpa penanda khusus. Item yang dihapus pemilik tidak dihidupkan kembali.
- **Alternatives considered**:
  - Kolom penanda sumber (mis. `source_ref`): butuh migrasi, padahal FR tidak memintanya.
  - Mencocokkan lewat kode: kode bergantung pada outlet dan urutan alokasi, jadi tidak stabil antar instalasi.

## D8 — Transaksi per mode, dan foto

- **Decision**: Setiap mode dijalankan sebagai satu `DB::transaction` di dalam `ModeGate::runAs($mode, …)`. Transaksi bersarang di `ModifierGroupService` dan `OutletMenuService` menjadi savepoint. Entri `ActivityLogger` ditulis di dalam transaksi yang sama (Konstitusi IV).
  - Foto ditulis lewat metode baru `ImageUploadService::storeLocalFile(string $absolutePath, string $directory)`. Metode ini membungkus berkas lokal sebagai `UploadedFile` (mode tes Symfony, berkas sumber tidak dipindah) lalu mendelegasikan ke `store()`, sehingga aturan MIME, ukuran, dan nama acak tetap satu.
  - Jalur berkas yang ditulis dicatat. Bila transaksi gagal, berkas-berkas itu dihapus lewat `ImageUploadService::delete()`, lalu galat dilempar ulang.
  - Tiap produk mendapat salinan fotonya sendiri, per mode.
- **Rationale**: FR-018 (semua-atau-tidak-sama-sekali) dan FR-019. Berkas tidak ikut transaksi DB, sehingga perlu pembersihan eksplisit. Salinan per produk mengikuti perilaku unggah biasa: menghapus foto satu item tidak merusak item lain.
- **Alternatives considered**:
  - Nama berkas berbasis hash yang dipakai bersama: menyimpang dari pola nama acak `ImageUploadService`, dan menghapus satu item akan memutus item lain.
  - Menulis foto setelah commit: kegagalan setelah commit meninggalkan item yang merujuk berkas hilang.

## D9 — Pratinjau

- **Decision**: `--dry-run` menjalankan jalur yang sama di dalam `DB::beginTransaction()` lalu `rollBack()`, tanpa memanggil `ImageUploadService`. Foto dihitung sebagai "akan ditulis". Pratinjau boleh dijalankan pada katalog berstatus draf, dengan peringatan bahwa pengisian sungguhan akan ditolak.
- **Rationale**: Laporan pratinjau dijamin sama dengan hasil nyata, karena alokasi kode dan pencocokan bergantung pada keadaan DB. Satu jalur kode berarti tidak ada simulasi kedua yang bisa melenceng.
- **Alternatives considered**: Menghitung rencana tanpa menulis ke DB: alokasi kode dan pencocokan harus diduplikasi.

## D10 — Validasi katalog dan persetujuan

- **Decision**: `app/Support/WjmCatalog.php` memuat JSON dan mengumpulkan **semua** masalah sebelum ada penulisan apa pun:
  - struktur dan jenis data;
  - `approval.status` harus `approved`, lengkap dengan `approved_on` dan `approved_by_role`;
  - setiap item merujuk kategori yang ada;
  - nilai pada varian ada di atribut sumber;
  - jumlah varian = `odoo_variant_count`, kecuali `variant_count_override` diisi beserta alasannya;
  - harga bilangan bulat ≥ 0;
  - pasangan (kategori, nama) unik;
  - grup pilihan berasal dari atribut "opsi saja", dan tautannya merujuk item yang ada;
  - foto ada di disk dan ≤ 5 MB;
  - jumlah baris sumber terpertanggungjawabkan: 79 item + 1 produk dikecualikan = 80.

  Ada masalah → semua mode ditolak sebelum menulis apa pun (FR-007, FR-023). Pratinjau tetap menampilkan masalah itu.
- **Rationale**: Pemetaan bersifat inferensi. Pemeriksaan jumlah varian terhadap Odoo menangkap salah ketik, atau keputusan pemilik yang belum dicatat.
- **Alternatives considered**: Pustaka JSON Schema: dependensi baru. `contracts/catalog.schema.json` tetap ditulis sebagai kontrak dokumentasi, dan tes memastikan katalog sungguhan lolos validator PHP.

## D11 — Laporan dan kode keluar

- **Decision**: Service mengembalikan laporan per mode, berisi:
  - status: `applied`, `previewed`, `refused`, atau `failed`;
  - outlet tujuan, dan apakah outlet itu dibuat;
  - jumlah dibuat dan sudah ada, per kategori, item, varian, grup, stasiun, dan foto;
  - daftar baris dikecualikan, item tanpa foto (terpotong/kosong), item berharga 0, grup yang diganti nama, item kit/BoM, item contoh yang ditarik, dan masalah validasi;
  - baris pertanggungjawaban sumber (SC-005).

  Perintah mencetak laporan dengan `table()`. Kode keluar 0 bila semua mode yang diminta `applied`/`previewed`, selain itu 1.
- **Rationale**: FR-021. Kode keluar memungkinkan skrip penyiapan berhenti bila gagal.
- **Alternatives considered**: Menulis laporan ke berkas: tidak diminta; keluaran bisa dialihkan (`> laporan.txt`).

## D12 — Stok, harga pokok, BoM, dan grup "Rasa"

- **Decision**:
  - Varian dibuat dengan `track_stock = false` secara eksplisit, karena bawaan model dan DB `true`, dengan `cost_price = 0` dan tanpa pergerakan stok.
  - Item kit/BoM dibuat sebagai item biasa dan hanya ditandai di laporan.
  - Grup "Rasa": `selection_type = single`, wajib (min 1, maks 1, meniru tampilan *radio* Odoo), tanpa tambahan harga, belum ditautkan ke item mana pun.
- **Rationale**: FR-012/013 dan asumsi spesifikasi. `StockService` tidak dipanggil, sehingga `StockLedgerInvariantTest` tidak terpengaruh.
- **Alternatives considered**: Grup "Rasa" opsional (min 0). Bisa diubah pemilik bila nanti ditautkan.

## D13 — Pengaman produksi

- **Decision**: Perintah memakai `ConfirmableTrait`. Bila `APP_ENV=production` dan bukan pratinjau, operator diminta konfirmasi kecuali memberi `--force`. Seeder tipis tidak meminta konfirmasi, karena `db:seed` di produksi sudah punya pengaman `--force` sendiri.
- **Rationale**: Data LIVE adalah data produksi pelanggan. Konvensi ini sama dengan `migrate`/`db:seed` Laravel.
- **Alternatives considered**: Tanpa konfirmasi: satu salah ketik `--mode` langsung menulis ke LIVE.

## D14 — Dokumentasi dan cakupan

- **Decision**: Tambah bagian di `docs/RUNBOOK.md` (pratinjau → isi → periksa), catatan bertanggal di `CLAUDE.md` (aturan domain: satu pengisi katalog WJM, sumber `catalog.json`), dan blok SPECKIT diarahkan ke rencana ini. `docs/openapi-pos-mvp.yaml` tidak berubah, karena tidak ada rute baru. PRD juga tidak berubah: ini perkakas data operasional khusus pelanggan, seperti `SakanaFridgeDemoSeeder`, bukan kapabilitas produk.
- **Rationale**: Disiplin dokumentasi di konstitusi.
- **Alternatives considered**: Catatan PRD bertanggal: tidak tepat, karena tidak ada perubahan cakupan produk.
