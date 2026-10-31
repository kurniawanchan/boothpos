**Klasifikasi**: INTERNAL

# Tasks: Sel Satu Baris di Daftar Pre-order

**Input**: Design documents from `/specs/053-preorder-list-oneline-cells/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-list-cells.md, quickstart.md

**Tests**: WAJIB — Konstitusi II (perubahan frontend harus disertai tes di `qa-tests/` + pemeriksaan peramban nyata).
Pendekatan TDD: tulis tes, pastikan GAGAL, baru implementasi.

**Organization**: Dua user story P1 yang independen (US1 = satu baris nomor/total, US2 = tanggal di tooltip). Keduanya
menyentuh `PreordersView.vue` dan file tesnya yang sama, jadi dikerjakan berurutan (bukan paralel), tetapi masing-masing
bisa diuji dan dirilis sendiri.

**Konvensi kode**: komentar Bahasa Indonesia, menjelaskan *mengapa* dan menyebut fitur `053`; kelas token Tailwind saja
(tanpa hex). Jalankan Vitest dengan `--exclude '.claude/**'` (worktree bersarang ikut terkumpul — lihat CLAUDE.md 052).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 / US2 (spec.md)

---

## Phase 1: Setup (Baseline)

**Purpose**: Bukti titik awal sebelum ada perubahan, supaya kegagalan setelahnya bisa dibedakan dari kegagalan lama.

- [X] T001 Jalankan `npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js qa-tests/component/BaseTooltip.test.js` dan seluruh suite `npx vitest run --exclude '.claude/**'`; catat jumlah lulus/gagal (termasuk kegagalan `ProductsView` yang sudah diketahui dari 052, bila masih ada) di bagian "Catatan eksekusi" di bawah file ini, specs/053-preorder-list-oneline-cells/tasks.md

---

## Phase 2: Foundational

Tidak ada — tidak ada migrasi, API, atau infrastruktur bersama yang menghalangi kedua story. Perubahan komponen bersama
`BaseTooltip` hanya dibutuhkan US2, jadi ditempatkan di fase US2.

---

## Phase 3: User Story 1 - Nomor PO dan Total tidak patah ke baris kedua (Priority: P1) 🎯 MVP

**Goal**: Nomor pre-order (dengan/ tanpa ikon bendera) dan nilai Total selalu tampil utuh dalam satu baris (FR-001–FR-003, FR-009).

**Independent Test**: Di daftar Pre-order lebar 1280 px, semua nomor dan semua Total satu baris; klik nomor tetap membuka detail.

### Tests for User Story 1 ⚠️

> Tulis dulu, pastikan GAGAL sebelum T004.

- [X] T002 [US1] Di qa-tests/component/PreordersView.test.js tambahkan blok `describe('053 — sel satu baris')` dengan tes: (a) sel nomor — elemen pembungkus terdekat dari tombol `PO-0010` (`closest('span')`) memiliki kelas `whitespace-nowrap`, baik untuk baris biasa maupun baris yang memunculkan tombol bendera (`needsShippingAttention`: `fulfillment: 'courier'` tanpa info pengiriman — salin bentuk data dari tes bendera yang sudah ada di file ini) dan tombol bendera berada di dalam pembungkus yang sama; (b) sel Total untuk `total_amount: '125000000.00'` — elemen yang berisi teks `formatIDR` hasilnya (cari via `within(row).getByText(/Rp\s?125\.000\.000/)`) memiliki kelas `whitespace-nowrap` dan teksnya sama persis dengan keluaran `formatIDR('125000000.00')` (impor dari `resources/js/utils/money.js`)
- [X] T003 [US1] Di blok yang sama, tes regresi: klik tombol nomor pada sel satu baris tetap memanggil `getPreorder` dengan id baris (pakai pola `userEvent` + `getPreorder.mockResolvedValue(DETAIL)` yang sudah ada di file), qa-tests/component/PreordersView.test.js

### Implementation for User Story 1

- [X] T004 [US1] Di resources/js/views/PreordersView.vue slot `#cell-preorder_number`: tambahkan `whitespace-nowrap` pada `<span class="inline-flex items-center gap-1.5">` yang membungkus bendera + tombol nomor, dengan komentar Indonesia singkat: sel `DataTable` tidak `nowrap` dan peramban mematahkan nomor setelah tanda hubung (053, research R1). JANGAN pakai `truncate`.
- [X] T005 [US1] Di resources/js/views/PreordersView.vue slot `#cell-total_amount`: ganti isi menjadi `<span class="whitespace-nowrap">{{ formatIDR(row.total_amount) }}</span>` dengan komentar: `formatIDR` memakai spasi biasa sehingga `Rp` bisa terpisah dari angka; `formatIDR` sengaja TIDAK diubah karena dipakai struk/invoice/ekspor (053, research R1)
- [X] T006 [US1] Jalankan `npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js`; T002–T003 harus lulus dan tidak ada tes lain di file itu yang baru gagal dibanding T001

