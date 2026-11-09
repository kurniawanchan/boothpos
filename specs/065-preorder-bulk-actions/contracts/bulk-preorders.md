# Contract: Pre-order bulk actions

**Classification**: INTERNAL

Base `/api/v1`, bearer token. Convention: `422` shape/validation, `409` business conflict (not used for per-pre-order
outcomes — those are `skipped`), `403` permission. Money = string with 2 decimals. Source for the matching
`docs/openapi-pos-mvp.yaml` change (same commit). All bodies are `application/json`.

Routes are static and declared BEFORE `apiResource('preorders', …)` (so `bulk` / `matching-ids` are not read as `{preorder}`),
registered from one table so each carries the same `ability` middleware as its individual action:

| `{action}` | ability | what |
|---|---|---|
| `status` | `preorders,status` | move to a target status |
| `dispatch` | `preorders,dispatch` | set marker `invoice_sent` / `pending` / `shipping` |
| `payment` | `preorders,payment` | record one payment per pre-order |
| `shipment` | `preorders,edit` | create one shipment per Mail Order pre-order |

## 1. `GET /preorders/matching-ids`

Same filter query string as `GET /preorders` (search, status[], fulfillment[], dispatch_status[], event, seller, …; page/per_page/sort ignored).

`200`: `{ "ids": [12, 15, …], "total": 340, "capped": true }` — `ids.length ≤ bulk_max`; `capped` = more matched than returned.
Only ids (no pre-order data). Same visibility rules as the list (mode + seller scope). Needs the `preorders` menu.

## 2. `POST /preorders/bulk/{action}/preview` (read-only)

Request: `{ "order_ids": [...], "params": { … } }` — `params` per action:

- `status`: `{ "status": "arrived", "cancel_reason": null }`
- `dispatch`: `{ "dispatch_status": "invoice_sent" }`
- `payment`: `{ "method": "bank_transfer", "channel_id": 3, "reference": "TRX-1", "notes": null }`
- `shipment`: `{ "courier_name": "JNE", "shipping_cost": null, "notes": null }`

`200`:

```json
{ "data": {
  "action": "status",
  "selected": 10,
  "eligible": 7,
  "skipped": [ { "preorder_id": 3, "preorder_number": "PO-…", "reason": "invalid_transition", "message": "…" } ],
  "status_options": [ { "status": "arrived", "count": 7 }, { "status": "cancelled", "count": 10 } ],
  "rows": []
} }
```

- `selected = eligible + skipped.length`. Writes nothing (no rows, logs, notifications, stock).
- `status_options` only for `action=status`, and is computed WITHOUT requiring `params` (the UI calls preview once with no
  `params.status` to learn the options, then again with the chosen target).
- `rows` only for `payment` and `shipment` (the editable review table), eligible and skipped alike:
  - payment row: `{ preorder_id, preorder_number, total, paid, outstanding, amount (default = outstanding), blocker: null|{reason,message} }`
  - shipment row: `{ preorder_id, preorder_number, recipient_name, recipient_phone, address_line, tracking_number: null, blocker: null|{reason,message,fields?} }`
- Totals for payment: `data.total_amount` = Σ default amounts of eligible rows.
- Errors: `422` (ids empty/over limit/not integers → message names the limit; missing/invalid `params`), `403` per ability.

## 3. `POST /preorders/bulk/{action}/apply`

Request: `{ "order_ids": [...], "params": { … } }` plus, per action:

- `payment`: `"batch_ref": "uuid"` and `"rows": [ { "preorder_id": 12, "amount": "150000.00" }, … ]`
- `shipment`: `"rows": [ { "preorder_id": 12, "recipient_name": "…", "recipient_phone": "…", "address_line": "…", "tracking_number": "…" }, … ]`

`200` (always, once the body is valid):

```json
{ "data": {
  "action": "payment",
  "bulk_ref": "uuid",
  "selected": 3, "succeeded": 2, "skipped": 1, "failed": 0,
  "skipped_by_reason": { "exceeds_balance": 1 },
  "results": [
    { "preorder_id": 12, "preorder_number": "PO-…", "status": "succeeded", "detail": { "payment_status": "fully_paid" } },
    { "preorder_id": 13, "preorder_number": "PO-…", "status": "skipped", "reason": "exceeds_balance", "message": "…" }
  ]
} }
```

- `succeeded + skipped + failed = selected = results.length`; request order preserved; `message` is the service's localized text.
- Reasons (skipped): `not_found, invalid_transition, not_fully_paid, insufficient_stock, cancelled, not_mail_order, already_done,
  shipment_exists, missing_recipient, fully_paid, invalid_amount, exceeds_balance, already_recorded, rejected`; failed: `failed`.
- Server re-judges every pre-order on its CURRENT state; client row values are only inputs to the existing rules (an amount
  can never exceed the remaining balance; a recipient field can never be empty).
- Idempotent in effect: a repeated request skips what is already done (`already_done`, `already_recorded`, `shipment_exists`,
  `invalid_transition`); payments replay by the derived `client_ref`.
- Side effects per succeeded pre-order: the individual action's effects (stock for handed_over, auto-settle, payment cache,
  status notification after commit) + one `preorder_bulk_*` audit row in the same transaction; plus one aggregate
  `preorder_bulk_operation` row after the loop.
- Errors before processing (nothing written): `422` (shape/limit/`cancel_reason` missing for cancel/`channel_id` missing for
  non-cash/`rows` not a subset of `order_ids`/duplicate row ids), `403` (`action_not_allowed`).

## 4. Read-model additions

- `GET /settings/features` → `preorder_bulk_max` (integer, all roles; convenience only, the server enforces).

## 5. Frontend API client (`resources/js/api/preorders.js`)

```js
matchingPreorderIds(filterParams)          // → { ids, total, capped }
previewBulk(action, orderIds, params)      // → data
applyBulk(action, orderIds, params, extra) // extra = { batch_ref, rows } → data
```
Errors are normalized by the shared client (`ApiError`); 403 `action_not_allowed` refreshes permissions (existing behaviour).
