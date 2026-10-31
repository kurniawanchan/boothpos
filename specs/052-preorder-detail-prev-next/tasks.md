---
description: "Daftar tugas fitur 052 — navigasi Sebelumnya/Berikutnya di detail pre-order"
---

**Klasifikasi**: INTERNAL

# Tasks: Navigasi Sebelumnya/Berikutnya di Detail Pre-order

**Input**: Design documents from `/specs/052-preorder-detail-prev-next/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-neighbors.md, quickstart.md

**Tests**: WAJIB (Konstitusi Prinsip II: feature test backend di MySQL, tes komponen frontend, verifikasi browser sungguhan). Urutan TDD: tes ditulis dan dipastikan GAGAL sebelum implementasinya.

**Organization**: dikelompokkan per user story spec.md (US1 navigasi, US2 nomor tetangga + status nonaktif, US3 keyboard).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: dapat dikerjakan paralel (berkas berbeda, tidak bergantung tugas yang belum selesai)
- **[Story]**: user story pemilik tugas (US1, US2, US3)

## Aturan yang berlaku untuk SEMUA tugas

- Komentar kode, docblock, dan pesan commit berbahasa Indonesia; jelaskan *mengapa* (rujuk fitur `052` / research Decision N).
- Tanpa hex: pakai token `@theme` (`bg-mint-50` untuk sorotan).
- Teks UI hanya lewat i18n (`preorders.*` di `resources/js/locales/en.json` DAN `id.json`).
- Menjalankan tes backend di container WAJIB dengan `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` (lihat CLAUDE.md); tanpa itu database dev terhapus.
- Jangan ubah `resources/js/components/ui/BaseDrawer.vue` (dipakai layar lain); isi slot `header`-nya saja.

---

## Phase 1: Setup

**Purpose**: baseline sebelum perubahan, agar kegagalan yang sudah ada tidak dikira regresi fitur ini.

- [X] T001 Jalankan baseline dan catat hasilnya di bagian "Catatan eksekusi" pada akhir `specs/052-preorder-detail-prev-next/tasks.md`: `php artisan test --filter=Preorder` (atau via container dengan flag `-e` di atas) dan `npm test -- PreordersView localeKeys`. Catat jumlah lulus/gagal; kegagalan yang sudah ada dicatat apa adanya, bukan diperbaiki di fitur ini.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: urutan daftar yang deterministik + endpoint tetangga. US1 dan US2 sama-sama bergantung pada fase ini.

**⚠️ CRITICAL**: belum ada pekerjaan user story sebelum fase ini selesai.

### Tes (tulis dulu, pastikan GAGAL)

- [X] T002 [P] Tambah tes regresi pengikat urutan di `tests/Feature/PreorderSearchTest.php`: buat 30 pre-order dengan `created_at` IDENTIK (`Preorder::query()->update(['created_at' => '2026-10-01 10:00:00'])` setelah dibuat lewat helper `makePreorderFor()`), ambil `GET /api/v1/preorders?per_page=10&page=1..3`, lalu pastikan gabungan id ketiga halaman = 30 id unik, terurut `id desc`. Ulangi untuk `sort_by=status&sort_dir=asc` (semua status sama) dan pastikan urutan `id asc`. Nama tes: `test_list_order_is_stable_across_pages_when_sort_values_tie`, `test_explicit_sort_breaks_ties_by_id_in_the_same_direction`.
- [X] T003 [P] Buat `tests/Feature/PreorderNeighborsTest.php` (pola fixture seperti `PreorderSearchTest`: `RefreshDatabase`, `actingAsCashier()`, pembuatan pre-order lewat `POST /api/v1/preorders`). Tes, semuanya `GET /api/v1/preorders/{id}/neighbors`:
  - `test_middle_row_returns_previous_and_next_in_default_order` — 3 pre-order A,B,C (dibuat berurutan, bawaan `created_at desc, id desc` → C,B,A); untuk B: `previous`=C, `next`=A (`id` + `preorder_number`), `in_list`=true, `position`=2, `total`=3.
  - `test_first_row_has_no_previous_and_last_row_has_no_next` dan `test_single_row_has_neither`.
  - `test_neighbors_cross_page_boundaries` — 30 pre-order, kirim `page=1&per_page=25` (harus diabaikan); id posisi 25 punya `next` = id posisi 26 (bandingkan dengan `GET /preorders?page=2&per_page=25` baris pertama).
  - `test_neighbors_follow_status_filter_and_search` — campuran status; dengan `status[]=deposit_paid`, tetangga hanya pre-order berstatus itu; dengan `search=<nama pelanggan>` juga.
  - `test_neighbors_follow_every_sort_option` — untuk setiap `sort_by` di whitelist `applySort()` (`preorder_number`, `customer_name`, `status`, `fulfillment`, `total_amount`, `outstanding`, `created_at`, `updated_at`) × `sort_dir` asc/desc: telusuri `next` mulai dari baris pertama `GET /preorders` (per_page 100) dan pastikan urutan id identik dengan daftar (SC-003), lalu telusuri `previous` dari ujung dan pastikan kebalikannya.
  - `test_not_in_list_returns_in_list_false_and_null_neighbors` — pre-order berstatus `ordered`, filter `status[]=handed_over`: `in_list`=false, `position`=null, `previous`/`next`=null, `total` = jumlah baris tersaring.
  - `test_other_data_mode_preorder_is_404` — pre-order dibuat di bawah `ModeGate::runAs('demo', …)` saat mode aktif `live` → 404.
  - `test_unauthenticated_is_401` dan `test_endpoint_writes_nothing` (jumlah baris `activity_logs` dan `preorders.updated_at` tidak berubah).
  - `test_neighbors_payload_has_no_customer_data` — kunci `previous`/`next` persis `['id','preorder_number']`.

### Implementasi

- [X] T004 Ubah `applySort()` di `app/Http/Controllers/Api/PreorderController.php`: setiap cabang (termasuk `customer_name` JOIN, `outstanding` `orderByRaw`, kolom whitelist, dan bawaan) diakhiri `->orderBy('preorders.id', $sortDir)`; bawaan menjadi `orderByDesc('preorders.created_at')->orderByDesc('preorders.id')`. Perbarui docblock: alasan pengikat (research Decision 3 — nilai sama tidak punya urutan pasti di MySQL sehingga baris bisa muncul di dua halaman / tetangga melompat). T002 harus lulus.
- [X] T005 Tambah method `neighbors(Request $request, Preorder $preorder): JsonResponse` di `app/Http/Controllers/Api/PreorderController.php` (dekat `summary()`): `$ids = $this->applySort($this->applyFilters(Preorder::query(), $request), $request)->pluck('preorders.id')->all();` → `array_search` id yang dibuka (strict, cast int) → bila tidak ditemukan kembalikan `in_list:false, position:null, total:count($ids), previous:null, next:null`; bila ditemukan ambil id indeks −1/+1, ambil nomornya dengan satu `Preorder::whereIn('id', …)->pluck('preorder_number', 'id')`, kembalikan sesuai `contracts/preorder-neighbors.md` §1. Docblock: satu sumber urutan, alasan `pluck` vs keyset (research Decision 2, ditinjau ulang > ~50.000 pre-order), hanya baca.
- [X] T006 Daftarkan rute `Route::get('/preorders/{preorder}/neighbors', [PreorderController::class, 'neighbors']);` di `routes/api.php` di samping `/preorders/{preorder}/invoice` (sesudah `apiResource('preorders', …)`; segmen statis setelah `{preorder}` tidak bertabrakan). T003 harus lulus seluruhnya.
- [X] T007 [P] Perbarui `docs/openapi-pos-mvp.yaml`: path baru `/preorders/{id}/neighbors` (tag `Preorder`, parameter query sama dengan `GET /preorders`, respons 200/401/404), skema baru `PreorderNeighbors` (`in_list` boolean, `position` integer nullable, `total` integer, `previous`/`next` nullable objek `{id, preorder_number}`), dan catatan pengikat `preorders.id` di deskripsi parameter `sort_by` milik `GET /preorders`. Validasi YAML: `php -r 'yaml_parse_file(...)'` bila ekstensi ada, atau `npx --yes @redocly/cli lint docs/openapi-pos-mvp.yaml` bila sudah dipakai di proyek (cek dulu; jangan menambah dependensi).
- [X] T008 [P] Tambah `getPreorderNeighbors(id, params = {})` di `resources/js/api/preorders.js` (pola yang sama dengan `listPreorders`/`getPreorderSummary`: `client.get(`/preorders/${id}/neighbors`, { params })` → `res.data`). Tambahkan `getPreorderNeighbors: vi.fn()` ke blok `vi.mock('../../resources/js/api/preorders', …)` di `qa-tests/component/PreordersView.test.js` dan beri nilai bawaan di `beforeEach` setiap `describe` yang membuka detail (`{ in_list: false, position: null, total: 0, previous: null, next: null }`) agar tes lama tidak rusak.

**Checkpoint**: endpoint & urutan stabil lulus tes; frontend belum berubah perilakunya.

---

## Phase 3: User Story 1 - Berpindah ke pre-order sebelumnya/berikutnya tanpa menutup detail (Priority: P1) 🎯 MVP

**Goal**: dari panel detail, tombol Sebelumnya/Berikutnya membuka pre-order tetangga (urutan daftar tersaring/terurut, lintas halaman) tanpa menutup panel; baris yang dibuka tersorot.

**Independent Test**: buka detail baris ke-2 → klik Berikutnya → detail baris ke-3 terbuka; Sebelumnya 2× → baris ke-1; panel tidak tertutup.

### Tes (tulis dulu, pastikan GAGAL)

- [X] T009 [US1] Tambah `describe('PreordersView — navigasi detail sebelumnya/berikutnya (052 US1)', …)` di `qa-tests/component/PreordersView.test.js`:
  - membuka detail memanggil `getPreorderNeighbors(id, params)` dengan filter/urutan aktif dan TANPA `page`/`per_page` (set dulu filter status + sort lewat UI atau `listPreorders` yang dipanggil, lalu periksa argumen);
  - klik `data-testid="preorder-nav-next"` → `getPreorder` dipanggil dengan id `next`, drawer tetap terbuka, judul berganti ke nomor tujuan, dan `getPreorderNeighbors` dipanggil ulang untuk id baru;
  - race (FR-008): `getPreorder` untuk klik pertama di-resolve SETELAH klik kedua → judul akhir = tujuan klik kedua (pakai promise yang di-resolve manual);
  - gagal memuat tujuan (mock reject 404) → drawer TETAP terbuka, detail sebelumnya tetap tampil, toast galat, `getPreorderNeighbors` dipanggil ulang untuk id lama;
  - setelah `refreshDetail()` (mis. ubah penanda dispatch lewat `updatePreorderDispatchStatus`) tetangga dimuat ulang;
  - baris daftar milik pre-order yang dibuka mendapat kelas `bg-mint-50`, dan kelas penanda kualitas data dari `preorderRowClass` yang lama tetap ada untuk baris yang memang bertanda.

### Implementasi

- [X] T010 [US1] Di `resources/js/views/PreordersView.vue` tambahkan state `neighbors` (ref null), `neighborsLoading` (ref false), `detailSeq` (counter non-reaktif), dan helper `listContextParams()` = salinan `params` milik `usePaginatedList` tanpa `page`/`per_page`. Tambah fungsi `loadNeighbors(id, seq)` yang memanggil `getPreorderNeighbors(id, listContextParams())` dan hanya menulis `neighbors` bila `seq === detailSeq`; galat tetangga → `neighbors = null` diam-diam (tombol nonaktif), tanpa menutup panel.
- [X] T011 [US1] Di `resources/js/views/PreordersView.vue` perluas `openDetail(row)`, `openDetailById(id)`, dan `refreshDetail()`: setiap panggilan menaikkan `detailSeq`, memuat detail dan `loadNeighbors` PARALEL, dan membuang hasil `getPreorder` yang usang (`seq !== detailSeq`). Tambah opsi `openDetailById(id, { fromNav = false } = {})`: bila `fromNav` dan gagal → JANGAN set `showDetail=false`, pertahankan `detail` lama, tampilkan `toast.error(t('preorders.nav_target_gone'))`, dan `loadNeighbors(detail.value.id, …)`; perilaku lama (tutup panel) tetap untuk pemanggil lain. Komentar `BUG YANG DITEMUKAN & DIPERBAIKI`-style tidak diperlukan (bukan bug), cukup rujuk 052 + research Decision 5.
- [X] T012 [US1] Di `resources/js/views/PreordersView.vue` tambah `goToNeighbor(direction)` (`'previous' | 'next'`) yang memanggil `openDetailById(neighbors.value[direction].id, { fromNav: true })` bila tetangga ada dan navigasi tidak sedang dinonaktifkan (lihat T016). Isi slot `#header` pada `<BaseDrawer>` detail (±baris 1588): pertahankan judul `h2` + subjudul seperti tampilan bawaan BaseDrawer, lalu kelompok dua tombol (ikon Phosphor `ph-caret-left` / `ph-caret-right`, gaya tombol sekunder yang sudah ada di layar, tinggi 34 px agar sejajar tombol tutup) dengan `data-testid="preorder-nav-prev"` / `"preorder-nav-next"`. Pada fase ini label cukup ikon + teks generik dari i18n; nomor tetangga ditambahkan di US2.
- [X] T013 [US1] Di `resources/js/views/PreordersView.vue` perluas `preorderRowClass(row)` agar MENGGABUNGKAN (bukan menimpa) kelas lama dengan `'bg-mint-50'` bila `showDetail.value && detail.value?.id === row.id` (research Decision 9). Halaman daftar tidak dipindah otomatis.
- [X] T014 [P] [US1] Tambah kunci i18n `preorders.nav_previous`, `preorders.nav_next`, `preorders.nav_target_gone` di `resources/js/locales/en.json` dan `resources/js/locales/id.json` (id: "Sebelumnya", "Berikutnya", "Pre-order tujuan tidak dapat dibuka — mungkin sudah dihapus atau tidak lagi ada di daftar."; en padanannya). Pastikan tidak ada kunci ganda di seksi `preorders` (jebakan fitur 025). T009 harus lulus.

