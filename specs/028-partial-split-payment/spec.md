# Feature Specification: Partial and Split Payments with Payment Summary

**Feature Branch**: `028-partial-split-payment`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "Partial Payment / Split Payment — enhance the Payment page to support partial and multiple payments for a single transaction: record a payment smaller than the total, see Total Paid and Remaining Balance update, add more payments until the balance is zero, keep an auditable history of independent payment entries, show a prominent payment summary (Grand Total, Total Paid, Remaining Balance, Payment Status) with an Add Payment action while a balance remains, make Partial / Multiple / Fully Paid immediately understandable, and prevent accidental duplicate payments with clear feedback after each save."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record a partial payment that is saved immediately (Priority: P1)

An admin or cashier opens the payment screen of a transaction (for example a pre-order with a Rp 1.000.000 total) and records a payment smaller than what is owed, such as Rp 400.000 in cash. The payment is saved as its own completed entry right away. The screen immediately shows the new Total Paid, the new Remaining Balance, and a status of "Partially Paid".

Today a payment is only kept inside the open dialog until the entered amounts add up to the balance; a smaller amount cannot be saved on its own. This story removes that limitation.

**Why this priority**: Customers regularly pay in instalments (deposit, then balance). Being able to save an instalment the moment it is received is the core of the feature and works on its own.

**Independent Test**: On a transaction with an outstanding balance, record a payment lower than the balance and confirm it appears in the history, Total Paid increased by that amount, Remaining Balance decreased by that amount, and the status reads "Partially Paid".

**Acceptance Scenarios**:

1. **Given** a transaction with Grand Total Rp 1.000.000 and nothing paid, **When** the user records Rp 400.000 by cash, **Then** the payment is saved immediately, Total Paid shows Rp 400.000, Remaining Balance shows Rp 600.000 and the status shows "Partially Paid".
2. **Given** the payment dialog is open, **When** the user saves a payment and closes the dialog, **Then** the payment is still recorded (it never depends on the balance being covered in the same dialog session).
3. **Given** a payment of Rp 0, a negative amount, or a non-numeric amount, **When** the user tries to save, **Then** it is refused with a clear message and nothing is recorded.
4. **Given** a payment by transfer or QRIS, **When** the user saves it, **Then** the proof of payment requirement that applies today still applies, and the optional reference or transaction number entered is stored with the payment.

---

### User Story 2 - Add more payments until the transaction is fully paid (Priority: P1)

After a partial payment, the user adds further payments against the same transaction, each by a possibly different method (for example cash, then bank transfer, then QRIS). Each addition updates the summary. When the Remaining Balance reaches zero the transaction becomes "Fully Paid", and the Add Payment action is no longer offered.

**Why this priority**: Completing the payment cycle is what makes partial payments useful; together with Story 1 it forms the minimum viable feature.

**Independent Test**: Record Rp 200.000 cash and Rp 200.000 transfer on a Rp 1.000.000 transaction (Remaining Rp 600.000), then Rp 600.000 by QRIS, and confirm the status becomes "Fully Paid", Remaining Balance is Rp 0 and Add Payment is gone.

**Acceptance Scenarios**:

1. **Given** a transaction with Remaining Balance Rp 600.000, **When** the user opens Add Payment, **Then** the dialog shows the current Remaining Balance and offers it as the default amount.
2. **Given** several payments already recorded, **When** the user records a payment equal to the Remaining Balance, **Then** Remaining Balance becomes Rp 0, the status becomes "Fully Paid", and the transaction is clearly marked as fully paid.
3. **Given** a Fully Paid transaction, **When** the user views the payment section, **Then** no Add Payment action is available (hidden or disabled with an explanation) and a "Fully Paid" indicator is shown.
4. **Given** a transaction whose Remaining Balance is greater than zero, **When** the user views the payment section, **Then** an Add Payment action is available.
5. **Given** a transaction that is closed (handed over or cancelled), **When** the user views the payment section, **Then** Add Payment is not available.

---

### User Story 3 - See the payment summary and an auditable payment history (Priority: P2)

The payment section always shows a prominent summary — Grand Total, Total Paid, Remaining Balance and Payment Status — followed by a payment history listing every payment ever recorded for the transaction. Each history line shows the payment method, amount, date and time, reference or transaction number when there is one, and the payment's status.

Recorded payments cannot be edited through the normal payment flow. The only way to remove one is the existing restricted correction action for owner/admin, which is logged and recalculates the summary.

**Why this priority**: The summary and history are what let staff answer "how much is still owed and how was it paid?" at a glance, and what makes the payments trustworthy, but the money can be recorded without them.

**Independent Test**: Record two payments with different methods and a reference number on one of them, then confirm the summary figures equal the sums of the history, every history line shows all required fields, and there is no edit action on any history line.

**Acceptance Scenarios**:

