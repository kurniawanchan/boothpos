# Perubahan Kontrak API: 089

**Klasifikasi**: INTERNAL

Semua perubahan HARUS masuk `docs/openapi-pos-mvp.yaml` di commit yang sama (PRD §9.5). Kode status mengikuti konvensi: 422 bentuk/validasi, 409 aturan bisnis, 403 peran. Uang tetap string `number_format(…, 2, '.', '')`.

## Resep

Izin tidak berubah: baca = menu `products`; tulis = menu `products` + `menu.recipe.edit`.

| Rute | Perubahan |
|---|---|
| `GET /products/{product}/recipes` | Mengembalikan daftar resep (bukan versi). Item: `id, name, yield, effective_from, note, variants[], is_active_now, archived` (+ `portion_cost, incomplete, food_cost_pct` bagi pemegang izin biaya). **`version` dihapus.** Arsip hasil migrasi ikut dengan `archived: true`. |
| `GET /recipes/{recipe}` | Sama; `lines[]` tetap. `version` dihapus, `name`/`archived` ditambah. |
| `POST /products/{product}/recipes` | Membuat SATU resep baru; tidak lagi "versi baru". Tubuh: `name, yield, effective_from, variant_ids[], lines[], note?`. Tidak lagi menimpa resep lain. 409 `cost_source_conflict` bila varian ber-BOM lengkap. 201. |
| `PUT /recipes/{recipe}` **(baru)** | Mengubah resep di tempat, tubuh sama dengan POST. Mengubah resep arsip → 409 `recipe_archived`. 200 + bentuk `GET /recipes/{id}`. Menulis log `recipe_updated` (nilai lama/baru) dan menghitung ulang `cost_price` varian terdampak dalam transaksi yang sama. |
| `DELETE /recipes/{recipe}` **(baru)** | Menghapus SATU resep; isi tersimpan di log `recipe_removed`. Varian yang tidak lagi punya resep aktif berhenti digerakkan resep (harga modal terakhir dibiarkan). 200 `{message, removed: 1}`. |
| `DELETE /products/{product}/recipes` | Tetap: hapus SEMUA resep produk (087). Respons: `removed_versions` dipertahankan sebagai alias `removed` agar klien lama tidak patah. |
| `POST /products/{product}/recipes/recalculate` | Tetap; menghitung `cost_price` varian = Σ biaya semua resep aktif. `skipped[].reason` = `incomplete` bila salah satu resep tak lengkap. |

## Grup modifier

`POST /modifier-groups`, `PUT /modifier-groups/{id}`:

- `options[].min_qty` (int 1..50, opsional, default 1) dan `options[].max_qty` (int ≥ `min_qty`, ≤ 50, **nullable**; null = tanpa batas atas; opsional, default 1). 422 `options.{i}.max_qty` bila `max_qty < min_qty`.
- `options[].materials[].material_id`: **bukan lagi `distinct` lintas pilihan.** Kembar di dalam SATU pilihan → 422 dengan kunci `options.{i}.materials.{j}.material_id` dan pesan "Bahan yang sama tidak boleh dipilih dua kali untuk satu pilihan" (kini hanya muncul pada pilihan yang bersangkutan).
- Respons grup dan `GET /variants/lookup?with_modifiers=1` / `GET /products?pos=1`: tiap opsi membawa `min_qty` dan `max_qty` (satu bentuk lewat `ModifierResolver::present`). Perubahan `min_qty/max_qty` menulis log aktivitas pada transaksi yang sama.

## Pesanan dan tab

`POST /orders` (per item `items[]`), `POST /tabs/{tab}/lines`, `PUT /tabs/{tab}/lines/{line}`:

- `modifier_selections`: `[{ "option_id": int, "qty": int 1..50 }]`, opsional, maksimum 50 elemen.
- `modifier_option_ids` tetap diterima (tiap id = qty 1).
- Keduanya sekaligus → 422 pada `modifier_selections`. `option_id` kembar → 422.
- `qty` di luar `[min_qty, max_qty]` pilihan → 422 `modifier_selections` dengan pesan yang menyebut grup dan batasnya.

Respons baris/pesanan: `modifiers[]` menambah `qty` (int, ≥ 1; baris lama tanpa `qty` dikembalikan 1) dan `price_delta` = kontribusi total pilihan itu. Struk (`receipt`), tiket dapur (`TicketResource`), daftar transaksi, dan ekspor menampilkan `qty` dan teks "Telur ×2" bila `qty > 1`.

`material_warnings[]` (087) tidak berubah bentuk; kini juga dapat berisi bahan dari resep.

## Detail produk

`GET /products/{id}` → `recipe`: tetap berisi ringkasan resep PERTAMA (menurut nama) agar klien lama tidak patah, ditambah di dalamnya `recipes[]` (ringkasan SEMUA resep aktif varian pertama, tanpa `lines`) dan `recipe_count`. `recipe.version` dihapus; `recipe.name` ditambah. **ASSUMPTION**: tidak ada klien selain web yang membaca `recipe` (Android tidak memuat resep; diverifikasi T008: tidak ada kata "recipe" di `android-pos/app/src/main`).

## Laporan Bahan

`GET /reports/materials` dan `GET /dashboard/materials`: `usage_untracked` kini hanya benar untuk bahan yang HANYA ada di BOM; bahan di resep aktif dihitung memakai laju nyata. Bentuk respons tidak berubah.
