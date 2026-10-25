# Kontrak API: 070

**Klasifikasi**: INTERNAL

Perubahan tambahan-saja (kompatibel mundur). `docs/openapi-pos-mvp.yaml` diperbarui dalam komit yang sama.

## GET /products/summary

Kini menerima filter himpunan tersaring (semua opsional, bentuk sama dengan `GET /products?tab=…`):

| Parameter | Tipe | Catatan |
|---|---|---|
| `outlet_id[]` | integer[] | dipotong dengan outlet yang diizinkan; kosong/tanpa = semua outlet yang diizinkan |
| `category_id[]` | integer[] | termasuk kategori anak |
| `status[]` | `draft`\|`active`\|`discontinued` | |
| `q` | string ≤ 100 | nama, nama pendek, kode, SKU varian |

`quality` dan `availability` TIDAK dihormati di sini (D3). Bentuk respons tidak berubah (`active`, `sold_out_today`, `quality`, `unreleased`, `computed_at`); `active.total` = jumlah item menu dalam himpunan tersaring. Nilai tak valid → 422.

## GET /products?tab=…

- `counts.menu` dan `counts.retail` kini mengikuti `outlet_id[]` (jenis lain tidak berubah). Tanpa filter: sama seperti sebelumnya.
- Filter lain tidak berubah. Klien halaman Produk tidak mengirim `X-Outlet-Id` pada panggilan ini (D1); server tetap memotong dengan outlet yang diizinkan.

## POST /products

Tambahan pada `variants.*`:

```json
{
  "variants": [
    {
      "variant_name": "Hot", "sell_price": 15000,
      "outlet_prices": [{ "outlet_id": 2, "price": 17000 }]
    }
  ],
  "outlet_ids": [1, 2]
}
```

| Aturan | Hasil |
|---|---|
| `outlet_prices.*.outlet_id` tidak diizinkan | 422 (`outlets.forbidden`) |
| `outlet_id` tidak termasuk `outlet_ids` | 422 |
| `outlet_id` ganda pada satu varian | 422 |
| `price` bukan angka ≥ 0 | 422 |
| `outlet_ids` dikirim kosong | 422 |
| Gagal di tengah | seluruh item dibatalkan (satu transaksi), 422/500 tanpa item setengah jadi |

Respons 201 tidak berubah bentuk. Harga outlet tertulis langsung (`price_histories.source = 'created'`), juga saat rilis menu aktif (item masih draft).
