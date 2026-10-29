# Contract: `GET /preorders/export` — kolom

Hanya owner/admin (tidak berubah). Filter dan izin tidak berubah. Respons: berkas `.xlsx`, satu baris per pre-order.

Urutan kolom (judul = kunci):

```
customer_name, event_name, fulfillment, pickup_day,
products, variant_names,            ← BARU, hanya-ekspor
quantities, unit_prices,
shipping_cost, courier_name, expected_date, discount, notes,
dispatch_status, invoice_sent_at, shipping_at,
created_at, updated_at              ← hanya-ekspor
```

`variant_names`:
- teks; nama varian ke-i sejajar dengan SKU ke-i pada `products`, dipisah ` | `;
- nama kosong → bagian kosong; tidak ada item → sel kosong;
- selalu bertipe teks di Excel (tidak pernah rumus);
- diabaikan oleh `POST /preorders/import`; tidak ada di `GET /preorders/import/template`.

Kompatibilitas: berkas lama tanpa kolom ini tetap diimpor; berkas baru diimpor tanpa galat baru.
