# Implementation Plan: Partial and Split Payments with Payment Summary

**Branch**: `028-partial-split-payment` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/028-partial-split-payment/spec.md`

## Summary

Make a payment a first-class, independently saved ledger entry for **both pre-orders and POS sales**. One shared server-side service records a payment (amount ≤ remaining balance, idempotent, row-locked, audited) and returns a derived **payment summary** (grand total, total paid, remaining, status Unpaid / Partially Paid / Fully Paid, payment count). The UI replaces the "collect entries in a dialog until they cover the balance" behaviour with a single **Add Payment** dialog that saves immediately, a shared **summary card** and **history list**, and clear Partial / Multiple / Fully Paid states.

POS gets two additions: a sale may be **completed partly paid** when a customer is attached, and a partly paid sale can later be settled from the Sales page. Because later cash arrives in a *different shift* than the sale, every payment records the cashier session it was **received in** (`payments.session_id`), and shift reconciliation (`close()`, `summary()`, the Sales shift panel) is rebased on that. See [research.md](./research.md) Decisions 1–12.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing); Vue 3 + Pinia + Vite (existing)

**Primary Dependencies**: None new. Reuses `PaymentRecorder`, `ActivityLogger`, `PreorderService`, `OrderService`, `PaymentPanel`, `BaseModal`, `ConfirmDialog`, `StatusPill`, `vue-i18n`

**Storage**: MySQL 8 — one migration on `payments` (4 nullable columns + indexes/FK + backfill). No new table. `orders` and `preorders` keep their existing cache columns; order payment status is *derived*

**Testing**: PHPUnit feature tests (real MySQL `boothpos_test`); Vitest component tests under `qa-tests/`; real-browser verification of Pre-orders, POS checkout and Sales (Constitution II)

**Target Platform**: Single-store local install (native or Docker), SPA over `localhost`

**Project Type**: Web application (Laravel API + Vue SPA)

**Performance Goals**: Saving a payment and re-rendering the summary feels instant (< 1 s on the local box); Sales list keeps its single-query row building (payments already eager-loaded)

**Constraints**: Server computes every amount; payments immutable except the audited owner/admin delete; duplicate submissions can never double-record; shift cash must stay reconcilable; `docs/openapi-pos-mvp.yaml` updated in the same commit as route/response changes; Indonesian comments/commit messages, UI copy in both locale files

**Scale/Scope**: Booth/store scale — tens of payments per transaction at most, hundreds of transactions per event

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 design — still passes.*

| Principle | Assessment |
|---|---|
| I. Single write path / clean code | **Pass.** Payments are still created only by `PaymentRecorder::record()` (extended with `reference`, `client_ref`, `session_id`, `recorded_by`). A new `PaymentService` is the single place that validates balance/idempotency/locking and updates the cache + status for *both* targets, so `PreorderService::recordPayment()` and the new order path cannot drift. No stock path is touched. |
| II. Testing | **Pass (planned).** Feature tests for the service, both endpoints, partial checkout, shift reconciliation, idempotency/concurrency, deletion; Vitest for the new components; browser check in quickstart.md. One existing test that locks the *old* no-overpay behaviour is rewritten deliberately (research Decision 3). |
| III. UX consistency | **Pass.** Token classes/existing components only; 422 = bad amount, 409 = business conflict (closed, voided, no open shift), 403 = role; delete action hidden (not disabled) for roles that cannot use it; Add Payment hidden when fully paid/closed (state-based, with the fully-paid banner explaining why); UI copy in both locales, comments/commits in Indonesian. |
| IV. Security | **Pass.** Amount, balance, status and session are server-derived — the client sends only method/amount/channel/proof/reference/notes/`client_ref`. Authorization enforced server-side (record = any authenticated user like checkout today; delete = owner/admin; all three mechanisms checked: no policy covers payments, inline `isOwnerOrAdmin()` is used for delete). Payment entries are never mutated; every record/delete is written to `activity_logs` inside the same transaction. Proof files stay on the private disk. |
| V. Performance | **Pass.** The summary is computed from already-loaded payment collections (no per-row queries); the Sales list already eager-loads `payments`; new relation `recorder` is eager-loaded where history is shown. |
| Stack constraints | MySQL 8; migration prefix `2026_10_31_000001` sorts after the last one (`2026_10_30_000001`); no remote/push involved. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` updated with the routes/fields in the same commit; PRD gets a dated post-MVP note (reverses the pre-order "no overpay guard" behaviour documented in a test comment). |

