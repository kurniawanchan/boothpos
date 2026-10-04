# Feature Specification: Separate POS Sales from Pre-order Sales in the Seller Recap

**Feature Branch**: `033-seller-recap-pos-preorder-split`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "add column to separate sales/POS transaction with preorder transaction." (scope confirmed by the requester: Seller Recap, Cost & Profit and Seller Cost; units split as well as amounts) — evidence: the Reports → Seller Recap table (Seller, Unit, Sales, Payable, Paid, Outstanding, Status) for an event, where e.g. one seller shows 83 units and Rp 1.269.000 in "Sales" with no way to see how much of that came from over-the-counter POS sales and how much from pre-orders.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See how much of each seller's sales came from POS and how much from pre-orders (Priority: P1)

An owner or admin settling accounts with sellers opens the Seller Recap for an event. Today each seller has a single "Sales" figure (and a single "Unit" figure) that silently adds together two very different things: sales rung up at the booth (POS) and the part of each pre-order that customers have actually paid so far. The owner cannot tell where a seller's money came from without opening the transaction detail and adding things up by hand. The recap must show, per seller, the POS portion and the pre-order portion side by side, so that the two add up to the existing total.

**Why this priority**: This is the whole request. Settlement conversations with sellers ("how much of my payout is from pre-orders that may still be collecting?") depend on this split.

**Independent Test**: For an event with both over-the-counter sales and partially paid pre-orders for the same seller, open the Seller Recap and check that the POS figure plus the Pre-order figure equals the seller's existing total, and that each matches the corresponding transactions in that seller's transaction detail.

**Acceptance Scenarios**:

1. **Given** a seller with POS sales and pre-order sales in the event, **When** the Seller Recap is shown, **Then** the row shows the POS units and POS sales and the pre-order units and pre-order sales in separate columns, and each pair adds up exactly to the row's existing Unit and Sales totals.
2. **Given** a seller with only POS sales, **When** the recap is shown, **Then** the pre-order columns show zero and the POS columns equal the totals.
3. **Given** a seller with only pre-orders, **When** the recap is shown, **Then** the POS columns show zero and the pre-order columns equal the totals.
4. **Given** a seller with no sales at all (still listed in the recap), **When** the recap is shown, **Then** all four new columns show zero.
5. **Given** the Grand Total row, **When** the recap is shown, **Then** it shows the sum of each new column over all sellers, and the POS and pre-order grand totals add up to the grand totals of units and sales.
6. **Given** a pre-order that is only partly paid, **When** the recap is shown, **Then** only the paid portion is counted in the pre-order figure (the same rule that already applies to the existing total), so the split never exceeds what the existing total says.

---

### User Story 2 - The split matches the seller's transaction detail and the export (Priority: P1)

The owner clicks "Transaction detail" for a seller and sees the individual POS and pre-order transactions that make up the figures. The sums of those transactions by kind must match the two new columns, and the "Export .xlsx" of the recap must carry the same split, so that numbers shown on screen, in the detail and in the exported file never disagree.

**Why this priority**: A split that disagrees with the detail view would destroy trust in the recap; the export is what gets sent to sellers.

**Independent Test**: For one seller, sum the POS rows and the pre-order rows of the transaction detail and compare with the two columns; export the recap and compare the file's columns with the screen.

**Acceptance Scenarios**:

1. **Given** a seller's transaction detail lists POS and pre-order transactions, **When** their amounts are summed per kind, **Then** the totals equal the seller's POS and pre-order columns in the recap.
2. **Given** the recap is exported to a spreadsheet, **When** the file is opened, **Then** it contains the same POS and pre-order columns with the same values as on screen, including the grand total row.
3. **Given** the same event shown again after a pre-order receives another payment or a POS sale is voided, **When** the recap is reloaded, **Then** the columns reflect the change and still add up to the total.

---

### User Story 3 - The same split on the Cost & Profit and Seller Cost reports (Priority: P2)

**Cost & Profit** (event level: revenue, cost of goods, gross profit, event cost, net profit) already adds POS and pre-order revenue and cost together. **Seller Cost** (per seller: sales, cost, gross profit) today counts **only POS sales** — pre-orders are not in it at all, so its sales figure does not match the Seller Recap for the same seller. The owner wants the same clarity in both: how much of the revenue, cost and gross profit comes from POS versus pre-orders, so margins of the two kinds can be compared, and Seller Cost brought in line with the other reports (decision of the requester, 2026-10-04: Seller Cost gains pre-order columns and a combined total).

**Why this priority**: Consistent with the Seller Recap and requested by the owner, but the recap solves the settlement need first.

**Independent Test**: For an event with both kinds of sales, open Cost & Profit and Seller Cost and check that, for revenue, cost and gross profit, POS + pre-order equals the existing total, per seller and for the event.

**Acceptance Scenarios**:

1. **Given** an event with POS sales and pre-orders, **When** Cost & Profit is shown, **Then** revenue, cost of goods and gross profit are each shown as a POS part and a pre-order part that add up to the existing figures; event cost and net profit are shown as before (they belong to the whole event, not to a kind).
2. **Given** the Seller Cost report, **When** it is shown, **Then** each seller's sales, cost and gross profit are shown as a POS part (equal to the figure shown today), a pre-order part (new), and a total equal to POS + pre-order, and the grand total row does the same; a seller who has only pre-orders now appears in the report.
3. **Given** a pre-order that is only partly paid, **When** these reports are shown, **Then** both its revenue and its cost count only the paid portion (the existing rule), so the pre-order gross profit stays consistent.
4. **Given** the "Export .xlsx" of each of these tabs, **When** the file is opened, **Then** it carries the same split as the screen.

