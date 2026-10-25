---

description: "Daftar tugas fitur 068 — pengisian awal katalog Odoo WJM ke mode LIVE dan DEMO"
---

# Tasks: Pengisian awal katalog Odoo WJM ke mode LIVE dan DEMO

**Klasifikasi**: INTERNAL

**Input**: Design documents from `specs/068-odoo-catalog-seeder/`

**Prerequisites**: plan.md, spec.md, research.md (D1–D14), data-model.md, contracts/cli.md, contracts/catalog.schema.json, quickstart.md

**Tests**: WAJIB. Konstitusi II meminta setiap perubahan backend disertai tes `tests/Feature/` terhadap MySQL nyata. Tiap tes ditulis lebih dulu dan harus GAGAL sebelum implementasinya.

**Perintah tes** (selalu dengan flag `-e`, CLAUDE.md; tanpa flag ini DB dev bisa terhapus):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<NamaTes>
```

**Organization**: tugas dikelompokkan per user story (spec.md US1–US4), agar tiap cerita dapat diimplementasi dan diuji mandiri.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: dapat dikerjakan paralel (berkas berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- **[Story]**: cerita yang dilayani (US1, US2, US3, US4)
- Setiap deskripsi menyebut jalur berkas persis

## Path Conventions

Laravel monolit di akar repo: `app/`, `database/`, `tests/Feature/`, `tests/Support/`. Dokumen fitur di `specs/068-odoo-catalog-seeder/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: data katalog sungguhan (draf) dan perlengkapan tes yang dipakai semua cerita.

- [X] T001 Normalisasi `specs/068-odoo-catalog-seeder/catalog-mapping.md` ke sintaks data-model §5, tanpa mengubah keputusan:
  - (a) sel "Varian usulan" item "Ayam Penyet & Bakar Cabe Ijo" menjadi `Ayam × Paket → Penyet Hemat, Penyet Komplit, Penyet Kenyang, Bakar Hemat, Bakar Komplit, Bakar Kenyang`;
  - (b) tambah bagian `## Harga varian khusus`, berisi tabel `| Item | Varian | Harga (Rp) |` tanpa baris;
  - (c) tambah bagian `## Grup pilihan`, berisi tabel `| Grup | Item |` tanpa baris ("Rasa" belum ditautkan);
  - (d) `**Status**:` tetap `DRAF`.
- [X] T002 [P] Tulis alat dev `specs/068-odoo-catalog-seeder/tools/build_catalog.py`. Python 3, pustaka standar saja, komentar berbahasa Indonesia, dijalankan dengan `python3 -I`. Argumen: `--src DIR` (folder berisi `Point of Sale Category (pos.category).xlsx`, `Product Attribute (product.attribute).xlsx`, `Product Template (product.template).xlsx`), `--mapping FILE`, `--out DIR`. Isi:
  - **Pembaca XLSX yang dikeraskan** (zipfile + ElementTree): tolak bagian XML yang memuat `<!DOCTYPE` atau `<!ENTITY`; batas ukuran tak-terkompresi 200 MB per bagian; sel jarang ditempatkan menurut huruf kolom; shared strings dan `inlineStr`.
  - **Kategori**: kolom "Display Name"; `order` = urutan baris (mulai 0); `station` dari judul `### <Kategori> (<Bar|Dapur>)` di tabel pemetaan.
  - **Atribut**: kolom "Attribute", "Variants Creation Mode", "Values". Baris lanjutan (kolom atribut kosong) milik atribut sebelumnya. Buang awalan `<Atribut>: ` yang pertama dari nilai. `creates_variants = (mode != "Never (option)")`.
  - **Produk**:
    - lewati baris lanjutan (Name kosong);
    - "Internal Reference" berawalan `nf_` → entri `excluded` (`products`, alasan "Produk teknis Odoo (<ref>), bukan menu");
    - `price` = "Sales Price"; `odoo_variant_count` = "# Product Variants";
    - `odoo_kit` = ("Is Kits" == True atau "# Bill of Material" > 0).
  - **Foto** (kolom "Image", base64):
    - nilai berawalan "The content of this cell is too long" → `photo = null`, `photo_issue = "truncated"`;
    - kosong → `photo_issue = "missing"`;
    - selain itu decode, pastikan JPEG/PNG lewat magic bytes, lalu tulis `photos/<sha256[:12]>.<jpg|png>` (dedup);
    - hapus berkas `*.jpg`/`*.png` di `photos/` yang tidak lagi dirujuk.
  - **Pemetaan** (data-model §5):
    - baris tabel per kategori → item; kolom "Harga (Rp)" harus sama dengan Sales Price sumber;
    - varian dari kolom "Varian usulan":
      - `Standard`;
      - `<Atribut>: n1, n2`;
      - `<A> × <B>` → kombinasi, label pendek = teks sebelum " (", A lebih dulu; teks setelah `→` harus sama dengan nama hasil hitungan;
    - harga varian dari `## Harga varian khusus`; tautan grup dari `## Grup pilihan`;
    - token `≠Odoo: <alasan>` di Catatan → `variant_count_override`;
    - persetujuan dari baris `**Status**:` (DRAF → `draft`, Disetujui → `approved`), `**Disetujui oleh (peran)**:`, dan `**Tanggal**:`.
  - **Grup pilihan**: satu per atribut "opsi saja", dengan `required: true` dan `options` = semua nilainya.
  - **Pengecualian otomatis**:
    - atribut pembentuk varian yang tidak dipakai item mana pun ("Tidak dipakai item mana pun");
    - nilai atribut terpakai yang tidak dipakai varian mana pun;
    - nilai bernama `test` sebagai entri `attribute_values` ("Data uji").
  - **Jangan** membaca atau menulis kolom Responsible, stok, perkiraan, harga pokok, satuan, atau BoM. **Jangan** menyalin XLSX.
  - **Keluaran**: `catalog.json` sesuai data-model §1 / `contracts/catalog.schema.json` (urutan sumber, `ensure_ascii=False`, indent 2), lalu ringkasan jumlah. Keluar dengan kode ≠ 0 bila ada item tanpa baris pemetaan, baris pemetaan tanpa item, kategori atau nilai tak dikenal, harga tidak cocok, atau jumlah varian ≠ Odoo tanpa `≠Odoo:`.
