# API Contract Deltas: Split Payment Visibility, Preorder Receipt & Reporting

All deltas below MUST be reflected in `docs/openapi-pos-mvp.yaml` in the same
commit (Documentation & Change Discipline). Status codes follow the existing
convention: `422` validation, `409` business-rule conflict, `403` role
denial.

## No change: `POST /orders`, `POST /preorders/{id}/payments`

Both endpoints keep their exact current request/response shape. Split
payment for Preorders is achieved by the frontend calling the existing
`POST /preorders/{id}/payments` **multiple times in sequence**, once per
split entry — not a new batch shape (research.md R2). This section exists
to make explicit that these two endpoints are *not* touched by this
feature, since it would otherwise be easy to assume a companion batch
endpoint was added.

## GET /reports/sales, GET /reports/profit — response rows gain preorder-sourced revenue

- **Change**: the existing aggregation additionally includes revenue
  recognized from Preorders (research.md R1's proration rule), merged into
  the same rows/totals already returned — **no new response fields**, the
  existing `entity_id/label/unit_count/amount` (sales) and revenue/cost
  (profit) shape is unchanged, only the numbers now reflect combined
  Order + recognized Preorder revenue.
- **Auth**: unchanged.
- **Errors**: unchanged.

## GET /reports/artist-settlements — totals now include preorder-sourced revenue

- **Change**: `SettlementService::recalculateForEvent()`'s aggregation
  (triggered the same way it already is today — on read/recalculation, per
  existing behavior) now includes preorder-sourced `total_sales`/
  `total_units` for each artist. Response shape unchanged.
- **Auth**: unchanged.

## GET /reports/preorders (new)

- **Auth**: `canAccessMenu('reports')`, matching every other report in
  `ReportController`.
- **Params**: `event_id` (optional, filters to one event per FR-014).
- **Response**: `{"rows": [{ "status", "payment_completeness",
  "preorder_count", "total_order_value", "total_collected",
  "total_outstanding" }, ...]}` — see data-model.md.
- **Errors**: `403` (role), unchanged conventions otherwise.

## GET /preorders/{preorder} — no shape change, confirmed already sufficient

`payments` relation is already loaded and present in this endpoint's
response (verified in the code survey) — the new
`PreorderPaymentReceiptModal.vue` component reads from this existing
endpoint rather than requiring a new one.

## No change: `GET /preorders/{preorder}/invoice`

Remains the order-confirmation document; not widened or repurposed for the
new payment-receipt use case (research.md R5).
