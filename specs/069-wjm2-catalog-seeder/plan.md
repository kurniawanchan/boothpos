# Implementation Plan: Pengisian katalog Odoo Warkop WJM 2 ke mode LIVE dan DEMO

**Klasifikasi**: CONFIDENTIAL — merujuk katalog nyata milik pelanggan.

**Branch**: `069-wjm2-catalog-seeder` | **Date**: 2026-10-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/069-wjm2-catalog-seeder/spec.md`

## Summary

Isi outlet "Warkop WJM 2" di LIVE (sudah ada, kode `WJD`) dan DEMO (dibuat) dengan 80 item / 141 varian dari ekspor Odoo WJM 2. Stok awalnya 100 per varian, dan data WJM 3 tidak tersentuh. Pendekatannya: **perluas pipeline 068**, jangan menduplikasinya (research D1).

1. **Alat bangkit** `build_catalog.py` membaca ekspor Odoo berbahasa Indonesia (D2) dan menerima nilai atribut dari tabel pemetaan, karena ekspor ini tidak memuatnya (D3).
2. **Format katalog 2** memindahkan kebijakan per outlet ke data:
   - cara menentukan outlet tujuan (D8);
   - nama stasiun per peran (D6);
   - penarikan item lain dari menu (D9);
   - kategori yang dikecualikan atau diganti nama (D4).
3. **`WjmCatalogSeedService`** mendapat empat perbaikan yang juga berlaku untuk WJM 3:
   - identitas item per outlet (D7);
   - stasiun per item agar tiket masuk ke stasiun yang benar (D5);
   - pencocokan stasiun menurut peran (D6);
   - outlet tujuan yang tidak pernah jatuh ke outlet lain (D8).
4. **Satu perubahan di luar pengisian**: `GET /pos/categories` mengikuti outlet shift, supaya tablet tidak menampilkan tab kosong milik outlet lain (D10).

## Technical Context

**Language/Version**: PHP `^8.3` · Laravel `^13.17`; Python 3, pustaka standar (alat dev)

**Primary Dependencies**: yang sudah ada saja; tanpa pustaka baru

**Storage**: MySQL 8, tabel yang ada (tanpa migrasi); disk `public/products`; data di `database/seeders/data/wjm2-odoo/`

**Testing**: PHPUnit feature test terhadap `warkop_wjm_test` dengan flag `-e` wajib; katalog mini kedua di `tests/Support/wjm2-catalog/`; skrip potret WJM 3 di DB dev; pemeriksaan POS di peramban/tablet

**Target Platform**: server/laptop toko, bentuk (a) dan (b)

**Project Type**: web-service (Laravel); antarmuka fitur berupa CLI, ditambah satu perubahan endpoint baca

**Performance Goals**: kedua mode < 2 menit (SC-005); perkiraan beberapa detik (80 item, 141 varian, 141 gerakan stok, 11 foto per mode)

**Constraints**:
- data WJM 3 identik sebelum dan sesudah, kecuali penanda "dijual di WJM 2" (FR-006, FR-019, SC-002);
- semua-atau-tidak-sama-sekali per mode;
- kode unik lintas mode;
- stok hanya lewat `StockService` (`StockLedgerInvariantTest`);
- kueri mentah atas tabel ber-outlet harus terdaftar (`OutletScopeRegistryTest`);
- hasil sama saat *guarded* maupun *unguarded*;
- katalog WJM 3 yang dibangkitkan ulang tidak boleh berubah isi (hanya kunci format 2).

**Scale/Scope**: 1 outlet DEMO baru, 2 stasiun per mode, ≤ 13 kategori (sebagian dipakai bersama), 80 item, 141 varian, 11 foto, 141 gerakan stok per mode. Perubahan kode: alat bangkit (alias kolom, nilai atribut dari pemetaan, format 2), `WjmCatalog` (format 2), `WjmCatalogSeedService` (D5–D9), `WjmCatalogSeedReport` (stok awal, penarikan LIVE, kategori dikecualikan), `PosCategoryController` + aturan "dijual di outlet" bersama, 1 seeder tipis, ±5 berkas tes baru/diperluas.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Penilaian | Status |
|---|---|---|
| **I. Satu implementasi per concern** | Satu pipeline untuk kedua katalog (D1). Kebijakan per outlet berupa data katalog, bukan cabang kode per pelanggan. Aturan "dijual di outlet ini" untuk `/products?pos=1` dan `/pos/categories` diangkat ke satu tempat (D10). Stok lewat `StockService`, harga lewat hook `PriceService`, menu lewat `OutletMenuService`. | PASS |
| **II. Tes, bukti sesi** | Setiap FR/SC dipetakan ke tes (quickstart §2). Larangan "jangan sentuh WJM 3" dibuktikan dengan tes potret dan potret DB dev (D13). Perubahan yang terlihat di POS diperiksa di peramban/tablet. | PASS |
| **III. Konsistensi UX** | Tanpa layar baru. Tab kategori POS menjadi per outlet, konsisten dengan daftar item yang sudah per outlet. Teks laporan berbahasa Indonesia. | PASS |
| **IV. Keamanan** | Outlet ditentukan katalog atau operator di server, bukan dari body request. `/pos/categories` memakai `OutletContext`, mekanisme bersama. Nama staf (Penanggung Jawab) tidak dibaca ke keluaran (FR-017). Log aktivitas di transaksi yang sama. Foto lewat `ImageUploadService`. | PASS |
| **V. Kinerja** | Pemuatan sekali per mode; `/pos/categories` tetap satu kueri dengan subkueri "tidak dijual di sini" (pola `ProductController`), tanpa N+1. | PASS |
| **Stack & lingkungan** | MySQL; bentuk (a) dan (b); satu organisasi, banyak outlet; tidak menyentuh `boothpos`. | PASS |
| **Dokumentasi** | `docs/openapi-pos-mvp.yaml` diperbarui untuk D10 di commit yang sama. `docs/RUNBOOK.md` §14 dan `CLAUDE.md` diperbarui. Spec 068 mendapat catatan bertanggal untuk D6/D7. | PASS |

**Pasca-desain (Phase 1)**: tanpa tabel atau rute baru. Satu perubahan perilaku endpoint baca (D10) dicatat di kontrak dan OpenAPI. Format katalog naik ke 2 dan katalog WJM 3 dibangkitkan ulang dengan pembanding diff. Tidak ada pelanggaran; Complexity Tracking tidak diperlukan.

## Project Structure

### Documentation (this feature)

```text
specs/069-wjm2-catalog-seeder/
├── spec.md                 # spesifikasi (CONFIDENTIAL)
├── source-analysis.md      # analisis ekspor Odoo WJM 2 (CONFIDENTIAL)
├── catalog-mapping.md      # usulan pemetaan + persetujuan pemilik (dibuat di tugas pertama)
├── plan.md                 # berkas ini
├── research.md             # D1–D14
├── data-model.md           # tabel pemetaan, catalog.json format 2, dampak DB
├── quickstart.md           # verifikasi dev & produksi
├── contracts/
│   ├── cli.md              # perbedaan CLI/laporan terhadap 068
│   ├── pos-categories.md   # GET /pos/categories per outlet (D10)
│   └── catalog.schema.json # JSON Schema format 2
├── photo-overrides/        # foto pengganti dari pemilik (opsional)
├── checklists/requirements.md
└── tasks.md                # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
specs/068-odoo-catalog-seeder/tools/build_catalog.py  # dipakai bersama: alias kolom ID/EN, nilai atribut dari pemetaan, format 2 (D2–D4, D6, D8, D9, D12)
app/Support/WjmCatalog.php                     # validasi format 2 + accessor outlet(), stations(), withdrawOthers()
app/Services/WjmCatalogSeedService.php         # D5 station per item, D6 peran stasiun, D7 identitas per outlet, D8 outlet, D9 penarikan
app/Support/WjmCatalogSeedReport.php           # baris stok awal, kategori dikecualikan, nilai dari pemetaan
app/Http/Controllers/Api/PosCategoryController.php  # D10
app/Http/Controllers/Api/ProductController.php      # D10: pakai aturan "dijual di outlet" bersama
app/Support/OutletCarriedRule.php (atau kelas baru sejenis)  # D10: satu tempat aturan "dijual di outlet X"
database/seeders/Wjm2CatalogSeeder.php         # pemanggil tipis (D1)
database/seeders/data/wjm2-odoo/catalog.json   # hasil bangkitan WJM 2
database/seeders/data/wjm2-odoo/photos/*.jpg   # 11 foto + foto pengganti
database/seeders/data/wjm-odoo/catalog.json    # dibangkitkan ulang ke format 2 (isi sama)
specs/068-odoo-catalog-seeder/catalog-mapping.md  # + baris Stasiun / Outlet / Tarik item (nilai yang mempertahankan perilaku 068)
tests/Feature/WjmCatalogFileTest.php           # diperluas: format 2, katalog WJM 2
tests/Feature/WjmCatalogSecondOutletTest.php   # baru: outlet kedua + potret outlet pertama (D7, D5, D9, D13)
tests/Feature/WjmCatalogOutletResolutionTest.php  # baru: create_when (D8)
tests/Feature/WjmCatalogStationRoleTest.php    # baru: peran stasiun (D6)
tests/Feature/WjmCatalogSeedRerunTest.php      # diperluas: jalan ulang outlet kedua
tests/Feature/PosCategoriesOutletTest.php      # baru: D10
tests/Support/wjm-catalog/catalog.json         # ke format 2
tests/Support/wjm2-catalog/catalog.json        # katalog mini outlet kedua (nama bentrok, kategori bersama, nilai atribut dari pemetaan)
docs/RUNBOOK.md · CLAUDE.md · docs/openapi-pos-mvp.yaml · specs/068-odoo-catalog-seeder/spec.md
```

**Structure Decision**: Laravel monolit yang ada. Alat bangkit tetap satu berkas di folder 068 (dipakai kedua fitur, tidak disalin). Data seeder per katalog di `database/seeders/data/<katalog>/`. Tes di `tests/Feature/`, data uji di `tests/Support/`.

## Langkah implementasi (ringkas)

1. **Tabel pemetaan WJM 2 (draf)**: usulkan kategori per item (sembilan dipetakan ke nama WJM 3), Hot/Ice untuk 22 item `Varian`, rasa Indomie seperti WJM 3, dan `?` untuk sisanya (JASJUS ×4, SHOWCASE ×19, MIE SEDAP ×11). Tandai kategori tanpa item. Tambahkan baris Outlet / Tarik item / Stasiun / Stok awal 100.
2. **Alat bangkit**: alias kolom ID/EN, deteksi berkas per akhiran model, `## Nilai atribut`, `?` (DRAF = nama sementara; Disetujui = galat), `→` nama kategori, kategori tanpa item, `## Nama item khusus`, perapian spasi, keluaran format 2. Bangkitkan ulang katalog WJM 3; diff isi harus nol.
3. **`WjmCatalog` format 2** + `WjmCatalogFileTest`; perbarui fixture mini.
4. **Service** (tes dulu, satu per keputusan): D8 resolusi outlet → D6 peran stasiun → D7 identitas per outlet → D5 `station_id` per item → D9 penarikan menurut katalog → laporan.
5. **Tes potret outlet kedua** (D13) di `WjmCatalogSecondOutletTest`.
6. **D10** `/pos/categories` + aturan bersama + OpenAPI.
7. **`Wjm2CatalogSeeder`**, RUNBOOK, CLAUDE.md, catatan bertanggal di spec 068.
8. **Suite penuh**; pratinjau di DB dev (quickstart §4).
9. **Persetujuan pemilik** → isi sungguhan di DB dev dengan potret sebelum/sesudah (quickstart §5–§6). Server pelanggan hanya dengan otorisasi eksplisit.

## Complexity Tracking

Tidak ada pelanggaran konstitusi.
