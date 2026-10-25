---
description: "Task list — 069 pengisian katalog Odoo Warkop WJM 2"
---

# Tasks: Pengisian katalog Odoo Warkop WJM 2 ke mode LIVE dan DEMO

**Klasifikasi**: CONFIDENTIAL — merujuk katalog nyata milik pelanggan.

**Input**: dokumen desain di `specs/069-wjm2-catalog-seeder/` (plan.md, spec.md, research.md D1–D14, data-model.md, contracts/, quickstart.md)

**Prerequisites**: pekerjaan 068 di working tree (belum di-commit) — `WjmCatalogSeedService`, `WjmCatalog`, `build_catalog.py`, `initial_stock`.

**Tests**: WAJIB (Konstitusi II, TDD). Setiap pasangan tes → implementasi: tes ditulis dulu dan harus terbukti gagal. Semua perintah tes:

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=<Nama>
```

**Organization**: per user story spec.md. US1 dan US2 sama-sama P1; US2 adalah larangan yang harus dibuktikan bersama US1.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: dapat dikerjakan paralel (berkas berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- **[Story]**: US1–US4 sesuai spec.md

---

## Phase 1: Setup (data dan fixture)

**Purpose**: tabel pemetaan WJM 2 (draf), baris format 2 untuk WJM 3, dan katalog mini outlet kedua.

- [X] T001 Buat `specs/069-wjm2-catalog-seeder/catalog-mapping.md` (CONFIDENTIAL, status `DRAF`) dari `source-analysis.md`, dengan sintaks data-model §1:
  - **Baris kepala**: `**Outlet**: Warkop WJM 2 · **Kode usulan**: WJ2, WD2 · **Buat outlet**: DEMO bila belum ada`, `**Tarik item lain dari menu outlet**: LIVE ya, DEMO ya`, `**Stasiun**: Bar = Bar; Dapur = Dapur`, `**Stok awal**: 100`.
  - **Kategori**: satu bagian `###` per 13 kategori sumber. Sembilan kategori setara dipetakan ke nama WJM 3 (`### COFFEE → Coffee (Bar)`, `### NON-COFFEE → Non Coffee (Bar)`, `### SNACKS → Snacks (Dapur)`, `### TEA → Tea (Bar)`, `### ADD ON → Add on (Dapur)`, `### MIE & PANGSIT LEVEL → Mie & Pangsit Level (Dapur)`, `### NASI → Nasi (Dapur)`, `### ROTI & PISANG → Roti & Pisang (Dapur)`, `### SIGNATURE COFFEE → Signature Coffee (Bar)`). Kategori yang tidak mendapat item ditandai `— tanpa item`.
  - **Item**: tabel `| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |` untuk ke-80 item, di kategori usulan (inferensi dari nama, ditandai Yakin T/S/R).
  - **Varian**: 22 item `Varian` → `Varian: Hot, Ice`; Indomie Goreng/Kuah/Double → rasa seperti WJM 3; JASJUS ×4, MINUMAN DINGIN SHOWCASE ×19, MIE SEDAP KUAH ×5, MIE SEDAP DOUBLE ×6 → `?`.
  - **`## Nilai atribut`**: `Varian` = Hot, Ice; `VARIANT INDOMIE` = rasa usulan; atribut lain `?`.
  - **`## Pertanyaan untuk pemilik`**: nama varian `?`, tiga item Rp0, kategori ragu, gaya nama (kapital/judul), dan nama stasiun.
  - Jangan menulis nama staf.
