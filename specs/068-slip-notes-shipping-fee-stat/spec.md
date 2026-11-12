**Classification**: INTERNAL

# Feature Specification: Shipping Notes on Bulk Slips and Total Shipping Fee Statistic

**Feature Branch**: `085-slip-notes-shipping-fee-stat` (spec dir number ≠ branch number)

**Created**: 2026-11-12

**Status**: Draft

**Input**: User description: "in preorder export shipping slips, add shipping notes below the Shipping Info title, add statistic total shipping fee based on filtered data"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the shipping notes on every printed slip (Priority: P1)

A shopkeeper selects Mail Order pre-orders on the Pre-orders list and downloads the shipping slips (the A4 cut-sheet PDF). Some orders carry a handling instruction (for example "fragile, wrap twice" or "leave with the security guard"). Today the slip shows only the title, the pre-order number and the From / To blocks, so the packer never sees that instruction. The slip should show the shipping notes directly under the "Shipping Info" title, so whoever packs and hands over the parcel reads it without opening the order.

**Why this priority**: This is the first half of the request and changes what physically leaves the booth. A missed handling note causes damaged or misdelivered parcels.

**Independent Test**: Select two Mail Order pre-orders, one with shipping notes and one without; download the slips; the first slip shows the notes right below the "Shipping Info" title, the second shows no notes area at all.

**Acceptance Scenarios**:

1. **Given** a selected pre-order that has shipping notes, **When** the user downloads shipping slips, **Then** that slip shows the notes text directly below the "Shipping Info" title, above the From / To blocks.
2. **Given** a selected pre-order with no shipping notes (empty or only spaces), **When** downloading, **Then** its slip looks exactly as it does today, with no empty line, label or placeholder.
3. **Given** a notes text long enough to wrap onto many lines, **When** downloading, **Then** the whole text is printed inside that slip's border, the slip grows taller to fit (the row grows with it), and nothing is cut off.
4. **Given** notes containing line breaks, special characters or text that looks like markup (for example `<b>` or `&`), **When** downloading, **Then** the slip shows it as plain text, with line breaks kept and no markup interpreted.
5. **Given** one slip with long notes and two neighbours without, **When** the sheet is laid out, **Then** the three slips in that row still share the same border lines and the same row height (existing cut-sheet rules unchanged).

---

### User Story 2 - See the total shipping fee for what I am looking at (Priority: P2)

On the Pre-orders list, the statistics bar shows the number of transactions, a count per status, the grand total and the outstanding amount, all for the currently filtered list. The shopkeeper also wants a **Total shipping fee** figure in that bar: the sum of the shipping fees of the pre-orders matching the current filters, so they can see at a glance how much shipping cost a given event, status, customer or seller represents, without exporting to Excel.

**Why this priority**: Useful for reconciling courier costs, but the slip change prevents errors in the field and so comes first. It is independent of the slip work.

**Independent Test**: With three Mail Order pre-orders of shipping fee Rp 20.000, Rp 30.000 and Rp 0 (pickup), the statistic reads Rp 50.000; filtering to the Rp 30.000 order alone makes it read Rp 30.000; a filter that matches nothing shows Rp 0.

**Acceptance Scenarios**:

1. **Given** the Pre-orders list with no filter, **When** the page loads, **Then** the statistics bar shows "Total shipping fee" equal to the sum of the shipping fees of all non-cancelled pre-orders.
2. **Given** any combination of existing filters (search, status, event, customer, seller, category, fulfillment, dispatch status, shipping flag, and so on), **When** the filter changes, **Then** the Total shipping fee is recalculated for exactly the pre-orders that match, the same set that the other statistics use.
3. **Given** a filter that matches no pre-order, **When** the statistic is shown, **Then** it reads Rp 0 (not blank, not an error).
4. **Given** a Self Pickup pre-order, **When** the statistic is calculated, **Then** it contributes Rp 0.
5. **Given** a cancelled pre-order that matches the filters, **When** the statistic is calculated, **Then** its shipping fee is not counted (same rule as Grand Total and Outstanding), while the per-status transaction count still lists it.
6. **Given** the user selects some rows on the list, **When** the statistic is shown, **Then** it does not change (it follows the filters, not the selection).

---

### Edge Cases