---

### Edge Cases

- Voided POS sales and cancelled pre-orders are excluded exactly as they are from the existing total (they appear in neither column).
- Pre-order amounts are the "recognised" amounts (proportional to cash actually collected), so they may be a fraction of the pre-order's face value; they are shown in the same money format as the other columns, never as a surprise decimal.
- A seller with deductions or payments recorded: Payable, Paid, Outstanding and Status are unchanged by this feature.
- Cost & Profit: event cost and net profit are whole-event figures and are NOT split by kind; only revenue, cost of goods and gross profit are.
- Inactive or deleted sellers that still appear because they have settlement data: shown with their split like any other row.
- DEMO and LIVE data modes: the split follows the mode currently shown, never mixing the two.
- Narrow screens: the table keeps working (it may scroll horizontally) without hiding existing columns.
- Events with no sales at all: the recap looks as before, with zeros in the new columns.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Seller Recap MUST show, for every seller row, the seller's units and sales from POS transactions and the seller's units and sales from pre-order transactions in separate columns.
- **FR-002**: For every row, the POS figure plus the pre-order figure MUST equal the row's existing total figure exactly (units and sales, no rounding drift), and the same MUST hold for the Grand Total row.
- **FR-003**: The pre-order figure MUST use the same rule as the existing total (cancelled pre-orders excluded; only the portion actually paid counted), and the POS figure MUST exclude voided sales, so the split reconciles with the total.
- **FR-004**: Units MUST be split by kind in the same way as the sales amounts (decided by the requester); pre-order units follow the same paid-portion rule as pre-order sales, so they can be fractional and are shown to a sensible precision.
- **FR-005**: The Grand Total row MUST include the totals of the new columns.
- **FR-006**: The "Export .xlsx" of the Seller Recap MUST include the new columns with the same values and headings (in the interface language) as on screen.
- **FR-007**: The new figures MUST match the sums, by kind, of the transactions listed in the seller's "Transaction detail".
- **FR-008**: Payable, Paid, Outstanding, Status, deductions, and the "Record payment" action MUST behave exactly as before.
- **FR-009**: The Cost & Profit report MUST show revenue, cost of goods and gross profit each split into a POS part and a pre-order part that add up to the existing figures (event cost and net profit are not split). The Seller Cost report MUST show each seller's sales, cost and gross profit as POS part + pre-order part = total (including its Grand Total row), where the POS parts equal what the report shows today and the totals now include the paid portion of the seller's pre-orders (cancelled pre-orders excluded), under the same rule as the Seller Recap.
- **FR-013**: The "Export .xlsx" of Cost & Profit and Seller Cost MUST carry the same split as on screen.
- **FR-010**: New column headings MUST be available in both interface languages and MUST make clear which is POS and which is Pre-order.
- **FR-011**: Only users who can already see the Seller Recap (owner/admin) MUST see the new columns; no new data is exposed to other roles.

### Key Entities

- **Seller recap row**: One seller's settlement summary for one event (units, sales, payable, paid, outstanding, status). Gains a POS portion and a pre-order portion of units and of sales.
- **Cost report figures**: Event-level revenue, cost of goods and gross profit, and per-seller sales, cost and gross profit; each gains a POS portion and a pre-order portion.
- **POS transaction**: A completed over-the-counter sale, counted at its full item value.
- **Pre-order transaction**: A pre-order not cancelled, counted only for the portion customers have actually paid.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every seller in every event, POS + pre-order equals the total sales shown in the same row (0 differences), and the grand totals reconcile the same way.
- **SC-002**: An owner can tell, for any seller, how much of the sales came from POS versus pre-orders at a glance, without opening the transaction detail (zero extra clicks).
- **SC-003**: The sums by kind in a seller's transaction detail match the recap's new columns in 100% of checked sellers.
- **SC-004**: The exported spreadsheet shows the same split and totals as the screen in 100% of checked exports.
- **SC-005**: Existing figures (Seller Recap units, sales, payable, paid, outstanding, status; Cost & Profit revenue, cost, gross profit, net profit) are unchanged for every seller and event after the feature is introduced (0 regressions). The one intended change: Seller Cost totals now include pre-orders, and its POS parts equal the figures shown before.
- **SC-006**: On Cost & Profit and Seller Cost, POS + pre-order equals the total for revenue, cost and gross profit in 100% of checked events and sellers, and a seller's Seller Cost sales total equals the same seller's Seller Recap sales total.

## Assumptions

- "Sales/POS transaction" means completed over-the-counter sales rung up at the booth, and "preorder transaction" means the seller's pre-orders; the Seller Recap's existing total is the sum of those two, so the new columns split that total rather than introduce new numbers.
- The pre-order figure keeps today's "recognised revenue" rule (the share of each pre-order that has been paid), because changing it would change the existing total and the amounts payable to sellers.
- Existing historical data needs no correction: the split is derivable for any event, past or present.
- The recap and the two cost reports stay owner/admin reports; no change to who can open them.
- Cost of a pre-order is counted with the same paid-portion rule as its revenue (this is how the Cost & Profit totals are built), so splitting cost and gross profit by kind reconciles with those totals.
- Seller Cost previously ignored pre-orders entirely; bringing them in (with a combined total) is a deliberate change of that report's totals, confirmed by the requester, so that Seller Recap, Seller Cost and Cost & Profit tell the same story.
- The transaction detail already lists POS and pre-order transactions together; this feature does not redesign it beyond making sure it reconciles with the new columns.
