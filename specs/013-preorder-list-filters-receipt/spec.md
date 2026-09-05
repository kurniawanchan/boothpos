# Feature Specification: Preorder List Filters, Seller Info & Receipt-Style Invoice

**Feature Branch**: `013-preorder-list-filters-receipt`

**Created**: 2026-09-05

**Status**: Draft

**Input**: User description: "preorder - tambah filter by seller - tambah informasi seller di table dan detail - klik nomor transaksi, show detail - untuk detail dan download pdf invoice, samakan dengan receipt POS tapi diberikan tanda yang jelas bahwa itu transaksi preorder beserta statusnya - ganti kata Print menjadi Receipt (multilanguage) - tambahkan statistik jumlah transaksi, total untuk setiap status, grandtotal, total outstanding"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Filter and identify preorders by seller (Priority: P1)

A store owner or admin managing a multi-seller event wants to see only the preorders that involve a specific seller, and wants to be able to tell at a glance which seller(s) each preorder belongs to, without opening every single preorder.

**Why this priority**: Sellers/artists are the store's core organizing unit throughout the rest of the app (reports, stock, settlements); the preorder list is the one major transactional list that currently has no seller dimension at all, forcing a manual open-each-preorder workflow whenever someone needs seller-specific visibility.

**Independent Test**: Can be fully tested by opening the Pre-orders list, selecting a seller from the new filter, and confirming only preorders containing at least one item from that seller are shown, with a visible seller column identifying each row's seller(s).

**Acceptance Scenarios**:

1. **Given** the Pre-orders list with preorders from multiple sellers, **When** the user picks one seller from the seller filter, **Then** the list shows only preorders that include at least one item from that seller.
2. **Given** the seller filter is set to a specific seller, **When** the user resets it back to "all sellers", **Then** the list returns to showing every preorder regardless of seller.
3. **Given** a preorder whose items belong to a single seller, **When** the list is displayed, **Then** that seller's name is visible in the row without opening the preorder.
4. **Given** a preorder whose items span more than one seller, **When** the list is displayed, **Then** all involved sellers are visible in the row (not just the first one).
5. **Given** a preorder's detail view is open, **When** the user looks at it, **Then** the seller(s) for each line item are visible in the detail, not only in the list.

---

### User Story 2 - Open preorder detail by clicking the transaction number (Priority: P2)

A user scanning the preorder list wants to jump straight into a preorder's detail by clicking its transaction number, the way transaction numbers behave elsewhere in the app (e.g. the Sales list), instead of having to locate the separate "Detail" action button every time.

**Why this priority**: Pure convenience/consistency improvement on top of an action that already exists (the "Detail" button already opens the same view) — lower risk and lower value than the seller-visibility work in User Story 1, but cheap to add once the list is already being touched.

**Independent Test**: Can be fully tested by clicking a preorder number in the list and confirming the same detail view opens as clicking the existing "Detail" button.

**Acceptance Scenarios**:

1. **Given** the Pre-orders list, **When** the user clicks a row's preorder number, **Then** that preorder's detail view opens.
2. **Given** the Pre-orders list, **When** the user clicks the existing "Detail" action instead, **Then** the same detail view opens as clicking the number (both paths are equivalent, not two different views).

---

### User Story 3 - Receipt-styled preorder invoice with clear preorder marking (Priority: P1)

A cashier or customer-facing user downloads or prints a preorder's invoice and expects it to look and feel like the familiar POS sale receipt (same layout conventions: store header, itemization, prominent total), while still being unmistakable that it is a **preorder** document, showing that preorder's **current status** — so it's never confused with a completed sale receipt.

**Why this priority**: This is a customer-facing document (handed to or downloaded by the buyer) — a confusing or inconsistent-looking document is a direct trust/professionalism problem, and the current invoice already looks meaningfully different from every other printable document in the app.

**Independent Test**: Can be fully tested by opening a preorder's invoice/receipt from the list, downloading it, and confirming it uses the same visual structure as a POS sale receipt while clearly showing "Pre-order" and the preorder's current status.

**Acceptance Scenarios**:

