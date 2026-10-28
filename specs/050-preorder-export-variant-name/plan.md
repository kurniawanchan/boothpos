# Implementation Plan: Nama Varian di Ekspor Pre-order

**Branch**: `081-preorder-export-variant-name` (dari `develop`; nomor direktori spec ≠ nomor branch) | **Date**: 2026-10-29 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/050-preorder-export-variant-name/spec.md`

## Summary

`GET /preorders/export` menulis kolom `products` berisi SKU saja. Rencana: tambah SATU kolom hanya-ekspor `variant_names`, tepat di sebelah `products`, berisi nama varian pada urutan yang sama dengan SKU/kuantitas/harga. Kolom `products` tidak berubah (importer membacanya), importer membaca per NAMA kolom sehingga kolom baru diabaikan seperti `created_at`/`updated_at`, dan template impor tidak memuatnya. Nama diambil dari varian (termasuk yang di-soft-delete) lewat eager-load, tanpa N+1. Satu perubahan kecil pendamping: `GenericArrayExport` memaksa sel string berawalan `=` menjadi teks agar nama varian tidak bisa menjadi rumus. Tanpa migrasi.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), Maatwebsite Excel / PhpSpreadsheet

**Primary Dependencies**: yang sudah ada saja; tidak ada dependensi baru

**Storage**: MySQL 8, tanpa perubahan skema (`preorder_items.variant_id` sudah ada, `restrictOnDelete`)

**Testing**: PHPUnit `tests/Feature/PreorderExportImportTest.php` (tambah/ubah tes); frontend tidak berubah

**Target Platform**: instalasi lokal per toko (Laravel + Vue SPA)

**Project Type**: web-service (API) + SPA; perubahan hanya di sisi API

**Performance Goals**: ekspor tidak menambah query per baris (satu eager-load tambahan)

**Constraints**: round-trip ekspor→impor harus tetap utuh; kolom `products`/`quantities`/`unit_prices` identik; DEMO/LIVE scope ikut berlaku

**Scale/Scope**: ekspor ratusan pre-order; satu kolom baru

## Constitution Check

| Prinsip | Status |
|---|---|
| I. Kualitas kode — logika di Services, satu implementasi | Lulus: perubahan di `PreorderExportImportService::export()`; satu perbaikan binder di `GenericArrayExport` (satu tempat), bukan salinan per pemanggil |
| II. Pengujian — tes backend di MySQL | Lulus: tes fitur baru + ubah tes tata letak kolom; dijalankan di `boothpos_test` |
| III. Konsistensi UX | Tidak ada UI; komentar/pesan Indonesia |
| IV. Keamanan — akses sisi server, snapshot historis | Lulus dengan catatan: otorisasi ekspor tidak berubah (owner/admin). Nama varian dibaca dari data TERKINI, bukan snapshot (lihat research Decision 2) — tidak menyentuh angka keuangan |
| V. Performa — tanpa N+1 | Lulus: `with('items.variant')` + `withTrashed()`; diuji dengan penghitung query |
| OpenAPI sejalan | Wajib: deskripsi `/preorders/export` diperbarui di commit yang sama |
| Catatan PRD untuk perubahan cakupan | Tidak perlu: tidak ada perubahan cakupan, hanya tambahan kolom ekspor |

Pasca-desain: tidak ada pelanggaran; Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/050-preorder-export-variant-name/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/export-columns.md
├── checklists/requirements.md
└── tasks.md             # dibuat oleh /speckit-tasks
```

### Source Code (repository root)

```text
app/Services/PreorderExportImportService.php   # export(): eager-load + kolom variant_names
app/Exports/GenericArrayExport.php             # pengikat nilai: string berawalan "=" → teks
tests/Feature/PreorderExportImportTest.php     # kolom baru, sejajar, round-trip, N+1, rumus
docs/openapi-pos-mvp.yaml                      # deskripsi /preorders/export
CLAUDE.md                                      # catatan singkat + blok rencana aktif
```

**Structure Decision**: perubahan terbatas pada dua kelas backend yang sudah ada; tidak ada berkas aplikasi baru. Frontend tidak disentuh (tombol Ekspor hanya mengunduh berkas).
