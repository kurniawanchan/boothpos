# Contract: BOM batch save and stock movements

Delta against `docs/openapi-pos-mvp.yaml`; the OpenAPI file is updated in the same commit as the code.

## PUT /api/v1/variants/{variant}/bom — save changed quantities (NEW)

Auth: `products` AND `purchase_orders` menu (same helper as every BOM mutation) → 403 otherwise.

Request:
```json
{ "lines": [ { "id": 12, "qty_needed": 11 }, { "id": 13, "qty_needed": 2 } ] }
```
- `lines` 1..200, distinct `id`, `qty_needed` whole number ≥ 1 (`2`, `"2"`, `2.0` accepted; `1.5` refused).

Responses:
- **200** — the standard BOM payload (`{data:[rows], summary:{…, current_stock}}`) after the save.
- **422** — `{message, errors:{"lines.0.qty_needed":["…"]}}`; NOTHING saved.
- **409** — `{message, code:"bom_line_not_found"}`: an id does not belong to this variant (e.g. removed meanwhile); nothing saved.
- **403** — not authorised.

Behaviour: one transaction; variant row locked once; unchanged values skipped; one `bom_qty_changed` audit row per changed line; `cost_price` re-synced once if the BOM is complete; cost/vendor/source snapshots never touched.

## Whole-number rule on existing endpoints

`PUT /bom/{id}`, `POST /variants/{v}/bom/items` (`items.*.qty`), `POST /variants/{v}/bom` (legacy) and the Excel `bom` sheet refuse fractional quantities with the same message (422 / row error). Reading stored fractional rows is unchanged.

## GET /api/v1/variants/{variant}/bom — payload addition

`summary.current_stock: integer` — the variant's current stock at read time (read-only).

## GET /api/v1/stock/movements — extended

Auth: **`stock` OR `products` menu** → 403 otherwise (was: any authenticated user).

Query (unchanged): `variant_id`, `type`, `date_from`, `date_to`, `per_page` (≤ 100), `page`.

Row (additive fields in bold):
```json
{ "id": 9, "variant_id": 3, "sku": "SPF-KC-MCY-008", "type": "sale",
  "qty_change": -1, "stock_before": 9, "stock_after": 8, "reason": null,
  "created_at": "2026-10-04T06:29:00+00:00",
  "user_name": "Chan", "variant_name": "Slippery 5cm", "product_id": 2, "product_name": "MCYT",
  "reference": { "type": "order", "id": 41, "number": "ORD-20261004-0007" } }  // type: "order" | "preorder"
```
`reference` is `null` when it cannot be resolved safely. Order: `created_at desc, id desc`.
