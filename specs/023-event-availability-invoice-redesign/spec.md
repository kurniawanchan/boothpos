# Feature Specification: Event Availability & Invoice Redesign

**Feature Branch**: `023-event-availability-invoice-redesign`

**Created**: 2026-09-23

**Status**: Draft

**Input**: User description: "in event: add available on option select Day 1 (start date) or Day 2 (end date) - add the available on information to preorder and sales invoice. make it standout - make standout about the event location in preorder and sales invoice - change layout of the invoice to follow the format, with header, detail with table, footer. make it widder - make the qr code bigger in the invoice - add shipping cost - in setting -> general, uploaded logo doesn't appear in general and invoices"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mark which day an event's booth is available (Priority: P1)

When creating or editing an event, an owner/admin can record which day the booth/merchandise is actually available to customers — "Day 1" (the event's start date) or "Day 2" (the event's end date) — so this can later be communicated to customers on their documents.

**Why this priority**: This is the foundational data point every other part of this feature depends on — without it, there's nothing to show on invoices or make stand out.

**Independent Test**: Can be fully tested by creating or editing an event, selecting "Day 1" or "Day 2" as the available day, saving, and confirming the choice is stored and re-displayed correctly when the event is reopened for editing.

**Acceptance Scenarios**:

1. **Given** an event with distinct start and end dates, **When** an owner/admin edits the event, **Then** they can choose "Day 1 (start date)" or "Day 2 (end date)" as the day the booth is available, or leave it unset.
2. **Given** an event whose start date and end date are the same (a one-day event), **When** an owner/admin views the event form, **Then** the "available on" choice is not offered (there is nothing to choose between).
3. **Given** an event that already has "Day 1" selected, **When** its start date is later changed so that the event becomes one day long, **Then** the stored choice no longer applies and is cleared (mirrors how a pre-order's own pickup day is already cleared when an event's dates change, feature 021 FR-009a).

---

### User Story 2 - Customers see clearly when and where to get their order (Priority: P1)

A customer reading their pre-order invoice, payment invoice, or a regular sales receipt can immediately see, without hunting for it, which day the booth is available and where the event is — both pieces of information are visually prominent, not buried in small footer text.

**Why this priority**: This is the actual customer-facing value the new "available on" data exists to deliver, and it directly addresses a real, reported readability problem with today's documents (event info is currently tiny footer text).

**Independent Test**: Can be fully tested by generating each of the three documents (pre-order invoice, payment invoice, sales receipt) for an event that has an available day and a location set, and confirming both pieces of information appear in a visually prominent way (not the same small muted footer text used for everything else).

**Acceptance Scenarios**:

1. **Given** a pre-order tied to an event with an "available on" day set, **When** its invoice is viewed, **Then** the available day (as a real date, e.g. "Available on: 12 October 2026") is shown prominently, near the top of the document alongside the order/customer identity.
2. **Given** a pre-order or sale tied to an event with a location set, **When** its document is viewed, **Then** the location is shown prominently (not as a single small line mixed in with the date range).
3. **Given** a pre-order or sale tied to an event with no "available on" day set, **When** its document is viewed, **Then** that block is omitted entirely (no empty or placeholder line).
4. **Given** a payment invoice (the per-payment document from feature 022), **When** it is viewed, **Then** it shows the same standout available-day/location treatment as the main pre-order invoice, since it already shares that document's layout.

---

### User Story 3 - A clearer, wider pre-order invoice with a bigger QR and shipping cost (Priority: P2)

The pre-order invoice (and the payment invoice, which shares its shell) is restructured into a clear header section (store + order identity), a tabular item breakdown, and a footer section (payment terms, closing message) — wider than today's narrow strip layout — with a larger, more scannable payment QR code, and an explicit shipping cost line for Mail Order pre-orders.

**Why this priority**: This is a real usability improvement but is more involved than Story 1/2 and depends on nothing else being broken to deliver value on its own — it can ship after the standout information from Story 2 is already in place.

**Independent Test**: Can be fully tested by opening a Mail Order pre-order's invoice with shipping cost set, and confirming: the document renders noticeably wider than before, items appear in a clear table (not a stacked list), a shipping cost line is present and included in the total, and the payment QR code is larger than before.

