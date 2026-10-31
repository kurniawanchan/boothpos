# Implementation Plan: Pilihan Modifier Memakai Bahan Baku (Potong Stok Otomatis)

**Klasifikasi**: CONFIDENTIAL (memuat struktur data dan kontrol internal)

**Branch**: `087-modifier-material-deduction` | **Date**: 2026-10-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/087-modifier-material-deduction/spec.md`

## Summary

Setiap pilihan modifier boleh memakai nol atau lebih bahan baku dengan jumlah per porsi (tabel baru `modifier_option_materials`, disimpan lewat payload grup modifier yang sudah ada). Saat pesanan **menjadi selesai** (checkout ritel, tab dilunasi, atau split), satu service baru `ModifierMaterialConsumption` memotong bahan dari saldo outlet transaksi lewat `MaterialStockService` (satu-satunya penulis), menambah biaya bahan ke `cost_price` baris, dan mengembalikan peringatan bila saldo menjadi minus (diizinkan hanya untuk jenis `sale`/`waste`). Void dan refund mengembalikan potongan bersih yang tercatat di buku besar; batal "buang" di tab terbuka mencatat `waste`. Stok produk tidak berubah perilakunya. Keputusan lengkap: [research.md](./research.md) D1–D15.

**Tambahan US6 (2026-10-31)**: tiap produk punya `stock_source` (`variant` | `modifier`, bawaan `variant`). Bahan modifier tetap dipotong untuk SEMUA produk (FR-025 = gabungan); `modifier` hanya menghentikan pergerakan stok VARIAN, lewat satu aturan `ProductVariant::tracksOwnStock()` di `StockService::applyMovementIfTracked` dan snapshot `order_items.stock_source` untuk pengembalian. Payload POS mengirim `track_stock` efektif, sehingga web dan Android menampilkan produk `modifier` tanpa stok dan tidak pernah "habis" tanpa perubahan klien (FR-031). Modul lain (koreksi, transfer, laporan stok, halaman Produk, master data, katalog WJM) mengikuti aturan yang sama. Lihat research D16–D21.

## Technical Context

**Language/Version**: PHP 8.x / Laravel 13 (backend), Vue 3 + Vite + Tailwind v4 (web), Kotlin/Compose (`android-pos/`, tanpa perubahan wajib)

**Primary Dependencies**: Eloquent, Sanctum, Pinia, Vitest; tidak ada dependensi baru

**Storage**: MySQL 8 (CHECK constraint, ENUM); `warkop_wjm` (dev), `warkop_wjm_test` (tes)

**Testing**: `php artisan test` (MySQL nyata, kontainer dengan `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test ... -e CACHE_STORE=array -e SESSION_DRIVER=array`), Vitest di `qa-tests/`, browser nyata untuk layar Grup modifier, Bahan, dan POS

**Target Platform**: server lokal / server bersama satu organisasi (konstitusi bentuk a/b)

**Project Type**: web-service + SPA (+ klien Android yang membaca respons yang sama)

**Performance Goals**: SC-006 — pesanan 10 baris × 3 pilihan berpemetaan selesai dalam toleransi 10% dari sebelum fitur. Target implementasi: 1 query muat pemetaan per pesanan, 1 query penjaga, ≤ 1 movement per (baris, bahan)

**Constraints**: atomik dalam transaksi penyelesaian yang sudah ada; urutan kunci `material_id ASC, order_item_id ASC`; DEMO/LIVE dan outlet terpisah; respons aditif (APK lama tetap jalan)

**Scale/Scope**: puluhan grup, ratusan pilihan, ratusan bahan per instalasi; ~1 migrasi tabel + 2 migrasi ubah; 1 service baru; 6 service diubah; 2 layar web diubah

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Prinsip | Status | Bukti |
|---|---|---|
| I. Satu jalur tulis, logika di Services | ✅ | Semua tulis stok bahan lewat `MaterialStockService::applyMovement()`; terjemahan baris → bahan hanya di `ModifierMaterialConsumption`; biaya satuan hanya `RecipeService::unitCost()`; log hanya `ActivityLogger`. Tidak ada tabel generik spekulatif (D1). |
| II. Diverifikasi, bukan diasumsikan | ✅ rencana | 8 berkas tes fitur (quickstart), Vitest, verifikasi browser nyata + konsol; tablet dicatat belum terverifikasi bila tidak tersedia. |
| III. UX konsisten | ✅ | Token Tailwind; 422/409 konvensional; peringatan stok = toast, bukan galat; bagian pemetaan hanya tampil untuk peran `dining_setup`; salinan Indonesia. |
| IV. Keamanan | ✅ | Jumlah potong dihitung server dari snapshot + pemetaan, tidak pernah dari klien; outlet = `order.outlet_id`; riwayat lewat `OutletFilter`; snapshot biaya ditulis sekali saat selesai; log pemetaan dalam transaksi. |
| V. Kinerja | ✅ | Pemetaan dimuat sekali per pesanan (eager); daftar bahan tetap ramping (`used_by_modifier_options` hanya di `show`); riwayat dipaginasi. |
| Dokumentasi | ✅ rencana | `docs/openapi-pos-mvp.yaml` di commit yang sama; catatan bertanggal di CLAUDE.md (Domain rules) dan PRD §10.2 (resep/BOM kini juga memotong lewat modifier). |

**Re-check pasca Phase 1**: ✅ tanpa pelanggaran. Satu hal yang perlu perhatian, bukan pelanggaran: melepas CHECK ≥ 0 memindahkan invarian dari database ke service (D6). Diimbangi tes `MaterialNegativeStockTest` yang membuktikan koreksi/transfer tetap ditolak.

## Project Structure

### Documentation (this feature)

```text
specs/087-modifier-material-deduction/
├── spec.md
├── assessment.md        # penilaian pra-plan (diminta pengguna)
├── plan.md              # dokumen ini
├── research.md          # D1–D15 + daftar belum terverifikasi
├── data-model.md
├── quickstart.md
├── contracts/api.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks (belum dibuat)
```

### Source Code (repository root)

```text
database/migrations/
├── 2026_12_30_000001_create_modifier_option_materials.php          # BARU
├── 2026_12_30_000002_add_consumption_types_to_material_movements.php # BARU (enum sale/return/waste, penjaga down)
└── 2026_12_30_000003_allow_negative_material_outlet_stock.php        # BARU (lepas CHECK, penjaga down)

