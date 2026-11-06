# Tasks: Ringkasan Pilihan di Dialog "Attach" Grup Modifier

**Input**: `specs/091-modifier-attach-selection-summary/` (plan.md, spec.md, research.md, quickstart.md)

**Tests**: disertakan (quickstart.md meminta tes ditulis dulu dan harus gagal sebelum implementasi; Konstitusi II).

**Format**: `- [ ] ID [P?] [Story] Deskripsi + path`. Seluruh perubahan di satu komponen, jadi hampir semua tugas berurutan pada berkas yang sama; [P] hanya untuk berkas berbeda.

## Phase 1: Setup

- [X] T001 Jalankan `npm test -- ModifierGroupsAdmin` di `qa-tests/component/ModifierGroupsAdmin.test.js` dan catat hasil dasar (semua hijau) sebagai pembanding regresi

## Phase 2: Foundational

Tidak ada (tanpa migrasi, API, atau komponen bersama baru; `BaseMultiSelect.vue` sengaja tidak diubah, research D1/D2).

## Phase 3: User Story 2 - Nilai awal "Tidak ada" (Priority: P1)

**Goal**: kosong = "Tidak ada" di isian dan baris pertama daftar, hanya di dialog Attach.

**Independent Test**: buka Attach pada grup tanpa tautan → Categories dan Products menampilkan "Tidak ada", tanpa daftar nama.

- [X] T002 [US2] Tambah tes di `qa-tests/component/ModifierGroupsAdmin.test.js`: grup `product_ids: [], category_ids: []` → buka Attach → kedua isian bertuliskan "Tidak ada" dan tidak ada "Semua"/"All"; membuka daftar → baris pertama "Tidak ada"; memilih lalu membatalkan semua kembali ke "Tidak ada". Pastikan GAGAL dulu.
- [X] T003 [US2] Di `resources/js/components/restaurant/ModifierGroupsAdmin.vue` (dialog Attach, ±baris 328–329) beri kedua `BaseMultiSelect` `:all-label="t('dining_setup.mod_attach_none')"` dan hapus `:placeholder` yang tak pernah tampil (research D1). Tambah komentar Indonesia yang menjelaskan *why* (kosong = tidak melampirkan apa pun, bukan "Semua").
- [X] T004 [US2] Tes regresi di `qa-tests/component/ModifierGroupsAdmin.test.js`: menyimpan tanpa memilih apa pun tetap memanggil `setModifierGroupAttachments(id, { product_ids: [], category_ids: [] })` (SC-004). Jalankan `npm test -- ModifierGroupsAdmin`: T002 kini hijau.

**Checkpoint**: US2 mandiri dan dapat didemokan.

## Phase 4: User Story 1 - Nama pilihan di bawah isian (Priority: P1) 🎯 MVP

**Goal**: ≥ 2 pilihan → nama tampil di bawah isian yang bersangkutan.

**Independent Test**: grup dengan 2+ produk tertaut → nama tampil di bawah isian Products tanpa membuka daftar.

- [X] T005 [US1] Di `qa-tests/component/ModifierGroupsAdmin.test.js` perluas mock `listProducts`/`listCategories` (per tes, via `mockResolvedValueOnce`) menjadi ≥ 3 produk dan ≥ 2 kategori, lalu tulis tes GAGAL-dulu: (a) 2+ produk terpilih → semua nama tampil di bawah Products dalam urutan daftar sumber; (b) 2 kategori → nama tampil di bawah Categories; (c) tepat 1 pilihan → tidak ada daftar tambahan; (d) mencentang/membatalkan → daftar mengikuti; (e) id terpilih tanpa padanan nama → tak ada teks "undefined" dan hitungan isian tetap benar (research D3); (f) payload simpan identik untuk pilihan yang sama.
- [X] T006 [P] [US1] Tambah kunci locale baru (label kecil di atas daftar, mis. `dining_setup.mod_attach_selected_names`) di `resources/js/locales/id.json` dan `resources/js/locales/en.json` di bawah `dining_setup`, dengan teks Indonesia dan Inggris (research D5).
- [X] T007 [US1] Di `resources/js/components/restaurant/ModifierGroupsAdmin.vue` tambah satu helper/`computed` yang memetakan id terpilih → nama dari `categories`/`products` (mempertahankan urutan sumber, membandingkan id dengan `==`, melewati id tanpa nama) dan dipakai untuk KEDUA isian (tanpa duplikasi, Konstitusi I).
- [X] T008 [US1] Di templat dialog Attach (`resources/js/components/restaurant/ModifierGroupsAdmin.vue`) render daftar nama tepat di bawah masing-masing `BaseMultiSelect` hanya bila jumlah id terpilih ≥ 2; teks `text-[12.5px] text-muted-3` (token yang ada, tanpa hex), dipisah koma. Jalankan tes: T005 hijau.

