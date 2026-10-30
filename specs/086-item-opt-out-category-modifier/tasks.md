---

description: "Task list for 086 — item melepas grup modifier warisan kategori"
---

# Tasks: Item Melepas Grup Modifier Warisan Kategori

**Klasifikasi**: INTERNAL

**Input**: `specs/086-item-opt-out-category-modifier/` — plan.md, spec.md, research.md, data-model.md, contracts/modifier-links-api.md, quickstart.md

**Tests**: Disertakan. Konstitusi II mewajibkan tes server (`tests/Feature/`) dan tes web (`qa-tests/`) untuk setiap perubahan.

**Organization**: Per user story. US1 (melepas) adalah MVP. US2 (memulihkan) dan US3 (konsistensi dan tepi) menambah di atasnya.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai)
- Perintah tes di kontainer WAJIB `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …` (flag `-e` SEBELUM nama layanan). Jangan pernah menjalankan tes tanpa flag itu: pernah menghapus DB dev. Menjalankan migrasi di DB dev (`warkop_wjm`) memerlukan persetujuan pemilik; tes memakai DB uji.
- Komentar kode dan teks UI berbahasa Indonesia (UI juga punya Inggris di `en.json`).

## Path Conventions

Server `app/`, `database/migrations/`, `tests/Feature/`; web `resources/js/`, `qa-tests/`.

