# Feature Specification: Mark a Non-Cash Payment as Verified

**Feature Branch**: `032-mark-payment-verified` (not yet created — see Notes)

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "add mark verified" — context: the Sales list shows a "Not verified" badge on every QRIS / bank-transfer sale. Every non-cash payment is recorded as "pending verification" (waiting to be checked against the bank or e-wallet statement), but nothing in the product can ever change it, so the badge never goes away and the shop has no way to record that a payment has been reconciled.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mark a payment as verified after checking the statement (Priority: P1)

At the end of the day (or when the bank/e-wallet statement arrives), an owner or admin opens a sale's Transaction details, compares the QRIS or transfer payment with the statement, and marks the payment as **verified**. From then on the payment shows as verified instead of "Not verified", and the sale's badge in the Sales list updates accordingly.

**Why this priority**: This is the whole point of the request: today the "Not verified" state is permanent and meaningless, so reconciliation cannot be recorded anywhere.

**Independent Test**: Make a QRIS sale (it shows "Not verified"), open its details as an owner, mark the payment verified, and confirm the payment and the Sales list row now show it as verified.

**Acceptance Scenarios**:

1. **Given** a non-cash payment that is "Not verified", **When** an allowed user marks it verified from the sale's details, **Then** the payment shows as verified and the Sales list row no longer shows "Not verified" for that payment.
2. **Given** a sale with two non-cash payments (a split payment) and only one is marked verified, **When** the Sales list is shown, **Then** the row still shows "Not verified" (the worst state among its payments) until the second is verified too.
3. **Given** a cash payment, **When** its details are shown, **Then** no "mark verified" action is offered (cash is verified on the spot, as today).
4. **Given** a user who is not allowed to verify this payment — the cashier who recorded it — **When** they open the same details, **Then** they see the verification state but no action to change it.
7. **Given** an owner/admin or a cashier other than the one who recorded the payment, **When** they open the details, **Then** the verify action is offered.
5. **Given** a voided sale, **When** its details are shown, **Then** payments cannot be marked verified.
6. **Given** a payment that is already verified, **When** the user tries to verify it again (stale screen, double click), **Then** nothing changes and the user is told it is already verified.

---

### User Story 2 - See who verified it and when (Priority: P1)

Once verified, the payment entry shows who marked it verified and when, so the shop can tell that reconciliation really happened and by whom. Every change of verification state is written to the activity log.

**Why this priority**: Verification is a financial control; a bare tick without a name and time is not an audit trail.

**Independent Test**: Mark a payment verified, then check the entry shows the verifier's name and the date/time, and the activity log has a matching entry.

**Acceptance Scenarios**:

1. **Given** a payment was just marked verified, **When** its entry is shown, **Then** it shows "Verified by <name> · <date/time>".
2. **Given** the same action, **When** the activity log is opened, **Then** a record shows who verified which payment of which transaction, when, and the previous and new state.
3. **Given** payments verified before this feature existed (cash), **When** they are shown, **Then** they display exactly as before (no "verified by" is invented).

---

### User Story 3 - Verify several payments at once from the Sales list (Priority: P2)

When a day has many QRIS/transfer sales, opening each one is slow. From the Sales list, an owner/admin can select several transactions and mark all their pending non-cash payments verified in one action, with a clear summary of how many were verified and how many were skipped (already verified, cash, voided).

**Why this priority**: A real convenience for busy days, but the single-payment action already solves the core need.

**Independent Test**: Make three QRIS sales, select them in the Sales list, choose "Mark verified", and confirm all three payments are verified and the summary says 3 verified.

**Acceptance Scenarios**:

1. **Given** several selected transactions with pending non-cash payments, **When** the user chooses "Mark verified", **Then** every pending non-cash payment of those transactions becomes verified and a summary reports how many were verified.
2. **Given** a selection that includes already-verified, cash-only or voided transactions, **When** the action runs, **Then** those are skipped without error and counted as skipped in the summary.
3. **Given** a selection that includes payments the user recorded themselves (and the user is not owner/admin), **When** the action runs, **Then** those payments are skipped and counted as skipped (the summary says they were recorded by the user), while the others are verified.
4. **Given** a user who cannot verify any payment in the selection, **When** the action runs, **Then** nothing changes and the summary says so.

---

### Verification is final (decision, 2026-10-04)

Once a payment is marked verified it stays verified: the product offers no way to set it back to "Not verified". A mistaken tick is therefore permanent; the activity log shows who ticked it, so the shop can follow up outside the product. (Chosen by the requester over an undo action.)

### Edge Cases