- [X] T002 [P] Tambahkan ke `specs/068-odoo-catalog-seeder/catalog-mapping.md`, tepat di bawah baris persetujuan: `**Outlet**: Warkop WJM 3 · **Kode usulan**: WJ3, WJM · **Buat outlet**: mode kosong`, `**Tarik item lain dari menu outlet**: LIVE tidak, DEMO ya`, `**Stasiun**: Bar = Bar, Dapur Minuman; Dapur = Dapur, Dapur Makanan`. Ini mempertahankan perilaku 068 (research D6, D8, D9). Tambahkan juga catatan bertanggal 2026-10-25 yang menjelaskan baris-baris ini.
- [X] T003 [P] Buat katalog mini kedua `tests/Support/wjm2-catalog/catalog.json` (format 2, status `approved`), dengan foto memakai ulang JPEG katalog mini pertama (salin ke `tests/Support/wjm2-catalog/photos/`):
  - `outlet` = `{name: "Warkop Uji 2", codes: ["WU2"], create_when: "demo_missing"}`, `withdraw_others` = `{live: true, demo: true}`, `stations` = `{Bar: ["Bar"], Dapur: ["Dapur"]}`, `initial_stock` = 100.
  - Kategori: "Kopi Uji" (sama persis dengan katalog mini pertama → dipakai bersama, `source_name` "KOPI UJI") dan "Camilan Uji 2" (baru, Dapur); satu kategori dikecualikan "MINUMAN KOSONG" (tanpa item).
  - Item: "KOPI UJI" (Kopi Uji, 9000, `Suhu: Hot, Ice`; namanya bentrok tanpa membedakan huruf besar-kecil dengan "Kopi Uji" milik outlet pertama), "Keripik Uji" (Camilan Uji 2, 5000, Standard, foto), "Jus Uji" (Kopi Uji, 7000, `Buah Uji: Jambu, Mangga`, nilai atribut `values_from: "pemetaan"`).
  - `source.rows` sesuai isinya.

**Checkpoint**: data siap; belum ada kode yang berubah.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: format katalog 2 (alat bangkit + validator), resolusi outlet (D8), dan peran stasiun (D6). Fase ini memblokir semua cerita, karena tanpanya katalog WJM 2 bisa masuk ke outlet WJM 3.

**⚠️ CRITICAL**: tes ditulis dulu dan harus gagal.

### Alat bangkit (Python, diverifikasi lewat keluaran dan diff)

- [X] T004 Perluas `specs/068-odoo-catalog-seeder/tools/build_catalog.py` (research D2):
  - cari berkas menurut akhiran model `(pos.category).xlsx`, `(product.attribute).xlsx`, `(product.template).xlsx`; galat bila tidak ada tepat satu per model;
  - tabel alias kolom EN/ID: `Display Name`/`Nama Tampilan`, `Attribute`/`Atribut`, `Values`, `Variants Creation Mode`/`Mode Pembuatan Varian`, `Name`/`Nama`, `Internal Reference`/`Referensi Internal`, `Sales Price`/`Harga Jual`, `# Product Variants`/`# Varian Produk`, `Image`/`Gambar 1024`, `Is Kits`, `# Bill of Material`; kolom yang tidak ada = kosong;
  - pesan foto terpotong EN dan ID;
  - mode "opsi saja" EN `Never (option)` dan padanan ID (cari teks yang mengandung `option`/`opsi`, ditambah tes manual pada ekspor WJM 3);
  - jangan pernah membaca kolom `Responsible`/`Penanggung Jawab`.
- [X] T005 Perluas parser tabel pemetaan di `build_catalog.py` (research D3, D4, D6, D8, D9, D12; data-model §1):
  - baris `**Outlet**`, `**Tarik item lain dari menu outlet**`, `**Stasiun**`;
  - judul `### SUMBER → Tujuan (Bar|Dapur)` dan `— tanpa item`;
  - bagian `## Nilai atribut` dan `## Nama item khusus`;
  - `?` di nama varian: nama sementara `(belum diisi n)` + daftar di ringkasan saat DRAF; galat saat Disetujui;
  - perapian spasi berlebih di nama item/atribut, dicatat di ringkasan "dirapikan".
- [X] T006 Ubah keluaran `build_catalog.py` ke format 2 (data-model §2):
  - `format: 2`; `outlet`, `withdraw_others`, `stations` menggantikan `outlet_name`; hapus argumen `--outlet-name`;
  - `attributes[].values_from`, `categories[].source_name`;
  - `excluded[]` dengan `source: "categories"`;
  - pertanggungjawaban: `source.rows.attribute_values` hanya menghitung nilai `odoo`.
