**Classification**: INTERNAL

# Data Model — 044 Sales Tables View

No table or column is added. This feature reads existing data and extends two read shapes additively.

## Floor snapshot (`GET /floor`) — shape after this feature

```text
{
  areas: [ { area: string, tables: [ Table ] } ],     // same order as today
  takeaway_tabs: [ OpenOrder + { tab_label } ],
  version: string                                     // hash of the payload
}
Table      = { id, name, seats, is_active, state: 'free'|'occupied', tab: OpenOrder|null }
OpenOrder  = { id, order_number, total_amount, paid_amount, opened_at, session_id, ready_count, unsent_count }
```

Additions: `OpenOrder.paid_amount` (money string, derived paid total = Σ non-rejected payments − change; same rule as `PaymentSummary`), and **inactive tables that currently hold an open order** are now listed (with `is_active: false`, `state: 'occupied'`). Nothing clock-dependent is added (keeps `version` stable).

## Tab detail (`GET /tabs/{id}`) — one addition

`opened_by`: name of the cashier who opened the order (nullable). Existing fields unchanged (`lines[]` with `prep_status`, `payments[]`, `subtotal`, `discount_amount`, `total_amount`, `paid_amount`, `balance`, `table`, `tab_label`, `created_at`, `status`). No cost/margin fields (unchanged guarantee).

## Derived, never stored

| Concept | Rule |
|---|---|
| Table state | occupied ⇔ it has exactly one open order (database-guaranteed), else free |
| Elapsed | `now − opened_at` (clock difference, not business date) |
| Over 45 minutes | occupied/takeaway order with elapsed > 45 min |
| Partially paid | `0 < paid_amount < total_amount` |
| Tile state precedence | inactive-with-order mark is additive; otherwise partial > over-45 > occupied > empty (partial and over-45 may both show: partial sets the fill, over-45 the outline) |

## The four figures (computed in one place, `utils/floorBoard.js`)

| Figure | Definition |
|---|---|
| Open orders | count of open orders = occupied tiles + takeaway tiles |
| Occupied tables | occupied table tiles ÷ listed table tiles (inactive-with-order tiles count in both) and the percentage |
| Tables over 45 min | occupied **table** tiles with elapsed > 45 min (takeaway excluded) |
| Open order value | Σ `total_amount` of all open orders (integer-cent sum) |

## State transitions (read-only view of the order lifecycle)

open → (paid) completed · open → (cancelled / merged away) voided · open → (moved) same order, different table. The board and the detail react to these through the next snapshot / detail refresh; they never cause them.