---

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/086-item-opt-out-category-modifier/evidence.md` dengan bagian: Pembaca grup kategori yang ditemukan, Hasil suite, Pemeriksaan peramban (tiga peran), POS web, Perangkat; semuanya berstatus "belum dijalankan"

## Phase 2: Foundational (memblokir semua user story)

- [X] T002 Buat migrasi `database/migrations/2026_12_29_000001_create_modifier_group_product_exclusions.php` (awalan tanggal terakhir; periksa dengan `ls database/migrations | tail -3` bahwa masih yang terbaru): tabel `modifier_group_product_exclusions` dengan `product_id` FK `products` dan `modifier_group_id` FK `modifier_groups` (keduanya `cascadeOnDelete`), `created_at` nullable, kunci primer komposit; `down()` menghapus tabel; komentar pola `modifier_group_product` ("tanpa data_mode: dijangkau lewat induk")
- [X] T003 Tambahkan relasi `excludedModifierGroups(): BelongsToMany` pada `app/Models/Product.php` (tabel `modifier_group_product_exclusions`), dengan komentar bahwa ini BUKAN tautan item dan hanya dibaca `ModifierResolver::inheritedGroups`
- [X] T004 Di `app/Services/ModifierResolver.php` tambahkan `inheritedGroups(Product $product): Collection` = grup kategori dikurangi grup di `excludedModifierGroups` item itu (`loadMissing` untuk `category.modifierGroups.options` dan `excludedModifierGroups`); ubah `effectiveGroups()` agar memakai helper ini sebagai pengganti `$product->category?->modifierGroups`, dan tambahkan `excludedModifierGroups` ke daftar `load([...])` di `resolve()`. Tautan item tetap menang (`concat` item dulu, lalu `unique('id')`) dan TIDAK disaring oleh pengecualian
- [X] T005 Tambahkan eager-load `excludedModifierGroups:id` di jalur yang memakai grup kategori supaya tidak ada N+1: `app/Http/Controllers/Api/ProductController.php` (baris `with_modifiers` di daftar produk ±79 dan di `GET /variants/lookup` ±529), `app/Services/ProductCatalogQuery.php::base()` (±153), dan daftar `with([...])` di `app/Services/ProductDetailService.php` (±27)

**Checkpoint**: tabel, relasi, dan helper ada; belum ada perilaku baru yang terlihat.

---

## Phase 3: User Story 1 — Lepas grup warisan kategori dari satu item (P1) 🎯 MVP

**Goal**: pengelola melepas grup warisan dari satu item; kasir tidak lagi ditanya grup itu untuk item itu, kategori dan item lain tidak berubah.

**Independent Test**: `quickstart.md` langkah 1–5 dan 7.

### Tes (tulis dulu)

- [X] T006 [P] [US1] Buat `tests/Feature/ModifierExclusionTest.php` (pola `ModifierLinkTest.php` dan `ModifierResolverTest.php`): (a) melepas grup kategori lewat `PUT /products/{id}/modifier-links` dengan `excluded_group_ids` membuat grup itu hilang dari `effectiveGroups`, dari `GET /products?with_modifiers=1`, dan dari `GET /variants/lookup?with_modifiers=1` untuk item itu; (b) item lain di kategori yang sama tetap membawa grup itu; (c) kategori tetap memegang grup (`modifier_group_category` tidak berubah); (d) tautan item ke grup yang sama TETAP berlaku walau grup itu dikecualikan; (e) `POST /tabs/{id}/items` dengan opsi dari grup yang dikecualikan ditolak 422 (`restaurant.modifier_option_invalid`) dan item lain di kategori yang sama menerimanya; (f) pengguna tanpa izin `ProductPolicy::update` mendapat 403 dan tidak ada baris pengecualian; (g) tanpa kunci `excluded_group_ids` pengecualian yang sudah ada TIDAK berubah; (h) id grup tak dikenal dan id ganda ditolak 422 tanpa mengubah apa pun; (i) satu entri log `modifier_links_updated` ditulis dalam transaksi yang sama, memuat nilai lama/baru pengecualian dan tanpa isi sensitif; (j) tab yang sudah berisi modifier dari grup itu tidak berubah

### Implementasi server

- [X] T007 [US1] Tambahkan aturan `excluded_group_ids` (`sometimes`, `array`, `max:30`) dan `excluded_group_ids.*` (`integer`) ke `app/Http/Requests/ModifierLinksRequest.php`
- [X] T008 [US1] Perluas `app/Services/ModifierLinkService.php`: `replace()` menerima `?array $excluded = null`; `validate()` memeriksa tiap id ada di `modifier_groups` dan tidak ganda (422 `excluded_group_ids.N`); `applyLinks()` menulis pengecualian lewat `$product->excludedModifierGroups()->sync($ids)` HANYA bila `$excluded !== null`; payload `menu_change` membawa `excluded_group_ids` hanya bila dikirim; log `modifier_links_updated` memuat nilai lama/baru pengecualian bila dikirim; semua di transaksi yang sama. `app/Http/Controllers/Api/ModifierLinkController.php` meneruskan `$request->validated('excluded_group_ids')` (null bila tak ada)
- [X] T009 [US1] Ubah `app/Services/Release/ModifierLinkApplier.php` agar meneruskan `$change->payload['excluded_group_ids'] ?? null` ke `applyLinks()` (null = tak berubah)
- [X] T010 [US1] Ubah `app/Services/ProductDetailService.php::links()` agar baris `source: category` berasal dari `ModifierResolver::inheritedGroups()` (grup yang dikecualikan tidak ada), dan tambahkan kunci `modifier_excluded` di respons detail: `[{group_id, name, options[]}]` untuk grup yang masih diwarisi kategori tetapi dikecualikan item ini (pengecualian yang grupnya sudah tidak ada di kategori tidak ditampilkan dan tidak menimbulkan galat)
- [X] T011 [US1] Ubah `app/Services/ProductCatalogQuery.php` (±297): nama grup di daftar Produk memakai `inheritedGroups()` sebagai pengganti `category?->modifierGroups`, dan tautan item tetap digabung dengan `unique('id')`
- [X] T012 [US1] Perbarui `docs/openapi-pos-mvp.yaml` di commit yang sama: `PUT /products/{id}/modifier-links` (field `excluded_group_ids`, semantik "tanpa kunci = tidak berubah", 422/403) dan respons detail `GET /products/{id}` (`modifier_excluded`, serta bahwa `modifier_links` memuat grup efektif)

### Implementasi web

- [X] T013 [P] [US1] Ubah `saveModifierLinks(id, links, excludedGroupIds)` di `resources/js/api/products.js`: kunci `excluded_group_ids` HANYA dikirim bila argumen ketiga bukan `undefined` (klien yang tak mengubahnya tidak boleh menghapus pengecualian)
- [X] T014 [P] [US1] Buat `resources/js/components/product/InheritedModifierList.vue`: props `inherited` (baris `source: category`), `editable` (boolean); menampilkan nama grup + `modifierRuleText` seperti sekarang; bila `editable`, tiap baris punya tombol "Lepas" (emit `exclude` dengan `group_id`); bila tidak `editable`, TIDAK ADA tombol sama sekali (Konstitusi III). Gunakan kelas token desain, tanpa heksadesimal; `data-testid` stabil
- [X] T015 [US1] Pasang komponen di `resources/js/components/product/ModifierLinksDrawer.vue`: ganti blok `inherited` (±baris 292) dengan `InheritedModifierList`, simpan `excludedIds` (ref, dari prop `excluded` ↔ `group_id`), "Lepas" memindahkan baris dari daftar warisan ke `excludedIds`, `submit()` mengirim `saveModifierLinks(id, payload, [...excludedIds])`; deteksi perubahan memperhitungkan `excludedIds`
- [X] T016 [US1] Pasang komponen di `resources/js/views/products/ProductFormDrawer.vue`: ganti blok `inherited-links` (±662) dengan `InheritedModifierList`, tambah `excludedIds` + baseline ke deteksi perubahan formulir (`modifierBaseline`) dan kirim lewat `saveModifierLinks` di jalur simpan modifier; baris `blankLinkRow` kosong dan tombol silangnya TIDAK diubah
- [X] T017 [US1] Di `resources/js/views/ProductDetailView.vue` (dan induk lain yang membuka `ModifierLinksDrawer`; cari lewat `grep -rn "ModifierLinksDrawer" resources/js`) teruskan `modifier_excluded` dari detail sebagai prop `excluded`
- [X] T018 [P] [US1] Tambahkan teks baru ke `resources/js/locales/id.json` dan `en.json` di kelompok `products` (mis. `ml_exclude` "Lepas", `ml_exclude_hint`); pertahankan `ml_from_category` yang ada
- [X] T019 [P] [US1] Buat `qa-tests/component/InheritedModifierList.test.js` (tombol Lepas muncul hanya bila `editable`, mengemit `exclude` dengan id yang benar, tidak ada tombol sama sekali bila tidak `editable`) dan perbarui `qa-tests/component/ModifierLinksDrawer.test.js`: melepas lalu simpan memanggil `saveModifierLinks` dengan `excluded_group_ids`, dan tanpa perubahan kunci itu TIDAK dikirim

**Checkpoint**: grup warisan bisa dilepas dari satu item di server dan di kedua permukaan; efektif di POS web dan tablet (tablet membaca dari server).

---

## Phase 4: User Story 2 — Pulihkan grup yang pernah dilepas (P2)

**Goal**: grup yang dilepas tetap terlihat di "Dilepas untuk item ini" dan bisa dipulihkan.

**Independent Test**: `quickstart.md` langkah 3 dan 6.

- [X] T020 [P] [US2] Tambahkan ke `tests/Feature/ModifierExclusionTest.php`: (a) `GET /products/{id}` memuat grup yang dikecualikan di `modifier_excluded` dan tidak di `modifier_links`; (b) `PUT` dengan `excluded_group_ids: []` memulihkan semuanya dan grup kembali efektif; (c) `PUT` dengan subset memulihkan hanya yang tidak disebut; (d) grup yang dipulihkan kembali dipertanyakan di POS (`with_modifiers`)
- [X] T021 [US2] Perluas `InheritedModifierList.vue`: prop `excluded` (`[{group_id, name, options}]`); bagian terpisah berjudul "Dilepas untuk item ini" dengan tombol "Pulihkan" (emit `restore`) hanya bila `editable`; bagian itu tidak tampil bila daftar kosong
- [X] T022 [US2] Di `ModifierLinksDrawer.vue` dan `ProductFormDrawer.vue`: tampilkan daftar `excluded` lewat komponen yang sama, "Pulihkan" memindahkan baris kembali ke daftar warisan dan mengeluarkan id dari `excludedIds`; keduanya memakai satu fungsi pembantu bersama untuk memindah baris (tidak menyalin logika dua kali), mis. di `resources/js/utils/modifierExclusion.js`
- [X] T023 [P] [US2] Tambahkan ke `qa-tests/component/InheritedModifierList.test.js` dan `ModifierLinksDrawer.test.js`: bagian "Dilepas untuk item ini" tampil dengan Pulihkan, Pulihkan mengembalikan baris ke daftar warisan dan mengosongkan bagian itu, simpan setelah memulihkan mengirim `excluded_group_ids: []`; tes kecil untuk `modifierExclusion.js`

**Checkpoint**: lepas dan pulihkan bekerja bolak-balik tanpa kehilangan jejak.

---

## Phase 5: User Story 3 — Konsisten di kedua tempat dan tahan perubahan kategori (P3)

**Goal**: laci dan formulir berperilaku sama; perubahan kategori tidak merusak pengecualian.

**Independent Test**: `quickstart.md` langkah 3 (formulir) dan 8.

- [X] T024 [P] [US3] Tambahkan ke `tests/Feature/ModifierExclusionTest.php`: (a) grup baru yang ditambahkan ke kategori kelak tetap berlaku untuk item yang melepas grup lain; (b) item dipindah ke kategori lain tetap melepas grupnya sampai dipulihkan dan kategori baru menambahkan grupnya sendiri; (c) grup yang dikecualikan lalu dinonaktifkan atau dihapus (`ModifierGroupService::delete` bila tak terpakai) tidak menimbulkan galat dan tidak menahan penghapusan; (d) kategori kehilangan grup yang dikecualikan: tidak ada galat, dan bila ditautkan lagi item tetap melepasnya; (e) rilis menu AKTIF: pelepasan diantrekan sebagai `menu_change` `modifier_link` dengan `excluded_group_ids` di payload, belum berlaku, lalu berlaku saat rilis terbit lewat `ModifierLinkApplier`
- [X] T025 [P] [US3] Buat `qa-tests/component/ModifierExclusionSurfaces.test.js`: dengan data detail yang sama, `ModifierLinksDrawer` dan `ProductFormDrawer` menampilkan daftar warisan dan "Dilepas untuk item ini" yang sama, dan melepas di salah satu mengirim `excluded_group_ids` yang sama (FR-006)

**Checkpoint**: semua Acceptance Scenario dan Edge Case spec tertutup tes.

---

## Phase 6: Polish & Verifikasi

- [X] T026 Telusuri pembaca grup kategori lain yang mungkin terlewat: `grep -rn "category.modifierGroups\|category->modifierGroups\|modifier_group_category\|modifierGroups" app resources/js android-pos/app/src/main` serta ekspor Excel (`app/Support/MasterDataSheets.php`, `app/Services/MasterDataImportService.php`) dan layar Setup restoran; catat setiap pembaca di `evidence.md` dengan keputusan (memakai helper / tak relevan). Bila ada pembaca yang menampilkan grup efektif, pindahkan ke `inheritedGroups()`
- [ ] T027 Jalankan suite penuh dengan flag `-e` yang benar: backend (`php artisan test`), `npm test`, `npm run build`; bila suite Android tidak tersentuh, tidak perlu. Catat jumlah dan setiap kegagalan apa adanya di `evidence.md`; kegagalan yang sudah ada sebelumnya hanya disebut "sudah ada" bila dibuktikan (bandingkan dengan cabang dasar atau tunjukkan bahwa tak ada berkas server yang disentuh)
- [ ] T028 Verifikasi PERANGKAT LUNAK nyata di peramban (skill `browser-verify`; bila alat peramban gagal tersambung, tulis itu di `evidence.md` dan JANGAN klaim selesai): `quickstart.md` langkah 2–8 pada halaman Produk (laci dan formulir) dengan tiga peran (owner, peran dengan izin produk, peran tanpa izin), serta POS web: item yang melepas "Suhu" tidak menanyakannya, item lain tetap; periksa konsol tanpa galat. Migrasi hanya di DB uji; untuk DB dev minta persetujuan pemilik terlebih dahulu
- [ ] T029 Verifikasi tablet (butuh perangkat; tidak bisa dikerjakan agen): item yang melepas "Suhu" tidak menanyakannya di Android, item lain tetap; catat di `evidence.md` atau tandai "belum diverifikasi di perangkat"
- [X] T030 Perbarui `CLAUDE.md`: tambahkan butir ringkas bertanggal 086 (tabel `modifier_group_product_exclusions`, helper `ModifierResolver::inheritedGroups` satu-satunya pembaca grup kategori, `excluded_group_ids` opsional/tanpa kunci = tak berubah) pada daftar aturan domain; jangan menulis ulang butir lama
- [ ] T031 Tinjau diff akhir terhadap spec: setiap FR-001–FR-013 punya tes atau bukti di `evidence.md`; hapus berkas sementara; pastikan `evidence.md` tidak memuat kredensial atau PII

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 → T003 → T004 → T005, berurutan: T004 dan T005 bergantung pada T003; T003 pada T002).
- US1 setelah Phase 2. Dalam US1: T006 (tes) boleh ditulis paralel dengan T007–T011; T007 → T008 → T009; T010 dan T011 bergantung pada T004; T012 sesudah T008 dan T010; T013, T014, T018 paralel; T015 dan T016 bergantung pada T013 dan T014; T017 sesudah T015; T019 sesudah T014–T016.
- US2 sesudah US1 (memakai komponen, prop, dan `excludedIds` yang sama): T020 paralel dengan T021; T022 sesudah T021; T023 sesudah T021–T022.
- US3 sesudah US1 (T024 dan T025 paralel; T025 juga membutuhkan US2 bila menguji Dilepas…).
- Polish sesudah story yang dikirim selesai; T028 dan T029 butuh peramban/perangkat.

### Parallel opportunities

- Fondasi: T002–T005 berurutan (dependensi).
- US1: T006, T013, T014, T018 bersamaan setelah Phase 2; T010 dan T011 bersamaan.
- US2: T020 dan T021 bersamaan.
- US3: T024 dan T025 bersamaan.

## Implementation Strategy

1. **MVP = Phase 1 + 2 + US1**: item bisa melepas grup warisan, efektif di server, POS web, dan tablet. Boleh dikirim tanpa US2, tetapi tanpa Pulihkan pengecualian hanya bisa dibatalkan lewat API; karena itu US2 sebaiknya masuk kiriman yang sama.
2. Kirim US1 + US2 bersama; US3 sebagai tes penguat (tidak menambah kode produksi kecuali tes menemukan celah).
3. Jangan menambah rute baru, jangan mengubah `modifier_group_product`, jangan mengubah Android kecuali T026 menemukan pembaca baru.
4. Jangan klaim "selesai" untuk layar sebelum T028; tablet tetap "belum diverifikasi" sebelum T029.

## Ringkasan

- Total tugas: 31 (Setup 1, Foundational 4, US1 14, US2 4, US3 2, Polish 6)
- Tugas yang butuh peramban/perangkat/orang: T028, T029 (dan persetujuan pemilik untuk migrasi di DB dev)