- [X] T007 Bangkitkan ulang `database/seeders/data/wjm-odoo/catalog.json` (WJM 3) dengan alat baru. Bandingkan dengan versi sebelumnya (salinan di scratchpad): perbedaan hanya boleh di `format`, `outlet`, `withdraw_others`, `stations`, `values_from`, dan `source_name`. Item, varian, harga, foto, dan `initial_stock` harus identik. Catat hasil diff di `specs/069-wjm2-catalog-seeder/evidence.md` §1.
- [X] T008 Bangkitkan `database/seeders/data/wjm2-odoo/catalog.json` + `photos/` dari `"/Users/chan/Downloads/WJM 2"` dan `specs/069-wjm2-catalog-seeder/catalog-mapping.md` (draf). Hitungan yang diharapkan: 80 item, 141 varian, 11 foto, `approval.status = draft`, nama varian sementara terdaftar. `grep -ri "penanggung\|responsible" database/seeders/data/wjm2-odoo` harus kosong. Catat di `evidence.md` §1.

### Validator format 2

- [X] T009 Tulis tes dulu di `tests/Feature/WjmCatalogFileTest.php`:
  - katalog WJM 3 dan katalog mini pertama sah dalam format 2;
  - katalog WJM 2 sah secara struktur (boleh draf);
  - kasus rusak baru: `format` 1, `outlet.create_when` tak dikenal, kode outlet bukan 3 huruf/angka, `stations` kosong atau satu nama di dua peran, `withdraw_others` bukan boolean, `values_from` tak dikenal, `outlet_name` (kunci lama) ditolak;
  - katalog mini kedua (T003) sah;
  - pertanggungjawaban: kategori sumber = dipakai + dikecualikan; nilai atribut hanya menghitung `odoo`.
  Jalankan dan pastikan gagal.
- [X] T010 Implementasikan format 2 di `app/Support/WjmCatalog.php`:
  - `ROOT_KEYS` baru; `format === 2`;
  - accessor `outlet()`, `stations()`, `withdrawOthers(string $mode)`; hapus `outletName()` dan perbarui pemanggilnya;
  - validasi `values_from`, `source_name`, dan `excluded` `categories`;
  - pertanggungjawaban baru.
  Ubah `tests/Support/wjm-catalog/catalog.json` ke format 2 (outlet "Warkop WJM 3", `mode_empty`, kode `["WJ3","WJM"]`; `withdraw_others` `{live:false, demo:true}`; `stations` `{Bar:["Bar"], Dapur:["Dapur"]}`). Perbarui `contracts/catalog.schema.json` 068 dengan rujukan ke skema 069. Jalankan T009 hingga lulus, lalu semua tes `WjmCatalog*` untuk memastikan perilaku 068 tidak berubah.

### Resolusi outlet (D8)

- [X] T011 [P] Tulis `tests/Feature/WjmCatalogOutletResolutionTest.php` (katalog mini kedua):
  - (a) LIVE tanpa outlet "Warkop Uji 2" tetapi dengan satu outlet aktif lain → `refused`, pesan menyebut nama outlet dan layar Outlet, `rowCounts()` tidak berubah;
  - (b) DEMO tanpa outlet itu, dengan satu outlet aktif lain → outlet dibuat dengan kode `WU2`, dan outlet lain tidak dipakai;
  - (c) kode `WU2` sudah dipakai (di mode mana pun) → kode bebas berikutnya `WJA…`;
  - (d) dua outlet bernama sama → `refused`;
  - (e) `--outlet=KODE` tetap mengesampingkan nama;
  - (f) katalog mini pertama (`mode_empty`) tetap berperilaku seperti 068: satu-satunya outlet aktif dipakai; mode kosong → outlet dibuat dengan `WJ3`.
  Pastikan gagal.
- [X] T012 Implementasikan D8 di `app/Services/WjmCatalogSeedService.php` (`resolveOutlet`, `createOutlet`): kebijakan dari `$catalog->outlet()`, kode dari `outlet.codes` (hapus konstanta `OUTLET_CODES`), dan tanpa jatuh ke satu-satunya outlet aktif untuk `demo_missing`. Jalankan T011 dan semua tes `WjmCatalog*`.

### Peran stasiun (D6)

- [X] T013 [P] Tulis `tests/Feature/WjmCatalogStationRoleTest.php`:
  - (a) outlet tanpa stasiun → dua stasiun dibuat dengan nama pertama tiap peran;
  - (b) outlet dengan stasiun "Dapur Minuman"/"Dapur Makanan" dan katalog yang menyebut alias itu → dipakai, tidak ada stasiun baru;
  - (c) outlet dengan stasiun yang tidak dikenali katalog → mode `refused`, pesan menyebut stasiun yang ada, tanpa perubahan;
  - (d) jalan ulang tidak menambah stasiun.
  Pastikan gagal.
