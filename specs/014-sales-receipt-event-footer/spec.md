# Feature Specification: Restore Sales Receipt Action & Event Info in Receipt Footers

**Feature Branch**: `014-sales-receipt-event-footer`

**Created**: 2026-09-05

**Status**: Draft

**Input**: User description: "sales - tampilkan kembali action untuk menampilkan receipt - di receipt POS dan Preorder, tambah info event name, location, dates di footer"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - View a completed sale's printed receipt again from the Sales list (Priority: P1)

A cashier or owner looking at the Sales list needs to see and reprint/redownload a specific transaction's actual sale receipt — the same document the customer was handed at checkout — not just the list of products sold in that transaction.

**Why this priority**: This capability existed before and was removed when the Sales screen was redesigned to open a "products sold" popup instead; its removal is a direct functional regression a user is now asking to fix, and a receipt is the one legally/operationally meaningful document a shop needs to be able to re-produce for a past sale (refunds, disputes, bookkeeping).

**Independent Test**: Can be fully tested by opening the Sales list, triggering the new "View receipt" action on any transaction row, and confirming the same receipt document that was shown at the time of sale is displayed, with print/download available.

**Acceptance Scenarios**:

1. **Given** the Sales list showing completed transactions, **When** the user triggers the "View receipt" action on a row, **Then** that transaction's receipt document opens, matching what would have been shown immediately after that sale was completed.
2. **Given** the receipt document is open, **When** the user downloads it (image or PDF), **Then** the downloaded file matches the on-screen receipt.
3. **Given** the Sales list, **When** the user triggers the existing "products sold" action on the same row instead, **Then** that popup still opens exactly as it does today — the new receipt action is additive, not a replacement.

---

### User Story 2 - Event name, location, and dates appear in the receipt/invoice footer (Priority: P2)

A cashier, owner, or customer looking at a POS sale receipt or a pre-order invoice/receipt wants to see which event the transaction belongs to — its name, where it was held, and its dates — printed at the bottom of the document, so a receipt collected from a multi-day or touring event booth is self-explanatory without asking staff.

**Why this priority**: Useful, expected context for a receipt from an event-based, non-fixed-location business, but the document is already fully usable (has the store, items, and totals) without it — lower priority than restoring a capability that was fully removed in User Story 1.

**Independent Test**: Can be fully tested by opening a POS receipt and a pre-order invoice/receipt for a transaction tied to a specific event, and confirming the event's name, location, and start/end dates appear in the document's footer.

**Acceptance Scenarios**:

1. **Given** a completed sale tied to an event with a name, location, and dates, **When** its receipt is viewed, **Then** the footer shows that event's name, location, and dates.
2. **Given** a pre-order tied to an event with a name, location, and dates, **When** its invoice/receipt document is viewed, **Then** the footer shows that event's name, location, and dates.
3. **Given** a pre-order that has no event associated with it (event is optional for pre-orders), **When** its invoice/receipt is viewed, **Then** the event-info footer block is simply omitted — no blank labels, no placeholder text.
4. **Given** an event with only a start date and no end date (or vice versa), **When** the footer renders, **Then** it shows whichever date(s) are actually set rather than showing a broken or empty date range.
5. **Given** a downloaded receipt/invoice (image or PDF), **When** the user views the downloaded file, **Then** the event info footer appears there identically to the on-screen version.

---

### Edge Cases

- A sale from before this feature shipped must still show its receipt correctly (this is a display-only addition to an already-working document, not a data migration).
- An event with no location set must not show an empty "at " or similar dangling label in the footer.
- A single-day event (start date equals end date) must display as one date, not a redundant "X – X" range.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Sales list MUST offer a "View receipt" action per transaction, independent of and in addition to the existing action that shows the transaction's sold products.
- **FR-002**: Triggering "View receipt" MUST show the exact same receipt document a user would see immediately after completing that sale, including its existing print/download capability.
- **FR-003**: The POS sale receipt's footer MUST show the event's name, location, and dates when the sale is tied to an event.
- **FR-004**: The pre-order invoice/receipt's footer MUST show the event's name, location, and dates when the pre-order is tied to an event.
- **FR-005**: When a pre-order has no associated event, the event-info footer block MUST be omitted entirely rather than shown with blank or placeholder values.
- **FR-006**: When an event has no location set, or only one of its start/end dates set, the footer MUST display only the information that is actually available, without dangling labels or broken formatting.
- **FR-007**: A single-day event (identical start and end date) MUST be shown as one date, not a redundant range.
- **FR-008**: The event-info footer MUST appear identically in downloaded receipt/invoice files (image and/or PDF) as it does on screen.

### Key Entities

- **Order / Sale transaction**: Already-existing record this feature makes reprintable from the Sales list; unchanged structurally.
- **Preorder**: Already-existing record; its invoice/receipt document gains event footer info, structurally unchanged otherwise.
- **Event**: The store's existing event entity (name, location, start date, end date), reused purely as read-only footer context — no new event attributes introduced.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can view any past sale's receipt from the Sales list in one action, without navigating away from the list.
- **SC-002**: 100% of receipts and invoices for event-tied transactions show that event's name, location, and dates in the footer, verified against the event's own record.
- **SC-003**: Zero receipts/invoices for transactions without an associated event (or an event missing location/one date) show broken, blank, or placeholder footer text.
- **SC-004**: A user can identify which event a receipt or invoice belongs to, and where/when that event took place, without asking staff or checking another screen.

## Assumptions

- "View receipt" for a sale reuses the existing sale receipt document/mechanism (the one shown immediately after checkout) rather than introducing a new receipt format — this is a restoration of removed functionality, not a redesign.
- The footer is the appropriate place for event info because both documents already reserve their bottom area for supplementary, non-transactional context (the existing optional store footer text on the POS receipt already establishes this convention).
- "Dates" means the event's start and end date as already recorded on the event; no new date fields (e.g. specific session times) are introduced.
- This feature does not change which roles can access the Sales list or view receipts — it restores/extends an existing, already-permitted action.