- [X] T003 Bangkitkan `database/seeders/data/wjm-odoo/catalog.json` dan `database/seeders/data/wjm-odoo/photos/` dengan `python3 -I specs/068-odoo-catalog-seeder/tools/build_catalog.py --src "/Users/chan/Downloads/WJM 3" --mapping specs/068-odoo-catalog-seeder/catalog-mapping.md --out database/seeders/data/wjm-odoo`. Pastikan hasilnya cocok dengan `source-analysis.md`: 10 kategori, 79 item, 111 varian, 57 berkas foto, 6 `truncated`, 12 `missing`, `approval.status = draft`; dan `grep -ri responsible database/seeders/data/wjm-odoo` tidak menemukan apa pun. Bergantung pada T001 dan T002.
- [X] T004 [P] Buat katalog mini `tests/Support/wjm-catalog/catalog.json`, ditambah satu JPEG kecil `tests/Support/wjm-catalog/photos/<sha256[:12]>.jpg` (dibuat dengan GD; nama = 12 heksa pertama SHA-256 isinya). Status `approved` (`approved_on` "2026-10-24", `approved_by_role` "pemilik"). Isi:
  - **Kategori**: "Kopi Uji" (Bar, order 0), "Makanan Uji" (Dapur, 1), "Tambahan Uji" (Dapur, 2).
  - **Atribut**:
    - pembentuk varian: "Suhu" [Hot, Ice], "Porsi" [Kecil, Besar], "Level" [Pedas, Sedang];
    - opsi saja: "Rasa" [Hazelnut, Vanilla];
    - pembentuk varian yang tidak dipakai: "Lama" [test].
  - **Item** (7 item, 11 varian):
    - "Kopi Uji" (Kopi Uji, 10000, Suhu Hot/Ice, odoo 2, foto);
    - "Nasi Paket Uji" (Makanan Uji, 20000, Porsi × Level → Kecil Pedas, Kecil Sedang, Besar Pedas, Besar Sedang; dua varian "Besar" 25000; odoo 4);
    - di Makanan Uji (kasus tabrakan segmen): "Roti Bakar Keju" (13000, foto yang sama), "Roti Bakar Coklat" (13000), "Roti Bakar Coklat Keju" (15000);
    - "Nasi Putih Uji" (Tambahan Uji, 0, `missing`);
    - "Kit Uji" (Tambahan Uji, 5000, `odoo_kit` true, `truncated`).
  - **Grup pilihan**: "Rasa" (from_attribute Rasa, required, [Hazelnut, Vanilla], attach_to ["Kopi Uji"]). Namanya sengaja sama dengan grup contoh di `WarkopMenuDemoSeeder`.
  - **Dikecualikan**: products "Partial Payment Uji" (produk teknis); attributes "Lama" (tidak dipakai); attribute_values "Lama: test" (data uji).
  - **source.rows**: categories 3, attribute_values 9, products 8.
- [X] T005 [P] Buat trait `tests/Support/WjmCatalogFixtures.php` (namespace `Tests\Support`) dengan metode:
  - `wjmFixtureDir()`;
  - `wjmCatalogPath(?callable $mutate = null): string`. Tanpa mutasi → jalur katalog mini. Dengan mutasi → salin folder katalog mini (beserta foto) ke `sys_get_temp_dir().'/wjm-'.uniqid()`, terapkan `$mutate(array $data): array`, tulis ulang JSON;
  - `wjmRun(array $modes = ['live'], ?string $outlet = null, bool $dryRun = false, ?string $path = null): array`. Memuat `WjmCatalog::fromFile()` lalu memanggil `app(WjmCatalogSeedService::class)->run(...)`;
  - `inMode(string $mode, callable $fn)`, membungkus `ModeGate::runAs`;
  - `rowCounts(): array`, jumlah baris lintas mode lewat `DB::table` untuk outlets, stations, categories, products, product_variants, modifier_groups, modifier_options, modifier_group_product, outlet_item_settings, price_history, dan activity_logs, untuk asersi "tanpa perubahan";
  - `placeholderDemoMenu(): void`, menjalankan `seed(DatabaseSeeder::class)` lalu `seed(WarkopMenuDemoSeeder::class)`.

**Checkpoint**: katalog sungguhan (draf) dan katalog mini siap.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: pemuat/validator katalog, alokasi kode, penyimpanan foto lokal, laporan, dan kerangka service yang dipakai semua cerita.

**⚠️ CRITICAL**: belum ada pekerjaan cerita sebelum fase ini selesai. Di setiap pasangan, tes ditulis lebih dulu dan harus gagal.

- [X] T006 [P] Tulis `tests/Feature/WjmCatalogFileTest.php` (pakai `WjmCatalogFixtures`). Kasus:
  - **(a) katalog sungguhan** `database/seeders/data/wjm-odoo/catalog.json`:
    - `problems()` kosong;
    - 10 kategori, 79 item, 1 grup pilihan;
    - jumlah varian = jumlah `odoo_variant_count` (111) kecuali item ber-`variant_count_override`;
    - pertanggungjawaban 80/10/26 cocok;
    - tidak ada kunci `responsible|user|email|phone` di tingkat mana pun;
    - setiap foto ada, ≤ 5 MB, dan JPEG.
  - **(b) katalog mini**: tanpa masalah dan `isApproved()` true.
  - **(c) tiap mutasi katalog mini menghasilkan masalah yang tepat**:
    - kategori tak dikenal;
    - nilai varian tidak ada di atribut;
    - jumlah varian ≠ `odoo_variant_count` (tidak bermasalah bila `variant_count_override` diisi);
    - harga negatif;
    - nama item ganda dalam satu kategori;
    - nama varian ganda dalam satu item;
    - grup pilihan dari atribut pembentuk varian;
    - `attach_to` ke item tak dikenal;
    - berkas foto hilang;
    - `photo` null tanpa `photo_issue`;
    - ada kunci `responsible`;
    - `source.rows.products` tidak cocok;
    - `approval.status = approved` tanpa `approved_on`.
  - **(d) katalog mini berstatus draf**: tanpa masalah struktur, tetapi `isApproved()` false.
