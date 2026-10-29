# Tasks: Sinkronisasi Stok Riil dengan Transaksi yang Sudah Ada

**Input**: `/specs/051-stock-sync-from-transactions/` (plan.md, spec.md, research.md, data-model.md, contracts/command-and-report.md, quickstart.md)

**Tests**: WAJIB (Konstitusi II) — fitur ini menulis stok nyata. PHPUnit di MySQL `boothpos_test`; uji mutasi untuk aturan yang paling berisiko.

**Format**: `- [ ] [ID] [P?] [Story] Deskripsi + path berkas`

> PERINGATAN: jangan jalankan `php artisan test` di container `app` tanpa `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`. Di host: `php -d memory_limit=1G vendor/bin/phpunit --filter StockTransactionSyncTest`. JANGAN menjalankan perintah baru terhadap database pemilik dari tugas mana pun di sini kecuali dry-run yang diminta pemilik (T027).

## Phase 1: Setup

- [ ] T001 Pastikan basis hijau: jalankan `php -d memory_limit=1G vendor/bin/phpunit --filter 'StockTest|MasterDataImport'` dan catat jumlah tes — `tests/Feature/`
- [X] T002 Buat kerangka tes `tests/Feature/StockTransactionSyncTest.php` (RefreshDatabase) dengan pembantu: buat varian + penjualan via `OrderService` / serah-terima via `PreorderService::transitionStatus`, "ketik stok" via `StockService::applyMovement(adjustment, reference_type 'MasterDataImport')`, dan void via `OrderService::void()` — jalur ASLI, bukan menyisipkan baris pergerakan manual

## Phase 2: Foundational (memblokir semua story)

**Tujuan**: kelas dasar perencana dan guard rumus Excel, dipakai US1–US3.

- [X] T003 Tes dulu: `SheetArrayExport` yang diberi sel `=1+1` di dalam `MultiSheetArrayExport` tersimpan sebagai teks (baca ulang xlsx, tipe `s`) — `tests/Feature/GenericArrayExportTest.php` (tambah) atau berkas tes ekspor sejenis
- [X] T004 Tambah guard `=` (binder `WithCustomValueBinder` yang sama dengan `GenericArrayExport`, delegasi ke `Maatwebsite\Excel\DefaultValueBinder`) pada `SheetArrayExport`; bila tes T003 menunjukkan binder sheet tidak berlaku di dalam multi-sheet, pasang binder di `MultiSheetArrayExport` (keputusan research Decision 8) — `app/Exports/SheetArrayExport.php`, `app/Exports/MultiSheetArrayExport.php`
- [X] T005 Mutasi: matikan guard, pastikan T003 gagal, pulihkan

**Checkpoint**: sel berawalan `=` aman di workbook multi-sheet; ekspor master-data yang memakai kelas ini tetap lulus (`--filter MasterData`).

## Phase 3: User Story 1 — Dry-run tanpa mengubah apa pun (P1) 🎯 MVP

**Independent Test**: dry-run pada data LIVE dengan penjualan sebelum pembaruan stok: Excel berisi stok sekarang, pengurang, stok seharusnya per varian; stok, pergerakan, dan log aktivitas tidak berubah.

