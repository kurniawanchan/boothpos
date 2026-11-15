**Classification**: INTERNAL

# Contract: `POST /api/v1/preorders/shipping-labels`

Read-only. Mirrors into `docs/openapi-pos-mvp.yaml` in the same commit.

**Auth**: `auth:sanctum` + `ability:preorders,export`. Seller-scoped users allowed (route segment `preorders` is
scope-aware); results narrowed by the scoped `Preorder` model.

## Request

```json
{ "preorder_ids": [12, 15, 18] }
```

| Field | Rule |
|---|---|
| `preorder_ids` | required, array, min 1, max `config('preorders.bulk_max')` (200) |
| `preorder_ids.*` | integer, distinct |

No `exists:` rule — it bypasses Eloquent scopes; unknown ids become `skipped: not_found`.

## 200 Response

```json
{
  "data": [
    {
      "preorder_id": 12,
      "preorder_number": "PO-20260929-0001",
      "courier_name": "JNE",
      "tracking_number": "543030012830026",
      "recipient_name": "<name>",
      "recipient_phone": "<phone>",
      "address_line": "<address>"
    }
  ],
  "skipped": [
    { "preorder_id": 15, "preorder_number": "PO-20260929-0004", "reason": "no_shipment" },
    { "preorder_id": 18, "preorder_number": null, "reason": "not_found" }
  ],
  "store_identity": { "name": "…", "address": "…", "contact_phone": "…", "contact_person": "…" }
}
```

- `data` ordered by `preorder_number` ASC, then `preorder_id`.
- `reason`: `not_found` | `not_mail_order` | `no_shipment`.
- `tracking_number` may be `null` (the only nullable shipment column of the three; `recipient_phone` and `address_line` are NOT NULL in the schema, but may be empty strings).
- `data.length + skipped.length` = number of distinct ids requested.

## Errors

| Status | When |
|---|---|
| 401 | not signed in |
| 403 `action_not_allowed` | role lacks `preorders.export` |
| 422 | empty/missing array, non-integer, duplicates, more than `bulk_max` (message names the max) |
| 423 | installation not activated (global gate) |

# UI contract (Pre-orders screen)

- Bulk action bar: button **"Unduh label kurir (A4 PDF)" / "Download courier labels (A4 PDF)"**
  (`data-testid="bulk-btn-courier-labels"`), `v-if auth.canDo('preorders','export')`, next to the shipping-slips button.
- Detail drawer, "Courier shipping" section (shipment exists): button **"Unduh label kurir (A4)" / "Download courier
  label (A4)"** (`data-testid="btn-courier-label"`), same `v-if`.
- File names: `label-kurir.pdf` (bulk), `label-kurir-<preorder_number>.pdf` (single).
- Toasts: success + print-at-100 % hint; "N skipped: PO-…, …" (numbers listed, `not_found` counted only);
  "none of the selected pre-orders has a courier shipment" (no file); too-tall label (names the pre-order number);
  "unsaved shipment changes were not included" (single, when the drawer is dirty).
