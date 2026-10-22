# Implementation Plan: Alamat server online bawaan untuk aplikasi Android

**Branch**: `066-android-default-online-server-address` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/066-android-default-online-server-address/spec.md`

## Summary

Tablet baru bertanya ke server alamat mana yang dipilih admin (065 / US6). Bila admin memilih Online tetapi kolom online kosong, jawabannya `url: null` dan tablet berhenti di "belum diatur". Fitur ini menanam `https://smasher-nullify-subgroup.ngrok-free.dev` sebagai alamat online bawaan di aplikasi dan membuat `ServerDiscovery` memakainya sebagai jatuhan **hanya** ketika pilihan = online dan alamat online di server kosong. Halaman web Server address menampilkan alamat bawaan itu sebagai petunjuk (nilai read-only dari konfigurasi server). Tidak ada migrasi, tidak ada endpoint baru, kontrak publik `GET /server-address` tidak berubah.

## Technical Context

**Language/Version**: Kotlin (android-pos, JDK 17 target) · PHP 8.3 / Laravel · Vue 3
**Primary Dependencies**: yang sudah ada saja (OkHttp, kotlinx.serialization, vue-i18n); tanpa dependensi baru
**Storage**: N/A — tidak ada tabel/baris baru; alamat bawaan TIDAK ditulis ke `settings` (FR-007)
**Testing**: JUnit (`ServerDiscoveryTest`), PHPUnit feature test, Vitest (`qa-tests/`)
**Target Platform**: tablet Android + SPA web
**Project Type**: mobile-app + web-service
**Performance Goals**: tidak berubah; satu probe tambahan paling banyak (batas 3 dtk, sudah ada)
**Constraints**: alamat bawaan hanya jatuhan, isian admin selalu menang (FR-003); aturan 065 (alamat tersimpan tidak ditanya ulang) tetap
**Scale/Scope**: 1 berkas Kotlin logika + 1 gradle + 1 controller/support + 1 Vue + 2 locale

## Constitution Check

- **I (satu implementasi per concern)**: logika "alamat mana yang dipakai" tetap di `ServerDiscovery` (tablet) dan `ServerAddress` (server). Alamat bawaan sisi server dibaca dari satu kunci konfigurasi, tidak diulang di controller. PASS
- **II (tes)**: tiap FR punya tes (lihat quickstart). PASS
- **III (UI Indonesia, i18n)**: petunjuk baru di `id.json` dan `en.json`; `localeKeys.test.js` menjaga. PASS
- **IV (keamanan)**: endpoint publik tidak berubah dan tetap hanya membocorkan alamat terpilih. Alamat bawaan hanya muncul di `GET /settings/server-address` (terotentikasi, izin `viewAny` Setting). Alamat ngrok bukan rahasia. PASS
- **V (kinerja)**: tidak ada kueri baru. PASS

Pasca-desain: tidak ada pelanggaran; tabel Complexity Tracking tidak diperlukan.

## Project Structure

```text
specs/066-android-default-online-server-address/
├── spec.md · plan.md · research.md · data-model.md · quickstart.md
└── contracts/server-address.md

android-pos/app/build.gradle.kts                                  # default pos.defaultOnlineUrl
android-pos/app/src/main/java/com/warkop/pos/data/ServerDiscovery.kt   # jatuhan online
android-pos/app/src/test/java/com/warkop/pos/data/ServerDiscoveryTest.kt
config/pos.php (atau config yang ada)                             # server_address.default_online
app/Support/ServerAddress.php · app/Http/Controllers/Api/ServerAddressController.php
resources/js/views/settings/ServerAddressSection.vue · locales/{en,id}.json
qa-tests/ (Vitest) · tests/Feature/ (PHPUnit) · docs/openapi-pos-mvp.yaml
```

## Langkah implementasi (ringkas)

1. **Android**: ubah nilai bawaan `pos.defaultOnlineUrl` di gradle menjadi alamat ngrok. `ServerDiscovery` menerima `defaultOnline: String?`; bila jawaban `url == null` **dan** `selected == "online"` dan `defaultOnline` terisi → perlakukan sebagai `url = defaultOnline` (probe bila beda dari `base`, aturan "jangan pindah ke alamat mati" sama). `selected == "local"` dengan `url == null` tetap `NotConfigured`. `fromContext` meneruskan string resource `default_online_url`.
2. **Server**: kunci konfigurasi `server_address.default_online` (env `SERVER_ADDRESS_DEFAULT_ONLINE`, bawaan = alamat ngrok); `GET /settings/server-address` menambah `default_online` (read-only; `PUT` mengabaikannya). `ServerAddress::current()` tidak berubah.
3. **Web**: di bawah kolom online, petunjuk dengan `{url}` dari `default_online`; tersembunyi bila nilainya kosong.
4. **Dok**: OpenAPI (field `default_online`), catatan bertanggal di CLAUDE.md.
