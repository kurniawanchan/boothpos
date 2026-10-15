# Contract: copy BOM to chosen variants

Delta against `docs/openapi-pos-mvp.yaml`; the OpenAPI file is updated in the same commit as the code.

## POST /api/v1/variants/{variant}/bom/copy-out — new `mode = selected`

Auth: `products` AND `purchase_orders` menu (same helper as every BOM mutation) → 403 otherwise.

Request:
```json
{ "mode": "selected", "variant_ids": [12, 15, 19], "confirm_replace": false }
```
- `mode`: `next` | `all` | `selected`.
- `variant_ids`: required when `mode = selected`; array 1..200 of distinct existing variant ids; ignored for `next`/`all`.
- `confirm_replace`: boolean (default false).

Responses:
- **200** `{ "results": [ { "variant_id": 12, "sku": "SPF-KC-MCY-003", "status": "copied", "rows": 2, "reopened": false } ] }` — one entry per selected variant; all-or-nothing in one transaction.
- **409** `{ "code": "requires_confirmation", "requires_confirmation": true, "variants": [ { "id", "sku", "variant_name", "rows" } ] }` — some selected variants already have BOM rows; nothing was copied; resend with `confirm_replace: true`.
- **422** — source has no rows; `variant_ids` missing/empty for `selected`; an id is the source itself, belongs to another product, or does not exist.
- **403** — not authorised.

Behaviour (unchanged from the other modes): a copy never marks a target complete; a target that was complete is reopened (its cost price keeps its last value); each target gets one `bom_copied` audit row; unticked variants are never touched.

## Not changed

`POST /products/{id}/variants` (`copy_bom_from_variant_id`) is reused as-is by Duplicate variant; no new endpoint.
