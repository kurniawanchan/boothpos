# Feature Specification: Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

**Feature Branch**: `012-seller-preorder-report-detail-export`

**Created**: 2026-09-05

**Status**: Draft

**Input**: User description: "tambahan untuk laporan: (1) masukkan penjualan preorder ke dalam detail transaksi per-seller pada laporan Seller Recap, bukan cuma total agregatnya saja, (2) buat laporan Pre-order lebih detail — breakdown per seller dan bisa lihat daftar preorder yang membentuk setiap baris, (3) tambahkan export Excel untuk laporan yang belum punya tombol export (terutama tab Pre-order dan tab lain yang masih kosong)"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Seller's transaction detail includes their preorder sales (Priority: P1)

An owner/admin reviewing the Seller Recap report sees a seller's total sales figure already reflecting both regular sales and preorder revenue. When they open that seller's transaction detail to see which individual transactions make up that total, today they only see regular sale transactions — any preorder that contributed to the total is invisible in the detail view, making the total unexplainable from what's shown underneath it.

**Why this priority**: This is a direct correctness/trust gap — a total that can't be traced back to its contributing transactions undermines confidence in the report, and is the most visible, immediately-noticeable symptom of the underlying data gap.

**Independent Test**: For a seller whose Seller Recap total includes both a regular sale and a preorder's collected payment, open that seller's transaction detail and confirm both transaction types appear, with their individual amounts summing to the seller's reported total.

**Acceptance Scenarios**:

1. **Given** a seller has both a completed sale and a preorder with a recorded payment contributing to their Seller Recap total, **When** the owner/admin opens that seller's transaction detail, **Then** both the sale and the preorder appear as line entries, each clearly identifiable by type.
2. **Given** the transaction detail is open, **When** the amounts of every listed entry (sales and preorders) are summed, **Then** that sum equals the seller's total sales figure shown on the Seller Recap summary row.
3. **Given** a preorder that has only been partially paid, **When** it appears in the transaction detail, **Then** the amount shown for it is the portion actually collected (consistent with how the Seller Recap total already only counts collected amounts), not the preorder's full order value.
4. **Given** a preorder that was cancelled, **When** the transaction detail is viewed, **Then** it does not appear as a contributing entry (consistent with cancelled preorders contributing zero to the total).

---

### User Story 2 - Pre-order report shows a per-seller breakdown (Priority: P2)

An owner/admin using the Pre-order report today only sees rows grouped by status and payment completeness (e.g. "Goods arrived / Paid / 1 preorder / Rp 39.000"). They cannot tell which seller's merchandise is represented in those figures, which matters when different sellers have very different outstanding-preorder situations.

**Why this priority**: A meaningful enhancement to an already-shipped report, valuable for the same multi-seller-booth reason every other report in this app already breaks figures down by seller — but distinct from and less urgent than the correctness issue in User Story 1.

**Independent Test**: With preorders spanning multiple sellers, view the Pre-order report and confirm figures can be seen broken down by seller, not only by status/payment-completeness.

**Acceptance Scenarios**:

1. **Given** preorders for multiple sellers exist, **When** the Pre-order report is viewed, **Then** the user can see each seller's contribution to the reported counts and monetary totals.
2. **Given** a preorder contains items from more than one seller, **When** that preorder's value is attributed across sellers in this breakdown, **Then** the attribution is proportional to each seller's share of that preorder's items, consistent with how this codebase already attributes multi-seller preorder revenue elsewhere.

---

### User Story 3 - Pre-order report rows drill down to the individual preorders behind them (Priority: P2)

An owner/admin looking at a Pre-order report row (e.g. "Deposit paid / Paid / 1 preorder / Rp 75.000") wants to see exactly which preorder(s) that row represents, the same way other reports in this app already let a summary row be expanded into its contributing detail.

**Why this priority**: Directly requested, and mirrors an interaction pattern already established elsewhere in this app's reports (e.g. the Seller Recap's transaction detail, the Stock-by-Seller drilldown) — a consistent, expected capability once the report exists.

**Independent Test**: Click a Pre-order report row and confirm a detail view lists the specific preorder(s) contributing to that row (number, customer, order value, collected, outstanding), matching the row's totals when summed.

**Acceptance Scenarios**:

1. **Given** a Pre-order report row representing more than one preorder, **When** the user drills into it, **Then** each individual preorder is listed with enough detail to identify it (preorder number, customer) and its own amounts.
2. **Given** the drilldown is open, **When** the listed preorders' amounts are summed, **Then** they equal the summary row's totals.
3. **Given** the drilldown, **When** the user selects an individual preorder, **Then** they can reach that preorder's existing full detail view (reusing what already exists, not a new one).

---

### User Story 4 - Every report has an Excel export (Priority: P2)

An owner/admin wants to export any report they're viewing to Excel. Today the Seller Recap, Cost & Profit, and Seller Cost reports have an "Export .xlsx" button, but the Purchases, Stock by Seller, and Pre-order reports do not — a user viewing one of those three has no way to get the data out of the app.

**Why this priority**: A gap affecting three whole report tabs; equally important as the Pre-order report enhancements since it blocks a basic, already-expected capability (every other report tab has it) for a substantial part of the Reports section.

