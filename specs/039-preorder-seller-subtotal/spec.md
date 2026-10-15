# Feature Specification: Subtotal per Seller in the Pre-order Report

**Feature Branch**: `039-preorder-seller-subtotal`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "add subtotal for each seller." (with a screenshot of Reports → Pre-order → By Seller)

## Clarifications

### Session 2026-10-05

- Q: Should the Excel export's "Per Seller" sheet also get a subtotal row per seller? → A: Yes — same as on screen: a clearly labelled Subtotal row after each seller's rows.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See each seller's total at a glance on the By Seller report (Priority: P1)

On **Reports → Pre-order → By Seller**, each seller currently appears as several rows (one per pre-order status and payment completeness, e.g. sapphirefiless has three), and only a single Grand Total at the very bottom. After the last row of every seller the user sees a **Subtotal** row that adds up that seller's rows — pre-order count, total order value, total collected and total outstanding — so a seller's overall position is readable without adding rows by hand.

**Why this priority**: the shopkeeper uses this report to settle with each seller; the number they need ("how much is outstanding for sapphirefiless?") is spread over several rows today, while the only total is for everyone combined.

**Independent Test**: open the By Seller view for an event with several sellers and several rows per seller; confirm each seller ends with a Subtotal row whose four figures equal the sum of that seller's rows, and that the Grand Total still equals the sum of all subtotals.

**Acceptance Scenarios**:

1. **Given** a seller with several rows (e.g. Ordered/Unpaid, Deposit paid/Paid, Deposit paid/Partially paid), **When** the By Seller table is shown, **Then** directly after that seller's last row a **Subtotal** row appears, labelled with the seller's name, showing the sum of the seller's pre-order count, total order value, total collected and total outstanding.
2. **Given** a seller with only one row, **When** the table is shown, **Then** that seller also gets a Subtotal row (every seller is treated the same, so the user can scan one pattern).
3. **Given** the subtotal rows, **When** they are compared with the Grand Total, **Then** the Grand Total row is unchanged, stays last, and each of its four figures equals the sum of the corresponding subtotals.
4. **Given** a Subtotal row, **When** it is read, **Then** the status and payment-completeness cells are empty, there is no "Detail" action, and the row is visibly different from data rows and from the Grand Total (lighter than the Grand Total, clearly heavier than a data row) without clutter.
5. **Given** a seller selected in the "All sellers" filter, **When** the table is shown, **Then** only that seller's rows and that seller's Subtotal appear, followed by the Grand Total (which then equals that Subtotal).
6. **Given** the event or filters change, **When** the table reloads, **Then** the subtotals are recomputed from the rows now shown (they never show stale numbers), and when there are no rows no subtotal and no grand total appear.
7. **Given** the language switch, **When** the user changes between English and Indonesian, **Then** the Subtotal label is translated in both.

---

### User Story 2 - The Excel export shows the same subtotals (Priority: P2)

When the user exports the pre-order report to Excel, the **Per Seller** sheet contains the same Subtotal row after each seller's rows, so the file matches the screen. The Summary sheet is unchanged.

**Why this priority**: the export is what gets sent to sellers or filed; a file that shows different rows from the screen would force the same manual addition again.

**Independent Test**: export with several sellers, open the Per Seller sheet, and check that each seller's rows are followed by a labelled Subtotal row whose figures match the on-screen subtotal.

**Acceptance Scenarios**:

1. **Given** an export, **When** the Per Seller sheet is opened, **Then** after each seller's last row there is a row labelled as that seller's subtotal with the four summed figures, matching the on-screen subtotal to the cent.
2. **Given** the sheet, **When** a subtotal row is read, **Then** it is clearly identifiable as a subtotal (its own label in the seller-name cell, empty seller id, status and payment-completeness cells) so it cannot be mistaken for a data row.
3. **Given** the export is made while a seller filter is active, **When** it is downloaded, **Then** it contains exactly what the filtered screen shows (same sellers, same subtotals).
4. **Given** the Summary sheet, **When** the export is opened, **Then** it is identical to before this feature.

---

### Edge Cases

