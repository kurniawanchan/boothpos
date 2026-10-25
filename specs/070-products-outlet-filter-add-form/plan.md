# Implementation Plan: Filter outlet Produk, formulir tambah setara halaman item, angka kepala mengikuti filter

**Klasifikasi**: INTERNAL

**Branch**: `070-products-outlet-filter-add-form` | **Date**: 2026-10-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/070-products-outlet-filter-add-form/spec.md`

## Summary

Empat perubahan pada halaman Produk, hampir semuanya menyambung kemampuan yang SUDAH ada di server (fitur 043 dan 045):

1. **Filter Outlet** di baris filter: server sudah menerima `outlet_id[]` pada `GET /products?tab=…`; yang kurang adalah kendali di UI, status di URL, dan **tidak mengirim header `X-Outlet-Id`** pada dua panggilan halaman ini, karena header hanya menyempitkan dan membuat filter tak bisa memilih outlet lain (D1).
2. **Angka kepala mengikuti filter**: `GET /products/summary` dan `counts` pada daftar kini menerima filter yang sama dan menghitung dari himpunan item yang sama dengan daftar (satu definisi "himpunan tersaring" di `ProductCatalogQuery`, D2–D4).
3. **Varian & harga di formulir tambah**: `POST /products` menerima `variants.*.outlet_prices` dan menuliskannya dalam transaksi yang sama lewat `PriceService` (satu-satunya penulis harga); tabel varian di drawer menambah kolom harga per outlet (D5–D6).
4. **Ketersediaan di formulir tambah**: bagian itu menyatukan pilihan outlet pembawa (`outlet_ids`, sudah ada) dan status awal dalam tata letak "Ketersediaan per outlet"; server menolak `outlet_ids` kosong saat membuat (D7).

Tidak ada migrasi, tabel baru, atau perubahan Android.

## Technical Context

**Language/Version**: PHP 8 / Laravel (API), Vue 3 + Pinia + Vite (SPA), Tailwind v4 CSS-first

**Primary Dependencies**: yang sudah ada saja — `ProductCatalogQuery`, `PriceService`, `OutletMenuService`, `OutletContext`, `useQueryState`, `FilterBar`/`BaseMultiSelect`, `ProductFormDrawer`/`ProductVariantsTable`, `AvailabilitySection`. Tidak ada dependensi baru.

**Storage**: MySQL 8 (tabel sudah ada: `outlet_item_settings`, `variant_outlet_prices`, `price_histories`, `item_availability_marks`). Tanpa migrasi.

**Testing**: PHPUnit `tests/Feature/` (MySQL, `warkop_wjm_test`; di kontainer WAJIB `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` dan `-e CACHE_STORE=array -e SESSION_DRIVER=array`); Vitest `qa-tests/` (`npm test`); verifikasi layar nyata di browser untuk perubahan yang menyentuh layar.

**Target Platform**: SPA back-office (browser) + API Laravel; satu server dan satu basis data per organisasi.

**Project Type**: web-service + SPA (struktur repo yang ada).

**Performance Goals**: angka kepala dihitung ulang di bawah 2 dtk pada ±250 item (SC-005); jumlah query tetap (tanpa N+1).

**Constraints**: outlet ditentukan server, permintaan hanya boleh menyempitkan (Konstitusi IV); `docs/openapi-pos-mvp.yaml` bergerak dalam komit yang sama; mode DEMO/LIVE tetap berlaku; teks UI, komentar, dan dokumen berbahasa Indonesia.

**Scale/Scope**: ±250 item menu, 2–4 outlet per organisasi; 3 layar disentuh (Produk, drawer tambah, tabel varian).

## Constitution Check

*GATE: lulus sebelum Phase 0; dievaluasi ulang setelah Phase 1 (lulus).*

| Prinsip | Penilaian |
|---|---|
| I. Kode bersih / satu jalur tulis | LULUS. Harga outlet hanya ditulis lewat `PriceService` (bukan `VariantOutletPrice::create` baru); carried lewat `OutletMenuService::setCarried`; definisi "himpunan tersaring" satu di `ProductCatalogQuery` dan dipakai daftar, hitungan tab, dan angka kepala. Tidak ada abstraksi spekulatif. |
| II. Pengujian | LULUS dengan syarat tugas: tes Feature untuk filter, angka kepala, `outlet_prices` saat buat (rilis mati dan hidup), penolakan `outlet_ids` kosong; tes Vitest untuk filter, URL, tabel varian, bagian Ketersediaan; uji browser nyata. |
| III. Konsistensi UX | LULUS. Memakai `FilterBar`/`BaseMultiSelect`, `useQueryState`, `ProductVariantsTable`, tata letak `AvailabilitySection`; tanpa heks mentah, token Tailwind saja. |
| IV. Keamanan / outlet | LULUS. `outlet_id[]` dipotong dengan `permittedIds()` di server; `outlet_prices` divalidasi dengan aturan outlet-diizinkan DAN harus termasuk outlet pembawa; outlet tidak pernah dibaca dari badan permintaan selain daftar eksplisit yang divalidasi (pola `outlet_ids` yang sudah ada). Log audit harga ditulis `PriceService` dalam transaksi yang sama. |
| V. Kinerja | LULUS. Angka kepala dihitung per permintaan (tanpa cache) dan membawa `computed_at`; tak ada cache baru yang perlu kunci. Kueri himpunan tersaring memakai subkueri/`whereIn`, bukan loop per item. |

Tidak ada pelanggaran → Complexity Tracking kosong.

## Project Structure

### Documentation (this feature)

```text
specs/070-products-outlet-filter-add-form/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-products-070.md
├── checklists/requirements.md
└── tasks.md             # dibuat /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/Api/ProductController.php     # summary() menerima filter; catalog() counts menurut outlet; store() menulis outlet_prices
│   └── Requests/
│       ├── ProductCatalogRequest.php             # (tetap) dipakai ulang oleh summary
│       └── StoreProductRequest.php               # + variants.*.outlet_prices, outlet_ids min:1
└── Services/ProductCatalogQuery.php              # satu "himpunan tersaring" untuk page/counts/summary

resources/js/
├── api/client.js                                 # opsi per-permintaan: tanpa header X-Outlet-Id
├── api/products.js                               # productSummary(params), listProducts(params) tanpa header
├── views/ProductsView.vue                        # filter Outlet, URL state, muat ulang angka kepala
├── views/products/ProductFormDrawer.vue          # bagian Ketersediaan; kirim outlet_prices
├── views/products/ProductVariantsTable.vue       # kolom harga per outlet (mode create)
├── components/product/ProductFigures.vue         # keadaan memuat per kartu
└── locales/{id,en}.json                          # teks baru

tests/Feature/                                    # ProductCatalogOutletFilterTest, ProductSummaryFilterTest, ProductStoreOutletPricesTest
qa-tests/component/                               # ProductsView filter, ProductVariantsTable, ProductFormDrawer availability
docs/openapi-pos-mvp.yaml                         # summary params, counts, outlet_prices
```

**Structure Decision**: struktur web-app yang sudah ada dipakai apa adanya; tidak ada direktori baru di kode aplikasi.

## Complexity Tracking

Tidak ada pelanggaran konstitusi yang perlu dibenarkan.