- [X] T014 Implementasikan D6 di `WjmCatalogSeedService::ensureStations()`: cocokkan peran menurut `$catalog->stations()`, buat hanya bila outlet belum punya stasiun aktif, dan tolak (`DomainException`) bila sebuah peran tidak cocok. Hapus konstanta `STATIONS`. Jalankan T013 dan semua tes `WjmCatalog*`.

**Checkpoint**: format 2 hidup; katalog WJM 2 tidak mungkin masuk ke outlet lain; stasiun yang diganti nama tidak digandakan.

---

## Phase 3: User Story 1 — Outlet WJM 2 siap berjualan di LIVE (Priority: P1) 🎯 MVP

**Goal**: outlet WJD di LIVE berisi 80 item / 141 varian WJM 2 dengan harga, foto, dan stok 100, dan tiketnya masuk ke stasiun WJD.

**Independent Test**: katalog mini pertama sudah diisi ke outlet A (LIVE); outlet "Warkop Uji 2" ada. Isi katalog mini kedua → semua item ada, milik outlet B, dan hanya dijual di B; stok 100 di B; pesanan item B di outlet B → tiket di stasiun B.

### Tests for User Story 1 ⚠️

- [X] T015 [P] [US1] Tulis `tests/Feature/WjmCatalogSecondOutletTest.php`, bagian LIVE. Persiapan: isi katalog mini pertama ke outlet A, lalu buat outlet "Warkop Uji 2" (B) lewat factory. Kasus:
  - (a) katalog kedua → 3 item milik B (`home_outlet_id`), termasuk "Kopi Uji" walaupun item bernama + berkategori sama milik A ada (D7); laporan item "Dibuat" = 3, "Sudah ada" = 0;
  - (b) item B dijual hanya di B (`OutletMenuService::isCarried`);
  - (c) `products.station_id` setiap item B = stasiun B sesuai peran kategorinya; kategori bersama "Kopi Uji" tetap menunjuk stasiun A (D5);
  - (d) setiap varian B: `track_stock`, saldo 100 di B, satu gerakan `initial`;
  - (e) "Jus Uji" bervarian Jambu/Mangga (nilai dari pemetaan);
  - (f) kategori "Camilan Uji 2" dibuat dengan stasiun Dapur B; "MINUMAN KOSONG" tidak dibuat dan muncul di daftar dikecualikan laporan.
  Pastikan gagal.
- [X] T016 [P] [US1] Tambahkan ke tes dapur yang ada (cari tes `KitchenService`/kirim tab di `tests/Feature/`; bila tidak ada, buat `tests/Feature/WjmCatalogKitchenRoutingTest.php`): pesanan item B di outlet B dengan kategori bersama yang menunjuk stasiun A → tiket di stasiun B. Pastikan gagal sebelum T018.

### Implementation for User Story 1

- [X] T017 [US1] Implementasikan D7 di `WjmCatalogSeedService::createItems()`: pencocokan "sudah ada" hanya untuk `home_outlet_id` = outlet tujuan (tetap `withTrashed`, nama + kategori). Pastikan jalan ulang dan tes "item dihapus/diganti nama" 068 tetap lulus.
- [X] T018 [US1] Implementasikan D5 di `createItems()`: isi `station_id` produk baru = stasiun peran kategori dari katalog, diisi eksplisit (bukan `$fillable`) agar hasilnya sama saat *guarded* dan *unguarded*. Kategori baru tetap mendapat `station_id` stasiun outlet tujuan; kategori yang sudah ada tidak diubah.
- [X] T019 [US1] `ensureCategories()`: pakai `categories[].name` (nama tujuan) untuk pencocokan dan pembuatan; kategori `excluded` tidak dibuat.
- [X] T020 [US1] `app/Support/WjmCatalogSeedReport.php` + cetakan konsol: baris "Stok awal" (jumlah varian yang mendapat gerakan `initial`), "Kategori dikecualikan", dan "Nilai atribut dari tabel pemetaan" (contracts/cli.md §4). Log `catalog_seeded` mendapat `new_values.catalog` (nama folder katalog).
- [X] T021 [P] [US1] Buat `database/seeders/Wjm2CatalogSeeder.php`, seeder tipis seperti `database/seeders/WjmCatalogSeeder.php` dengan jalur `database/seeders/data/wjm2-odoo/catalog.json`. Jangan didaftarkan di `DatabaseSeeder`. Tambahkan asersinya ke tes "DatabaseSeeder tidak pernah mengisi katalog WJM" yang ada.
- [X] T022 [US1] Jalankan T015, T016, dan semua tes `WjmCatalog*` hingga lulus.