**Checkpoint**: US1 berfungsi penuh dan dapat diuji sendiri (MVP).

---

## Phase 4: User Story 2 - Melihat nomor pre-order tetangga sebelum berpindah (Priority: P1)

**Goal**: setiap tombol menampilkan nomor pre-order tujuan; di tepi daftar / di luar daftar / saat memuat / saat form pengiriman terbuka tombol tetap terlihat tetapi nonaktif dengan alasan.

**Independent Test**: detail baris tengah → tombol bertuliskan nomor baris di atas dan di bawahnya, sama persis dengan daftar; baris pertama → Sebelumnya nonaktif dengan tooltip.

### Tes (tulis dulu, pastikan GAGAL)

- [X] T015 [US2] Tambah `describe('PreordersView — nomor tetangga & status nonaktif (052 US2)', …)` di `qa-tests/component/PreordersView.test.js`:
  - tombol menampilkan `previous.preorder_number` / `next.preorder_number` dari mock; setelah navigasi label ikut berganti;
  - `previous: null` → tombol Sebelumnya `disabled` dan tooltip `nav_first`; `next: null` → `nav_last`; keduanya null + `in_list:false` → keduanya disabled dengan `nav_not_in_list`;
  - selama `getPreorderNeighbors` belum resolve → kedua tombol disabled (`nav_busy`);
  - membuka form pengiriman inline (`showShipmentForm`) → kedua tombol disabled; menutupnya → aktif kembali;
  - `aria-label` berisi nomor tujuan ("Pre-order berikutnya: PO-…" untuk locale id).

