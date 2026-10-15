# Implementation Plan: Optional Payment Proof, Addable Later from Sales Detail

**Branch**: `031-optional-payment-proof` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/031-optional-payment-proof/spec.md`

## Summary

Two changes on the shared payment ledger (features 028/024):

1. **The proof stops being mandatory.** A non-cash payment is rejected today at two layers: `PaymentRecorder::record()` (server — "proof required for non-cash") and `PaymentPanel.vue` (`canSubmitCurrent` needs `proofToken` for non-cash, plus the hint "A payment proof must be attached before confirming"). Both are relaxed for sales and pre-orders (photo/file, reference and notes all optional). Purchase-order payments keep their rule (out of scope). A provided-but-invalid token is still refused.
2. **A confirmation can be added later.** A new, narrowly-scoped write path on an existing payment: `PATCH /orders/{order}/payments/{payment}/confirmation` and `PATCH /preorders/{preorder}/payments/{payment}/confirmation` (body: optional `proof_token`, `reference`, `notes`). It lives in `PaymentService::updateConfirmation()` (row-locked, audited in the same transaction), is allowed only for owner/admin or the cashier who recorded that payment, applies to non-cash payments of open targets (not voided / not cancelled), never touches amounts/status/shift cash, and *replaces* a proof by marking the old `payment_proofs` row `superseded_at` (file kept for audit). The Sales detail and the Pre-order detail share `PaymentHistoryList`, which gains a "No proof" marker, notes, and an add/edit action opening a new `PaymentConfirmationModal` (reusing `ProofCapture`).

One discovered constraint (spec corrected): proof files are viewable only by owner/admin or the uploader (`PaymentProofController::show`). That BOLA protection stays; it is extended so the **recorder** of the payment can open a proof someone else added, and the payment payload carries server-computed `can_view_proof` / `can_edit_confirmation` flags so the SPA never guesses.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (service + controllers + one migration); Vue 3 SPA (Vite)

**Primary Dependencies**: none added (reuses `ProofCapture.vue`, `uploadPaymentProof`, `getPaymentProofBlobUrl`, `ImageLightbox`)

**Storage**: MySQL 8 — one nullable column `payment_proofs.superseded_at`; proof files on the private `local` disk as today

**Testing**: PHPUnit on the host (`.env.testing`/`boothpos_test`) — new `PaymentConfirmationTest` + rewritten assertions of the old "proof required" tests; Vitest for `PaymentPanel`, `PaymentHistoryList`, new `PaymentConfirmationModal`, `TransactionItemsModal`, `PreordersView`; real-browser check on an ISOLATED server + test DB (the check writes data)

**Target Platform**: Local Laravel app + browser SPA

**Project Type**: Web application (backend + frontend)

**Performance Goals**: No extra request per list row (proofs eager-loaded with payments); confirmation save is one upload + one PATCH

**Constraints**: Payments rows are still created ONLY by `PaymentRecorder`; amounts/method/channel/date/status/totals/shift cash are immutable through this feature (SC-004); object-level authorization enforced server-side (never the SPA); file rules identical to checkout (JPEG/PNG ≤ 5 MB); purchase-order payment rule unchanged

**Scale/Scope**: 1 migration, 1 service method, 2 controller actions + 1 form request + 2 routes, presenters (2), proof-view authorization, `PaymentRecorder` relaxation, 1 new SPA component + 3 edited components, locales (en/id, backend + frontend), OpenAPI, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / single write path | **Pass.** `PaymentRecorder` stays the only creator of `payments` rows; the only mutation after creation (besides the existing delete) is `PaymentService::updateConfirmation()`, restricted to `reference`/`notes` + the proof link. The editability rule lives once (`Payment::confirmationEditableBy()`), used by the service, controller guard and presenter flag. The "what is the current proof" rule lives once (`Payment::currentProof()`), used by both presenters. UI: one list component and one new modal shared by Sales and Pre-orders. |
| II. Testing | **Pass (planned).** Backend authorization matrix, closed-target rules, replace/audit, totals-unchanged, viewing rule; the two old "proof required" tests are rewritten, not deleted; frontend tests per component; real-browser end-to-end on an isolated server (offline-first, writes only to the test DB). |
| III. UX consistency | **Pass.** Same capture widget, same reference/notes inputs, same list/pill styling; strings in both `en.json` and `id.json`. |
| IV. Security | **Pass.** Server-side object-level authz on write (owner/admin or recorder; 403 otherwise) and on read (`can_view_proof` mirrors `PaymentProofController::show` + recorder); token consumed once and only if unlinked; uploads validated as today (mime sniffed, 5 MB, random name, private disk); change audited inside the transaction; no new public URL. |
| V. Performance | **Pass.** `payments.proofs` is eager-loaded in every payload that renders payments (no N+1); flags computed in memory. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` moves with the new routes/fields (PRD §9.5); `CLAUDE.md` "Payment ledger" section updated (it states "History entries have no edit path"). |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/031-optional-payment-proof/
├── plan.md
├── research.md                       # decisions + alternatives
├── data-model.md                     # superseded_at; derived flags
├── quickstart.md                     # automated + isolated real-browser verification
├── contracts/payment-confirmation.md # endpoints, payload additions, authz matrix, errors
├── checklists/requirements.md
└── tasks.md                          # created later by /speckit-tasks
```

### Source Code (repository root)

```text
database/migrations/2026_11_01_000001_add_superseded_at_to_payment_proofs_table.php
app/Models/Payment.php                              # currentProof(), confirmationEditableBy(), proofViewableBy()
app/Models/PaymentProof.php                         # superseded_at fillable/cast
app/Services/PaymentRecorder.php                    # proof optional (PO keeps required)
app/Services/PaymentService.php                     # updateConfirmation()
app/Http/Requests/UpdatePaymentConfirmationRequest.php
app/Http/Controllers/Api/OrderController.php        # updatePaymentConfirmation(); eager-load payments.proofs
app/Http/Controllers/Api/PreorderController.php     # updatePaymentConfirmation(); present(): notes, flags
app/Http/Controllers/Api/PaymentProofController.php # show(): recorder may view the current proof
app/Http/Resources/OrderResource.php                # payments[]: notes, proof_id, has_proof, can_* flags
routes/api.php                                      # 2 PATCH routes
lang/{en,id}/orders_payments.php                    # new messages
resources/js/api/payments.js (or orders/preorders)  # updatePaymentConfirmation
resources/js/components/payment/PaymentPanel.vue    # proof optional + hint
resources/js/components/payment/PaymentHistoryList.vue
resources/js/components/payment/PaymentConfirmationModal.vue   # NEW
resources/js/components/sales/TransactionItemsModal.vue
resources/js/views/PreordersView.vue
resources/js/locales/{en,id}.json
docs/openapi-pos-mvp.yaml, CLAUDE.md
tests/Feature/PaymentConfirmationTest.php (new), OrderTest.php, PreorderPaymentLedgerTest.php
qa-tests/component/{PaymentPanel,PaymentHistoryList,PaymentConfirmationModal,TransactionItemsModal,PreordersView}.test.js
```

**Structure Decision**: Extend the existing payment ledger modules; no new top-level concepts.

## Complexity Tracking

No constitution violations to justify.
