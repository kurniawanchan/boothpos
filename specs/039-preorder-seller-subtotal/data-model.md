# Data Model: 039-preorder-seller-subtotal

**No schema change; subtotals are derived and never stored.**

## Derived: seller subtotal

| Field | Meaning |
|---|---|
| `artist_id` | the seller |
| `artist_name` | the seller's name |
| `preorder_count` | Σ `preorder_count` of the seller's rows (integer) |
| `total_order_value` | Σ row `total_order_value` (cents-exact, 2-dp string) |
| `total_collected` | Σ row `total_collected` |
| `total_outstanding` | Σ row `total_outstanding` (each row already clamped at 0) |

Invariants: for every seller, subtotal = Σ of that seller's data rows; for the whole table, each Grand Total figure = Σ of the subtotals.

## Response shape (additive)

`GET /reports/preorders?breakdown=artist` → `{ "rows": [...unchanged...], "subtotals": [ { artist_id, artist_name, preorder_count, total_order_value, total_collected, total_outstanding } ] }` (one per seller, in the order sellers first appear in `rows`; empty when there are no rows).