- A pre-order with items from two sellers is counted once per seller (existing per-seller behaviour); subtotals follow the same rule, so the Grand Total's count can exceed the number of distinct pre-orders — unchanged behaviour, not altered by this feature.
- "Total outstanding" is per-row (never negative): a row can show collected greater than order value with outstanding Rp 0 (as in the screenshot's Paid row). The subtotal's outstanding is the SUM OF THE ROW OUTSTANDING VALUES, not order value minus collected, so it always equals what the user can add up from the visible rows.
- Money sums are exact to the cent (no rounding drift between row values, subtotal and Grand Total).
- A seller whose rows are all zero still shows its subtotal (zeros).
- Very many sellers (e.g. 50): the table stays readable and subtotal rows do not break column alignment.
- Sorting/grouping: rows of one seller are always contiguous (the report is already ordered by seller), so a subtotal always directly follows its seller's last row.
- Keyboard and screen readers: a subtotal row is announced as a subtotal (its label is real text), and the "Detail" buttons of data rows keep working unchanged.
- Printing/exporting: subtotal rows appear exactly where they appear on screen.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The By Seller table of the Pre-order report MUST show, directly after the last row of each seller, a Subtotal row for that seller.
- **FR-002**: A Subtotal row MUST show the sum, over that seller's rows, of pre-order count, total order value, total collected and total outstanding; money values are summed exactly (to the cent) from the values the rows display.
- **FR-003**: A Subtotal row MUST have empty status and payment-completeness cells, MUST NOT offer a "Detail" action, and MUST be visually distinct from data rows and from the Grand Total.
- **FR-004**: Every seller shown MUST get a Subtotal row, including a seller with a single row.
- **FR-005**: The Grand Total row MUST remain, MUST remain the last row, MUST be unchanged in value, and MUST equal the sum of the subtotals for each of its four figures.
- **FR-006**: Subtotals MUST respect the active event and seller filters and MUST be recomputed whenever the shown rows change; with no rows, no subtotal and no grand total are shown.
- **FR-007**: The Subtotal label MUST exist in both supported languages (no missing-translation key on screen).
- **FR-008**: The Excel export's "Per Seller" sheet MUST contain the same Subtotal rows (after each seller's rows, matching the screen to the cent for the same filters), each identifiable as a subtotal (own label, empty seller id, status and payment-completeness cells); the Summary sheet and the export's file name, sheet names and column order MUST NOT change.
- **FR-009**: The subtotal and grand-total figures MUST be derived from the same rows the table displays, so the screen, the export and the Grand Total can never disagree.

### Key Entities *(include if feature involves data)*

- **Seller report row**: one seller × pre-order status × payment completeness line with count, order value, collected, outstanding (existing).
- **Seller subtotal**: a derived (not stored) row summarising all rows of one seller; shown on screen and in the export.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of sellers in any event, the Subtotal row's four figures equal the sum of that seller's displayed rows, exactly to the cent.
- **SC-002**: The sum of all subtotals equals the Grand Total for each of the four figures, in 100% of cases and filter combinations.
- **SC-003**: A user can state any one seller's total outstanding by reading a single row (no arithmetic), versus adding up 1–3 rows today.
- **SC-004**: For the same event and filters, the on-screen subtotals and the exported Per Seller sheet's subtotals are identical (0 differences).
- **SC-005**: 0 untranslated keys appear, and the existing report tests keep passing except where they assert the exact row list of the By Seller table or sheet.

## Assumptions

- "Subtotal for each seller" refers to the By Seller view of the **Pre-order** report shown in the screenshot (the other report tabs already summarise per seller or are not grouped by seller).
- A subtotal is shown for every seller, including one with a single row, for a consistent reading pattern.
- Subtotals are derived from the rows (sums), not stored or recomputed with a separate formula; the row-level definitions of collected and outstanding are unchanged.
- The Grand Total keeps its current semantics (including counting a multi-seller pre-order once per seller).
- The export's subtotal rows are labelled in the seller-name column (e.g. "Subtotal — <seller>"), with empty seller id, status and payment-completeness cells; no grand-total row is added to the export (none exists today).
- Out of scope: changing how collected/outstanding are computed (including the case where collected exceeds order value, noted above), the Summary view, other report tabs, and the Detail drill-down.
