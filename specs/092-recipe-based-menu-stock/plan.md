# Implementation Plan: Stok Menu Berbasis Resep (Web dan Android)

**Klasifikasi**: INTERNAL

**Branch**: `092-recipe-based-menu-stock` | **Date**: 2026-11-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/092-recipe-based-menu-stock/spec.md`

## Summary

Pemotongan bahan resep saat pesanan selesai SUDAH ada (087/089). Yang ditambahkan: (1) satu penghitung server, `MenuStockService`, yang menurunkan stok menu dari saldo bahan outlet dikurangi bahan yang ditahan tab terbuka; (2) varian beresep otomatis lepas dari stok varian; (3) penjaga kapasitas atomik yang menolak pesanan melebihi bahan (409), dipanggil di semua titik pesanan masuk dan selesai; (4) angka stok dikirim lewat bidang `outlet_stock`/`track_stock` yang sudah dibaca web dan Android, sehingga kedua klien (dan APK lama) menampilkan angka identik tanpa menghitung sendiri. Tanpa tabel atau migrasi baru. Detail dan alasan: [research.md](research.md).

## Technical Context

**Language/Version**: PHP / Laravel 13 (API); Vue 3 + Pinia + Vite (web, `resources/js`); Kotlin + Jetpack Compose (`android-pos/`)

**Primary Dependencies**: Eloquent, `MaterialStockService`, `RecipeService`, `ModifierMaterialConsumption`, `OutletPriceBook`, `OutletContext`; Vitest; JUnit untuk Android

**Storage**: MySQL 8 (wajib). Tanpa skema baru; membaca `recipes`, `recipe_variants`, `recipe_blocks`, `recipe_lines`, `materials`, `material_outlet_stock`, `orders`, `order_items`

**Testing**: PHPUnit di `tests/Feature/` terhadap MySQL `warkop_wjm_test` (flag aman wajib); Vitest `qa-tests/`; tes unit Android untuk `MenuMapper`; verifikasi peramban nyata dan tablet

**Target Platform**: server Laravel lokal atau bersama + SPA di peramban + tablet Android

**Project Type**: web-service + SPA + aplikasi mobile (repo ini: `app/`, `resources/js/`, `android-pos/`)

**Performance Goals**: pemuatan daftar menu dengan jumlah kueri tetap terhadap jumlah menu beresep (FR-018, SC-007); tanpa cache lintas permintaan (D9)

**Constraints**: bahan dipotong HANYA saat selesai (087 dipertahankan); satu penulis stok bahan (`MaterialStockService`); satu penerjemah kebutuhan (`ModifierMaterialConsumption`); kunci baris saldo berurutan `material_id`; tidak ada rute publik baru; APK lama harus tetap bekerja

**Scale/Scope**: satu organisasi, beberapa outlet, ratusan menu, belasan tab terbuka per outlet

## Constitution Check

*GATE: dievaluasi sebelum Phase 0, dievaluasi ulang setelah Phase 1.*

| Prinsip | Penilaian | Bukti |
|---|---|---|
| I. Kode (satu implementasi per concern) | LULUS | Satu penghitung (`MenuStockService`), satu rumus kebutuhan (D2, diekstrak, bukan disalin), satu penulis stok bahan, satu penerjemah kebutuhan. Logika di Services, controller hanya mendelegasikan. |
| II. Pengujian | LULUS bersyarat | Tes Feature + Vitest + Android unit + peramban nyata + tablet. Keserentakan MySQL sebenarnya hanya bisa dibuktikan manual (D12); tidak akan dilaporkan lulus tanpa dijalankan. |
| III. Konsistensi UX | LULUS | Galat 409 lewat penangan pusat; teks Indonesia; token CSS; tidak ada kontrol terlihat-tapi-ditolak (tombol tambah berhenti di batas). |
| IV. Keamanan | LULUS | Server satu-satunya penghitung dan pemutus; outlet dari server; tidak ada rute publik baru; nama bahan tidak bocor ke respons publik (D11). |
| V. Performa | LULUS | Kueri tetap per permintaan (D9), pilihan tanpa-cache dicatat sebagai sengaja; tanpa N+1. |
| Dokumentasi | AKAN dipenuhi | `docs/openapi-pos-mvp.yaml` di commit yang sama; catatan bertanggal di PRD dan CLAUDE.md untuk pembalikan 087 FR-031 (hanya menu beresep). |

**Pembalikan keputusan 087 (dicatat, bukan pelanggaran)**: penjualan tidak lagi "tidak pernah ditolak karena bahan" untuk kebutuhan yang berasal dari resep. Keputusan pemilik 2026-11-06 (spec FR-013). Kebutuhan dari pilihan modifier tetap tidak menolak.

**Evaluasi ulang pasca-desain**: tidak ada pelanggaran baru. Tidak ada entri Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/092-recipe-based-menu-stock/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-changes.md
├── checklists/requirements.md
└── tasks.md              # dibuat /speckit-tasks, BUKAN oleh perintah ini
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── MenuStockService.php            # BARU: kapasitas, tahanan, assertCanFulfil (D1,D3,D4,D5)
│   ├── RecipeService.php               # + recipeDrivenVariantIds() ber-memo; buang memo saat tulis (D6)
│   ├── ModifierMaterialConsumption.php # addRecipeNeed memanggil rumus bersama; + recipeNeed() publik (D2,D5)
│   ├── OrderService.php                # create(): assertCanFulfil sebelum consumeOrder
│   ├── TabService.php                  # addLine/updateLine(naik): assertCanFulfil
│   └── TabSettlementService.php        # settle/split: assertCanFulfil sebelum consumeOrder
├── Support/RecipeCost.php              # + perPortionNeed() rumus tunggal (D2)
├── Models/ProductVariant.php           # tracksOwnStock() + scope SQL: tidak beresep (D6)
├── Exceptions/RecipeStockException.php # BARU: 409 insufficient_recipe_stock (F9)
├── Http/Resources/ProductVariantResource.php   # pos: track_stock/outlet_stock/stock_basis/stock_limiting (D7)
└── Http/Controllers/Api/ProductController.php  # lookupVariants & daftar POS: bentuk varian yang sama
bootstrap/app.php                       # render RecipeStockException
docs/openapi-pos-mvp.yaml               # kontrak (commit yang sama)
docs/PRD-POS-Event-Multivendor.md       # catatan bertanggal pembalikan 087 FR-031 (khusus resep)

resources/js/
├── utils/posProductCards.js            # baca stock_basis/stock_limiting; tanpa menghitung ulang
├── views/PosView.vue, TabView.vue      # pesan batas qty menyebut bahan; muat ulang menu pasca transaksi (D8)
└── locales/{id,en}.json                # teks baru

android-pos/app/src/main/java/com/warkop/pos/
├── data/ (dto + MenuMapper)            # stockBasis, stockLimiting; angka tetap dari outletStock
├── model/PosModels.kt                  # Product.stockBasis/limitingMaterial (tampilan)
└── ui/ + store                         # pesan batas qty; muat ulang menu pasca transaksi (D8)

tests/Feature/
├── RecipeMenuStockCapacityTest.php     # kapasitas, bottleneck, nol, pecahan, susut, Portions, kasus tepi
├── RecipeMenuStockGuardTest.php        # 409, tahanan lintas tab, gabungan baris, atomik, retry
├── RecipeMenuStockVariantDecouplingTest.php # varian tidak bergerak, snapshot stock_source, tanpa resep tak berubah
├── RecipeMenuStockPayloadTest.php      # bentuk payload POS + jumlah kueri tetap
└── RecipeMenuStockVoidRefundTest.php   # pembalikan dari buku besar
qa-tests/                               # Vitest: posProductCards (stock_basis), pesan batas
android-pos/app/src/test/               # MenuMapperTest: stok beresep
```

**Structure Decision**: satu penghitung server (`MenuStockService`) berdampingan dengan penerjemah kebutuhan yang sudah ada; klien hanya membaca bidang payload yang sudah mereka pakai. Tidak ada modul, tabel, atau endpoint baru.

## Phase Notes

- **Urutan kerja yang disarankan** (untuk `/speckit-tasks`): rumus bersama (D2) → `MenuStockService` kapasitas+tahanan → `tracksOwnStock` (D6) → penjaga di titik pesanan (D5) → payload (D7) → klien web → klien Android → kontrak/PRD/CLAUDE.md → verifikasi (suite penuh, peramban, dua terminal, tablet).
- **Risiko utama**: (a) pemisahan stok varian mengubah laporan dan koreksi untuk varian beresep: dijaga tes dan dinyatakan ke pemilik; (b) tahanan dari tab terbuka membaca baris di banyak pesanan: dijaga satu kueri dan tes jumlah kueri; (c) pembalikan 087 FR-031 harus diberi catatan bertanggal agar tidak "dipulihkan" sebagai bug.
- **Belum terverifikasi saat perencanaan**: pemuatan ulang menu pasca transaksi di web dan Android (D8) dan konkurensi MySQL sebenarnya (D12).
