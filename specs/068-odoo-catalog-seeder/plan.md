# Implementation Plan: Pengisian awal katalog Odoo WJM ke mode LIVE dan DEMO

**Klasifikasi**: INTERNAL

**Branch**: `068-odoo-catalog-seeder` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/068-odoo-catalog-seeder/spec.md`

## Summary

Warkop WJM 3 pindah dari Odoo. Katalognya (10 kategori, 79 item, 111 varian, 61 foto) diisi langsung ke database BoothPOS, untuk mode LIVE dan DEMO, oleh satu service `WjmCatalogSeedService`. Pemanggilnya perintah `app:seed-wjm-catalog` (opsi mode, outlet, pratinjau) dan seeder tipis `WjmCatalogSeeder`. Keduanya tidak ikut penyiapan bawaan.

Sumber datanya `database/seeders/data/wjm-odoo/catalog.json` beserta foto. Berkas ini dibangkitkan alat dev dari ekspor XLSX dan tabel pemetaan yang disetujui pemilik; nama staf dan berkas mentah tidak pernah masuk repo. Pengisian ditolak selama katalog berstatus draf, dan katalog divalidasi lengkap sebelum ada penulisan apa pun.

Setiap mode adalah satu transaksi yang memakai jalur tulis yang sudah sah: `ProductCodeGenerator`, hook harga `PriceService`, `ModifierGroupService`, `OutletMenuService::setCarried`, `ImageUploadService`, dan `ActivityLogger`. Tidak ada stok yang ditulis, tidak ada migrasi, dan tidak ada rute baru.

## Technical Context

**Language/Version**: PHP `^8.3` · Laravel `^13.17` (`composer.json`) · Python 3, pustaka standar, hanya untuk alat dev pembangkit katalog

**Primary Dependencies**: yang sudah ada saja. JSON dibaca dengan `json_decode`; tanpa pustaka JSON Schema atau XLSX baru. `maatwebsite/excel ^4.0` sudah ada tetapi tidak dipakai, karena aplikasi tidak membaca XLSX.

**Storage**: MySQL 8, tabel yang ada (data-model §2); disk `public` (`products/`) untuk foto; berkas data di `database/seeders/data/wjm-odoo/`

**Testing**: PHPUnit `^12.5.12` feature test di `tests/Feature/` terhadap MySQL uji (`warkop_wjm_test`), `Storage::fake('public')`, katalog mini di `tests/Support/wjm-catalog/`, ditambah pemeriksaan POS di peramban (quickstart §4)

**Target Platform**: server/laptop toko (native atau Docker), bentuk (a) satu mesin dan (b) server bersama

**Project Type**: web-service (Laravel); antarmuka fitur berupa CLI

**Performance Goals**: kedua mode < 2 menit (SC-008). Perkiraan beberapa detik: ±200 kueri cek kode, ±400 insert, 61 salinan foto per mode.

**Constraints**:
- semua-atau-tidak-sama-sekali per mode (FR-018);
- tidak mengubah data yang ada, kecuali penarikan item contoh di DEMO (FR-016/017);
- kode unik lintas mode (`categories.code`, `outlets.code`, `products.code_prefix`, `product_variants.sku` unik di seluruh tabel);
- service harus sama hasilnya saat *guarded* (perintah) maupun *unguarded* (`db:seed`) (research D6);
- tanpa kueri mentah atas tabel ber-outlet (`OutletScopeRegistryTest`) dan tanpa `applyMovement` (`StockLedgerInvariantTest`).

**Scale/Scope**: per mode 1 outlet (bila perlu dibuat), 2 stasiun, 10 kategori, 79 item, 111 varian, 1 grup pilihan, 61 foto (57 unik, ±0,5 MB di repo). Kode baru: 1 service, 2 kelas Support (katalog, laporan), 1 perintah, 1 seeder, 4 metode di service yang ada, 8 berkas tes + 1 trait, 1 alat dev.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Penilaian | Status |
|---|---|---|
| **I. Satu implementasi per concern; logika di Services** | Logika di `WjmCatalogSeedService`; perintah dan seeder hanya pemanggil. Semua tulisan lewat jalur sah (research D3). Kode baru untuk kode kategori dan segmen masuk `ProductCodeGenerator`; foto lokal masuk `ImageUploadService` (mendelegasikan ke `store()`). Tidak ada kerangka "importer Odoo" umum, cukup kebutuhan WJM ini. | PASS |
| **II. Tes, bukti sesi** | Setiap FR/SC dipetakan ke tes (quickstart §2) terhadap MySQL nyata; perintah tes memakai flag `-e` wajib. Tidak ada layar yang berubah, tetapi tampilan POS (foto, kategori, item contoh hilang, tiket Bar/Dapur) diperiksa di peramban dengan konsol bersih (quickstart §4). | PASS |
| **III. Konsistensi UX, bahasa Indonesia** | Tanpa UI baru. Laporan CLI, komentar, dan pesan galat berbahasa Indonesia. | PASS |
| **IV. Keamanan** | Tanpa permukaan baru (CLI saja, akses server). Outlet ditentukan server dan operator, bukan dari body request. Log aktivitas ditulis di transaksi yang sama (aturan impor massal). Foto lewat `ImageUploadService` (MIME dari isi, ≤ 5 MB) ke disk `public` (gambar produk memang publik). Nama staf dibuang, ditambah tes kunci terlarang (SC-006). Harga dari katalog yang disetujui, bukan dari input klien. | PASS |
| **V. Kinerja** | Nama yang sudah ada dimuat sekali per mode; tanpa N+1 kecuali cek keunikan kode per item, yang memang wajib lintas mode. Target < 2 menit. | PASS |
| **Stack & lingkungan** | MySQL; bekerja di bentuk (a) dan (b); satu organisasi; tidak menyentuh `boothpos`. | PASS |
| **Dokumentasi** | `docs/RUNBOOK.md` dan `CLAUDE.md` diperbarui. OpenAPI tidak berubah (tanpa rute). PRD tidak berubah (research D14). | PASS |

**Pasca-desain (Phase 1)**: data-model, kontrak CLI, dan skema katalog tidak menambah jalur tulis, tabel, atau rute. Tidak ada pelanggaran; Complexity Tracking tidak diperlukan.

## Project Structure

### Documentation (this feature)

```text
specs/068-odoo-catalog-seeder/
├── spec.md                 # spesifikasi (CONFIDENTIAL)
├── source-analysis.md      # analisis 3 ekspor Odoo (CONFIDENTIAL)
├── catalog-mapping.md      # usulan pemetaan + persetujuan pemilik (CONFIDENTIAL); sumber keputusan
├── plan.md                 # berkas ini
├── research.md             # D1–D14
├── data-model.md           # catalog.json, pemetaan ke tabel, status jalan
├── quickstart.md           # verifikasi dev & produksi
├── contracts/
│   ├── cli.md              # app:seed-wjm-catalog + WjmCatalogSeeder, kode keluar, laporan, log
│   └── catalog.schema.json # JSON Schema catalog.json
├── tools/build_catalog.py  # alat dev (Python stdlib): XLSX + catalog-mapping.md → catalog.json + foto
├── checklists/requirements.md
└── tasks.md                # Phase 2 (/speckit-tasks) — belum dibuat
```

### Source Code (repository root)

```text
app/Console/Commands/SeedWjmCatalog.php         # app:seed-wjm-catalog: opsi, ConfirmableTrait, cetak laporan, kode keluar
app/Services/WjmCatalogSeedService.php          # orkestrasi per mode (D3, D5, D7, D8, D9, D11)
app/Support/WjmCatalog.php                      # muat + validasi catalog.json (D10)
app/Support/WjmCatalogSeedReport.php            # laporan per mode + cetak ke konsol (dipakai perintah & seeder) (D11)
app/Services/ProductCodeGenerator.php           # + freeCategoryCode(), segmentCandidates(), firstFreeCodePrefix() (D4)
app/Services/ImageUploadService.php             # + storeLocalFile() (D8)
database/seeders/WjmCatalogSeeder.php           # pemanggil tipis (D1); TIDAK didaftarkan di DatabaseSeeder
database/seeders/data/wjm-odoo/catalog.json     # hasil bangkitan (D2)
database/seeders/data/wjm-odoo/photos/*.jpg     # 57 foto unik, nama = SHA-256 12 heksa
tests/Feature/WjmCatalogFileTest.php            # katalog sungguhan + aturan validasi (D10)
tests/Feature/WjmCatalogCodeAllocationTest.php  # kode kategori & segmen (D4)
tests/Feature/ImageUploadServiceLocalFileTest.php  # storeLocalFile (D8)
tests/Feature/WjmCatalogSeedLiveTest.php        # US1
tests/Feature/WjmCatalogSeedCommandTest.php     # US1: opsi, konfirmasi produksi, kode keluar
tests/Feature/WjmCatalogSeedDemoTest.php        # US2 + paritas perintah vs seeder (D6)
tests/Feature/WjmCatalogSeedRerunTest.php       # US3
tests/Feature/WjmCatalogSeedPreviewTest.php     # US4
tests/Support/WjmCatalogFixtures.php            # trait pembantu tes
tests/Support/wjm-catalog/catalog.json          # katalog mini: 3 kategori, 7 item, 11 varian (multi-varian, kombinasi, berfoto, harga 0, kit)
tests/Support/wjm-catalog/photos/*.jpg          # 1 JPEG kecil hasil GD
docs/RUNBOOK.md · CLAUDE.md                     # dokumentasi operasional dan aturan domain
```

**Structure Decision**: Laravel monolit yang ada. Service di `app/Services/`, pemuat/validator di `app/Support/` (pola `MasterDataSheets`, `ModeGate`), perintah di `app/Console/Commands/`, data seeder di `database/seeders/data/` (direktori baru). Tes di `tests/Feature/` dengan data di `tests/Support/`, mengikuti konvensi yang ada.

## Langkah implementasi (ringkas)

1. **Alat dev dan data**:
   - Normalisasi sintaks `catalog-mapping.md` (data-model §5): sel kombinasi menjadi `Ayam × Paket`, ditambah bagian `## Harga varian khusus` dan `## Grup pilihan`.
   - Tulis `tools/build_catalog.py`, memakai ulang pembaca XLSX yang dikeraskan (tanpa DTD/entitas, batas ukuran).
   - Bangkitkan `catalog.json` (draf) dan foto, lalu cocokkan hitungannya dengan `source-analysis.md`.
2. **`App\Support\WjmCatalog`**: muat dan validasi seluruh aturan D10, kumpulkan semua masalah. Tambah `WjmCatalogFileTest`.
3. **Service yang ada**: `ProductCodeGenerator::freeCategoryCode()` dan `segmentCandidates()`, `ImageUploadService::storeLocalFile()`, beserta tesnya (Roti Bakar ×7 → segmen berbeda; kode kedua mode tidak bertabrakan).
4. **`WjmCatalogSeedService`**, per mode di dalam `ModeGate::runAs()` + `DB::transaction`:
   1. resolusi outlet (buat bila kosong);
   2. stasiun;
   3. kategori;
   4. grup pilihan;
   5. item + varian (`track_stock=false`) + foto;
   6. `setCarried($p, [$target])`;
   7. tarik item contoh (DEMO);
   8. log aktivitas;
   9. laporan.

   Gagal → rollBack + hapus foto. Pratinjau → rollBack tanpa foto.
5. **Pemanggil**: `app:seed-wjm-catalog` dan `WjmCatalogSeeder` (lempar galat bila ada mode yang tidak `applied`).
6. **Tes per cerita** (`WjmCatalogSeed{Live,Command,Demo,Rerun,Preview}Test`): semua skenario quickstart §2, termasuk perintah vs seeder (D6).
7. **Dokumentasi**: bagian RUNBOOK (pratinjau → isi → jalan ulang → produksi); catatan bertanggal di CLAUDE.md (aturan domain: `WjmCatalogSeedService` satu-satunya pengisi katalog WJM; sumber `catalog.json` dibangkitkan dari `catalog-mapping.md`).
8. **Setelah pemilik menyetujui**: bangkitkan ulang (`approved`), jalankan tes, quickstart §3–5 di dev, lalu §6 di server pelanggan.

**Ketergantungan**: langkah 1–7 bisa dikerjakan saat katalog masih draf, karena tes perilaku memakai katalog mini. Pengisian sungguhan (langkah 8) menunggu persetujuan pemilik atas 10 pertanyaan di `catalog-mapping.md`.

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dibenarkan.
