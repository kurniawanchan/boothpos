# Contracts: Dispatch status endpoint and list additions

## PATCH /preorders/{id}/dispatch-status (new)

Same audience as `PATCH /preorders/{id}/status` (any authenticated user with access to the Pre-orders menu).

Request:

```json
{ "dispatch_status": "invoice_sent" }
```

`dispatch_status` — required, one of `pending`, `invoice_sent`, `shipping`. Any other key (including `invoice_sent_at` / `shipping_at`) is ignored.

Responses:

| Status | When |
|---|---|
| `200` | Updated. Body is the full pre-order as in `GET /preorders/{id}` (items, customer, payments, shipment included), with the new dates. |
| `409` | The pre-order is `cancelled`, **or** `dispatch_status = shipping` on a non-`courier` pre-order. Body: `{ "message": "...", "errors": { "dispatch_status": ["..."] } }`. |
| `422` | Missing or unknown value. |

Effects: only `dispatch_status`, `invoice_sent_at`, `shipping_at` (and `updated_at`) change. No stock movement, no payment, no notification e-mail, no change to `status`.

Date rules: see data-model.md's state table.

## GET /preorders (existing, extended)

- New repeatable filter `dispatch_status[]=pending|invoice_sent|shipping` (values OR-ed; ANDed with the other filters). Also honoured by `GET /preorders/summary` and `GET /preorders/export`.
- Rows gain `dispatch_status`, `invoice_sent_at`, `shipping_at`, `updated_at` (`created_at` already present).
- `sort_by` accepts `created_at` and `updated_at` in addition to the existing keys.

## PATCH /preorders/{id} (existing, behaviour note)

When the edit changes `fulfillment` from `courier` to `pickup` on a pre-order whose `dispatch_status` is `shipping`, the response shows `dispatch_status = invoice_sent` and `shipping_at = null`. No request shape change.

## Unchanged

No other endpoint changes shape. `docs/openapi-pos-mvp.yaml` carries the path, the filter and the behaviour notes.
