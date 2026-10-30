# Kontrak API yang berubah — 084

Label: INTERNAL. `docs/openapi-pos-mvp.yaml` WAJIB ikut berubah di commit yang sama (Konstitusi, Documentation & Change Discipline). Kode status mengikuti konvensi: 422 bentuk, 409 aturan bisnis, 403 peran.

## 1. `POST /api/v1/orders` — modifier per item (web PosView)

Request, tambahan opsional per item:

```json
{
  "items": [
    { "variant_id": 12, "qty": 2, "modifier_option_ids": [5, 9] }
  ]
}
```

- `items.*.modifier_option_ids`: array integer, opsional, `exists:modifier_options,id`. Harga TIDAK dikirim klien.
- Server: `ModifierResolver::resolve($variant, ids)` → `sell_price` baris = harga dasar + delta; `order_items.modifiers` = snapshot.
- Respons: `items[].modifiers` (sudah ada di `OrderResource`) kini terisi untuk penjualan POS.
- Error: 422 bila grup wajib tak terpenuhi / opsi tak sah / melebihi maksimum.
- Kompatibilitas: klien tanpa field ini berperilaku persis seperti sebelumnya.

## 2. `POST /api/v1/tabs/{order}/move` — juga untuk pesanan selesai

- Sebelumnya: hanya tab terbuka (selain itu 409 tab tidak terbuka).
- Sekarang: pesanan berstatus selesai juga diterima; hanya `table_id` yang berubah (tanpa cek meja bebas, tanpa mengubah `service_type`); dibatalkan/void tetap ditolak 409.
- Tetap: `table_id` wajib (422); meja nonaktif (422); beda outlet (409 `outlet_mismatch`); meja terisi untuk pesanan terbuka (409 `table_occupied`).
- Log aktivitas: `table_changed` (tanpa data sensitif) di transaksi yang sama.
- Otorisasi: peran/keanggotaan outlet yang sama dengan `move` sekarang (diperiksa di tiga mekanisme: FormRequest `MoveTabRequest`, kontroler, kebijakan).

## 3. Pembayaran QRIS — langsung terverifikasi

- `POST /api/v1/tabs/{order}/payments` dengan kanal QRIS: respons `verification: "verified"` (sebelumnya `"pending"`).
- `POST .../payments/{payment}/verify` dan `.../fail` tetap ada untuk baris lama yang `pending`.
- `POST /sessions/{session}/close`: aturan `pending_payments` tetap, namun tak lagi terpicu oleh pembayaran baru.

## 4. `GET /api/v1/orders?session_id=` — daftar Selesai dibatasi shift (Android)

- Tidak ada perubahan server: `GET /orders` SUDAH mendukung `session_id`. Android kini mengirim shift terbukanya sehingga pesanan
  shift yang sudah ditutup tidak tampil; tanpa shift terbuka daftar kosong dan server tidak ditanya.
- (Rencana awal menyebut `GET /tabs?status=closed`; itu keliru — `/tabs` hanya memuat tab terbuka.)

## 5. `GET /api/v1/variants/lookup` — modifier dan produk (web POS)

- Respons menambah `product_id`; `modifier_groups` ikut hanya bila `with_modifiers=1` (opt-in, Konstitusi V), bentuk sama dengan
  `GET /products?with_modifiers=1` (`ModifierResolver::present`).

## 6. Nama baris order (`name_snapshot`)

- Order BARU: produk yang hanya punya satu varian aktif bernama "Standard" memakai nama produk saja (`ProductVariant::lineName()`).
  Snapshot lama tidak ditulis ulang.

Tidak ada endpoint baru.
