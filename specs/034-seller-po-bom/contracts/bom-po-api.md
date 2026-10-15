# Contract: BOM / purchase-order API (additions and changes)

Conventions unchanged: `422` validation, `409` business-rule conflict, `403` role denial; money = 2-dp string; `qty_needed` = 4-dp string; pagination envelope `{data, meta}`.

**Gates**: *read* = menu `products`; *mutate / eligible-lines / copy / complete / reopen* = menu `products` AND menu `purchase_orders`; PO endpoints keep `PurchaseOrderPolicy` (`purchase_orders`).

## Purchase orders

- `POST /purchase-orders` — new required field `artist_id` (existing artist). `items.*.product_id` stays optional/accepted (not offered by the UI). `422` when `artist_id` missing/invalid.
- `PUT /purchase-orders/{po}` — accepts `artist_id`. `409` if the PO's lines are referenced by any BOM row and `artist_id` would change; assigning a seller to a legacy PO (NULL) always allowed; activity log entry.
- `GET /purchase-orders` — new filter `artist_id`; every row (and detail) adds `artist_id`, `artist_name` (null = legacy "no seller").
- `GET /purchase-orders/{po}` — items additionally carry `used_in_bom_count` (number of BOM rows referencing the line) so the UI can warn before a seller change.

## Eligible PO lines (selector)

`GET /variants/{variant}/bom/eligible-lines`
Query: `q` (item/material/description text), `purchase_order` (PO number text), `vendor_id`, `line_type` (`material`|`service`), `date_from`, `date_to` (PO `ordered_at`/`created_at` date), `page`, `per_page` (≤ 100).
Returns only lines of POs with `artist_id` = the variant's seller and status ∈ ordered/received/paid, active data mode. Row:
`{purchase_order_item_id, purchase_order_id, po_number, po_status, po_date, vendor_id, vendor_name, line_type, item_name, material_id, unit_price, po_qty, in_bom}` (`in_bom` = already used by THIS variant).

## BOM

`GET /variants/{variant}/bom` — `{data: [row…], summary: {material_cost, service_cost, bom_cost, has_legacy, bom_complete, cost_price}}`.
Row: `{id, line_type, item_name, is_legacy, material_id, material_unit, purchase_order_item_id, po_number, vendor_id, vendor_name, unit_cost, qty_needed, item_cost, po_qty, notes, source_cancelled, newer_price: null | {purchase_order_item_id, po_number, unit_price}}`. Legacy rows: `unit_cost` = current reference price (live), `is_legacy: true`.

`POST /variants/{variant}/bom/items` — body `{items: [{purchase_order_item_id, qty?}]}` (qty default 1, > 0, ≤ 4 decimals). Server copies the snapshot from the PO line. `201` with the refreshed BOM payload. Errors: `422` invalid line / qty ≤ 0 / **line belongs to another seller** / PO status not eligible; `409` line already in this BOM. Adding to a complete BOM is allowed and re-syncs the cost price.

`PUT /bom/{bomLine}` — `{qty_needed?, notes?}` only (a PO row's cost/vendor/source never change here). `422` qty ≤ 0.

`POST /bom/{bomLine}/replace-source` — `{purchase_order_item_id}` (same eligibility + seller rules); keeps `qty_needed`, re-snapshots the cost; audited.

`DELETE /bom/{bomLine}` — removes the row; if the BOM was complete and becomes empty it is auto-reopened (response `{reopened: true}` in the refreshed payload).

`POST /variants/{variant}/bom/complete` — `409` with a reason code when: `empty`, `has_legacy` (names the rows), `invalid_row`. On success sets `bom_complete`, syncs `cost_price`, audits `bom_completed` + `cost_price_synced`.
`POST /variants/{variant}/bom/reopen` — clears `bom_complete`; cost price keeps its value.

`POST /variants/{variant}/bom/copy` — `{mode: "from", source_variant_id, confirm_replace?: bool}` copies INTO `{variant}`.
`POST /variants/{variant}/bom/copy-out` — `{mode: "next"|"all", confirm_replace?: bool}` copies FROM `{variant}` to the next variant / all other variants of the product.
Rules: same product only (`422` otherwise); a target with rows needs `confirm_replace` (`409` with `requires_confirmation: true` and the list of affected variants otherwise); copying never completes the target and reopens a complete target; response lists per-target results (`copied`, `skipped`, `reopened`).

`POST /products/{product}/variants` — optional `copy_bom_from_variant_id` (variant of the same product) copies that BOM into the new variant.

`GET /variants/{variant}/cost-breakdown` (existing) — payload extended with `material_cost`, `service_cost`, `bom_cost`, `has_legacy`, per-line `line_type`, `is_legacy`, `vendor_name`, `po_number`.

`POST /variants/{variant}/bom` (legacy, material + qty) — unchanged contract, still creates a **legacy** row; `409` when the variant's BOM is complete. Deprecated in the docs; the UI no longer offers it.

## Cost-price lock

`PUT /variants/{variant}` — when `bom_complete` and `cost_price` differs from the stored value: `409` `{message, code: "cost_price_locked_by_bom"}`; an unchanged value is accepted. `ProductVariantResource` adds `bom_complete`, `bom_cost` (null when no BOM), `has_bom`.
Excel import `products` sheet: row error for a complete variant whose `cost_price` cell differs; `bom` sheet: row error for a complete variant.

## Audit entries

See data-model.md "Audit". Shape follows `ActivityLogger::log()` (user, action, entity, old/new values).
