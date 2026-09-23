# Contracts: Pre-order Invoice Layout Refinements & Shipping Slip

## GET /preorders/{id}, POST /preorders, PATCH /preorders/{id}, POST /preorders/{id}/payments, GET /preorders/{id}/invoice, POST /preorders/bulk-invoices (existing endpoints, response extended)

All of these already flow through `PreorderController::present()`. One new
field is added there, reaching every one of them:

```jsonc
{
  // ...existing fields unchanged...
  "created_at": "2026-09-23T10:15:00+00:00"
}
```

No request shape change, no new endpoint. `created_at` is a standard,
always-present Eloquent timestamp — never null.

## No other contract changes

Every other requirement in this feature (two-column header, two-column
payment terms, shipping slip, title, footer message) is satisfied by data
already documented in existing contracts (features 007/014/022/023) and
is implemented entirely in the frontend. See data-model.md's field-source
table for the full mapping.
