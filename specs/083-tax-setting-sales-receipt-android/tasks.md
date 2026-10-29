---

description: "Task list for feature 083 — Pengaturan Pajak (tarif, label, tampil/sembunyi) di web, detail Penjualan, struk, dan Android"
---

# Tasks: Pengaturan Pajak (083)

**Input**: `/specs/083-tax-setting-sales-receipt-android/` — plan.md, spec.md, research.md, data-model.md, contracts/tax-setting-api.md, quickstart.md

**Prerequisites**: branch `083-tax-setting-sales-receipt-android`; MySQL dev container up; `.env.testing` points at `warkop_wjm_test`.

**Tests**: INCLUDED. Konstitusi II mewajibkan tes backend (`tests/Feature/`), tes frontend (`qa-tests/`), dan pemeriksaan browser nyata untuk layar yang disentuh pengguna; Android memakai tes unit yang sudah ada di `android-pos/app/src/test/`.

**Perintah tes backend yang AMAN** (CLAUDE.md; tanpa `-e` ini database dev terhapus):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test --filter=<NamaTes> -e CACHE_STORE=array -e SESSION_DRIVER=array
```

## Format: `[ID] [P?] [Story] Description`

- **[P]**: bisa paralel (berkas berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- **[Story]**: US1 pengaturan, US2 sembunyi-tetap-ditagih, US3 detail Penjualan + struk, US4 Android
- Semua teks UI, komentar kode, dan pesan commit berbahasa Indonesia (Konstitusi III)

---

## Phase 1: Setup

**Purpose**: memastikan titik awal hijau sebelum mengubah apa pun.

- [X] T001 Jalankan tes pajak/pengaturan yang ada sebagai garis dasar (perintah aman di atas) untuk `PosSettingsTest`, `SettingsFeaturesPosTest`, `ReceiptStoreIdentityTest`, dan `npm test -- posTotals`; catat hasilnya di `specs/083-tax-setting-sales-receipt-android/evidence.md` (berkas baru, bagian "Garis dasar").

---

## Phase 2: Foundational (memblokir semua cerita)

**Purpose**: kunci `tax_show`, validasinya, dan jalur bacanya di server dan store web. Semua cerita membaca ini.

**⚠️ CRITICAL**: tidak ada cerita yang boleh mulai sebelum fase ini selesai.

- [X] T002 Tambah konstanta `TAX_SHOW = 'tax_show'` dan `taxShow(): bool` (bawaan `true`; baca lewat `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, bukan cast `(bool)`) di `app/Support/PosSettings.php`; komentar singkat kenapa bawaannya aktif (instalasi yang sudah berpajak tidak berubah; research D2).
- [X] T003 Tambah validasi pada closure `settings.*.value` di `app/Http/Requests/UpdateSettingsRequest.php`: `tax_rate` numerik 0–100 maksimal 2 desimal; `tax_label` string maksimal 30 karakter setelah dipangkas; `tax_show` harus boolean (`FILTER_VALIDATE_BOOLEAN` dengan `FILTER_NULL_ON_FAILURE`). Pesan galat berbahasa Indonesia, pola sama dengan `price_approval_threshold_pct`.
- [X] T004 [P] Tambah `'tax_show' => \App\Support\PosSettings::taxShow()` di samping `tax_rate`/`tax_label` pada `features()` di `app/Http/Controllers/Api/SettingsController.php`.
- [X] T005 [P] Tambah `'tax_show' => \App\Support\PosSettings::taxShow()` pada respons `receipt()` di `app/Http/Controllers/Api/OrderController.php` (field `tax_amount`, `tax_rate`, `tax_label`, `total_amount` tidak diubah).
- [X] T006 Tulis `tests/Feature/TaxSettingTest.php`: (a) bawaan `taxShow()` true dan `features` membawa `tax_show`; (b) `PUT /settings` menerima tarif 10, label "PPN", `tax_show` false lalu `features` mencerminkannya; (c) tarif 101, -1, `abc`, label 31 karakter, `tax_show` "mungkin" → 422 dan nilai lama utuh; (d) kasir → 403; (e) setiap perubahan tercatat di log aktivitas dengan nilai lama dan baru. Jalankan dengan perintah aman; harus hijau setelah T002–T005.
- [X] T007 [P] Tambah `taxShow` (bawaan `true`) di state `resources/js/stores/settings.js`, baca `data.tax_show` di tempat `taxRate`/`taxLabel` dibaca (sekitar baris 82), dan tambah aksi `setTax({ rate, label, show })` yang memanggil `updateSettings` untuk tiga kunci (`group: 'pos'`, tipe `decimal`/`string`/`boolean`) lalu memperbarui state, mengikuti `setReceiptDisplay`.
- [X] T008 [P] Tambah getter `showTaxLine` di `resources/js/stores/settings.js` (`taxShow && taxRate > 0`) sebagai SATU-SATUNYA aturan tampil untuk layar kerja (POS, pembayaran). Aturan untuk order tersimpan memakai `taxShow && tax_amount > 0` dan didefinisikan di `resources/js/utils/taxDisplay.js` (berkas baru, fungsi `shouldShowTaxLine({ show, amount })`), supaya tidak ada `v-if` ganda per komponen.
- [X] T009 [P] Tambah kunci terjemahan bagian Pajak (`settings.tax_section_title`, `tax_rate_label`, `tax_label_label`, `tax_show_label`, `tax_help_hidden_still_charged`, `tax_help_shared_modes`, `tax_saved`, `save_tax`, dan `sales.detail_tax`) di `resources/js/locales/id.json` dan `resources/js/locales/en.json`; `qa-tests/unit/localeParity.test.js` harus tetap lulus.
- [X] T010 Perbarui `docs/openapi-pos-mvp.yaml` di commit yang sama: `tax_show` pada respons `GET /settings/features` dan `GET /orders/{order}/receipt`, serta kunci pajak pada contoh `PUT /settings` (sesuai contracts/tax-setting-api.md).

