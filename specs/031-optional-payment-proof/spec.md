# Feature Specification: Optional Payment Proof, Addable Later from Sales Detail

**Feature Branch**: `031-optional-payment-proof`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "make as optional for take photo, upload file, reference, notes — the payment confirmation can be added to the sales detail." Evidence: (1) the POS Payment screen with a QRIS (Shopee) channel selected, showing "Take photo", "Upload file", "Reference / transaction number (optional)" and "Notes (optional)", with "Confirm & save transaction" disabled and the hint "A payment proof must be attached before confirming."; (2) the Sales "Transaction details" window, whose Payments section lists each payment (method, channel, amount, date, reference, "recorded by", a Paid badge) with a Delete action.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Complete a non-cash sale without a proof (Priority: P1)

At a busy booth the customer pays by QRIS or bank transfer and the cashier wants to finish the sale right away; the proof photo, the reference number and notes can be taken care of later (or never). Today a non-cash payment cannot be confirmed until a photo or file is attached, so the cashier is stuck at the payment screen and the queue waits. The photo, the file upload, the reference and the notes must all be optional: the sale can be confirmed with none of them, with only some of them, or with all of them.

**Why this priority**: This is the blocker at the register — it slows the sale and forces a throw-away photo just to get past the button.

**Independent Test**: Add an item, choose a QRIS or bank-transfer channel at payment, attach nothing, leave reference and notes empty, and confirm. The sale is saved and the payment is recorded as a non-cash payment without a proof.

**Acceptance Scenarios**:

1. **Given** a non-cash channel is selected and nothing is attached, **When** the cashier confirms, **Then** the sale is saved and the payment is recorded with the chosen channel and amount.
2. **Given** a non-cash channel is selected and a photo or file is attached, **When** the cashier confirms, **Then** the proof is saved with the payment exactly as it is today.
3. **Given** only a reference number (or only notes) is filled in, **When** the cashier confirms, **Then** the sale is saved with what was entered.
4. **Given** the payment screen is open, **When** it is shown, **Then** it no longer says a proof must be attached, and the "Confirm & save transaction" action is available as soon as the amount/channel are valid.
5. **Given** a split payment with several non-cash entries, **When** the cashier confirms, **Then** each entry follows the same rule independently (an entry with a proof and an entry without can sit in the same sale).

---

### User Story 2 - Add the payment confirmation later from the Sales detail (Priority: P1)

After the sale, someone (the cashier at a quiet moment, or the owner when reconciling the day) opens the sale's "Transaction details", sees a non-cash payment with no proof, and adds the confirmation: take a photo or upload a file of the proof, a reference / transaction number, and notes. Once saved, the confirmation appears on that payment entry in the Sales detail, and the proof can be opened for viewing. Payments that still lack a proof are visibly marked so they are easy to follow up.

**Why this priority**: Making the proof optional at the register is only safe if the shop can complete the record afterwards; without this the proof is simply lost.

**Independent Test**: Make a non-cash sale without a proof (Story 1), open its Transaction details, add a photo, a reference and a note to the payment, save, and confirm the entry now shows them and the proof opens.

**Acceptance Scenarios**:

1. **Given** a non-cash payment without a proof, **When** the Sales detail is open, **Then** the payment entry is marked as having no proof and offers a way to add the confirmation (take photo, upload file, reference, notes).
2. **Given** the user attaches a photo or file and saves, **When** the detail refreshes, **Then** the payment entry shows the proof (viewable) and the saved reference/notes.
3. **Given** the user fills in only the reference and/or notes, **When** they save, **Then** those are stored and shown; the proof stays optional here too.
4. **Given** a payment that already has a confirmation, **When** an allowed user opens the Sales detail, **Then** the proof is shown and they can edit the reference and notes and replace the proof with another photo/file; the entry then shows the new values, and the change is recorded in the activity log.
5. **Given** a user who is not allowed to change that payment's confirmation (not owner/admin and not the cashier who recorded it), **When** they open the Sales detail, **Then** they see the reference, the notes and whether a proof exists, but no add/edit/replace action is offered; and they can open the proof file only if they are allowed to view it (see FR-014).
6. **Given** the file is not an accepted image or is too large, **When** the user tries to attach it, **Then** it is refused with a clear message and the payment is unchanged.
7. **Given** the sale was voided, **When** its detail is opened, **Then** the confirmation can be viewed but not added or changed.

---

### User Story 3 - The same optional rule when paying a pre-order (Priority: P2)

Pre-order payments are recorded through the same payment form. A deposit or settlement by transfer/QRIS must likewise be confirmable without a proof, with the confirmation added later from the pre-order's payment history, so cashiers meet one consistent rule across the product.

**Why this priority**: Consistency — the same form with a different rule would surprise users — but the reported screen is the POS checkout.

**Independent Test**: Record a pre-order deposit by bank transfer with no proof, then add the confirmation later from the pre-order's payment list.

**Acceptance Scenarios**:

1. **Given** a pre-order payment by a non-cash channel with nothing attached, **When** it is confirmed, **Then** the payment is recorded.
2. **Given** that payment appears in the pre-order's payment history, **When** the user adds a confirmation, **Then** it behaves exactly as in Story 2.

---

### Edge Cases

