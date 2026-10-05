# Data Model: Seller Recap Shows POS Transactions Only

No schema change, no migration.

## `artist_settlements` (existing, `HasDataMode`-scoped via its event/artist rows)

| Column | Before (033) | After (040) |
|---|---|---|
| `total_sales` | POS sales + paid portion of non-cancelled pre-orders | POS sales only (`order_items.line_total` of `completed` orders of the event, active data mode) |
| `total_units` | `round(POS units + prorated pre-order units)` | POS units (integer sum of `order_items.qty`) |
| `payable_amount` | `total_sales − deduction` | `total_sales − deduction` (unchanged formula; no screen sets `deduction`) |
| `paid_amount`, `paid_at` | recorded payments | **untouched** |
| `status` | `unpaid`/`partial`/`paid` from paid vs payable | same derivation (`paid <= 0` → unpaid; `paid < payable` → partial; else paid) |

Rewritten on every read of the recap and on event close (existing behaviour); rows with no counted sale are first reset to 0.

## Derived response row (`GET /reports/artist-settlements` → `data[]`)

| Field | Rule |
|---|---|
| `id` | settlement id or `null` for a seller without a row (unchanged) |
| `artist_id`, `artist_name` | unchanged (every active seller is listed) |
| `total_sales` | stored POS `total_sales`, 2-dp string |
| `total_units` | stored POS `total_units`, int |
| `deduction`, `payable_amount`, `paid_amount` | unchanged formulas |
| `outstanding` | **`max(0, payable − paid)`**, 2-dp string (was `payable − paid`, could be negative) |
| `status` | unchanged |
| ~~`pos_units`, `preorder_units`, `pos_sales`, `preorder_sales`~~ | **removed** |

## Detail transaction (`GET /reports/artist-settlements/{artist}/transactions` → `transactions[]`)

`{key: "order-<id>", number, created_at, items[{sku,name,qty,line_total}], amount_for_artist}` — POS only; `source` removed; `preorder-<id>` entries no longer produced. Σ `amount_for_artist` = the row's `total_sales`.

## Export (`GET /reports/artist-settlements/export`)

Sheet "Rekap" headings: `id, artist_id, artist_name, total_sales, total_units, deduction, payable_amount, paid_amount, outstanding, status` (the four 033 columns removed). Sheet "Detail Transaksi": unchanged (already POS only).
