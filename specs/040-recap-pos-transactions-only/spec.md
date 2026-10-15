# Feature Specification: Seller Recap Shows POS Transactions Only

**Feature Branch**: `040-recap-pos-transactions-only`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "change to only show POS transaction." (with a screenshot of Reports → Seller Recap, event Comifuro 23, where sapphirefiless shows 1 POS unit but 82 pre-order units, so the recap is dominated by pre-orders)

## Clarifications

### Session 2026-10-05

- Q: What should "only show POS transaction" apply to on the Seller Recap? → A: The whole recap is POS-only — the pre-order figures are removed from the table, its Grand Total, and the payable/paid/outstanding amounts, not just from the "Transaction detail" list.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The Seller Recap counts only POS sales (Priority: P1)

On **Reports → Seller Recap**, the shopkeeper wants one figure per seller that reflects what was actually sold at the booth through the POS. Today the table splits each number into POS and Pre-order parts and the totals blend both (e.g. sapphirefiless: 1 POS unit + 82 pre-order units = 83 units, Rp 30.000 + Rp 1.239.000 = Rp 1.269.000). After this change the recap shows **POS only**: each seller's **Unit** and **Sales** are the POS units and POS sales, with no pre-order columns, and the Grand Total is the sum of those POS figures.

**Why this priority**: it is the whole request — the recap must stop mixing in pre-orders.

**Independent Test**: for an event with both POS sales and pre-orders, open the Seller Recap and confirm every seller's Unit/Sales equal that seller's POS sales only, the pre-order columns are gone, and the Grand Total equals the sum of the rows.

**Acceptance Scenarios**:

1. **Given** a seller with POS sales and pre-orders in the event, **When** the recap is shown, **Then** that seller's Unit and Sales equal the POS units and POS sales only (pre-order units and pre-order revenue are not included).
2. **Given** the recap table, **When** it is read, **Then** there is no "Pre-order unit" or "Pre-order sales" column and no separate "POS" prefix is needed (the whole table is POS), while Seller, Unit, Sales, Payable, Paid, Outstanding, Status and the row actions remain.
3. **Given** a seller who has pre-order sales only (no POS sale) in the event, **When** the recap is shown, **Then** that seller still appears (the recap keeps listing every active seller) with Unit 0 and all money Rp 0.
4. **Given** the Grand Total row, **When** it is read, **Then** each figure equals the sum of the rows above it, all POS-only.
5. **Given** a voided POS sale, **When** the recap is shown, **Then** it is still not counted (unchanged rule).
6. **Given** the seller filter and the event selector, **When** they are changed, **Then** the table and Grand Total follow them exactly as before, with POS-only figures.
7. **Given** the language switch, **When** the user changes between English and Indonesian, **Then** all remaining labels are translated in both.

---

### User Story 2 - "Transaction detail" lists only POS transactions that add up to the row (Priority: P1)

The **Transaction detail** list of a seller shows only the POS transactions, so its total equals that seller's Sales on the recap row. Pre-order transactions no longer appear in it.

**Why this priority**: a detail list that still showed pre-orders would contradict the POS-only row it explains.

**Independent Test**: open "Transaction detail" for a seller who has both kinds; confirm only POS transactions are listed and their amounts add up to the row's Sales.

**Acceptance Scenarios**:

