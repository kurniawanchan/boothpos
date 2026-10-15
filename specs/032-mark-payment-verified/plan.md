# Implementation Plan: Mark a Non-Cash Payment as Verified

**Branch**: `032-mark-payment-verified` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/032-mark-payment-verified/spec.md`

## Summary

Every non-cash payment is stored `verification = 'pending'` (shown as "Not verified" in the Sales list) and nothing ever changes it. The columns to record the outcome already exist (`payments.verified_by`, `verified_at`; `verification` enum `pending|verified|rejected`), so this feature is the missing **action**, its **audit**, and its **UI**:

1. **Single action** — `POST /orders/{order}/payments/{payment}/verify` and `POST /preorders/{preorder}/payments/{payment}/verify` → `PaymentService::markVerified()` (row-locked, one-way `pending → verified`, activity log `payment_verified` inside the same transaction). Allowed for owner/admin, or any user **except the payment's recorder** (separation of duties; legacy NULL recorder ⇒ anyone). Cash, rejected, already-verified payments and voided/cancelled targets are refused (409/422); there is **no undo** (decision) — no route, no service method, no UI.
2. **Bulk action** — `POST /orders/verify-payments` `{ order_ids: [...] }` (Sales list selection): runs the same service per pending non-cash payment, each in its own transaction, skipping (not failing) payments the caller can't verify, and returns a summary (`verified`, `skipped` by reason).
3. **Display** — payment payloads (`OrderResource`, `PreorderController::present`) gain `verified_by_name`, `verified_at`, `can_verify` (server-computed); `PaymentHistoryList` shows a "Not verified"/"Verified by X · date" marker plus a "Mark verified" action behind a confirm dialog ("cannot be undone"); the Sales list gets a "Mark verified (N)" bulk button next to Export, used together with the existing payment-state filter (`pstate=pending` = "Needs verification").

Nothing money-related changes: amounts, paid amount, payment status, expected shift cash (cash only) and reports are untouched (`PaymentSummary`/reports only exclude `rejected`, never read `pending` vs `verified`). One intended visible consequence: the Cashier Session summary's per-method breakdown has always counted only verified payments, so a verified QRIS payment now appears there (research Decision 6).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (service, controller actions, routes); Vue 3 SPA (Vite)

**Primary Dependencies**: none added

**Storage**: MySQL 8 — **no schema change** (uses existing `payments.verified_by`, `verified_at`)

**Testing**: PHPUnit on the host (`.env.testing`/`boothpos_test`): new `PaymentVerificationTest`; Vitest for `PaymentHistoryList`, `TransactionItemsModal`, `PreordersView`, `SalesView`; real-browser check on an ISOLATED server + test DB (it writes data)

**Target Platform**: Local Laravel app + browser SPA

**Project Type**: Web application (backend + frontend)

**Performance Goals**: Bulk verify of ~30 selected sales in one request well under 2 s (one query batch to load orders+payments, one short transaction per verified payment); payload flags computed in memory

**Constraints**: One-way transition only; server-side authorization for every write; every state change audited in the same transaction; no change to any money figure; DEMO/LIVE scoping via the normal scoped model lookups; payments' `rejected` state untouched

**Scale/Scope**: 1 model rule + 1 relation, 1 service method (+ bulk wrapper), 3 routes, 1 form request, 2 controller actions + bulk, 2 presenters, `PaymentHistoryList` + 3 callers, locales (en/id, backend + frontend), OpenAPI, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / single write path | **Pass.** `PaymentRecorder` stays the only creator of `payments` rows; verification is a second narrowly-scoped mutation in `PaymentService` next to `updateConfirmation`/`deletePayment`, writing only `verification`/`verified_by`/`verified_at`. "Who may verify" lives once (`Payment::mayVerify()`), "may this payment be verified at all" once (`Payment::isVerifiable()`), both reused by the service, controllers, bulk wrapper and presenters (`can_verify`). The bulk action is a loop over the single-payment service, not a second implementation. |
| II. Testing | **Pass (planned).** Authorization matrix incl. recorder-excluded and legacy NULL recorder, state matrix (pending/verified/rejected/cash), closed targets, idempotence/double-click/race, "no money change", audit rows, bulk summary + skips, DEMO/LIVE; component tests for list/modals/Sales bulk; real-browser end-to-end on an isolated server. |
| III. UX consistency | **Pass.** Reuses `StatusPill`, `ConfirmDialog`, the shared `PaymentHistoryList`, the Sales list's existing selection/Export toolbar and `pstate` filter; strings in both locales. |
| IV. Security | **Pass.** Server decides (403 for the recorder; no un-verify path exists at all); row lock + status guard make verification single-shot (no double ticks, no duplicate log rows); bulk caps the id list and never trusts client-provided state; audit inside the transaction; no new public surface. |
| V. Performance | **Pass.** Bulk loads orders with `payments` in one query batch (no N+1), writes only changed rows; presenters use eager-loaded `verifier`. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` moves with the 3 routes and the new payment fields (PRD §9.5); `CLAUDE.md` "Payment ledger" section gains the verification rules. |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/032-mark-payment-verified/
├── plan.md
├── research.md                         # decisions + alternatives
├── data-model.md                       # state machine; no schema change
├── quickstart.md                       # automated + isolated real-browser verification
├── contracts/payment-verification.md   # endpoints, payload additions, authz/state matrix
├── checklists/requirements.md
└── tasks.md                            # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Models/Payment.php                              # verifier() relation, isVerifiable(), mayVerify()
app/Services/PaymentService.php                     # markVerified(), verifyOrderPayments() (bulk wrapper)
app/Http/Requests/VerifyOrderPaymentsRequest.php    # NEW: order_ids[] (1..200, distinct ints)
app/Http/Controllers/Api/OrderController.php        # verifyPayment(), verifyPayments(); eager-load payments.verifier
app/Http/Controllers/Api/PreorderController.php     # verifyPayment(); present(): verification fields
app/Http/Resources/OrderResource.php                # payments[]: verified_by_name, verified_at, can_verify
app/Services/PreorderService.php                    # PAYLOAD_RELATIONS += payments.verifier; delegate
routes/api.php                                      # 3 routes
lang/{en,id}/orders_payments.php                    # new messages
resources/js/api/payments.js                        # verifyPayment(), verifyOrderPayments()
resources/js/components/payment/PaymentHistoryList.vue   # marker + "Mark verified"
resources/js/components/sales/TransactionItemsModal.vue  # wire action + confirm
resources/js/views/PreordersView.vue                # wire action + confirm
resources/js/views/SalesView.vue                    # bulk "Mark verified (N)" + confirm + summary
resources/js/locales/{en,id}.json                   # strings
docs/openapi-pos-mvp.yaml, CLAUDE.md
tests/Feature/PaymentVerificationTest.php (new)
qa-tests/component/{PaymentHistoryList,TransactionItemsModal,PreordersView,SalesView}.test.js
```

**Structure Decision**: Extend the payment ledger modules from features 028/031; no new top-level concepts and no migration.

## Complexity Tracking

No constitution violations to justify.
