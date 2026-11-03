# Tasks: Resep Memotong Stok, Resep Ganda per Varian, dan Jumlah Pilihan Modifier

**Klasifikasi**: INTERNAL

**Input**: `specs/089-recipe-multi-modifier-qty/` — plan.md, spec.md, research.md (D1–D12), data-model.md, contracts/api-changes.md, quickstart.md

**Tes**: DIWAJIBKAN oleh Konstitusi II (backend `tests/Feature/`, web `qa-tests/`, Android `src/test`). Tes ditulis lebih dulu per cerita dan harus gagal sebelum implementasi.

**Format**: `- [ ] T### [P?] [US?] Deskripsi dengan jalur file`. `[P]` = bisa paralel (file berbeda, tanpa ketergantungan pada tugas yang belum selesai).

**Aturan lingkungan (CLAUDE.md, wajib)**:
- Tes backend di kontainer HANYA dengan: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test … -e CACHE_STORE=array -e SESSION_DRIVER=array`.
- SATU proses tes pada satu waktu (jangan jalankan tes terfilter saat suite penuh berjalan).
- **Jangan menjalankan migrasi ke DB dev** tanpa persetujuan pengembang (T063).
- Teks UI, komentar, dan pesan commit berbahasa Indonesia; komentar menjelaskan MENGAPA.

## Phase 1: Setup

- [X] T001 Buat kerangka `specs/089-recipe-multi-modifier-qty/evidence.md` (bagian: tes otomatis, verifikasi peramban, verifikasi tablet, hal yang belum terverifikasi, risiko APK lama) dengan label INTERNAL.
- [X] T002 Jalankan baseline tes terkait di kontainer (`--filter=Recipe`, `--filter=Modifier`, `--filter=MaterialReport`) dan catat hasil awal (lulus/gagal sebelum perubahan, termasuk kegagalan lama yang sudah diketahui) di `evidence.md`.

---

## Phase 2: Foundational (memblokir SEMUA cerita)

**Tujuan**: skema dan pembaca "resep aktif" tanpa versi, serta kolom jumlah pilihan, tersedia untuk semua cerita.

- [X] T003 Buat migrasi `database/migrations/2026_12_31_000001_make_recipes_unversioned.php` sesuai research D4/data-model: dalam satu transaksi (1) hitung versi aktif lama tiap varian (aturan `activeFor` lama), (2) hapus baris `recipe_variants` yang bukan versi aktif varian itu dan catat tiap pasangan ke `activity_logs` aksi `recipe_migrated`, (3) isi `archived_at` untuk resep tanpa tautan varian tersisa yang bukan resep masa depan, (4) tambah `name` (isi "Resep", arsip diberi sufiks " vN"), (5) hapus unique `uniq_recipes_product_version` dan kolom `version`. `down()` satu arah dengan komentar alasan. Jangan ubah awalan tanggal migrasi lama.
- [X] T004 [P] Buat migrasi `database/migrations/2026_12_31_000002_add_qty_limits_to_modifier_options.php`: `min_qty` tinyint unsigned default 1, `max_qty` tinyint unsigned null default 1, CHECK `min_qty >= 1` dan `max_qty IS NULL OR max_qty >= min_qty` (pola `DB::statement` seperti `2026_12_30_000001_create_modifier_option_materials.php`).
- [X] T005 [P] Tulis `tests/Feature/RecipeUnversionMigrationTest.php`: data berversi (varian A: v1 lalu v2; varian B hanya tertaut ke v1; satu resep masa depan) dimigrasikan → v2 aktif untuk A, v1 tetap aktif untuk B, tautan A→v1 dilepas dan tercatat `recipe_migrated`, resep tanpa tautan berstatus arsip, resep masa depan tetap tertaut. Gunakan data fiktif saja.
- [X] T006 [P] Ubah `app/Models/Recipe.php`: hapus `version` dari `$fillable`/`casts`, tambah `name`, `archived_at` (cast datetime), scope `active()` (tidak arsip, `effective_from <= BusinessDay::today()`); perbarui docblock (bukan lagi "versi"). Perbarui `Product::recipes()` di `app/Models/Product.php` (hapus `orderByDesc('version')`, urutkan `name`).
- [X] T007 [P] Ubah `app/Models/ModifierOption.php`: tambah `min_qty`, `max_qty` ke `$fillable` dan `casts` (int, `max_qty` nullable).
- [X] T008 Cari SEMUA pembaca `version` resep (`grep -rn "version" app/Services/RecipeService.php app/Services/ProductDetailService.php app/Http resources/js/components/product resources/js/utils/recipeCost.js lang`) dan daftarkan temuannya sebagai catatan di `evidence.md`; tiap pembaca diperbaiki oleh tugas cerita yang memilikinya (US5/US2). Verifikasi juga bahwa tidak ada kata "recipe" di `android-pos/app/src/main` (asumsi kontrak: tablet tidak memuat resep).
- [X] T009 Di `app/Services/RecipeService.php` ganti `activeFor()` menjadi `activeRecipesFor(ProductVariant): Collection<Recipe>` (semua resep tertaut, tidak arsip, `effective_from <= BusinessDay::today()`) dan tambah `activeRecipesForVariants(array $variantIds): array<int, Collection<Recipe>>` yang memuat SEMUA sekaligus dengan satu kueri (+ `lines.material`) untuk menghindari N+1. `isRecipeDriven()` = `activeRecipesFor(...)->isNotEmpty()`. Ganti `Carbon::today()` → `BusinessDay::today()`. Jangan hapus pemanggil dulu; perbaiki pemanggil `activeFor` di `ProductDetailService.php:140` dan `RecipeService::recalculate/applyDue/versions` pada tugas US5 (T019–T022).

**Checkpoint**: skema + pembaca resep aktif siap; `T005` lulus.

---

## Phase 3: User Story 1 — Menjual varian ber-resep memotong stok bahan (P1) 🎯 MVP

**Tujuan**: pesanan selesai memotong bahan resep semua resep aktif varian; void/refund/buang konsisten.

**Tes independen**: varian dengan resep "cabai 2 gr/porsi", stok cabai 100, jual 3 porsi lalu selesaikan → stok 94, pergerakan `sale` menaut ke baris pesanan; void → kembali 100.

### Tes (tulis dulu, harus gagal)

- [X] T010 [P] [US1] Tulis `tests/Feature/RecipeMaterialConsumptionTest.php` (pola `ModifierMaterialConsumptionTest.php`): potong saat selesai (`OrderService`, tab `settle`, `split`) = `qty ÷ (1 − susut) ÷ Portions × qty baris`; Portions 4 memotong seperempat; susut 20%; DUA resep aktif dijumlahkan (cabai 2+1 = 3 gr × 3 porsi = 9); bahan sama dari resep dan pilihan modifier digabung per baris menjadi satu pergerakan; resep masa depan tidak memotong; resep arsip tidak memotong; varian tanpa resep tak berubah; selama tab terbuka stok tidak berubah; void/refund mengembalikan dari buku besar walau resep diubah setelah jual; batal "buang" (`stock_decision=waste`) mencatat `waste`; stok minus diizinkan dengan `material_warnings` yang menyebut bahan dan outlet; panggilan kedua `consumeOrder` tidak memotong ulang; bahan nonaktif dilewati; `order_items.cost_price` TIDAK berubah oleh potongan resep (tanpa biaya ganda, D9).
- [X] T011 [P] [US1] Tambah tes jumlah kueri di `tests/Feature/RecipeMaterialConsumptionTest.php` (atau berkas kembar `RecipeMaterialConsumptionQueryCountTest.php`): menyelesaikan pesanan 10 baris varian berbeda ber-resep tidak menambah kueri per baris (batasi dengan `DB::getQueryLog()`), Konstitusi V.
- [X] T012 [P] [US1] Perbarui tes Laporan Bahan yang menyebut `usage_untracked` (cari dengan `grep -rln "usage_untracked" tests/`): bahan di resep AKTIF tidak lagi tak tercatat; bahan yang hanya di BOM tetap `usage_untracked`.

### Implementasi

- [X] T013 [US1] Perluas `app/Services/ModifierMaterialConsumption.php::needFor()`: setelah kebutuhan pilihan modifier, ambil resep aktif semua `variant_id` baris lewat `RecipeService::activeRecipesForVariants()` (satu kueri), hitung `round(quantity / (1 − shrinkage_pct/100) / yield × item.qty, 3)` per bahan aktif, gabungkan ke `$need[materialId][itemId]` (bahan nonaktif/terhapus dilewati; `ksort` + saring ≤ 0 seperti sekarang). Jangan menulis biaya baris untuk resep (D9). Perbarui docblock kelas: kini membaca pilihan modifier DAN resep, nama kelas dipertahankan (alasan: tes dan riwayat 087). Pastikan `$materials` memuat bahan dari resep juga.
- [X] T014 [US1] Pastikan `needFor()` mengembalikan `$costPerPortion` hanya untuk bahan modifier (resep sudah di `cost_price`) agar `addMaterialCost` tidak menghitung dua kali; tambahkan komentar `MENGAPA` yang merujuk research D9.
- [X] T015 [US1] Ubah `app/Services/MaterialReport.php` (`untrackedMaterials`, baris ±451–464): hapus `recipe_lines` dari himpunan "tak tercatat" (resep kini memotong), pertahankan BOM; perbarui komentar dan padanan teks UI "pemakaian tidak tercatat" di `resources/js/locales/{en,id}.json` bila menyebut resep.
- [X] T016 [US1] Jalankan `T010`–`T012` di kontainer dan pastikan lulus; catat di `evidence.md`.

**Checkpoint**: US1 berfungsi untuk satu atau banyak resep (resep dibuat lewat factory/seeder tes; UI belum perlu).

---

## Phase 4: User Story 5 — Resep ganda untuk satu varian, tanpa versi (P1)

**Tujuan**: pemilik membuat/ubah/hapus resep satuan; satu varian boleh banyak resep; biaya varian = jumlah.

**Tes independen**: beri satu varian dua resep; daftar menampilkan keduanya; ubah satu di tempat; hapus satu; `cost_price` varian = jumlah biaya porsi resep aktif.

### Tes (tulis dulu)

- [X] T017 [P] [US5] Perbarui/tulis `tests/Feature/RecipeApiTest.php`: `POST` membuat resep tanpa `version` dan TIDAK menimpa resep lain; dua resep untuk satu varian; `PUT /recipes/{id}` mengubah di tempat + log `recipe_updated`; `DELETE /recipes/{id}` satu resep + log `recipe_removed`; `DELETE /products/{id}/recipes` tetap menghapus semua; resep arsip → 409 `recipe_archived` saat diubah; varian ber-BOM lengkap → 409 `cost_source_conflict`; `name` wajib; `cost_price` varian = Σ biaya porsi resep aktif; salah satu resep tak lengkap → `cost_price` tidak ditulis dan `recalculate` melaporkan `incomplete`; peran tanpa `menu.recipe.edit` → 403 untuk POST/PUT/DELETE; respons tidak memuat `version`.

### Implementasi backend

- [X] T018 [P] [US5] Ubah `app/Http/Requests/StoreRecipeRequest.php` (tambah `name` wajib ≤ 100) dan buat `app/Http/Requests/UpdateRecipeRequest.php` (aturan sama, gerbang menu `products` + `menu.recipe.edit`).
- [X] T019 [US5] Ubah `RecipeService::create()` di `app/Services/RecipeService.php`: tanpa nomor versi, tanpa pembatalan resep lain, `name` disimpan; tambah `update(Recipe, array, User)` (409 `recipe_archived` bila arsip; sinkron `variants` dan `lines` penuh; log `recipe_updated` dengan nilai lama/baru dalam transaksi yang sama; cek konflik BOM sama dengan create) dan `removeOne(Recipe, User)` (isi disimpan di log `recipe_removed`). Pertahankan `remove(Product)` (hapus semua; ganti kata "versi" di log/pesan).
- [X] T020 [US5] Ubah sinkron biaya di `RecipeService`: `variantCost(ProductVariant)` = Σ `cost()['portion_cost']` semua resep aktif (null/incomplete bila salah satu tak lengkap); `writeCost`, `applyDue`, `recalculate` dihitung per VARIAN memakai `variantCost` dan tanggal berlaku yang baru tiba; pesan log `cost_price_synced` menyebut jumlah resep (bukan "vN"). Setelah `create/update/removeOne` hitung ulang varian terdampak (varian yang kehilangan resep aktif terakhir berhenti digerakkan resep, harga modal terakhir dibiarkan).
- [X] T021 [US5] Ubah `versions()` → `list()` dan `present()` di `RecipeService`: hapus `version`, tambah `name`, `archived`, `is_active_now`; urutkan `name`. Perbarui `app/Http/Controllers/Api/RecipeController.php` (`index` memanggil `list`, tambah `update` dan `destroyOne`; `destroy` lama mengembalikan `removed` plus alias `removed_versions`) dan daftarkan rute `PUT /recipes/{recipe}` + `DELETE /recipes/{recipe}` di `routes/api.php` (dekat baris 175–179, middleware dan izin sama).
- [X] T022 [US5] Ubah `app/Services/ProductDetailService.php::recipeSummary()` (±baris 130–150): kembalikan ringkasan SEMUA resep aktif (`recipes[]` tanpa `lines`, `recipe_count`) dan pertahankan kunci `recipe` tunggal = resep pertama untuk klien lama (kontrak). Hindari N+1 (muat resep semua varian sekali).
- [X] T023 [P] [US5] Tambah/ubah string galat di `lang/id/products.php` dan `lang/en/products.php`: `recipe_name_required`, `recipe_archived`, pesan `recipe_removed`, ganti kata "versi"; pastikan kedua bahasa sinkron.
- [X] T024 [P] [US5] Perbarui `docs/openapi-pos-mvp.yaml` untuk semua perubahan rute/respons resep di `contracts/api-changes.md` (bagian Resep dan Detail produk) di commit yang sama dengan T019–T022.

### Implementasi web

- [X] T025 [P] [US5] Perbarui modul API resep di `resources/js/api/` (cari dengan `grep -rln "recipes" resources/js/api`): tambah `updateRecipe(id, body)` dan `deleteRecipe(id)`, `createRecipe` memakai `name`; hapus pemakaian `version`.
- [X] T026 [US5] Ubah `resources/js/components/product/RecipeSection.vue`: tampilkan DAFTAR resep (nama, Portions, varian terkait, biaya porsi bagi pemegang izin biaya, penanda arsip baca-saja) dengan tombol Tambah/Ubah/Hapus (hanya untuk peran ber-izin; tersembunyi untuk yang lain, Konstitusi III); hapus tampilan riwayat versi; konfirmasi hapus lewat dialog yang ada.
- [X] T027 [US5] Ubah `resources/js/components/product/RecipeDrawer.vue`: satu resep per laci (buat/ubah), isian Nama, daftar varian boleh dipilih walau sudah tertaut resep lain, tanpa konsep "versi baru"; simpan memanggil `createRecipe` atau `updateRecipe`; kesalahan 422/409 lewat penangan pusat.
- [X] T028 [P] [US5] Perbarui `qa-tests/component/RecipeSection.test.js` dan `qa-tests/component/RecipeDrawer.test.js` (API di-`vi.mock`): daftar dua resep, ubah, hapus, tombol tersembunyi tanpa izin, tidak ada teks "versi".

**Checkpoint**: US1 + US5 menjadi MVP penuh (resep ganda memotong stok).

---

## Phase 5: User Story 2 — Label "Portions" di layar Resep (P2)

**Tujuan**: "Yield" → "Portions"/"Porsi", tanpa teks satuan di sebelah isian, banner baru.

**Tes independen**: buka Resep → label Portions, tidak ada "portions" di samping isian, tidak ada kata "Yield" tersisa.

- [X] T029 [P] [US2] Tulis `qa-tests/unit/recipeLabels.test.js`: memuat `resources/js/locales/en.json` dan `id.json` dan memastikan `rc_yield` = "Portions" / "Porsi", `rc_err_yield` tidak memuat "yield"/"Yield", tidak ada nilai `rc_*` berisi "Yield", dan banner (`rc_banner*` — cari kuncinya) tidak lagi menyebut "NEW VERSION" atau "does not deduct".
- [X] T030 [US2] Ubah `resources/js/locales/en.json` dan `id.json` (±baris 2223–2258): `rc_yield` → "Portions"/"Porsi", hapus `rc_yield_unit` dari penggunaan (hapus kuncinya bila tidak dipakai di tempat lain — cek dengan grep), ganti `rc_err_yield`, dan ganti teks banner menjadi penjelasan "stok bahan dipotong saat pesanan selesai". Ubah juga `recipe_yield_invalid` di `lang/{en,id}/products.php`.
- [X] T031 [US2] Ubah `resources/js/components/product/RecipeDrawer.vue`: hapus elemen satuan di sebelah isian Portions dan ganti label; perbarui `qa-tests/component/RecipeDrawer.test.js` (T028) untuk menegaskan label dan ketiadaan satuan. (Berkas sama dengan T027 — kerjakan setelahnya.)

**Checkpoint**: SC-006 terpenuhi di web.

---

## Phase 6: User Story 3 — Satu bahan di banyak pilihan modifier (P2)

**Tujuan**: perbaiki aturan `distinct` lintas pilihan; kembar hanya ditolak di dalam satu pilihan.

**Tes independen**: grup "Level Pedas" pilihan 1/2/4 masing-masing memakai Cabe → tersimpan; Cabe dua kali di satu pilihan → galat hanya pada pilihan itu.

- [X] T032 [P] [US3] Perluas `tests/Feature/ModifierOptionMaterialMappingTest.php`: bahan sama di 3 pilihan → 201/200 dan ketiga pemetaan tersimpan; kembar dalam satu pilihan → 422 dengan kunci `options.{i}.materials.{j}.material_id` (indeks pilihan yang benar, bukan pilihan lain); jumlah berbeda per pilihan; memesan satu pilihan hanya memotong jumlah miliknya (pakai fixture tes `ModifierMaterialConsumptionTest`).
- [X] T033 [US3] Ubah `app/Http/Requests/StoreModifierGroupRequest.php` (dan permintaan ubah grup bila terpisah — cek `grep -rn "options.\*.materials" app/Http/Requests`): hapus `distinct` dari `options.*.materials.*.material_id`, tambah `withValidator()` yang memeriksa kembar PER pilihan dan menambahkan galat ke kunci `options.{i}.materials.{j}.material_id` dengan pesan `option_material_duplicate`; hapus entri `distinct` dari `messages()`. Tambahkan komentar `BUG YANG DITEMUKAN & DIPERBAIKI` (wildcard ganda membuat `distinct` membandingkan lintas pilihan; lihat research D7).
- [X] T034 [US3] Verifikasi `app/Services/ModifierGroupService.php::syncOptions` tidak memiliki pemeriksaan kembar lintas pilihan lain (baca fungsinya); bila ada, perbaiki per pilihan. Catat hasil di `evidence.md`.
- [X] T035 [P] [US3] Perbarui pesan di `lang/{en,id}/vendors_materials.php` (`option_material_duplicate`) agar menyebut pilihan yang bermasalah (`:option`) bila memungkinkan; sinkron kedua bahasa.
- [X] T036 [US3] Ubah `resources/js/components/restaurant/ModifierGroupsAdmin.vue`: tandai bahan kembar per pilihan sebelum kirim, tampilkan galat server pada pilihan yang tepat (bukan semua pilihan); perluas `qa-tests/component/ModifierGroupsAdmin.test.js` (tiga pilihan memakai Cabe tanpa galat; kembar dalam satu pilihan menampilkan galat hanya di pilihan itu).

**Checkpoint**: SC-002 terpenuhi.

---

## Phase 7: User Story 4 — Jumlah pilihan modifier dengan min/maks (P2)

**Tujuan**: pilihan punya `min_qty`/`max_qty`; kasir (web + tablet) memesan jumlah; harga, bahan, struk, KOT ikut jumlah.

**Tes independen**: pilihan "Telur" min 1 maks 3, pakai 1 butir; pesan ×2 → harga naik 2 × selisih, stok −2; ×4 → 422; klien lama tetap jalan.

### Tes backend (tulis dulu)

- [X] T037 [P] [US4] Tulis `tests/Feature/ModifierQuantityTest.php`: `modifier_selections` dalam batas diterima; qty di luar `[min_qty,max_qty]` → 422 pada `modifier_selections` yang menyebut grup/batas; `max_qty` null = tanpa batas atas (≤ 50); `min_qty` > `max_qty` ditolak saat simpan grup; id kembar → 422; mengirim `modifier_selections` DAN `modifier_option_ids` → 422; `modifier_option_ids` saja = qty 1 (kompatibel); harga baris `unit_price = dasar + Σ price_delta` dengan `price_delta = delta × qty`; grup `portion_per_option` per SATUAN (SOTO ×2 + AYAM ×1 → dua harga dasar ekstra: pilihan pertama membawa `qty−1`, berikutnya `qty`); snapshot memuat `qty`; baris lama tanpa `qty` dibaca 1; `PUT /tabs/{tab}/lines/{line}` memakai dasar yang benar (Σ price_delta lama dikurangi) dengan qty; grup `max_select`/`min_select` tetap menghitung pilihan berbeda; stok tidak berubah bila `qty` diubah selama tab terbuka.
- [X] T038 [P] [US4] Tulis `tests/Feature/ModifierMaterialQuantityTest.php`: bahan pilihan dipotong `jumlah × qty pilihan × qty baris`; void/refund mengembalikan persis yang terpotong; batal "buang" mencatat `waste` sebesar yang sama; bahan sama dari resep + pilihan ×qty digabung.
- [X] T039 [P] [US4] Perluas tes tampilan di `tests/Feature/OrderModifierTest.php` dan `tests/Feature/TabModifiersTest.php`: respons baris, struk, tiket dapur, dan daftar transaksi memuat `qty` dan teks "Telur ×2" bila qty > 1; ekspor transaksi memuat jumlah.

### Backend

- [X] T040 [US4] Ubah `app/Http/Requests/StoreOrderRequest.php`, `AddTabLineRequest.php`, `UpdateTabLineRequest.php`: tambah `modifier_selections` (`array|max:50`, `.*.option_id` integer, `.*.qty` integer 1..50) dan larangan dikirim bersama `modifier_option_ids` (422 di `modifier_selections`).
- [X] T041 [US4] Ubah `app/Services/ModifierResolver.php`: normalizer SATU pintu (`normalizeSelections(array $optionIds, ?array $selections): array<int,int>` id→qty, tolak kembar) dipakai `resolve()`; validasi tiap qty terhadap `min_qty/max_qty` opsi; kontribusi `price_delta × qty`; porsi per SATUAN untuk `portion_per_option` (research D5: pilihan pertama membawa `qty−1` harga dasar ekstra, berikutnya `qty`); snapshot menambah `qty`; `present()` menambah `min_qty`/`max_qty` per opsi; pesan galat baru di `lang/{id,en}/restaurant.php` (`modifier_qty_violated` memuat grup, opsi, batas).
- [X] T042 [US4] Ubah pemanggil `resolve()`: `app/Services/OrderService.php` (±baris 104), `app/Services/TabService.php` (±baris 167 dan 237) agar meneruskan selections/ids lewat normalizer; verifikasi `TabService::updateLine` menghitung dasar `sell_price − Σ price_delta lama` tetap benar (snapshot menyimpan kontribusi total).
- [X] T043 [US4] Ubah `ModifierMaterialConsumption::needFor()` (`app/Services/ModifierMaterialConsumption.php`): jumlah per pilihan dibaca dari `qty` snapshot (default 1) dan dikalikan ke kebutuhan bahan pilihan dan `costPerPortion`; hapus `->unique()` yang menyatukan pilihan kembar menjadi satu. (Sesudah T013 — berkas sama.)
- [X] T044 [P] [US4] Ubah `app/Support/ModifierSnapshot.php::forDisplay()` agar menyertakan `qty` dan `label` "Nama ×N" bila N > 1; pastikan `TicketResource` (`app/Http/Resources/TicketResource.php`), `KitchenService` (±baris 80), `TabSettlementService` (±baris 225), resource struk/transaksi, dan ekspor transaksi (`SalesTransactionsService`/kolom modifier) mengikuti satu bentuk itu.
- [X] T045 [US4] Ubah penyimpanan grup: `StoreModifierGroupRequest.php` (`options.*.min_qty` int 1..50, `options.*.max_qty` nullable int ≥ min_qty ≤ 50), `ModifierGroupService::syncOptions` (simpan dan catat perubahan `min_qty/max_qty` ke log aktivitas dalam transaksi yang sama, aksi `modifier_qty_changed`), resource grup yang menyajikan opsi.
- [X] T046 [P] [US4] Perbarui `docs/openapi-pos-mvp.yaml` untuk `modifier_selections`, `options[].min_qty/max_qty`, `modifiers[].qty`, dan perubahan Laporan Bahan (`contracts/api-changes.md`).

### Web

- [X] T047 [P] [US4] Ubah `resources/js/utils/modifierRules.js`: pilihan membawa `qty`; `selectionDelta(groups, sel, base)` memakai qty dan porsi per SATUAN sama dengan server; tulis `qa-tests/unit/modifierRules.test.js` (tambahan kasus: qty 2, porsi per satuan, batas).
- [X] T048 [US4] Ubah `resources/js/components/restaurant/ModifierPickerModal.vue` (stepper jumlah untuk pilihan `max_qty ≠ 1`, jumlah awal = `min_qty`, pelepasan pilihan = jumlah 0, batas min/maks diberlakukan, pilihan `max_qty = 1` tetap pilih/lepas), `resources/js/stores/posCart.js`, `resources/js/views/PosView.vue`, `resources/js/views/TabView.vue`: bawa `qty` per pilihan. Payload: kirim `modifier_selections` bila ada pilihan berjumlah ≠ 1; bila semua pilihan berjumlah 1, tetap kirim `modifier_option_ids` (jalur lama, kompatibel dengan server lama).
- [X] T049 [US4] Ubah `resources/js/components/restaurant/ModifierGroupsAdmin.vue`: kolom "Jumlah minimum"/"Jumlah maksimum" per pilihan (kosong = tanpa batas atas, bantuan teks), validasi min ≤ maks, teks di `resources/js/locales/{en,id}.json` (kedua bahasa). (Berkas sama dengan T036 — kerjakan setelahnya.)
- [X] T050 [P] [US4] Ubah `resources/js/components/receipt/ReceiptModal.vue` dan `resources/js/components/sales/OpenOrderDetail.vue` agar menampilkan "Nama ×N"; ubah `resources/js/utils/posProductCards.js` hanya bila kartu/keranjang membawa pilihan (cek; jangan membuat kartu dengan `map` inline — aturan CLAUDE.md).
- [X] T051 [P] [US4] Tulis/perbarui `qa-tests/component/ModifierPickerModal.test.js`, `qa-tests/unit/posCartModifiers.test.js`, `qa-tests/component/ReceiptModal.test.js` (jumlah di luar batas ditolak di UI, payload `modifier_selections`, label ×N, pilihan `max_qty=1` tidak menampilkan stepper).

### Android (`android-pos/`)

- [X] T052 [US4] Ubah `android-pos/app/src/main/java/com/warkop/pos/model/PosModels.kt` (`ModOption` + `minQty`, `maxQty: Int?`; model baris/struk membawa `qty` per modifier) dan `data/MenuMapper.kt` (baca `min_qty`/`max_qty`, bawaan 1/1 bila kunci tak ada); tes `android-pos/app/src/test/java/com/warkop/pos/data/MenuMapperEntriesTest.kt`.
- [X] T053 [P] [US4] Ubah `model/ModifierPricing.kt`: `optionDelta`/`unitPrice`/`portionCount` qty-aware dan SAMA dengan server (kontribusi × qty, porsi per SATUAN); perluas `android-pos/app/src/test/java/com/warkop/pos/model/ModifierPricingTest.kt` (qty 2, grup porsi per satuan, batas).
- [X] T054 [US4] Ubah `ui/Sheets.kt` (stepper jumlah per pilihan di pemilih modifier, batas min/maks, harga berjalan) dan `state/PosStore.kt` (menyimpan pilihan sebagai id→qty); semua teks baru memakai istilah baku yang dijaga `TerminologyScanTest` (mis. "Jumlah").
- [X] T055 [US4] Ubah `data/RemoteBackend.kt` (±baris 344–347) dan DTO terkait: kirim `modifier_selections` saat ada jumlah > 1 atau batas non-bawaan, selain itu tetap `modifier_option_ids` (APK baru + server lama tetap berfungsi); tes dengan MockWebServer/serializer yang sudah dipakai di `RemoteBackend*Test.kt`.
- [X] T056 [P] [US4] Ubah `data/OrderMapper.kt`, `data/ReceiptMapper.kt`, `print/Documents.kt`, `model/KitchenPrint.kt`: parse `qty`, cetak "Nama ×N" di struk dan KOT; perluas `android-pos/app/src/test/java/com/warkop/pos/print/DocumentsModifiersTest.kt` dan `ReceiptMapperTest.kt`.

**Checkpoint**: SC-003, SC-004 (bagian modifier), SC-005 terpenuhi; semua cerita independen berfungsi.

---

## Phase 8: Polish & Cross-Cutting

- [X] T057 [P] Tambah catatan bertanggal 089 di `CLAUDE.md` (bagian Domain rules: resep tanpa versi, resep aktif dijumlahkan, rumus potongan, `modifier_selections`, `min_qty/max_qty`, porsi per satuan, sifat satu arah migrasi) dan catatan bertanggal di `docs/PRD-POS-Event-Multivendor.md` serta `docs/RUNBOOK.md` (catatan rilis: stok bahan mulai turun karena resep memotong stok).
- [X] T058 [P] Tulis tes otorisasi dan keamanan di `tests/Feature/RecipeApiTest.php`/`ModifierQuantityTest.php` bila belum tercakup: PUT/DELETE resep dari pengguna tanpa izin → 403; resep produk lain tidak bisa diubah lewat `variant_ids` asing (422 `recipe_variants_foreign`); harga/qty tidak pernah dipercaya dari klien (qty di luar batas ditolak walau UI mengizinkan). Area: access control, input validation.
- [X] T059 Jalankan suite penuh SATU PROSES: backend (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array`), `npm test`, dan `cd android-pos && ./gradlew testDebugUnitTest`; catat hasil dan kegagalan lama (mis. `ShiftReportParityTest`) di `evidence.md`.
- [X] T060 Lakukan verifikasi OTOMATIS terhadap sumber: `grep` bahwa tidak ada pembaca `version` resep tersisa (T008), tidak ada `Payment::create`/penulis stok baru (satu jalur tulis tetap), tidak ada hex literal baru di komponen, `npm run build` sukses (cek ukuran chunk tidak melonjak).
- [ ] T061 **Butuh manusia** — verifikasi peramban nyata mengikuti `quickstart.md` langkah 1–5 dan 7 (konsol bersih); catat di `evidence.md`. Sampai dilakukan, tandai "belum terverifikasi".
- [ ] T062 **Butuh manusia** — verifikasi tablet/APK nyata (pemilih jumlah, harga berjalan, cetak struk/KOT "×N", APK lama dengan `min_qty > 1` mendapat 422) mengikuti `quickstart.md` langkah 5–6; catat di `evidence.md`.
- [ ] T063 **Gerbang persetujuan** — minta persetujuan pengembang sebelum menjalankan `php artisan migrate` pada DB dev (`warkop_wjm`); cadangkan dulu dengan `php artisan app:backup`; catat persetujuan dan hasil di `evidence.md`. Jangan dijalankan otomatis.
- [X] T064 Tinjauan kode independen (mis. `/code-review` atau `superpowers:requesting-code-review`) atas diff, fokus pada: migrasi data (pemotongan ganda), idempotensi `consumeOrder`, invarian `unit_price = dasar + Σ price_delta`, kompatibilitas klien lama. Perbaiki temuan dan catat di `evidence.md`.