**Checkpoint**: US1 selesai dan bisa diuji/dirilis sendiri.

---

## Phase 4: User Story 2 - Tanggal invoice/pengiriman di tooltip samping status (Priority: P1)

**Goal**: Sel status dispatch satu baris (StatusPill + ikon ⓘ bila ada tanggal); tanggal hanya di tooltip yang aksesibel
keyboard, dua baris, tidak terpotong tabel (FR-004–FR-008, FR-011).

**Independent Test**: Pre-order "Sedang dikirim" dengan dua tanggal → baris tabel tanpa teks tanggal; hover/Tab ke ikon
menampilkan dua baris tanggal; pre-order "Menunggu" tanpa ikon.

### Tests for User Story 2 ⚠️

> Tulis dulu, pastikan GAGAL sebelum T011–T013.

- [X] T007 [P] [US2] Di qa-tests/component/BaseTooltip.test.js tambahkan tes: gelembung `role="tooltip"` memiliki kelas `whitespace-pre-line`, dan untuk `text: 'Baris satu\nBaris dua'` `textContent` gelembung sama persis dengan string itu (baris baru dipertahankan); tes yang sudah ada tidak diubah
- [X] T008 [US2] Di qa-tests/component/PreordersView.test.js TULIS ULANG tes `'shows the invoice-sent and shipping dates inside the existing status cell (no extra column)'` (alasan di research R6 — tanggal kini di tooltip yang di-teleport ke `<body>`): dengan data yang sama (`dispatch_status: 'shipping'`, kedua tanggal), harapkan `row` TIDAK memuat `'Invoice terkirim:'` maupun `'Pengiriman mulai:'`; `within(row).getByTestId('dispatch-dates-info')` ada, `aria-label`-nya memuat `'Invoice terkirim: 27 Sep 2026'` dan `'Pengiriman mulai: 28 Sep 2026'`; setelah `fireEvent.focus` / `user.tab()` sampai ke tombol itu (atau `fireEvent.mouseEnter` pada pembungkusnya), `screen.getByRole('tooltip')` terlihat dan `textContent`-nya = baris invoice + `\n` + baris pengiriman (urutan itu); `user.keyboard('{Escape}')` menyembunyikannya. Ganti judul tes menjadi `'053: shows the invoice-sent and shipping dates in a tooltip beside the dispatch status, not as cell text'`
- [X] T009 [US2] Di qa-tests/component/PreordersView.test.js tambahkan tes kasus tepi: (a) `dispatch_status: 'pending'`, kedua tanggal `null` → tidak ada `dispatch-dates-info` di baris; (b) `dispatch_status: null` → tidak ada ikon, label StatusPill = label "pending"; (c) hanya `shipping_at` terisi (`invoice_sent_at: null`) → ikon ada, tooltip/aria-label hanya memuat baris pengiriman; (d) `dispatch_status: 'invoice_sent'` dengan `invoice_sent_at` saja → tooltip satu baris invoice; (e) sel dispatch dibungkus elemen ber-kelas `whitespace-nowrap` yang memuat StatusPill dan tombol ikon
- [X] T010 [US2] Pastikan tes panel detail `'shows created, updated and dispatch dates in the detail drawer'` di qa-tests/component/PreordersView.test.js TIDAK diubah (FR-010) — hanya baca, tidak ada edit

### Implementation for User Story 2