1. **Given** payments of Rp 200.000 cash and Rp 200.000 transfer on a Rp 1.000.000 transaction, **When** the user views the payment section, **Then** the summary shows Grand Total Rp 1.000.000, Total Paid Rp 400.000, Remaining Balance Rp 600.000 and the history lists both entries in the order they were recorded.
2. **Given** a payment recorded with a reference number, **When** the history is displayed, **Then** that reference is shown on its line; a payment without a reference shows no empty or placeholder clutter.
3. **Given** a payment history, **When** the user looks for a way to change an existing entry, **Then** there is no edit action; only owner/admin can see a delete action, which asks for confirmation.
4. **Given** an owner/admin deletes a payment, **When** the deletion completes, **Then** the summary and status are recalculated from the remaining payments and the deletion is recorded in the activity log.
5. **Given** a transaction with no payments, **When** the payment section is displayed, **Then** the summary shows Total Paid Rp 0, Remaining Balance equal to the Grand Total, a status of "Unpaid", and an empty-history message.

---

### User Story 4 - Safe, understandable payment flow (Priority: P2)

The flow makes it obvious whether the transaction is not paid, partially paid, paid across multiple payments, or fully paid, and protects against mistakes: an amount larger than the balance is refused, a double click or a retry after a slow network does not record the same payment twice, and every successful save gives clear confirmation.

**Why this priority**: These safeguards prevent real money errors but build on the core recording flow.

**Independent Test**: Double-click Save on a valid payment and confirm only one entry exists; enter an amount above the Remaining Balance and confirm it is refused; save a payment and confirm a confirmation shows the amount recorded and the new Remaining Balance.

**Acceptance Scenarios**:

1. **Given** a Remaining Balance of Rp 600.000, **When** the user enters Rp 700.000, **Then** the amount is refused with a message stating the maximum allowed and nothing is recorded.
2. **Given** a valid payment, **When** the user clicks Save twice quickly (or the screen resubmits after a connection delay), **Then** exactly one payment is recorded.
3. **Given** a payment has just been saved, **When** the save completes, **Then** the user sees a confirmation that states the amount recorded and either the new Remaining Balance or that the transaction is now fully paid.
4. **Given** one payment recorded and a balance remaining, **When** the user views the status, **Then** it is labelled "Partially Paid"; **Given** two or more payments recorded and a balance remaining, **Then** the screen also states how many payments have been made so far (for example "Partially Paid · 2 payments"); **Given** the balance is zero, **Then** it is labelled "Fully Paid".
5. **Given** two staff members record payments on the same transaction at nearly the same time, **When** the second payment would exceed the balance left by the first, **Then** the second is refused with the up-to-date Remaining Balance rather than overpaying.

---

### User Story 5 - Leave a POS sale partly paid and settle it later (Priority: P2)

At the register a customer pays only part of a sale (for example a deposit on a larger purchase). The cashier completes the sale with the partial payment, attaching the customer. Later the same customer returns, and the cashier finds the sale in the sales list (filterable by payment status), opens it and adds the remaining payment(s) with the same payment summary, history and Add Payment action used for pre-orders.

**Why this priority**: Option B of the scope decision: POS sales today must be paid in full at checkout. This story extends the feature to them, but it is separable from the pre-order work in Stories 1–4.

**Independent Test**: Complete a POS sale of Rp 500.000 paying Rp 200.000 with a customer attached; confirm it is saved as partially paid with Rp 300.000 remaining, then open it from the sales list, add Rp 300.000, and confirm it becomes Fully Paid.

**Acceptance Scenarios**:

1. **Given** a cart totalling Rp 500.000, **When** the cashier attaches a customer and records Rp 200.000, **Then** the sale is completed (stock is deducted as for any sale), its payment status is "Partially Paid", and the receipt shows amount paid and remaining balance.
2. **Given** a cart with no customer attached, **When** the cashier tries to complete it with less than the total paid, **Then** it is refused with a message that a customer is required for a partially paid sale.
3. **Given** a partially paid POS sale, **When** the cashier opens it from the sales list, **Then** the payment summary, history and Add Payment action appear as in Story 3.
4. **Given** the sales list, **When** the cashier filters by payment status, **Then** only partially paid (or unpaid-balance) sales are listed and the total outstanding amount across them is shown.
5. **Given** a cash payment is added to an older sale, **When** the shift is reconciled, **Then** that cash counts towards the shift in which it was actually received, not the shift in which the sale was originally made.
6. **Given** a partially paid sale, **When** it is voided by someone allowed to void sales, **Then** the void works as for any sale (stock returns) and the payments already received remain visible in its history; refunding money is handled outside the system.

---

### Edge Cases

