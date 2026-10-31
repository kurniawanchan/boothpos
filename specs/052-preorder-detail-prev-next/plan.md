**Klasifikasi**: INTERNAL

# Implementation Plan: Navigasi Sebelumnya/Berikutnya di Detail Pre-order

**Branch**: `083-preorder-detail-prev-next` (dari `develop`; nomor direktori spec ≠ nomor branch) | **Date**: 2026-10-31 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/052-preorder-detail-prev-next/spec.md`

## Summary

Panel detail pre-order mendapat tombol **‹ Sebelumnya** dan **Berikutnya ›** di header. Label tombol adalah nomor pre-order tujuan. Urutan mengikuti daftar Pre-order sebagaimana sedang tersaring dan terurut, dan melintasi batas halaman. Tetangga dihitung di server oleh endpoint baca-saja baru, `GET /preorders/{preorder}/neighbors`. Endpoint itu memakai ulang `applyFilters()` + `applySort()` milik `index()`, jadi urutannya tidak mungkin berbeda dari daftar. Posisi dicari dengan `pluck` id berurutan. `applySort()` mendapat pengikat stabil `preorders.id` di semua cabang. Tanpa pengikat itu, baris bernilai sama boleh berpindah urutan antar-query; ini juga memperbaiki paginasi daftar yang sekarang. SPA mengisi slot `header` `BaseDrawer` dan memuat tetangga paralel dengan detail setiap kali detail dibuka atau dimuat ulang. Respons usang dibuang lewat penghitung urutan. Navigasi yang gagal tidak menutup panel. Baris yang sedang dibuka disorot lewat `rowClass`, dan ←/→ di luar kolom isian ikut menavigasi. Tanpa migrasi, tanpa data baru.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13); JavaScript (Vue 3, Vite, Tailwind v4 CSS-first)

**Primary Dependencies**: yang sudah ada saja; tidak ada dependensi baru

**Storage**: MySQL 8, tanpa perubahan skema

**Testing**: PHPUnit `tests/Feature/PreorderNeighborsTest.php` (baru) + `PreorderSearchTest.php` (regresi urutan); Vitest `qa-tests/component/PreordersView.test.js`; `qa-tests/unit/localeKeys.test.js`; verifikasi browser sungguhan (quickstart.md)

**Target Platform**: instalasi lokal per toko (Laravel + Vue SPA di satu mesin; native atau Docker)

**Project Type**: web-service (API) + SPA

**Performance Goals**: detail tujuan tampil secepat membuka dari daftar (SC-005). Tetangga = 1 query `pluck` id + 1 query nomor, paralel dengan `GET /preorders/{id}`.

**Constraints**: urutan tetangga HARUS identik dengan daftar (satu builder); hanya baca; patuh DEMO/LIVE; tanpa hex; teks UI dwibahasa lewat i18n

**Scale/Scope**: puluhan sampai beberapa ribu pre-order per instalasi (ASUMSI, research Decision 2); 1 endpoint baru, 1 perubahan perilaku urutan, 1 layar

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Status | Catatan |
|---|---|---|
| I. Code Quality (satu implementasi, tanpa spekulasi) | ✅ | Tetangga memakai `applyFilters()`/`applySort()` yang sama dengan `index()`; keyset ditolak karena akan menyalin logika urutan (research D2). `BaseDrawer` tidak diubah, hanya slot-nya diisi. Logika posisi cukup kecil untuk method privat controller (pola `summary()`); tidak ada Service baru karena tidak ada logika bisnis/mutasi. |
| II. Testing | ✅ | Feature test backend di MySQL (pertama/tengah/terakhir, lintas halaman, filter, setiap `sort_by` + nilai sama, `in_list=false`, mode lain 404, invarian next↔previous). Tes komponen untuk tombol/label/nonaktif/race/keyboard. Verifikasi browser 12 langkah + konsol. |
| III. UX Consistency | ✅ | Token saja (`bg-mint-50` untuk sorotan). `BaseTooltip` untuk alasan nonaktif. Tombol nonaktif BUKAN soal izin peran, melainkan keadaan sementara; preseden fitur 027/035 (research D7). Teks UI lewat i18n `en`/`id`. Komentar kode berbahasa Indonesia. |
| IV. Security | ✅ | Hanya baca. Otorisasi = sama dengan `GET /preorders` dan `show()` (sudah dicek: tidak ada FormRequest/inline/Policy untuk pre-order selain `auth:sanctum` + `HasDataMode`). `sort_by` tetap whitelist. Respons hanya `id` + `preorder_number`, tanpa PII pelanggan. |
| V. Performance | ✅ | `pluck` tanpa eager-load atau hidrasi model. Tidak ada N+1. Tidak ada dependensi frontend baru. |
| Dokumentasi | ✅ | OpenAPI dalam commit yang sama (path + skema baru, catatan pengikat). Tidak ada perubahan lingkup PRD (navigasi UI pada layar yang sudah ada). Pointer `CLAUDE.md` diperbarui. |

**Hasil**: lolos, tanpa pelanggaran. **Re-check pasca-desain**: tetap lolos. Desain Phase 1 tidak menambah tabel, peran, atau jalur tulis.

## Project Structure

### Documentation (this feature)

```text
specs/052-preorder-detail-prev-next/
├── plan.md              # file ini
├── research.md          # Phase 0 — 9 keputusan
├── data-model.md        # Phase 1 — tanpa skema; objek turunan "tetangga"
├── quickstart.md        # Phase 1 — tes + verifikasi browser
├── contracts/
│   └── preorder-neighbors.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — belum dibuat)
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/PreorderController.php   # + neighbors(); applySort() + pengikat preorders.id
routes/api.php                                    # + GET /preorders/{preorder}/neighbors
docs/openapi-pos-mvp.yaml                         # + path & skema PreorderNeighbors; catatan urutan
tests/Feature/PreorderNeighborsTest.php           # BARU
tests/Feature/PreorderSearchTest.php              # + regresi pengikat (nilai sama stabil lintas halaman)