**Acceptance Scenarios**:

1. **Given** any pre-order invoice, **When** it is opened, **Then** it displays as three clear sections in order — a header (store identity, order/customer identity, status), an itemized table (product, quantity, unit price, line total), and a footer (payment terms, closing message) — at a wider width than the current document.
2. **Given** a Mail Order pre-order with a shipping cost greater than zero, **When** its invoice is viewed, **Then** a "Shipping cost" line appears in the totals section, consistent with the amount already recorded for that pre-order.
3. **Given** a pre-order with no shipping cost (Self Pickup, or Mail Order with zero shipping), **When** its invoice is viewed, **Then** the shipping cost line is omitted, not shown as "Rp 0".
4. **Given** a payment channel with a QR code, **When** it is shown on the invoice, **Then** it renders visibly larger than the current size, while remaining clickable to open the existing full-size popup (feature 022).

---

### User Story 4 - Uploaded store logo actually appears everywhere it's configured to (Priority: P1)

An owner/admin who uploads a store logo in Settings → General sees it appear immediately in that same screen, and it also appears on every invoice document — reliably, not intermittently.

**Why this priority**: This is a reported defect in already-shipped functionality (feature 001's store identity, extended by feature 022's invoice redesign) — store branding silently failing to appear is a visible, credibility-damaging bug for a product whose whole pitch is professional-looking customer documents.

**Independent Test**: Can be fully tested by uploading a logo image in Settings → General, confirming it appears in that screen immediately and after a page reload, and confirming it also appears on a freshly generated pre-order invoice and sales receipt.

**Acceptance Scenarios**:

1. **Given** an owner/admin on the Settings → General screen, **When** they upload a store logo image, **Then** the logo preview appears immediately after the upload completes.
2. **Given** a store logo was uploaded previously, **When** the Settings → General screen is reloaded from scratch, **Then** the logo still appears (not blank).
3. **Given** a store logo is configured and the "Show logo" toggle is enabled, **When** a pre-order invoice or sales receipt is generated, **Then** the logo appears in the document header.

---

### Edge Cases

- What happens if an event's start/end dates are edited after an "available on" choice was made, but the event is still multi-day (just shifted)? The stored choice ("Day 1" / "Day 2") still refers to the (new) start/end date, so it's re-displayed against the updated dates without needing to be re-chosen — only a collapse to a single-day event clears it (see User Story 1, Acceptance Scenario 3).
- What happens on a sales receipt (a walk-in POS sale) for an order not tied to any specific "available on" choice, because sales aren't pre-orders? The standout location block still applies (every order is tied to an event, which may have a location), but the standout available-day block only appears if that event has an "available on" day set — the same omission rule as pre-orders.
- What happens to existing pre-order/payment invoices for events created before this feature shipped (no "available on" value set)? They render exactly as before, minus the new standout block, which is simply absent — no error, no placeholder.
- What happens if the store logo file itself was deleted or corrupted outside the app (e.g. manually removed from the server)? Out of scope for this feature — this only fixes the app's own upload-then-display path, not manual file tampering.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Owners/admins MUST be able to set an event's "available on" day to either "Day 1" (the event's start date) or "Day 2" (the event's end date), or leave it unset, when creating or editing an event.
- **FR-002**: The "available on" choice MUST NOT be offered for an event whose start date and end date are the same day.
- **FR-003**: If an event's dates are edited such that the event becomes single-day, any previously-set "available on" choice for that event MUST be cleared.
- **FR-004**: The pre-order invoice, the payment invoice, and the sales receipt MUST each display the event's "available on" day (resolved to its real calendar date) as a visually prominent element, when the linked event has that value set.
- **FR-005**: The same three documents MUST display the event's location as a visually prominent element, when the linked event has a location set.
- **FR-006**: Both the "available on" block and the location block MUST be fully omitted (not shown as empty/placeholder) when the underlying data isn't set.
- **FR-007**: The pre-order invoice (and payment invoice, which shares its layout) MUST be restructured into three distinct sections — header, itemized table, footer — and rendered at a wider width than the current layout.
- **FR-008**: The pre-order invoice's itemized lines MUST be presented as a table (columns for product, quantity, unit price, line total), replacing the current stacked-row presentation.
- **FR-009**: The pre-order invoice MUST display the pre-order's shipping cost as its own line in the totals section whenever it is greater than zero, and omit that line when it is zero.
- **FR-010**: The payment QR code shown on the pre-order invoice MUST render at a larger size than its current size, while remaining clickable to open the existing full-size popup.
- **FR-011**: A store logo uploaded in Settings → General MUST be visible in that screen immediately after upload and after any subsequent page reload.
- **FR-012**: A configured store logo MUST appear on the pre-order invoice, payment invoice, and sales receipt headers whenever the existing "show logo" setting is enabled.