**Checkpoint**: US1 + US2 berjalan bersama.

## Phase 5: User Story 3 - Daftar panjang tetap rapi (Priority: P2)

**Goal**: pilihan puluhan item tidak membuat dialog meluap.

**Independent Test**: pilih seluruh katalog → Simpan/Batal tetap terlihat.

- [X] T009 [US3] Tes di `qa-tests/component/ModifierGroupsAdmin.test.js`: dengan banyak produk terpilih (mis. 40) wadah daftar nama memiliki kelas batas tinggi + gulir (`max-h-*`, `overflow-y-auto`) dan tombol Simpan/Batal tetap ada di DOM. (Verifikasi visual sebenarnya di T012.)
- [X] T010 [US3] Di templat dialog Attach (`resources/js/components/restaurant/ModifierGroupsAdmin.vue`) bungkus daftar nama dengan `max-h-[120px] overflow-y-auto break-words` (research D4); ukuran memakai pola Tailwind yang sudah dipakai berkas ini.

## Phase 6: Polish & Verifikasi

- [X] T011 Jalankan seluruh Vitest (`npm test`) dan pastikan tidak ada regresi, termasuk tes `BaseMultiSelect` yang menegaskan bawaan "Semua" tanpa `allLabel` (quickstart #8); laporkan jumlah lulus/gagal apa adanya.
- [ ] T012 Verifikasi peramban pada `localhost:8010/dining-setup` → Modifiers → Attach "Suhu Minuman" (6 produk: nama tampil di bawah; kosong: "Tidak ada"; banyak: dialog tak meluap) dan satu layar lain pemakai `BaseMultiSelect` (filter Products: tetap "Semua"). Bila tak dapat dilakukan, tandai "belum diverifikasi" di `specs/091-modifier-attach-selection-summary/evidence.md`.
- [X] T013 Buat `specs/091-modifier-attach-selection-summary/evidence.md` (hasil tes, yang belum terverifikasi) dan, hanya bila perlu, tambahkan catatan bertanggal 2026-11-06 di `CLAUDE.md` tentang temuan `allLabel`/`placeholder` di `BaseMultiSelect` (kosong menampilkan `allLabel`, bukan `placeholder`).

## Dependencies & Execution Order

- T001 → semua. US2 (T002–T004) dan US1 (T005–T008) menyentuh berkas komponen dan tes yang sama: kerjakan berurutan US2 → US1 → US3 (US2 paling kecil dan memberi pembanding bagi US1).
- Dalam tiap story: tes gagal-dulu → implementasi → hijau.
- T006 (locale) dapat dikerjakan paralel dengan T005; T008 menunggu T006 dan T007.
- T010 menunggu T008 (menyentuh markup yang sama). T011–T013 terakhir.

## Parallel Example

```text
T005 (tes US1)  ‖  T006 (locale id/en)   — berkas berbeda
```

## Implementation Strategy

- **MVP** = US2 + US1 (keduanya P1; US2 adalah perubahan 2 baris, US1 inti permintaan). Hentikan dan verifikasi di peramban setelah T008.
- Lalu US3 (P2) dan polish. Total 13 tugas: US2 = 3, US1 = 4, US3 = 2, setup 1, polish 3.
