# Implementation Plan: Identitas Toko di Judul Tab, Sidebar, Favicon, dan POS Selalu Terlihat

**Branch**: `072-store-identity-branding` | **Date**: 2026-10-25 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification dari `/specs/072-store-identity-branding/spec.md`

## Summary

Tiga perubahan SPA kecil plus satu field respons: (1) `GET /settings/features` menambah `store_logo_url`; (2) sidebar (`RailNav.vue`) memakai logo + nama toko; (3) satu composable `useBranding` menyetel `document.title` dan `<link rel="icon">` dari `settings` store; (4) `AppShell.vue` dibatasi setinggi layar (`h-screen`) sehingga `PosView` pas satu layar, daftar produk menggulir sendiri, dan footer keranjang selalu terlihat. Penyimpanan Identitas toko kini menyegarkan `settings` store agar perubahan langsung tampak. Tanpa migrasi.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel (API), Vue 3 + Pinia + Vite (SPA)
**Primary Dependencies**: Tailwind v4 (token `@theme`), Vitest
**Storage**: MySQL 8; memakai baris `settings` yang sudah ada (`store_name`, `store_name_demo`, `store_logo_path`, `app_name`) — tanpa tabel/kolom baru
**Testing**: `php artisan test` (di kontainer WAJIB `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array`), Vitest di `qa-tests/`, plus cek browser nyata
**Target Platform**: Browser pada laptop kasir (≥1366×768) dan tablet
**Project Type**: Web (Laravel API + SPA satu mesin)
**Performance Goals**: Tidak ada permintaan jaringan baru; logo ikut payload `features` yang sudah dimuat sekali
**Constraints**: Kode/komentar/UI copy berbahasa Indonesia; tanpa hex literal; `docs/openapi-pos-mvp.yaml` ikut commit yang sama
**Scale/Scope**: 1 field API, 4 berkas SPA, 1 composable baru

## Constitution Check

| Prinsip | Status | Catatan |
|---|---|---|
| I. Kualitas kode / satu sumber | PASS | Aturan gabung judul hidup di SATU util (`utils/documentTitle.js`); URL logo diselesaikan server lewat `ImageUploadService::url` seperti endpoint lain; nama toko tetap dari `ModeGate`-aware `features`. |
| II. Pengujian | PASS (rencana) | Tes fitur `features` memuat `store_logo_url`; Vitest untuk util judul, RailNav, composable; cek browser nyata termasuk layar 1366×768 dan konsol. |
| III. Konsistensi UX | PASS | Hanya token CSS yang ada; salinan Indonesia; tanpa kontrol baru yang bergantung peran. |
| IV. Keamanan | PASS | Logo dan nama toko bukan rahasia (sudah tampil di struk/features); nama dirender sebagai teks (`{{ }}`, `document.title`), bukan HTML; tidak ada otorisasi baru. |
| V. Performa | PASS | Tanpa permintaan tambahan; tanpa dependensi baru. |

Gerbang pasca-desain: tidak ada pelanggaran; Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/072-store-identity-branding/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/features-endpoint.md
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/SettingsController.php   # features(): + store_logo_url
docs/openapi-pos-mvp.yaml                          # + store_logo_url pada /settings/features
resources/js/
├── utils/documentTitle.js                         # BARU: gabung "{toko} – {aplikasi}" (aturan kosong/sama)
├── composables/useBranding.js                     # BARU: watch store → document.title + link[rel=icon]
├── stores/settings.js                             # + storeLogoUrl (state, load, setter)
├── components/layout/RailNav.vue                  # merek: logo/huruf + nama toko
├── components/layout/AppShell.vue                 # min-h-screen → h-screen; panggil useBranding
└── views/settings/StoreIdentitySection.vue        # simpan → segarkan settings store
resources/views/app.blade.php                      # <link rel="icon"> bawaan eksplisit
tests/Feature/SettingsFeaturesStoreLogoTest.php
qa-tests/unit/documentTitle.test.js, qa-tests/component/RailNav*.test.js, AppShell.test.js
```

**Structure Decision**: Pola yang ada dipertahankan — field baru lewat `features` (sudah dibaca semua peran sebelum layar Pengaturan), state di `settings` store, tampilan di komponen layout.

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dibenarkan.