- A pre-order whose shipping fee was never filled in counts as Rp 0, and the "missing shipping cost" flag/filter keeps working as before.
- Shipping notes that are extremely long (thousands of characters) must either fit within a page or fail the way a too-tall slip already fails today (a clear message, no truncated file); they must never be silently cut.
- A pre-order that matches the filters but was deleted or changed by someone else between loading the list and downloading slips: the slip reflects the data at download time (existing behaviour); the statistic reflects the data at the last list refresh.
- Switching between DEMO and LIVE mode: the statistic only counts the active mode's pre-orders, like every other figure on the screen.
- A seller-scoped user sees the statistic only for the pre-orders visible to them (existing visibility rules apply).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each slip in the bulk shipping-slip download MUST show the pre-order's shipping notes directly below the "Shipping Info" title when notes exist.
- **FR-002**: A slip whose pre-order has no shipping notes (empty or whitespace only) MUST be unchanged from today: no label, blank line or placeholder.
- **FR-003**: Shipping notes MUST be shown completely: long text wraps inside the slip, the slip (and its row) grows taller to fit, and no text is clipped.
- **FR-004**: Shipping notes MUST be rendered as plain text, with line breaks preserved and any markup-like characters shown literally.
- **FR-005**: The existing cut-sheet rules (A4 pages, at most 3 slips per row, shared 1 px black borders, equal row height per row, no slip split across pages, a too-tall slip refused with a message) MUST keep working with the added notes line.
- **FR-006**: The single shipping slip shown in the pre-order detail drawer MUST NOT change as part of this feature [see Assumptions].
- **FR-007**: The Pre-orders list statistics bar MUST show a "Total shipping fee" figure, formatted as currency like the other amounts in the bar.
- **FR-008**: The Total shipping fee MUST equal the sum of the shipping fees of the non-cancelled pre-orders matching the list's current filters, and MUST be recalculated whenever those filters change.
- **FR-009**: The Total shipping fee MUST use exactly the same filtered set as the existing statistics (transaction count, Grand Total, Outstanding), so the figures can never describe different lists.
- **FR-010**: The Total shipping fee MUST read Rp 0 when nothing matches, and Self Pickup pre-orders MUST contribute Rp 0.
- **FR-011**: The new label MUST exist in both supported interface languages (English and Indonesian), like every other label on the screen.
- **FR-013** *(added 2026-11-12 on request)*: The Pre-orders list table MUST show a "Shipping fee" column (after Fulfillment): the pre-order's shipping fee for Mail Order rows, and "—" for Self Pickup rows (even if a fee is stored), matching the rule of the Total shipping fee statistic. *(Cell content changed 2026-11-12, on request: the value is three parts, in this order — the courier (when set), the shipping fee, and the pre-order notes (when not blank, clamped to two lines with the full text on hover/focus); Self Pickup rows still show only "—".)* *(Sortable since 2026-11-12, also on request:)* clicking the header sorts by shipping fee ascending, then descending; Self Pickup rows ("—") always come last in both directions, and rows with equal fees keep a stable order.
- **FR-012**: The shipping notes shown on the slip MUST be the pre-order's own Notes (entered when creating or editing the pre-order, available for every order). The notes of the later-created shipment record are NOT shown. *(Resolved by default on 2026-11-12 with the recommended option A because the question went unanswered when planning started; requester to confirm. See research.md D1.)*

### Key Entities *(include if feature involves data)*

- **Pre-order**: the customer order; carries a fulfillment type (Self Pickup or Mail Order), a shipping fee, a status, and free-text notes.
- **Shipment record**: the optional courier record created later for a Mail Order pre-order; carries its own free-text notes and its own shipping fee. May not exist when a slip is printed. Its notes are not used by this feature (FR-012).
- **Shipping slip**: the printed delivery sheet derived from a pre-order (store as sender, customer as recipient, pre-order number); this feature adds the notes line to it.
- **Pre-order statistics**: the figures shown above the list (count, per-status counts, Grand Total, Outstanding) computed for the current filters; this feature adds the shipping-fee total to it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In a download of 10 slips of which 4 have shipping notes, exactly those 4 slips show their notes directly under the title and the other 6 are visually identical to today's.
- **SC-002**: 100% of the characters of a shipping note up to the longest length a slip can physically hold are printed; a note too long for a page produces a clear message instead of a cut-off slip.
- **SC-003**: For any filter combination on the Pre-orders list, the displayed Total shipping fee equals the hand-added shipping fees of the visible (non-cancelled) result rows, in 100% of checked combinations.
- **SC-004**: After a filter change, the Total shipping fee updates together with the other statistics, within the same refresh the user already waits for (no extra click, no noticeably longer wait).
- **SC-005**: A packer can read the handling instruction from the printed slip alone, without opening the order, in 100% of slips whose order has notes.

## Assumptions

- The slips involved are the ones produced by "Download shipping slips" in the Pre-orders bulk actions (the A4 cut-sheet PDF from feature 067); the spec reads "export shipping slips" as that action. The single slip in the order detail drawer is left unchanged (FR-006); it can be aligned later if wanted.
- "Below the Shipping Info title" is read literally: the notes sit immediately under the title line; the pre-order number follows below them. If the pre-order number should stay directly under the title, only the order of those two lines changes.
- The notes carry no label (no "Notes:" prefix) unless requested; keeping the slip compact matters on a three-column cut sheet.
- "Statistic" means the existing statistics bar above the Pre-orders list; the new figure follows the same filters and the same exclusion of cancelled pre-orders as Grand Total and Outstanding, because a cancelled order will not ship.
- The shipping fee counted is the fee recorded on the pre-order; Self Pickup pre-orders carry no fee and add Rp 0.
- No new permission is introduced: anyone who can use those bulk actions or view the Pre-orders list sees the same screens as today; the new figure is not more sensitive than Grand Total already shown there.
- The statistic is not added to the Excel export, reports or dashboard in this feature.
- No change to how shipping fees or notes are entered, stored or validated.
- The table column (FR-013) reads the `shipping_cost` the list already returns (no new API field). Sorting is done on the server (the list is paginated) through a new `shipping_cost` option of `sort_by`.
