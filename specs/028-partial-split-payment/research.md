# Research: Partial and Split Payments

Findings come from reading `OrderService`, `OrderController`, `PreorderService::recordPayment()/deletePayment()`, `PaymentRecorder`, `CashierSessionController::close()/summary()`, `SalesTransactionsService`, `useSalesFilters.js`, `PaymentPanel.vue`, `RecordPaymentModal.vue`, `TransactionItemsModal.vue`, and the `payments` schema. No `NEEDS CLARIFICATION` remains (scope was answered: pre-orders **and** POS sales).

## What exists today (facts that shape the plan)

- **Pre-orders**: payments are already a ledger (`POST /preorders/{id}/payments`, `paid_amount` cache, status auto-moves `ordered→dp_paid`, `arrived→settled`). There is **no overpayment guard** — locked in on purpose by `PreorderTest::test_third_sequential_call_after_fully_paid_is_still_accepted_no_overpay_guard_exists`. The dialog (`RecordPaymentModal` + `PaymentPanel mode="record"`) holds entries client-side until they cover the balance, then submits them one by one.
- **POS orders**: created in one request with a `payments[]` array; `OrderService::create()` *rejects* `paid < total` (`payment_insufficient`); `orders.paid_amount` is the **tendered** sum (can exceed total) and `change_amount` the change.
- **Payments table**: no reference number, no author, no shift. `verification` (`pending|verified|rejected`) exists but **nothing ever changes it** (non-cash stays `pending`), while the Sales popup shows a "pending" pill for it.
- **Shift cash** (`close()`, `summary()`, and the Sales shift panel via `cash_amount`/`session_id` per order) is attributed through **`orders.session_id`** — correct only while an order is paid entirely inside its own shift.

## Decision 1 — One `PaymentService` for both targets; `PaymentRecorder` stays the only row writer

**Decision**: New `App\Services\PaymentService` with `addPayment($target, $input, $user)` and `deletePayment($target, $payment, $user)`, where `$target` is a `Preorder` or an `Order`. `PreorderService::recordPayment()` and its existing `deletePayment()` become thin delegates. `PaymentRecorder::record()` remains the sole creator of `Payment` rows (extended, see Decision 5).

**Rationale**: Constitution I — payment recording must have exactly one implementation. The balance guard, idempotency, locking, cache update, status transition and audit row are identical for both targets; only the "what does Fully Paid do to my status" hook differs (pre-order lifecycle vs. derived POS status).

**Alternatives rejected**: duplicating the logic in `OrderService` (guaranteed drift); a polymorphic payments table rewrite (far larger than needed — `payments` already has `order_id`/`preorder_id`).

## Decision 2 — Summary is derived from the entries, never trusted from a stored figure

**Decision**: `App\Support\PaymentSummary` computes, from a target and its payments:
`counted = payments where verification != 'rejected'`; **Total Paid** = Σ counted amount (for an *order*: minus `change_amount`, because order payments store the tendered cash); **Remaining** = max(0, Grand Total − Total Paid); **Status** = `unpaid` (no counted payment) / `partially_paid` (remaining > 0) / `fully_paid` (remaining = 0, rounded to 2 dp); **count** = counted payments. Grand Total is `preorders.total_amount` / `orders.total_amount`.
The existing cache columns (`preorders.paid_amount`, `orders.paid_amount`) are kept and rewritten from the same formula on every add/delete, so existing readers (reports, list rows, `outstanding()`) keep working.

**Rationale**: Spec FR-007 ("always computed from the recorded entries"); matches the codebase's earlier lesson that cached `paid_amount` can drift. **Rejected payments** are excluded because `SalesTransactionsService` already excludes them from cash/non-cash and the column can only become `rejected` through a future workflow.

**Alternatives rejected**: a stored `orders.payment_status` column (a second source of truth to keep in sync — derive instead; the Sales list needs it only per row, computed from the already-loaded payments).

## Decision 3 — Overpayment is refused (reverses the pre-order "no guard" behaviour)