- [X] T007 Implementasikan `app/Support/WjmCatalog.php` (final class, komentar Indonesia, merujuk 068/D10):
  - `fromFile(string $path): self`, memakai `json_decode` dengan `JSON_THROW_ON_ERROR`. Galat baca atau JSON dicatat sebagai masalah, bukan dilempar sebagai eksepsi mentah.
  - `problems(): list<string>`, mengumpulkan SEMUA pelanggaran terhadap data-model §1, `contracts/catalog.schema.json`, dan aturan lintas kolom research D10. Pesan berbahasa Indonesia dan menyebut item, mis. "Item 'X': jumlah varian 3 ≠ Odoo 2".
  - Pertanggungjawaban nilai atribut memakai himpunan kunci "Atribut: nilai": nilai terpakai varian ∪ opsi grup ∪ nilai atribut dikecualikan ∪ entri `excluded` attribute_values. Jumlahnya harus sama dengan `source.rows.attribute_values`.
  - `isApproved(): bool`, terpisah dari `problems()` agar pratinjau tetap bisa berjalan pada draf.
  - Aksesor: `outletName()`, `source()`, `approval()`, `categories()`, `attributes()`, `optionGroups()`, `items()`, `excluded()`, dan `photoPath(string $file): string` (folder `photos/` di samping berkas JSON).
- [X] T008 [P] Tulis `tests/Feature/WjmCatalogCodeAllocationTest.php`. Kasus:
  - `freeCategoryCode()`: "Coffee" → `CO`, "Mie & Pangsit Level" → `MP`, "Non Coffee" → `NC`;
  - kode yang sudah dipakai kategori di mode LAIN (dibuat lewat `ModeGate::runAs('demo')`) atau oleh kategori terhapus tidak dipakai lagi: "Nasi" → `NA`; lalu "Non Coffee", saat `NA` dan `NC` sudah terpakai → `N1`, dan berikutnya `N2`;
  - `segmentCandidates()`: kandidat pertama "Dimsum Isi 4" adalah `DIS`, "Basreng" `BAS`; "Roti Bakar Coklat" diawali `[RBC, RCO, ROT, …]`;
  - `firstFreeCodePrefix('WJ3', 'RP', …)` dipanggil berurutan untuk 10 nama Roti/Pisang Bakar (research D4), dengan produk dibuat di tiap langkah, menghasilkan `PBC, PKE, PBK, RBB, RBC, RCO, RKE, RBK, RBS, RBT`, dan tidak memakai prefix milik produk mode lain atau produk terhapus;
  - `buildCodePrefix()` yang lama tetap melempar galat saat tabrakan.
- [X] T009 Tambah ke `app/Services/ProductCodeGenerator.php` metode `freeCategoryCode(string $name): string`, `segmentCandidates(string $name): list<string>`, dan `firstFreeCodePrefix(string $outletCode, string $categoryCode, string $name): array{0: string, 1: string}` sesuai research D4. Perilaku metode lama tidak berubah; docblock berbahasa Indonesia merujuk 068/D4.
  - Kata adalah deret huruf (angka dan simbol dibuang), ditulis huruf besar; pad dengan `X` bila hasil kurang dari 3 huruf.
  - Cek keunikan kode kategori lewat `Category::withTrashed()->withoutGlobalScopes()`. Cek prefix lewat private `prefixTaken()`, yang juga dipakai `buildCodePrefix()`.
  - Lempar `RuntimeException` bila semua kandidat habis.
- [X] T010 [P] Tulis `tests/Feature/ImageUploadServiceLocalFileTest.php`. Dengan `Storage::fake('public')`:
  - `storeLocalFile(<JPEG buatan GD di folder temp>, 'products')` mengembalikan `products/<uuid>.jpg`, berkasnya ada di disk palsu, dan berkas sumber tetap ada;
  - berkas teks → `InvalidArgumentException`;
  - berkas > 5 MB → `InvalidArgumentException`.
- [X] T011 Tambah `ImageUploadService::storeLocalFile(string $absolutePath, string $directory): string` di `app/Services/ImageUploadService.php`. Bungkus berkas sebagai `new UploadedFile($absolutePath, basename($absolutePath), null, null, true)` lalu delegasikan ke `store()`, sehingga aturan MIME dari isi, batas ukuran, dan nama acak tetap satu. Komentar Indonesia menjelaskan alasannya: CLI tidak punya unggahan HTTP; mode tes Symfony hanya melewati `is_uploaded_file`; `storeAs` menyalin lewat stream sehingga berkas sumber tidak dipindah.
- [X] T012 [P] Buat `app/Support/WjmCatalogSeedReport.php` (final class):
  - konstanta status `applied`, `previewed`, `refused`, `failed`;
  - properti `mode`, `status`, `outlet` (`?array{code, name, created}`), dan `counts` (`created`/`existing` untuk `stations`, `categories`, `items`, `variants`, `option_groups`, `photos`);
  - daftar `excluded`, `withoutPhoto`, `zeroPrice`, `renamedGroups`, `kitItems`, `withdrawn`, `deletedByOwner`, `problems`, `warnings`, dan `sourceAccounting`;
  - metode `created(string $kind, int $n = 1)` dan `existing(string $kind, int $n = 1)`;
  - `printTo(\Illuminate\Console\Command $command): void`, mencetak bentuk `contracts/cli.md` §3 dalam Bahasa Indonesia: judul mode, tabel jumlah, setiap daftar yang tidak kosong, dan baris pertanggungjawaban. Status `previewed` diawali "PRATINJAU — tidak ada yang disimpan"; status `refused` atau `failed` mencetak masalah satu per baris.
