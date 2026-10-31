**Klasifikasi**: INTERNAL

# Data Model: Sel Satu Baris di Daftar Pre-order (053)

**Tidak ada perubahan skema, migrasi, model, atau respons API.** Semua field yang dipakai sudah dikirim oleh
`GET /preorders` (baris daftar) sejak fitur 025.

## Field baris daftar yang dibaca (tidak berubah)

| Field | Tipe | Dipakai untuk |
|---|---|---|
| `preorder_number` | string | Sel Nomor (satu baris) |
| `dispatch_status` | `pending` \| `invoice_sent` \| `shipping` \| null | Label `StatusPill` (null = `pending`) |
| `invoice_sent_at` | ISO 8601 \| null | Baris tooltip "Invoice terkirim: …" |
| `shipping_at` | ISO 8601 \| null | Baris tooltip "Pengiriman mulai: …" |
| `total_amount` | string uang | Sel Total (satu baris) |

## Nilai turunan di tampilan

`dispatchDatesLines(row)` → array of string

- Kumpulkan, berurutan: `t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(invoice_sent_at) })` bila
  `invoice_sent_at` ada; lalu `t('preorders.dispatch_shipping_on', { date: formatDateTime(shipping_at) })` bila
  `shipping_at` ada.
- Kosong (`[]`) bila tidak ada keduanya. Tooltip = gabung `\n`; `aria-label` = gabung `. `.
- Ikon info tampil ⇔ array tidak kosong (bergantung pada TANGGAL, bukan pada `dispatch_status`, supaya data lama/impor yang
  hanya punya `shipping_at` tetap tampil — edge case spec).
