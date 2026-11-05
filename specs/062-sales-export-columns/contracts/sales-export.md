**Classification: INTERNAL**

# Contract: `POST /api/v1/reports/sales/transactions/export`

Route, method, request body, authorization (`sales` menu), seller restriction, filters, image limit (422) and the binary `.xlsx` response are all unchanged. Only the sheet's columns change.

## Request (unchanged)

`event_id`, `date_from`, `date_to`, `include_voided`, `keys[]` (`order:<id>`), `sellers[]`.

## Response sheet

Columns, in order: `transaction_no, status, time, sellers, cashier, items, units, net_sales, payment_methods, qr_type, payment_status, verification, cash, non_cash, total, balance, payment_note, reference_note, payment_proofs`, then `reference_image_N` and `payment_proof_image_N`.

| Change | Detail |
|---|---|
| Removed | `customer`, `gross_sales`, `discount`, `cost`, `margin`, `paid` (all roles; the file never carries customer name, phone or email) |
| New `qr_type` | Providers of non-rejected QR payments, unique, comma separated; empty if none |
| New `payment_note` | One line per non-rejected payment note, `method: note`; empty if none |
| Changed `items` | One bulleted line per item: `• name ×qty (category)`; category omitted when unknown; lines separated by a newline; wrap text on |
| New last row | `Grand total` in `transaction_no`, numeric sum of the `net_sales` cells above in `net_sales`, other cells empty; excludes voided sales; absent when there are no rows |

## Compatibility notes

- Consumers that read the sheet by header name are unaffected, except for the removed headers.
- Consumers that read by column index must account for removed columns and the new ones.
- The grand total is a data row; code that treats every row as a sale must skip the row whose `transaction_no` equals the localised "Grand total" label (EN: `Grand total`, ID: see `lang/id/sales_export.php`).
- `GET /reports/sales` (the on-screen list) is unchanged and still returns customer, discount, paid, cost and margin fields as before.