- A payment that was rejected (an existing state with no way to set it today): it is not changed by this feature; rejecting a payment is out of scope.
- Two people verify the same payment at the same moment: the result is a single verified state with one verifier recorded, and the second person is told it was already verified.
- A transaction that has both cash and non-cash payments: only the non-cash payments can be verified; the cash one stays as it is.
- A pre-order's payment history: non-cash payments show their verification state (today they only show "Paid") and can be verified the same way.
- Payments that were verified/pending before this feature: unchanged, displayed with their current state.
- Totals, paid amounts, payment status, shift cash and reports: not changed by verification (see FR-010).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: An allowed user MUST be able to mark a single pending non-cash payment as verified from the transaction's details (sale and pre-order payment history).
- **FR-002**: Cash payments MUST NOT offer a verification action (they are verified when recorded, as today).
- **FR-003**: After verification, the payment entry MUST show it as verified, and the Sales list "payment state" MUST be recomputed from the payments' states (worst state first: rejected, then not verified, then verified).
- **FR-004**: A verified payment MUST record and display who verified it and when.
- **FR-005**: Every change of verification state MUST be written to the activity log (who, which payment and transaction, previous state, new state).
- **FR-006**: Verification MUST be refused (with a clear message) for voided sales and cancelled pre-orders, and MUST be idempotent for an already-verified payment (no duplicate record, user told it is already verified).
- **FR-007**: A payment's verification MAY be marked by an owner/admin, or by any other user with access to the transaction EXCEPT the user who recorded that specific payment (separation of duties); the recorder sees the state without any action. A payment with no recorded user (older records) can be verified by any such user.
- **FR-008**: From the Sales list, an allowed user MUST be able to verify the pending non-cash payments of several selected transactions in one action; payments that cannot be verified by that user (already verified, cash, voided, or recorded by the user themselves) MUST be skipped without failing the rest, and a summary MUST report how many were verified and how many skipped.
- **FR-009**: Verification is final: the product MUST NOT offer any way to set a verified payment back to "Not verified" (screen or request).
- **FR-010**: Changing verification MUST NOT change the payment's amount, method, channel, date, the transaction's totals, paid amount, payment status, the shift's expected cash, or any report. The one intended consequence: the shift summary's per-method breakdown has always listed only verified payments, so a verified non-cash payment now appears in it (see Assumptions).
- **FR-011**: Rejecting a payment is out of scope; payments already in the "rejected" state are shown and treated exactly as today.
- **FR-012**: Verification state, verifier and time MUST appear wherever the transaction's payments are listed (Sales detail and Pre-order payment history) in both interface languages.

### Key Entities

- **Payment**: One recorded payment. Already carries a verification state (not verified / verified / rejected), the user who verified it and when; this feature makes those usable.
- **Verification change record**: The activity-log entry written whenever the state changes (who, what, when, previous and new state).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An allowed user can verify one payment from a sale's details in under 15 seconds (2 interactions after opening the details).
- **SC-002**: 100% of verification changes appear in the activity log with who, which payment and the previous/new state.
- **SC-003**: After verifying, the sale's badge in the Sales list matches the payments' states immediately (no page reload needed beyond the list refresh the screen already does).
- **SC-004**: Totals, paid amounts, payment status, expected shift cash and report figures are identical before and after any verification change (0 differences); the only visible side effect is the payment appearing in the shift summary's per-method breakdown.
- **SC-005**: A user without permission — including the cashier who recorded the payment — has no way to verify it, and no user has a way to un-verify it, from the screen or by any direct request (0 successful attempts).
- **SC-006**: Verifying a day's worth (e.g., 30) of pending payments takes under 2 minutes using the list-level action.

## Assumptions

- "Verified" means the shop has checked the payment against the bank/e-wallet statement; the product does not connect to a bank or payment gateway.
- The existing three states (not verified, verified, rejected) are kept; "Not verified" is the stored "pending" state shown in the Sales list.
- Verification does not affect any money figure: non-cash payments already count as paid from the moment they are recorded (unchanged), and shift cash already counts only cash.
- The place to verify is the transaction details (and the pre-order payment history), consistent with where the payment confirmation (proof, reference, notes) is managed (feature 031); verification is independent of whether a proof exists.
- The database already stores who verified and when, so no new kind of data is introduced.
- The cashier-shift summary lists payments per method counting only VERIFIED payments (an existing rule since the shift summary was built, written for exactly this state): a QRIS payment is absent from that breakdown while "Not verified" and appears once verified. This is accepted as the natural meaning of verification, not changed here; the shift's expected cash is cash-only and is unaffected.
- Rejecting payments, undoing a verification, automatic matching with statements, and notifications are out of scope.
- "Other than the cashier who recorded it" is judged on the user who recorded the payment, not on the role name: an owner/admin may always verify, including payments they recorded themselves.

## Notes

- This spec was written while feature 031 (optional payment proof) was still uncommitted on its own branch; the `032` branch has not been created yet so the two sets of changes are not mixed.
