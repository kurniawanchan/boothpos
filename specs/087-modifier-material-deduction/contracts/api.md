# Kontrak API: 087

**Klasifikasi**: CONFIDENTIAL

Semua di bawah `/api/v1`, token Sanctum, header `X-Outlet-Id` mengikuti aturan yang sudah ada. Uang = string 2 desimal; jumlah bahan = string 4 desimal (pemetaan) atau 3 desimal (saldo/pergerakan). `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama.

## 1. Grup modifier (diubah, aditif)

`POST /modifier-groups`, `PUT /modifier-groups/{modifierGroup}`: gerbang tetap menu `dining_setup`.

```json
{
  "name": "Topping",
  "selection_type": "multiple",
  "options": [
    { "id": 31, "name": "Telur", "price_delta": 3000,
      "materials": [ { "material_id": 7, "quantity": 1 } ] },
    { "id": 32, "name": "Sosis", "price_delta": 4000 },
    { "name": "Extra Pedas", "price_delta": 0,
      "materials": [ { "material_id": 12, "quantity": 2 }, { "material_id": 13, "quantity": 15 } ] }
  ]
}
```

- `options[].materials` ada (termasuk `[]`) → penggantian penuh pemetaan pilihan itu. Tidak ada → pemetaan tidak berubah.
- 422: `options.2.materials.1.material_id` (tidak ada / nonaktif / terhapus / beda mode / duplikat), `options.0.materials.0.quantity` (≤ 0, bukan angka).

Respons (`ModifierGroupResource`, juga `GET /modifier-groups[/{id}]`):

```json
"options": [
  { "id": 31, "name": "Telur", "price_delta": "3000.00", "display_order": 0, "is_active": true,
    "materials": [
      { "material_id": 7, "code": "BHN-007", "name": "Telur", "unit": "butir",
        "quantity": "1.0000", "material_active": true }
    ] }
]
```

`materials` selalu hadir pada setiap opsi saat `options` dimuat (array kosong bila tanpa pemetaan).

## 2. Bahan (diubah, aditif)

`GET /materials/{material}` (kebijakan `view`) menambah:

```json
"used_by_modifier_options": [
  { "option_id": 31, "option": "Telur", "option_active": true,
    "group_id": 4, "group": "Topping", "quantity": "1.0000" }
]
```

`GET /materials?stock_status=negative`: hanya bahan dengan saldo < 0 pada outlet yang berlaku. `outlet_stock[].on_hand` kini bisa negatif (mis. `"-1.000"`).

`DELETE /materials/{material}`: **409** baru bila dipakai pemetaan pilihan aktif:

```json
{ "message": "Bahan masih dipakai pilihan modifier (Topping → Telur). Lepaskan dulu dari grup modifier." }
```

## 3. Riwayat bahan (baru, P3)

`GET /materials/{material}/movements?type[]=sale&type[]=return&type[]=waste&outlet_id[]=1&from=2026-10-01&to=2026-10-31&page=1&per_page=25`

Gerbang: sama dengan `POST /materials/{material}/adjustments`. Outlet dibatasi `OutletFilter` (outlet di luar milik pengguna → tidak terlihat).

```json
{
  "data": [
    { "id": 901, "type": "sale", "outlet_id": 1, "outlet_code": "WJM3",
      "qty_change": "-2.000", "stock_before": "1.000", "stock_after": "-1.000",
      "reference": { "type": "order_item", "id": 5521, "order_id": 1880, "order_number": "ORD-20261031-0042" },
      "user": { "id": 3, "name": "kasir01" }, "created_at": "2026-10-31T06:12:44Z" }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1 }
}
```

`reference.order_id`/`order_number` = `null` bila baris sudah dihapus (batal "buang").

## 4. Penyelesaian pesanan (diubah, aditif)

`POST /orders` (checkout ritel), `POST /tabs/{order}/payments` (settle), `POST /tabs/{order}/split`:

- Permintaan: **tidak berubah**.
- Status code: **tidak berubah**. Stok bahan tidak pernah menyebabkan 409/422 (FR-014 = izinkan minus).
- Respons: bidang tingkat atas opsional, hanya bila ada bahan yang menjadi minus:

```json
"material_warnings": [
  { "material_id": 7, "material": "Telur", "unit": "butir", "outlet_id": 1,
    "stock_before": "1.000", "required": "2.000", "stock_after": "-1.000" }
]
```

`POST /tabs/{order}/items` dan perubahan baris tab lain: **tidak berubah** (tidak menyentuh bahan).

## 5. Pembatalan / void / refund

Tidak ada perubahan kontrak. Efek samping baru:

| Rute | Efek bahan |
|---|---|
| `DELETE /tabs/{order}/items/{item}` dengan `stock_decision=waste` (`routes/api.php:387`) | `waste` untuk baris itu |
| `stock_decision=return` | tidak ada |
| `POST /tabs/{order}/void`, `POST /tabs/{order}/cancel` | `waste` untuk baris yang sudah dimasak |
| `POST /orders/{order}/void`, `POST /orders/{order}/refund` | `return` sebesar potongan bersih per baris |

## 6. Sumber stok produk (US6, 2026-10-31)

`POST /products`, `PUT /products/{id}`: bidang opsional `stock_source` (`variant` | `modifier`; bawaan `variant` saat membuat). 422 untuk nilai lain. Respons ubah boleh membawa `warnings` aditif:

```json
"warnings": [
  { "code": "variant_stock_remaining", "total": "12" },
  { "code": "no_material_mappings" }
]
```

Respons produk (daftar/detail pengelolaan): `stock_source`, dan per varian `track_stock` (mentah) + `stock_tracked` (efektif).

Payload POS (`GET /products?pos=1`, `GET /variants/lookup`): per varian `track_stock` = EFEKTIF (false untuk produk `modifier`), sehingga web dan Android menampilkan tanpa stok dan tidak pernah "habis" tanpa perubahan klien.

| Rute | Perubahan untuk produk `modifier` |
|---|---|
| `POST /stock/adjustments` | 409 `{ code: "stock_source_modifier" }` untuk varian produk itu |
| `POST /stock-transfers` (baris varian) | 422 per baris, sama dengan varian tak dilacak |
| `GET /stock/low`, `GET /stock/balances`, `GET /reports/stock-by-outlet` | varian produk itu tidak tercantum |
| Ekspor/impor master data `products` | kolom `stock_source` |