**Decision**: `amount` must be `> 0` and `≤ remaining` (422 `payment_exceeds_balance` with the maximum in the message and `errors.amount`). Applies to pre-orders and POS late payments. POS *checkout* keeps its existing cash-change logic untouched (the change is computed at order creation only).

**Rationale**: Spec FR-008. The old pre-order behaviour was documented as "existing, not intended"; `PreorderTest` line ~387 is rewritten to assert the new refusal, and the change is called out in the PRD note.

**Edge**: a fully paid or closed target returns **409** (not 422), because the *state* — not the number — is the problem.

## Decision 4 — Idempotency via a client-generated `client_ref`

**Decision**: The client sends a UUID `client_ref` generated when the Add Payment dialog opens (regenerated after each success). `payments.client_ref` is nullable and **unique**. `addPayment()` first looks it up: if a payment with that ref exists *for the same target*, it returns the current summary with `200` instead of creating another; a ref belonging to another target → 422. The unique index is the backstop for two truly simultaneous identical requests.

**Rationale**: FR-020 (double click / retry after timeout). Mirrors the existing `orders.local_ref` idempotency pattern. The button is also disabled while saving, but the server guarantee is what matters.

**Alternatives rejected**: time-window duplicate heuristics (amount + method within N seconds) — would wrongly block two genuine equal instalments; client-only disabling (bypassable, loses retry safety).

## Decision 5 — `payments` gains four nullable columns