---

## Dependencies & Execution Order

```text
Phase 1 (Setup)
   └─► Phase 2 (Foundational: T003–T009)   ← memblokir semua cerita
          ├─► US1 (T010–T016)  ─┐
          ├─► US5 (T017–T028)  ─┼─► MVP (US1 + US5)
          │       └─► US2 (T029–T031)  [berkas RecipeDrawer sama dengan T027]
          ├─► US3 (T032–T036)
          └─► US4 (T037–T056)  [T043 setelah T013; T049 setelah T036]
                       └─► Phase 8 Polish (T057–T064)
```

- US1 butuh hanya Foundational (resep untuk tes dibuat lewat factory).
- US5 butuh Foundational; T020 (biaya jumlah) memakai `activeRecipesFor` dari T009.
- US2 setelah US5 pada berkas `RecipeDrawer.vue` dan locale; secara fungsi independen.
- US3 independen dari resep; berbagi `ModifierGroupsAdmin.vue` dengan US4 (T036 sebelum T049).
- US4 independen dari US5; berbagi `ModifierMaterialConsumption.php` dengan US1 (T013 sebelum T043).
- Tes tiap cerita ditulis dan HARUS gagal sebelum implementasi cerita itu.

## Parallel Examples

- **Foundational**: T004, T005, T006, T007 dapat jalan bersamaan (berkas berbeda) setelah T003.
- **US1**: T010, T011, T012 (tes) bersamaan; lalu T013 → T014 → T015.
- **US5**: T018, T023, T024, T025 bersamaan; T028 (tes web) paralel dengan T019–T022.
- **US4**: T037, T038, T039 (tes) bersamaan; backend T040–T046 sebagian besar berurutan (resolver dulu), web T047/T050/T051 dan Android T053/T056 paralel dengan backend setelah kontrak (T046) disepakati.
- Peringatan: tes backend di kontainer TIDAK boleh paralel (satu proses per DB tes).

## Implementation Strategy

1. **MVP**: Phase 1 → 2 → US1 → US5. Hasil: resep (satu atau banyak) memotong stok dan bisa dikelola tanpa versi. Hentikan dan verifikasi (T016, T059 sebagian).
2. **Inkremen 2**: US2 (label) dan US3 (perbaikan galat bahan) — kecil dan cepat, bisa dirilis sendiri.
3. **Inkremen 3**: US4 (jumlah pilihan) — web dulu, lalu Android; server mendukung klien lama sehingga APK bisa menyusul.
4. **Penutup**: Polish, verifikasi manusia (T061/T062), persetujuan migrasi dev (T063), tinjauan kode (T064).

## Ringkasan

- Total tugas: 64
- Per fase: Setup 2 · Foundational 7 · US1 7 · US5 12 · US2 3 · US3 5 · US4 20 · Polish 8
- Tugas bertanda [P]: 28; tugas "butuh manusia": T061, T062, T063.
