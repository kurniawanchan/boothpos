**Classification**: INTERNAL

# Data model — 046 Hide Events and Pre-orders

This feature removes behaviour, not data. The only schema work is making three links optional and cleaning saved role menus.

## 1. Schema changes (migrations `2026_12_10_000001` and `000002`)

| Table | Change | Why |
|---|---|---|
| `cashier_sessions.event_id` | NOT NULL → **NULL allowed** (FK kept) | a shift no longer belongs to an event (FR-003) |
| `orders.event_id` | NOT NULL → **NULL allowed** (FK kept) | sales/tabs are created without an event (FR-004) |
| `outlet_settlements.event_id` | NOT NULL → **NULL allowed** (FK kept) | the table stops being written (research D4); nullable so no constraint can ever block |
| `roles.menu_keys` (JSON) | remove the strings `events` and `preorders` from every row | saved roles must not list removed menus (FR-015); idempotent |

`down()` of `000001` restores NOT NULL only when no row has NULL (otherwise it stops with a clear message); `down()` of `000002` cannot restore removed keys (documented; restore from `app:backup`).

## 2. Tables that stay untouched (inert history)

| Table | State after the feature |
|---|---|
| `events` | rows kept, never read or written by the application |
| `preorders`, `preorder_items`, `preorder_notifications`, `shipments` | rows kept, never read or written |
| `payments.preorder_id` | column and rows kept; excluded from every figure (these payments have no order and no shift) |
| `stock_movements` / `material_stock_movements` | rows with `reference_type` `preorder`/`preorder_item` and type `preorder_handover` kept (ledger invariants I1/I2); no new row of those kinds |
| `outlet_settlements` | rows kept (history), no longer recalculated |
| `pos_drafts.event_id`, `orders.event_id` (old rows) | values kept, never returned |
| `products.is_preorder`, `products.preorder_eta` | columns kept, never shown or written |

## 3. Behaviour that changes on existing entities

- **Shift (`CashierSession`)**: outlet, cashier, opening cash entries, business date; no event. Closing/summary unchanged.
- **Order**: no event on create; payload no longer carries event name/location/dates/available-on.
- **Report period**: business date range `[date_from, date_to]` (inclusive), default = current business day. No entity is keyed by an event any more.
- **Recap row** (per outlet): computed live from completed orders by reporting outlet (feature 045 rule); `historical` marker unchanged.
- **Profit report**: revenue, cost of goods, gross profit only (no `event_cost`, `net_profit`).
- **Stock ledger labels**: legacy `preorder_handover` rows show a neutral "stock out" label and no reference link.

## 4. Invariants that must still hold (asserted by the upgrade rehearsal)

1. Σ completed-order totals, Σ order payments and Σ shift cash are identical before/after for every business date.
2. I1/I2 (stock): balance = last ledger `stock_after`; cached total = Σ outlet balances — row counts of `stock_movements` unchanged.
3. Row counts of `events`, `preorders`, `preorder_items`, `payments`, `outlet_settlements` unchanged (nothing deleted).
4. `outlets:reconcile` compare is green.
