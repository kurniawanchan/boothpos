# Research: Optional Payment Proof, Addable Later from Sales Detail

## Evidence (current behaviour)

- **Server**: `PaymentRecorder::record()` throws `proof_required_for_non_cash` for any non-cash payment without a `proof_token`, and refuses a token that is unknown or already linked. It is shared by `OrderService` (POS checkout), `PaymentService::addPayment()` (later payments on sales and pre-orders) and `PurchaseOrderService`.
- **Client**: `PaymentPanel.vue::canSubmitCurrent` requires `proofToken !== null` for every non-cash entry (checkout and "record" mode); the hint under the button reads `pos.proof_required_before_confirm`. Reference (`reference_optional`) and notes (`notes_optional`) are already optional.
- **Storage**: `payment_proofs` rows are created by `POST /payment-proofs` (unlinked, `proof_token`), then linked (`payment_id`) when the payment is created. `payments` already has `reference` and `notes` columns (028).
- **Payloads**: the pre-order payment presenter returns `reference`, `recorded_by_name`, `status`, `proof_id` (first proof) but not `notes`; the sale payment presenter (`OrderResource`) returns `reference`, `recorded_by_name`, `provider` but neither `notes` nor `proof_id`, and `showProof` is `false` for POS in `PaymentHistoryList`.
- **Viewing**: `PaymentProofController::show()` allows only owner/admin or `uploaded_by === user` (BOLA protection) — a proof added later by an owner could not be opened by the cashier who took the payment.
- **No edit path** exists for a payment row (028: only add and owner/admin delete); `deletePayment()` removes all proof rows and files.
- **Old tests pinning the requirement**: `OrderTest::test_non_cash_payment_without_proof_token_is_rejected`, `PreorderPaymentLedgerTest::test_a_non_cash_payment_still_needs_a_channel_and_proof`.

## Decision 1 — Relax the rule at both layers; keep purchase orders as they are

**Decision**: In `PaymentRecorder`, a missing token is no longer an error for sales/pre-orders; a *provided* token must still resolve to an unlinked proof (else 422 `proof_token_invalid`). The requirement stays when the call is for a purchase order (`$purchaseOrderId !== null`). In `PaymentPanel`, drop `proofToken` from `canSubmitCurrent` (keep the `uploading` guard so a half-finished upload cannot be confirmed), and replace the hint with a neutral one.

**Rationale**: One shared rule, two layers (server is the real boundary, UI mirrors it). PO behaviour is outside the request (spec Assumptions) and relaxing it would change a supplier-payment control nobody asked about. The DB already enforces `chk_payments_channel` (non-cash ⇒ `channel_id` NOT NULL; cash ⇒ NULL), but the request layers leave `channel_id` nullable, so until now a non-cash call without a channel was stopped only indirectly by the proof check — and with a valid token it already surfaced as a raw 500 from the constraint. Removing the proof check would widen that hole, so `PaymentRecorder` now validates it explicitly: a non-cash payment without `channel_id` is refused with a clean validation error (same `payments` message key family as the proof errors), turning a 500 into a 422/409. `PreorderPaymentLedgerTest::test_a_non_cash_payment_still_needs_a_channel_and_proof` is rewritten to assert both halves: still refused without a channel, accepted with a channel but no proof.

## Decision 2 — A dedicated, narrow "confirmation" write path (not a general payment edit)

**Decision**: `PaymentService::updateConfirmation(Preorder|Order $target, Payment $payment, array $input, User $user)`, exposed as `PATCH …/payments/{payment}/confirmation` for orders and pre-orders (same shape as the existing `POST …/payments` / `DELETE …/payments/{payment}` pair). Input keys are all optional: `proof_token`, `reference`, `notes`. Rules, in order, all after locking the target row (like `addPayment`/`deletePayment`):
1. Payment must belong to the target (else 404).
2. Target not closed for this purpose: refused (409) for a **voided** sale or a **cancelled** pre-order; allowed for a handed-over pre-order (no money effect; FR-012).
3. Payment method must be non-cash (else 422).
4. Caller must satisfy `Payment::confirmationEditableBy($user)` (owner/admin, or `recorded_by === user->id`; legacy NULL recorder → owner/admin only) — enforced here AND by the controller before calling (403).
5. Request must carry at least one of the three keys (else 422 `confirmation`).
6. Apply: `reference`/`notes` set (empty string → NULL clears); a `proof_token` must resolve to an unlinked proof, which is linked to the payment, and any current proof is marked `superseded_at = now()` (kept on disk and in the table).
7. After applying, the payment must still hold at least one of reference / notes / current proof (FR-010: an edit cannot blank the whole confirmation) else 422.
8. Activity log `payment_confirmation_updated` (old/new reference, notes, old/new proof id) written inside the same transaction.
Amount, method, channel, purpose, verification, `paid_at`, session and the target's `paid_amount`/status are never written (SC-004, FR-013).