### Implementasi

- [X] T016 [US2] Di `resources/js/views/PreordersView.vue` tambah computed `navState` → untuk tiap arah `{ target, disabled, reason }`: `disabled` bila `neighborsLoading` (`reason: nav_busy`), `showShipmentForm` terbuka (`nav_busy`), `neighbors?.in_list === false` (`nav_not_in_list`), atau target null (`nav_first`/`nav_last`). `goToNeighbor()` (T012) menolak arah yang `disabled`. Render nomor `target.preorder_number` sebagai label tombol (font mono seperti kolom nomor di daftar, `truncate` aman; nomor `PO-YYYYMMDD-NNNN` 16 karakter) dengan ikon panah, dan bungkus tiap tombol dengan `BaseTooltip` berteks alasan saat disabled (bukan atribut `title` — aturan fitur 037). `:aria-label` = `t('preorders.nav_previous_aria', { number })` / `nav_next_aria`; saat tanpa target pakai teks alasan.
- [X] T017 [P] [US2] Tambah kunci i18n `preorders.nav_previous_aria` ("Pre-order sebelumnya: {number}"), `nav_next_aria`, `nav_first` ("Ini pre-order pertama di daftar"), `nav_last` ("Ini pre-order terakhir di daftar"), `nav_not_in_list` ("Pre-order ini tidak ada di daftar dengan filter saat ini"), `nav_busy` ("Tunggu sampai selesai / tutup form dulu") di `resources/js/locales/id.json` dan padanan Inggris di `resources/js/locales/en.json`. T015 harus lulus; jalankan juga `npm test -- localeKeys`.
- [ ] T018 [US2] Periksa tata letak header di lebar 880 px drawer dan di layar sempit (≤ 640 px, aturan gutter 16 px): judul tidak terdesak oleh dua tombol bernomor; bila sempit, kelompok tombol turun ke baris kedua di bawah subjudul (flex-wrap), tanpa scroll horizontal. Sesuaikan kelas di slot header `resources/js/views/PreordersView.vue`.