No unjustified violations → Complexity Tracking not required. Two *deliberate behaviour reversals* are called out instead: (1) pre-order overpayment is now refused (spec FR-008); (2) cash shift attribution moves from "the order's shift" to "the shift the money was received in" (FR-027).

## Project Structure

### Documentation (this feature)

```text
specs/028-partial-split-payment/
├── plan.md
├── research.md          # Decisions 1–12
├── data-model.md        # payments columns, summary formulas, session attribution, state rules
├── quickstart.md        # automated + browser verification script
├── contracts/
│   └── api-deltas.md    # new/changed endpoints and payload fields
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/PaymentService.php              # NEW — addPayment(), deletePayment(), summarize()
├── Services/PaymentRecorder.php             # + reference, client_ref, session_id, recorded_by
├── Services/PreorderService.php             # recordPayment()/deletePayment() delegate to PaymentService
├── Services/OrderService.php                # create(): allow partial (customer required); payments get session_id
├── Services/SalesTransactionsService.php    # + paid/balance/payment_status per row; sessions[].cash_received
├── Support/PaymentSummary.php               # NEW — pure formulas (grand total, paid, remaining, status)
├── Models/Payment.php, Order.php, Preorder.php   # fillable, recorder() relation, summary helpers
├── Http/Controllers/Api/OrderController.php      # + storePayment(), destroyPayment(); receipt() fields
├── Http/Controllers/Api/PreorderController.php   # present(): payment_summary + richer payments; storePayment uses service
├── Http/Controllers/Api/CashierSessionController.php  # close()/summary() on payments.session_id
├── Http/Requests/StorePaymentRequest.php    # NEW — shared shape rules for both targets
├── Http/Requests/StoreOrderRequest.php      # (rules unchanged; partial handled in service)
└── Http/Resources/OrderResource.php         # + payment summary, payments reference/recorder/status

database/migrations/2026_10_31_000001_add_ledger_columns_to_payments_table.php   # NEW

routes/api.php                               # + POST/DELETE /orders/{order}/payments[/{payment}]
lang/{en,id}/orders_payments.php, preorders.php   # new messages
docs/openapi-pos-mvp.yaml, docs/PRD-POS-Event-Multivendor.md, CLAUDE.md

resources/js/
├── api/orders.js, api/preorders.js          # + addOrderPayment(), deleteOrderPayment(); client_ref on add
├── components/payment/PaymentSummaryCard.vue    # NEW
├── components/payment/PaymentHistoryList.vue    # NEW
├── components/payment/AddPaymentModal.vue       # NEW — replaces RecordPaymentModal.vue
├── components/payment/PaymentPanel.vue          # mode="record" stops accumulating; partial finish in checkout
├── components/payment/PosPaymentModal.vue       # partial-with-customer confirmation
├── components/sales/TransactionItemsModal.vue   # summary + history + Add Payment for POS sales
├── components/receipt/ReceiptModal.vue  # paid / remaining / status lines
├── composables/useSalesFilters.js           # payment-status filter, outstanding total, shift cash from session.cash_received
├── views/PreordersView.vue                  # use the shared components (summary/history/Add Payment)
├── views/SalesView.vue                      # payment-status column/filter/outstanding
├── views/PosView.vue                        # pass customer to the payment modal; partial result handling
└── locales/{en,id}.json

tests/Feature/
├── PaymentServiceTest.php, PosPartialPaymentTest.php, PreorderPaymentLedgerTest.php,
│   ShiftCashAttributionTest.php, PaymentIdempotencyTest.php (names indicative)
qa-tests/component/
├── PaymentSummaryCard.test.js, PaymentHistoryList.test.js, AddPaymentModal.test.js (+ updates to
│   PaymentPanel/PosView/TransactionItemsModal/SalesView/RecordPaymentModal tests)
```

**Structure Decision**: Existing Laravel + Vue web app. Backend: one new service + one pure summary helper + one migration; frontend: three new shared components that both Pre-orders and Sales/POS consume. No new top-level directories.

## Complexity Tracking

No constitution violations to justify.
