# Feature Specification: Preorder Invoice Restyled as POS Receipt

**Feature Branch**: `011-preorder-invoice-receipt-style`

**Created**: 2026-09-05

**Status**: Draft

**Input**: User description: "update invoice preorder ini seperti receipt POS [Image #10] tapi diberikan tanda yang jelas bahwa itu transaksi preorder beserta statusnya" — restyle the preorder invoice document to visually match the POS sale receipt (store header, itemized line items, print/download actions), while keeping it unmistakably marked as a preorder transaction and showing its current status.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Preorder invoice looks and functions like the POS receipt (Priority: P1)

A cashier or the customer needs to view or print the invoice for a preorder. Today that document (screenshot) is a plain box with a small "Invoice" pill, the preorder number, customer name, a bare item list, and a Total/Paid/Outstanding block — visually inconsistent with the POS sale receipt customers already recognize (store name/logo/address header, dashed-line itemization, larger total display, download-as-image/PDF actions). The user needs the preorder invoice restyled to match that familiar receipt layout, so it reads as "the same kind of document" rather than a separate, less-polished one.

**Why this priority**: This is the entire scope of the request — a visual consistency fix to a document every preorder customer sees, using an already-proven layout (the POS receipt) as the reference rather than inventing a new one.

**Independent Test**: Open a preorder's invoice (from the Pre-orders list "Print"/"Detail" action) and confirm it now shows a store header (name/logo/address), a dashed-line itemized product list, and a prominent total, matching the visual structure of a POS sale receipt, with working download-as-image and download-as-PDF actions.

**Acceptance Scenarios**:

1. **Given** a preorder with at least one item, **When** its invoice is opened, **Then** the document shows a store header block (store name, logo if set, address if set) at the top, matching the POS receipt's header structure.
2. **Given** the same invoice, **When** viewed, **Then** each ordered item is listed with quantity, name, unit price, and line total inside a dashed-line-bordered item section, matching the POS receipt's itemization style.
3. **Given** the same invoice, **When** viewed, **Then** the total due is displayed prominently (large, bold), matching the POS receipt's total-amount emphasis.
4. **Given** the same invoice, **When** the user chooses to download it, **Then** they can download it as an image and as a PDF, the same two options already available on the POS receipt.

---

### User Story 2 - The document is unmistakably a preorder, not a regular sale, with its current status shown (Priority: P1)

Because the restyled document now closely resembles a POS sale receipt, a customer or cashier glancing at it must still be able to tell at a glance that this is a preorder — not a completed sale — and see where that preorder currently stands (e.g. ordered, deposit paid, goods arrived, settled, handed over, or cancelled).

**Why this priority**: Without this, the visual restyle could actively mislead a customer into thinking they received a regular sale receipt for goods already in hand, which is a correctness/trust problem, not just a cosmetic one — equally critical to User Story 1 and delivered together.

**Independent Test**: Open invoices for preorders in at least two different statuses and confirm each one prominently and distinctly shows a "preorder" marking plus that preorder's specific current status, with no ambiguity that it is a regular sale.

**Acceptance Scenarios**:

1. **Given** any preorder's invoice, **When** viewed, **Then** a clear, prominent label identifies the document as being for a preorder (not a completed sale).
2. **Given** that same invoice, **When** viewed, **Then** the preorder's current status (e.g. ordered, deposit paid, goods arrived, settled, handed over, cancelled) is displayed alongside or near that preorder marking.
3. **Given** a preorder whose status changes over time (e.g. from "ordered" to "deposit paid"), **When** its invoice is reopened later, **Then** the status shown reflects the preorder's current state at the time of viewing, not a stale snapshot from when it was first created.
4. **Given** a cancelled preorder, **When** its invoice is viewed, **Then** the cancelled status is shown with the same clarity as any other status, not hidden or downplayed.

---

### Edge Cases