- [X] T013 Buat kerangka `app/Services/WjmCatalogSeedService.php`, dengan konstruktor yang menerima `ProductCodeGenerator`, `ImageUploadService`, `OutletMenuService`, `ModifierGroupService`, dan `ActivityLogger`:
  - `run(WjmCatalog $catalog, array $modes, ?string $outletCode = null, bool $dryRun = false): array<string, WjmCatalogSeedReport>`;
  - mode di luar `live|demo` → `InvalidArgumentException`; LIVE selalu diproses sebelum DEMO;
  - `problems()` tidak kosong → semua mode `refused` beserta masalahnya;
  - katalog draf dan bukan pratinjau → semua mode `refused` dengan pesan "Katalog masih DRAF: setujui catalog-mapping.md lalu bangkitkan ulang catalog.json";
  - tiap mode dijalankan di dalam `ModeGate::runAs($mode, …)` dan `DB::transaction(fn () => $this->apply(...))`;
  - jalur foto yang sudah ditulis dicatat di sebuah larik. `\DomainException` (penolakan, mis. outlet ambigu) → status `refused`; `\Throwable` lain → status `failed`. Keduanya menghapus foto lewat `ImageUploadService::delete()`, lalu lanjut ke mode berikutnya;
  - `apply()` masih berupa langkah kosong yang diisi fase US1.

  Bergantung pada T007 dan T012.

**Checkpoint**: fondasi siap; `WjmCatalogFileTest`, `WjmCatalogCodeAllocationTest`, dan `ImageUploadServiceLocalFileTest` hijau.

---

## Phase 3: User Story 1 - Outlet WJM 3 siap berjualan dengan menu Odoo-nya di mode LIVE (Priority: P1) 🎯 MVP

**Goal**: satu jalan `app:seed-wjm-catalog --mode=live` mengisi outlet "Warkop WJM 3" di LIVE (dibuat bila mode kosong) dengan stasiun, kategori, item, varian, harga, foto, dan grup pilihan. Item langsung dapat dipesan, hanya di outlet itu, dan tercatat satu log aktivitas.

**Independent Test**: pada database uji kosong, jalankan perintah untuk LIVE dengan katalog mini; semua item dapat dipesan di outlet tujuan dengan harga sumber (`WjmCatalogSeedLiveTest`, `WjmCatalogSeedCommandTest`).

### Tests for User Story 1 ⚠️

> Tulis lebih dulu; tes harus GAGAL sebelum T016–T022.

- [X] T014 [P] [US1] Tulis `tests/Feature/WjmCatalogSeedLiveTest.php` (RefreshDatabase, `WjmCatalogFixtures`, `Storage::fake('public')`). Kasus:
  1. LIVE kosong → outlet "Warkop WJM 3" aktif berkode `WJ3` dibuat, beserta stasiun "Bar" dan "Dapur" dengan `outlet_id` outlet itu.
  2. 3 kategori: nama persis, `display_order` 0–2, kode `KU`/`MU`/`TU`, stasiun sesuai ("Kopi Uji" → Bar).
  3. 7 produk aktif dan terbit (`published_at` terisi), milik outlet tujuan; `code_prefix` cocok dengan `^WJ3-[A-Z0-9]{2}-[A-Z]{3}$`; tiga "Roti Bakar …" punya segmen berbeda.
  4. 11 varian dengan nama dan harga persis (dua varian "Besar" 25000), `track_stock` false, `cost_price` 0, aktif. Setiap varian punya `price_history` bersumber `created`, dan `MenuAvailability::isOrderable($v->load('product'), $outlet->id)` true.
  5. Foto "Kopi Uji" dan "Roti Bakar Keju" tersimpan di disk palsu dengan jalur berbeda; "Nasi Putih Uji" dan "Kit Uji" tanpa foto.
  6. Grup "Rasa": satu pilihan, wajib, min 1 / maks 1, opsi Hazelnut/Vanilla dengan `price_delta` 0, tertaut hanya ke "Kopi Uji".
  7. LIVE berisi outlet "Warkop WJM 3" dan "Cabang Lain" (keduanya aktif; `multi_outlet_enabled` true) → item WJM dijual di "Warkop WJM 3" dan TIDAK di "Cabang Lain" (`OutletMenuService::isCarried`); item lama "Cabang Lain" tetap dijual di sana.
  8. LIVE dengan satu outlet aktif bernama lain → outlet itu yang dipakai; tidak ada outlet baru.
  9. Dua outlet aktif tanpa "Warkop WJM 3" → `refused`, masalahnya menyebut kode kedua outlet, dan `rowCounts()` tidak berubah. `$outletCode` yang tidak dikenal juga → `refused`.
  10. Katalog draf → `refused`, tanpa perubahan apa pun.
  11. Tepat satu baris `activity_logs` `catalog_seeded`: `user_id` null, entitas Outlet = outlet tujuan, `new_values` memuat jumlah item dibuat = 7.
  12. `multi_outlet_enabled` false, DEMO sudah punya outlet, LIVE kosong → outlet LIVE tetap dibuat (hitungan lisensi per mode).
  13. `seed(DatabaseSeeder::class)` tidak membuat produk apa pun, dan `database/seeders/DatabaseSeeder.php` tidak menyebut `WjmCatalogSeeder` (FR-001).
- [X] T015 [P] [US1] Tulis `tests/Feature/WjmCatalogSeedCommandTest.php`. Kasus:
  - `artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--catalog' => <katalog mini>])` → kode 0, keluaran memuat "Mode LIVE" dan "applied";
  - tanpa `--catalog`, perintah memakai `config('seeding.wjm_catalog_path')` (di-set ke katalog mini);
  - `--mode=both --outlet=XYZ` → kode 1, tanpa perubahan;
  - `--mode=foo` → kode 1;
  - katalog draf → kode 1, keluaran menyebut "DRAF";
  - `app()['env'] = 'production'` tanpa `--force` → `expectsConfirmation(<pertanyaan bawaan ConfirmableTrait>, 'no')` → kode 1, tanpa perubahan;
  - dengan `--force` → kode 0.

### Implementation for User Story 1