app/Models/
├── ModifierOptionMaterial.php            # BARU (HasDataMode)
├── ModifierOption.php                    # + materials()
└── Material.php                          # + modifierUsages()

app/Services/
├── ModifierMaterialConsumption.php       # BARU: consumeOrder, restoreOrder, wasteLine
├── MaterialStockService.php              # NEGATIVE_ALLOWED = ['sale','waste'], tanpa jepit untuk jenis itu
├── ModifierGroupService.php              # syncOptions → syncOptionMaterials + log modifier_materials_changed
├── OrderService.php                      # checkout → consumeOrder; void → restoreOrder
├── TabSettlementService.php              # settle (cabang $completes) & split → consumeOrder
├── TabService.php                        # removeLine('waste') & voidTab → wasteLine
└── RefundService.php                     # refund → restoreOrder

app/Http/
├── Requests/StoreModifierGroupRequest.php      # options.*.materials.*
├── Resources/ModifierGroupResource.php         # options[].materials
├── Resources/MaterialResource.php              # used_by_modifier_options (show)
├── Controllers/Api/MaterialController.php      # show eager-load, destroy 409, index stock_status, movements()
├── Controllers/Api/OrderController.php         # material_warnings di respons checkout
└── Controllers/Api/TabPaymentController.php    # material_warnings di respons settle/split

