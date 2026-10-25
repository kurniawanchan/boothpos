# Tasks: Identitas Toko di Judul Tab, Sidebar, Favicon, dan POS Selalu Terlihat

**Input**: Design documents dari `/specs/072-store-identity-branding/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/features-endpoint.md, quickstart.md
**Tests**: Disertakan — konstitusi II mewajibkan tes backend (`tests/Feature/`), tes frontend (`qa-tests/`), dan cek browser nyata.

**Format**: `- [ ] T### [P?] [US?] Deskripsi dengan path berkas`

Perintah tes backend di kontainer WAJIB: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test <filter> -e CACHE_STORE=array -e SESSION_DRIVER=array`.

## Phase 1: Setup

- [X] T001 Jalankan `npm test` dan tes backend `--filter=SettingsFeatures` sebagai garis dasar (catat hasil; jangan lanjut bila ada kegagalan baru yang bukan bawaan)

## Phase 2: Foundational (memblokir US1–US3)

**Tujuan**: logo toko tersedia di state SPA untuk semua peran.

- [X] T002 Tulis tes gagal `tests/Feature/SettingsFeaturesStoreLogoTest.php`: `GET /api/v1/settings/features` memuat `store_logo_url` = `null` tanpa logo, = URL logo setelah `store_logo_path` diisi, dan terbaca oleh peran `cashier` (bukan hanya owner/admin)
- [X] T003 Tambah `store_logo_url` di `features()` pada `app/Http/Controllers/Api/SettingsController.php` lewat `$this->imageUploadService->url(Setting::get('store_logo_path'))` dengan komentar Indonesia (kenapa di sini: `GET /settings` hanya owner/admin); tes T002 harus lulus
- [X] T004 [P] Tambah `store_logo_url` pada respons `GET /settings/features` di `docs/openapi-pos-mvp.yaml` (dekat `app_name`, sekitar baris 613)
- [X] T005 Tambah state `storeLogoUrl` (bawaan `null`), isi dari `data.store_logo_url ?? null` di `load()`, dan aksi `setStoreLogoUrl(url)` di `resources/js/stores/settings.js`

**Checkpoint**: state `settings.storeLogoUrl`, `storeName`, `appName` siap dipakai.

## Phase 3: User Story 1 — Sidebar menampilkan nama dan logo toko (P1) 🎯 MVP

**Tujuan**: sidebar memakai logo + nama toko, dengan fallback lama.
**Tes mandiri**: isi nama toko + logo → sudut kiri atas sidebar menampilkan keduanya; kosongkan → tampilan lama.

- [X] T006 [P] [US1] Perbarui `qa-tests/component/RailNav.test.js`: (a) logo + nama toko tampil; (b) nama ada, logo tidak → kotak huruf pertama nama toko; (c) nama kosong → huruf + nama aplikasi; (d) `<img>` error → jatuh ke kotak huruf; (e) saat diciut nama tersembunyi; (f) nama berisi markup tampil sebagai teks
- [X] T007 [US1] Ubah blok merek di `resources/js/components/layout/RailNav.vue` (sekitar baris 114–121): judul = `settings.storeName || settings.appName`; slot 28×28 memuat `<img :src="settings.storeLogoUrl">` (`object-contain`, `@error` menyembunyikannya) bila ada, selain itu kotak huruf `bg-brand` dari huruf pertama judul; baris kedua tetap `settings.tierLabel`; pakai token CSS, tanpa hex
- [X] T008 [US1] Ubah `resources/js/views/settings/StoreIdentitySection.vue`: setelah simpan nama toko (`saveStoreIdentity`) panggil `settings.load()`, dan setelah unggah logo (`uploadStoreLogo`) panggil `settings.setStoreLogoUrl(res.store_logo_url)` agar sidebar langsung berubah tanpa muat ulang (FR-006)
- [X] T009 [P] [US1] Tambah/lengkapi tes `qa-tests/component/` untuk `StoreIdentitySection` (cari berkas tes yang ada dengan `ls qa-tests/component | grep -i StoreIdentity`; bila tidak ada, buat `StoreIdentitySection.test.js`): simpan nama memicu penyegaran store, unggah logo memanggil `setStoreLogoUrl`

**Checkpoint**: US1 berdiri sendiri dan dapat didemokan.

## Phase 4: User Story 2 — Judul tab memuat nama toko dan nama aplikasi (P1)

**Tujuan**: `document.title` = "{toko} – {aplikasi}" dan ikut berubah tanpa muat ulang.
**Tes mandiri**: nama toko "Warkop WJM" + nama aplikasi "Warkop WJM" → judul "Warkop WJM"; beda → "A – B".

- [X] T010 [P] [US2] Tulis tes gagal `qa-tests/unit/documentTitle.test.js`: gabung normal "Toko – Aplikasi"; toko kosong/spasi → aplikasi; sama tanpa beda huruf besar/kecil → sekali; aplikasi kosong → `BoothPOS`
- [X] T011 [US2] Buat `resources/js/utils/documentTitle.js` (`buildDocumentTitle(storeName, appName)`, memakai `resolveAppName` dari `utils/appName.js`, komentar Indonesia); tes T010 lulus
- [X] T012 [US2] Buat composable `resources/js/composables/useBranding.js`: `watch` `settings.storeName`/`appName` (`immediate`) → `document.title = buildDocumentTitle(...)`; saat unmount kembalikan judul bawaan (`BoothPOS`)
- [X] T013 [US2] Panggil `useBranding()` dari `resources/js/components/layout/AppShell.vue` (di `<script setup>`); tambah tes di `qa-tests/component/AppShell.test.js`: judul berubah saat `settings.storeName`/`appName` berubah dan kembali ke bawaan saat shell dilepas
- [X] T014 [P] [US2] Tes composable `qa-tests/unit/useBranding.test.js` untuk kasus "tanpa muat ulang" (ubah state → `document.title` berubah)

## Phase 5: User Story 3 — Favicon memakai logo toko (P2)

**Tujuan**: `<link rel="icon">` = logo toko bila ada, selain itu `/favicon.ico`.

- [X] T015 [P] [US3] Tambah `<link rel="icon" href="/favicon.ico">` eksplisit di `resources/views/app.blade.php` (ikon bawaan pra-login; judul bawaan `BoothPOS` tetap)
- [X] T016 [US3] Perluas `resources/js/composables/useBranding.js`: `watch` `settings.storeLogoUrl` → setel `href` pada `link[rel="icon"]` (buat elemen bila belum ada); URL kosong → `/favicon.ico`; saat unmount kembalikan `/favicon.ico`
- [X] T017 [P] [US3] Tambah kasus di `qa-tests/unit/useBranding.test.js`: logo ada → `href` = URL logo; logo dihapus/diganti → `href` mengikuti; unmount → `/favicon.ico`

## Phase 6: User Story 4 — POS pas satu layar, ringkasan keranjang selalu terlihat (P1)

**Tujuan**: halaman tidak menggulir; daftar produk menggulir sendiri; footer keranjang penuh terlihat.
**Tes mandiri**: POS di 1366×768 dengan katalog besar → Total dan Bayar terlihat tanpa gulir.

- [X] T018 [P] [US4] Tambah tes di `qa-tests/component/AppShell.test.js`: akar shell memakai kelas `h-screen` (bukan `min-h-screen`) dan `main` tetap `overflow-auto` dengan `min-h-0`
- [X] T019 [US4] Ubah akar `resources/js/components/layout/AppShell.vue` dari `min-h-screen` ke `h-screen` (komentar Indonesia: kenapa — tinggi akar mengikuti isi sehingga footer keranjang POS terdorong keluar layar; lihat research D4)
- [X] T020 [US4] (diperluas: lihat research D4 revisi — `meta.fillHeight` di router + `AppShell` main kolom flex + akar PosView `min-h-0 flex-1`) Di `resources/js/views/PosView.vue` (baris ~289–330) pisahkan bagian atas kolom produk (banner sesi, pencarian, filter) agar TIDAK ikut menggulir dan bungkus grid produk dalam wadah `min-h-0 flex-1 overflow-auto` sendiri; pertahankan `h-full` pada akar dan perilaku gulir keranjang (`PosCartPanel.vue`: daftar baris `flex-1 overflow-auto`, footer tetap)
- [X] T021 [P] [US4] Perbarui `qa-tests/component/PosView.test.js`: grid produk berada di wadah gulir sendiri dan kotak pencarian berada di luar wadah itu
- [ ] T022 [US4] Cek browser nyata (login `owner`, `http://localhost:8010`): POS di jendela 1366×768 dengan katalog besar → halaman tidak menggulir, Total/Bayar/pesan sesi terlihat penuh, gulir daftar produk berfungsi dan pencarian tetap di atas; keranjang berisi banyak baris menggulir sendiri; konsol tanpa galat
- [ ] T023 [US4] Periksa regresi gulir layar lain setelah T019 di browser (Dashboard, Penjualan, Produk, Stok, Pengaturan, Restaurant Setup): masih menggulir normal di dalam `main`, tanpa konten terpotong atau header lengket rusak; catat temuan, perbaiki bila ada