- [X] T016 [US1] Di `app/Services/WjmCatalogSeedService.php`, implementasikan resolusi outlet (research D5, FR-003):
  - urutan: `$outletCode` (harus ada dan aktif di mode itu) → nama persis `catalog->outletName()` tanpa peka huruf → satu-satunya outlet aktif → mode tanpa outlet sama sekali, maka buat. Selain itu lempar `\DomainException` berisi daftar "kode — nama" outlet aktif;
  - pembuatan: `LicenseGate::canCreateOutlet()` harus true; bila tidak → `\DomainException`;
  - kode outlet baru: kandidat pertama yang bebas dari `WJ3`, `WJM`, `WJA`…`WJZ`, `WJ0`…`WJ9`, dicek lewat `Outlet::withTrashed()->withoutGlobalScopes()`;
  - `Outlet::create(['code', 'name', 'is_active' => true])` (hook `created` mengurus `materializeForOutlet`);
  - isi `report->outlet`.
- [X] T017 [US1] Di `app/Services/WjmCatalogSeedService.php`, pastikan stasiun "Bar" dan "Dapur" ada di outlet tujuan:
  - cari lewat `Station::withoutGlobalScopes()->where('outlet_id', …)->where('name', …)`;
  - bila belum ada: `new Station(['name' => …, 'display_order' => i, 'is_active' => true])`, set `$station->outlet_id = $outlet->id` SECARA EKSPLISIT (research D6; jangan mengandalkan `firstOrCreate` + `$fillable`), lalu `save()`;
  - hitung `created`/`existing` stasiun.
- [X] T018 [US1] Di `app/Services/WjmCatalogSeedService.php`, buat kategori katalog berurutan lewat `Category::create`:
  - `code` dari `ProductCodeGenerator::freeCategoryCode($name)`;
  - `name`; `display_order` = `order`; `station_id` = stasiun sesuai `station`; `is_active` true;
  - simpan peta nama → model untuk langkah item.
- [X] T019 [US1] Di `app/Services/WjmCatalogSeedService.php`, buat grup pilihan lewat `ModifierGroupService::create(['name' => …, 'selection_type' => ModifierGroup::SINGLE, 'is_required' => $group['required'], 'display_order' => i, 'is_active' => true, 'options' => [['name' => …, 'price_delta' => 0, 'display_order' => j, 'is_active' => true], …]])`. Setelah item dibuat (T020), tautkan grup BARU ke item yang dibuat dalam jalan ini dan disebut di `attach_to`, lewat `setAttachments($group, $productIds, [])`.
- [X] T020 [US1] Di `app/Services/WjmCatalogSeedService.php`, buat item:
  - kode lewat `firstFreeCodePrefix($outlet->code, $category->code, $item['name'])`;
  - foto (bila `photo` tidak null) lewat `ImageUploadService::storeLocalFile($catalog->photoPath(...), 'products')`; jalurnya dicatat untuk pembersihan;
  - `Product::create(['home_outlet_id', 'category_id', 'code_prefix', 'product_segment', 'name', 'image_path', 'is_active' => true])`;
  - varian berurutan lewat `$product->variants()->create(['sku' => nextVariantSku($product), 'variant_name', 'sell_price' => price, 'cost_price' => 0, 'track_stock' => false, 'is_active' => true])`. Hook model mencatat `price_history` dan saldo outlet;
  - lalu `OutletMenuService::setCarried($product, [$outlet->id])` (FR-004);
  - tanpa `StockService` dan tanpa `DB::table` atas tabel ber-outlet (`OutletScopeRegistryTest`).
- [X] T021 [US1] Di `app/Services/WjmCatalogSeedService.php`, di akhir transaksi mode:
  - panggil `ActivityLogger::log(userId: null, action: 'catalog_seeded', entityType: 'Outlet', entityId: $outlet->id, description: "Katalog WJM (Odoo) diisi ke outlet {kode} oleh app:seed-wjm-catalog.", newValues: [...])` (contracts/cli.md §4). `newValues` berisi jumlah dibuat, sudah ada, dan dikecualikan per jenis, ditambah `catalog_exported_on` dan `approved_on`;
  - isi `counts` di laporan; status `applied`.
- [X] T022 [P] [US1] Buat `app/Console/Commands/SeedWjmCatalog.php` (`use ConfirmableTrait`), dengan signature `contracts/cli.md` §1 (`--mode=both`, `--outlet=`, `--dry-run`, `--catalog=`, `--force`) dan deskripsi berbahasa Indonesia:
  - validasi opsi: mode tidak sah, atau `--outlet` bersama `both` → galat, kode 1;
  - jalur katalog = `--catalog` ?: `config('seeding.wjm_catalog_path', database_path('seeders/data/wjm-odoo/catalog.json'))`;
  - muat `WjmCatalog` lalu cetak masalahnya;
  - bila bukan pratinjau, panggil `confirmToProceed()` (produksi tanpa `--force` → bertanya);
  - panggil service, lalu `printTo($this)` untuk tiap laporan, dan ringkasan "Selesai: LIVE …, DEMO …";
  - kode keluar 0 hanya bila semua mode `applied` atau `previewed`.
- [X] T023 [US1] Jalankan `tests/Feature/WjmCatalogSeedLiveTest.php` dan `tests/Feature/WjmCatalogSeedCommandTest.php` (`--filter='WjmCatalogSeedLive|WjmCatalogSeedCommand'`) dengan perintah tes berflag `-e`; perbaiki hingga hijau.

**Checkpoint**: US1 selesai. LIVE dapat diisi dan langsung berjualan (MVP).

---

## Phase 4: User Story 2 - Mode DEMO memakai menu WJM yang asli (Priority: P2)

**Goal**: pengisian DEMO memakai outlet `WJ3` yang sudah ada dan menarik item contoh dari menu outlet itu tanpa menghapusnya. Outlet lain tidak disentuh, dan DEMO tetap terpisah dari LIVE. `WjmCatalogSeeder` mengisi kedua mode.

**Independent Test**: jalankan `placeholderDemoMenu()`, lalu isi untuk DEMO. POS WJ3 hanya berisi katalog WJM, dan data LIVE tidak berubah (`WjmCatalogSeedDemoTest`).

### Tests for User Story 2 ⚠️