app/Support/OutletScopeRegistry.php       # teks entri material_stock_movements
routes/api.php                            # GET /materials/{material}/movements
lang/id/*.php                             # pesan 422/409/peringatan
docs/openapi-pos-mvp.yaml

resources/js/
├── api/modifierGroups.js, api/materials.js
├── components/restaurant/ModifierGroupsAdmin.vue      # editor bahan per pilihan
├── components/restaurant/OptionMaterialsEditor.vue    # BARU: baris bahan + jumlah + satuan
├── components/masterData/MaterialsTable.vue           # pil Minus, saringan, tombol "Dipakai oleh / Riwayat"
├── components/masterData/MaterialUsageModal.vue       # BARU: dipakai oleh + riwayat (P3)
└── views/PosView.vue (atau composable checkout)       # toast material_warnings

tests/Feature/   (8 berkas, lihat quickstart.md)
qa-tests/unit/   modifierMaterials.test.js, materialsNegative.test.js

android-pos/     # tanpa perubahan wajib; verifikasi parser (research U1); tampilan peringatan = P3
```

**Structure Decision**: struktur Laravel + SPA yang sudah ada; tanpa modul baru. Satu service baru karena "terjemahkan pesanan → potongan bahan" punya tiga pemanggil penyelesaian dan dua pemanggil pengembalian (konstitusi I: satu implementasi per concern).

## US6 — berkas yang disentuh

```text
database/migrations/2026_12_30_000004_add_stock_source_to_products_and_order_items.php   # BARU
app/Models/ProductVariant.php            # tracksOwnStock(), scopeTracksOwnStock()
app/Models/Product.php                   # stock_source fillable/cast
app/Services/StockService.php            # applyMovementIfTracked → tracksOwnStock; applyLineMovementIfTracked (baru)
app/Services/OrderService.php, TabService.php, TabSettlementService.php, RefundService.php   # snapshot + pengembalian via baris
app/Services/StockTransferService.php    # tracksOwnStock
app/Http/Controllers/Api/StockController.php, ReportController.php, ProductController.php
app/Services/AttentionService.php, MasterDataImportService.php, WjmCatalogSeedService.php
app/Support/MasterDataSheets.php
app/Http/Requests/StoreProductRequest.php (+ update), app/Http/Resources/ProductVariantResource.php (+ produk)
resources/js/views/products/ProductFormDrawer.vue, ProductVariantsTable.vue, daftar Produk
docs/openapi-pos-mvp.yaml
tests/Feature/ProductStockSourceTest.php, StockSourceModulesTest.php
```

## Urutan kerja (untuk /speckit-tasks)

1. **Fondasi** (memblokir semua): 3 migrasi, model, `MaterialStockService` minus terbatas + tes regresi `MaterialStockPerOutletTest`.
2. **US1 pemetaan** (P1): request, `syncOptions`, log, resource, "dipakai oleh", 409 hapus, UI editor.
3. **US2 potong saat selesai** (P1): `consumeOrder` + 3 titik + biaya + `material_warnings` + toast web.
4. **US3 pengembalian & buang** (P1): `restoreOrder` (void, refund), `wasteLine` (removeLine, voidTab).
5. **US4 minus** (P2): saringan `stock_status=negative`, pil Minus.
6. **US5 riwayat** (P3): endpoint movements + modal.
7. **Penutup**: OpenAPI, CLAUDE.md/PRD catatan bertanggal, verifikasi parser Android (U1), browser nyata, `evidence.md`.
8. **US6 sumber stok** (P1, ditambahkan 2026-10-31): migrasi + aturan `tracksOwnStock` + snapshot baris → jalur jual/kembali → modul lain (koreksi, transfer, laporan, Produk, master data, katalog) → UI formulir → OpenAPI/catatan → regresi penuh.

## Risiko

| Risiko | Mitigasi |
|---|---|
| Tes lama mengandalkan CHECK ≥ 0 bahan | Jalankan `MaterialStockPerOutletTest`, `MaterialTest`, tes transfer/PO di fase fondasi |
| Parser Android galat karena bidang baru | Verifikasi U1 sebelum rilis; bidang hanya muncul saat ada minus |
| Settle dipanggil saat lunas sebagian → potong terlalu dini | Panggil hanya di cabang `$completes`; tes "settle sebagian = 0 movement" |
| Double count biaya pada split | Biaya bahan ditambahkan hanya saat selesai; baris tab terbuka yang disalin split belum punya biaya bahan; tes split |
| Pembulatan biaya per satuan | Diterima dan didokumentasikan (D9) |

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dijustifikasi.