- Amount of zero, a negative number, text, or more decimal places than the currency allows: refused with a clear message.
- Amount larger than the Remaining Balance: refused (overpayment is not supported in this feature); the message names the maximum payable.
- Cash handed over exceeds what is owed: the user records only the amount owed; giving change is outside this feature.
- Transfer or QRIS payment without proof of payment: refused exactly as today.
- Network failure or timeout while saving: the user is told the outcome is unknown and is guided to check the history before retrying, so a payment is never silently duplicated or lost.
- The transaction total is changed after payments exist: existing rules still apply (a total below the amount already paid is not allowed), and the summary always reflects the current total.
- A payment is deleted by owner/admin: summary and status are recalculated; a Fully Paid transaction becomes Partially Paid again and Add Payment returns.
- A transaction that is closed (a pre-order that is handed over or cancelled, or a voided POS sale): payments can be viewed but not added.
- A POS sale paid in full at checkout, including with several payments (existing split-payment checkout): unchanged — it is simply created already Fully Paid.
- A payment is added to a POS sale after the shift it was sold in has been closed: the money is attributed to the shift in which it was received.
- A transaction with a very long history: the list remains readable and shows newest and oldest clearly in recorded order.

## Requirements *(mandatory)*

### Functional Requirements

**Scope**

- **FR-001**: The partial/split payment flow MUST apply to both **pre-orders** and **point-of-sale (POS) sales**, with the same summary, history, rules and safeguards on each. Purchase orders to vendors are out of scope.
- **FR-001a**: A POS sale MUST be allowed to be completed with less than its total paid (a "partially paid" sale), provided a customer is attached to it so it is clear who owes the remainder; a sale paid in full at checkout behaves exactly as it does today.

**Recording payments**

- **FR-002**: Users with permission to record payments (admin and cashier) MUST be able to record a payment smaller than the Remaining Balance, and it MUST be saved immediately as its own completed entry — it MUST NOT depend on the entered amounts covering the balance within one dialog session.
- **FR-003**: After each saved payment the system MUST update Total Paid and recalculate Remaining Balance, and show both without the user having to refresh.
- **FR-004**: Users MUST be able to add further payments to the same transaction, repeatedly, until Remaining Balance is zero; each by any available payment method.
- **FR-005**: Every payment entry MUST be recorded independently and MUST store: amount, payment method, date and time recorded, who recorded it, a reference or transaction number when one is provided, and its payment status.
- **FR-006**: For transfer and QRIS payments the existing requirements (a payment channel and proof of payment) MUST continue to apply; the reference or transaction number is optional and free text.

**Payment rules**

- **FR-007**: Total Paid MUST equal the sum of all successful payment entries, and Remaining Balance MUST equal Grand Total minus Total Paid, always computed from the recorded entries.
- **FR-008**: A payment amount MUST be greater than zero and MUST NOT exceed the current Remaining Balance; overpayment is not supported and MUST be refused with a message stating the maximum payable.
- **FR-009**: While Remaining Balance is greater than zero and at least one payment exists, the payment status MUST be "Partially Paid"; with no payments it MUST be "Unpaid"; when Remaining Balance is zero it MUST be "Fully Paid".
- **FR-010**: Previously recorded payments MUST NOT be editable through the normal payment flow. The only removal path is the existing owner/admin delete action, which MUST require confirmation, MUST recalculate the summary and status, and MUST be written to the activity log with who did it and the old and new figures.
- **FR-011**: Every payment recorded MUST be traceable: the history MUST show who recorded it and when, and the recording action MUST be written to the activity log.
- **FR-012**: Adding payments MUST NOT be possible on a closed transaction (a pre-order that is handed over or cancelled, or a voided POS sale).
- **FR-013**: The payment status label ("Unpaid", "Partially Paid", "Fully Paid") is a separate indicator from the transaction's own lifecycle status (for example Ordered, Deposit paid, Settled); recording payments MUST continue to move the lifecycle status exactly as it does today.

**POS sales**

- **FR-024**: Completing a POS sale with less than the total paid MUST require a customer on the sale; without one it MUST be refused with an explanatory message.
- **FR-025**: A partially paid POS sale MUST be completed like any other sale (stock deducted, receipt issued) and MUST carry the payment status "Partially Paid" with its Remaining Balance until settled.
- **FR-026**: The sales list MUST show each sale's payment status, MUST allow filtering by payment status, and MUST show the total outstanding amount of the sales currently listed.
- **FR-027**: Cash received after the original sale MUST count towards the shift in which it is received, so each shift's expected cash stays correct; payments received at checkout continue to count towards the sale's own shift.
- **FR-028**: The POS receipt MUST show amount paid, remaining balance and payment status for a partially paid sale, and a receipt reprinted after later payments MUST reflect them.
- **FR-029**: Sales reporting MUST keep counting a sale at the time it was completed, as it does today; outstanding balances MUST be reported separately and MUST NOT change the recognised sales amount.