**Checkpoint**: US1 + US2 lengkap — permintaan inti selesai.

---

## Phase 5: User Story 3 - Navigasi dengan keyboard (Priority: P3)

**Goal**: ←/→ menavigasi saat panel detail terbuka dan fokus di luar kolom isian.

**Independent Test**: fokus di panel (bukan input) → → membuka pre-order berikutnya; ketik di input → panah hanya menggerakkan kursor.

### Tes (tulis dulu, pastikan GAGAL)

- [X] T019 [US3] Tambah `describe('PreordersView — navigasi keyboard (052 US3)', …)` di `qa-tests/component/PreordersView.test.js`: `keydown` `ArrowRight` pada elemen non-input di dalam drawer → `getPreorder` dipanggil dengan id `next`; `ArrowLeft` → `previous`; `keydown` dari `<input>`/`<textarea>`/`<select>` di dalam drawer → tidak ada navigasi; dengan `altKey`/`ctrlKey`/`metaKey`/`shiftKey` → tidak ada navigasi; arah yang `disabled` (T016) → tidak ada navigasi.

### Implementasi

- [X] T020 [US3] Di `resources/js/views/PreordersView.vue` tambah `onDetailKeydown(e)` pada wrapper konten drawer detail (elemen di dalam slot default dan slot header — pasang di elemen yang membungkus keduanya, atau pasang di keduanya): abaikan bila ada modifier, bila `e.target.closest('input, textarea, select, [contenteditable="true"], [role="listbox"], [role="combobox"]')`, atau bila `e.defaultPrevented`; `ArrowLeft` → `goToNeighbor('previous')`, `ArrowRight` → `goToNeighbor('next')`, lalu `e.preventDefault()`. Modal yang di-Teleport ke `body` (pembayaran, konfirmasi, edit) tidak mengirim event ke handler ini — tulis komentar singkat yang menjelaskannya (research Decision 8). T019 harus lulus.

