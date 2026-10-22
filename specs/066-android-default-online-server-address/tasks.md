# Tasks: Alamat server online bawaan untuk aplikasi Android

**Input**: `specs/066-android-default-online-server-address/` (plan.md, spec.md, research.md, data-model.md, contracts/server-address.md, quickstart.md)
**Tests**: disertakan — konstitusi II mewajibkan tes untuk tiap perubahan perilaku.
**Format**: `- [ ] T### [P?] [US?] Deskripsi + path`. Perintah tes PHP di kontainer WAJIB memakai `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` sebelum nama layanan dan `-e CACHE_STORE=array -e SESSION_DRIVER=array` (CLAUDE.md).

## Phase 1: Setup

- [x] T001 (baseline tidak dijalankan terpisah sebelum perubahan; lihat evidence.md) Pastikan di cabang `066-android-default-online-server-address` dan baseline hijau: `cd android-pos && ./gradlew :app:testDebugUnitTest --tests '*ServerDiscoveryTest'` dan `npm test -- serverAddress` (catat hasil di `specs/066-android-default-online-server-address/evidence.md`)

## Phase 2: Foundational (memblokir kedua story)

- [x] T002 Buat `config/pos.php` dengan kunci `server_address.default_online` = `env('SERVER_ADDRESS_DEFAULT_ONLINE', 'https://smasher-nullify-subgroup.ngrok-free.dev')`; komentar (Indonesia) menyebut nilai ini HARUS sama dengan bawaan `pos.defaultOnlineUrl` di `android-pos/app/build.gradle.kts` dan bahwa hanya dipakai untuk petunjuk web
- [x] T003 [P] Tambah `defaultOnline(): ?string` di `app/Support/ServerAddress.php` (satu-satunya pembaca config itu; trim + buang `/` akhir lewat `clean()`, kosong → null); `current()` TIDAK berubah

**Checkpoint**: konfigurasi dan pembacanya siap.

## Phase 3: User Story 1 — Tablet tersambung lewat alamat online bawaan (P1) 🎯 MVP

**Tujuan**: pilihan Online + kolom online kosong → penyiapan terminal memakai alamat bawaan bila terjangkau.
**Tes mandiri**: `ServerDiscoveryTest` (tabel kontrak) hijau; manual quickstart #4.

### Tes US1 (tulis dulu, harus gagal)

- [x] T004 [P] [US1] Tambah kasus di `android-pos/app/src/test/java/com/warkop/pos/data/ServerDiscoveryTest.kt` (helper `discovery` menerima `defaultOnline`): (a) `online`+`null`+bawaan terjangkau → `Found(bawaan)`; (b) bawaan tak terjangkau → `Unreachable`; (c) `online`+`null`+tanpa bawaan → `NotConfigured("online")`; (d) `local`+`null`+bawaan terisi → `NotConfigured("local")`; (e) url terisi admin → `Found(url admin)` dan bawaan tidak ditanyai; (f) bawaan sama dengan alamat yang baru menjawab → tidak diprobe dua kali
- [x] T005 [US1] Sesuaikan tes lama `server menjawab tetapi pilihan admin belum punya alamat berhenti tanpa menebak` di berkas yang sama: kasus `online`+`null` kini memakai `defaultOnline = null` agar tetap `NotConfigured("online")` (perilaku lama bila build tanpa bawaan)

### Implementasi US1

- [x] T006 [US1] Ubah `ServerDiscovery` di `android-pos/app/src/main/java/com/warkop/pos/data/ServerDiscovery.kt`: tambah parameter `defaultOnline: String? = null`; saat `answer.url` kosong dan `answer.selected == "online"` dan `defaultOnline` terisi → pakai `defaultOnline` (dibersihkan seperti kandidat) sebagai `url`, lalu jalankan aturan yang ada (`url != base && probe(url) == null` → `Unreachable`, selain itu `Found`); `local` tetap `NotConfigured`. Perbarui KDoc (Indonesia, jelaskan alasan: jatuhan hanya untuk pilihan online yang kosong)
- [x] T007 [US1] Teruskan bawaan online di `ServerDiscovery.fromContext` (string resource `default_online_url`) pada berkas yang sama
- [x] T008 [US1] Ganti nilai bawaan `pos.defaultOnlineUrl` dari `""` menjadi `https://smasher-nullify-subgroup.ngrok-free.dev` di `android-pos/app/build.gradle.kts` dan perbarui komentar bagian atas (bawaan ada; `gradle.properties`/`local.properties` tetap bisa menimpa; nilai harus sama dengan `config/pos.php`)
- [x] T009 [US1] Jalankan `cd android-pos && ./gradlew :app:testDebugUnitTest --tests '*ServerDiscoveryTest' --tests '*PosStoreConnectionTest'` lalu `./gradlew :app:assembleDebug`; catat di `evidence.md` (jika JDK/Gradle gagal di mesin ini, catat apa adanya, jangan klaim lulus)

**Checkpoint**: US1 berfungsi dan teruji tanpa US2.

## Phase 4: User Story 2 — Admin tahu alamat bawaan yang berlaku (P2)

**Tujuan**: petunjuk di bawah kolom online menyebut alamat bawaan.
**Tes mandiri**: `GET /settings/server-address` memuat `default_online`; komponen web menampilkan/menyembunyikan petunjuk.

### Tes US2 (tulis dulu)

