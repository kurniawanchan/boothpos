# Bukti Implementasi — 089

**Klasifikasi**: INTERNAL

Tanggal: 2026-11-02. Cabang `089-recipe-multi-modifier-qty` (belum di-commit).

## Baseline (T002)

Sebelum perubahan: `--filter='Recipe|Modifier|MaterialReport'` = **164 lulus, 0 gagal** (873 assertion).

## Tes otomatis (dijalankan sesi ini)

| Area | Hasil |
|---|---|
| Backend terfilter: `RecipeApiTest` + `RecipeUnversionMigrationTest` (setelah perbaikan review) | 25 lulus |
| Backend: `RecipeMaterialConsumptionTest` | 14 lulus |
| Backend: `ModifierQuantityTest` + `ModifierMaterialQuantityTest` | 23 lulus |
| Backend: `ModifierOptionMaterialMappingTest` (termasuk bahan sama di banyak pilihan) | 10 lulus |
| Backend: `MaterialReport*` | 25 lulus |
| Backend: regresi `Modifier|TabModifiers|OrderModifier|Material` | 244 lulus (+1 diperbaiki: bentuk opsi kini memuat `min_qty/max_qty`) |
| Web (Vitest) penuh | **1966 lulus**, 2 dilewati, 0 gagal (232 berkas) |
| Android `testDebugUnitTest` (app + core, JBR 21) | **586 tes, 0 gagal** (termasuk `TerminologyScanTest`) |
| `npm run build` | sukses |
| Backend suite PENUH | 2495 lulus, 1 gagal lama (`ShiftReportParityTest`), 1 dilewati |

Catatan: perintah tes kontainer yang benar menaruh SEMUA `-e` sebelum nama layanan (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …`); `-e` setelah `artisan test` ditolak PHPUnit ("Unknown option").

## Suite penuh backend (T059)

Dijalankan di kontainer (`warkop_wjm_test`, satu proses), 3350 dtk: **2495 lulus, 1 gagal, 1 dilewati** (20144 assertion).

- Satu kegagalan: `ShiftReportParityTest` — "the summary keeps every existing field" (`assertJsonMissingPath('expected_cash')`, baris 61). Ini kegagalan lama yang sudah dikenal (ringkasan shift/blind count, fitur 048); cabang 089 tidak menyentuh berkas sesi/shift mana pun (`git status` bersih untuk nama `session|shift`). Tidak diselidiki lebih jauh di sini.
- Percobaan pertama dihentikan pengguna; kedua mati karena kontainer app/node dimatikan (exit 137, MySQL restart) — bukan kegagalan tes. Hasil di atas dari percobaan ketiga yang utuh.

## Temuan pembaca `version` resep (T008)

Pembaca yang ditemukan dan diperbaiki: `RecipeService` (create/remove/activeFor/versions/present/writeCost/validate), `RecipeController::destroy` (alias `removed_versions` dipertahankan), `ProductDetailService::recipeSummary`, `Product::recipes()`, `RecipeSection.vue`, `RecipeDrawer.vue`, kunci locale `rc_versioning/rc_versions*/rc_col_version/rc_version_cost/rc_yield_unit`, `tests/Support/MaterialReportFixtures.php`. Grep akhir: tidak ada pembaca `recipe->version` tersisa. Tablet: tidak ada kata "recipe" di `android-pos/app/src/main` (asumsi kontrak terbukti).

## Verifikasi di sumber (T034, T060)

- `ModifierGroupService::syncOptions` tidak punya pemeriksaan kembar lintas pilihan (hanya unique `(pilihan, bahan)` per pilihan di skema); penyebab galat "bahan yang sama" adalah `distinct` dua-wildcard di `StoreModifierGroupRequest` — diperbaiki.
- Tidak ada penulis stok baru (`applyMovement` hanya lewat `ModifierMaterialConsumption`), tidak ada `Payment::create`, tidak ada hex literal baru di komponen web.

## Tinjauan kode independen (T064)

Dilakukan agen peninjau terpisah (baca-saja, tanpa menjalankan tes). Tidak ada temuan Kritis. Ditindaklanjuti:

1. **Penting — migrasi: resep masa depan pada varian yang sudah punya resep aktif** akan terpotong dua kali pada tanggalnya. DIPERBAIKI: tautan resep masa depan semacam itu dilepas dan dicatat `recipe_migrated`; resep masa depan pada varian tanpa resep aktif tetap tertaut. Tes migrasi diperluas (`test_a_future_only_recipe_stays_linked_until_its_date`).
2. **Kecil — validasi batas:** `yield` dan `lines.*.quantity` kini punya batas atas sesuai lebar kolom (422, bukan 500); tes ditambahkan.

Dicatat, TIDAK diubah:

- **Varian BOM-selesai dan resep masa depan:** `isRecipeDriven` memakai resep aktif (bertanggal), sehingga BOM bisa diselesaikan untuk varian yang punya resep masa depan lalu `cost_price`-nya ditulis resep pada tanggalnya. Syarat tanggal yang sama sudah ada pada `activeFor` lama, jadi bukan regresi 089; keputusan pemilik diperlukan bila ingin mengubahnya.
- **Pembulatan 3 desimal per (bahan, baris)** membuang kebutuhan bahan yang sangat kecil (mis. 0,3 g pada bahan ber-satuan kg). Aturan ini berasal dari 087, bukan 089.
- **Penjaga idempotensi `consumeOrder`** bersifat per pesanan; tidak ada jalur yang menulis `sale` sebagian, jadi tidak berdampak sekarang.
- `GET /recipes` terbuka bagi pemegang menu `products` (biaya tetap dijaga izin biaya); perilaku lama.

## Verifikasi peramban (T061)

**BELUM TERVERIFIKASI** — butuh manusia. Ikuti `quickstart.md` langkah 1–5 dan 7 (label "Porsi", dua resep per varian, potong/void stok, bahan sama di banyak pilihan, jumlah pilihan di kasir, konsol bersih).

## Verifikasi tablet (T062)

**BELUM TERVERIFIKASI** — butuh manusia (pemilih jumlah, harga berjalan, cetak struk/KOT "Telur x2", APK lama dengan `min_qty > 1` mendapat 422). Kompilasi dan tes unit Android lulus; tampilan Compose belum dilihat di perangkat.

## Migrasi DB dev (T063)

**BELUM DIJALANKAN** — menunggu persetujuan pengembang (cadangkan dulu: `php artisan app:backup`). Migrasi `2026_12_31_000001` satu arah; `…000002` menambah `min_qty/max_qty`.

## Risiko APK lama

APK lama hanya mengirim `modifier_option_ids` (jumlah 1). Bila `min_qty` sebuah pilihan diatur > 1, pesanan dari APK lama ditolak 422. Pilihan ber-`max_qty` > 1 tetap bisa dipilih satu kali dari APK lama.

## Hal yang belum terverifikasi

- Peramban nyata dan tablet nyata (T061, T062).
- Migrasi pada data dev sungguhan (T063).