### Key Entities

- **Event**: Gains a new "available on" attribute (unset, "Day 1", or "Day 2" — resolved against the event's own start/end dates), only meaningful for multi-day events.
- **Pre-order Invoice / Payment Invoice**: Existing documents (feature 022) — gain a standout available-day/location block, a restructured header/table/footer layout, a wider rendering width, a bigger QR code, and a shipping cost line.
- **Sales Receipt**: Existing document (feature 001/014) — gains the same standout available-day/location block as the pre-order documents; its own layout, width, QR, and shipping-cost scope are unaffected (sales have no shipping concept).
- **Store Logo**: Existing Settings → General upload (feature 001) — the fix ensures its already-intended display in both the settings screen and every invoice document actually works reliably.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner/admin can set an event's available day in under 10 seconds while filling out the rest of the event form (no extra screen or save step).
- **SC-002**: 100% of pre-order invoices, payment invoices, and sales receipts for events with an available day and a location configured show both pieces of information prominently, without needing to scroll past unrelated content to find them.
- **SC-003**: A customer or staff member can identify a pre-order invoice's item breakdown, shipping cost (if any), and grand total at a glance, without misreading a line item for a total or vice versa.
- **SC-004**: 100% of store logos uploaded in Settings → General remain visible after a page reload and appear on the very next invoice generated, with zero manual workaround (e.g. re-uploading, clearing cache) required.

## Assumptions

- **"Available on" is an event-level setting, not a per-order one** — it describes when the booth itself is open/available, distinct from a pre-order's own "pickup day" (feature 021), which is about when a specific customer collects their specific order. Both can coexist on the same invoice.
- **Only two fixed choices exist ("Day 1"/"Day 2"), not a generic "Day N" picker** — the request explicitly names exactly these two options, tied to start date and end date respectively; this is intentionally simpler than the existing per-order pickup-day picker (which already supports arbitrary "Day N" for events longer than two days).
- **"Sales invoice" refers to the existing POS sale receipt** (`ReceiptModal.vue`, opened from the Sales screen), the only other customer-facing transaction document in this product besides the pre-order invoice family.
- **The layout redesign, bigger QR, and shipping-cost line are scoped to the pre-order invoice / payment invoice only** — the sales receipt has no shipping-cost concept and doesn't currently show a payment QR code on the document itself (QR only appears during live payment collection), so those specific changes don't apply to it; it only gains the standout available-day/location block from User Story 2.
- **The store logo bug's concrete root cause will be identified during planning** — initial investigation found the Settings screen constructs the logo's display URL differently from how every other image in this product (products, categories, payment-channel QR codes, and the invoice documents themselves) does it, which is the leading suspect; the fix is to make it consistent with that one existing, working convention rather than inventing a new one.
- **No new Settings fields are introduced** — this feature only fixes the existing logo upload/display path and reuses the existing "show logo" toggle; it does not add new logo-related configuration.

## Resolved Clarifications

No [NEEDS CLARIFICATION] markers were needed — all ambiguities in the request had a single reasonable, low-risk default (see Assumptions), each either directly implied by explicit wording in the request ("Day 1 (start date) or Day 2 (end date)") or by how the existing codebase already draws an equivalent distinction (pickup day vs. event availability; sales receipt vs. pre-order invoice scope).