- [X] T006 [US1] Tes: varian 50 (diketik) setelah 8 terjual → rencana: sekarang 50, pengurang 8, seharusnya 42, status `akan_diubah` — `tests/Feature/StockTransactionSyncTest.php`
- [X] T007 [P] [US1] Tes pengurang: penjualan 8, void 3 → pengurang bersih 5; serah-terima pre-order 2 ikut; pre-order BELUM diserahkan tidak ikut (Q1 = A); pre-order dibatalkan tidak ikut — `tests/Feature/StockTransactionSyncTest.php`
- [X] T008 [P] [US1] Tes jendela dan acuan: transaksi SESUDAH pembaruan tidak dihitung; pembaruan kedua → hanya jendela (acuan sebelumnya, acuan terakhir) dengan `catatan` "beberapa pembaruan manual"; acuan memakai `id` bukan `created_at` (dua pergerakan di detik yang sama tetap terurut) — `tests/Feature/StockTransactionSyncTest.php`
- [X] T009 [P] [US1] Tes status: tanpa pembaruan manual → `dilewati`; tanpa transaksi sebelum pembaruan → `tidak_ada_perubahan`; varian soft-deleted → `dilewati` tanpa galat; operand = stok SEKARANG (transaksi susulan setelah pembaruan tidak terhapus) — `tests/Feature/StockTransactionSyncTest.php`
- [X] T010 [P] [US1] Tes klem (Q2 = B): terjual 12 dengan stok sekarang 5 → seharusnya 0, selisih −5, kekurangan 7, status `perlu_perhatian`; stok sekarang sudah 0 dengan pengurang > 0 → tidak ada pergerakan direncanakan tetapi kekurangan tampil — `tests/Feature/StockTransactionSyncTest.php`
- [X] T011 [P] [US1] Tes DEMO/LIVE: transaksi dan pembaruan DEMO tidak pernah masuk rencana walau `system_mode` sedang `demo`; perencana tidak memakai `DB::table` tanpa filter — `tests/Feature/StockTransactionSyncTest.php`
- [X] T012 [P] [US1] Tes jumlah query: rencana untuk 3 vs 30 varian memakai jumlah query yang sama (pemanasan dulu) — `tests/Feature/StockTransactionSyncTest.php`
- [X] T013 [US1] Implementasi `StockSyncPlanner::plan()` di dalam `ModeGate::runAs('live', …)`: satu kueri acuan per varian (adjustment terakhir bukan `stock_sync`), kueri pergerakan jendela berkelompok (`sale`, `preorder_handover`, `return`), hitung `taken`, `new_stock`, `delta`, `shortfall`, `status`, `note`; `plan_hash` = sha256 baris berdampak urut `variant_id`; Eloquent saja; komentar Indonesia merujuk research Decision 2–5 — `app/Services/StockSync/StockSyncPlanner.php`
- [X] T014 [US1] Tes bukti "hanya baca": panggil `plan()` lalu bandingkan jumlah baris `stock_movements`, `activity_logs`, dan `current_stock` semua varian sebelum/sesudah (selisih nol, SC-002) — `tests/Feature/StockTransactionSyncTest.php`
- [X] T015 [US1] Command dry-run: `SyncStockFromTransactions` tanpa `--apply` → hitung rencana, tulis Excel (T020–T021), cetak ringkasan per status, jalur berkas, `plan_hash`; kode keluar 0; tanpa menyentuh data — `app/Console/Commands/SyncStockFromTransactions.php`

**Checkpoint**: US1 berdiri sendiri — dry-run aman dan terbaca.

## Phase 4: User Story 2 — Terapkan setelah persetujuan eksplisit (P1)

**Independent Test**: apply dengan `--approve` yang benar mengubah stok persis sesuai Excel; hash basi ditolak; jalankan ulang → nol perubahan.

- [X] T016 [US2] Tes apply: stok akhir tiap varian = `new_stock` dari rencana yang sama; satu pergerakan `adjustment` per varian berubah dengan `reference_type='stock_sync'`, `reference_id` = id acuan, `user_id` = pelaksana, `data_mode='live'`, `reason` memuat rincian (dan "kekurangan N unit" bila diklem); satu log `stock_adjusted` per baris — `tests/Feature/StockTransactionSyncTest.php`
- [X] T017 [P] [US2] Tes idempoten: setelah apply, `plan()` lagi → semua `sudah_disinkronkan`/`tidak_ada_perubahan`, `plan_hash` berubah, apply kedua tidak menulis pergerakan baru (SC-004); termasuk kasus transaksi antara pembaruan asli dan koreksi TIDAK dihitung dua kali — `tests/Feature/StockTransactionSyncTest.php`
- [X] T018 [P] [US2] Tes persetujuan: `--apply` tanpa `--approve` → gagal tanpa tulis; `--approve` salah/basi (satu penjualan baru setelah dry-run) → gagal tanpa tulis dan Excel baru dibuat; tanpa `--by` atau `--by` kasir → gagal; non-interaktif tanpa `--yes` → gagal; konfirmasi "tidak" → gagal tanpa tulis — `tests/Feature/StockTransactionSyncTest.php`
- [X] T019 [P] [US2] Tes semua-atau-tidak-sama-sekali: paksa satu varian gagal di tengah (mis. terjemahkan `StockService` yang dilempar untuk varian ke-2) → TIDAK ada perubahan stok/pergerakan di varian mana pun — `tests/Feature/StockTransactionSyncTest.php`
- [X] T020 [US2] Implementasi `StockSyncApplier::apply($plan, $user)`: satu `DB::transaction`, hanya baris dengan `delta ≠ 0`, `StockService::applyMovement(adjustment, delta, 'stock_sync', baselineId, reason, userId)` di dalam `ModeGate::runAs('live')`; tidak pernah menulis `current_stock` langsung — `app/Services/StockSync/StockSyncApplier.php`
- [X] T021 [US2] Command `--apply`: wajib `--approve` dan `--by` (cari pengguna, harus `canManageMasterData()`), hitung ulang rencana dan bandingkan hash, konfirmasi interaktif (atau `--yes`), cadangan `BackupService::create()` (gagal → batal), panggil applier, tulis Excel HASIL, cetak sebelum→sesudah dan petunjuk pemulihan `app:restore`; kode keluar 1 pada penolakan/gagal — `app/Console/Commands/SyncStockFromTransactions.php`
- [X] T022 [US2] Mutasi: (a) hapus penanda `stock_sync` dari pemilihan acuan → T017 harus gagal (pengurangan ganda); (b) lewati pembandingan hash → T018 basi harus gagal; pulihkan keduanya

