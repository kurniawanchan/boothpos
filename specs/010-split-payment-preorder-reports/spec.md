# Feature Specification: Split Payment Visibility, Preorder Receipt & Reporting

**Feature Branch**: `010-split-payment-preorder-reports`

**Created**: 2026-09-04

**Status**: Draft

**Input**: User description: "saya mau menambahkan fitur end-to end split payment (saat ini tidak terlihat di POS dan preorder), highlighted mouseover row untuk semua table, update receipt preorder seperti receipt POS, tapi diberikan tanda yang jelas bahwa itu transaksi preorder beserta statusnya dan bisa cetak receipt pembayaran. gabungkan dan hitung transaksi Preorder ke dalam setiap laporan. buatkan juga laporan khusus preorder."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cashier can clearly split a payment across methods at POS checkout (Priority: P1)

A cashier is checking out a customer whose total exceeds what fits on one payment method — for example the customer wants to pay part by cash and the rest by QRIS. Today the payment screen doesn't make it obvious this is possible: there is no visible "split" affordance, so cashiers default to picking one method and either can't complete an over-limit sale or don't realize a second method can be added. The cashier needs the payment screen to visibly and unambiguously let them add more than one payment entry, see the running remaining balance shrink as each entry is added, and complete the sale once the entries cover the total.

**Why this priority**: This is the most common real-world payment scenario at an event booth (limited cash-only or QRIS-only wallets among buyers); if it's not visibly usable, cashiers either turn away sales or use workarounds that produce inaccurate records.

**Independent Test**: At POS checkout with a total that isn't a round cash amount, open the payment screen, add a partial cash entry, observe the remaining balance update, add a second entry in a different method that covers the remainder, and complete the sale — the resulting order and receipt must show both entries.

**Acceptance Scenarios**:

1. **Given** a checkout total of Rp 100.000, **When** the cashier enters Rp 60.000 cash as the first payment entry, **Then** the screen visibly shows a running list containing that entry and a remaining balance of Rp 40.000, with a clear way to add another entry rather than only a single method picker.
2. **Given** the remaining balance is Rp 40.000, **When** the cashier picks QRIS and enters Rp 40.000 as a second entry, **Then** the sale completes, the order is created with both payment entries recorded, and the remaining balance shows Rp 0.
3. **Given** the cashier has added a split entry by mistake, **When** they choose to remove that entry before completing the sale, **Then** it is removed from the list and the remaining balance recalculates.
4. **Given** a completed split-payment sale, **When** the cashier opens or prints that transaction's receipt, **Then** every payment entry (method and amount) is itemized on the receipt.

---

### User Story 2 - Owner/admin can clearly split a preorder payment across methods (Priority: P1)

An owner or admin is recording a payment against a preorder (down payment or final settlement) and the customer wants to pay using more than one method. The same split capability that exists at POS checkout needs to be equally visible and usable here.

**Why this priority**: Preorder payments are large, deposit-driven transactions where a customer splitting between, say, a bank transfer and cash is common — the payment-recording screen must not silently limit them to one method without making the alternative visible.

**Independent Test**: Open a preorder's "record payment" flow, add a partial entry in one method, observe the remaining amount due update, add a second entry in another method that completes the amount, and confirm the payment record reflects both entries.

**Acceptance Scenarios**:

1. **Given** a preorder with an outstanding balance, **When** the owner/admin opens the payment-recording screen, **Then** the same visible multi-entry split experience from User Story 1 is available (add entry, see running remaining balance, remove an entry).
2. **Given** a split payment recorded against a preorder, **When** the preorder's payment history is viewed, **Then** each entry (method, amount) is shown individually, not collapsed into one total.

---

### User Story 3 - Any user can tell which table row their cursor is over (Priority: P2)

A user scanning a dense data table (products, customers, transactions, reports, etc.) currently has no visual feedback showing which row is under their cursor, making it easy to misread values across rows, especially on tables with many similarly-formatted numeric columns.

**Why this priority**: A low-risk, broadly-applied usability fix that reduces misreads across every list screen in the app — independent of the payment/reporting work, safe to ship on its own.

**Independent Test**: Open any screen with a data table, move the mouse over different rows, and confirm each hovered row is visually distinguished from the rest while the mouse is over it.

**Acceptance Scenarios**:

1. **Given** any data table in the application, **When** the user's cursor moves over a row, **Then** that row is visually highlighted (e.g. background change) distinctly from non-hovered rows.
2. **Given** the cursor moves off a row, **When** it is no longer hovered, **Then** the highlight is removed and the row returns to its normal appearance.
3. **Given** a table row that is already visually distinguished for another reason (e.g. selected, or a status color), **When** it is also hovered, **Then** the hover highlight still applies without making the row unreadable or removing the other indicator's meaning.

---