**Payment screen**

- **FR-014**: The payment section MUST display a prominent summary: Grand Total, Total Paid, Remaining Balance and Payment Status, and keep it consistent with the history below it at all times.
- **FR-015**: An Add Payment action MUST be available while Remaining Balance is greater than zero on an open transaction.
- **FR-016**: When Remaining Balance reaches zero, the Add Payment action MUST be hidden or disabled and the transaction MUST be clearly marked as Fully Paid.
- **FR-017**: A payment history list MUST appear below the summary showing every payment: method, amount, date and time, reference when present, and status, in the order recorded.
- **FR-018**: The Add Payment dialog MUST show the current Remaining Balance and default the amount to it, so paying in full is one step and paying part of it requires only changing the amount.
- **FR-019**: The screen MUST make Partial Payment, Multiple Payments and Fully Paid immediately distinguishable: a distinct status label for each, the number of payments recorded so far, and distinct visual treatment for "Fully Paid".

**Safeguards and feedback**

- **FR-020**: The system MUST prevent the same payment being recorded twice by double submission, repeated clicks, or retries after a connection delay.
- **FR-021**: The system MUST protect against simultaneous payments by different users overpaying the transaction; the later payment MUST be refused with the up-to-date Remaining Balance.
- **FR-022**: After each successful save the user MUST see a confirmation stating the amount recorded and the new Remaining Balance, or that the transaction is now Fully Paid.
- **FR-023**: When saving fails, the user MUST see a clear message and the dialog MUST keep the entered data; where the outcome is uncertain the user MUST be directed to check the history before retrying.

### Key Entities

- **Payment entry**: One independent receipt of money against a transaction — amount, method, payment channel (for transfer/QRIS), reference or transaction number, proof of payment (for non-cash), date and time, recorded by, status. Entries are never edited; they can only be removed by the restricted owner/admin correction.
- **Payment summary (per transaction)**: Derived figures — Grand Total, Total Paid, Remaining Balance, payment count and Payment Status — always computed from the payment entries and the current Grand Total.
- **Transaction**: The thing being paid — a pre-order or a POS sale (FR-001); owns its Grand Total and, for pre-orders, a lifecycle status which payments continue to influence as they do today. A POS sale additionally carries a payment status.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can record a partial payment and see the updated Total Paid and Remaining Balance in under 30 seconds, without leaving the transaction screen.
- **SC-002**: For 100% of transactions, Total Paid equals the sum of the history lines and Remaining Balance equals Grand Total minus Total Paid, shown identically everywhere they appear.
- **SC-003**: A transaction paid in three separate payments reaches "Fully Paid" with Remaining Balance exactly Rp 0 and no Add Payment action offered.
- **SC-004**: Double-clicking Save, or retrying after a slow connection, never produces a duplicate payment in 100% of test attempts.
- **SC-005**: 100% of attempts to record zero, negative or above-balance amounts are refused before anything is saved, with a message the user can act on.
- **SC-006**: In a usability check, at least 90% of first-time staff correctly tell, from the screen alone, whether a transaction is unpaid, partially paid or fully paid, and how much remains.
- **SC-007**: Every recorded payment and every payment deletion can be traced to who did it and when, from the history and the activity log.
- **SC-008**: A partially paid POS sale can be found in the sales list and settled in under 1 minute, and each shift's expected cash matches the cash actually received in that shift in 100% of test scenarios that include later payments.

## Assumptions

- Scope decision (product owner): pre-orders and POS sales are in scope; purchase orders to vendors are out of scope.
- A partially paid POS sale is treated as a completed sale for stock and sales reporting (the sale happened); only the unpaid amount is tracked as outstanding. Artist settlements and profit reports keep their current basis (completed sales) and are not changed by payment status.
- Voiding a partially paid POS sale does not refund money automatically; refunds remain out of scope, and the received payments stay visible in the history.
- A payment counts as "successful" as soon as it is recorded (cash, or non-cash with its proof). The system has no separate payment verification or rejection workflow today; if one is added later, only successful entries would count towards Total Paid.
- "Paid" is the status shown for every recorded entry in the history; the "Unpaid / Partially Paid / Fully Paid" label is derived from the figures, not stored separately from them.
- The roles that can record payments today (including admin and cashier) keep that ability; deleting a recorded payment stays restricted to owner/admin as it is today.
- Overpayment and change-giving are out of scope; refunds are out of scope (a mistaken payment is corrected with the existing owner/admin delete).
- Currency is Indonesian Rupiah with no decimals shown, consistent with the rest of the product.
- The existing proof-of-payment, payment-channel and payment-receipt (print) capabilities are reused unchanged.
- The existing behavior of holding several entries in the open dialog until they cover the balance is replaced by saving each payment as it is entered; no data migration of existing payments is needed.
