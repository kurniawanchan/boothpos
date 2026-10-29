# Contract: Pengaturan Pajak (083)

Tidak ada rute baru. Tiga rute yang sudah ada berubah bentuknya. `docs/openapi-pos-mvp.yaml` harus ikut di commit yang sama.

## PUT /api/v1/settings (owner/admin, `SettingPolicy`)

Bentuk bulk yang sudah ada. Kunci baru yang divalidasi:

```json
{ "settings": [
  { "key": "tax_rate",  "value": "10",    "type": "decimal", "group": "pos" },
  { "key": "tax_label", "value": "PPN",   "type": "string",  "group": "pos" },
  { "key": "tax_show",  "value": true,    "type": "boolean", "group": "pos" }
] }
```

| Kunci | Aturan | Galat |
|---|---|---|
| `tax_rate` | numerik, 0 ≤ nilai ≤ 100, maks 2 desimal | 422 pada `settings.N.value` |
| `tax_label` | string, maks 30 karakter (setelah dipangkas) | 422 |
| `tax_show` | boolean (`true`/`false`/`1`/`0`) | 422 |

Peran lain: 403. Tiap kunci tercatat di log aktivitas dalam transaksi yang sama.

## GET /api/v1/settings/features (semua peran login)

Field baru (field lama `tax_rate`, `tax_label` tidak berubah):

```json
{ "tax_rate": 10, "tax_label": "PPN", "tax_show": true }
```

## GET /api/v1/orders/{order}/receipt

Field baru, field lama (`tax_amount`, `tax_rate`, `tax_label`, `total_amount`) tetap dikirim apa adanya:

```json
{ "tax_show": true, "tax_amount": "700.00", "tax_rate": "10.00", "tax_label": "PPN", "total_amount": "7700.00" }
```

`tax_show` bernilai `false` TIDAK menghapus field pajak dan TIDAK mengubah `total_amount`; klien hanya menyembunyikan baris.

## Detail order untuk Penjualan

`OrderResource` / `TabResource` sudah mengirim `tax_amount` dan `tax_rate`. Tidak berubah. Klien membaca `tax_show` dari `features`.

## Kompatibilitas

Klien lama mengabaikan `tax_show` dan tetap menampilkan baris pajak seperti sekarang. Itu diterima dan dicatat (research D5).