**Checkpoint**: WJM 2 terisi di LIVE (pada tes); WJM 3 masih harus dibuktikan utuh (US2).

---

## Phase 4: User Story 2 — Data WJM 3 tidak tersentuh (Priority: P1)

**Goal**: potret data outlet A identik sebelum dan sesudah pengisian outlet B di kedua mode. Satu-satunya perubahan yang diizinkan adalah penanda "dijual di B" untuk item A. Item A ditarik dari menu B.

**Independent Test**: `WjmCatalogSecondOutletTest` kasus potret, dan `PosCategoriesOutletTest`.

### Tests for User Story 2 ⚠️

- [X] T023 [P] [US2] Tambahkan kasus potret ke `tests/Feature/WjmCatalogSecondOutletTest.php` (research D13). Sebelum mengisi B di LIVE dan DEMO, ambil potret A: produk, varian (harga, nama, `track_stock`, `current_stock`), riwayat harga, `variant_outlet_stock`, `stock_movements`, `outlet_item_settings` untuk `outlet_id` ≠ B, kategori (nama, urutan, stasiun), stasiun A, grup pilihan + opsi + tautan, dan foto di disk palsu. Sesudahnya potret harus identik. Untuk `outlet_item_settings` di B, setiap item A harus `is_carried = false` (FR-019) dan item A tetap dijual di A. Pastikan gagal.
- [X] T024 [P] [US2] Tulis `tests/Feature/PosCategoriesOutletTest.php` (research D10, contracts/pos-categories.md). **Hanya bila pengguna menyetujui cakupan D10**; bila tidak, lewati T024, T026, T027 dan catat di `evidence.md`. Kasus:
  - kasir dengan shift di B hanya melihat kategori yang punya item aktif yang dijual di B;
  - `product_count` per outlet;
  - kasir di A tidak melihat kategori yang hanya berisi item B;
  - tanpa shift di instalasi satu outlet → hasil sama dengan sebelumnya.
  Pastikan gagal.

### Implementation for User Story 2

- [X] T025 [US2] Implementasikan D9 di `WjmCatalogSeedService`: ganti `if ($report->mode === ModeGate::DEMO)` di `apply()` dengan `$catalog->withdrawOthers($report->mode)`. `withdrawOtherItems()` hanya menulis penanda untuk outlet tujuan, dan laporan "Ditarik dari menu outlet ini" berlaku di LIVE juga. Jalankan T023 dan semua tes `WjmCatalog*`. Tes DEMO 068 harus tetap lulus.
- [X] T026 [US2] Implementasikan D10: angkat aturan "item yang tidak dijual di outlet X" dari `app/Http/Controllers/Api/ProductController.php` (dua tempat, `$notCarriedHere`) ke satu metode bersama (mis. di `app/Services/OutletMenuService.php`, `notCarriedProductIds(?int $outletId): array`). Pakai metode itu di `ProductController` dan `app/Http/Controllers/Api/PosCategoryController.php`; kategori dan `product_count` disaring untuk `OutletContext::orderingId()`. Bila ada kueri mentah baru atas tabel ber-outlet, daftarkan di `OutletScopeRegistry`. Jalankan T024 dan tes POS/produk yang ada.
- [X] T027 [US2] Perbarui `docs/openapi-pos-mvp.yaml` untuk `GET /pos/categories` (filter outlet shift, arti `product_count`) di commit yang sama dengan T026.

**Checkpoint**: larangan "jangan sentuh WJM 3" terbukti pada tes; POS kedua outlet hanya menampilkan menu masing-masing.

---

## Phase 5: User Story 3 — Katalog yang sama di DEMO (Priority: P2)

**Goal**: DEMO mendapat outlet "Warkop WJM 2" baru dengan katalog dan stok yang sama dengan LIVE; outlet DEMO WJM 3 utuh.

**Independent Test**: `--mode=demo` pada DB yang DEMO-nya hanya punya outlet A.

