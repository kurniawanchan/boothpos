# Contract: HTTP changes

No new routes.

## `DELETE /api/v1/purchase-orders/{id}`

- Draft → `204` (activity log `deleted`, unchanged).
- Not a draft → `409` `{message: "Only draft purchase orders can be deleted. Cancel it instead."}` (message changed; status unchanged).
- Has any payment, or a BOM row references one of its lines → `409` with its own message (defensive; draft orders cannot normally reach this).

## `PUT /api/v1/purchase-orders/{id}`

Unchanged contract. New side effect: writes `purchase_order_updated` when the vendor changes or lines are rewritten.

## `GET /api/v1/settings/features`

Adds `schema_update_required: boolean` — `true` only for users with the `settings` menu when at least one migration is pending; `false` for everyone else.

## Schema-error response (any API route)

When a request fails with a database "unknown column" (SQLSTATE `42S22`) or "table not found" (`42S02`):

```
HTTP 503
{ "message": "<friendly, localized: the database needs an update; ask an administrator to apply the pending updates>", "code": "schema_outdated" }
```

No SQL, table or column names in the body; the full exception is still written to the application log. All other database errors keep their current handling.
