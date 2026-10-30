# Implementation Plan: Item Melepas Grup Modifier Warisan Kategori

**Klasifikasi**: INTERNAL

**Branch**: `086-item-opt-out-category-modifier` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/086-item-opt-out-category-modifier/spec.md`

## Summary

Grup modifier yang dilampirkan ke KATEGORI kini selalu berlaku untuk semua item di kategori itu, tanpa jalan keluar (research F1). Fitur ini menambah **pengecualian per item**: item boleh melepas satu grup warisan, dan bisa memulihkannya. Pendekatan: tabel baru `modifier_group_product_exclusions`; SATU helper `ModifierResolver::inheritedGroups()` yang dipakai tiga pembaca grup kategori (resolver/POS/validasi pesanan, detail Produk, daftar Produk); endpoint `PUT /products/{id}/modifier-links` yang ada menerima `excluded_group_ids` opsional; satu komponen UI bersama untuk laci dan formulir. Android tidak berubah (membaca grup efektif dari server).

## Technical Context

**Language/Version**: PHP 8 / Laravel (server), Vue 3 + Vite (web)

**Primary Dependencies**: yang sudah ada; tidak ada dependensi baru

**Storage**: MySQL 8 — SATU tabel baru (`modifier_group_product_exclusions`), tanpa mengubah tabel lain

**Testing**: PHPUnit (`tests/Feature/`, DB `warkop_wjm_test`), Vitest (`qa-tests/`)

**Target Platform**: server Laravel + SPA web lokal; tablet Android hanya membaca hasilnya

**Project Type**: web-service + SPA

**Performance Goals**: tanpa kueri per baris; relasi pengecualian di-eager-load (research D9)

**Constraints**: klien lama yang tak mengirim `excluded_group_ids` tidak boleh menghapus pengecualian (D4); tautan item menang atas pengecualian; rilis menu aktif diantrekan; teks UI Indonesia dan Inggris

**Scale/Scope**: 1 migrasi, 1 relasi, 1 helper, 3 pembaca server, 1 endpoint diperluas, 1 komponen UI bersama dipakai 2 permukaan

## Constitution Check

| Prinsip | Status | Catatan |
|---------|--------|---------|
| I. Kode bersih, satu sumber | LULUS | Satu helper `inheritedGroups()` untuk tiga pembaca (F2); tulis tautan tetap hanya lewat `ModifierLinkService`. Tabel terpisah menghindari tafsir ganda pada `modifier_group_product` (D1). |
| II. Pengujian | LULUS dengan syarat | Tes server dan komponen wajib. Perubahan layar wajib dicoba di peramban nyata; belum bisa di tahap rencana, dicatat di `evidence.md`, tidak diklaim selesai. |
| III. Konsistensi UX | LULUS | Token desain, teks Indonesia/Inggris; kontrol tersembunyi penuh untuk peran tanpa izin; galat 422/403 lewat penanganan pusat. |
| IV. Keamanan | LULUS | Server memutuskan grup efektif; izin `ProductPolicy::update` ditegakkan server; log aktivitas di transaksi yang sama; tidak ada masukan klien yang dipercaya untuk uang. |
| V. Performa | LULUS | Eager-load `excludedModifierGroups` di jalur `with_modifiers` dan daftar Produk; tidak ada N+1. |
| Dokumentasi | AKAN DIPENUHI | `docs/openapi-pos-mvp.yaml` berubah di commit yang sama (bentuk permintaan/respons berubah). Migrasi memakai awalan tanggal baru di akhir urutan. |

Tidak ada pelanggaran. Pasca-desain: tinjauan ulang tetap LULUS.

## Project Structure

### Documentation (this feature)

```text
specs/086-item-opt-out-category-modifier/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/modifier-links-api.md
├── checklists/requirements.md
└── tasks.md            # dibuat /speckit-tasks
```

### Source Code (repository root)

```text
database/migrations/2026_12_xx_create_modifier_group_product_exclusions.php   # baru (awalan tanggal terakhir)
app/Models/Product.php                                  # + excludedModifierGroups()
app/Services/ModifierResolver.php                       # + inheritedGroups(); effectiveGroups() memakainya
app/Services/ModifierLinkService.php                    # replace()/applyLinks(): excluded_group_ids opsional, validasi, audit
app/Services/Release/ModifierLinkApplier.php            # meneruskan excluded_group_ids
app/Services/ProductDetailService.php                   # links() memakai helper; + modifier_excluded
app/Services/ProductCatalogQuery.php                    # nama grup di daftar memakai helper; eager-load
app/Http/Controllers/Api/ProductController.php          # eager-load pengecualian (with_modifiers)
app/Http/Requests/ModifierLinksRequest.php              # + excluded_group_ids
docs/openapi-pos-mvp.yaml                               # permintaan + respons detail

resources/js/components/product/InheritedModifierList.vue   # baru: Lepas / Dilepas untuk item ini / Pulihkan
resources/js/components/product/ModifierLinksDrawer.vue     # memakai komponen + kirim excluded_group_ids
resources/js/views/products/ProductFormDrawer.vue           # idem + deteksi perubahan
resources/js/api/products.js                                # saveModifierLinks(id, links, excluded?)
resources/js/locales/{id,en}.json                           # teks baru

tests/Feature/ModifierExclusionTest.php                 # baru
qa-tests/component/InheritedModifierList.test.js        # baru
```

**Structure Decision**: web application; semua perubahan mengikuti struktur yang ada. Android tidak diubah.

## Fase

### Fase 1 — Data dan aturan server (P1, MVP)
Migrasi, relasi, `inheritedGroups()`, tiga pembaca memakainya, penulisan lewat `ModifierLinkService` (termasuk jalur rilis), permintaan, audit, OpenAPI. Tes server untuk semua Acceptance Scenario US1/US3 dan Edge Case.

### Fase 2 — UI (P1 + P2 + P3)
Komponen bersama, dipasang di laci dan formulir, deteksi perubahan, teks dua bahasa, gerbang izin.

### Fase 3 — Verifikasi
Suite penuh, lalu peramban nyata (kedua permukaan, tiga peran) dan POS web; tablet bila ada.

## Risiko

- Penafsiran permintaan (fitur, bukan galat) adalah ASSUMPTION di spec.
- Pembaca keempat grup kategori yang belum ditemukan (mis. di ekspor atau Android): ditelusuri ulang di tahap tugas lewat pencarian `category.modifierGroups` dan `modifier_group_category`.
- Tampilan peramban dan tablet belum diverifikasi.
