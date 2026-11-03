# Implementation Plan: Resep Memotong Stok, Resep Ganda per Varian, dan Jumlah Pilihan Modifier

**Klasifikasi**: INTERNAL

**Branch**: `089-recipe-multi-modifier-qty` | **Date**: 2026-11-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/089-recipe-multi-modifier-qty/spec.md`

## Summary

Tiga perubahan di atas buku besar bahan fitur 087, tanpa jalur tulis stok baru:

1. **Resep memotong stok.** Penerjemah "baris pesanan → bahan" yang sudah ada (`ModifierMaterialConsumption::needFor`) diperluas: selain bahan pilihan modifier, ia membaca SEMUA resep aktif varian baris itu dan menambah kebutuhan `jumlah ÷ (1 − susut%) ÷ Portions × qty`. Titik panggil (selesai, void/refund, buang) tidak berubah; void/refund tetap membaca buku besar.
2. **Resep ganda tanpa versi.** Tabel `recipes` kehilangan nomor versi; satu varian boleh tertaut ke banyak resep aktif, biaya porsi varian (`cost_price`) = jumlah biaya semua resep aktifnya. Resep diubah di tempat (`PUT`), dihapus satu per satu. Data lama dimigrasikan tanpa kehilangan riwayat.
3. **Modifier.** (a) Perbaiki aturan `distinct` yang salah cakupan (lintas pilihan) menjadi per pilihan. (b) Pilihan punya `min_qty`/`max_qty`; pesanan membawa jumlah per pilihan lewat `modifier_selections` (id lama tetap diterima). Harga, bahan, struk, KOT, dan tablet ikut jumlah.

Label "Yield" → "Portions" hanyalah perubahan teks (kunci API `yield` tidak berubah).

## Technical Context

**Language/Version**: PHP 8.x / Laravel 13 (backend); Vue 3 + Pinia + Vite, Tailwind v4 (web); Kotlin + Jetpack Compose (`android-pos/`)

**Primary Dependencies**: yang sudah ada saja; tidak ada paket baru

**Storage**: MySQL 8 (wajib; CHECK constraint). Migrasi baru: `recipes` (hapus `version`, tambah `name`, `archived_at`), `recipe_variants` (tetap), `modifier_options` (tambah `min_qty`, `max_qty`); snapshot JSON `order_items.modifiers` mendapat kunci `qty`

**Testing**: PHPUnit di `tests/Feature/` (MySQL nyata, `warkop_wjm_test`); Vitest di `qa-tests/`; JUnit di `android-pos/app/src/test`. Tes kontainer WAJIB memakai `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test` dan `-e CACHE_STORE=array -e SESSION_DRIVER=array` (CLAUDE.md)

**Target Platform**: server Laravel + SPA di satu mesin/server per organisasi; tablet Android (APK terpisah, bisa lebih lama dari server)

**Project Type**: web-service + SPA + aplikasi mobile

**Performance Goals**: penyelesaian pesanan tidak menambah kueri per baris — resep aktif semua varian pesanan dimuat dengan SATU kueri berisi `lines.material` (tanpa N+1, Konstitusi V)

**Constraints**: klien lama (APK, tab web terbuka) tanpa `modifier_selections` harus tetap bisa memesan; server adalah satu-satunya penghitung harga dan potongan stok; migrasi tidak boleh menghapus riwayat resep

**Scale/Scope**: puluhan resep per outlet, satu sampai belasan baris bahan per resep; pesanan dengan puluhan baris

## Constitution Check

*GATE: lulus sebelum Phase 0; dicek ulang setelah Phase 1 (hasil: lulus, tanpa pelanggaran).*

| Prinsip | Penilaian |
|---|---|
| **I. Kode bersih / satu jalur tulis** | Lulus. Tidak ada penulis stok baru: potongan tetap `MaterialStockService::applyMovement()` lewat `ModifierMaterialConsumption`. Logika resep di `RecipeService`, logika jumlah pilihan di `ModifierResolver`; controller hanya memvalidasi dan mendelegasi. Nama kelas `ModifierMaterialConsumption` dipertahankan (tidak diganti nama, agar riwayat dan tes 087 utuh) dengan komentar yang menyebut kini ia juga membaca resep. |
| **II. Pengujian** | Lulus dengan syarat: tes backend + Vitest + JUnit tertulis di tasks; layar web (Resep, editor modifier, kasir) dan tablet WAJIB dicoba di peramban/perangkat nyata. Sebelum itu status "belum diverifikasi" dicatat di `evidence.md` (aturan yang dipakai fitur 084–088). |
| **III. Konsistensi UX** | Lulus. Token desain yang ada, teks UI Indonesia (en.json/id.json keduanya diperbarui), galat 422/409/403 lewat penangan pusat. Kontrol resep tetap tersembunyi bagi peran tanpa izin `menu.recipe.edit`. |
| **IV. Keamanan** | Lulus. Jumlah pilihan dan harga dihitung ulang server dan batasnya diperiksa server; stok tidak pernah dari klien. Potret `qty` + `price_delta` disimpan di baris pesanan (immutable). Perubahan/hapus resep dan perubahan pengaturan jumlah pilihan menulis log aktivitas di dalam transaksi yang sama. Outlet pemotongan tetap dari pesanan di server. |
| **V. Performa** | Lulus. Resep aktif dimuat sekaligus (research D8). Tidak ada cache baru. |
| **Dokumentasi** | `docs/openapi-pos-mvp.yaml` diubah di commit yang sama dengan rute/respons; catatan bertanggal di PRD dan CLAUDE.md. |
| **Lingkungan** | MySQL nyata; migrasi ke DB dev hanya dengan persetujuan eksplisit pengembang (tidak dijalankan otomatis). |

## Project Structure

### Documentation (this feature)

```text
specs/089-recipe-multi-modifier-qty/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-changes.md
├── checklists/requirements.md
└── tasks.md            # dibuat /speckit-tasks, BUKAN oleh perintah ini
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── RecipeService.php                  # tanpa versi; banyak resep aktif; biaya = jumlah; update/hapus satuan
│   ├── ModifierMaterialConsumption.php    # needFor() + kebutuhan resep; qty pilihan
│   ├── ModifierResolver.php               # modifier_selections, min_qty/max_qty, harga × qty, porsi per satuan
│   ├── ModifierGroupService.php           # simpan min_qty/max_qty + log; aturan distinct per pilihan
│   ├── MaterialReport.php                 # "tak tercatat" kini hanya BOM (resep sudah memotong)
│   ├── ProductDetailService.php           # ringkasan = semua resep aktif
│   ├── OrderService.php / TabService.php  # terima modifier_selections
├── Support/ModifierSnapshot.php           # tampil: qty
├── Http/Controllers/Api/RecipeController.php   # PUT /recipes/{id}, DELETE /recipes/{id}
├── Http/Requests/{StoreRecipeRequest,UpdateRecipeRequest,StoreModifierGroupRequest,
│                  StoreOrderRequest,AddTabLineRequest,UpdateTabLineRequest}.php
├── Models/{Recipe,ModifierOption}.php
database/migrations/                       # tanggal BARU setelah migrasi terakhir; jangan ubah awalan yang ada
lang/{id,en}/{products,vendors_materials,restaurant}.php
routes/api.php
docs/openapi-pos-mvp.yaml
resources/js/
├── components/product/{RecipeSection,RecipeDrawer}.vue    # daftar resep + ubah satuan; label Portions
├── components/restaurant/ModifierPickerModal.vue          # pemilih jumlah
├── stores/posCart.js, views/{PosView,TabView}.vue          # kirim modifier_selections
├── utils/recipeCost.js, utils/modifierRules.js            # jumlah; porsi per satuan
├── locales/{en,id}.json
└── (editor grup modifier)                                  # min/maks jumlah, cek duplikat per pilihan
android-pos/app/src/main/java/com/warkop/pos/
├── model/{ModifierPricing,PosModels}.kt, data/{MenuMapper,RemoteBackend}.kt, ui/Sheets.kt
├── model/KitchenPrint.kt + struk (EscPos) # "×N"
tests/Feature/            qa-tests/            android-pos/app/src/test/
```

**Structure Decision**: web application + mobile app dengan struktur yang sudah ada; tidak ada modul baru, hanya perluasan service yang sudah menjadi satu-satunya pemilik tiap aturan.

## Phase 0 — Research

Lihat [research.md](./research.md): D1–D12 (tanpa sisa NEEDS CLARIFICATION).

## Phase 1 — Design & Contracts

- [data-model.md](./data-model.md) — skema, migrasi data lama, bentuk snapshot.
- [contracts/api-changes.md](./contracts/api-changes.md) — rute dan bentuk respons yang berubah.
- [quickstart.md](./quickstart.md) — skenario verifikasi manual dan perintah tes.

## Risiko dan catatan

- **APK lama + `min_qty` > 1**: APK lama hanya mengirim id (qty 1); bila pemilik mengatur minimum >1 pada pilihan, pesanan dari APK lama ditolak 422 dengan pesan yang menyebut grup. Pilihan bermaksimum >1 tetap bisa dipilih satu kali dari APK lama. Dicatat di quickstart dan `evidence.md`.
- **Migrasi data resep** mengubah apa yang dipotong untuk varian lama (resep yang tadinya hanya HPP kini memotong stok). Ini perilaku yang diminta, tetapi saldo bahan akan mulai turun setelah rilis; diumumkan di catatan rilis.
- **Verifikasi nyata** (peramban, tablet) tidak bisa dilakukan oleh agen; ditandai belum terverifikasi sampai manusia melakukannya.

## Complexity Tracking

Tidak ada pelanggaran Konstitusi yang perlu dibenarkan.
