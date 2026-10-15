# Data Model: 036-bom-variant-stock-ux

**No schema change.** All tables below already exist.

## `product_variant_bom_lines` (existing)

- `qty_needed decimal(12,4)` — meaning unchanged (quantity per ONE finished unit). New write rule: whole number ≥ 1 (`WholeBomQuantity`). Existing fractional values stay valid for reading and costing; the next edit of such a row must be whole.
- Batch save writes `qty_needed` only (cost/vendor/source snapshot untouched — unchanged invariant from feature 034).

## `stock_movements` (existing, append-only)

`id, variant_id, type(purchase|sale|preorder_handover|adjustment|return|initial), qty_change, stock_before, stock_after, reference_type, reference_id, reason, user_id, created_at`. Index `(variant_id, created_at)`.

### Reference resolution (read-time, never stored)

Verified in the writers: `reference_id` does NOT mean the same thing for every movement, so the resolver decides by **(movement type, reference_type)**. (`purchase_order_item` references only ever go to `material_stock_movements`, never to variant movements — not resolved here.)

| movement `type` | `reference_type` | `reference_id` is | Resolved to | Guard (else `reference = null`) |
|---|---|---|---|---|
| `sale` | `order_item` | an **order** id (`OrderService::create`) | `orders.order_number` | the order has an item for this variant |
| `return` | `order_item` | an **order item** id (`OrderService::void`) | item's `orders.order_number` | item.variant_id == movement.variant_id |
| `purchase`, `preorder_handover` | `preorder_item` | a **pre-order item** id (arrival / hand-over) | item's `preorders.preorder_number` | item.variant_id == movement.variant_id |
| `purchase` | `preorder` (NEW, written by the pre-order edit delta from this feature on) | a **pre-order** id | `preorders.preorder_number` | the pre-order has an item for this variant |
| `purchase` (rows written by the edit path BEFORE this feature) | `preorder_item` holding a pre-order id | ambiguous with the arrival rows | resolved as an item, with the variant guard | residual risk: a pre-order id that coincides with an item id of the same variant (rare; documented) |
| `adjustment`, `initial`, `MasterDataImport`, null | — | — | `null` (reason text shown) | — |

## API shapes (additive)

- `GET /stock/movements` row: existing fields + `user_name`, `variant_name`, `product_id`, `product_name`, `reference: {type, id, number} | null`.
- `GET|PUT … /variants/{variant}/bom` payload `summary`: + `current_stock` (integer).
- New `PUT /variants/{variant}/bom` — see contracts.

## Validation rules

- `WholeBomQuantity`: numeric; `>= 1`; `<= 99999999`; fractional part must be zero. Message keys `bom.qty_whole` (en/id).
- Batch: `lines` array 1..200; each `id` integer, distinct; each `qty_needed` via `WholeBomQuantity`; ids must belong to the variant (409).

## State

BOM `bom_complete` semantics unchanged: a batch save on a complete variant re-syncs `cost_price` once (`cost_price_synced` audit as today).
