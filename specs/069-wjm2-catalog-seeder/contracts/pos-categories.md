# Kontrak API: `GET /api/v1/pos/categories` (perubahan, research D10)

**Klasifikasi**: INTERNAL

## Sebelum

Semua kategori aktif di mode aktif, dengan `product_count` = jumlah produk aktif di semua outlet.

## Sesudah

Outlet acuan = `OutletContext::orderingId()` (outlet shift pengguna, atau satu-satunya outlet). Aturan ini sama dengan `GET /products?pos=1`.

| `orderingId()` | Isi `data` |
|---|---|
| `null` | sama dengan sebelumnya |
| outlet X | kategori aktif, **kecuali** yang punya produk tetapi seluruhnya tidak dijual di X (ada baris `outlet_item_settings` dengan `is_carried = false` untuk X pada semua produknya). Kategori yang sama sekali belum punya produk tetap tampil dengan `product_count` 0 (perilaku 055). `product_count` = produk aktif yang dijual di X |

**Penyempurnaan saat implementasi (2026-10-25).** Rencana awal: tampilkan hanya kategori yang punya ≥1 produk dijual di X. Tes 055 `test_a_category_without_sellable_products_is_kept_with_count_zero` dan teks OpenAPI 055 menetapkan bahwa kategori tanpa produk TETAP tampil, karena tab POS berasal dari server dan bukan dari produk. Aturan di atas menyembunyikan tab kosong milik outlet lain tanpa melanggar keputusan 055.

Bentuk respons tidak berubah:

```json
{ "data": [ { "id": 7, "code": "CO", "name": "Coffee", "display_order": 1, "product_count": 22 } ] }
```

- Otorisasi tidak berubah (`viewAny` Product).
- Klien: Android (`RemoteBackend.menu()`), tanpa perubahan kode. Tab kosong dari outlet lain hilang dengan sendirinya.
- `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama (deskripsi filter outlet dan arti `product_count`).
- Tes: kategori yang hanya berisi item outlet lain tidak muncul untuk kasir yang shift-nya di outlet X; kategori bersama muncul dengan `product_count` per outlet; tanpa shift dan dengan satu outlet, hasilnya sama dengan sebelumnya.