**Checkpoint**: `TaxSettingTest` hijau; web sudah bisa membaca `taxShow`/`showTaxLine`.

---

## Phase 3: User Story 1 — Pemilik mengatur pajak di Pengaturan (Priority: P1) 🎯 MVP

**Goal**: bagian **Pajak** di Pengaturan untuk tarif, label, dan tampil/sembunyi, tersimpan dan terbaca kembali.

**Independent Test**: login `owner`, isi tarif 10 dan label PPN, simpan, muat ulang: nilai bertahan; POS menampilkan "PPN (10%)". Login `kasir01`: bagian Pajak tidak ada.

- [X] T011 [P] [US1] Buat `resources/js/views/settings/TaxSection.vue` mengikuti `ReceiptDisplaySection.vue` (komponen `SettingsSection`, `BaseInput`/`BaseButton`, kotak centang bertoken desain, tanpa hex): tarif (angka, 0–100), label (maks 30), toggle "Tampilkan pajak di layar dan dokumen", teks bantu bahwa pajak tetap ditagih saat disembunyikan dan nilainya dipakai bersama mode DEMO dan LIVE; simpan lewat `settings.setTax`, galat 422 lewat penangan galat pusat, toast sukses.
- [X] T012 [US1] Daftarkan bagian `tax` di `resources/js/views/SettingsView.vue` (peta `SECTIONS` dan daftar `groups`, label `settings.tax_section_title`), hanya untuk peran yang memegang menu `settings` (disembunyikan, bukan dinonaktifkan; Konstitusi III).
- [X] T013 [P] [US1] Tulis `qa-tests/component/TaxSection.test.js` (Vitest, API di-`vi.mock`): memuat nilai dari store; menyimpan memanggil `updateSettings` dengan tiga kunci bertipe benar; tarif tak valid menampilkan galat dan tidak mengubah store; label kosong tetap boleh.
- [ ] T014 [US1] Verifikasi di browser nyata (Konstitusi II) mengikuti langkah 1, 6, dan 7 di `quickstart.md`; cek konsol tanpa galat; catat hasil di `specs/083-tax-setting-sales-receipt-android/evidence.md`.

**Checkpoint**: US1 berdiri sendiri; pajak bisa diatur tanpa tinker atau API manual.

---

## Phase 4: User Story 2 — Sembunyikan baris pajak, tetap ditagih (Priority: P1)

**Goal**: dengan toggle mati, baris pajak hilang di POS dan pembayaran, tetapi total tetap memuat pajak.

