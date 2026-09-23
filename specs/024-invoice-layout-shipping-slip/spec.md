# Feature Specification: Pre-order Invoice Layout Refinements & Shipping Slip

**Feature Branch**: `024-invoice-layout-shipping-slip`

**Created**: 2026-09-23

**Status**: Draft

**Input**: User description: "adjust preorder invoice as title; add event name, before location and available on; add date created; align center for location and available on; add shipping slip with details of event name, po number, from, to and item type; two columns of ways to pay, first column is for qr code (make it bigger), second column is for bank payment; add footer message from setting page; make two column for the header: column one: event name, location, available on; column two: po number, status, to: name, email, phone, social media handler, address"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A clearer, better-organized invoice header (Priority: P1)

Someone opening a pre-order invoice can immediately see everything about the order and the recipient at a glance, without scanning the whole document — event details on one side, order/recipient details on the other, laid out as two columns instead of one long stacked list.

**Why this priority**: This is the core restructuring the rest of the request depends on — the event-name/date-created/centering adjustments are all refinements to this same header area.

**Independent Test**: Can be fully tested by opening any pre-order invoice and confirming the header renders as two columns: the left showing event name, location, and available-on day (each on its own line, centered); the right showing the pre-order number, its status, and a "To:" block with the customer's name, email, phone, social media handle, and address.

**Acceptance Scenarios**:

1. **Given** a pre-order invoice for an order tied to an event with a name, location, and available-on day all set, **When** the invoice is viewed, **Then** the left column shows the event name first, then the location, then the available-on day, each centered within that column.
2. **Given** the same invoice, **When** viewed, **Then** the right column shows the pre-order number, its current status, and a "To:" block listing the customer's name, email, phone, social media handle, and address — whichever of those fields the customer actually has on file (missing ones are simply skipped, not shown blank).
3. **Given** a pre-order not tied to any event, **When** its invoice is viewed, **Then** the left column's event-related lines are omitted entirely, without leaving an empty gap where the column content would normally start.
4. **Given** any pre-order invoice, **When** viewed, **Then** it also shows the date the pre-order was created, positioned near the order identity information.

---

### User Story 2 - The invoice document is clearly labeled and dated (Priority: P2)

Anyone glancing at the invoice window's title bar, or comparing it to other documents, can tell at a glance that this is specifically a pre-order invoice, and when the order was placed.

**Why this priority**: A smaller polish item — clarity of labeling — that depends on nothing else in this feature to deliver value on its own.

**Independent Test**: Can be fully tested by opening a pre-order invoice and confirming the document's title area reads as a pre-order invoice (not just a bare order number), and that a creation date is visible somewhere in the document.

**Acceptance Scenarios**:

1. **Given** any pre-order invoice is open, **When** its title area is read, **Then** it identifies the document as a pre-order invoice, not just showing the order number on its own.

---

### User Story 3 - Payment options are easier to scan, with a bigger QR (Priority: P1)

A customer looking at the invoice's payment options can immediately tell which are scan-to-pay QR channels and which are bank transfers, because they're grouped into two separate columns instead of one mixed list — and the QR code itself is large enough to scan comfortably straight from the screen.

**Why this priority**: Directly affects whether a customer can actually complete payment without confusion — a core purpose of this document.

**Independent Test**: Can be fully tested by opening an invoice for a store with both QR-based and bank-transfer payment channels configured, and confirming they render in two visually separate columns, with the QR image large enough to be the dominant visual element in its column.

**Acceptance Scenarios**:

1. **Given** a store with both a QR-based channel (e.g. an e-wallet) and a bank-transfer channel configured, **When** the invoice is viewed, **Then** the QR-based channel(s) appear in one column and the bank-transfer channel(s) appear in a separate column alongside it.
2. **Given** a store with only one of the two channel types configured, **When** the invoice is viewed, **Then** only that one column's worth of content appears — no empty second column taking up space.
3. **Given** a QR-based channel, **When** its QR code is shown, **Then** it renders larger than the invoice's current QR size.

---

### User Story 4 - A separate shipping slip for Mail Order pre-orders (Priority: P2)

Staff packing a Mail Order pre-order for shipment can print or view a simple shipping slip — separate from the payment/pricing details — showing the event name, the pre-order number, who it's from (the store), who it's going to (the customer), and what kind of items are inside, so it can be attached to the package without exposing pricing information to a courier.

**Why this priority**: A genuinely new, separate capability rather than a refinement of the existing invoice — valuable on its own, but the invoice itself (Stories 1–3) is usable without it.

**Independent Test**: Can be fully tested by opening the invoice for a Mail Order pre-order and confirming a distinct shipping-slip section appears showing the event name, pre-order number, the store as sender, the customer as recipient, and the type of item(s) in the order — separately from the itemized pricing table.

**Acceptance Scenarios**:

1. **Given** a Mail Order pre-order's invoice, **When** viewed, **Then** a shipping slip section is present showing: the event name, the pre-order number, the store's identity as the sender ("From"), the customer's recipient details as the destination ("To"), and the type of item(s) being shipped.
2. **Given** a Self Pickup pre-order's invoice, **When** viewed, **Then** no shipping slip section appears — pickup orders aren't shipped.
3. **Given** a Mail Order pre-order that has no shipment record created yet, **When** its invoice is viewed, **Then** the shipping slip still shows using the same recipient details already visible elsewhere on the invoice (the customer's own name/phone/address), rather than being blocked on a shipment record existing.

---

### User Story 5 - The store's closing message still appears (Priority: P3)

The store's configured footer/closing message continues to appear on the invoice exactly as it does today.

**Why this priority**: This already exists (feature 023) — listed here only to confirm it isn't accidentally dropped by this round of layout changes, not because it's new work.

**Independent Test**: Can be fully tested by confirming a store with a configured footer message still sees that exact message on the invoice after these layout changes.

**Acceptance Scenarios**:

1. **Given** a store with a footer/closing message configured in Settings, **When** any pre-order invoice is viewed, **Then** that message still appears, unchanged in content, after the layout changes in this feature.

---

### Edge Cases

- What happens when a customer has none of email/phone/social handle/address on file (only a name)? The "To:" block shows just the name — no blank lines, no placeholder text for the missing fields.
- What happens to the two-column header on a cancelled pre-order? Both columns render exactly as for any other status — the status shown in the right column simply reads "Cancelled".
- What happens if a store has neither QR nor bank-transfer channels configured at all? The whole two-column payment section is omitted, exactly as the single payment-terms section is omitted today when nothing is configured.
- What happens to the shipping slip's "item type" when an order has several different products? All distinct product types in the order are listed, not just the first one.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The pre-order invoice's header MUST render as two columns: a left column with event name, location, and available-on day (in that order); a right column with the pre-order number, its status, and a "To:" block with the customer's name, email, phone, social media handle, and address.
- **FR-002**: Within the left column, the event name, location, and available-on lines MUST each be center-aligned.
- **FR-003**: Any of the left column's event-related lines, and any of the right column's "To:" fields, MUST be omitted individually when the underlying data isn't set — never shown blank or with placeholder text.
- **FR-004**: The invoice MUST display the pre-order's creation date.
- **FR-005**: The invoice document's title/heading MUST identify it as a pre-order invoice, not merely display the order number alone.
- **FR-006**: The invoice's payment options MUST be grouped into two columns: QR-based channels in one column, bank-transfer channels in the other — each column omitted individually when that channel type isn't configured.
- **FR-007**: A QR code shown in the payment-options section MUST render larger than its current size.
- **FR-008**: For a Mail Order pre-order, the invoice MUST include a shipping slip section showing the event name, the pre-order number, the store as sender, the customer as recipient, and the type(s) of item(s) in the order.
- **FR-009**: The shipping slip section MUST NOT appear for a Self Pickup pre-order.
- **FR-010**: The shipping slip MUST render using the customer's own recorded details even when no shipment record has been created yet for that pre-order.
- **FR-011**: The store's configured footer/closing message MUST continue to appear on the invoice after these layout changes.

### Key Entities

- **Pre-order Invoice**: Existing document (features 022/023) — header restructured into two columns, payment-options section restructured into two columns, gains a creation-date display and a conditional shipping-slip section.
- **Shipping Slip**: A new section within the existing invoice document (not a separate screen or download) — sender/recipient/event/order-number/item-type summary for Mail Order orders, sourced from data already available on the pre-order and its customer.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can identify the event, the recipient, and the order status of any pre-order invoice within 3 seconds of opening it, without scrolling.
- **SC-002**: A customer can distinguish QR-pay options from bank-transfer options at a glance, with zero instances of the two being visually mixed together.
- **SC-003**: 100% of Mail Order pre-order invoices include a usable shipping slip (event name, order number, sender, recipient, item type all present when the underlying data exists), with zero instances of it appearing for Self Pickup orders.
- **SC-004**: A store's footer message and QR code visibility are unaffected by this change — verified by comparing before/after on the same pre-order.

## Assumptions

- **"To:" recipient fields reuse the pre-order's already-loaded customer record** (name, email, phone, social media handle, address) — the same `Customer` fields already available in the invoice payload (features 022/023) — no new data collection is introduced.
- **"From" on the shipping slip is the store's own identity** (name and address), reusing the store-identity block already shown at the top of the invoice — not a separate field to configure.
- **"Item type" on the shipping slip means the distinct product names/types in the order**, not a new categorization field — derived from the pre-order's existing item list.
- **The shipping slip is a section within the same invoice document**, shown/hidden based on the pre-order's fulfillment method, not a separate downloadable file or screen — consistent with how this document has always been a single client-rendered view (features 007/022/023).
- **"Adjust preorder invoice as title" means the document's own title/heading text**, changed to explicitly read as a pre-order invoice (e.g. "Pre-order Invoice") rather than just showing the bare order number as it does today.
- **This feature applies to the pre-order invoice and, where it shares the same document shell, the payment invoice (feature 022's payment-per-event document)** — the sales/POS receipt is a different, simpler document not covered by this round of changes (no shipping, no per-channel-type payment breakdown was requested for it).
- **No new Settings fields are introduced** — the footer message continues to come from the existing `receipt_footer_text` setting.

## Resolved Clarifications

No [NEEDS CLARIFICATION] markers were needed. The two genuinely ambiguous points in the request — what a "shipping slip" concretely contains, and whether it needs an actual `Shipment` record to exist — were resolved with a low-risk default: reuse data the invoice already has (event, pre-order, customer) rather than introducing a new dependency on the separately-created `Shipment` record, since the request's own wording ("from, to, item type") maps directly onto data already present, and blocking the slip on a shipment existing would contradict the point of a document meant to be produced *before* packing happens.