**Checkpoint**: semua user story berfungsi mandiri.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T021 Jalankan suite penuh dan catat hasil di "Catatan eksekusi": backend `php artisan test` (host) ATAU `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`; frontend `npm test`; `npm run build`. Bandingkan dengan baseline T001; regresi baru WAJIB diperbaiki sebelum lanjut.
- [ ] T022 Verifikasi browser sungguhan mengikuti 12 langkah `specs/052-preorder-detail-prev-next/quickstart.md` (≥ 30 pre-order agar ada > 1 halaman; DEMO via `SakanaFridgeDemoSeeder` bila perlu), periksa konsol (tanpa error/warning baru) dan tab Network (satu `neighbors` per buka/navigasi, tanpa request berlebih). Catat hasil per langkah di "Catatan eksekusi"; bila alat browser tidak tersedia, tulis "BELUM DIVERIFIKASI DI BROWSER" secara eksplisit — jangan menandai selesai.
- [X] T023 [P] Tambah ringkasan fitur ke `CLAUDE.md` di luar blok SPECKIT, bagian baru "## Pre-order detail prev/next (feature 052, 2026-10-31)": endpoint `GET /preorders/{preorder}/neighbors` memakai `applyFilters()`+`applySort()` (satu sumber urutan, jangan buat urutan kedua); pengikat `preorders.id` di setiap cabang `applySort()` (jangan dihapus — paginasi dan tetangga bergantung padanya); `in_list:false` untuk pre-order di luar filter; `detailSeq` membuang respons usang; navigasi gagal tidak menutup panel; sorotan baris digabung ke `preorderRowClass`.
- [X] T024 [P] Tinjauan keamanan ringkas atas diff (risiko: kontrol akses & validasi input): endpoint tidak membocorkan PII (hanya `id`+`preorder_number`), `sort_by` tetap whitelist (tidak ada `orderByRaw` dari input), cakupan DEMO/LIVE dipatuhi lewat builder Eloquent (bukan `DB::table`), tanpa jalur tulis. Catat temuan di "Catatan eksekusi".

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: tanpa ketergantungan.
- **Foundational (Phase 2)**: setelah Setup; MEMBLOKIR semua user story.
- **US1 (Phase 3)**: setelah Phase 2.
- **US2 (Phase 4)**: setelah Phase 2; secara berkas bergantung pada slot header dan `goToNeighbor()` dari US1 (T012) — kerjakan sesudah US1 (berkas `PreordersView.vue` yang sama).
- **US3 (Phase 5)**: setelah US1 (memakai `goToNeighbor`) dan idealnya US2 (menghormati `disabled` dari T016).
- **Polish (Phase 6)**: setelah semua story yang dipilih selesai.