**Rationale**: Mirrors the existing ledger discipline (single service, row lock, in-transaction audit) and keeps the "no general edit" rule of 028 intact: only descriptive evidence can change.

## Decision 3 — Replace = supersede, not delete

**Decision**: `payment_proofs.superseded_at` (nullable timestamp). The "current proof" of a payment is its non-superseded row (latest by id, defensive). Superseded rows/files are retained; `deletePayment()` keeps deleting all proof rows and files of the payment (existing behaviour, documented).

**Rationale**: Spec FR-011 (replaced proof retained for audit) and Q2=B ("every change logged"). Unlinking the old row instead (`payment_id = NULL`) would make it indistinguishable from the unlinked pre-upload rows that are garbage-collectable; a timestamp is explicit and queryable.

## Decision 4 — Viewing rule: keep BOLA protection, add the recorder

**Decision**: `PaymentProofController::show()` allows owner/admin, the uploader, or — for the payment's *current* (non-superseded) proof — the user who recorded the payment. The same predicate is exposed per payment as `can_view_proof` (so the SPA only offers "View proof" when it will work) next to `can_edit_confirmation`.

**Rationale**: Without it, "owner adds the proof later" makes the cashier's own payment proof unreadable to them, contradicting FR-009. Other cashiers remain blocked (SC-005). Superseded proofs stay viewable only by owner/admin/uploader (audit access by id).

## Decision 5 — Payload additions (both presenters), computed server-side

Each payment in sale (`OrderResource`) and pre-order (`PreorderController::present`) payloads gains: `notes`, `proof_id` (current proof; `OrderResource` didn't have it), `has_proof` (bool), `can_view_proof`, `can_edit_confirmation`. They depend on the `payments.proofs` relation: every endpoint that returns these payloads must eager-load it, and the presenters guard with `relationLoaded()` — the known "silently vanishes" trap, so the plan lists every load site (`OrderController::show/storePayment/destroyPayment/updatePaymentConfirmation`, `PreorderController` loads already include `payments.proofs`). Order list/other consumers that don't load proofs simply omit the flags (`whenLoaded`-style), never error.

## Decision 6 — Frontend

- `PaymentPanel`: proof optional (hint text neutral); `ProofCapture` stays.
- `PaymentHistoryList`: shows `notes`; for non-cash entries without a proof a "No proof" pill; "View proof" when `proof_id && can_view_proof`; "Add confirmation" / "Edit confirmation" when `can_edit_confirmation` (emit `edit-confirmation`). `showProof` becomes true for sales too.
- New `PaymentConfirmationModal.vue`: `ProofCapture` + reference + notes, prefilled; save uploads the file first (existing `uploadPaymentProof`) then PATCHes; disabled until something is entered/changed; shows the server's refusal message; note that replacing keeps the old file on record.
- `TransactionItemsModal` and `PreordersView` open the modal, refresh their order/preorder from the response, and (sales) open the proof lightbox like `PreordersView` does.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| Make `reference`/`notes`/proof editable through a general `PUT /payments/{id}` | Reopens the amount/method edit surface 028 closed deliberately; a narrow route cannot be misused to change money. |
| Delete the old proof file on replace | Violates FR-011/Q2=B audit retention; a mistaken replacement would destroy the original evidence. |
| Unlink the old proof (`payment_id = NULL`) instead of a column | Indistinguishable from the pre-upload rows meant for garbage collection; no history query possible. |
| Allow only owner/admin to view/edit | Contradicts the requester's Q1=C answer (the recording cashier completes their own records). |
| Compute `can_*` flags in the SPA from the user's role/id | The SPA is never the security boundary; the server must decide (and the rule has a legacy-NULL-recorder case). |
| Relax the proof rule for purchase orders too | Not requested; changes a supplier-payment control. |
| Block confirmation changes on handed-over pre-orders like payments | Adds friction with no benefit — a confirmation moves no money and finished orders are exactly where late proofs arrive. |

## Risks / notes

- `OrderResource` is used by several endpoints; adding proof-dependent fields must not 500 when `payments.proofs` isn't loaded — presenters guard with `relationLoaded('proofs')`.
- Pre-existing non-cash payments (all have a proof) keep displaying exactly as before (SC-006); only the new "No proof" pill/notes/actions are additive.
- A cashier whose shift is closed can still add a confirmation (no cash/shift effect) — intended.
- `proof_required_before_confirm` locale key becomes unused and is removed from both languages.
