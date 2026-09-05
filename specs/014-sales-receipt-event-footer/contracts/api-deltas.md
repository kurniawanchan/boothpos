# API Deltas: Restore Sales Receipt Action & Event Info in Receipt Footers

All changes are additive to existing endpoints. No new endpoints, no breaking changes.

## `GET /orders/{order}/receipt` (existing — `OrderController::receipt()`)

**New response fields**: `event_location: string|null`, `event_start_date: string|null` (date),
`event_end_date: string|null` (date) — alongside the already-existing `event_name`. Sourced
from the already-eager-loaded `event` relation; no change to the `->load([...])` call.

## `GET /preorders/{preorder}/invoice` (existing — `PreorderController::invoice()`)

**New response fields**: `event_name: string|null`, `event_location: string|null`,
`event_start_date: string|null`, `event_end_date: string|null`. All `null` together when the
preorder has no `event_id`. Requires adding `'event'` to this action's `->load([...])` call
(currently `['items', 'payments', 'customer']`).

## Frontend (no route changes)

- `resources/js/components/receipt/ReceiptModal.vue`: renders the new fields in a footer block,
  omitted entirely if `event_location` is null AND both dates are null (name is always present
  for an Order's receipt, so its presence alone doesn't gate the block — the block only needs
  at least one of location/start/end to be worth showing beyond the event name already shown
  near the top of the receipt).
- `resources/js/components/preorder/PreorderInvoiceModal.vue`: renders the same block, omitted
  entirely when `event_name` is null (i.e. the preorder has no event at all).
- `resources/js/views/SalesView.vue`: re-imports `ReceiptModal.vue`, adds a "View receipt"
  action button alongside the existing "View items" action, using the already-existing
  `getReceipt(id)` API client call — no new API client function needed.

## `docs/openapi-pos-mvp.yaml`

Must be updated in the same commit (PRD §9.5): the four new fields on both
`GET /orders/{order}/receipt` and `GET /preorders/{preorder}/invoice` response schemas.