- [X] T011 [P] [US2] Di resources/js/components/ui/BaseTooltip.vue tambahkan kelas `whitespace-pre-line` pada `<span role="tooltip">` gelembung, dan tambahkan satu kalimat ke docblock (053): `\n` di `text` menjadi baris baru; teks tanpa `\n` tampil sama seperti sebelumnya. Prop/emit/slot tidak berubah.
- [X] T012 [US2] Di resources/js/views/PreordersView.vue `<script setup>` tambahkan fungsi `dispatchDatesLines(row)` yang mengembalikan array baris: `t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(row.invoice_sent_at) })` bila `row.invoice_sent_at` ada, lalu `t('preorders.dispatch_shipping_on', { date: formatDateTime(row.shipping_at) })` bila `row.shipping_at` ada (urutan tetap invoice → pengiriman; ikon bergantung pada TANGGAL, bukan `dispatch_status` — data-model.md). Letakkan di dekat definisi `DISPATCH_LABEL`/`DISPATCH_VARIANT`, dengan komentar Indonesia bahwa ini satu-satunya sumber teks tanggal untuk ikon, tooltip (gabung `\n`) dan `aria-label` (gabung `. `). Pakai kunci locale yang sudah ada; JANGAN tambah kunci baru.
- [X] T013 [US2] Di resources/js/views/PreordersView.vue slot `#cell-dispatch_status`: ganti `<div class="flex flex-col items-start gap-1">` + dua `<span>` tanggal dengan `<span class="inline-flex items-center gap-1.5 whitespace-nowrap">` berisi `StatusPill` yang sama dan, `v-if="dispatchDatesLines(row).length"`, `<BaseTooltip :text="dispatchDatesLines(row).join('\n')">` yang membungkus `<button type="button" data-testid="dispatch-dates-info" class="flex h-5 w-5 items-center justify-center rounded text-muted-3 hover:bg-line-7 hover:text-brand-active" :aria-label="dispatchDatesLines(row).join('. ')"><i class="ph-duotone ph-info text-[14px]" aria-hidden="true"></i></button>`. Ganti komentar lama ("Tanggal ditandai, di kolom yang sama") dengan komentar 053: tanggal pindah ke tooltip agar tinggi baris seragam; `title` native tidak dipakai karena tidak bisa dicapai keyboard. Pastikan `BaseTooltip` sudah diimpor (sudah, baris impor 052). Cek nama kelas token (`text-muted-3`, `hover:bg-line-7`, `hover:text-brand-active`) memang ada di resources/css/app.css / dipakai di file ini.
- [X] T014 [US2] Jalankan `npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js qa-tests/component/BaseTooltip.test.js`; T007–T009 lulus, tes detail (T010) tetap lulus, tidak ada kegagalan baru dibanding T001

**Checkpoint**: US1 dan US2 sama-sama berfungsi.

---

## Phase 5: Polish & Verifikasi

- [X] T015 Jalankan seluruh suite frontend `npx vitest run --exclude '.claude/**'` dan `npm run build`; bandingkan dengan baseline T001 dan catat hasil di "Catatan eksekusi", specs/053-preorder-list-oneline-cells/tasks.md
- [X] T016 Pastikan tidak ada pemanggil `BaseTooltip` lain yang terdampak `whitespace-pre-line`: periksa teks yang diteruskan di resources/js/views/ProductsView.vue, resources/js/views/SettlementDetailView.vue dan resources/js/views/PreordersView.vue (tombol navigasi 052) tidak memuat `\n`; catat hasilnya di "Catatan eksekusi"
- [ ] T017 Verifikasi di peramban nyata sesuai specs/053-preorder-list-oneline-cells/quickstart.md langkah 1–10 (lebar 1280 px, nomor/total satu baris, bendera sebaris, ikon ⓘ + tooltip via hover dan Tab, Escape menutup, tooltip baris terakhir tidak terpotong, tinggi baris seragam, gulir horizontal saat sempit, konsol bersih, panel detail tidak berubah). Gunakan data DEMO/test, JANGAN data LIVE; catat bukti (tangkapan/teks) di "Catatan eksekusi". Bila alat peramban tidak tersedia, tandai BELUM TERVERIFIKASI — jangan dicentang.
- [X] T018 [P] Bila implementasi menyimpang dari plan (nama fungsi, struktur sel, kelas), perbarui catatan feature 053 di blok SPECKIT "Active feature plan" agar cocok dengan kode; bila tidak menyimpang, tidak ada edit, CLAUDE.md

