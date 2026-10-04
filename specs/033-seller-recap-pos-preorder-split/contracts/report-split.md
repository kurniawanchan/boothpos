# Contract: report responses and exports (additions)

All three endpoints keep their authorization (`canAccessMenu('reports')` → else `403`) and their existing keys; fields below are **added**.

## `GET /api/v1/reports/artist-settlements?event_id=`  (Seller Recap)

Each `data[]` row gains (all numbers, money as 2-dp strings, units as integers):

| Field | Meaning |
|---|---|
| `pos_units` | Units sold at POS (completed orders) |
| `preorder_units` | `total_units − pos_units` |
| `pos_sales` | POS sales amount |
| `preorder_sales` | `total_sales − pos_sales` (cent-exact) |

Invariant: `pos_units + preorder_units = total_units`, `pos_sales + preorder_sales = total_sales`. Zero-sales sellers: all four are `0` / `"0.00"`.

## `GET /api/v1/reports/profit?event_id=`  (Cost & Profit)

New flat keys: `revenue_pos`, `revenue_preorder`, `cost_of_goods_pos`, `cost_of_goods_preorder`, `gross_profit_pos`, `gross_profit_preorder` (money strings). Invariants: `revenue = revenue_pos + revenue_preorder`; `cost_of_goods = cost_of_goods_pos + cost_of_goods_preorder`; `gross_profit = gross_profit_pos + gross_profit_preorder`. `event_cost`, `net_profit` unchanged and not split.

## `GET /api/v1/reports/artist-profit?event_id=`  (Seller Cost) — **behaviour change**

`total_sales`, `modal`, `gross_profit` now include the recognised part of the seller's non-cancelled pre-orders (previously POS only), and new keys give the parts: `sales_pos`, `sales_preorder`, `modal_pos`, `modal_preorder`, `gross_profit_pos`, `gross_profit_preorder`. Invariants: each total = POS part + pre-order part; for events without pre-orders every old value is unchanged. Sellers with only pre-orders now appear. A seller's `total_sales` equals their Seller Recap `total_sales`.

## `GET /api/v1/reports/{artist-settlements|profit|artist-profit}/export` (xlsx)

- `artist-settlements` summary sheet "Rekap": existing headings unchanged and in place; **appended** `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` (same names as the API fields, so the sheet needs no aliasing). The "Detail Transaksi" sheet is unchanged (POS items only — known gap, out of scope).
- `profit`, `artist-profit`: the new keys appear as extra columns after the existing ones (headings are the response keys).
