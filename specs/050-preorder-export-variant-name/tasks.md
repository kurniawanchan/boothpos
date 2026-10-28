# Tasks: Nama Varian di Ekspor Pre-order

**Input**: `/specs/050-preorder-export-variant-name/` (plan.md, spec.md, research.md, data-model.md, contracts/export-columns.md, quickstart.md)

**Tests**: WAJIB (Konstitusi II) — tes backend di MySQL `boothpos_test`; tanpa perubahan frontend.

**Format**: `- [ ] [ID] [P?] [Story] Deskripsi + path berkas`

> Peringatan: JANGAN menjalankan `php artisan test` di dalam container `app` tanpa `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`. Di host: `php -d memory_limit=1G vendor/bin/phpunit --filter PreorderExportImportTest`.

## Phase 1: Setup

- [X] T001 Jalankan `PreorderExportImportTest` di host untuk memastikan dasar hijau sebelum perubahan (catat jumlah tes) — `tests/Feature/PreorderExportImportTest.php` — 23 tes lulus (104 asersi)

## Phase 2: Foundational (memblokir US1)

**Tujuan**: sel string berawalan `=` tidak pernah menjadi rumus (FR-007, SC-004), di satu tempat.

- [X] T002 Tulis tes gagal dulu: ekspor lewat `GenericArrayExport` dengan nilai `=1+1` lalu baca ulang workbook; sel harus bertipe `s` dan nilainya `=1+1` — `tests/Feature/PreorderExportImportTest.php` (atau berkas tes baru `tests/Feature/GenericArrayExportTest.php`)
- [X] T003 `GenericArrayExport` mengimplementasikan `WithCustomValueBinder`: string berawalan `=` → `setValueExplicit(TYPE_STRING)`, selain itu `DefaultValueBinder`; komentar Indonesia berisi alasan (formula injection) dan mengapa HANYA awalan `=` (research Decision 5) — `app/Exports/GenericArrayExport.php`
- [X] T004 Mutasi: matikan binder sebentar, pastikan T002 gagal (sel bertipe `f`), lalu pulihkan — dilakukan: dengan binder dimatikan, tes binder + tes sel xlsx gagal; eager-load dimatikan, tes jumlah query gagal

**Checkpoint**: T002 hijau; ekspor lain yang memakai `GenericArrayExport` tidak berubah perilakunya selain kasus awalan `=`.

## Phase 3: User Story 1 — Nama varian terbaca di ekspor (P1) 🎯 MVP

**Independent Test**: ekspor pre-order berisi dua varian; `variant_names` sejajar dengan `products`, kolom lama identik.

- [X] T005 [US1] Tes: dua item (varian ber-nama berkoma) → `variant_names` = `"Merah, L | Biru"`, jumlah bagian = jumlah SKU, `products`/`quantities`/`unit_prices` identik dengan sebelumnya — `tests/Feature/PreorderExportImportTest.php`
- [X] T006 [P] [US1] Tes: varian tanpa nama menjaga posisi (`"A |  | C"`), varian soft-deleted tetap tampil namanya, pre-order tanpa item → string kosong — `tests/Feature/PreorderExportImportTest.php`
- [X] T007 [P] [US1] Tes: nama berawalan `=` pada item pertama tersimpan sebagai teks setelah dibaca ulang dari xlsx — `tests/Feature/PreorderExportImportTest.php`
- [X] T008 [P] [US1] Tes: jumlah query ekspor tidak tumbuh bersama jumlah pre-order (panggilan pemanasan dulu, lalu hitung query untuk 2 vs 10 pre-order) — `tests/Feature/PreorderExportImportTest.php`
- [X] T009 [US1] `PreorderExportImportService::export()`: eager-load `items.variant` dengan `withTrashed()`, tambah kunci `variant_names` tepat setelah `products` (nama varian dipisah ` | `, kosong bila tak ada); komentar Indonesia merujuk research Decision 1–4 — `app/Services/PreorderExportImportService.php`
- [X] T010 [US1] Ubah tes tata letak lama `test_export_carries_dispatch_columns_and_read_only_created_updated` agar mengharapkan `variant_names` setelah `products` (kolom impor `HEADINGS` tetap dibandingkan tanpa `variant_names`) — `tests/Feature/PreorderExportImportTest.php`
- [X] T011 [US1] Tes: filter ekspor (status/event/tanggal/pencarian) tetap berlaku dan setiap baris lolos punya `variant_names` — `tests/Feature/PreorderExportImportTest.php`

