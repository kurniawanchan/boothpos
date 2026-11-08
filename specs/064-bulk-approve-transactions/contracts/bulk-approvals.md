# Contract: Bulk approvals

**Classification**: INTERNAL

Base path `/api/v1`. Auth: bearer token. Errors follow project convention: `422` shape/validation, `409` business-rule
conflict, `403` permission. Money is a string with 2 decimals. This file is the source for the matching
`docs/openapi-pos-mvp.yaml` change (same commit).

Routes are declared next to `POST /orders/verify-payments`, **before** any `/orders/{order}` route, with
`->middleware('ability:sales,approve')` (the 057 route-coverage test fails otherwise). Both are classified scope-aware for
058 (the `orders` segment already is).

## 1. `POST /orders/approvals/bulk/preview` — what would be approved (read-only)

Request (JSON): `{ "order_ids": [12, 13, 15] }` — 1…`approvals.bulk_max` distinct integers.

`200`:

```json
{
  "data": {
    "selected": 3,
    "eligible_orders": 2,
    "approvals": 3,
    "amount": "450000.00",
    "sellers": [
      { "artist_id": 4, "artist_name": "Seller A", "approvals": 2, "amount": "300000.00" },
      { "artist_id": 7, "artist_name": "Seller B", "approvals": 1, "amount": "150000.00" }
    ],
    "skipped": [
      { "order_id": 15, "order_number": "ORD-…", "reason": "already_approved" }
    ],
    "plan": [
      { "order_id": 12, "sellers": [ { "artist_id": 4, "items_hash": "v1:…" } ] },
      { "order_id": 13, "sellers": [ { "artist_id": 4, "items_hash": "v1:…" }, { "artist_id": 7, "items_hash": "v1:…" } ] }
    ]
  }
}
```

- `selected = eligible_orders + skipped.length` always (FR-013).
- `approvals` = number of seller approvals that apply would record; `amount` = Σ of exactly those sellers' line subtotals.
- `order_number` is shown only for orders the user can see; ids not visible come back as `not_found` with `order_number: null`.
- Never writes, never stores a file, never reconciles stale approvals.
- Errors: `422` (ids empty/over limit/not integers; message states the limit), `403 approval_not_allowed`-style permission
  denial from `ability:sales,approve` (`{code:'action_not_allowed', …}`), `401`.

## 2. `POST /orders/approvals/bulk` — record the approvals

Request: `multipart/form-data`

| Field | Type | Rules |
|---|---|---|
| `method` | string | `draw` \| `upload` \| `stamp` |
| `image` | file | required for `draw`/`upload`; forbidden for `stamp`; PNG/JPEG by content; ≤ 5 MB |
| `order_ids` | JSON string | array of distinct ints, 1…`approvals.bulk_max` |
| `plan` | JSON string | the `plan` from preview (freshness reference) |

`200` (always `200` once validation passes — a per-order problem is `skipped`, not an HTTP error; pattern of `bulk-email`
and `verify-payments`):

```json
{
  "data": {
    "bulk_ref": "uuid",
    "method": "stamp",
    "selected": 3,
    "approved_orders": 2,
    "approved_sellers": 3,
    "skipped_total": 1,
    "skipped_by_reason": { "already_approved": 1 },
    "results": [
      { "order_id": 12, "order_number": "ORD-…", "status": "approved", "sellers_approved": 1 },
      { "order_id": 15, "order_number": "ORD-…", "status": "skipped", "reason": "already_approved" }
    ]
  }
}
```

- `approved_orders + skipped_total = selected = results.length`; `results` order = request order.
- Reasons: `not_found`, `voided`, `already_approved`, `no_approvable_seller`, `changed`, `failed`.
- Server decides everything per order (research D5): permission per seller, ownership, voided, cycle, freshness. The client
  `plan` can only cause `changed`; ids the user cannot see are `not_found`.
- Idempotent in effect: repeating the same request skips what is already approved; the UNIQUE active-slot index is the net.
- Validation errors (`422`: bad/oversized/blank image, wrong type, stamp with image, ids over the limit) are returned
  BEFORE any order is processed — nothing is recorded and no file is kept.
- `403` `{code:'action_not_allowed'}` for roles without `sales.approve`; the preview-level check also covers a crafted call.
- Side effects: one `order_approved` log per approval (inside that order's transaction) and one `order_bulk_approved` log;
  no money, stock, report, or settlement change (059 invariant).

## 3. Read-model additions

- `GET /orders/{id}/approvals` → each `history[]` entry (owner/admin only, as today) gains `via_bulk: boolean`. `sellers[].approval`
  gains nothing; `can_view_image` semantics unchanged per approval row.
- `GET /auth/me` → new boolean `can_approve_sellers` (owner/admin, or a user linked to a seller). Used only to decide whether
  the SPA shows the Bulk approve button; the server re-checks everything on every call.

## 4. Frontend API client (`resources/js/api/orderApprovals.js`)

```js
previewBulkApproval(orderIds)                      // → data
bulkApprove({ orderIds, plan, method, image })     // multipart; order_ids & plan appended as JSON strings → data
```

409/403/422 toasts come from the shared client interceptor; the dialog only adapts its state.