1. **Given** a preorder in any status, **When** its invoice document is opened, **Then** it uses the same visual layout as the POS sale receipt (store header, itemization, dashed separators, prominent total).
2. **Given** that same document, **When** it is viewed, **Then** it prominently shows that this is a **preorder** (not a completed sale) and shows the preorder's current status (e.g. ordered, DP paid, arrived, settled, handed over, cancelled).
3. **Given** a preorder's invoice document, **When** the user downloads it, **Then** the downloaded file preserves the same receipt-style layout and preorder/status marking as the on-screen view.
4. **Given** a preorder's status changes over time (e.g. from "ordered" to "arrived"), **When** the invoice is reopened later, **Then** it reflects the preorder's current status, not the status at the time it was first created.

---

### User Story 4 - Rename "Print" to "Receipt" everywhere it appears (Priority: P3)

A user looking at the action buttons on the Pre-orders screen sees the word "Receipt" instead of "Print" for every action that opens or downloads one of these documents, in whichever interface language they're using.

**Why this priority**: Small, low-risk wording change that only makes sense once the documents themselves actually look like receipts (User Story 3) — otherwise the label would say "Receipt" for a document that still looks like a plain invoice.

**Independent Test**: Can be fully tested by switching between the two supported interface languages and confirming every button/label that previously read "Print" (in either language) now reads the "Receipt" wording in that language, with no leftover "Print" text anywhere in this screen.

**Acceptance Scenarios**:

1. **Given** the interface is set to Indonesian, **When** the user views the Pre-orders screen, **Then** every action that previously said "Cetak" (Print) now says the Indonesian wording for "Receipt".
2. **Given** the interface is set to English, **When** the user views the Pre-orders screen, **Then** every action that previously said "Print" now says "Receipt".
3. **Given** the renamed action, **When** the user clicks it, **Then** it behaves exactly as it did before — only the label changed, not the behavior.

---

### User Story 5 - Summary statistics for the preorder list (Priority: P2)

A store owner or admin scanning the Pre-orders screen wants an immediate summary — how many preorder transactions exist, how much value sits in each status, the grand total across all of them, and how much is still outstanding (uncollected) — without manually adding up rows themselves.

**Why this priority**: Directly answers the most common "how are we doing on preorders right now" question at a glance; ranked below the seller-visibility and receipt-styling work because it's an additive summary rather than a gap in what the screen can currently show at all.

**Independent Test**: Can be fully tested by loading the Pre-orders list with a known set of preorders and confirming the displayed transaction count, per-status totals, grand total, and total outstanding match a manual sum of the underlying data.

**Acceptance Scenarios**:

1. **Given** the Pre-orders list is loaded, **When** the user views the screen, **Then** they see the total number of preorder transactions currently in view.
2. **Given** preorders across multiple statuses, **When** the user views the summary, **Then** the total value is broken out per status (e.g. total value of "ordered" preorders, total value of "arrived" preorders, etc.), not only as one combined figure.
3. **Given** the same data, **When** the user views the summary, **Then** a grand total across all statuses is shown, and separately, the total outstanding (uncollected) amount across all preorders is shown.
4. **Given** the seller filter (User Story 1) or the status/fulfillment filters already on this screen are applied, **When** the user narrows the list, **Then** the summary statistics update to reflect only the currently filtered preorders, not the unfiltered full set.

---

### Edge Cases