**Checkpoint**: US1 + US2 hijau — alat dapat dipakai penuh.

## Phase 5: User Story 3 — Berkas Excel tinjauan dan jejak (P2)

**Independent Test**: buka Excel; tiap angka dapat dicocokkan dengan transaksi sumber dan layar Stok.

- [X] T023 [US3] Tes laporan: baca ulang xlsx → lembar `Ringkasan` (mode LIVE, `plan_hash`, jumlah per status, total pengurang, total kekurangan), `Varian` (kolom sesuai data-model.md; kolom `kekurangan` terisi untuk varian terklem), `Sumber` (nomor transaksi tiap sumber cocok dengan pesanan/pre-order asal, SC-003); tidak ada kolom/nilai nama, telepon, email pelanggan; nama berawalan `=` tersimpan sebagai teks — `tests/Feature/StockTransactionSyncTest.php`
- [X] T024 [US3] Implementasi `StockSyncReport` (lembar `Ringkasan`, `Varian`, `Sumber`) lewat `MultiSheetArrayExport`/`SheetArrayExport`; nomor sumber via `StockMovementReferences::resolve()` (sekali, berkelompok); simpan dengan `Excel::store(..., 'local')` ke `stock-sync/stock-sync-<dryrun|applied>-<YYYYmmdd-HHMMSS>.xlsx`; nama penjual/produk dimuat `withTrashed` tanpa N+1 — `app/Services/StockSync/StockSyncReport.php`
- [X] T025 [US3] Tes berkas privat: berkas ada di `storage/app/private/stock-sync/` dan TIDAK bisa diakses lewat URL publik — `tests/Feature/StockTransactionSyncTest.php`

**Checkpoint**: semua story hijau.

## Phase 6: Polish & lintas-fitur

- [X] T026 [P] Tambah bagian di `docs/RUNBOOK.md`: urutan pakai (dry-run → tinjau Excel → `--apply --approve=… --by=…`), arti tiap status, batas yang diketahui (varian tanpa pembaruan manual, pembaruan ganda, kedatangan pre-order tidak ditambahkan), pemulihan lewat `app:restore` pada cadangan pengaman
- [X] T027 [P] Tambah bagian ringkas bergaya CLAUDE.md "Stock sync from transactions (feature 051)" (aturan non-obvious: buku besar sebagai sumber, `id` bukan `created_at`, penanda `stock_sync`, operand stok sekarang, klem ke 0, hash persetujuan) — `CLAUDE.md`
- [ ] T028 Jalankan suite backend penuh `php -d memory_limit=1G vendor/bin/phpunit` dengan keluaran DISIMPAN ke berkas (jangan dipotong `tail`), dan laporkan hasil apa adanya
- [ ] T029 Uji nyata pada database uji bersih (bukan data pemilik): bangun skenario lewat jalur aplikasi, dry-run, baca Excel, apply dengan hash, dry-run ulang = nol; catat hasil di sini. JIKA tidak dijalankan, tulis "tidak dijalankan" — jangan ditandai selesai
- [ ] T030 Serahkan ke pemilik: dry-run terhadap data LIVE pemilik HANYA dijalankan oleh/atas permintaan pemilik; apply tidak pernah dijalankan oleh asisten tanpa persetujuan eksplisit atas Excel yang ditinjau pemilik

## Dependencies & urutan

- T001 → T002 → Phase 2 (T003 → T004 → T005) → Phase 3 → Phase 4 → Phase 5 → Phase 6.
- Dalam US1: tes T006–T012 ditulis sebelum T013; T014 setelah T013; T015 butuh T024 (laporan) atau menulis Excel sementara lewat stub yang diganti di T024 — urutkan T024 sebelum T015 bila memilih tanpa stub.
- US2 (T016–T022) butuh T013 dan T015; T020 sebelum T021.
- US3 (T023–T025) melengkapi laporan yang dipakai US1/US2; T024 boleh dikerjakan lebih awal agar T015 tidak perlu stub.
- Semua tes satu berkas, jadi `[P]` hanya paralel logis (tulis bersama, jalankan sekali).

## Strategi

- MVP = Phase 2 + US1 (dry-run aman + Excel) — memberi pemilik angka untuk ditinjau tanpa risiko; US2 menambah kemampuan menulis hanya sesudah MVP terbukti.
- Commit seperti 048–050: `docs(spec)` (direktori spec + `feature.json`), lalu `feat(stock)` (kode, tes, RUNBOOK, CLAUDE.md).
- Di luar cakupan: layar/UI, stok bahan baku, penambahan kedatangan pre-order, koreksi multi-jendela otomatis untuk varian dengan banyak pembaruan.