### Dalam fase

- T002, T003 (tes) sebelum T004–T006; T004 sebelum T005 (neighbors bergantung pada urutan yang deterministik); T005 sebelum T006.
- T007 dan T008 dapat paralel dengan T004–T006 (berkas berbeda).
- Tes story (T009, T015, T019) sebelum implementasinya; semua tes frontend berada di satu berkas, jadi tidak diparalelkan satu sama lain.
- T010 → T011 → T012 → T013 berurutan (berkas yang sama); T014 paralel dengan T010–T013.

### Parallel Opportunities

```text
# Phase 2 — tes dan berkas pendukung serentak:
T002 tests/Feature/PreorderSearchTest.php
T003 tests/Feature/PreorderNeighborsTest.php
T007 docs/openapi-pos-mvp.yaml
T008 resources/js/api/preorders.js (+ mock di PreordersView.test.js)

# Phase 3 — i18n paralel dengan implementasi view:
T014 locales en/id   ||   T010–T013 PreordersView.vue

# Phase 4:
T017 locales en/id   ||   T016 PreordersView.vue

# Phase 6:
T023 CLAUDE.md   ||   T024 tinjauan keamanan
```

---

## Implementation Strategy

### MVP First (US1)

1. Phase 1 → Phase 2 (endpoint + urutan stabil, tes hijau).
2. Phase 3 (US1) → tombol bekerja, lintas halaman, tanpa menutup panel.
3. **STOP & VALIDATE** dengan langkah 1–3, 5, 6, 8 quickstart.

### Incremental Delivery

1. + US2 → nomor tetangga di tombol dan status nonaktif (inti permintaan pengguna lengkap; disarankan dikirim bersama US1 karena permintaan eksplisit menyebut "show the previous and next PO number").
2. + US3 → keyboard.
3. Phase 6 → suite penuh, browser, dokumentasi, tinjauan keamanan.