**Independent Test**: tarif 10, toggle mati, item Rp 7.000: tidak ada baris pajak, total Rp 7.700 di keranjang dan pembayaran; order tersimpan bertotal 7.700.

- [X] T015 [US2] Ganti gerbang `v-if="settings.taxRate > 0"` menjadi `settings.showTaxLine` pada baris pajak di `resources/js/components/pos/PosCartPanel.vue` (sekitar baris 116); `totals.tax` tetap dihitung dan dijumlahkan ke total tanpa melihat `taxShow`.
- [X] T016 [US2] Lakukan hal yang sama di `resources/js/components/payment/PosPaymentModal.vue` (baris 77): tambahkan prop `taxShow` (bawaan `true`) atau baca store, dan teruskan dari `resources/js/views/PosView.vue` (sekitar baris 425); total yang ditampilkan tetap `taxAmount` + sisanya.
- [X] T017 [P] [US2] Tambah kasus di `qa-tests/unit/posTotals.test.js`: `cartTotals` memberi total yang sama untuk `taxShow` aktif dan mati (toggle tidak ada di rumus). Tambah `qa-tests/component/PosCartPanelTax.test.js`: dengan `taxShow=false` baris pajak tidak ada tetapi teks total memuat pajak; dengan `taxShow=true` dan tarif 0 tidak ada baris.
- [X] T018 [P] [US2] Tambah kasus di `tests/Feature/TaxSettingTest.php`: dengan `tax_show=false` dan tarif 10, `POST /orders` untuk subtotal 7.000 tetap menyimpan `tax_amount` 700 dan `total_amount` 7.700 (server tidak peduli toggle; FR-006, FR-011).
- [ ] T019 [US2] Verifikasi di browser: langkah 2 dan 4 di `quickstart.md` (POS dan pembayaran); catat di `evidence.md`.

**Checkpoint**: US1 + US2 jalan; toggle hanya mengubah tampilan.

---

## Phase 5: User Story 3 — Pajak di detail Penjualan dan struk (Priority: P1)

**Goal**: dengan toggle aktif dan pajak > 0, detail Penjualan dan struk menampilkan baris pajak yang menjumlah ke total; mati → tidak ada baris, total sama.

**Independent Test**: bayar order Rp 7.000 bertarif 10%: detail Penjualan dan struk menampilkan Subtotal 7.000, PPN (10%) 700, Total 7.700; matikan toggle: baris hilang, total tetap 7.700; ubah tarif ke 11: order lama tetap 10% dan 700.

- [X] T020 [US3] Buat `resources/js/utils/taxDisplay.js` bila belum (T008) dengan `shouldShowTaxLine({ show, amount })` dan `formatTaxLabel(label, ratePercent)` ("PPN (10%)", tanpa desimal untuk tarif bulat); dipakai tiga komponen di bawah, bukan salinan per komponen.
- [X] T021 [P] [US3] `resources/js/components/receipt/ReceiptModal.vue`: tampilkan baris pajak antara Diskon dan Total memakai `receipt.tax_show`, `receipt.tax_amount`, `receipt.tax_rate`, `receipt.tax_label` (field sudah dikirim server). Pertahankan penamaan berbahasa yang sudah dipakai berkas itu (komentar baris 15 menjelaskan label struk yang sengaja hardcode).
- [X] T022 [P] [US3] `resources/js/components/sales/OpenOrderDetail.vue`: tambahkan baris `tax` pada daftar `totals` (di antara `discount` dan `total`) memakai `o.tax_amount`/`o.tax_rate` dan `settings.taxShow` lewat `shouldShowTaxLine`; label dari `settings.taxLabel`.
- [X] T023 [US3] `resources/js/components/sales/TransactionItemsModal.vue`: tambah baris pajak pada blok `order-totals` (setelah diskon pesanan) dan pastikan total yang ditampilkan adalah `order.total_amount` tersimpan; rincian yang dihitung dari baris item (komentar baris 78) harus tetap menjumlah: subtotal − diskon + pajak tersimpan = total. Baris hanya tampil bila `shouldShowTaxLine`.
- [X] T024 [P] [US3] Tes Vitest: `qa-tests/component/ReceiptModalTax.test.js`, `qa-tests/component/OpenOrderDetailTax.test.js`, `qa-tests/component/TransactionItemsModalTax.test.js`. Per komponen: toggle aktif + pajak 700 → baris ada dan jumlah cocok; toggle mati → tidak ada baris, total sama; `tax_amount` 0 (order lama) → tidak ada baris; tarif snapshot 10 tetap tampil 10% setelah `settings.taxRate` jadi 11. Tambah `qa-tests/unit/taxDisplay.test.js` untuk dua fungsi di T020.
- [X] T025 [P] [US3] Tambah kasus di `tests/Feature/TaxSettingTest.php`: `GET /orders/{id}/receipt` dan `GET /orders/{id}` membawa `tax_amount`/`tax_rate` snapshot order walau tarif lalu diubah (SC-004), dan `receipt.tax_show` mengikuti pengaturan.
- [ ] T026 [US3] Verifikasi di browser: langkah 3, 4, dan 5 di `quickstart.md` (Penjualan, struk, tarif diubah, order lama utuh); periksa konsol; catat di `evidence.md`.

