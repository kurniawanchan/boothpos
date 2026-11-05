# Contract: Order Approvals API

**Classification**: INTERNAL

Base: `/api/v1`, bearer token. Errors follow the project convention: `422` validation, `409` business conflict (`{code, message}`), `403` role/ownership denial. All routes live under the existing `sales`-menu auth; `docs/openapi-pos-mvp.yaml` is updated in the same commit.

## 1. `GET /orders/{order}/approvals`

Gate: user can see the sale (existing route binding + scopes; foreign-seller sale → 404). Never writes. Any role with the `sales` menu.

200:

```json
{
  "data": {
    "order_id": 12,
    "status": "partial",                    // pending | partial | full | voided
    "total": 3, "approved": 2, "pending": 1,
    "progress": "2/3",
    "sellers": [
      {
        "artist_id": 5, "artist_name": "Seller A",
        "status": "approved",               // approved | pending
        "stale": false,                      // approved earlier, lines changed since → pending + true
        "items": [{"sku":"…","name":"Indomie Goreng","qty":2,"line_total":"20000.00"}],
        "subtotal": "…",
        "items_hash": "v1:…",                // echo back in POST
        "can_approve": false,                // server-computed for THIS user
        "approval": {
          "id": 31, "method": "draw",        // draw | upload | stamp
          "approver_id": 9, "approver_name": "…",
          "on_behalf": false, "approved_at": "2026-11-05T11:20:00+07:00",
          "has_image": true, "can_view_image": true
        }                                    // null while pending
      }
    ],
    "history": [ /* owner/admin only; every cycle incl. invalidated:
                   {id, artist_id, artist_name, method, approver_name, approved_at,
                    invalidated_at, invalidation_reason, on_behalf} */ ]
  }
}
```

`history` is omitted (not empty) for users who are not owner/admin. Money strings use the project `number_format(…,2,'.','')` rule.

## 2. `POST /orders/{order}/approvals`

Middleware: `ability:sales,approve`. `multipart/form-data` (file) or JSON for `stamp`.

| Field | Rule |
|---|---|
| `artist_id` | required, must be a requirement of this sale |
| `method` | required, `draw` \| `upload` \| `stamp` |
| `items_hash` | required, the value from GET |
| `image` | required for `draw`/`upload` (PNG or JPEG, ≤ 5 MB, type sniffed from content, non-empty); forbidden for `stamp` |

Server checks, in order: eligible (owner/admin OR `user.artist_id == artist_id`) → else 403 `approval_not_allowed` (checked first so an ineligible user learns nothing about the sale's state); `reconcile()` in its own committed transaction (stale approvals invalidated + logged, so the trace survives a refused attempt; skipped for voided sales); then, inside one transaction with the order row locked: sale not voided → else 409 `order_voided`; seller is a current requirement → else 422; no valid approval → else 409 `already_approved`; hash equals current → else 409 `approval_stale`; store file; insert row; `ActivityLogger` `order_approved`.

201: the same shape as item 1 (`data`), refreshed. Unique-index race → 409 `already_approved` (never 500). Files written during a failed transaction are deleted.

## 3. `GET /orders/{order}/approvals/{approval}/image`

`scopeBindings()`; parameter name must stay `approval`. Allowed: owner/admin, the approver, users whose `artist_id` equals the approval's seller; otherwise 403. 404 for `stamp` rows or a missing file. Headers: correct `Content-Type`, `Cache-Control: private, no-store`.

## 4. Sales list row addition (`GET /reports/sales` → `transactions[]`, and the export-less rows)

```json
"approval": {
  "status": "partial", "total": 3, "approved": 2, "pending": 1,
  "sellers": [{"artist_id":5,"name":"Seller A","status":"approved",
               "approver_name":"…","method":"draw","approved_at":"…","on_behalf":false}]
}
```

No hash, no URL, no image. `status` is `voided` for voided sales (shown only when `include_voided=1`). Built from one eager load per page (no per-row query).

## Not provided (on purpose)

No update, delete, withdraw or "set status" endpoint exists. A test asserts the routes are absent and the model has no mutator other than `reconcile()`.
