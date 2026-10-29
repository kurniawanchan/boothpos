# Implementation Plan: Sinkronisasi Stok Riil dengan Transaksi yang Sudah Ada

**Branch**: `082-stock-sync-from-transactions` (dari `develop`; nomor direktori spec ≠ nomor branch) | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/051-stock-sync-from-transactions/spec.md`

## Summary

Perintah artisan `app:sync-stock-from-transactions` (alat koreksi sekali pakai, mengikuti pola `app:clone-live-to-demo`): **dry-run secara bawaan** menghitung, per varian LIVE, berapa unit yang terjual/diserahterimakan SEBELUM pembaruan stok manual terakhirnya, menulis berkas Excel tinjauan, dan mencetak **kode rencana (hash)**. Penerapan hanya jalan dengan `--apply --approve=<hash>` (hash dari dry-run yang sudah ditinjau; bila data berubah hash berbeda → ditolak dan dibuat Excel baru) plus konfirmasi interaktif dan cadangan pengaman. Koreksi ditulis lewat `StockService::applyMovement()` bertipe `adjustment` (append-only, ter-audit, semua-atau-tidak-sama-sekali) dengan penanda `reference_type='stock_sync'` yang membuat perintah idempoten. Hasil negatif dibatasi ke 0 dan kekurangannya dilaporkan. Tanpa migrasi, tanpa route, tanpa perubahan frontend.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), artisan command

**Primary Dependencies**: yang sudah ada (Maatwebsite Excel, `StockService`, `BackupService`, `ModeGate`, `StockMovementReferences`); tidak ada dependensi baru

**Storage**: MySQL 8; skema tidak berubah. Berkas Excel di disk privat `local` (`storage/app/private/stock-sync/`)

**Testing**: PHPUnit `tests/Feature/StockTransactionSyncTest.php` (MySQL `boothpos_test`), tes binder ekspor

**Target Platform**: instalasi lokal per toko / Docker; dijalankan oleh pemilik atau pengembang di terminal

**Project Type**: web-service (artisan command + service); tidak ada UI

**Performance Goals**: ≤ 2.000 varian dalam satu kali jalan, jumlah query tetap (tidak tumbuh per varian)

**Constraints**: hanya data LIVE; tanpa menulis `current_stock` langsung; tanpa data pribadi pelanggan di Excel; semua-atau-tidak-sama-sekali

**Scale/Scope**: satu perintah, satu service, satu kelas ekspor kecil, satu berkas tes

## Constitution Check

| Prinsip | Status |
|---|---|
| I. Satu implementasi per concern | Lulus: penulisan stok HANYA lewat `StockService::applyMovement()`; pelaporan referensi memakai `StockMovementReferences` yang sudah ada; logika di `app/Services/StockSync/`, command tipis |
| II. Pengujian — MySQL, bukti dari sesi ini | Lulus (rencana): tes fitur penuh di `boothpos_test`; uji mutasi; satu uji nyata dry-run → apply pada database uji dengan data buatan. Tidak ada layar baru, jadi tidak ada uji browser |
| III. UX / bahasa | Pesan dan komentar Indonesia; tidak ada UI |
| IV. Keamanan | Lulus: otorisasi sisi server (`--by` harus pengguna owner/admin/inventory (`canAccessAnyMenu` daftar menu master data; `canManageMasterData()` hanya nama konsep, methodnya tidak ada)); jejak audit `stock_adjusted` ditulis `applyMovement` dalam transaksi yang sama; cadangan pengaman sebelum menulis; jumlah uang tidak dipercaya dari klien (tidak ada klien). Excel memuat nama pengguna-diketik → binder `=` wajib (lihat Decision 8) |
| V. Performa — tanpa N+1 | Lulus: kueri berkelompok (satu untuk acuan, satu untuk pergerakan jendela), jumlah query diuji tidak tumbuh per varian |
| OpenAPI | Tidak ada route/respons berubah → tidak ada perubahan OpenAPI |
| Catatan PRD | Tidak ada perubahan cakupan produk (alat operasi); catatan di RUNBOOK dan CLAUDE.md |

Pasca-desain: tidak ada pelanggaran; Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/051-stock-sync-from-transactions/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/command-and-report.md
├── checklists/requirements.md
└── tasks.md             # dibuat oleh /speckit-tasks
```

### Source Code (repository root)

```text
app/Console/Commands/SyncStockFromTransactions.php   # tipis: opsi, konfirmasi, cadangan, cetak
app/Services/StockSync/StockSyncPlanner.php          # plan(): baris per varian + hash (hanya baca, ModeGate::runAs('live'))
app/Services/StockSync/StockSyncApplier.php          # apply(plan, user): semua-atau-tidak-sama-sekali lewat StockService
app/Services/StockSync/StockSyncReport.php           # susun lembar Excel (Ringkasan, Varian, Sumber)
app/Exports/SheetArrayExport.php                     # guard "=" (binder) — lihat Decision 8
tests/Feature/StockTransactionSyncTest.php
docs/RUNBOOK.md                                      # cara pakai + pemulihan
CLAUDE.md                                            # catatan aturan non-obvious + blok rencana aktif
```

**Structure Decision**: tiga kelas service kecil di `app/Services/StockSync/` agar perencana (hanya-baca) terpisah dari penerap (menulis) — dry-run secara struktur tidak bisa menulis. Command hanya merangkai.