## Phase 7: Polish & Cross-Cutting

- [ ] T024 Cek browser nyata alur identitas (quickstart langkah 3): isi nama + logo → sidebar/judul/ikon berubah tanpa muat ulang; ubah Application name; samakan dengan nama toko → muncul sekali; kosongkan nama → kembali ke nama aplikasi; hapus logo → kotak huruf; login `kasir01` → tampilan sama; mode DEMO↔LIVE mengikuti; konsol tanpa galat
- [X] T025 [P] (hasil: Vitest 203 berkas/1735 lulus; backend 2065 lulus, 2 gagal — `ShiftReportParityTest` bawaan, dan duplikat kode kategori `PN` di `VariantBomLegac…` yang hanya muncul pada run penuh, belum dibuktikan bawaan) Jalankan seluruh `npm test` dan suite backend (perintah aman kontainer di atas); laporkan hasil apa adanya, termasuk kegagalan bawaan (mis. `ShiftReportParityTest`, bukan dari fitur ini)
- [X] T026 [P] Tambah catatan bertanggal 2026-10-25 untuk 072 di `CLAUDE.md` (bagian Domain rules: judul/favicon/sidebar dari `useBranding` + `features.store_logo_url`; `AppShell` `h-screen`) bila ada keputusan non-obvious yang muncul saat implementasi

## Dependencies & Execution Order

- Phase 1 → Phase 2 (memblokir US1–US3). **US4 tidak bergantung Phase 2** dan dapat dikerjakan paralel.
- US1 bergantung T005 (state). US2 bergantung T005 hanya untuk `appName`/`storeName` yang sudah ada — dapat mulai setelah Phase 1. US3 bergantung T005 dan T012 (berkas composable yang sama; kerjakan setelah US2).
- T008 dan T007 menyentuh berkas berbeda; T016 setelah T012; T019 sebelum T020, T022, T023.
- Dalam tiap story: tes ditulis dahulu dan gagal sebelum implementasi.

### Peluang paralel

- T004 ‖ T005 (setelah T003); T006 ‖ T009 ‖ T010 ‖ T018 (berkas tes berbeda).
- US4 (T018–T023) ‖ rangkaian US1–US3 bila dikerjakan dua orang.

## Implementation Strategy

- **MVP**: Phase 1–2 + US1 (sidebar), lalu US4 (POS) karena sama-sama P1 dan independen.
- **Bertahap**: US1 → US2 → US4 → US3, validasi browser tiap story sebelum lanjut.
- Tanpa migrasi; tidak ada langkah deploy khusus selain `npm run build`.
