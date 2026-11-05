**Classification: INTERNAL**

# Data Model: Sales Export Sheet

No tables, columns or migrations change. This describes the exported sheet.

## Columns (in order)

| # | Header | Source | Notes |
|---|---|---|---|
| 1 | `transaction_no` | `orders.order_number` | The grand total row shows the label here |
| 2 | `status` | `orders.status` | |
| 3 | `time` | `orders.created_at` | |
| 4 | `sellers` | distinct item seller names | |
| 5 | `cashier` | `orders.user_id` name | |
| 6 | `items` | order items | **Multi-line**, `• name ×qty (category)` per line |
| 7 | `units` | Σ item qty (seller share if filtered) | |
| 8 | `net_sales` | Σ line_total (seller share if filtered) | Number. Grand total sums this column |
| 9 | `payment_methods` | payments' methods | |
| 10 | `qr_type` | channel provider of non-rejected `qr_ewallet` payments | **New.** Empty when none |
| 11 | `payment_status` | derived unpaid/partially_paid/fully_paid | |
| 12 | `verification` | worst verification, non-cash only | |
| 13 | `cash` | cash received minus change | |
| 14 | `non_cash` | non-cash payments | |
| 15 | `total` | `orders.total_amount` | |
| 16 | `balance` | remaining to pay | |
| 17 | `payment_note` | non-rejected payments' notes | **New.** Multi-line `method: note` |
| 18 | `reference_note` | sale reference note | Unchanged |
| 19 | `payment_proofs` | viewable proof labels | Unchanged |
| 20+ | `reference_image_N`, `payment_proof_image_N` | embedded pictures | Unchanged |

Removed from the file: `customer`, `gross_sales`, `discount`, `cost`, `margin`, `paid`.

## Rows

- One row per sale, as before (same filters, same selection by keys, voided only when asked).
- Plus, when there is at least one row, ONE last "Grand total" row (added by the export class): label in `transaction_no`, the sum in `net_sales`, every other cell empty, bold. Voided sales are not summed.

## Relations read (all read-only)

- `order → items → variant → product → category` (all including soft-deleted, scopes lifted; label only)
- `order → payments → channel` (including soft-deleted; provider only)

## Rules

- A payment with `verification = 'rejected'` contributes neither QR type nor note.
- A QR payment with no channel contributes no QR type.
- Text starting with `=` is written as plain text (existing protection).