- [X] T028 [P] [US3] Tambahkan kasus DEMO ke `tests/Feature/WjmCatalogSecondOutletTest.php`:
  - (a) DEMO hanya berisi outlet A dan menu A → outlet B dibuat (kode `WU2`) beserta stasiunnya; katalog dan stok 100 sama dengan LIVE; item A ditarik dari menu B; potret A identik (pakai pembantu T023);
  - (b) kegagalan buatan di DEMO (mis. foto rusak lewat mutasi katalog) → DEMO kembali utuh, dan LIVE yang sudah `applied` tetap terisi;
  - (c) paritas: hasil `app:seed-wjm-catalog --catalog=… --mode=demo` sama dengan `db:seed --class=Wjm2CatalogSeeder` (*unguarded*).
  Pastikan kasus yang belum didukung gagal, lalu perbaiki di `WjmCatalogSeedService` hingga lulus.

**Checkpoint**: kedua mode terisi; outlet WJM 3 di kedua mode utuh.

---

## Phase 6: User Story 4 — Pratinjau dan jalan ulang yang aman (Priority: P2)

**Goal**: pratinjau tidak menyimpan apa pun; jalan ulang tidak membuat apa pun dan tidak menambah stok.

**Independent Test**: `--dry-run` pada katalog kedua; dua kali jalan sungguhan.

- [X] T029 [P] [US4] Perluas `tests/Feature/WjmCatalogSeedPreviewTest.php`:
  - pratinjau katalog kedua melaporkan stasiun, kategori dikecualikan, stok awal, dan item yang akan ditarik di LIVE;
  - `rowCounts()` dan jumlah gerakan stok tidak berubah;
  - katalog kedua berstatus draf dengan nama varian sementara → pratinjau berjalan, pengisian sungguhan ditolak.
- [X] T030 [P] [US4] Perluas `tests/Feature/WjmCatalogSeedRerunTest.php` untuk outlet kedua:
  - jalan kedua → 0 dibuat, stok tetap 100, gerakan stok tetap satu per varian;
  - harga dan foto yang disunting pemilik bertahan;
  - item B yang dihapus pemilik tidak dibuat ulang;
  - jalan ulang katalog A sesudah B diisi → item B tidak dianggap milik A, dan A tidak berubah.
- [X] T031 [US4] Perbaiki `WjmCatalogSeedService`/`WjmCatalogSeedReport` hingga T029 dan T030 lulus, tanpa melemahkan asersi.

**Checkpoint**: semua cerita lulus pada tes.

---

## Phase 7: Polish, bukti, dan peluncuran

- [X] T032 [P] Tambahkan bagian WJM 2 ke `docs/RUNBOOK.md` §14: bangkit katalog, pratinjau, isi, jalan ulang, potret WJM 3, pemulihan dari cadangan, dan larangan menjalankan tanpa persetujuan pemilik.
- [X] T033 [P] Tambahkan catatan bertanggal 2026-10-25 ke `CLAUDE.md` (aturan domain "Katalog WJM dari Odoo"): katalog kedua `wjm2-odoo`, format 2, identitas item per outlet, `station_id` per item, peran stasiun, `withdraw_others`, dan `/pos/categories` per outlet (bila D10 disetujui).
- [X] T034 [P] Tambahkan catatan bertanggal ke `specs/068-odoo-catalog-seeder/spec.md` (FR-003/FR-015/FR-017): resolusi outlet, stasiun menurut peran, identitas per outlet, dan penarikan menurut `withdraw_others` kini berlaku untuk katalog WJM 3 juga.
- [X] T035 Jalankan `vendor/bin/pint` pada berkas PHP yang berubah, lalu suite backend penuh dengan flag `-e`, termasuk `StockLedgerInvariantTest`, `OutletScopeRegistryTest`, dan semua tes 068. Catat lulus/gagal di `specs/069-wjm2-catalog-seeder/evidence.md` §2. Kegagalan `ShiftReportParityTest` yang sudah ada sejak 065 dicatat terpisah, bukan diperbaiki di sini.
- [X] T036 Pratinjau di DB dev (quickstart §4): `app:backup`, lalu skrip potret WJM 3 (scratchpad, bukan repo), lalu `app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json --dry-run`. Catat laporan dan bukti bahwa DB tidak berubah di `evidence.md` §3.
- [X] T037 (2026-10-25: usulan disetujui apa adanya sebagai Pemilik; 4 item ditunda lewat bagian baru `## Item ditunda` karena nama 34 variannya belum diketahui, sehingga katalog berstatus Disetujui tanpa nama sementara. Lihat `evidence.md` §5.) Awalnya: DIBLOKIR sampai pemilik mengisi semua nama varian `?`, menjawab pertanyaan di `catalog-mapping.md`, dan mengubah status menjadi `Disetujui` dengan peran dan tanggal. Lalu bangkitkan ulang (T008), dan jalankan `WjmCatalogFileTest` serta pratinjau ulang.
- [ ] T038 (Sebagian, 2026-10-25: pengisian sungguhan di DB dev selesai atas permintaan pengembang; potret WJM 3 identik, stok 100, jalan ulang 0 dibuat, lihat `evidence.md` §5. Tinggal pemeriksaan peramban/tablet.) Awalnya: DIBLOKIR sampai T037 selesai dan pengembang mengizinkan penulisan ke DB dev. Jalankan quickstart §6:
  - isi sungguhan;
  - potret WJM 3 sesudah = sebelum, kecuali penanda menu di WJD dan outlet DEMO WJM 2;
  - stok 100 per varian;
  - pemeriksaan peramban/tablet: menu WJM 2 saja, tiket ke stasiun WJM 2, POS WJM 3 tanpa tab baru, konsol bersih;
  - jalan ulang 0 dibuat.
  Catat di `evidence.md` §4.