- [x] T010 [P] [US2] Tambah tes di `tests/Feature/ServerAddressTest.php`: GET memuat `default_online` sesuai `config('pos.server_address.default_online')`; config dikosongkan → `null`; PUT dengan `default_online` di body tidak mengubah `settings` dan responsnya tetap memuat nilai config; kolom online tetap tersimpan `NULL` setelah PUT kosong (FR-007)
- [x] T011 [P] [US2] Tambah tes di `tests/Feature/ServerAddressPublicTest.php`: respons publik `GET /api/v1/server-address` TIDAK memuat `default_online` dan, untuk `selected=online` + online kosong, `url` tetap `null`
- [x] T012 [P] [US2] Tambah tes komponen di `qa-tests/unit/serverAddress.test.js` (atau berkas komponen setara yang sudah memuat `ServerAddressSection`): petunjuk memuat alamat bila `default_online` ada; tidak dirender bila `null`; tampil juga saat kolom online terisi

### Implementasi US2

- [x] T013 [US2] Tambah `default_online` ke respons `show()` dan `update()` di `app/Http/Controllers/Api/ServerAddressController.php` lewat `ServerAddress::defaultOnline()`; `UpdateServerAddressRequest` tidak menerima/validasi kunci itu (diabaikan)
- [x] T014 [P] [US2] Tambah kunci `settings.server_address_online_default_hint` di `resources/js/locales/id.json` dan `en.json` (satu-satunya definisi di tiap bagian; hati-hati kunci ganda), mis. id: "Bila dikosongkan, tablet memakai alamat bawaan aplikasi: {url}. Isian di sini selalu didahulukan." / en: "If left empty, tablets use the app's built-in address: {url}. A value entered here always takes priority."
- [x] T015 [US2] Di `resources/js/views/settings/ServerAddressSection.vue`: simpan `defaultOnline` dari `apply(data)` (`data.default_online ?? ''`, tidak ikut dikirim di `save()`), dan tampilkan `:hint` pada `BaseInput` kolom online memakai kunci T014 hanya bila `defaultOnline` terisi
- [x] T016 [US2] Jalankan: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=ServerAddress` dan `npm test -- serverAddress localeKeys`; catat di `evidence.md`

### Temuan saat implementasi: aturan simpan 054 menghalangi skenario P1

`UpdateServerAddressRequest` menolak `selected=online` bila kolom online kosong, sehingga admin tidak bisa menyimpan "Online + kolom kosong" (skenario US1). Dilonggarkan HANYA bila ada alamat bawaan.

- [x] T022 [P] [US1] Tes di `tests/Feature/ServerAddressTest.php`: `selected=online` + online kosong → 200 saat `config('pos.server_address.default_online')` terisi, tersimpan online `NULL`; → 422 pada `selected` saat config dikosongkan (aturan 054 lama tetap berlaku tanpa bawaan); perbarui `test_defaults_are_empty_addresses_and_local_selected` (exact JSON kini memuat `default_online`)
- [x] T023 [US1] Longgarkan `withValidator()` di `app/Http/Requests/UpdateServerAddressRequest.php`: tolak online kosong hanya bila `ServerAddress::defaultOnline()` null; perbarui komentar dan pesan

**Checkpoint**: US1 + US2 mandiri dan lengkap.

## Phase 5: Polish & lintas-cakupan

- [x] T017 [P] Perbarui `docs/openapi-pos-mvp.yaml`: skema respons `GET/PUT /settings/server-address` + `default_online` (nullable string, readOnly); `GET /server-address` tidak berubah (konstitusi/PRD §9.5: satu commit dengan perubahan respons)
- [x] T018 [P] Tambah catatan bertanggal 2026-10-22 (feature 066) di `CLAUDE.md` setelah bagian 065: bawaan online ada di gradle + `config/pos.php` (harus sama), jatuhan hanya untuk `selected=online` + url kosong, alamat bawaan tidak pernah ditulis ke `settings` maupun dikirim lewat endpoint publik
- [x] T019 [P] Dokumentasikan variabel `SERVER_ADDRESS_DEFAULT_ONLINE` di `docs/RUNBOOK.md` (bagian alamat server, bila ada; kalau tidak, bagian konfigurasi `.env`) dan cara mengganti bawaan APK (`pos.defaultOnlineUrl`) bila alamat ngrok berubah
- [x] T020 Sapuan keamanan (akses kontrol/exposure data): pastikan alamat bawaan tidak muncul di `GET /server-address` publik, tidak di log aktivitas, dan `GET /settings/server-address` tetap lewat `authorize('viewAny', Setting::class)`; catat di `evidence.md`
- [~] T021 (item 1–3 lulus; item manual #4 di tablet BELUM diuji) Jalankan seluruh quickstart.md; item manual #4 (tablet/emulator) dicatat "belum diuji" bila tidak ada perangkat — jangan ditandai lulus

## Dependensi & urutan

- Phase 1 → Phase 2 → {US1, US2}. **US1 tidak bergantung pada Phase 2** (hanya Android); US2 bergantung pada T002–T003.
- Dalam story: tes (T004/T005, T010–T012) sebelum implementasi; T006 → T007 → T008 → T009; T013 → T015 → T016; T014 [P] dengan T013.
- Polish setelah kedua story.

## Peluang paralel

- T003 ∥ (T004, T005) · T010 ∥ T011 ∥ T012 ∥ T014 · T017 ∥ T018 ∥ T019.
- US1 (Android) dan US2 (server+web) dapat dikerjakan paralel setelah T002–T003.

## Strategi implementasi

- **MVP = US1** (T001, T004–T009): perilaku inti tablet; dapat dirilis tanpa US2 (APK saja, kontrak server tidak berubah).
- Lalu US2 (petunjuk admin), kemudian polish. Konsekuensi yang perlu diketahui: tanpa T002/T003 nilai petunjuk dan bawaan APK bisa berbeda; T008 dan T002 harus memakai string yang sama.