**Checkpoint**: tiga cerita web selesai dan konsisten; MVP web lengkap.

---

## Phase 6: User Story 4 — Android membaca pengaturan pajak (Priority: P2)

**Goal**: tablet memakai tarif, label, dan tampil/sembunyi dari server di editor, lembar struk, dan cetak; tanpa tarif 10% yang ditulis di kode.

**Independent Test**: atur pajak di web, segarkan tablet: baris pajak dan total sama dengan web; matikan tampilan: baris hilang, total sama; offline memakai nilai terakhir.

- [X] T027 [P] [US4] Tambah field `tax_show` pada DTO `features` dan DTO struk di `android-pos/core-network/src/main/java/com/warkop/core/network/dto/ShiftDtos.kt` (nilai bawaan `true` agar server lama tak membuat parsing gagal).
- [X] T028 [US4] `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt`: ubah `TaxDisplay(label, ratePercent)` menjadi `TaxDisplay(label, ratePercent, show: Boolean = true)`. `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt` `taxDisplay()`: JANGAN lagi mengembalikan null bila label kosong (pakai "Pajak"), isi `show` dari `tax_show`.
- [X] T029 [US4] `android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt`: hapus `TAX_RATE = 0.10` (baris 124) dan perkiraan `base * TAX_RATE` (baris 114); perkiraan lokal memakai tarif server dari `TaxDisplay` (0 bila belum pernah menerima, FR-013). Sesuaikan pemanggil `tax`/`total` agar menerima tarif; angka server (`serverTax`/`serverTotal`) tetap menang.
- [X] T030 [US4] `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt`: pastikan nilai `tax` terakhir dipertahankan saat panggilan gagal (pola `tax = try {...} catch { tax }` baris 584 sudah ada; verifikasi dan tambahkan bila ada jalur lain yang menimpa dengan null).
- [X] T031 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/EditorScreen.kt` (baris ~226): tampilkan `MoneyRow(taxLine(store.tax), rp(o.tax))` hanya bila `store.tax?.show != false` dan `o.tax > 0`; total tetap `o.total`.
- [X] T032 [P] [US4] `android-pos/app/src/main/java/com/warkop/pos/data/ReceiptMapper.kt`, `ui/Sheets.kt` (baris ~713), dan `print/Documents.kt` (baris ~103): tambah `taxShow` pada model struk (`PosModels.kt` sekitar baris 175–191) dari `tax_show`, dan cetak/tampilkan baris pajak hanya bila `taxShow && tax > 0`; total cetak tetap `r.total` dari server.
- [X] T033 [P] [US4] Perbarui tes unit yang ada dan tambah kasus: `android-pos/app/src/test/java/com/warkop/pos/state/FakePosBackend.kt` (`tax` dengan `show`), `PosStoreRefreshTest.kt` (nilai terakhir dipertahankan saat gagal; tarif 11 memengaruhi perkiraan), `print/DocumentsTest.kt` dan `data/ReceiptMapperTest.kt` (baris pajak ada bila `taxShow`, hilang bila mati dan total sama, tidak ada bila pajak 0). Jalankan `./gradlew :app:testDebugUnitTest` di `android-pos/` (perintah persis menyesuaikan modul; belum diverifikasi).
- [X] T034 [US4] Catat di `evidence.md` bahwa verifikasi di perangkat belum dilakukan bila `adb`/tablet tidak ada (jangan mengklaim terbukti); sertakan langkah Android dari `quickstart.md` sebagai daftar periksa tersisa.

**Checkpoint**: Android mengikuti pengaturan di tingkat tes unit; verifikasi perangkat tercatat jujur.

---

## Phase 7: Polish & lintas-cerita

- [X] T035 [P] Tambah catatan bertanggal di `CLAUDE.md` (satu butir "Pajak" di Domain rules: `PosSettings::taxShow()` satu-satunya pembaca, toggle hanya tampilan, `TaxCalculator` satu-satunya rumus, DEMO/LIVE berbagi nilai) dan di `docs/RUNBOOK.md` (cara mengatur pajak dari Pengaturan; tablet APK lama tetap mencetak baris pajak).
- [X] T036 [P] Tambah catatan bertanggal di `docs/PRD-POS-Event-Multivendor.md` (kontrol pajak baru; bukan perubahan lingkup MVP) sesuai Dokumentasi & Disiplin Perubahan.
- [ ] T037 Jalankan seluruh suite: backend dengan perintah aman (`php artisan test`), `npm test`, `npm run build` (hasil build memuat bagian Pajak; tidak ada chunk awal yang membengkak), lalu Android unit test; catat angka lulus/gagal APA ADANYA di `evidence.md` (kegagalan lama yang diketahui seperti `ShiftReportParityTest` dicatat sebagai sudah ada sebelumnya, bukan disembunyikan).
- [ ] T038 Jalankan seluruh `quickstart.md` di browser dari awal sampai akhir sekali lagi setelah semua cerita selesai; pastikan konsol bersih dan total identik di keranjang, pembayaran, Penjualan, dan struk pada kedua keadaan toggle (SC-002, SC-003).

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** (blokir) → **US1, US2, US3** → **US4** → **Polish**.
- **US1** bergantung pada T002–T003, T007, T009. **US2** bergantung pada T007–T008 (tidak pada US1 secara kode, tetapi diuji paling mudah setelah US1 ada pengaturnya). **US3** bergantung pada T005, T008, T020. **US4** hanya bergantung pada Phase 2 (T004–T005), jadi bisa berjalan paralel dengan US1–US3 oleh orang lain.
- Dalam cerita: tes ditulis bersama atau tepat sebelum implementasinya; verifikasi browser paling akhir.
- **Berkas yang sama (jangan paralel)**: `tests/Feature/TaxSettingTest.php` (T006, T018, T025), `resources/js/stores/settings.js` (T007, T008), `evidence.md` (semua tugas verifikasi), `PosModels.kt` (T029, T032).

## Parallel Examples

```text
Phase 2 (setelah T002–T003):
  T004 (SettingsController) | T005 (OrderController) | T007 + T009 (frontend) | T010 (openapi)

US3 (setelah T020):
  T021 ReceiptModal | T022 OpenOrderDetail | T025 tes backend      (T023 menyusul, berkas besar sendiri)

US4 (setelah T027–T028):
  T031 EditorScreen | T032 Receipt/Sheets/Documents | T033 tes unit
```

## Implementation Strategy

1. **MVP web**: Phase 1 → Phase 2 → US1 → US2 → US3. Berhenti dan validasi dengan `quickstart.md` langkah 1–8; ini sudah menyelesaikan permintaan di web.
2. **Android (P2)**: US4 menyusul; boleh paralel dengan US1–US3 sejak Phase 2 selesai. Rilis APK terpisah dari server; sampai APK baru terpasang, tablet lama tetap mencetak baris pajak (dicatat di T035).
3. Satu commit per cerita bila memungkinkan, `openapi` dan dokumen ikut commit yang mengubah kontraknya (Konstitusi). Jangan push atau buka PR tanpa izin eksplisit.

## Catatan risiko yang dibawa dari rencana

- Tablet berAPK lama mengabaikan `tax_show` (research D5).
- Label pajak order lama mengikuti pengaturan saat ini, bukan snapshot (research D6).
- Verifikasi Android di perangkat belum mungkin tanpa `adb`/tablet (T034).
