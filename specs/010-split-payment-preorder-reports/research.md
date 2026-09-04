# Phase 0 Research: Split Payment Visibility, Preorder Receipt & Reporting

## R1 — Revenue recognition for merged Preorder reports (the one non-obvious decision in this plan)

**Decision**: When merging Preorder-sourced revenue into `sales()`, `profit()`, and `SettlementService::recalculateForEvent()`, recognize only the cash actually collected — summed live from `payments` rows (`WHERE preorder_id = ... AND verification != 'rejected'`, mirroring however Order payments already filter rejected/pending proofs), never from the `preorders.paid_amount` cache column and never from `preorder_items.line_total` (the full order value). That collected amount is then prorated across the preorder's items — and therefore across artists — by each item's share of the preorder's subtotal: `item_recognized_revenue = (item.line_total / preorder.subtotal) * amount_collected`. A cancelled preorder (`status = 'cancelled'`) contributes zero regardless of any `payments` history.

**Rationale**: Confirmed via code read that `PreorderItem` mirrors `OrderItem`'s shape closely (`artist_id`, `qty`, `cost_price`, `sell_price`, `line_total`, `data_mode`) — structurally easy to UNION into the existing order_items-based aggregates. But spec FR-012 explicitly forbids counting a partially-paid preorder's full order value as revenue, and a preorder can span multiple artists in one order (this is a multi-artist merch booth), so "how much of this partial payment belongs to which artist" needs an explicit rule, not an assumption left implicit in SQL. Proration by item-value share is the standard, defensible approach for partial-payment revenue attribution across multiple line items — it's also self-correcting as more payments arrive (fully-paid preorders recognize 100% of every item's `line_total`, exactly matching how a completed Order already works). Using `payments` directly (not the `paid_amount` cache) was flagged by the code survey as the safer source of truth, since `paid_amount` is a running total updated by `PreorderService::recordPayment()` and is never re-derived from `payments` at read time — it's a cache, and this codebase's own convention elsewhere (`ArtistSettlement`/`SettlementService`'s doc comment: "artist_settlements berfungsi sebagai cache ... BUKAN sumber kebenaran") already establishes "recompute from the transactional rows, don't trust the cache" as the house style for exactly this kind of report.

**Alternatives considered**:
- *Recognize full `line_total` for any preorder with `status` at or past `dp_paid`* — rejected; directly violates FR-012 and would overcount revenue for a preorder that's only 20% paid.
- *Recognize revenue only for `handed_over` preorders (goods actually delivered)* — rejected; this is an accrual-accounting rule, not what this codebase does anywhere else (Order revenue is recognized at `completed` status, i.e. at sale/payment time, not at pickup), and it would hide real cash already collected from reports for weeks while a preorder sits in `arrived` status.
- *Attribute 100% of a partial payment to whichever artist's item happens first in the preorder* — rejected; arbitrary and would misstate individual artist settlements even though the aggregate total across all artists would happen to be correct.

## R2 — Preorder split payment: sequential calls vs. a new batch endpoint

**Decision**: `PaymentPanel.vue`'s `mode="record"` branch is changed to accumulate entries into `entries[]` exactly like `mode="checkout"` already does, and `RecordPaymentModal.vue` (or its caller in `PreordersView.vue`) submits that array as **sequential** calls to the existing, unmodified `POST /preorders/{id}/payments` endpoint — one call per entry, awaited in order. No new backend endpoint.

**Rationale**: Confirmed via code read that `POST /preorders/{id}/payments` → `PreorderController::storePayment()` → `PreorderService::recordPayment()` already validates and persists exactly one `Payment` row per call, correctly incrementing `paid_amount` and running the state-machine transition logic each time. That logic (status transitions, DP-vs-settlement `purpose` handling, 409 guards) is exactly the same logic that must run for every entry in a split — reusing it N times is strictly simpler and lower-risk than teaching a new batch endpoint to replicate the same per-payment side effects atomically. Since a Preorder's payment history is already a running ledger (unlike a POS order, which is created atomically in one request), sequential calls are also a more natural fit for this domain than an artificial single-request batch.

**Alternatives considered**:
- *New `POST /preorders/{id}/payments/batch` accepting `payments[]`, mirroring `POST /orders`* — rejected; the Order endpoint's `payments[]` handling exists because an Order is created atomically in one request with its payments as part of that same creation. A Preorder's payment-recording endpoint is fundamentally an "add one more payment to an existing running ledger" operation — forcing multiple ledger entries through one request would require either wrapping N `PaymentRecorder::record()` calls in one transaction (duplicating `recordPayment()`'s existing per-call logic) or changing that service's signature, both unnecessary complexity for the same net effect.
- *Client-side accumulate, then submit only the LAST entry as if it were the full remaining balance in one call* — rejected; this loses the itemization of which method covered which portion (violates FR-005 and the receipt's per-entry itemization).

**UX note carried to Phase 1**: since each split entry is now its own network round-trip, the frontend must show per-entry submission progress/failure state (e.g. entry 1 of 2 succeeded, entry 2 failed) rather than treating the whole split as one atomic unit — this is a real UX requirement, not just an implementation detail, and is reflected in data-model.md's state notes.

## R3 — Making split payment visible (not just possible) at POS checkout

**Decision**: `PaymentPanel.vue` gains an explicit, always-visible UI element (not gated behind `entries.length > 0`) making clear that more than one payment method can be used — e.g. a persistently-visible "payments so far" list (even when empty, showing "no split entries yet" or hidden but paired with an explicit small "+ Split payment" affordance near the method picker) and a submit button whose label changes contextually ("Add & continue" vs "Complete payment") instead of always reading the same static `submitLabel`.

**Rationale**: Confirmed via code read that today the only signal a split is happening is the retroactive appearance of a "payments so far" box **after** the first partial entry is committed (`isSplitting` computed, gated on `entries.length > 0`) — there is nothing on screen *before* that first click suggesting the capability exists at all, which is exactly the gap spec FR-001/User-Story-1 describes. The submit button's label never changes to reflect what clicking it will actually do (commit a partial entry and continue vs. finish the sale), which is the specific UX ambiguity a cashier under time pressure would trip on.

**Alternatives considered**:
- *Add a separate, explicit "Split payment" toggle that reveals a different UI mode* — rejected as an unnecessary mode switch; the existing implicit-accumulation mechanic already works correctly once entries exist, the fix needed is making its existence and current state visible at every step, not replacing the mechanic.

## R4 — Row hover coverage: `DataTable.vue` plus four other raw-table components

**Decision**: Add a hover class to `DataTable.vue`'s single `<tr>` row template (covers every screen already using the shared component) plus the four other components confirmed to render their own `<table>` markup outside `DataTable.vue`: `VariantBomModal.vue`, `ArtistTransactionsModal.vue`, `MasterDataImportModal.vue`, `ProductDetailModal.vue`.

**Rationale**: Code read confirmed `DataTable.vue`'s row markup has zero hover-related classes today, and a grep for other raw `<table>` usage found exactly these four additional components not routed through the shared component — spec FR-006 says "every data table," so all five locations are in scope, not just the shared component.

**Alternatives considered**: *Only add hover to `DataTable.vue` and treat the four standalone tables as out of scope* — rejected; would leave FR-006 only partially satisfied and contradicts the spec's explicit "every data table" wording.

## R5 — Preorder payment receipt: new component, not reuse

**Decision**: Build a new `PreorderPaymentReceiptModal.vue`, sourcing data from `GET /preorders/{id}` (which already loads and returns the `payments` relation per `PreorderController::show()`/`present()`), rather than extending `ReceiptModal.vue` or `PreorderInvoiceModal.vue`.

**Rationale**: `ReceiptModal.vue` is structurally POS-order-specific (expects `order_number`, `cashier_name`, `change_amount`, `discount_amount` — none of which map cleanly onto a Preorder payment event) but its `payment_summary[]` iteration pattern (line ~157 in the code survey) is the visual pattern to mirror, per spec FR-008's "same layout... as the existing POS sale receipt." `PreorderInvoiceModal.vue` is a different document entirely — an order confirmation showing aggregate `paid_amount`/`outstanding`, not an itemized per-payment-event receipt — and does not currently expose `payments[]` in its own payload (only `show()` does). Building the new component off `show()`'s already-available data avoids widening `getPreorderInvoice()`'s response shape for an unrelated document.

**Alternatives considered**:
- *Extend `PreorderInvoiceModal.vue` with a "receipt" display mode* — rejected; that component's `document_type`-driven heading logic (`invoice`/`receipt`/`cancelled`) already exists for a different purpose (order lifecycle state), conflating it with "which specific payment event does this receipt document" would overload one component with two distinct concerns.
- *Widen `ReceiptModal.vue` to accept an optional "preorder mode"* — rejected; the order-specific fields it assumes (cashier, change) have no Preorder equivalent, and forcing them to be optional/nullable throughout that component risks regressing the well-tested POS receipt path for a Preorder-only concern.

## R6 — Dedicated Preorder report: new endpoint, aggregate query pattern

**Decision**: Add `ReportController::preorders()` (or a small dedicated method if the controller already groups by concern — confirm during implementation whether a sibling `PreorderReportController` fits this codebase's existing controller-per-concern granularity better than one more method on the already-large `ReportController`), following the exact `sales()` pattern: a base `DB::table('preorder_items')` query joined to `preorders`, `products`, `artists`, filtered by `data_mode`/`event_id`, grouped by `preorders.status` and by a computed payment-completeness bucket (`unpaid` when `SUM(payments)=0`, `partial` when `0 < paid < total`, `paid` when `paid >= total`) — using the same live-summed-from-`payments` source as R1, for consistency and to avoid the same cache-drift risk.

**Rationale**: Confirmed via code read that no existing aggregate covers this — `GET /preorders` is a row-level list, not a summary. Mirroring `sales()`'s established base-query + `data_mode` filter + `group_by` shape keeps this new report consistent with every other report in the file rather than inventing a new query style.

**Alternatives considered**: *Compute payment-completeness in PHP after fetching raw rows* — rejected for the same Constitution V reason `sales()` already avoids row-by-row PHP aggregation: do it in one grouped SQL query.

## R7 — `PreorderItem` cost/price parity confirms profit-report merge is feasible

**Decision**: `profit()`'s preorder-revenue merge uses `PreorderItem.cost_price` exactly as `profit()` already uses `OrderItem.cost_price`, prorated by the same R1 payment-share ratio applied to `line_total`.

**Rationale**: Code read confirmed `PreorderItem` already carries the same `cost_price`/`sell_price`/`line_total` snapshot fields as `OrderItem` — no schema change needed, and the existing "historical financial data is an immutable snapshot" convention (Constitution IV) already applies identically to preorder items.

**Alternatives considered**: None needed — this is a direct structural match, not a design choice.