- Cash payments: unchanged (they never needed a proof); the "add confirmation" action is for non-cash payments.
- A file that is not a JPEG/PNG image, or larger than the limit already used at checkout: refused with a clear message, nothing saved.
- The user chooses "Take photo" but the camera is unavailable or permission is denied: they can still upload a file or continue without a proof.
- Two people open the same payment and both add a confirmation: the result is consistent (no duplicate or lost data; the second attempt is told what is already there).
- The payment is deleted while someone is adding a confirmation: the add fails gracefully with a clear message.
- Proof images remain private: viewable only inside the application by users allowed to see the transaction, never through a public link.
- A shop that always wants a proof can still attach one every time (nothing is removed from the screen).
- Reports, totals, shift cash and the payment status of the sale are unaffected by whether a proof exists.
- A completed (handed-over) pre-order can still receive a confirmation on its payments; a cancelled pre-order or voided sale cannot.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A non-cash payment MUST be confirmable without any proof photo/file, reference or notes; none of the four is required.
- **FR-002**: When a proof, reference or notes are provided at payment time, they MUST be saved with the payment exactly as before.
- **FR-003**: The payment screen MUST NOT block confirmation, nor show a message demanding a proof, when none is attached; the proof actions ("Take photo", "Upload file") remain available.
- **FR-004**: The rule in FR-001 MUST apply to every entry of a split payment, independently, and to pre-order payments recorded through the same form.
- **FR-005**: In the Sales detail, each non-cash payment entry MUST show whether it has a proof, and an entry without one MUST be visibly marked.
- **FR-006**: From the Sales detail, a user allowed to do so MUST be able to add a confirmation to a non-cash payment: a proof (take photo or upload file), a reference / transaction number, and notes — each optional, at least one needed to save.
- **FR-007**: After saving, the payment entry MUST display the reference and notes and offer to view the proof to users allowed to view it; the proof image MUST open inside the application.
- **FR-008**: Attached files MUST be restricted to the same image types and size limit as at checkout, with a clear refusal message otherwise.
- **FR-009**: Only an owner/admin, or the cashier who recorded that specific payment, MUST be able to add, edit or replace its confirmation later; every other user can only see its reference, notes and whether a proof exists. A payment with no recorded cashier (older records) is changeable by owner/admin only.
- **FR-010**: An existing confirmation MUST be changeable by those allowed in FR-009: the reference and notes can be edited and the proof can be replaced by another photo/file. The proof, reference and notes cannot be left entirely empty by an edit that would remove everything (removing a proof outright is not offered; replacing it is).
- **FR-011**: Every addition, edit or replacement of a confirmation MUST be recorded in the activity log (who, which payment, what was added/changed, and that a proof was replaced) like other changes to a transaction; a replaced proof file MUST be retained for audit and no longer shown on the entry.
- **FR-012**: The confirmation of a voided sale or a cancelled pre-order MUST remain viewable but MUST NOT be changeable. A completed (handed-over) pre-order's payments MAY still receive a confirmation, since doing so affects no money.
- **FR-013**: Adding a confirmation MUST NOT change the payment's amount, method, channel, date, the sale's totals, payment status, shift cash figures, or any report.
- **FR-014**: Proof files MUST remain private to the application (never reachable by an unauthenticated or public address). The existing viewing rule — owner/admin or the user who uploaded the file — stays, extended so the cashier who recorded the payment can also open a proof that someone else added to it; no other user can open it.
- **FR-015**: The behaviour in FR-005–FR-008 and FR-011–FR-012 MUST be the same wherever a payment history is shown for a sale or pre-order.

### Key Entities

- **Payment**: One recorded payment on a sale or pre-order (method, channel, amount, date, recorded-by). Gains an optional confirmation.
- **Payment confirmation**: The optional extra evidence of a non-cash payment — a proof image/file, a reference / transaction number, and notes.
- **Proof file**: The private image of the confirmation, viewable only inside the application.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A cashier can finish a non-cash sale without attaching anything in the same number of steps as a cash sale (no step is blocked by a missing proof).
- **SC-002**: 100% of non-cash payments confirmed without a proof are saved and appear in the Sales detail marked as "no proof".
- **SC-003**: A user can add a photo, reference and notes to an existing payment from the Sales detail in under 1 minute, and it shows on the entry immediately after saving.
- **SC-004**: Totals, payment status, shift cash and reports for any sale are identical before and after a confirmation is added (0 differences).
- **SC-005**: No proof file is reachable without being signed in and allowed to see that transaction (0 public links).
- **SC-006**: Payments that were recorded with a proof before this change display exactly as before (no regression).

## Assumptions

- The optional rule applies everywhere the shared payment form is used (POS checkout, split payments, pre-order payments), not only the POS screen in the screenshot, to keep one consistent rule; the request's screenshot is the POS checkout and the Sales detail.
- "Payment confirmation" means the bundle of proof photo/file, reference / transaction number and notes; the Sales detail is the place to add it for sales, and the pre-order's payment history for pre-orders (same component).
- Cash payments never had a proof and are out of the "add confirmation" flow; only non-cash payments get it.
- Whether a non-cash payment is later "verified" against the bank statement is a separate concern and is not changed by having or lacking a proof.
- The accepted file types and the size limit are the ones already used when attaching a proof at checkout.
- Today a proof file can be opened only by an owner/admin or by the user who uploaded it (a deliberate protection against one cashier reading another's proofs). That protection stays (FR-014). Everyone who can open a transaction's details already sees its payments; changing a confirmation is narrower (FR-009), decided by the requester: owner/admin plus the cashier who recorded the payment.
- Payments to suppliers on purchase orders are out of scope: their rules are unchanged.
- A replaced proof is kept (not deleted) so the activity log can always point to the original evidence; it is simply no longer displayed on the entry. Deleting a whole payment (existing owner/admin action) behaves as today.