`reference` (string 100, the user's reference/transaction number), `client_ref` (uuid, unique), `session_id` (FK `cashier_sessions`, nullOnDelete — the shift the money was received in), `recorded_by` (FK `users`, nullOnDelete — who entered it; needed for the history and FR-011). Backfill in the same migration: `session_id = orders.session_id` for payments of orders; `recorded_by` stays NULL for old rows (history shows "—").

## Decision 6 — Concurrency: lock the target row and recompute inside the transaction

**Decision**: `addPayment()` runs in `DB::transaction`, `lockForUpdate()` re-reads the target, re-evaluates closed/voided state and the remaining balance from the **fresh** payment sum, then validates the amount. A second user's payment that would overshoot is refused with the up-to-date remaining (FR-021). Same pattern as `PreorderService::performSplit()`.

## Decision 7 — Shift attribution: cash is counted in the shift it was received in

**Decision**: Every payment stores `session_id`. For checkout payments it is the order's session; for a later payment it is the **recording user's open session**. Cash requires one (409 `payment_session_required` otherwise); non-cash records it when present. `CashierSessionController::close()` expected cash becomes: opening + Σ(cash payments with `session_id = this shift`, order not voided, `verification` verified) − Σ(`change_amount` of orders created in this shift, not voided). `summary()`'s `by_method` is rebuilt on `payments.session_id`. Pre-order payments have `session_id = NULL` and stay outside shift cash exactly as today.
`SalesTransactionsService` adds `sessions[].cash_received` (same formula) and also lists shifts that only received late payments; `useSalesFilters` uses `session.cash_received` for the shift panel when present (fallback to the per-row sum for older payloads).

**Rationale**: FR-027/SC-008. Without this, cash taken later would be attributed to an already-closed shift whose stored `expected_cash` can never change, or would silently go missing from the open shift.

**Delete interplay**: deleting a **cash** payment whose shift is **closed** is refused (409) — it would invalidate a stored reconciliation; other deletions (non-cash, or cash in the still-open shift) are allowed for owner/admin.

**Alternatives rejected**: attributing late cash to the original order's shift (breaks closed-shift reconciliation); a separate "receivables" cash account (over-engineered for booth scale).

## Decision 8 — POS partial checkout rules

**Decision**: `OrderService::create()` replaces the unconditional `payment_insufficient` with: if `paid < total`, require `customer_id` (422 `customer_required_for_partial_payment`); at least one payment is still required (`payments min:1`). The order is created `completed` exactly like a full sale (stock deducted, receipt issued); its payment status is derived (`partially_paid`). `change_amount` stays 0 for partial sales. The client shows an explicit confirmation before completing a sale with a balance, and only offers it when a customer is attached.

**Rationale**: FR-001a/FR-024/FR-025/FR-029. Keeping the sale `completed` preserves every report's accrual semantics (sales counted when completed; outstanding is a separate figure).

## Decision 9 — Payment status vs. verification

**Decision**: Do not touch `verification`. Entries count toward Total Paid unless `rejected`. The history labels a non-rejected entry **"Paid"**; a rejected entry (only possible via a future workflow or imported data) is labelled "Rejected" and excluded. The Sales popup's existing "pending" pill for non-cash entries is replaced by the same "Paid" label so the two screens agree.

**Rationale**: Nothing can ever verify a non-cash payment today, so counting only `verified` would make every transfer/QRIS payment uncountable. Documented as an assumption in the spec.

## Decision 10 — Immutability and audit

Entries have no update path. `PaymentService` writes `payment_recorded` and `payment_deleted` activity-log rows **inside the transaction** (`entity_type` `Preorder` or `Order`, `old_values/new_values` with amount, method, reference, status and paid totals). The existing owner/admin delete (from the 027 follow-up) is generalised to POS sales via `DELETE /orders/{id}/payments/{payment}` with the same rules (not for voided orders; closed-shift cash guard above).

## Decision 11 — Frontend structure

**Decision**: Three shared components — `PaymentSummaryCard` (grand total, total paid, remaining, status pill, "Partially Paid · N payments", Fully Paid banner), `PaymentHistoryList` (method, amount, date/time, reference, recorder, status, plus proof/receipt/delete slots) and `AddPaymentModal` — consumed by `PreordersView` and `TransactionItemsModal`. `AddPaymentModal` shows the remaining balance, defaults the amount to it, and **saves on one click** (loading state, `client_ref`, success toast "Rp X recorded · Rp Y remaining" or "Fully paid", stays open with data on failure and says "check the history before retrying" when the outcome is unknown). `PaymentPanel` `mode="record"` stops accumulating entries (that accumulation was only needed for deferring submission); `mode="checkout"` keeps its split entries (one request creates the order) and gains the partial-with-customer finish. `RecordPaymentModal` and its sequential-submit machinery are removed with their test replaced.

**Rationale**: Reuse; the same summary/history must appear on both transaction types (FR-014/FR-017); fewer states than the old sequential per-entry retry UI.

## Decision 12 — Reports and settlements are intentionally unchanged

Sales/profit/artist-settlement reports keep reading `orders`/`order_items` (accrual at completion), so FR-029 needs **no report change**; only the Sales *page* gains payment-status fields and an outstanding figure computed from rows. Pre-order revenue recognition (feature 010, "cash actually collected") already reads live payment sums and automatically reflects added/deleted payments. Voiding a partly paid sale keeps today's behaviour (stock returns, payments remain, refund outside the system).

## Decision 13 — Implementation notes recorded during the build

- **Status codes**: `OrderController::store()` maps every `ValidationException` from the service to 409, so the partial-checkout customer rule is **409** (not 422 as first drafted); `POST /orders/{id}/payments` follows `$e->status` (422 amount / ref, 409 state). `client_ref` is optional on the server (older callers and scripts keep working) and always sent by the SPA.
- **Replay signalling**: `PaymentService::addPayment(..., ?bool &$replayed)` reports a replay through an out parameter so the controller can answer 200 vs 201 without changing the return type used by `PreorderService::recordPayment()` (and the report tests that call it directly).
- **Order cache semantic**: `orders.paid_amount` stays the TENDERED sum (total paid + change), so readers that expect `paid_amount ≥ total_amount` for sales paid with change are unaffected.
- **Checkout audit**: POS checkout payments are not individually logged; the order row (cashier + time) is their audit trail. Late payments and all deletions are logged.
- **Report fixtures**: three `ReportTest` cases relied on a fixture quirk (a pre-order recorded through the service kept status `ordered` because the DB default is not visible on the model returned by `create()`); with the locked fresh read the payment correctly moves it to `dp_paid`, as the API always did.