**Independent Test**: Open each of the Purchases, Stock by Seller, and Pre-order report tabs and confirm an "Export .xlsx" action is present and produces a downloaded workbook whose contents match what's shown on screen.

**Acceptance Scenarios**:

1. **Given** the Purchases report tab is open, **When** the user chooses to export, **Then** a workbook downloads containing the same rows currently shown on that tab.
2. **Given** the Stock by Seller report tab is open, **When** the user chooses to export, **Then** a workbook downloads containing the same per-seller stock rows currently shown.
3. **Given** the Pre-order report tab is open (including its User Story 2 per-seller breakdown), **When** the user chooses to export, **Then** a workbook downloads containing that report's rows.
4. **Given** any of these three exports, **When** the currently active event filter is applied on screen, **Then** the exported workbook respects that same filter, consistent with how existing report exports already respect the active filter.

---

### Edge Cases

- A seller with zero contributing transactions (no sales, no preorders) must show an empty transaction-detail state, not an error, consistent with existing empty-state handling elsewhere in this app.
- The per-seller Pre-order breakdown (User Story 2) must exclude cancelled preorders from monetary totals the same way the existing Pre-order report and the Seller Recap report already do, while still allowing a cancelled preorder to be visible in a drilldown for completeness if it's part of a status/seller grouping being inspected.
- All new detail/drilldown views and all new exports must respect the existing DEMO/LIVE data-mode boundary and the existing role-based access already enforced on these reports (owner/admin only, per existing convention) — no new capability introduces a new exposure.
- An export of a report with zero rows for the current filter must still produce a valid (empty) workbook, not an error, matching how this codebase's export convention already behaves for other reports.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A seller's transaction-detail view on the Seller Recap report MUST include preorder-sourced transactions alongside regular sale transactions, each clearly labeled by type.
- **FR-002**: Each preorder transaction shown in that detail view MUST reflect only the amount actually collected for that seller's share of the preorder, not the preorder's full order value.
- **FR-003**: The sum of all entries (sales and preorders) shown in a seller's transaction detail MUST equal that seller's total sales figure on the Seller Recap summary.
- **FR-004**: A cancelled preorder MUST NOT appear as a contributing entry in a seller's transaction detail.
- **FR-005**: The Pre-order report MUST support viewing figures broken down by seller, in addition to the existing status × payment-completeness grouping.
- **FR-006**: A preorder spanning multiple sellers' items MUST have its value attributed to each seller proportionally to that seller's share of the preorder's items, consistent with the existing attribution rule already used elsewhere for preorder revenue.
- **FR-007**: The Pre-order report MUST support drilling into a summary row to see the individual preorder(s) that make up its totals (preorder number, customer, order value, collected, outstanding).
- **FR-008**: Selecting an individual preorder from that drilldown MUST open that preorder's existing detail view rather than a new, separate one.
- **FR-009**: The Purchases report tab MUST provide an Excel export action.
- **FR-010**: The Stock by Seller report tab MUST provide an Excel export action.
- **FR-011**: The Pre-order report tab MUST provide an Excel export action, including the per-seller breakdown from User Story 2.
- **FR-012**: Every export added by this feature MUST respect the currently active event filter shown on screen at the time of export, consistent with existing report exports.
- **FR-013**: All new views and exports introduced by this feature MUST respect the existing DEMO/LIVE data-mode boundary and the existing role-based access control already enforced on these reports.

### Key Entities

- **Order / Preorder**: Existing entities; read from (not modified) to populate the seller transaction-detail view and the Pre-order report's per-seller breakdown and drilldown.
- **Artist (Seller)**: Existing entity; the dimension both the transaction-detail fix and the new Pre-order breakdown are organized by.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any seller with mixed sale and preorder revenue, the sum of their transaction-detail entries matches their Seller Recap total to the rupiah, 100% of the time.
- **SC-002**: An owner/admin can determine, from the Pre-order report alone, how much of a given status/payment-completeness figure belongs to each seller, without cross-referencing the Preorders list.
- **SC-003**: An owner/admin can identify the specific preorder(s) behind any Pre-order report row in at most one additional click.
- **SC-004**: All three currently export-less report tabs (Purchases, Stock by Seller, Pre-order) produce a downloadable workbook matching on-screen data, closing a 100% gap in export coverage across the Reports section.

## Assumptions

- This feature builds directly on the Seller Recap, Pre-order report, and per-seller preorder revenue attribution shipped by the `010-split-payment-preorder-reports` feature — it extends that work rather than duplicating it, and depends on that feature's revenue-recognition/proration rule (cash actually collected, prorated by item value share) as its existing source of truth.
- "Every report that lacks one" is scoped to the three report tabs confirmed to have no export today (Purchases, Stock by Seller, Pre-order) — the three tabs that already have export (Seller Recap, Cost & Profit, Seller Cost) are unaffected by this feature.
- The Pre-order report's per-seller breakdown is additive to its existing status × payment-completeness grouping (e.g. an additional dimension or a nested view), not a replacement of the existing summary shape depended on elsewhere.
- The individual-preorder drilldown (User Story 3) reuses whatever preorder detail view already exists in the product rather than introducing a new one, consistent with this codebase's general preference for reusing existing detail views across reports.
