**Klasifikasi**: INTERNAL

# Implementation Plan: Sel Satu Baris di Daftar Pre-order

**Branch**: `084-preorder-list-oneline-cells` (ditumpuk di atas `083-preorder-detail-prev-next`) | **Date**: 2026-10-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/053-preorder-list-oneline-cells/spec.md`

## Summary

Di daftar Pre-order: nomor pre-order dan Total tampil satu baris (kelas `whitespace-nowrap` pada isi slot sel — sel
`DataTable` tidak punya nowrap, `formatIDR` memakai spasi biasa, dan nomor patah setelah tanda hubung); tanggal
invoice/pengiriman di kolom status dispatch pindah dari teks di bawah label ke `BaseTooltip` yang ada (ikon ⓘ sebaris
di samping `StatusPill`, aksesibel keyboard, gelembung di-teleport sehingga tidak terpotong). `BaseTooltip` mendapat
`whitespace-pre-line` agar dua tanggal tampil di baris terpisah. Frontend saja; tidak ada migrasi, API, atau kunci
locale baru. Lihat research.md R1–R7.

## Technical Context

**Language/Version**: JavaScript (ES2022), Vue 3 SFC `<script setup>`

**Primary Dependencies**: Vue 3, vue-i18n, Tailwind v4 (CSS-first `@theme`), Phosphor Icons (duotone)

**Storage**: N/A (tidak ada perubahan data)

**Testing**: Vitest + @testing-library/vue (`qa-tests/component/`); verifikasi peramban nyata (quickstart.md)

**Target Platform**: SPA di peramban desktop (laptop booth), disajikan Laravel di `localhost`

**Project Type**: Web application (Laravel API + Vue SPA, satu repo)

**Performance Goals**: Tidak ada beban baru — satu fungsi string per baris (≤ 100 baris/halaman)

**Constraints**: Token desain saja (tanpa hex); salinan UI Indonesia/Inggris lewat locale yang ada; tidak boleh
mengubah panel detail, ekspor, dokumen

**Scale/Scope**: 1 view (`PreordersView.vue`), 1 komponen bersama (`BaseTooltip.vue`, perubahan satu kelas), 2 file tes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Status | Catatan |
|---|---|---|
| I. Code Quality / DRY | ✅ | Pakai ulang `BaseTooltip`, `StatusPill`, kunci locale & `formatDateTime` yang ada; satu fungsi `dispatchDatesLines` untuk ikon, tooltip, dan aria-label. |
| II. Testing | ✅ | Tes komponen ditulis dulu (TDD); tes lama yang bergantung pada teks tanggal di `<tr>` ditulis ulang dengan alasan (R6); pemeriksaan peramban nyata wajib (R7) karena jsdom tidak melakukan layout. |
| III. UX Consistency | ✅ | Kelas token saja; tooltip aksesibel keyboard (bukan `title`); tidak ada kontrol tersembunyi/terkunci baru; komentar kode dalam Bahasa Indonesia. |
| IV. Security | ✅ | Tidak ada data/endpoint baru; tidak ada data pelanggan tambahan di tooltip. |
| V. Performance | ✅ | Tanpa permintaan tambahan; teleport tooltip hanya saat dibuka (perilaku yang ada). |
| Docs & Change Discipline | ✅ | Tidak ada perubahan rute/respons → OpenAPI tidak disentuh; tidak ada perubahan cakupan PRD. |

Hasil re-check pasca desain: tetap lulus semua; tidak ada pelanggaran yang perlu dicatat di Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/053-preorder-list-oneline-cells/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── preorder-list-cells.md
├── checklists/
│   └── requirements.md
└── tasks.md             # dibuat /speckit-tasks
```

### Source Code (repository root)

```text
resources/js/
├── components/ui/
│   └── BaseTooltip.vue          # + whitespace-pre-line pada gelembung
└── views/
    └── PreordersView.vue        # sel preorder_number, dispatch_status, total_amount; dispatchDatesLines()

qa-tests/component/
├── PreordersView.test.js        # tes satu baris + tooltip; tulis ulang tes tanggal-di-sel
└── BaseTooltip.test.js          # + tes baris baru (\n) dirender sebagai baris terpisah
```

**Structure Decision**: Web application yang sudah ada; perubahan terbatas pada satu view dan satu komponen UI
bersama. Tidak ada file backend.

## Complexity Tracking

Tidak ada — tidak ada pelanggaran konstitusi.