- [X] T024 [P] [US2] Tulis `tests/Feature/WjmCatalogSeedDemoTest.php` (RefreshDatabase, `WjmCatalogFixtures`, `RestaurantFixtures`, `Storage::fake('public')`). Kasus:
  1. Setelah `placeholderDemoMenu()`, jalan DEMO memakai outlet `WJ3` yang ada; tidak ada outlet baru.
  2. Ke-32 item contoh `isCarried(..., WJ3)` false tetapi masih ada (tidak terhapus). Baris order demo yang dibuat sebelum pengisian untuk salah satu varian contoh (lewat `makeShift`/`makeOpenOrder`/`addLineTo` di dalam `ModeGate::runAs('demo')`) tetap terbaca beserta variannya.
  3. Outlet DEMO lain ("Outlet Lain", dibuat lewat `Outlet::factory()` di mode demo, beserta produknya) tidak menjual item WJM, dan menunya tidak berubah.
  4. Grup contoh "Rasa" (Cokelat/Keju/Srikaya/Kacang) tidak berubah; grup baru "Rasa (WJM)" dibuat dengan Hazelnut/Vanilla.
  5. Tanpa menu contoh, `wjmRun(['demo', 'live'])` tetap memproses LIVE lebih dulu: kategori LIVE berkode `KU`/`MU`/`TU`, DEMO berkode cadangan `K1`/`M1`/`T1`; tidak ada `code_prefix` atau SKU ganda.
  6. Mengubah harga varian DEMO (`PriceService::setBasePrice` oleh owner) tidak mengubah varian LIVE yang bernama sama.
  7. Jalan LIVE tidak pernah menarik item: produk milik pemilik di outlet tujuan LIVE tetap dijual.
  8. Paritas D6: `seed(WjmCatalogSeeder::class)` (dengan config `seeding.wjm_catalog_path` → katalog mini) dan, di metode tes terpisah, perintah `app:seed-wjm-catalog` menghasilkan bentuk yang sama: stasiun ber-`outlet_id` tujuan, dan jumlah baris yang sama.

### Implementation for User Story 2

- [X] T025 [US2] Di `app/Services/WjmCatalogSeedService.php`, hanya bila mode = `demo` dan setelah item dibuat: untuk setiap produk mode ini yang dijual outlet tujuan (`OutletMenuService::isCarried`) tetapi bukan item katalog WJM (dibuat atau dicocokkan dalam jalan ini), panggil `setCarried($product, [], [$outlet->id])` dan catat namanya di `report->withdrawn` (FR-017). Mode `live` tidak pernah menarik apa pun.
- [X] T026 [US2] Di `app/Services/WjmCatalogSeedService.php`, sebelum membuat grup pilihan, cari grup bernama sama di mode ini (FR-016):
  - ada yang himpunan nama opsi aktifnya identik → pakai grup itu: hitung `existing`, jangan ubah, jangan tautkan ulang;
  - hanya ada yang berbeda → pakai nama pertama yang bebas dari "<nama> (WJM)", "<nama> (WJM 2)", … (yang identik dipakai ulang), buat grupnya, dan catat di `report->renamedGroups`.
- [X] T027 [P] [US2] Buat `database/seeders/WjmCatalogSeeder.php`, dengan docblock Indonesia (cara menjalankan; tidak didaftarkan di `DatabaseSeeder`):
  - jalur katalog dari `config('seeding.wjm_catalog_path', database_path('seeders/data/wjm-odoo/catalog.json'))`;
  - muat `WjmCatalog`, panggil `run(['live', 'demo'])`, lalu `printTo($this->command)` untuk tiap laporan;
  - lempar `RuntimeException` bila ada mode yang tidak `applied`, agar `db:seed` keluar dengan kode ≠ 0.
- [X] T028 [US2] Jalankan `tests/Feature/WjmCatalogSeedDemoTest.php` (`--filter=WjmCatalogSeedDemo`) dengan perintah tes berflag `-e`, lalu ulangi filter US1; perbaiki hingga hijau.

**Checkpoint**: US1 dan US2 berjalan; seeder mengisi kedua mode.

---

## Phase 5: User Story 3 - Jalan ulang aman, dengan laporan untuk setiap baris sumber (Priority: P2)

**Goal**: jalan ulang tidak membuat dan tidak mengubah apa pun; suntingan pemilik bertahan; kegagalan tidak meninggalkan sisa; laporan mempertanggungjawabkan setiap baris sumber.

**Independent Test**: jalankan dua kali berturut-turut. Jalan kedua membuat 0 data, dan `rowCounts()` serta `updated_at` tidak berubah (`WjmCatalogSeedRerunTest`).

### Tests for User Story 3 ⚠️

- [X] T029 [P] [US3] Tulis `tests/Feature/WjmCatalogSeedRerunTest.php`. Kasus:
  1. Jalan kedua: semua `created` 0, `existing` = total, `rowCounts()` sama, dan `max(updated_at)` tiap tabel katalog (categories, products, product_variants, modifier_groups, outlet_item_settings, stations) sama.
  2. Harga varian yang diubah pemilik (`PriceService::setBasePrice`) dan `image_path` produk yang diganti tetap bertahan setelah jalan ulang.
  3. Produk hasil pengisian yang dihapus pemilik (soft delete) tidak dibuat ulang, dan tercantum di `deletedByOwner`.
  4. Produk yang diganti nama oleh pemilik dibuat lagi pada jalan ulang dengan nama sumber, tercatat sebagai `created` (mendokumentasikan edge case spec).
  5. Kegagalan disuntikkan pada `wjmRun(['live', 'demo'])`: buat instans asli dulu (`$real = app(OutletMenuService::class)`), lalu `partialMock(OutletMenuService::class)` yang melempar `RuntimeException` pada `setCarried` hanya saat `ModeGate::isDemo()`, selain itu meneruskan ke `$real`. Hasilnya: LIVE `applied`, DEMO `failed`; di DEMO tidak ada kategori, produk, varian, atau grup baru, dan tidak ada log `catalog_seeded`; berkas foto DEMO yang sempat ditulis sudah terhapus dari disk palsu.
  6. Isi laporan:
     - `excluded` memuat "Partial Payment Uji" beserta alasannya;
     - `withoutPhoto` memuat "Nasi Putih Uji" (missing) dan "Kit Uji" (truncated);
     - `zeroPrice` memuat "Nasi Putih Uji"; `kitItems` memuat "Kit Uji";
     - `sourceAccounting` menyatakan produk 8 = dibuat + sudah ada + 1 dikecualikan, kategori 3, dan nilai atribut 9.