1. **Given** a seller with POS sales and pre-orders, **When** Transaction detail is opened, **Then** only POS transactions (with only that seller's items in each order) are listed, and their amounts sum to the recap row's Sales.
2. **Given** a seller with no POS sale, **When** Transaction detail is opened, **Then** it shows the existing empty state, not pre-order transactions.
3. **Given** the detail dialog, **When** it is read, **Then** no row is labelled or styled as a pre-order.

---

### User Story 3 - Payable, Paid, Outstanding and "Record payment" follow the POS-only Sales (Priority: P2)

A seller's **Payable** equals their POS-only Sales (there is still no commission), so **Outstanding** = Payable − Paid and **Status** (Unpaid/Partial/Paid) are computed from POS sales only. "Record payment" is offered only when something is payable, and recording a payment works exactly as today against the new Payable.

**Why this priority**: the amounts to settle with each seller must agree with the Sales column the user now sees; showing Sales as POS-only but Payable including pre-orders would be contradictory.

**Independent Test**: for a seller with POS and pre-order sales, confirm Payable = their POS Sales, Outstanding = Payable − Paid, and a recorded payment is checked against that Payable.

**Acceptance Scenarios**:

1. **Given** a seller with POS Sales of Rp 30.000 and pre-orders of Rp 1.239.000, **When** the recap is shown, **Then** Payable is Rp 30.000 and Outstanding is Rp 30.000 minus what was paid.
2. **Given** a seller with Payable Rp 0 (e.g. pre-order sales only), **When** the recap is shown, **Then** no "Record payment" action is offered for that seller.
3. **Given** a payment is recorded for a seller, **When** the recap reloads, **Then** Paid, Outstanding and Status update against the POS-only Payable, and the rules for recording a payment (amount limits, who may record it) are unchanged.
4. **Given** payments were already recorded in the past for a seller (when Payable still included pre-orders) that now exceed the POS-only Payable, **When** the recap is shown, **Then** Paid shows what was actually recorded, Outstanding is never shown negative (Rp 0), and nothing recorded is deleted or altered.
5. **Given** an event is closed, **When** its settlement figures are produced, **Then** they are the POS-only figures (the same ones the recap shows).

---

### User Story 4 - The recap Excel export matches the screen (Priority: P3)

The export of the Seller Recap contains the same POS-only figures as the screen: the summary sheet no longer carries pre-order unit/sales columns, and the transaction-detail sheet (already POS-only) now agrees with it.

**Why this priority**: the file is what gets sent to sellers; it must not disagree with the screen.

**Independent Test**: export the recap for an event with both kinds; confirm every figure equals the on-screen row and no pre-order column or row appears.

**Acceptance Scenarios**:

1. **Given** an export, **When** the summary sheet is opened, **Then** its Unit/Sales/Payable/Paid/Outstanding figures equal the on-screen rows and there are no pre-order columns.
2. **Given** the detail sheet, **When** it is opened, **Then** it lists POS transactions only and sums to the summary.

---

### Edge Cases

- A seller whose entire activity in the event is pre-orders shows zeros on the recap (they are not hidden), because the recap lists every active seller.
- Pre-order revenue is deliberately **not** shown anywhere on the Seller Recap after this change. It remains visible on the **Pre-order** report tab, the Pre-orders screen, **Cost & Profit**, **Seller Cost**, the Dashboard's sales/breakdown panels and the Sales summary cards, which this feature does not change. The Dashboard's per-seller results panel reads the same figures as the recap and therefore becomes POS-only with it.
- The Seller Recap's Sales will therefore no longer equal Seller Cost's total sales for an event that has pre-orders (they were equal while both blended pre-orders).
- Historical recorded seller payments are kept as-is; only how they are compared with Payable changes (see Story 3, scenario 4).
- Voided POS sales and sales of other data modes (DEMO/LIVE) remain excluded exactly as before.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Seller Recap MUST report each seller's Unit and Sales from POS sales only; pre-order units and pre-order revenue MUST NOT be included in any recap figure.
- **FR-002**: The recap table MUST NOT show pre-order unit or pre-order sales columns; the remaining columns MUST be Seller, Unit, Sales, Payable, Paid, Outstanding, Status and the row actions.
- **FR-003**: The recap Grand Total MUST equal the sum of the rows shown, for every figure, and MUST be POS-only.
- **FR-004**: Every active seller MUST still be listed, including sellers with no POS sale (zero figures).
- **FR-005**: A seller's Payable MUST equal that seller's POS-only Sales; Outstanding MUST equal Payable minus Paid and MUST never be shown below zero; Status MUST be derived from those values.
- **FR-006**: "Record payment" MUST be offered only for a seller with an outstanding payable amount, and its rules (limits, permissions) MUST be unchanged.
- **FR-007**: "Transaction detail" MUST list only POS transactions, containing only the selected seller's items, and their amounts MUST sum to the seller's Sales on the recap.
- **FR-008**: Payments already recorded MUST be preserved unchanged; they are only compared with the new POS-only Payable.
- **FR-009**: The settlement figures produced when an event is closed MUST be the same POS-only figures the recap shows.
- **FR-010**: The recap Excel export MUST contain the same POS-only figures as the screen, with no pre-order columns; its detail sheet MUST agree with its summary sheet.
- **FR-011**: The Pre-order report, Cost & Profit, Seller Cost, Sales page and Pre-orders screen MUST be unchanged by this feature. The Dashboard's "Results per seller" panel shows the same per-seller sales as the recap, so it follows the recap (POS-only); every other Dashboard panel is unchanged.
- **FR-012**: All remaining recap labels MUST exist in English and Indonesian.

### Key Entities

- **Seller recap row**: one per active seller per event — Unit, Sales (POS), Payable (= Sales), Paid (sum of recorded seller payments), Outstanding, Status.
- **Seller payment**: an already-recorded amount paid out to a seller for an event; preserved as is.
- **POS transaction**: a completed, non-voided sale made through the POS, contributing the seller's items to the recap and the detail list.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For an event with both POS sales and pre-orders, 100% of recap rows show Unit and Sales equal to that seller's POS sales, and no pre-order amount appears anywhere on the recap screen or its export.
- **SC-002**: For every seller, the amounts in "Transaction detail" add up exactly (to the cent) to the Sales on the recap row.
- **SC-003**: The Grand Total equals the sum of the rows on every recap, in every filter combination.
- **SC-004**: For every seller, Payable equals Sales and Outstanding equals Payable minus Paid (never negative); a user can settle a seller in the same number of steps as before.
- **SC-005**: A shopkeeper reading the screenshot's event (sapphirefiless) sees 1 unit / Rp 30.000 instead of 83 units / Rp 1.269.000.

## Assumptions

- "The recap" means the **Seller Recap** tab (the screen in the screenshot); other report tabs are not part of this request (see FR-011). **ASSUMPTION**: Seller Cost and Cost & Profit keep including pre-orders, which means they stop matching the recap's Sales for events with pre-orders; if they should become POS-only too, that is a separate decision.
- Payable still has no commission (as today): Payable = Sales minus the seller's recorded deduction, and no screen sets a deduction, so in practice Payable = Sales.
- The recap keeps being recalculated from the underlying sales each time it is read, so events that were closed earlier show the new POS-only figures when opened; no recorded payment is changed.
- **Business impact to be aware of**: pre-order revenue no longer produces a payable amount on the recap, so settling sellers for pre-order sales must be handled outside this screen (the Pre-order report still shows that revenue).
- The "Transaction detail" and export wording stays as is apart from removing pre-order content.

## Follow-up request (2026-10-06, after implementation)

The requester asked to also **remove the Status column and the "Record payment" action** from the Seller Recap screen, and then **the Payable, Paid and Outstanding columns**. The screen now shows Seller, Unit, Sales and the "Transaction detail" action only. This supersedes the screen-facing parts of Story 3 (Payable/Paid/Outstanding display and "Record payment") and the recap columns listed in FR-002/FR-005/FR-006. The requester then confirmed dropping them from the **API and the export too**: the response rows are now only `artist_id, artist_name, total_sales, total_units` (no `id`, `deduction`, `payable_amount`, `paid_amount`, `outstanding`, `status`), the "Rekap" export sheet has the same four columns, and the `POST /reports/artist-settlements/{id}/payment` endpoint was removed. The settlement table keeps being refreshed (POS-only snapshot) and recorded `paid_amount` values are untouched; no migration.