- A preorder with no store logo, no store address, or no customer contact info must render the header/footer sections that already gracefully omit missing fields on the POS receipt (no broken layout, no empty placeholder boxes) — the same "omit entirely when unset" behavior already established there.
- A preorder invoice must continue to work as both an on-screen view and a downloadable image/PDF, since the underlying capture mechanism captures whatever is currently rendered — the restyle must not break that mechanism.
- The existing distinction this document already draws between "invoice" (order confirmation) and other lifecycle moments must be preserved — this restyle changes appearance and status visibility, not which document is shown when.
- The Total/Paid/Outstanding figures already shown today must still be present in the restyled layout — the receipt-style itemization replaces the *presentation*, not the underlying financial summary a preorder invoice is expected to carry (unlike a completed sale's receipt, a preorder invoice inherently needs to show what's still owed).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The preorder invoice document MUST display a store header block (store name, logo when configured, address when configured) in the same structural position and style as the POS sale receipt's header.
- **FR-002**: The preorder invoice document MUST list each ordered item (quantity, name, unit price, line total) inside a dashed-line-bordered item section, matching the POS sale receipt's itemization style.
- **FR-003**: The preorder invoice document MUST display the total due with the same visual prominence (size/weight) as the POS sale receipt's total.
- **FR-004**: The preorder invoice document MUST continue to display the amount already paid and the outstanding balance, since — unlike a completed sale — a preorder invoice inherently needs to communicate what remains owed.
- **FR-005**: The preorder invoice document MUST support downloading as an image and as a PDF, using the same mechanism already used by the POS sale receipt.
- **FR-006**: The preorder invoice document MUST display a clear, prominent marking identifying it as a preorder transaction, distinct from a regular completed-sale receipt.
- **FR-007**: The preorder invoice document MUST display the preorder's current status alongside that preorder marking.
- **FR-008**: The status shown MUST reflect the preorder's actual current state at the time the document is viewed, not a value fixed at preorder creation.
- **FR-009**: Optional fields (store logo, store address, customer contact info) that are unset MUST be omitted entirely from the rendered document, not shown as empty placeholders.
- **FR-010**: This restyle MUST NOT change which underlying document (invoice vs. any other preorder document) is shown for a given preorder state — it changes the visual presentation and status visibility of the existing invoice, not the business logic deciding when it's shown.

### Key Entities

- **Preorder**: Existing entity; its invoice document is read-only presentation built from already-available preorder data (items, customer, totals, status) — no new fields are introduced.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user already familiar with the POS sale receipt can recognize the restyled preorder invoice as visually consistent with it (shared header style, itemization style, total emphasis) without being told they're related documents.
- **SC-002**: 100% of preorder invoices, regardless of status, show both a preorder marking and that preorder's current status without the viewer needing to navigate elsewhere to find it.
- **SC-003**: A user can distinguish a preorder invoice from a regular POS sale receipt at a glance, in 100% of reviewed cases, despite their now-similar layout.
- **SC-004**: Downloading a preorder invoice as an image or PDF succeeds and produces a document showing the same information visible on screen, matching the reliability already established for POS receipt downloads.

## Assumptions

- "Receipt style" refers specifically to `ReceiptModal.vue`'s established visual structure (header block, dashed-line item section, prominent total, download actions) — this feature restyles the existing preorder invoice component to match that structure, it does not merge the two into one shared component or change the POS receipt itself.
- Consistent with this codebase's existing convention that customer-facing transaction documents (the POS receipt) are always rendered in Indonesian regardless of the viewer's selected interface language, the restyled preorder invoice follows the same convention, since it is now explicitly modeled on that document.
- The "preorder marking" is a visually distinct badge/label (e.g. a colored pill reading "Pre-order") rather than plain body text, so it reads as a status indicator at a glance rather than something a reader has to parse out of a sentence.
- This feature is scoped to the preorder *invoice* document specifically (the order-confirmation document shown from the Pre-orders list). It does not change any separate per-payment-event receipt or other preorder document that may exist elsewhere in the product.
- No new data is required from the backend — current status and all financial figures needed are already available wherever this invoice is currently sourced from.