### Implementation for User Story 3

- [X] T030 [US3] Di `app/Services/WjmCatalogSeedService.php`, terapkan pencocokan research D7 (FR-015/016):
  - muat sekali per mode: peta kategori menurut nama, dan peta produk menurut (nama, nama kategori) termasuk yang terhapus (`withTrashed`);
  - kategori yang cocok dipakai ulang: hitung `existing`, tidak diubah;
  - item yang cocok dilewati utuh (tanpa varian, foto, tautan, maupun `setCarried`) dan dihitung `existing`; bila terhapus, catat di `deletedByOwner`;
  - stasiun dan grup sudah dicocokkan oleh T017 dan T026.
- [X] T031 [P] [US3] Di `app/Support/WjmCatalogSeedReport.php`, tambah:
  - `fillFromCatalog(WjmCatalog $catalog): void`, mengisi `excluded`, `withoutPhoto` (beserta jenis masalahnya), `zeroPrice`, dan `kitItems`;
  - `accountFor(WjmCatalog $catalog): void`, menyusun `sourceAccounting` untuk produk, kategori, dan nilai atribut (contracts/cli.md §3).

  Service memanggil keduanya untuk tiap mode.
- [X] T032 [US3] Di `app/Services/WjmCatalogSeedService.php`, kuatkan jalur gagal:
  - foto dibersihkan pada status `refused` maupun `failed`;
  - pesan galat masuk `problems` tanpa jejak tumpukan;
  - log aktivitas hanya ditulis di dalam transaksi yang berhasil;
  - mode berikutnya tetap dijalankan.

  Pastikan `app/Console/Commands/SeedWjmCatalog.php` keluar dengan kode 1 bila ada mode `failed`.
- [X] T033 [US3] Jalankan `tests/Feature/WjmCatalogSeedRerunTest.php` (`--filter=WjmCatalogSeedRerun`), lalu semua tes fitur (`--filter='WjmCatalog|ImageUploadServiceLocalFile'`), dengan perintah tes berflag `-e`; perbaiki hingga hijau.

**Checkpoint**: aman dijalankan ulang; laporan lengkap.

---

## Phase 6: User Story 4 - Pratinjau tanpa menyimpan (Priority: P3)

**Goal**: `--dry-run` menghasilkan laporan yang sama dengan jalan sungguhan tanpa menyimpan data maupun berkas, dan boleh dijalankan pada katalog draf.

**Independent Test**: jalankan pratinjau untuk LIVE. Laporan terisi, sementara `rowCounts()` dan disk palsu tidak berubah (`WjmCatalogSeedPreviewTest`).

### Tests for User Story 4 ⚠️

- [X] T034 [P] [US4] Tulis `tests/Feature/WjmCatalogSeedPreviewTest.php`. Kasus:
  - pratinjau LIVE kosong → status `previewed`, `rowCounts()` sama sebelum dan sesudah, disk palsu kosong;
  - jumlah `created` dalam pratinjau sama dengan jalan sungguhan sesudahnya;
  - katalog draf → pratinjau `previewed` dengan peringatan draf, sedangkan jalan sungguhan `refused`;
  - perintah `--dry-run` mencetak "PRATINJAU — tidak ada yang disimpan" dan keluar dengan kode 0;
  - dengan `app()['env'] = 'production'`, `--dry-run` tidak meminta konfirmasi.

### Implementation for User Story 4

- [X] T035 [US4] (Dikerjakan lebih awal bersama T022 agar `--dry-run` tidak pernah menulis data sungguhan selama US1–US3; diverifikasi T037.) Di `app/Services/WjmCatalogSeedService.php`, bila `$dryRun`:
  - jalankan `apply()` yang sama di antara `DB::beginTransaction()` dan `DB::rollBack()` (di dalam `finally`);
  - lewati `storeLocalFile`; foto dihitung sebagai "akan ditulis", dengan `image_path` null;
  - izinkan katalog draf dengan peringatan "Katalog masih DRAF — pengisian sungguhan akan ditolak";
  - status `previewed`.
- [X] T036 [P] [US4] Di `app/Console/Commands/SeedWjmCatalog.php`, teruskan `--dry-run` ke service, lewati `confirmToProceed()` saat pratinjau, dan cetak spanduk pratinjau.
- [X] T037 [US4] Jalankan `tests/Feature/WjmCatalogSeedPreviewTest.php` (`--filter=WjmCatalogSeedPreview`) dengan perintah tes berflag `-e`; perbaiki hingga hijau.

**Checkpoint**: semua cerita berfungsi dan teruji mandiri.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: dokumentasi, suite penuh, bukti, dan pengisian nyata setelah persetujuan pemilik.

- [X] T038 [P] Tambah bagian "Katalog WJM dari Odoo (068)" di `docs/RUNBOOK.md`:
  - urutan kerja: cadangkan (`app:backup`) → pratinjau → isi → periksa POS → jalan ulang;
  - opsi `--mode`, `--outlet`, `--force`;
  - item contoh DEMO hanya ditarik, tidak dihapus;
  - item yang diganti nama pemilik akan dibuat lagi pada jalan ulang;
  - perintah tes berflag `-e`;
  - pemulihan lewat `app:restore`.
- [X] T039 [P] Tambah catatan bertanggal (2026-10-24, fitur 068) di bagian "Domain rules" `CLAUDE.md`:
  - `WjmCatalogSeedService` satu-satunya pengisi katalog WJM;
  - sumbernya `database/seeders/data/wjm-odoo/catalog.json`, dibangkitkan lewat `specs/068-odoo-catalog-seeder/tools/build_catalog.py` dari `catalog-mapping.md` yang disetujui pemilik;
  - katalog berstatus draf menolak pengisian;
  - `catalog.json` tidak boleh disunting dengan tangan.
