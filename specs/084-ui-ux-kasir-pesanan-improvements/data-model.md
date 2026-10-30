# Data Model: 084

Label: INTERNAL. Tidak ada tabel baru dan tidak ada migrasi skema. Semua kolom yang dipakai sudah ada.

## Yang sudah ada dan dipakai

| Entitas | Kolom/atribut | Catatan |
|---------|---------------|---------|
| `order_items` | `modifiers` (JSON snapshot: `option`, `group`, `price_delta`, …), `sell_price`, `note` | Ditulis `ModifierResolver` snapshot. Penjualan web kini ikut menulisnya (D2). |
| `orders` | `table_id`, `service_type`, `status`, `session_id`, `outlet_id` | `table_id` boleh berubah pada tab terbuka (sudah) dan pesanan selesai (baru, D8). |
| `payments` | `verification` (`pending`/`verified`/…), `channel` | Kanal QRIS dicatat `verified` sejak dibuat (D9). Baris lama `pending` tetap sah. |
| `dining_tables` | `is_active`, `outlet_id` | Syarat tujuan: aktif dan satu outlet; bebas hanya untuk pesanan terbuka. |
| `cashier_sessions` | `opened_at`, status | Jam "Shift dibuka {jam}". |

## Model sisi klien (Android)

- `PrinterStatus` + `Connecting` (state, tanpa persistensi).
- `Order.label`: counter → `"Kasir {queue}"`; dine-in tetap `"Meja {table}"`.
- `ReceiptView`/`Order` membawa informasi jumlah varian produk untuk aturan "Standard" (D4), atau aturan dijalankan di mapper.
- `CountInput`: tidak berubah bentuknya (`typed` tetap digit murni); ditambah fungsi murni pemetaan offset tampilan↔digit.

## Transisi status

- Pembayaran QRIS: (dibuat) → `verified` (baru). Jalur lama `pending → verified/failed` hanya untuk baris warisan.
- Pesanan: tidak ada transisi baru; hanya atribut `table_id` yang boleh diubah setelah `selesai`, dan perubahan itu tercatat di log aktivitas.

## Aturan validasi
- Meja tujuan: ada, aktif, satu outlet dengan pesanan (`outlet_mismatch` → 409), bebas untuk pesanan terbuka (`table_occupied` → 409). Pelanggaran bentuk → 422.
- `modifier_option_ids`: divalidasi `ModifierResolver` (grup wajib/min/maks, opsi aktif, milik produk); pelanggaran → 422 `InputRejectedException` seperti di Tab.
