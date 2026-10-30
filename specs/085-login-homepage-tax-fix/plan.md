# Implementation Plan: Beranda Login Dua Kolom & Perbaikan Hitungan Pajak

**Klasifikasi**: INTERNAL

**Branch**: `085-login-homepage-tax-fix` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/085-login-homepage-tax-fix/spec.md`

## Summary

Dua bagian terpisah, bisa dikirim terpisah.

1. **Pajak (P1)**: gejala "atur 100%, hitungan 10%" TIDAK muncul di server maupun data dev (research F1, F3), dan tidak ada literal tarif di jalur Android/web (F4, F5). Pendekatan: **reproduksi dulu** di Web, tablet, dan server; tambah satu kumpulan kasus paritas bersama (tarif non-10) untuk tiga suite tes; tambah tes anti-literal; segarkan tarif di pratinjau web; tampilkan nomor build di Android. Perbaiki hanya penyebab yang terbukti.
2. **Beranda login (P2, P3)**: ganti tata letak tiga kolom `PinScreen` menjadi dua kolom (Info Outlet berfoto + kartu pengguna | PIN), tombol "Ganti outlet / terminal" yang jelas, keypad lebih besar, hapus roda gigi. Hanya Android, tanpa perubahan API.

## Technical Context

**Language/Version**: PHP 8 / Laravel (server), Vue 3 + Vite (web), Kotlin + Jetpack Compose Material 3 (Android)

**Primary Dependencies**: yang sudah ada; tidak ada dependensi baru

**Storage**: MySQL 8 — tidak ada perubahan skema, tidak ada migrasi

**Testing**: PHPUnit (`tests/Feature/`, DB `warkop_wjm_test`), Vitest (`qa-tests/`), JUnit Android (`android-pos/app/src/test`)

**Target Platform**: server Laravel + SPA web lokal; tablet Android (lanskap utama, potret didukung)

**Project Type**: web-service + SPA + aplikasi mobile (`android-pos/`)

**Performance Goals**: jam berdetak 1 Hz tanpa menjank; tidak ada kueri baru

**Constraints**: tablet bisa offline (jam dan tanggal tetap jalan); layar PIN tanpa token (pengecualian Konstitusi IV 073 tidak diperlebar); teks UI Indonesia

**Scale/Scope**: 1 layar Android diubah, ±3 tes baru per platform, 0 endpoint berubah

## Constitution Check

| Prinsip | Status | Catatan |
|---------|--------|---------|
| I. Kode bersih, satu sumber | LULUS | Satu rumus pajak tetap `TaxCalculator`; web `taxOnBase` adalah pratinjau yang diikat ke kasus paritas yang sama. Tanpa titik ekstensi baru (foto latar bukan abstraksi per outlet). |
| II. Pengujian | LULUS dengan syarat | Tes PHPUnit/Vitest/JUnit wajib. Perubahan layar wajib dicoba di peramban/perangkat nyata; **belum bisa dilakukan di tahap rencana**, dicatat di `evidence.md` dan tidak diklaim selesai sebelumnya. |
| III. Konsistensi UX | LULUS | Token desain/tema dipakai, tanpa heksadesimal baru; teks Indonesia; istilah baku 084. |
| IV. Keamanan | LULUS | Server tetap menghitung pajak; layar PIN tidak menambah data keluar dari rute tanpa token; tidak ada perubahan otorisasi. |
| V. Performa | LULUS | Tidak ada kueri baru. Bila H3 (cache) terbukti, kunci cache memuat semua masukan dan waktu hitung. |
| Dokumentasi | LULUS | Tidak ada perubahan rute/respons, jadi OpenAPI tidak berubah; catatan bertanggal di CLAUDE.md bila F6 terbukti salah. |

Tidak ada pelanggaran; tabel Complexity Tracking tidak diperlukan.

Pasca-desain: tinjau ulang, tetap LULUS (tidak ada skema/rute baru).

## Project Structure

### Documentation (this feature)

```text
specs/085-login-homepage-tax-fix/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── outlet-background-reference.png
├── contracts/
│   ├── tax-parity-cases.md
│   └── pin-screen-ui.md
├── checklists/requirements.md
└── tasks.md            # dibuat /speckit-tasks
```

### Source Code (repository root)

```text
app/Support/TaxCalculator.php, PosSettings.php        # tidak diubah kecuali reproduksi membuktikan lain
tests/Feature/TaxParityTest.php                        # baru: kasus paritas + pesanan lengkap tarif non-10
qa-tests/fixtures/tax-parity-cases.json                # baru: satu sumber kasus (dibaca 3 suite)
qa-tests/unit/taxParity.test.js                        # baru
resources/js/stores/settings.js, views/PosView.vue     # muat ulang tarif saat POS dibuka (D4)

android-pos/app/src/main/java/com/warkop/pos/
├── ui/screens/PinScreen.kt                            # ubah: dua kolom, OutletInfoPanel, StaffCardGrid, tombol, keypad besar
├── ui/PinLayoutRule.kt                                # ubah: hapus THREE_COLUMN
└── res/drawable-nodpi/outlet_background.jpg           # baru: foto latar bawaan (dikecilkan)
android-pos/app/src/test/java/com/warkop/pos/
├── ui/PinLayoutRuleTest.kt, PinScreenCopyTest.kt      # ubah
└── data/TaxParityTest.kt                              # baru
```

**Structure Decision**: web application + mobile; semua perubahan mengikuti struktur yang ada.

## Fase

### Fase 0 — Reproduksi pajak (blokir untuk perbaikan pajak)
Reproduksi pada tarif 100% di Web, tablet, dan server; catat di `evidence.md` (angka, build APK, mode, `tax_show`). Hasil menentukan apakah H1–H4 benar dan perbaikan mana yang perlu.

### Fase 1 — Jaring pengaman pajak
Fixture paritas, tiga suite tes, tes anti-literal, tarif pratinjau web yang segar, nomor build Android. Ini dikerjakan APA PUN hasil Fase 0, karena menutup celah F2.

### Fase 2 — Beranda login
`OutletInfoPanel`, `StaffCardGrid`, keypad besar, tombol, hapus roda gigi, aset latar, perbarui tes tata letak.

### Fase 3 — Verifikasi
Suite backend/web/Android, lalu pemeriksaan peramban dan perangkat sesuai `quickstart.md`.

## Risiko

- Penyebab gejala 10% belum diketahui; bila Fase 0 tidak mereproduksinya di mana pun, laporkan apa adanya dan minta contoh pesanan nyata, jangan mengubah rumus yang terbukti benar.
- Foto latar menambah ukuran APK (dikecilkan ke ≈ 150–250 KB).
- Layar tablet nyata belum terverifikasi.