---

## Dependencies & Execution Order

- **T001** → semua tugas lain.
- **US1 (T002–T006)** dan **US2 (T007–T014)** independen secara perilaku, tetapi berbagi `PreordersView.vue` dan
  `PreordersView.test.js` → kerjakan berurutan (US1 dulu, MVP). US2 bisa dikerjakan lebih dulu tanpa US1 bila perlu.
- Di dalam US2: T007 → T011 (BaseTooltip); T008–T009 → T012 → T013 → T014.
- **Polish (T015–T018)** setelah kedua story.

### Parallel Opportunities

- T007 (BaseTooltip.test.js) dan T011 (BaseTooltip.vue) berbeda file dari tugas US2 lainnya → bisa paralel dengan
  T008–T009 / T012.
- T018 (CLAUDE.md) paralel dengan T015–T017.

```text
# US2 paralel:
T007 BaseTooltip.test.js  ‖  T008/T009 PreordersView.test.js
T011 BaseTooltip.vue      ‖  T012 PreordersView.vue (script)
```

## Implementation Strategy

1. T001 baseline.
2. **MVP = US1** (T002–T006): kecil, risiko rendah, langsung terlihat. Berhenti dan validasi.
3. US2 (T007–T014): satu perubahan komponen bersama (satu kelas) + sel dispatch.
4. Polish: suite penuh, build, audit pemanggil tooltip, peramban nyata (wajib sebelum menyatakan selesai).

## Catatan eksekusi

- **T001 baseline (2026-10-31)**: `PreordersView.test.js` + `BaseTooltip.test.js` 91/91 lulus; suite penuh 99 file, 973 lulus / 2 dilewati / 0 gagal (kegagalan `ProductsView` yang dicatat di 052 tidak muncul lagi).
- **US1 (T002–T006)**: dua tes kelas GAGAL dulu ("Expected the element to have class"), tes klik (penjaga regresi) lulus sejak awal; setelah T004–T005 `PreordersView.test.js` 83/83 lulus.
- **US2 (T007–T014)**: T007–T009 GAGAL dulu (kelas `whitespace-pre-line` tidak ada / `dispatch-dates-info` tidak ditemukan). Setelah T011–T013 tes `BaseTooltip` lulus; dengan T012 terisi satu tes masih gagal karena kesalahan TES, bukan kode: tes melakukan hover lalu Escape, padahal hover tidak memindahkan fokus dan `BaseTooltip` mendengar Escape di pembungkusnya (perilaku 037). Tes diperbaiki agar mengikuti dua jalur nyata: tetikus menjauh menutup; fokus (klik/Tab) membuka dan Escape menutup. Hasil: 96/96 lulus.
- **Penyimpangan dari tasks**: ikon tidak memakai `hover:bg-line-7` — itu warna hover BARIS di `DataTable`, jadi tidak terlihat pada baris yang sedang di-hover; cukup `hover:text-brand-active`.
- **Peringatan Vue `Invalid prop "remaining"`** muncul di `PreordersView.test.js` juga pada kode SEBELUM perubahan (55 kali, dicek via `git stash`) — bukan dari fitur ini.
- **T015**: suite penuh 99 file, 978 lulus / 2 dilewati / 0 gagal (baseline 973 + 5 tes baru); `npm run build` sukses.
- **T016**: pemanggil `BaseTooltip` lain — `ProductsView` (`v.variant_name`, `master_data.bom_open_tip`), `SettlementDetailView` (`settlements.reason_*`), `PreordersView` 052 (`navState[dir].reason`) — tidak ada yang memuat `\n` (kedua file locale tanpa `\n`), jadi tampilannya tidak berubah.
- **T017 BELUM TERVERIFIKASI**: MCP chrome-devtools dan playwright gagal tersambung di sesi ini, tidak ada Playwright lokal, dan stack Docker boothpos tidak berjalan. Pemeriksaan peramban nyata (quickstart.md langkah 1–10) masih wajib sebelum fitur dinyatakan selesai.
- **T018**: tidak ada penyimpangan yang mengubah catatan CLAUDE.md (nama `dispatchDatesLines` sudah disinkronkan saat /speckit-tasks) — tidak ada edit.
