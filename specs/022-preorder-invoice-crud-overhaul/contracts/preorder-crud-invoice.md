# Contracts: Pre-order Invoice & CRUD Overhaul

All endpoints below are additions/modifications to `routes/api.php`'s
existing `preorders` route group, gated the same way existing pre-order
routes are (the `preorders` menu key — see `PreorderController`'s current
`authorize`/policy usage; no new gate is introduced).

## PATCH /preorders/{preorder}

Edit an existing pre-order's items and editable fields.

**Request body** (all optional except `items`):
```jsonc
{
  "customer_id": 1,
  "event_id": 2,
  "fulfillment": "pickup",
  "pickup_day": "2026-10-01",
  "courier_name": null,
  "discount": 5000,
  "expected_date": "2026-10-05",
  "notes": "string",
  "items": [
    { "variant_id": 10, "quantity": 2 },
    { "variant_id": 11, "quantity": 1 }
  ]
}
```

**Responses**:
- `200` — updated pre-order, same shape as `GET /preorders/{id}`.
- `409` — status is `handed_over` or `cancelled`
  (`preorders.edit_not_allowed_status`).
- `422` — standard validation (e.g. discount exceeds new total, pickup
  day/courier cross-field rules from feature 021, unknown variant).

**Side effects**: if status is `arrived`/`settled`, applies one
`StockService::applyMovement()` call per variant whose quantity changed
(delta = new − old), type `purchase`. No stock effect if status is
`ordered`/`dp_paid`.

## DELETE /preorders/{preorder}

**Responses**:
- `204` — deleted.
- `409` — status is not `ordered`, or a payment exists
  (`preorders.delete_not_allowed_status` /
  `preorders.delete_not_allowed_has_payment`), message directs staff to
  use the existing Cancel action instead.

## GET /preorders/{preorder}/invoice (existing endpoint, extended response)

Adds `store_identity`, `payment_channels` (unmasked), `footer_text` — see
data-model.md's "Invoice response shape". No request change.

## GET /preorders/{preorder}/payments/{payment}/receipt (existing "payment receipt" endpoint, renamed in UI to "Payment invoice", extended response)

Same additive fields as the invoice endpoint. No request/route change —
this is a response-shape and frontend-label change only.

## POST /preorders/bulk-invoices

Bulk-generate invoice data for client-side rendering (US5, "bulk
download"). The backend does **not** render PDFs (research.md Decision
5) — it returns each selected pre-order's full invoice payload (same
shape as the single-invoice endpoint) in one call, so the frontend can
render+zip them client-side without N sequential requests.

**Request**:
```jsonc
{ "preorder_ids": [1, 2, 3], "document": "invoice" }
```
`document`: `"invoice"` or `"payment_invoice"` (US4/US5 both use bulk
download — payment invoices need a `payment_id` per order, see below).

For `document: "payment_invoice"`, each id in `preorder_ids` must resolve
to a pre-order's *latest* payment record, or the request accepts
`{ "payment_ids": [5, 6] }` instead of `preorder_ids`.

**Response**: `200`, `{ "data": [ <invoice payload>, ... ] }`.

## POST /preorders/bulk-email

Sends `App\Mail\PreorderInvoiceMail` (rich HTML body, no PDF attachment —
research.md Decision 5) to each selected pre-order's customer email, one
send per pre-order, reusing `PreorderNotifier`'s existing
record-every-attempt pattern (new rows in `preorder_notifications` with a
`channel`/`document_type` distinguishing this from status-change
notifications).

**Request**:
```jsonc
{ "preorder_ids": [1, 2, 3], "document": "invoice" }
```

**Response**: `200`,
```jsonc
{ "data": [
  { "preorder_id": 1, "status": "sent" },
  { "preorder_id": 2, "status": "skipped_no_email" },
  { "preorder_id": 3, "status": "skipped_not_configured" }
] }
```
Never a hard failure for one bad recipient — mirrors feature 007's
resend-notification endpoint's own per-row status reporting.

## GET /imports/preorders/template — REWRITTEN

Returns the new one-row-per-order template (see data-model.md's
"Import/Export row shape"), replacing the old row-per-item template.

## POST /imports/preorders — REWRITTEN

Validates/applies rows in the new shape. Same all-or-nothing +
`dry_run=1` preview convention as the master-data import
(`MasterDataImportService`'s already-established pattern, reused here for
consistency even though this is a separate service).

## GET /exports/preorders — REWRITTEN

Produces the same column set/order as the template, so an exported file
round-trips back through import.

## Shipment endpoints (existing routes, request-shape change only)

`POST/PUT /preorders/{preorder}/shipment`: `city`/`postal_code` removed
from validation rules and from the persisted row (columns dropped);
`address_line` remains required. No route change.
