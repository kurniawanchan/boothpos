# API Deltas: Duplicate and Split Pre-orders

Both routes live in the existing authenticated group (`auth:sanctum`) and follow the same access rule as `POST /preorders` / `PATCH /preorders/{id}` (any authenticated user; `FormRequest::authorize()` = `user() !== null`). Money is returned as `"0.00"`-style strings. These deltas must be mirrored in `docs/openapi-pos-mvp.yaml` in the same commit.

**Route order**: `POST /preorders/duplicate` is static — register it **before** `Route::apiResource('preorders', …)` next to `bulk-invoices` / `bulk-email`. `POST /preorders/{preorder}/split` goes beside `/payments`.

---

## `POST /api/v1/preorders/duplicate`

Request:

```json
{ "preorder_ids": [12, 15, 19] }
```

| Rule | Failure |
|---|---|
| `preorder_ids` required, array, 1–100 items | `422` |
| each id integer + exists in `preorders` | `422` |

Response `200` (always, even when some or all orders fail — mirrors `POST /preorders/bulk-email`):

```json
{
  "data": [
    { "source_id": 12, "source_number": "PO-20260929-0004", "status": "created",
      "preorder": { "id": 40, "preorder_number": "PO-20261001-0007", "status": "ordered", "…": "standard preorder payload incl. source" } },
    { "source_id": 15, "source_number": "PO-20260929-0005", "status": "failed",
      "error": "Item “Keychain — Blue” is no longer available." }
  ]
}
```

Per-order failure reasons: item variant/product deleted or inactive; re-priced discount exceeds subtotal + shipping; id not visible in the active data mode ("not found"). Each created order: status `ordered`, no payments/shipment, `dispatch_status` `pending`, `source = { type: "duplicate", … }`. No email is sent.

---

## `POST /api/v1/preorders/{preorder}/split`

Request — manual:

```json
{ "mode": "items", "items": [ { "item_id": 101, "qty": 2 }, { "item_id": 103, "qty": 1 } ] }
```

Request — by seller:

```json
{ "mode": "by_seller" }
```

Shape validation (`422`): `mode` in `items|by_seller`; for `items`: `items` array ≥ 1, `item_id` integer, `qty` integer ≥ 1.

Business validation:

| Condition | Status | Message key (new, `lang/{en,id}/preorders.php`) |
|---|---|---|
| Item id does not belong to this pre-order | `422` | `split_item_not_in_order` |
| `qty` greater than the line's quantity | `422` | `split_qty_exceeds_line` |
| Nothing moves, or nothing would remain | `422` | `split_must_move_and_keep` |
| `by_seller` with fewer than two sellers | `422` | `split_single_seller` |
| Status `handed_over` / `cancelled` | `409` | `split_not_allowed_status` |
| Pre-order has any recorded payment | `409` | `split_not_allowed_has_payment` |
| Original's discount would exceed its remaining subtotal + shipping | `409` | `split_discount_exceeds_remaining` |

Response `201`:

```json
{
  "original": { "…": "standard preorder payload, reloaded, incl. split_children" },
  "created":  [ { "…": "standard preorder payload for each new order, incl. source" } ]
}
```

Guarantees: all-or-nothing; subtotals of `original` + `created` sum to the pre-split subtotal; no stock change; no email.

---

## Additive fields on the existing pre-order payload (`present()`)

| Field | Type | When |
|---|---|---|
| `source` | `{ type: "duplicate"\|"split", preorder_id: int\|null, preorder_number: string } \| null` | Always (null for ordinary orders). `preorder_id` is null if the source was deleted |
| `split_children` | `[{ id, preorder_number }]` | Only where `splitChildren` is eager-loaded (`show()`, the split response); empty array otherwise is acceptable, but the key must not silently disappear on those endpoints |

No existing field changes meaning; existing clients ignore the additions.
