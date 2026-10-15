---

description: "Task list for Partial and Split Payments with Payment Summary"
---

# Tasks: Partial and Split Payments with Payment Summary

**Input**: Design documents from `/specs/028-partial-split-payment/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–12), data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: INCLUDED. Constitution II makes tests mandatory (backend in `tests/Feature/` against real MySQL, frontend in `qa-tests/`, plus a real-browser check). Test tasks precede the implementation they cover.

**Organization**: Grouped by user story — US1 Record a partial payment (P1) · US2 Add more until fully paid (P1) · US3 Summary + auditable history (P2) · US4 Safe flow: overpayment, duplicates, feedback (P2) · US5 POS partial sale + settle later + shift attribution (P2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US5, only on user-story phase tasks
- Code comments, UI copy and commit messages are in Indonesian (project convention); UI strings go in BOTH `resources/js/locales/{en,id}.json`, backend messages in BOTH `lang/{en,id}/…`

## Safety reminders (CLAUDE.md + this machine's history)

- Run backend tests on the host, or in Docker ONLY as `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …` — a bare run in the container wipes the dev database. Never run `migrate:fresh`, `db:wipe` or `db:seed` against the dev database.
- The Docker VM disk filled up once and froze MySQL commits (the whole API hung). Check free space before long test/build runs.
- Migration date prefix `2026_10_31_000001` is load-bearing; never rename.
- `PreorderController::present()` / `OrderResource` silently drop relations that were not eager-loaded — every endpoint that returns these payloads must load `payments.recorder`/`payments.channel`.
- Browser verification uses an isolated server + `boothpos_test`, not the dev database.

---

## Phase 1: Setup

**Purpose**: Confirm a safe, green baseline before touching payments. No new dependencies are introduced.

- [X] T001 Check Docker disk space (`docker compose exec mysql df -h /var/lib/mysql` must show free space — a full disk hung MySQL commits before), confirm `.env.testing` points at `boothpos_test`, then run on the host `php artisan test --filter='Payment|Order|Preorder|Session|Sales|Report'` and record the passing counts (`tests/Feature/*Test.php`)

---

## Phase 2: Foundational (blocks every user story)

**Purpose**: Schema, the pure summary maths, the extended single row-writer and shared request/lang plumbing that all stories read.

**⚠️ CRITICAL**: No user-story work can begin until this phase is complete.

- [X] T002 Create migration `database/migrations/2026_10_31_000001_add_ledger_columns_to_payments_table.php`: add nullable `reference` string(100), `client_ref` char(36) **unique**, `session_id` FK → `cashier_sessions` (`nullOnDelete`, indexed), `recorded_by` FK → `users` (`nullOnDelete`); backfill `payments.session_id` from `orders.session_id` for order payments; working `down()`; Indonesian docblock explaining each column (data-model.md)
- [X] T003 Update `app/Models/Payment.php`: add the four columns to `$fillable`, add `recorder(): BelongsTo` (User via `recorded_by`) and `session(): BelongsTo` (CashierSession)
- [X] T004 [P] Write `tests/Feature/PaymentSummaryTest.php` for the pure maths (research Decision 2): pre-order total paid = Σ non-rejected payments; order total paid = Σ − `change_amount`; remaining never negative and rounded to 2 dp; status `unpaid`/`partially_paid`/`fully_paid`; `payment_count`; rejected entries excluded
- [X] T005 Create `app/Support/PaymentSummary.php` (pure, no I/O): `PaymentSummary::for(Preorder|Order $target, ?Collection $payments = null): array` returning `{grand_total, total_paid, remaining, status, payment_count}` as money strings per data-model.md; add `paymentSummary()` helpers on `app/Models/Preorder.php` and `app/Models/Order.php`; make `PaymentSummaryTest` pass
- [X] T006 Extend `app/Services/PaymentRecorder.php::record()` to accept and store `reference`, `client_ref`, `session_id` and `recorded_by` (all optional so `PurchaseOrderService` and existing callers keep working); it stays the ONLY creator of `Payment` rows
- [X] T007 [P] Create `app/Http/Requests/StorePaymentRequest.php`: `method` required in cash|bank_transfer|qr_ewallet, `amount` numeric > 0, `channel_id` nullable exists, `proof_token` nullable uuid, `reference` nullable string max 100, `notes` nullable string max 1000, `purpose` sometimes in full|down_payment|settlement, `client_ref` required uuid; `authorize()` = authenticated
- [X] T008 [P] Add the new backend messages to `lang/en/orders_payments.php` and `lang/id/orders_payments.php`: `payment_exceeds_balance` (with `:max`), `payment_already_fully_paid`, `payment_target_closed`, `payment_session_required`, `payment_client_ref_conflict`, `customer_required_for_partial_payment`, `payment_delete_closed_shift`
- [X] T009 Run the migration on the dev database through the container (`docker compose exec app php artisan migrate` — NEVER `migrate:fresh`/`db:wipe`), then run `php artisan test --filter='PaymentSummaryTest|PreorderPaymentDeleteTest|OrderTest'` on the host to confirm no regression

---

## Phase 3: User Story 1 — Record a partial payment that is saved immediately (Priority: P1) 🎯 MVP

**Goal**: On a pre-order, a payment smaller than the balance is saved at once as its own entry; the summary shows the new Total Paid, Remaining Balance and a Partially Paid status.

**Independent Test**: Record Rp 400.000 on a Rp 1.000.000 pre-order and confirm the entry is in the history, Total Paid = Rp 400.000, Remaining = Rp 600.000, status Partially Paid — without the dialog needing to "cover" the balance.

### Tests for User Story 1 ⚠️ write first

- [X] T010 [P] [US1] Create `tests/Feature/PreorderPaymentLedgerTest.php`: a partial payment returns 201 and is stored immediately; response carries `payment_summary` (grand_total / total_paid / remaining / `partially_paid` / count 1) and `payments[]` with `reference`, `recorded_by_name`, `status`; the entry stores `reference` and `recorded_by`; the existing lifecycle is unchanged (first payment `ordered→dp_paid`, `arrived` + full → `settled`); amount 0, negative and non-numeric → 422; non-cash without channel/proof is still refused
- [X] T011 [P] [US1] Create `qa-tests/component/PaymentSummaryCard.test.js`: shows Grand Total, Total Paid, Remaining and the status pill for unpaid and partially-paid states
- [X] T012 [P] [US1] Create `qa-tests/component/AddPaymentModal.test.js`: opens with the amount defaulted to the remaining balance; a single click on Save calls the injected submit function once with `{method, amount, channel_id, proof_token, reference, notes, client_ref}`; Save is disabled for amount ≤ 0 or above the remaining balance; emits `saved` on success

### Implementation for User Story 1

- [X] T013 [US1] Create `app/Services/PaymentService.php` with `addPayment($target, array $input, User $user)` for a **Preorder** target: `DB::transaction` + `lockForUpdate()` re-read, 409 `payment_target_closed` for `handed_over`/`cancelled`, call `PaymentRecorder::record()` with `recorded_by`/`reference`, recompute `preorders.paid_amount` from `PaymentSummary`, move the status exactly as `PreorderService::recordPayment()` does today (`ordered→dp_paid`; `arrived` + fully paid → `settled`), write the `payment_recorded` activity-log row in the same transaction, return `->fresh(PreorderService::PAYLOAD_RELATIONS)`. Target lookups stay Eloquent-scoped (DEMO/LIVE safe); Indonesian comments explaining why the status transitions live here
- [X] T014 [US1] Make `PreorderService::recordPayment()` in `app/Services/PreorderService.php` a thin delegate to `PaymentService::addPayment()` (keep its signature); add `payments.recorder` to `PreorderService::PAYLOAD_RELATIONS` and to every `fresh([...'payments.proofs'...])` / `load()` that feeds `PreorderController::present()` (CLAUDE.md relationLoaded trap)
- [X] T015 [US1] Update `app/Http/Controllers/Api/PreorderController.php`: `storePayment()` takes `StorePaymentRequest` and delegates to the service; `present()` adds `payment_summary` and enriches each `payments[]` entry with `reference`, `recorded_by_name`, `status` (`paid`/`rejected` per research Decision 9); `show()` eager-loads `payments.recorder`
- [X] T016 [P] [US1] Create `resources/js/components/payment/PaymentSummaryCard.vue`: Grand Total, Total Paid (shown as a deduction), Remaining Balance (prominent), Payment Status pill (Unpaid / Partially Paid), built from design tokens only; props = a `payment_summary` object
- [X] T017 [US1] Update `resources/js/components/payment/PaymentPanel.vue`: `mode="record"` no longer accumulates `entries[]` — Save emits one payload immediately; add an optional `reference` input (record mode); cap the amount at the remaining balance for every method in record mode; keep `mode="checkout"` behaviour untouched; update `qa-tests/component/PaymentPanel.test.js` accordingly
- [X] T018 [US1] Create `resources/js/components/payment/AddPaymentModal.vue` (target-agnostic): props `open`, `remaining`, `title`, `purpose`, `submitFn`; shows the remaining balance banner, wraps `PaymentPanel mode="record"`, saves on one click via `submitFn`, toasts and emits `saved`/`close`; replaces the accumulate-then-sequential-submit machinery of `RecordPaymentModal`
- [X] T019 [US1] Wire `resources/js/views/PreordersView.vue`: render `PaymentSummaryCard` at the top of the Payment card, open `AddPaymentModal` (submit via `createPreorderPayment` in `resources/js/api/preorders.js`) instead of `RecordPaymentModal`, and after a save refresh the detail, the list (`load()`) and the summary cards (`loadSummary()`)
- [X] T020 [US1] Delete `resources/js/components/payment/RecordPaymentModal.vue` and `qa-tests/component/RecordPaymentModal.test.js`; update the cases in `qa-tests/component/PreordersView.test.js` that referenced the old modal
- [X] T021 [US1] Add the US1 UI strings (summary card labels, "Add Payment", reference field, status labels Unpaid/Partially Paid) to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T022 [US1] Document the pre-order changes in `docs/openapi-pos-mvp.yaml` (`POST /preorders/{id}/payments` body `reference`/`client_ref`, response `payment_summary` + enriched `payments[]`; schema additions) in the same commit as the code
- [X] T023 [US1] Run `php artisan test --filter='PreorderPaymentLedgerTest|PreorderTest|SplitPaymentTest|PreorderPaymentDeleteTest'` and `npm test`; fix until green

---

## Phase 4: User Story 2 — Add more payments until the transaction is fully paid (Priority: P1)

**Goal**: Repeated payments by any method update the summary each time; at zero remaining the transaction is Fully Paid and Add Payment is gone.

**Independent Test**: Record Rp 200.000 cash + Rp 200.000 transfer, then Rp 600.000 QRIS on a Rp 1.000.000 pre-order → Fully Paid, Remaining Rp 0, no Add Payment.

### Tests for User Story 2 ⚠️ write first

- [X] T024 [P] [US2] Add to `tests/Feature/PreorderPaymentLedgerTest.php`: two partials then the remainder → `fully_paid`, `remaining` exactly `0.00`, `paid_amount` = total; with the order `arrived` the last payment moves it to `settled`; a different method per payment is stored per entry
- [X] T025 [P] [US2] Add to `qa-tests/component/PaymentSummaryCard.test.js` and `qa-tests/component/PreordersView.test.js`: after a first payment the label reads "Partially Paid"; with ≥ 2 payments it reads "Partially Paid · 2 payments"; at zero remaining a Fully Paid banner is shown and the Add Payment button is absent; Add Payment is absent for `handed_over`/`cancelled`; reopening the dialog defaults the amount to the NEW remaining

### Implementation for User Story 2

- [X] T026 [US2] Extend `resources/js/components/payment/PaymentSummaryCard.vue`: payment-count wording ("Partially Paid · N payments"), a distinct visual treatment and banner for Fully Paid (clear "Fully Paid" indicator), distinct Unpaid / Partially Paid / Multiple payments / Fully Paid states (FR-019)
- [X] T027 [US2] Update `resources/js/views/PreordersView.vue`: show Add Payment only while `remaining > 0` on an open pre-order, replace it with the Fully Paid banner at zero, and remove the old "Record settlement"/status-based gating that conflicts
- [X] T028 [US2] Update `resources/js/components/payment/AddPaymentModal.vue`: recompute the default amount from the freshly loaded remaining on every open; title "Add Payment" (first payment) vs "Add another payment"; add the US2 strings to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T029 [US2] Run `php artisan test --filter='PreorderPaymentLedgerTest'` and `npm test`; fix until green

---

## Phase 5: User Story 3 — Payment summary and auditable payment history (Priority: P2)

**Goal**: A history list under the summary shows every payment (method, amount, date/time, reference, recorder, status); entries cannot be edited; the only removal is the audited owner/admin delete.

**Independent Test**: Record two payments (one with a reference); confirm every history line shows all fields, the summary equals the sums, there is no edit action, and deletion recalculates and is logged.

### Tests for User Story 3 ⚠️ write first

- [X] T030 [P] [US3] Add to `tests/Feature/PreorderPaymentLedgerTest.php` and `tests/Feature/PreorderPaymentDeleteTest.php`: history order and fields in the payload; `payment_recorded` activity row holds amount/method/reference/status/total_paid/remaining; the delete response carries the recalculated `payment_summary`; the `payment_deleted` row records old/new figures; there is no update route for a payment (PUT/PATCH → 404/405); a cashier cannot delete (403)
- [X] T031 [P] [US3] Create `qa-tests/component/PaymentHistoryList.test.js`: renders method, amount, date/time, reference (nothing when empty), recorder and status per line in recorded order; shows the empty-history message; exposes a delete action only when `canDelete` is true and never an edit action

### Implementation for User Story 3

- [X] T032 [US3] Move `PreorderService::deletePayment()` logic into `PaymentService::deletePayment()` (`app/Services/PaymentService.php`), keep `PreorderService::deletePayment()` as a delegate; recompute cache/status from `PaymentSummary`, write `payment_deleted` with old/new values inside the transaction, return the fresh payload including `payment_summary`; behaviour for pre-orders stays exactly as built in feature 027
- [X] T033 [P] [US3] Create `resources/js/components/payment/PaymentHistoryList.vue`: props `payments`, `canDelete`; lines show method (+ provider), amount, `formatDateTime(paid_at)`, reference when present, recorded-by name, status ("Paid"/"Rejected"); named slots/events for View proof, Payment invoice and Delete; empty-state message
- [X] T034 [US3] Update `resources/js/views/PreordersView.vue`: replace the inline payment rows with `PaymentHistoryList`, keeping View proof, Payment invoice and the owner/admin Delete + confirm dialog wired as before
- [X] T035 [US3] Add the US3 strings to `resources/js/locales/en.json` and `resources/js/locales/id.json`; update `docs/openapi-pos-mvp.yaml` for the delete response (`payment_summary`) and the entry fields
- [X] T036 [US3] Run `php artisan test --filter='PreorderPaymentLedgerTest|PreorderPaymentDeleteTest'` and `npm test`; fix until green

---

## Phase 6: User Story 4 — Safe, understandable payment flow (Priority: P2)

**Goal**: Overpayment is refused, a double click or retry never records twice, concurrent payments cannot overshoot, and every save gives clear feedback.

**Independent Test**: Double-click Save → one entry; amount above the remaining → refused with the maximum; save → confirmation with amount and new remaining (or "Fully Paid").

### Tests for User Story 4 ⚠️ write first

- [X] T037 [P] [US4] Create `tests/Feature/PaymentIdempotencyTest.php`: same `client_ref` twice on one pre-order → one row and the second call returns 200 with the same state; a ref already used on another transaction → 422 `payment_client_ref_conflict`; inserting a duplicate `client_ref` directly violates the unique index; amount above remaining → 422 with `errors.amount` naming the maximum; a fully paid pre-order → 409 `payment_already_fully_paid`; closed → 409; two sequential requests where the second would overshoot the balance left by the first → 422 with the fresh remaining
- [X] T038 [P] [US4] Rewrite `PreorderTest::test_third_sequential_call_after_fully_paid_is_still_accepted_no_overpay_guard_exists` in `tests/Feature/PreorderTest.php` into the new rule (third call after Fully Paid → 409; an over-balance single payment → 422) and update its docblock to say the old behaviour was deliberately reversed by feature 028
- [X] T039 [P] [US4] Extend `qa-tests/component/AddPaymentModal.test.js`: a double click calls the submit function once; the SAME `client_ref` is reused when retrying after a failure and a NEW one is generated after a success; Save shows a loading state and stays disabled while saving; a server error keeps the entered data; a network error shows the "outcome unknown — check the history before retrying" message; success toast states the amount recorded and the new remaining, or that the transaction is now fully paid

### Implementation for User Story 4

- [X] T040 [US4] Harden `PaymentService::addPayment()` in `app/Services/PaymentService.php`: after the row lock recompute the remaining from the fresh payment sum and refuse `amount > remaining` (422, `errors.amount` with `:max`) and a fully paid target (409); look up `client_ref` first — same target returns the current state flagged as a replay, another target → 422; map a unique-index collision to the replay path
- [X] T041 [US4] Update `PreorderController::storePayment()` in `app/Http/Controllers/Api/PreorderController.php`: respond 201 for a new payment and 200 for a replay; map service `ValidationException` statuses (422 amount/ref, 409 state) via `$e->status`
- [X] T042 [US4] Update `resources/js/components/payment/AddPaymentModal.vue` and `resources/js/api/preorders.js`: generate `client_ref` with `crypto.randomUUID()` when the dialog opens, reuse on retry, regenerate after success; disable Save while saving; keep the dialog and its data on error; no automatic re-submission anywhere
- [X] T043 [US4] Add the US4 strings (maximum-payable message, outcome-unknown message, success confirmations) to `resources/js/locales/en.json` and `resources/js/locales/id.json`; document the 422/409/200-replay behaviour and the overpayment reversal in `docs/openapi-pos-mvp.yaml`
- [X] T044 [US4] Run `php artisan test --filter='PaymentIdempotencyTest|PreorderTest|PreorderPaymentLedgerTest'` and `npm test`; fix until green

---

## Phase 7: User Story 5 — Leave a POS sale partly paid and settle it later (Priority: P2)

**Goal**: A POS sale can be completed with less than the total paid (customer required), shows the same summary/history/Add Payment on the Sales page, and later cash counts in the shift it was received in.

**Independent Test**: Sell Rp 500.000, pay Rp 200.000 with a customer attached → Partially Paid; from the Sales page add Rp 300.000 in a later shift → Fully Paid, with each shift's expected cash correct.

### Tests for User Story 5 ⚠️ write first

- [X] T045 [P] [US5] Create `tests/Feature/PosPartialPaymentTest.php`: partial checkout without a customer → 422 `customer_required_for_partial_payment`; with a customer → order `completed`, stock deducted, `change_amount` 0, payments stored with `session_id` = the order's shift and `recorded_by`; `OrderResource` returns `payment_summary` (`partially_paid`) and enriched `payments[]`; a fully paid checkout (including cash change) is unchanged; `POST /orders/{id}/payments`: cash needs the user's open shift (409 `payment_session_required`) and stores its `session_id`, non-cash works without a shift, amount ≤ remaining (422), fully paid/voided → 409, `client_ref` idempotency; `DELETE /orders/{id}/payments/{payment}`: owner/admin only, voided → 409, cash of a CLOSED shift → 409, otherwise recalculates
- [X] T046 [P] [US5] Create `tests/Feature/ShiftCashAttributionTest.php`: shift A sells Rp 500.000 and receives Rp 200.000 cash, closes; shift B receives Rp 300.000 → A's stored `expected_cash` counts only its own cash (minus its change), B's expected cash includes the 300.000; `GET /sessions/{id}/summary` `by_method` follows `payments.session_id`; voided orders' payments excluded; pre-order payments excluded; existing `OrderTest` shift-close expectations still pass
- [X] T047 [P] [US5] Extend `tests/Feature/SalesTransactionsTest.php`, `tests/Feature/SalesPageDataTest.php` and `tests/Feature/SalesTransactionsExportTest.php`: rows carry `paid_amount`, `balance_amount`, `payment_status`; `sessions[]` carries `cash_received` and includes a shift that only received late payments; totals of `/reports/sales` are unchanged; the export file adds paid/balance columns and its `payment_status` column now holds the new Unpaid/Partially Paid/Fully Paid value; add a receipt case (`GET /orders/{id}/receipt` returns `paid_amount`, `balance_amount`, `payment_status` and per-payment `reference`)
- [X] T048 [P] [US5] Extend frontend tests: `qa-tests/component/PaymentPanel.test.js` and `PosView.test.js` (partial finish is offered only with a customer, behind a confirmation dialog, otherwise a hint asks to attach a customer); `TransactionItemsModal.test.js` (summary + history + Add Payment on a partly paid sale, hidden when fully paid/voided, delete only for owner/admin); `SalesView.test.js`/`SalesViewAdvanced.test.js` (payment-status filter, outstanding total of the listed sales, shift panel uses `session.cash_received`); `ReceiptModal.test.js` (paid / remaining / status lines)

### Implementation for User Story 5

- [X] T049 [US5] Update `app/Services/OrderService.php::create()`: replace the unconditional `payment_insufficient` with the partial rule (paid < total requires `customer_id`, else 422 `customer_required_for_partial_payment`); keep ≥ 1 payment; `change_amount` 0 for partial sales; pass `session_id`, `recorded_by` and per-payment `reference` to `PaymentRecorder`; `local_ref` idempotency untouched; add `reference` to the `payments.*` rules in `app/Http/Requests/StoreOrderRequest.php`
- [X] T050 [US5] Extend `PaymentService::addPayment()`/`deletePayment()` in `app/Services/PaymentService.php` for an **Order** target: summary with change, `voided` = closed, cache `orders.paid_amount`, cash requires the recording user's open `CashierSession` (409 `payment_session_required`) stored as `session_id`, non-cash records it when present, delete refused (409 `payment_delete_closed_shift`) for a cash payment whose shift is closed; audit rows for both actions
- [X] T051 [US5] Update `app/Http/Controllers/Api/OrderController.php` and `routes/api.php`: add `storePayment()` (any authenticated user, 201/200 replay) and `destroyPayment()` (`isOwnerOrAdmin()`, 403 otherwise) with routes `POST /orders/{order}/payments` and `DELETE /orders/{order}/payments/{payment}` beside the other `/orders/{order}/…` routes; `show()` eager-loads `payments.channel` and `payments.recorder`; `receipt()` adds `paid_amount`, `balance_amount`, `payment_status` and per-payment `reference`/`paid_at`
- [X] T052 [P] [US5] Update `app/Http/Resources/OrderResource.php`: add `payment_summary` and enrich `payments[]` with `reference`, `recorded_by_name`, `status`, `session_id` (all `whenLoaded`-safe)
- [X] T053 [US5] Rebase shift cash in `app/Http/Controllers/Api/CashierSessionController.php`: `close()` expected cash = opening + Σ verified CASH `payments.session_id = shift` for non-voided orders − Σ `change_amount` of non-voided orders created in the shift (keep the existing BUG-comment style); `summary()` `by_method` from `payments.session_id`; pre-order payments (null `session_id`) stay excluded
- [X] T054 [US5] Update `app/Services/SalesTransactionsService.php`: each row adds `paid_amount`, `balance_amount`, `payment_status` (`unpaid`/`partially_paid`/`fully_paid` via `PaymentSummary`); `sessions[]` adds `cash_received` and also lists shifts that only received late payments (eager-load payments' sessions to avoid N+1); update the export mapping in `app/Http/Controllers/Api/ReportController.php` (`exportSalesTransactions`) to add `paid`/`balance` columns and use the new `payment_status`
- [X] T055 [P] [US5] Add `addOrderPayment(orderId, payload)` and `deleteOrderPayment(orderId, paymentId)` to `resources/js/api/orders.js`
- [X] T056 [US5] Update `resources/js/components/payment/PaymentPanel.vue`, `resources/js/components/payment/PosPaymentModal.vue` and `resources/js/views/PosView.vue`: pass the selected customer into the modal; in checkout mode offer "Complete with remaining balance" only when a customer is attached and the entered payments are < the total, behind a `ConfirmDialog`; otherwise show a hint to attach a customer; surface the server 422 `customer_required_for_partial_payment` clearly
- [X] T057 [US5] Update `resources/js/components/sales/TransactionItemsModal.vue`: show `PaymentSummaryCard` + `PaymentHistoryList` for the sale, an Add Payment button (via `AddPaymentModal` + `addOrderPayment`) while a balance remains on a non-voided sale, owner/admin Delete (with confirm), and emit the existing `changed` event after any change; replace the old "pending" pill with the shared "Paid" status
- [X] T058 [US5] Update `resources/js/composables/useSalesFilters.js` and `resources/js/views/SalesView.vue`: payment-status column/pill and filter (Unpaid / Partially Paid / Fully Paid), an Outstanding total for the listed non-voided rows, and the shift panel reading `session.cash_received` when present (fallback to the per-row sum for older payloads)
- [X] T059 [US5] Update `resources/js/components/receipt/ReceiptModal.vue` to print amount paid, remaining balance and payment status for a partially paid sale
- [X] T060 [US5] Add the US5 strings to `resources/js/locales/en.json` and `resources/js/locales/id.json`; document `POST/DELETE /orders/{id}/payments`, the partial `POST /orders`, `OrderResource`/receipt/sales-row fields and the shift-attribution change in `docs/openapi-pos-mvp.yaml`
- [X] T061 [US5] Run `php artisan test --filter='PosPartialPaymentTest|ShiftCashAttributionTest|SalesTransactionsTest|SalesPageDataTest|SalesTransactionsExportTest|OrderTest|SplitPaymentTest'` and `npm test`; fix until green

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T062 [P] Add a dated post-MVP note to `docs/PRD-POS-Event-Multivendor.md` (partial/split payments for pre-orders and POS sales; pre-order overpayment now refused; cash attributed to the receiving shift; purchase orders explicitly excluded)
- [X] T063 [P] Add a "Payment ledger" section to `CLAUDE.md` (single `PaymentService` + `PaymentRecorder` write path, derived summary, `client_ref` idempotency, `payments.session_id` shift attribution and its closed-shift delete guard, partial POS sale needs a customer, reports unchanged) and keep the SPECKIT plan pointer current
- [X] T064 Run the full backend suite on the host (`php artisan test`; Docker only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`), `npm test` and `npm run build`; record counts and fix every regression (watch `ReportTest`, `PreorderReportTest`, `SalesPageDataTest`, `OrderTest`, `PurchaseOrderTest`)
- [X] T065 Execute every step of `specs/028-partial-split-payment/quickstart.md` in a real browser against an ISOLATED server and the `boothpos_test` database (never the dev database): pre-order multi-payment flow, guards and duplicate protection, POS partial checkout, settling later from Sales, two-shift cash scenario, owner delete, EN↔ID, clean console; record the evidence
- [X] T066 Final diff review against the constitution: no raw hex, no second payment write path, amounts never taken from the client, activity-log rows inside the transactions, `openapi-pos-mvp.yaml` moved in the same commits, Indonesian comments on every non-obvious decision (including the two deliberate reversals)


## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories → Polish.** Phase 2 blocks everything.
- **US1** is the MVP (pre-order partial payment saved immediately + summary). **US2** builds on US1's service and components. **US3** (history/audit) and **US4** (guards/idempotency/feedback) both extend `PaymentService` and `AddPaymentModal` from US1 and can follow in either order, but US4's overpay guard must exist before US5 ships. **US5** (POS) depends on US1–US4's service/components and on the Phase 2 migration (`session_id`, `recorded_by`).
- Within a story: tests (fail first) → service → controller/route → frontend → docs → green run.

### Same-file serialisation (cannot be [P] together)

`app/Services/PaymentService.php` (US1, US3, US4, US5) · `app/Services/PreorderService.php` (US1, US3) · `app/Http/Controllers/Api/PreorderController.php` (US1, US4) · `app/Http/Controllers/Api/OrderController.php` + `routes/api.php` (US5) · `resources/js/views/PreordersView.vue` (US1, US2, US3) · `resources/js/components/payment/AddPaymentModal.vue` (US1, US2, US4) · `resources/js/components/payment/PaymentPanel.vue` (US1, US5) · `resources/js/components/payment/PaymentSummaryCard.vue` (US1, US2) · `resources/js/locales/{en,id}.json` and `docs/openapi-pos-mvp.yaml` (every story) · `tests/Feature/PreorderPaymentLedgerTest.php` (US1–US3) · `qa-tests/component/AddPaymentModal.test.js` (US1, US4) · `qa-tests/component/PreordersView.test.js` (US1, US2).

## Parallel examples

**Phase 2**: after the migration and `Payment` model land, `PaymentSummaryTest`, `StorePaymentRequest` and the lang keys run in parallel; `PaymentSummary` and `PaymentRecorder` follow.

**US1**: `PreorderPaymentLedgerTest`, `PaymentSummaryCard.test.js` and `AddPaymentModal.test.js` are written together; `PaymentSummaryCard.vue` runs in parallel with the backend service work.

**US5**: the four test tasks run in parallel; `OrderResource`, `api/orders.js` run in parallel with the service/controller work; the Sales-page and POS frontend tasks follow the API tasks.

## Implementation strategy

1. **MVP**: Phases 1–3 (US1) — partial pre-order payments saved immediately with a live summary. Stop and validate in the browser.
2. + **US2** (fully-paid flow), then **US3** (history/audit) and **US4** (guards) — together these complete the pre-order experience and reverse the overpayment behaviour deliberately.
3. + **US5** (POS partial sales + shift attribution) — the riskiest slice; keep it behind `ShiftCashAttributionTest` and the isolated-browser check.
4. Polish before any merge.

### Notes

- Commit per story (Indonesian messages, Co-Authored-By trailer); do not push without the developer's explicit instruction.
- Each checkpoint is a safe stopping point: the app keeps working, and earlier stories stay demoable.
