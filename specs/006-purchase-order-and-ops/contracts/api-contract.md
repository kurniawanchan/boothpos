# API Contract (Phase 1)

Conventions per CLAUDE.md: money as formatted strings, `422` validation,
`409` business-rule conflicts (status transitions), `403` role denial,
`{"data": [...], "meta": {...}}` pagination envelope. `docs/openapi-pos-mvp.yaml`
must be updated in the same commit as these routes ship.

## Purchase Orders — gated by `canManageMasterData()`

- `GET /purchase-orders` — list, filterable by `status`, `vendor_id`, `date_from`, `date_to`.
- `POST /purchase-orders` — create (status starts `draft`); body: `vendor_id`, `notes?`, `items: [{line_type, material_id?, product_id?, description?, qty, unit_price}]`.
- `GET /purchase-orders/{id}` — detail with items eager-loaded.
- `PUT /purchase-orders/{id}` — update; `409` if not `draft` and the request touches `items`.
- `DELETE /purchase-orders/{id}` — `409` if not `draft`.
- `PATCH /purchase-orders/{id}/status` — body `{status, cancel_reason?}`; `409` on an invalid transition (research.md R5). Transitioning to `received` triggers `MaterialStockService::applyMovement()` for every material line item, inside the same transaction as the status write.
- `GET /purchase-orders/{id}/invoice` — returns the data needed to render the client-side invoice (no server PDF); actual PDF is generated client-side per research.md R6.

## Payments — split payment & notes

- `POST /orders` — **no new route**; `StoreOrderRequest` gains `payments.*.notes` (nullable, max 1000). `payments` already accepts 1+ entries (research.md R2).
- `POST /preorders/{preorder}/payments` — **no new route**; already accepts `notes`. Frontend now may call this once per split-payment entry in sequence.
- `POST /purchase-orders/{id}/payments` — new, single-payment-per-call (mirrors preorder's shape, not order's array shape, since a PO's payment is typically one settlement, but the endpoint accepts being called more than once if a PO is paid in installments): `{method, channel_id?, amount, notes?}`. Marks the PO `paid` once the sum of its payments reaches `total_amount`.

## POS Drafts — any authenticated cashier, own-drafts-only

- `GET /pos-drafts` — list current user's drafts.
- `POST /pos-drafts` — save current cart; body: `customer_id?`, `label?`, `items`, `discount_amount`.
- `GET /pos-drafts/{id}` — resume (returns the snapshot plus a `warnings` array flagging any now-invalid variant/customer references, per spec edge case).
- `DELETE /pos-drafts/{id}` — discard.

## Cashier Session — per-artist opening cash

- `POST /sessions` — `opening_cash` (existing, required) gains an optional sibling `opening_cash_entries: [{artist_id?, amount}]`; server validates entries sum to `opening_cash` (`422` on mismatch — client must send a consistent total, since FR-015 requires the total to always equal the sum).
- `GET /sessions/{session}/summary` — response gains `opening_cash_entries` array alongside existing fields.

## Settings — theme & receipt display

- `PUT /settings` — **no new route**; accepts new keys `theme_accent_color`, `receipt_footer_text`, `receipt_show_logo` through the existing bulk-update contract.
- `GET /settings/features` — response gains `theme_accent_color` (so it can be applied before the full settings screen loads, same reasoning as why `system_mode` is already surfaced here).

## Activity Log — frontend only

- `GET /activity-logs` — **no backend change**; existing endpoint, its actual current filter/pagination parameters are read from `ActivityLogController::index()` during implementation (not guessed here) and a `resources/js/api/activityLog.js` wrapper + `ActivityLogView.vue` are added.

## Reports

- `GET /reports/purchases` — filterable `date_from`, `date_to`, `vendor_id`, `status`; totals + rows, mode-scoped.
- `GET /reports/purchases/export` — mirrors existing `GET /reports/{report}/export` convention.
- `GET /reports/stock-by-artist` — filterable `artist_id`; grouped totals + per-product/variant breakdown, mode-scoped (stock levels themselves aren't mode-scoped data today at the variant level — confirm during implementation whether `product_variants.current_stock` needs the same explicit-filter treatment CLAUDE.md flags for hand-rolled queries, since `ProductVariant` is `HasDataMode`-scoped already via the Eloquent global scope, unlike the raw `DB::table()` queries in `ReportController::sales()`).