- [X] T040 Jalankan suite backend penuh dengan perintah tes berflag `-e`, termasuk `StockLedgerInvariantTest`, `OutletScopeRegistryTest`, `SakanaFridgeDemoSeederTest`, dan `WarkopOutletsDemoSeederTest`. Catat jumlah lulus/gagal di `specs/068-odoo-catalog-seeder/evidence.md`.
- [ ] T041 (Sebagian, 2026-10-25: cadangan, pratinjau, isi sungguhan, pemeriksaan DB, log aktivitas, dan jalan ulang (0 dibuat) selesai, lihat `evidence.md` §4. Tinggal pemeriksaan POS di peramban.) Dengan persetujuan pengembang (tugas ini menulis ke DB dev), jalankan quickstart §3–§5 di DB dev dan catat buktinya di `specs/068-odoo-catalog-seeder/evidence.md`:
  - `app:backup`, lalu pratinjau, lalu isi; catat durasinya (SC-008: < 2 menit);
  - periksa POS di peramban: DEMO menampilkan kategori, foto, tanpa item contoh, tiket masuk Bar/Dapur, konsol bersih; LIVE berisi katalog yang sama;
  - periksa log aktivitas;
  - jalan ulang menghasilkan 0 dibuat.
- [X] T042 (2026-10-25: pemilik menyetujui semua usulan apa adanya; status `Disetujui`, peran Pemilik. T003 diulang, dan diff `catalog.json` hanya mengubah blok `approval`. Tes katalog lulus 56/56. Quickstart §3–§5 dijalankan di DB dev, lihat `evidence.md` §4; pemeriksaan peramban tercatat di T041.) Awalnya diblokir sampai pemilik menjawab 10 pertanyaan. Masukkan jawabannya ke `specs/068-odoo-catalog-seeder/catalog-mapping.md`: kategori, varian, `## Harga varian khusus`, `## Grup pilihan`, serta status `Disetujui` beserta peran dan tanggal. Lalu ulangi T003, jalankan `tests/Feature/WjmCatalogFileTest.php`, dan jalankan quickstart §3–§5.
- [ ] T043 DIBLOKIR sampai T042 selesai dan pengembang memberi otorisasi eksplisit. Jalankan quickstart §6 di server pelanggan (pratinjau LIVE, isi LIVE dengan `--force`, DEMO opsional) dan simpan laporannya, tanpa data pribadi, di `specs/068-odoo-catalog-seeder/evidence.md`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T003 butuh T001 dan T002; T004 dan T005 bebas.
- **Foundational (Phase 2)**: butuh Phase 1 (kasus katalog sungguhan di T006 butuh T003). Fase ini MEMBLOKIR semua cerita.
- **US1 (Phase 3)**: butuh Phase 2.
- **US2 (Phase 4) dan US3 (Phase 5)**: butuh US1, karena keduanya memperluas `apply()` di service yang sama. Urutannya bebas, tetapi kerjakan berurutan karena berbagi berkas service.
- **US4 (Phase 6)**: butuh US1; paling baik setelah US3 agar laporannya sudah lengkap.
- **Polish (Phase 7)**:
  - T038–T040 setelah cerita yang diinginkan selesai;
  - T041 setelah T040;
  - T042 menunggu jawaban pemilik;
  - T043 menunggu T042 dan otorisasi pengembang.

### User Story Dependencies

- **US1 (P1)**: tanpa ketergantungan pada cerita lain (MVP).
- **US2 (P2)**: memakai pipeline US1; teruji mandiri lewat `WjmCatalogSeedDemoTest`.
- **US3 (P2)**: memakai pipeline US1; teruji mandiri lewat `WjmCatalogSeedRerunTest`.
- **US4 (P3)**: memakai pipeline US1, ditambah laporan US3; teruji mandiri lewat `WjmCatalogSeedPreviewTest`.

### Within Each User Story

Tes ditulis dulu dan harus gagal → implementasi service (berurutan, satu berkas) → pemanggil (perintah/seeder) → jalankan tes.

### Parallel Opportunities

- **Phase 1**: T002, T004, T005.
- **Phase 2**: pasangan (T006 → T007), (T008 → T009), (T010 → T011), serta T012, dapat dikerjakan paralel antarpasangan. T013 setelah T007 dan T012.
- **US1**: T014 dan T015 paralel; T022 (perintah) paralel dengan T016–T021 (service).
- **US2**: T024 dan T027 paralel dengan T025–T026.
- **US3**: T029 dan T031 paralel dengan T030 dan T032.
- **US4**: T034 dan T036 paralel dengan T035.
- **Polish**: T038 dan T039.

---

## Parallel Example: User Story 1

```bash
# Tes US1 bersamaan:
Task: "T014 Tulis tests/Feature/WjmCatalogSeedLiveTest.php"
Task: "T015 Tulis tests/Feature/WjmCatalogSeedCommandTest.php"

# Perintah dikerjakan bersamaan dengan service:
Task: "T022 Buat app/Console/Commands/SeedWjmCatalog.php"
Task: "T016–T021 berurutan di app/Services/WjmCatalogSeedService.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Kerjakan Phase 1, lalu Phase 2, lalu Phase 3 (US1).
2. **STOP and VALIDATE**: `WjmCatalogSeedLiveTest` dan `WjmCatalogSeedCommandTest` hijau.
3. MVP ini belum untuk produksi, karena jalan ulang belum aman sebelum US3.

### Incremental Delivery

1. **US1**: LIVE dapat diisi (dev).
2. **US2**: DEMO memakai menu asli; seeder tersedia.
3. **US3**: aman dijalankan ulang dan laporannya lengkap. Ini syarat minimum sebelum menyentuh server pelanggan.
4. **US4**: pratinjau; dianjurkan sebelum menyentuh LIVE pelanggan.
5. **Polish**: dokumentasi, suite penuh, dan bukti di dev. Setelah pemilik menyetujui, lanjut ke T042 dan T043.

---

## Notes

- [P] = berkas berbeda dan tanpa ketergantungan pada tugas yang belum selesai.
- Komentar, pesan, dan teks laporan berbahasa Indonesia (Konstitusi III).
- Jangan menjalankan `php artisan test` di kontainer tanpa flag `-e` (CLAUDE.md).
- Jangan commit atau push tanpa permintaan pengembang.
- Contoh data di tes bersifat generik; jangan menyalin foto atau data pelanggan ke `tests/`.