---

## Catatan eksekusi

2026-10-31, dijalankan di container `app` dengan `-e APP_ENV=testing -e DB_DATABASE=boothpos_test -e CACHE_STORE=array`.

- **T001 baseline**: backend `--filter=Preorder` 310/310 lulus; frontend `PreordersView` + `localeKeys` 126/126 lulus (baris "Not implemented: navigation" dari jsdom sudah ada sebelumnya).
- **TDD**: T002/T003 GAGAL sebelum T004–T006 (termasuk kedua tes pengikat → urutan baris bernilai sama memang tidak stabil di kode lama); T009 dan T019 (positif) GAGAL sebelum implementasi. T015 (US2) ditulis SETELAH markup header (label/nonaktif ikut dibangun bersama T012) — diganti dengan uji mutasi: `disabled` dipaksa `false` → 6/7 tes US2 gagal. Uji mutasi keyboard: tiga penjaga dihapus → 3 tes negatif US3 gagal. Keduanya dikembalikan.
- **Penyimpangan dari tasks**: (1) tetangga juga dimuat ulang lewat `watch(items)` karena ubah penanda dispatch memanggil `load()`, bukan `refreshDetail()`; (2) tombol nonaktif selama memuat, sehingga quickstart langkah 8 diubah (klik cepat diabaikan, bukan "berakhir di ke-4"); (3) `aria-disabled`, bukan `disabled` (tooltip alasan tetap terjangkau papan ketik); (4) kunci i18n US1+US2 ditambahkan sekaligus, plus `nav_label`.
- **T021 suite penuh** (1.944 s): backend 1514 lulus / 10 gagal:
  - `PreorderDispatchStatusTest::test_list_and_detail_expose_created_updated_and_dispatch_dates_and_sort_by_them` — DISEBABKAN pengikat fitur ini: tes mengandalkan urutan seri `updated_at` yang tidak terdefinisi (update di detik yang sama dengan pembuatan baris lain). Diperbaiki di tes (`travel(1)->seconds()` sebelum update, asersi tidak diubah); file terkait dijalankan ulang 30/30 lulus.
  - `Tests\Unit\Code128Test` ×9 — `Class "Picqer\Barcode\Types\TypeCode128B" not found`: `picqer/php-barcode-generator` adalah `require-dev` dan tidak terpasang di `vendor/` container. Tidak terkait fitur ini; tidak diubah.
  - Frontend: `npx vitest run --exclude '.claude/**'` 99 berkas, 973 lulus / 2 skip / 0 gagal. (`npm test` polos ikut mengumpulkan tes worktree `.claude/worktrees/083-android-pos-app`, yang gagal 1 tes `ProductImageUpload` miliknya sendiri.)
  - `npm run build` sukses (`PreordersView` 109,89 kB / gzip 26,40 kB).
- **T022 / T018: BELUM DIVERIFIKASI DI BROWSER.** MCP Playwright dan Chrome DevTools gagal terhubung di sesi ini dan tidak ada Playwright/Puppeteer lokal. Belum terlihat: tata letak header di 880 px dan ≤ 640 px, apakah `bg-mint-50` terlihat pada baris yang juga `bg-warn-bg` (keduanya properti `background-color` yang sama), tooltip pada tombol `aria-disabled`, konsol browser, dan 12 langkah quickstart.
- **T024 keamanan**: kontrol akses — gerbang sama dengan `GET /preorders`/`show()` (`auth:sanctum` + `DataModeScope`), tidak membuka data baru; validasi input — `sort_by` tetap whitelist, `sort_dir` dinormalkan `asc|desc`, satu-satunya `orderByRaw` adalah yang lama (hanya ditambah pengikat); paparan data — respons hanya `id` + `preorder_number`, dirender sebagai teks (tanpa `v-html`); tanpa jalur tulis. Tidak ada temuan.
- **Catatan penomoran**: branch worktree `083-android-pos-app` sudah ada, sehingga nomor 083 dipakai dua branch (nama berbeda, tidak bentrok di git).
