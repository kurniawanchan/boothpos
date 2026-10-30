---

description: "Task list for 085 — beranda login dua kolom & perbaikan paritas pajak"
---

# Tasks: Beranda Login Dua Kolom & Perbaikan Hitungan Pajak

**Klasifikasi**: INTERNAL

**Input**: `specs/085-login-homepage-tax-fix/` — plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Disertakan. Konstitusi II mewajibkan tes untuk setiap perubahan, dan celah tes pajak (research F2: semua tes memakai 10%) adalah bagian dari masalah.

**Organization**: Per user story. US1 (pajak, P1) dan US2/US3 (beranda login, P2/P3) tidak saling bergantung dan dapat dikirim terpisah. US3 menyunting file yang sama dengan US2, jadi dikerjakan sesudahnya.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- Perintah tes di kontainer WAJIB `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …` (flag `-e` SEBELUM nama layanan). Jangan pernah menjalankan tes tanpa flag itu: pernah menghapus DB dev.

## Path Conventions

Server `app/`, `tests/Feature/`; web `resources/js/`, `qa-tests/`; Android `android-pos/app/src/{main,test}/java/com/warkop/pos/`.

---

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/085-login-homepage-tax-fix/evidence.md` berisi bagian: Reproduksi pajak (Web, tablet, server), Hasil suite, Pemeriksaan peramban, Pemeriksaan perangkat; setiap bagian awalnya berstatus "belum dijalankan"

## Phase 2: Foundational (memblokir US1)

- [X] T002 Buat fixture bersama `qa-tests/fixtures/tax-parity-cases.json` berisi semua kasus tabel di `specs/085-login-homepage-tax-fix/contracts/tax-parity-cases.md` (field: `base`, `rate`, `tax`, `note`); tarif sebagai angka, pajak sebagai bilangan bulat rupiah

**Checkpoint**: fixture ada; semua suite tes US1 membacanya.

---

## Phase 3: User Story 1 — Pajak sama persis di Web dan Android (P1) 🎯 MVP

**Goal**: pajak mengikuti tarif di Pengaturan untuk semua tarif sah (termasuk 100%), identik di Web, Android, dan server.

**Independent Test**: ikuti bagian Pajak di `quickstart.md`; tarif 100% menghasilkan pajak = dasar di keranjang, pembayaran, detail pesanan, dan struk pada kedua aplikasi.

### Reproduksi (blokir perbaikan, bukan tes otomatis)

- [X] T003 [US1] Reproduksi di SERVER pada tarif 100%: ulangi pesanan lewat `PUT /settings` lalu `POST /orders` dan tab; catat angka, `tax_show`, dan baris `settings` dev (baca saja, tanpa menulis ke DB dev) di `specs/085-login-homepage-tax-fix/evidence.md`
- [ ] T004 [US1] Reproduksi di WEB (peramban nyata, gunakan skill `browser-verify`): atur 100% di Pengaturan → Pajak, nyalakan "tampilkan pajak", buka POS dengan item 17.000; catat pajak dan total, periksa konsol; ulangi dengan peramban kedua yang sudah terbuka sebelum tarif diubah (menguji H2) di `evidence.md`
- [ ] T005 [US1] Reproduksi di TABLET (butuh perangkat; tidak bisa dikerjakan agen): pemilik mencatat nomor build APK, tarif yang terlihat di keranjang/struk, dan nilai `tax_show`, ke `evidence.md`. Tandai "belum diverifikasi di perangkat" bila belum dijalankan. Hasil T003–T005 menentukan apakah T014 dikerjakan

### Tes (tulis dulu, harus gagal bila ada penyimpangan)

- [X] T006 [P] [US1] Buat `tests/Feature/TaxParityTest.php`: (a) `TaxCalculator::compute` untuk setiap kasus di fixture T002; (b) pesanan lengkap pada tarif 100, 5, 2,5 yang disimpan lewat `PUT /settings`, memeriksa `tax_amount`, `tax_rate`, `total_amount` pada `POST /orders`, `GET /orders/{id}`, tab, dan struk; (c) diskon tingkat-pesanan dikurangkan sebelum pajak; (d) mengganti tarif tidak mengubah pesanan lama
- [X] T007 [P] [US1] Buat `qa-tests/unit/taxParity.test.js`: `taxOnBase` dan `cartTotals` terhadap fixture T002, termasuk tarif 100, 0,5, dasar 0, dasar negatif
- [X] T008 [P] [US1] Buat `android-pos/app/src/test/java/com/warkop/pos/data/TaxParityTest.kt`: angka pajak/total dari server dipetakan apa adanya (`OrderMapper`, `ReceiptMapper`), `Order.tax` bernilai 0 bila server tidak memberi pajak (tidak ada cadangan), dan `taxLabelText`/label struk menampilkan tarif yang diberikan server (100, 2,5) tanpa pembulatan ke 10
- [X] T009 [P] [US1] Buat `tests/Feature/TaxNoHardcodedRateTest.php` (pola tes pemindai sumber `outletId` di `StockService`): gagal bila jalur pajak di `app/`, `resources/js/` (di luar locale dan tes), dan `android-pos/app/src/main` memuat angka tarif tertulis (mis. `* 0.1`, `* 0.11`, `/ 10`, `0.10`, `10 %` dalam rumus pajak). Perbarui daftar pengecualian bila ada kecocokan sah, dengan alasan di komentar

### Implementasi

- [X] T010 [US1] Web: pastikan pratinjau pajak memakai tarif segar — muat ulang `settings.load()` saat `PosView` dibuka dan saat tab peramban kembali fokus, di `resources/js/views/PosView.vue` (dan `resources/js/stores/settings.js` bila perlu); tambahkan tes komponen di `qa-tests/component/PosTaxLines.test.js` bahwa tarif yang berubah di server terlihat setelah muat ulang. Server tetap penentu total
- [X] T011 [US1] Android: tampilkan nomor versi (`versionName` + `versionCode`) di layar Koneksi atau penyiapan (cari layar lewat `grep -rn "Koneksi" android-pos/app/src/main/java/com/warkop/pos/ui/screens`); satu sumber nilai, sama dengan yang dilaporkan `HeartbeatWorker.kt:15` (jangan menulis versi dua kali); tambah tes aturan teks versi di `android-pos/app/src/test/java/com/warkop/pos/ui/`
- [X] T012 [US1] Jalankan T006–T009 pada kode sekarang dan catat hasilnya di `evidence.md`. Bila ada yang gagal, itu temuan: perbaiki di sumbernya (`app/Support/TaxCalculator.php`, `resources/js/utils/posTotals.js`, atau Android) dan catat dengan komentar `BUG YANG DITEMUKAN & DIPERBAIKI`
- [X] T013 [US1] Sesuaikan catatan: bila terbukti pajak TIDAK dipisah per mode DEMO/LIVE (research F6), tambahkan catatan bertanggal di `CLAUDE.md` pada butir Pajak (083) tanpa menulis ulang riwayat; bila terbukti dipisah, perbaiki kode yang membaca tarif dari mode yang salah
- [ ] T014 [US1] KONDISIONAL menurut T003–T005: (H3) bila nilai lama tertahan di cache, ubah `app/Models/Setting.php` atau `PosSettings` agar kunci cache memuat semua masukan yang mengubah nilainya (Konstitusi V) dan tambah tes; (H1) bila tablet ternyata memakai APK lama, catat langkah pembaruan APK di `docs/RUNBOOK.md` dan tidak mengubah kode; bila tak satu pun terbukti, tulis di `evidence.md` bahwa gejala tidak tereproduksi dan minta contoh pesanan nyata (nomor struk, tarif, angka) dari pemilik

**Checkpoint**: tiga suite paritas hijau; reproduksi tercatat; US1 dapat dikirim sendiri.

---

## Phase 4: User Story 2 — Beranda login dua kolom dengan info outlet (P2)

**Goal**: layar PIN Android berbentuk dua kolom: Info Outlet berfoto + kartu pengguna seragam | PIN besar.

**Independent Test**: bagian Beranda login di `quickstart.md`, butir 1, 2, 4, 5.

### Tes

- [X] T015 [P] [US2] Perbarui `android-pos/app/src/test/java/com/warkop/pos/ui/PinLayoutRuleTest.kt`: `pinLayoutFor` hanya mengembalikan `TWO_COLUMN` (≥ 720 dp) dan `STACKED`; hapus uji `THREE_COLUMN`
- [X] T016 [P] [US2] Perbarui `android-pos/app/src/test/java/com/warkop/pos/ui/StaffRowsRuleTest.kt`: `expanded` berarti "terpilih" saja; staf tanpa PIN tetap `selectable = false`; dua orang bernama sama tetap dua baris

### Implementasi

- [X] T017 [P] [US2] Siapkan foto latar: kecilkan `specs/085-login-homepage-tax-fix/outlet-background-reference.png` ke JPEG ±1280 px (`sips -s format jpeg -Z 1280 …`, kualitas ±70) menjadi `android-pos/app/src/main/res/drawable-nodpi/outlet_background.jpg`; periksa ukuran berkas < 300 KB
- [X] T018 [US2] Ubah `android-pos/app/src/main/java/com/warkop/pos/ui/PinLayoutRule.kt`: hapus `THREE_COLUMN` dan `PIN_THREE_COLUMN_MIN_DP`, sesuaikan KDoc
- [X] T019 [US2] Di `android-pos/app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt`, ganti `BrandPanel` dengan `OutletInfoPanel`: foto `outlet_background` sebagai latar, scrim gradien gelap, lalu logo + nama outlet (`StoreMark`), lencana mode, nama terminal sebagai baris tersendiri, tanggal, jam berdetak (`liveClockText`), chip online (`OutletStatusChips`); teks putih, kontras terbaca; gambar dekoratif (`contentDescription = null`)
- [X] T020 [US2] Di `PinScreen.kt`, ganti `StaffColumn`/`StaffRowItem` dengan `StaffCardGrid`: `LazyVerticalGrid` dengan `GridCells.Adaptive`, kartu tinggi tetap, baris atas `UserAvatar`, baris bawah nama + jabatan (satu baris, elipsis), terpilih = cincin tebal tanpa mengubah ukuran, tanpa PIN = redup + `NO_PIN_REASON` dan tidak bisa diketuk; pertahankan status memuat/galat/kosong
- [X] T021 [US2] Di `PinScreen.kt`, bangun tata letak akhir: `TWO_COLUMN` = kolom kiri (`OutletInfoPanel` di atas, `StaffCardGrid` di bawah) | kolom PIN; `STACKED` = Info Outlet, kartu, PIN berurutan di dalam satu gulir; hapus cabang tiga kolom
- [X] T022 [US2] Di `PinScreen.kt`, perbesar area PIN: keypad lebar maks ≈ 440 dp, tinggi tombol 88 dp (kompak 64 dp), `PinDot` 22 dp, judul PIN lebih besar; kolom PIN tetap menggulir pada tinggi layar kecil

**Checkpoint**: layar PIN dua kolom berfungsi; login PIN tidak berubah (FR-012). Tes JVM hijau; tampilan di tablet belum diverifikasi sampai T028.

---

## Phase 5: User Story 3 — Ganti outlet/terminal sebagai tombol jelas (P3)

**Goal**: "Ganti outlet / terminal" berupa tombol yang jelas; roda gigi dihapus.

**Independent Test**: `quickstart.md` Beranda login butir 3.

- [X] T023 [P] [US3] Perbarui `android-pos/app/src/test/java/com/warkop/pos/ui/PinScreenCopyTest.kt`: teks tombol tetap "Ganti outlet / terminal"; tambah pemindai sumber bahwa `PinScreen.kt` tidak lagi memuat `Icons.Filled.Settings` maupun `import ….filled.Settings`
- [X] T024 [US3] Di `PinScreen.kt`, ganti `TextButton` "Ganti outlet / terminal" dengan `OutlinedButton` lebar penuh berikon di bawah keypad (memanggil `store.goToSetup()`), dan hapus `IconButton` roda gigi serta importnya

**Checkpoint**: hanya satu jalan ke penyiapan; tombol terlihat jelas.

---

## Phase 6: Polish & Verifikasi

- [X] T025 Jalankan suite backend penuh dengan flag `-e` yang benar dan catat hasilnya apa adanya di `evidence.md` (kegagalan `ShiftReportParityTest` sebelumnya dianggap pra-ada, tetapi belum terbukti: bandingkan dengan `git stash`/cabang dasar sebelum menyebutnya pra-ada)
- [X] T026 [P] Jalankan `npm test` dan `npm run build`; catat jumlah tes dan hasil di `evidence.md`
- [X] T027 [P] Jalankan suite JVM Android (`cd android-pos && ./gradlew testDebugUnitTest`); catat jumlah tes dan hasil di `evidence.md`
- [ ] T028 Verifikasi PERANGKAT (butuh tablet; tidak bisa dikerjakan agen): `quickstart.md` Beranda login butir 1–5 dan Pajak butir 2–3 pada APK baru, lanskap dan potret, termasuk jam 10 detik, Wi-Fi mati/hidup, 1 dan banyak pengguna, nama panjang. Catat di `evidence.md`; tanpa ini status layar tetap "belum diverifikasi di perangkat"
- [X] T029 Perbarui `CLAUDE.md`: tambahkan butir ringkas bertanggal 085 (layar PIN dua kolom, `THREE_COLUMN` dihapus, fixture paritas pajak, tes anti-literal) pada daftar aturan domain; jangan menulis ulang butir 078/083
- [ ] T030 Tinjau diff akhir terhadap spec: setiap FR-001–FR-020 punya tes atau bukti di `evidence.md`; hapus berkas sementara; pastikan tidak ada kredensial/PII di `evidence.md`

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1. US2 dan US3 tidak bergantung pada US1 dan bisa jalan paralel dengannya (file berbeda).
- US3 (T023–T024) SESUDAH US2 (T019–T022): file `PinScreen.kt` sama.
- Dalam US1: T003–T005 (reproduksi) berjalan paralel dengan penulisan tes T006–T009; T012 menjalankan tes; T014 menunggu hasil T003–T005 dan T012.
- Polish (T025–T030) sesudah story yang dikirim selesai; T028 butuh perangkat.

### Parallel opportunities

- US1: T006, T007, T008, T009 (file berbeda) — setelah T002.
- US2: T015, T016, T017 bersamaan; T019–T022 berurutan (satu file).
- US1 dan US2 dapat dikerjakan dua orang sekaligus.
- Polish: T026 dan T027 bersamaan.

## Implementation Strategy

1. **MVP = US1**: fixture, tiga tes paritas, tes anti-literal, reproduksi. Bahkan bila gejala 10% tidak tereproduksi, celah tes tertutup dan pemilik mendapat bukti angka.
2. Kirim US1 lebih dulu (kebenaran uang), lalu US2 + US3 sebagai satu kiriman tampilan.
3. Jangan mengubah `TaxCalculator`/`posTotals` kecuali T012 menemukan kegagalan nyata (server sudah terbukti benar, research F1).
4. Jangan klaim "selesai" untuk layar sebelum T028 dijalankan.

## Ringkasan

- Total tugas: 30 (Setup 1, Foundational 1, US1 12, US2 8, US3 2, Polish 6)
- Tugas yang butuh perangkat/orang: T005, T028
