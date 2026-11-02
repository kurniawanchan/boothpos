# Implementation Plan: Laporan Bahan dan Statistik Stok di Dashboard

**Branch**: `088-material-stock-report` | **Date**: 2026-11-01 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/088-material-stock-report/spec.md`
**Klasifikasi**: INTERNAL

## Summary

Pemilik mendapat tab **Bahan** di halaman Laporan (risiko habis per outlet, menu yang terancam, saran pembelian → draf PO per vendor, pemakaian/terbuang/selisih per periode, ekspor) dan kartu **statistik bahan & stok** di Dashboard yang bisa diklik menuju laporan tersaring. Semua angka diturunkan saat diminta dari buku besar bahan oleh SATU service `MaterialReport` (tanpa tabel baru), memakai biaya satuan yang sama dengan resep, aturan pewarisan grup modifier yang sama dengan POS, dan jalur PO yang sudah ada.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), JavaScript (Vue 3.5, Vite 6)
**Primary Dependencies**: Laravel, maatwebsite/excel (`SafeStringExport`), Pinia, Tailwind v4 tokens, Phosphor Icons
**Storage**: MySQL 8 (tabel yang sudah ada; tanpa migrasi)
**Testing**: PHPUnit feature tests (`tests/Feature/`, MySQL `warkop_wjm_test`, flag aman), Vitest (`qa-tests/`), peramban nyata (quickstart)
**Target Platform**: Server lokal/bersama per organisasi; SPA di peramban. Android tidak berubah.
**Project Type**: Web application (Laravel API + Vue SPA dalam satu repo)
**Performance Goals**: laporan posisi untuk 300 bahan × 3 outlet < 3 detik (SC-005); Dashboard memakai cache 30 detik
**Constraints**: angka = buku besar (FR-012/015); outlet dari server; mode DEMO/LIVE; rupiah hanya untuk menu `reports`; tanpa N+1 (agregasi `GROUP BY`, eager-load)
**Scale/Scope**: ratusan bahan, 1–5 outlet, jendela ≤ 90 hari, periode ≤ 366 hari

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Status | Catatan |
|---|---|---|
| I. Logika di Services, satu implementasi | ✅ | `MaterialReport` satu sumber untuk laporan, ekspor, Dashboard; pembaca balik grup di `ModifierResolver`; PO lewat `PurchaseOrderService::create`; biaya lewat `RecipeService::unitCost`. |
| I. Tidak lebih abstrak dari kebutuhan | ✅ | Tanpa tabel snapshot, tanpa setelan DB untuk preferensi (localStorage). |
| II. Tes backend MySQL + Vitest + peramban | ✅ | Tes fitur per endpoint, tes paritas laporan = buku besar = Dashboard; Vitest tab & kartu; verifikasi peramban di quickstart (dicatat jujur bila tak tersedia). |
| III. Token desain, konvensi galat, sembunyikan kontrol tanpa akses | ✅ | Tombol "Buat draf PO" tersembunyi tanpa izin PO; rupiah tersembunyi tanpa `reports`; 422/403 lewat handler pusat. |
| IV. Keamanan: server menghitung, otorisasi server, outlet dari server, log | ✅ | Saran & harga dihitung server; draf PO memakai outlet tunggal `OutletContext`, bukan body; PO menulis log lewat service yang ada. |
| IV. Satu mekanisme akses outlet di semua jalur baca | ✅ | Semua kueri lewat `OutletFilter`; kueri mentah baru didaftarkan di `OutletScopeRegistry` (`filtered`). |
| V. Tanpa N+1; cache dikunci semua masukan + waktu hitung | ✅ | Agregasi per jenis; cache Dashboard dikunci mode + outlet + periode + tanggal bisnis + zona/cutoff, `computed_at` di respons. |
| Dokumentasi | ✅ | OpenAPI di commit yang sama; catatan bertanggal CLAUDE.md; tidak menyentuh lingkup yang dipotong PRD (bukan manajemen PO baru — hanya draf lewat PO yang ada). |

Re-check pasca desain: tetap ✅ (tidak ada tabel baru, tidak ada jalur tulis baru selain pemanggilan service PO yang ada). Tidak ada pelanggaran → Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/088-material-stock-report/
├── spec.md
├── plan.md              # berkas ini
├── research.md          # D1–D11
├── data-model.md        # bentuk turunan (tanpa tabel baru)
├── quickstart.md
├── contracts/api.md     # 6 endpoint
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/MaterialReport.php                 # BARU: posisi, laju, status, saran, pemakaian, tren, rincian menu, statistik Dashboard
├── Services/MaterialPurchaseDrafts.php         # BARU: kelompokkan saran per vendor → PurchaseOrderService::create
├── Services/ModifierResolver.php               # + productsUsingGroups() (pembaca balik pewarisan grup)
├── Http/Controllers/Api/MaterialReportController.php   # BARU: position, usage, usageByMenu, purchaseDrafts, export
├── Http/Controllers/Api/DashboardController.php        # + materials()
├── Exports/MaterialReportExport.php            # BARU: 3 sheet, turunan SafeStringExport
└── Support/OutletScopeRegistry.php             # daftarkan kueri mentah baru
routes/api.php                                  # 6 rute
lang/{id,en}/reports.php                        # pesan
resources/js/
├── api/reports.js, api/dashboard.js            # klien
├── views/reports/MaterialsTab.vue              # BARU: ringkasan, saringan, tabel risiko, saran + draf PO, pemakaian
├── components/report/MaterialUsageByMenuModal.vue   # BARU
├── components/dashboard/DashboardMaterialStats.vue  # BARU: kartu klik-menuju-laporan
├── utils/materialReport.js                     # BARU: label status, nada, preferensi localStorage
└── locales/{id,en}.json
tests/Feature/MaterialReport*Test.php           # posisi/laju/status, pemakaian=buku besar, saran & draf PO, Dashboard, akses/outlet/mode, ekspor
qa-tests/{component,unit}/...                   # tab, kartu, util
docs/openapi-pos-mvp.yaml
```

**Structure Decision**: struktur web yang ada (Laravel `app/` + Vue `resources/js/`), tanpa paket baru. Tab baru mengikuti pola `views/reports/*Tab.vue`; Dashboard menambah satu komponen tanpa mengubah kartu KPI yang ada.

## Complexity Tracking

Tidak ada pelanggaran Konstitusi yang perlu dibenarkan.