### User Story 4 - Preorder payment receipt looks and prints like the POS receipt, clearly marked as a preorder (Priority: P2)

A cashier or the customer needs a printable receipt for a payment made against a preorder, formatted consistently with the regular POS sale receipt (same layout, itemization, totals) so it's immediately familiar — but unmistakably marked as a preorder transaction, showing the preorder's current status (e.g. ordered, arrived, handed over), not a regular completed sale.

**Why this priority**: Today preorders only have a printable invoice (order confirmation), not a payment receipt matching the POS style — this is a real, separate document a customer expects after handing over money, distinct from the order invoice they received when they first ordered.

**Independent Test**: Record a payment against a preorder, then open/print its payment receipt and confirm it visually matches the POS receipt's structure while clearly labeled as a preorder with its status shown.

**Acceptance Scenarios**:

1. **Given** a preorder with at least one recorded payment, **When** a user requests to print/view its payment receipt, **Then** the receipt uses the same layout and itemization style as the POS sale receipt (store info, line items, totals, payment method breakdown).
2. **Given** that same receipt, **When** it is viewed, **Then** it is clearly and prominently marked as a "Pre-order" transaction (not a regular sale) and displays the preorder's current status.
3. **Given** a preorder that has received multiple separate payments (e.g. a down payment and a later settlement), **When** its payment receipt is viewed, **Then** it is clear which payment event the receipt reflects (not just the preorder's lifetime total).

---

### User Story 5 - Preorder transactions are counted in every existing report (Priority: P2)

An owner/admin reviewing sales, profit, or artist settlement reports for an event today only sees regular POS sales — preorder transactions (down payments, arrivals, handovers, and their revenue) are invisible in those numbers, undercounting the event's real financial picture.

**Why this priority**: This is a correctness gap in existing, already-trusted reports — money and units from preorders are real business activity that decision-makers currently can't see without cross-referencing a separate screen.

**Independent Test**: Create a preorder with a recorded payment, run the existing sales/profit/artist-settlement reports for that event, and confirm the preorder's revenue and units are reflected in the totals and breakdowns alongside regular sales.

**Acceptance Scenarios**:

1. **Given** an event with both a regular sale and a preorder with a recorded payment, **When** the sales report is viewed for that event, **Then** the reported totals include both, and each is distinguishable as a contributing transaction.
2. **Given** the same event, **When** the artist settlement / profit reports are viewed, **Then** artist earnings and profit figures include the preorder's contribution using the same recorded amounts as the rest of that report.
3. **Given** a preorder that has NOT yet had any payment recorded, **When** reports are viewed, **Then** it does not inflate revenue totals (only actual recorded money counts), while remaining visible in the preorder-specific report (User Story 6).

---

### User Story 6 - A dedicated preorder report exists (Priority: P3)

An owner/admin wants a report focused specifically on preorders — how many are open vs. fulfilled, how much is outstanding vs. collected, and which are overdue for pickup/arrival — distinct from the general sales reports which only show completed revenue.

**Why this priority**: A genuinely new, additive report; valuable but lower urgency than fixing the undercounting in User Story 5, and independently shippable once the underlying preorder-in-reports plumbing exists.

**Independent Test**: With a mix of preorders in different statuses (ordered, arrived, handed over) and payment states (unpaid, partially paid, fully paid), open the new preorder report and confirm it summarizes counts and amounts by status and payment state.

**Acceptance Scenarios**:

1. **Given** preorders in multiple statuses and payment states for an event, **When** the preorder report is opened, **Then** it shows counts and monetary totals broken down by status (ordered/arrived/handed over/cancelled) and by payment completeness (unpaid/partial/paid).
2. **Given** the preorder report, **When** filtered by event, **Then** only that event's preorders are reflected.

---

### Edge Cases

- A split payment where the sum of entries doesn't exactly equal the total (over/under by a rounding difference) must be handled the same way the existing single-payment flow already handles cash overpayment/change — split payment must not introduce a new, looser tolerance for non-cash methods.
- Removing every split entry before completing a sale must leave the user able to start over cleanly, not stuck in a broken state.
- A voided/cancelled order that had a split payment must still show all of its original payment entries on any historical view (voiding doesn't erase payment history).
- The preorder payment receipt must degrade gracefully if a preorder has shipment/customer data missing (matching how the existing POS receipt and preorder invoice already handle optional fields), rather than crashing.
- Reports must respect the existing DEMO/LIVE data-mode boundary and role-based visibility already enforced on every other report — including preorders in report totals must not leak data a role couldn't already see via the Preorders screen.
- A cancelled preorder must not contribute revenue to reports (matches User Story 5's "only actual recorded money counts" rule) but should still be visible/countable in the dedicated preorder report's status breakdown.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The POS checkout payment screen MUST provide a visible, discoverable way to record more than one payment entry (different methods and/or amounts) against a single sale, showing the list of entries added so far and the remaining balance after each.
- **FR-002**: The POS checkout payment screen MUST allow removing a previously-added entry before the sale is completed, recalculating the remaining balance.
- **FR-003**: A completed sale's receipt (both on-screen and printed) MUST itemize every payment entry (method and amount) that was part of that sale, not just a single combined total.
- **FR-004**: The preorder payment-recording screen MUST provide the same visible multi-entry split capability as POS checkout (add entry, see running remaining balance, remove an entry).
- **FR-005**: A preorder's payment history view MUST show each recorded payment entry individually (method, amount), for both single-method and split payments.
- **FR-006**: Every data table in the application MUST visually highlight the row currently under the user's cursor, and remove that highlight when the cursor leaves the row.
- **FR-007**: The row-hover highlight MUST NOT visually conflict with or obscure any other row-level indicator already present (selection state, status color, etc.).
- **FR-008**: The system MUST provide a printable payment receipt for a preorder, using the same layout, store information, and itemization structure as the existing POS sale receipt.
- **FR-009**: The preorder payment receipt MUST be clearly and prominently labeled as a preorder transaction (distinct from a regular completed sale) and MUST display the preorder's current status.
- **FR-010**: The preorder payment receipt MUST identify which specific payment event it documents when a preorder has received more than one separate payment (e.g. down payment vs. final settlement), rather than only showing a lifetime total.
- **FR-011**: Existing sales, profit, and artist-settlement reports MUST include preorder transactions' recorded revenue and units alongside regular sales, using the same recorded amounts already used elsewhere (no re-derivation from current prices).
- **FR-012**: A preorder MUST only contribute to report totals for the amount actually recorded as paid — an unpaid or partially-paid preorder must not inflate revenue by its full order value.
- **FR-013**: The system MUST provide a report scoped specifically to preorders, showing counts and monetary totals broken down by preorder status and by payment completeness (unpaid/partial/paid).
- **FR-014**: The dedicated preorder report MUST support filtering by event, consistent with how other reports already filter by event.
- **FR-015**: All report changes in this feature MUST continue to respect the existing DEMO/LIVE data-mode boundary and existing role-based report access already enforced on every other report.

### Key Entities

- **Order / Payment**: Existing entities; Payment already supports multiple entries per order — this feature makes that capability visibly usable at checkout rather than changing its data shape.
- **Preorder / Payment**: Existing entities; preorder payments gain the same visible split-entry capability, plus a new payment-receipt document distinct from the existing order invoice.
- **Report (Sales / Profit / Artist Settlement / Preorder)**: Existing report outputs, extended to include preorder-sourced revenue; the Preorder report is a new, additive report output.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A cashier can complete a checkout requiring two different payment methods without any workaround or manual reconciliation step outside the app.
- **SC-002**: 100% of completed transactions' receipts (POS and preorder) that used more than one payment method show every method and amount used.
- **SC-003**: A user hovering any row in any table screen sees a visible highlight on that row within normal interaction (no perceptible delay).
- **SC-004**: A preorder payment receipt is visually recognizable as "the same receipt style" as a POS receipt by a user familiar with the POS receipt, while being unambiguous that it is a preorder (not confused with a regular sale) 100% of the time in review.
- **SC-005**: For any event with a mix of regular sales and paid preorders, the sales/profit/artist-settlement report totals equal the sum of regular sale amounts plus recorded preorder payment amounts — verifiable to the rupiah.
- **SC-006**: An owner/admin can determine, from the dedicated preorder report alone, how much money is still outstanding across all open preorders for an event without visiting the Preorders list.

## Assumptions

- "Split payment" already has a working backend/data model (an order or preorder payment already supports multiple entries per transaction) — this feature is about making that capability visibly and reliably usable end-to-end (checkout UI, preorder payment UI, and receipt display), not introducing a new multi-payment data model from scratch. If the underlying capability turns out to have a functional gap (not just a visibility gap) during planning, that gap is in scope to fix as part of "end-to-end."
- The preorder payment receipt is a new, additive document distinct from the existing preorder invoice (which documents the order itself, not a specific payment) — both continue to exist for different purposes.
- "Every table" (row hover) means every data-table-style list view already used for tabular records across the app (products, customers, orders, preorders, reports, master data, etc.), not free-form card/grid layouts that aren't table rows.
- Report inclusion of preorders is additive to existing report totals, not a new parallel report replacing them — existing report consumers see a bigger, more accurate number, not a different report shape, except for the new dedicated preorder report which is entirely new.
- A cancelled preorder is treated as contributing zero revenue to reports (never having been paid, or its payment being refunded/reversed is out of scope for this feature — this feature assumes cancellation doesn't retroactively need a refund workflow beyond what already exists).