**Checkpoint**: US1 berdiri sendiri — ekspor menampilkan nama varian.

## Phase 4: User Story 2 — Round-trip impor utuh (P1)

**Independent Test**: berkas hasil ekspor diunggah ke impor (dry run) tanpa galat baru.

- [X] T012 [US2] Tes: berkas persis hasil ekspor (termasuk `variant_names`) diimpor `dry_run` → tanpa `row_errors`, SKU/kuantitas/harga sama (perluas `test_export_then_reimport_round_trips_without_modification`) — `tests/Feature/PreorderExportImportTest.php`
- [X] T013 [P] [US2] Tes: nama varian yang diubah manual di berkas tidak mengubah barang yang diimpor (SKU tetap penentu); berkas lama tanpa kolom itu tetap diimpor — `tests/Feature/PreorderExportImportTest.php`
- [X] T014 [P] [US2] Tes: `template()` dan template unduhan TIDAK memuat `variant_names` — `tests/Feature/PreorderExportImportTest.php`
- [X] T015 [US2] Bila T012–T014 menemukan impor menolak kolom baru, perbaiki di `PreorderExportImportService::import()` (diperkirakan tidak perlu: impor membaca per nama kolom) — `app/Services/PreorderExportImportService.php` — TIDAK PERLU: impor membaca per nama kolom, T012–T014 hijau tanpa perubahan impor

**Checkpoint**: US1 + US2 hijau.

## Phase 5: Polish & lintas-fitur

- [X] T016 Perbarui deskripsi `/preorders/export` di `docs/openapi-pos-mvp.yaml` (kolom `variant_names`, sejajar dengan `products`, pemisah ` | `, hanya-baca, diabaikan impor, tidak ada di template); validasi YAML — di commit yang sama dengan kode (Konstitusi)
- [X] T017 [P] Tambah catatan singkat bergaya CLAUDE.md di bagian ekspor/impor pre-order (kolom hanya-ekspor baru, binder `=` di `GenericArrayExport`, `SheetArrayExport`/`MultiSheetArrayExport` belum dilindungi) — `CLAUDE.md`
- [X] T018 Jalankan suite backend penuh `php -d memory_limit=1G vendor/bin/phpunit` dan laporkan hasil apa adanya — 1418 tes lulus (7150 asersi); suite pertama menemukan regresi (ekspor profit melempar "Unable to bind unstringable array"), diperbaiki dengan pengikat Maatwebsite + tes sel-array
- [ ] T019 Verifikasi manual quickstart.md langkah 2–5 di aplikasi nyata (ekspor, buka xlsx, nama `=1+1`, template). JIKA tidak dijalankan, catat "tidak dijalankan" di sini dan di PR — jangan ditandai selesai — TIDAK DIJALANKAN (tidak ada pengecekan manual di Excel/aplikasi nyata)

## Dependencies & urutan

- T001 → T002 → T003 → T004 (Foundational) → Phase 3 → Phase 4 → Phase 5.
- T005–T008 ditulis sebelum T009 (tes dulu); T009 lalu T010–T011.
- T012 bergantung pada T009; T013–T014 boleh paralel dengan T012.
- Semua tes ada di satu berkas, jadi tugas `[P]` hanya paralel secara logis (tulis dalam satu sesi, jalankan sekali).

## Strategi

- MVP = Phase 2 + Phase 3 (kolom + keamanan rumus), lalu Phase 4 membuktikan round-trip, lalu Phase 5.
- Pemisahan commit seperti 048/049: `docs(spec)` (direktori spec + `feature.json`), lalu `feat(preorder)` (kode, tes, OpenAPI, CLAUDE.md).
- Di luar cakupan: melindungi `SheetArrayExport`/`MultiSheetArrayExport` dan kebijakan "paksa semua string jadi teks" (PR terpisah).