resources/js/api/preorders.js                     # + getPreorderNeighbors(id, params)
resources/js/views/PreordersView.vue              # slot header drawer, state tetangga, detailSeq,
                                                  #   navigasi, keyboard, rowClass sorotan
resources/js/locales/en.json, id.json             # + kunci preorders.nav_*
qa-tests/component/PreordersView.test.js          # + tes navigasi
```

**Structure Decision**: struktur web-app proyek yang ada (Laravel `app/` + SPA `resources/js/`, tes backend `tests/Feature/`, tes frontend `qa-tests/`). Tidak ada komponen baru: tombol ditulis di slot header di dalam `PreordersView.vue`. Ekstraksi komponen hanya bila tes/ukuran file menuntutnya saat implementasi (satu pemakai, Prinsip I).

## Implementation Notes (untuk /speckit-tasks)

1. **Backend dulu (TDD)**: tes `PreorderNeighborsTest` yang gagal → `applySort()` + pengikat → `neighbors()` + rute → OpenAPI.
   - `neighbors()`: `$ids = $this->applySort($this->applyFilters(Preorder::query(), $request), $request)->pluck('preorders.id')`. Cari indeks `$preorder->id`, lalu ambil nomor tetangga dengan `Preorder::whereIn('id', [...])->pluck('preorder_number', 'id')`. Cabang `customer_name` sudah `select('preorders.*')` dan `pluck` menggantinya; cek bahwa JOIN tidak menduplikasi baris (relasi pelanggan many-to-one, aman).
   - Pengikat: `orderBy('preorders.id', $sortDir)` di setiap cabang. Bawaan menjadi `orderByDesc('preorders.created_at')->orderByDesc('preorders.id')`.
2. **SPA**:
   - `openDetailById(id, { fromNav })`: naikkan `detailSeq`, lalu muat `getPreorder` + `getPreorderNeighbors(id, filterParams)` paralel dan buang hasil usang. Mode `fromNav` tidak menutup panel saat gagal: tampilkan toast `nav_target_gone` dan muat ulang tetangga untuk `detail.id` lama.
   - `openDetail(row)` dan `refreshDetail()` ikut memuat tetangga.
   - `filterParams` = salinan `params` tanpa `page`/`per_page`.
   - Keyboard: handler di wrapper konten drawer. Lewati bila `e.target` adalah kolom isian/`[contenteditable]`, atau bila ada modifier key.
   - Sorotan: `rowClass` yang sudah ada mungkin dipakai untuk penanda kualitas data (bendera ongkir/alamat). Gabungkan kelasnya, jangan timpa.
3. **Verifikasi**: suite penuh backend (host, atau container dengan `-e`), `npm test`, `npm run build`, lalu quickstart 12 langkah di browser.

## Complexity Tracking

Tidak ada pelanggaran konstitusi; tabel tidak diperlukan.