- [ ] T039 DIBLOKIR sampai T038 selesai dan pengembang memberi otorisasi eksplisit. Jalankan di server pelanggan (quickstart §7): cadangan eksternal, pratinjau, lalu isi dengan `--force`. Simpan laporannya tanpa data pribadi di `evidence.md` §5.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1** (T001–T003): bebas; T002 dan T003 paralel dengan T001.
- **Phase 2**:
  - T004 → T005 → T006 → T007/T008 (satu berkas alat; berurutan);
  - T009 → T010 (butuh T006–T008 untuk katalog sungguhan, dan T003 untuk fixture kedua);
  - T011 → T012 dan T013 → T014: setelah T010; T011 dan T013 paralel, T012 dan T014 berurutan (berkas service yang sama).
- **US1** (T015–T022): setelah Phase 2. T017–T020 berurutan (berkas service dan laporan yang sama); T021 paralel.
- **US2** (T023–T027): T023 setelah T015 (memakai persiapan yang sama); T025 setelah T017. T024, T026, T027 (D10) tidak bergantung pada service pengisian dan dapat dikerjakan paralel sejak Phase 2 selesai.
- **US3** (T028): setelah T025.
- **US4** (T029–T031): setelah US1; dapat paralel dengan US3.
- **Polish**: T032–T034 kapan saja setelah desainnya stabil; T035 setelah semua cerita; T036 setelah T035; T037 menunggu pemilik; T038 menunggu T037 dan izin pengembang; T039 menunggu T038 dan otorisasi eksplisit.

### Parallel opportunities

```text
Phase 1:  T001 ‖ T002 ‖ T003
Phase 2:  T011 ‖ T013            (tes, berkas berbeda)
US1:      T015 ‖ T016 ‖ T021
US2:      T023 ‖ T024;  T026+T027 paralel dengan T025
US3/US4:  T028 ‖ T029 ‖ T030
Polish:   T032 ‖ T033 ‖ T034
```

## Implementation Strategy

1. **MVP = Phase 1 + Phase 2 + US1 + US2.** Larangan WJM 3 sama pentingnya dengan pengisian WJM 2, jadi keduanya dibuktikan sebelum ada pengisian sungguhan.
2. Lalu US3 (DEMO) dan US4 (pratinjau/jalan ulang), suite penuh, dan pratinjau di DB dev (T036).
3. Pengisian sungguhan di DB dev (T038) hanya setelah pemilik menyetujui tabel pemetaan; server pelanggan (T039) hanya dengan otorisasi eksplisit.
4. D10 (`/pos/categories`) menunggu keputusan pengguna. Bila ditolak, T024/T026/T027 dilewati dan risikonya (tab kosong di tablet) dicatat di `evidence.md`.
