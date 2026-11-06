# Implementation Plan: Ringkasan Pilihan di Dialog "Attach" Grup Modifier

**Branch**: `091-modifier-attach-selection-summary` | **Date**: 2026-11-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/091-modifier-attach-selection-summary/spec.md`

## Summary

Hanya frontend, satu komponen: `components/restaurant/ModifierGroupsAdmin.vue`. (1) Nilai awal kosong = "Tidak ada" dengan menyalurkan prop `allLabel` yang SUDAH ADA di `BaseMultiSelect` (mengganti label isian DAN baris pertama daftar). (2) Daftar nama di bawah isian untuk ≥ 2 pilihan, dibangun dari `options` yang sama, dibatasi tinggi dan bisa digulir. `BaseMultiSelect` (9 pemakai) TIDAK diubah. Lihat research.md D1–D4.

## Technical Context

**Language/Version**: Vue 3 (`<script setup>`), Tailwind v4 CSS-first

**Primary Dependencies**: vue-i18n, komponen `BaseMultiSelect`, `BaseModal` (sudah ada); tanpa dependensi baru

**Storage**: N/A (tanpa server, migrasi, atau API; payload `PUT` lampiran identik)

**Testing**: Vitest + Vue Test Utils, `qa-tests/component/ModifierGroupsAdmin.test.js` (sudah ada, diperluas)

**Target Platform**: SPA web (layar kerja owner/admin); Android tidak terlibat

**Project Type**: web-application (hanya sisi Vue)

**Performance Goals**: N/A; pemetaan nama O(n) pada ratusan produk, dihitung `computed`

**Constraints**: tanpa hex mentah (token `@theme`); teks UI Indonesia + Inggris; tinggi dialog dibatasi

**Scale/Scope**: 1 berkas komponen, 2 berkas locale, 1 berkas tes

## Constitution Check

| Prinsip | Penilaian |
|---|---|
| I. Kode bersih / DRY | Lulus. Memakai prop `allLabel` yang ada, bukan fork komponen. Satu helper kecil untuk nama terpilih dipakai dua isian (tanpa duplikasi). |
| II. Pengujian | Lulus. Tes komponen ditambah sebelum/bersama implementasi (lihat quickstart). |
| III. Konsistensi UX | Lulus. Token warna/ukuran yang ada (`text-muted-3`, `text-[12.5px]`), teks Indonesia + Inggris; tidak ada kontrol baru yang bergantung peran. |
| IV. Keamanan | Tidak terdampak: tanpa rute, izin, atau data baru. |
| V. Performa | Lulus. Daftar dibatasi tinggi; `computed` murni. |
| Dokumentasi | OpenAPI tidak berubah (tanpa perubahan rute/respons). Catatan bertanggal di CLAUDE.md hanya bila ada keputusan non-obvious (lihat D1). |

Tidak ada pelanggaran; Complexity Tracking tidak diperlukan. Dicek ulang setelah desain: tetap lulus.

## Project Structure

### Documentation (this feature)

```text
specs/091-modifier-attach-selection-summary/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
└── tasks.md             # /speckit-tasks (belum dibuat)
```

(Tanpa `contracts/`: tidak ada antarmuka eksternal baru.)

### Source Code (repository root)

```text
resources/js/
├── components/restaurant/ModifierGroupsAdmin.vue   # allLabel + daftar nama di bawah isian
└── locales/{en,id}.json                            # kunci dining_setup.* baru untuk daftar nama
qa-tests/component/ModifierGroupsAdmin.test.js      # tes baru
```

**Structure Decision**: perubahan terkurung di komponen pemanggil; `components/ui/BaseMultiSelect.vue` tidak disentuh supaya 8 layar lain (kosong = "Semua" sebagai filter) tidak berisiko regresi.
