# Phase 0 Research: Restore Sales Receipt Action & Event Info in Receipt Footers

## R1 — Restoring the Sales receipt action is a pure frontend rewire; zero backend change

**Decision**: `SalesView.vue` re-adds `ReceiptModal.vue` (imported, with its own `showReceipt`/`receiptOrderId` state) and a second action button in the same `#cell-actions` cell that already renders the "Produk Terjual" ("View items") button, calling a new `openReceipt(row)` that sets `receiptOrderId.value = row.id; showReceipt.value = true`. `GET /orders/{id}/receipt` (`OrderController::receipt()`) and the `getReceipt(id)` API client function are both already fully implemented, unchanged since before the `009` redesign removed only the *trigger*, not the underlying document/endpoint.

**Rationale**: Confirmed via code read that `SalesView.vue:93-96`'s own comment ("T017 — klik nomor transaksi tak lagi membuka ReceiptModal (struk cetak), melainkan popup 'Produk Terjual'") documents this removal precisely, and that `ReceiptModal.vue`, `getReceipt()`, and `OrderController::receipt()` were never deleted or changed by that redesign — only the one call site that opened them. Restoring the trigger is therefore the entire scope of FR-001/FR-002; each transaction row already carries the `id` field `openItems()` already uses, so no new data needs to reach the row either.

**Alternatives considered**: *Make the transaction number itself open the receipt again (its pre-009 behavior)* — rejected; that would silently remove the "products sold" popup FR-003's acceptance scenario explicitly requires to keep working unchanged, and the request explicitly asks to restore the action as something visible again ("tampilkan kembali action"), i.e. an additive, discoverable button — not to re-repurpose the number click.

## R2 — Event info requires a new `Preorder::event()` relation; `Order::event()` already exists

**Decision**: Add `event(): BelongsTo` to `App\Models\Preorder` (mirroring `Order::event()`, which already exists and is already eager-loaded by `OrderController::receipt()`). `PreorderController::invoice()` gains `event` to its existing `->load([...])` call, and its response gains `event_name`/`event_location`/`event_start_date`/`event_end_date` (or `null` when the preorder has no `event_id`, which the model already allows — preorders are optionally event-scoped per `010`'s research.md R6). `OrderController::receipt()` gains the same four fields, since `$order->event` is already loaded — no relation change needed on the `Order` side, only new response fields.

**Rationale**: Confirmed via code read that `Preorder` has no `event()` relation today (only `items`, `payments`, `customer`, `shipment`, `notifications`), while `Order::event()` already exists and is non-nullable at the database level (every `Order` requires an event) — so `Order`'s footer info can assume the event is always present, while `Preorder`'s must handle a null event gracefully (FR-005), consistent with `010`'s established rule that a preorder's `event_id` is optional.

**Alternatives considered**: *Fetch the event separately on the frontend via `GET /events/{id}`* — rejected; both documents already return a fully-formed, ready-to-render payload in one request (the established pattern for both `ReceiptModal.vue` and `PreorderInvoiceModal.vue`), and introducing a second request for four read-only display fields would be a needless round-trip and a new failure mode (event fetch failing independently of the receipt itself).

## R3 — Footer date formatting: reuse existing date utilities, no new formatting logic

**Decision**: The footer's date display reuses `formatDate()` (already imported/used elsewhere in this codebase for date-only values, e.g. Sales/Reports date filters) for `event_start_date`/`event_end_date`, and the component computes the display string client-side: both dates present and different → `"{start} – {end}"`; both present and identical → the single date only (FR-007); only one present → that one alone; neither present → the whole event-info block is omitted (FR-005/FR-006), matching the existing "omit entirely when unset" convention `receipt_footer_text`/`customer_name` blocks already use in `ReceiptModal.vue`.

**Rationale**: Formatting this once, client-side, from three simple fields (name, location, two dates) is simpler and more consistent with how the rest of the receipt already renders (all money/date formatting happens client-side from raw API values) than teaching the backend to pre-format a display string, which would need to duplicate this exact conditional logic for two different controllers (`OrderController`, `PreorderController`) instead of one shared frontend computation.

**Alternatives considered**: *Backend pre-formats a single `event_info` string* — rejected; would need identical conditional logic written twice in PHP (once per controller) versus once in a shared frontend computed value, and would make the already-established "component owns its own display formatting" convention (money, dates, everything else on both receipts) inconsistent for just this one field.

## R4 — No shared component extracted for the footer block despite appearing in two places

**Decision**: The event-info footer markup is written directly into both `ReceiptModal.vue` and `PreorderInvoiceModal.vue`, not factored into a new shared sub-component.

**Rationale**: Both components already independently duplicate their own money/date formatting and layout conventions by design (per `PreorderPaymentReceiptModal.vue`'s own docblock, which explicitly rejected merging with `ReceiptModal.vue` for exactly this reason — different field sets, deliberately separate documents). A four-line, three-field footer block is far below the threshold where a shared component would pay for its own indirection, and this codebase's own precedent (Constitution I: no speculative abstraction) favors the small duplication here over a new shared component with only two call sites.

**Alternatives considered**: *Extract a shared `EventFooterInfo.vue`* — rejected as premature abstraction for a two-call-site, four-line block; can be revisited if a third document needs the same footer later.
