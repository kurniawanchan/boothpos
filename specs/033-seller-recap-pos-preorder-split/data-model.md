# Data Model: POS vs Pre-order Split in the Reports

**No schema change.** Nothing is stored; the split is derived at report time from the same sources the totals already use.

## Sources (existing)

| Kind | Rows | Filter | Amount | Units |
|---|---|---|---|---|
| POS | `order_items` of `orders` with `status = 'completed'` (event) | `data_mode = current` | `line_total`; cost = `cost_price × qty` | `qty` |
| Pre-order | `preorder_items` of `preorders` with `status != 'cancelled'` (event) | `data_mode = current` | `line_total × fraction`; cost = `cost_price × qty × fraction` | `qty × fraction` |

`fraction = COALESCE(collected, 0) / preorders.subtotal` (0 when subtotal is 0), `collected` = sum of the pre-order's payments excluding `verification = 'rejected'` (the existing `PREORDER_FRACTION_EXPR`).

## Derived figures

Per seller (Seller Recap): `pos_sales`, `pos_units`, `preorder_sales`, `preorder_units`, where the stored totals stay `total_sales`/`total_units`:

- `pos_units` = integer sum of POS qty; `preorder_units` = `total_units − pos_units` (so `pos + preorder = total` exactly; equals `round(fractional pre-order units)` because `pos_units` is an integer).
- `pos_sales` = POS sum to 2 dp; `preorder_sales` = (`round(total_sales×100) − round(pos_sales×100)`) / 100.

Event (Cost & Profit): for each of revenue and cost of goods: POS part (exact sum) and pre-order part = total − POS part in cents; `gross_profit_pos = revenue_pos − cost_pos`; `gross_profit_preorder = gross_profit − gross_profit_pos` (cents).

Per seller (Seller Cost): same three metrics (sales, modal/cost, gross profit) with POS part, pre-order part, total; `total_sales`, `modal`, `gross_profit` keep their names and now mean POS + pre-order.

## Invariants

- POS + pre-order = total for every row and for every Grand Total (0 drift).
- Voided orders and cancelled pre-orders appear in neither part.
- The sum of a seller's drill-down amounts by kind (`order-*` vs `preorder-*` rows) equals that seller's POS / pre-order sales.
- Payable, paid, outstanding, status, deduction, event cost and net profit are unchanged.
- A seller's Seller Cost `total_sales` equals the same seller's Seller Recap `total_sales`.