- A preorder involving more than one seller must show ALL of its sellers in both the list's seller column and its detail — never silently show just one and drop the rest.
- A cancelled preorder still needs to be filterable/visible by seller, and its invoice document still needs to clearly show "Pre-order — Cancelled" rather than hiding the fact that it was cancelled.
- The seller filter and the existing status/fulfillment filters and customer-name search must all combine (AND logic) — selecting a seller should narrow within whatever else is already filtered, not replace those filters.
- A seller with zero preorders should be selectable in the filter and simply result in an empty list (consistent with how other seller filters in this app behave), not be omitted from the filter's options.
- Summary statistics must be based on recognized/collected amounts consistent with how the rest of the app already reports preorder revenue, not the full (possibly unpaid) order value counted as if it were already earned.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Users MUST be able to filter the Pre-orders list by a single seller, in addition to the existing status/fulfillment filters and customer-name search, and all active filters MUST combine rather than replace one another.
- **FR-002**: The seller filter MUST offer an explicit "all sellers" option to clear the filter and return to the unfiltered (by seller) list.
- **FR-003**: The Pre-orders list MUST display the seller(s) involved in each preorder as a visible column, showing every distinct seller for preorders spanning more than one seller.
- **FR-004**: The preorder detail view MUST display seller information for its line items, not only the list.
- **FR-005**: Clicking a preorder's transaction number in the list MUST open that preorder's detail view, equivalent to clicking the existing "Detail" action.
- **FR-006**: The preorder invoice document (both the on-screen view and any downloaded file) MUST use the same visual layout conventions as the POS sale receipt (store header, itemization, prominent total).
- **FR-007**: The preorder invoice document MUST prominently and unambiguously indicate that it is a preorder document (not a completed-sale receipt) and MUST display the preorder's current status.
- **FR-008**: The preorder invoice document MUST always reflect the preorder's live/current status at the time it is opened or downloaded, not a status frozen at an earlier point.
- **FR-009**: Every user-facing label that currently reads "Print" (or its Indonesian equivalent) for a preorder document action MUST be renamed to the "Receipt" wording, correctly translated in every interface language the app supports, with no change to the underlying action's behavior.
- **FR-010**: The Pre-orders screen MUST display the total count of preorder transactions currently in view.
- **FR-011**: The Pre-orders screen MUST display the total value broken out per preorder status, for the preorders currently in view.
- **FR-012**: The Pre-orders screen MUST display a grand total value and a total outstanding (uncollected) value across the preorders currently in view.
- **FR-013**: All summary statistics (FR-010 through FR-012) MUST recompute to reflect the currently applied filters (seller, status, fulfillment, customer search), not the full unfiltered dataset.

### Key Entities

- **Preorder**: A transactional pre-sale record already tracked by the system; gains a derived, read-only association to the seller(s) contributing items to it, and remains the subject of the receipt-style invoice document and the summary statistics.
- **Preorder Item**: An individual product line within a preorder; already carries which seller its product belongs to — this feature surfaces that existing association in the list and detail views rather than introducing a new relationship.
- **Seller**: The store's existing seller/artist entity, reused here purely as a filter dimension and a display label — no new seller attributes are introduced.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can narrow the Pre-orders list to a single seller's preorders in one selection, without needing to open any individual preorder first.
- **SC-002**: 100% of preorders spanning multiple sellers show every involved seller in the list, verified against the underlying line-item data.
- **SC-003**: A user can determine a preorder's total transaction count, per-status totals, grand total, and outstanding total for whatever is currently filtered, in a single glance at the top of the screen, with no manual calculation.
- **SC-004**: The preorder invoice document is visually indistinguishable in layout style from the POS sale receipt (same structural conventions), while a user can identify within 2 seconds of viewing it that it is a preorder and what its current status is.
- **SC-005**: Zero remaining occurrences of "Print" (or its Indonesian equivalent) wording on the Pre-orders screen in either supported interface language.

## Assumptions

- This feature's receipt-styling requirement (User Story 3) supersedes and fully absorbs the not-yet-implemented `011-preorder-invoice-receipt-style` spec's goal — implementing this feature satisfies that earlier spec's intent, and `011` does not need separate implementation once this feature ships.
- "Seller info" for a preorder means every distinct seller whose product appears among that preorder's items — consistent with how a single order/cart can already span multiple sellers elsewhere in this app (POS, Sales report). A preorder is not assumed to belong to exactly one seller.
- The seller filter is a single-select ("all sellers" or exactly one seller at a time), matching the seller filter pattern just introduced on the Reports screen, rather than a multi-select.
- Summary statistics (User Story 5) use the same "recognized revenue" convention already established for preorders elsewhere in this app (amounts actually collected via payments, not a preorder's full, possibly-unpaid order value) — consistent with how existing preorder reports avoid overstating revenue.
- "Downloading" the invoice means the same client-side download mechanism the current invoice/receipt views already use (e.g. save-as-image or PDF from the rendered document) — this feature does not introduce a new server-generated PDF pipeline.
- The two supported interface languages remain Indonesian and English, per the existing language-toggle feature; no new language is introduced by this feature.
