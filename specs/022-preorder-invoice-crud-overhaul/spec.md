# Feature Specification: Pre-order Invoice & CRUD Overhaul

**Feature Branch**: `022-preorder-invoice-crud-overhaul`

**Created**: 2026-09-23

**Status**: Draft

**Input**: User description: "in preorder page: add edit and delete preorder (affects stock); auto-fill customer details when creating shipment data; remove city and postal code (folded into address); rename Receipt to Invoice; redesign the invoice document (reference designs attached) with store identity, payment terms, footer text, a bigger clickable QR; rename/redesign Payment Receipt to Payment Invoice matching the new design; bulk download and email invoices/payment invoices from the list; show the customer list with a scrollbar in the picker; overhaul the import/export column layout (event name instead of ID, drop phone/email columns, receive-method wording, comma-separated products/quantities, add shipping cost/courier/ETA)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Correct a mistake on a pre-order after it's created (Priority: P1)

A staff member notices a pre-order was entered with the wrong item, quantity, or other detail after saving it — today the only way to change it is to cancel the whole thing and start over, which is confusing and, if the order already reached "Goods arrived," would leave stock in the wrong state. They need to edit the order's details, or delete it entirely, and have the store's stock count stay accurate no matter what stage the order is in when they do it.

**Why this priority**: This is the single most-requested gap in the current screen — creating an order is easy, but nothing lets a staff member fix or remove one afterward, which is a real operational dead end every time a mistake happens.

**Independent Test**: Can be fully tested by creating a pre-order, editing its items, and confirming both the order and the store's stock reflect the change correctly; separately, deleting a still-"Ordered" pre-order (before any stock or payment exists) and confirming it disappears from the list with no stock side-effect, while attempting to delete one at a later status correctly refuses and points to "Cancel" instead.

**Acceptance Scenarios**:

1. **Given** a pre-order that has not yet had its goods marked "arrived," **When** a staff member edits its items (add, remove, or change quantity), **Then** the order's totals update accordingly and no stock movement is recorded (goods were never physically received for this order yet).
2. **Given** a pre-order whose goods have already been marked "arrived" (stock was increased for it), **When** a staff member edits its items, **Then** the store's stock is adjusted to match the new item list exactly — never left over- or under-counted from the original items.
3. **Given** a pre-order that has reached "Handed over" or "Cancelled," **When** a staff member attempts to edit its items, **Then** the system refuses the edit and explains that a completed/cancelled order can no longer be changed — this is a fully closed transaction.
4. **Given** a pre-order still at status "Ordered" (nothing has happened to stock or payments yet), **When** a staff member deletes it, **Then** it (and its items) no longer appear anywhere in the pre-order list or reports, and no stock adjustment is needed (none was ever made).
5. **Given** a pre-order at any status other than "Ordered" (e.g. "Deposit paid," "Goods arrived," "Settled," "Handed over"), **When** a staff member attempts to delete it, **Then** the system prevents the deletion and directs them to use the existing "Cancel" action instead.
6. **Given** a pre-order that already has a recorded payment, **When** a staff member attempts to delete it, **Then** the system prevents the deletion and explains why, rather than silently discarding a record of money already collected (this is already covered by Scenario 5 in practice, since any status past "Ordered" that has a payment is excluded either way, but the payment check stands on its own regardless of status).

---

### User Story 2 - Faster, more accurate shipment details (Priority: P1)

When a staff member records shipment details for a "Mail Order" pre-order, they shouldn't have to retype the customer's name, phone, and address — that information was already captured when the order was created. They also don't need separate "city" and "postal code" boxes; one address field is enough.

**Why this priority**: This removes duplicate data entry on every single mail-order shipment, and simplifies a form that currently asks for more structure than the business actually uses.

**Independent Test**: Can be fully tested by opening "Create shipment data" on a pre-order with a known customer and address, and confirming the recipient name, phone, and address fields are already filled in, with no separate city/postal code fields present.

**Acceptance Scenarios**:

1. **Given** a Mail Order pre-order with a customer who has a name, phone, and address on file, **When** a staff member opens "Create shipment data," **Then** the recipient name, phone, and address fields are pre-filled from that customer's record.
2. **Given** the pre-filled shipment details, **When** the staff member wants to ship to a different address (e.g. a gift), **Then** they can still edit the pre-filled fields before saving.
3. **Given** the shipment form, **When** a staff member looks for "city" and "postal code" fields, **Then** they no longer exist as separate fields — that information is expected to be part of the single address field instead.

---

### User Story 3 - A professional-looking pre-order invoice (Priority: P1)

The document a customer receives for their pre-order — currently labeled "Receipt" and showing only line items and a total — needs to look like a real business invoice: it should carry the store's identity (logo, name, contact person, phone, email, full address), tell the customer how and by when to pay, and end with the store's own closing message.

**Why this priority**: This document is customer-facing and represents the business — a bare list of items with no store branding or payment guidance looks unfinished and increases the chance a customer doesn't know how to actually pay.

**Independent Test**: Can be fully tested by opening a pre-order's document, confirming it is now labeled "Invoice" everywhere it previously said "Receipt," and that it shows the store's logo/name/contact/address, the available ways to pay, and the store's configured closing message.

**Acceptance Scenarios**:

1. **Given** any pre-order document previously labeled "Receipt," **When** a staff member or customer views it (on screen or as a downloaded file), **Then** it is labeled "Invoice" instead, consistently everywhere that label appears.
2. **Given** the store has its logo, name, contact person, phone, email, and address configured, **When** an invoice is generated, **Then** all of that information appears on it.
3. **Given** the store has one or more ways to receive payment configured (bank transfer, e-wallet, etc.), **When** an invoice is generated, **Then** those payment options are listed on it so the customer knows how to pay.
4. **Given** the store has a closing/footer message configured, **When** an invoice is generated, **Then** that message appears at the bottom of the invoice.
5. **Given** a pre-order's payment QR code needs to be shown during payment collection, **When** a staff member views it, **Then** it displays larger than before and can be clicked/tapped to open a full-size view.

---

### User Story 4 - A matching invoice for each payment received (Priority: P2)

The document generated after recording a single payment against a pre-order — currently labeled "Payment receipt" — should look and feel like the same invoice from Story 3, just focused on what was actually paid in that one payment event rather than the whole order.

**Why this priority**: Consistency across every document a customer receives from this business matters, but this depends on Story 3's redesign already existing to match against.

**Independent Test**: Can be fully tested by recording a payment on a pre-order and confirming the resulting document is labeled "Payment invoice," uses the same visual layout as the main invoice, and clearly shows what was paid in that specific payment versus the order's full picture.

**Acceptance Scenarios**:

1. **Given** a payment recorded against a pre-order, **When** the resulting document is viewed, **Then** it is labeled "Payment invoice" (not "Payment receipt").
2. **Given** that document, **When** compared to the main pre-order invoice from Story 3, **Then** it uses the same overall look (store identity, layout, footer) — the two documents read as being from the same business and the same document family.
3. **Given** that document, **When** viewed, **Then** it clearly distinguishes what this specific payment covered from the order's overall total and remaining balance — a reader should never confuse "what I paid today" with "what the whole order costs."

---

### User Story 5 - Handle many invoices at once (Priority: P2)

A staff member who needs to send several customers their invoices, or archive a batch of them, shouldn't have to open each pre-order one at a time. From the pre-order list, they should be able to select several orders and either download all of their invoices at once or have them emailed out.

**Why this priority**: This is a real time-saver for a store handling many orders, but it depends on Story 3/4's invoice documents already existing in their new form.

**Independent Test**: Can be fully tested by selecting multiple pre-orders in the list and triggering a bulk download, confirming all selected orders' invoices are produced; separately, triggering a bulk email send and confirming each selected order's customer receives their invoice.

**Acceptance Scenarios**:

1. **Given** the pre-order list, **When** a staff member selects more than one pre-order, **Then** an option appears to download all of their invoices in one action.
2. **Given** the same selection, **When** the staff member instead chooses to send by email, **Then** each selected pre-order's invoice (or payment invoice, if that's what's being sent) is emailed to that order's customer.
3. **Given** a selected pre-order with no customer email on file, **When** a bulk email send is triggered, **Then** that specific order is reported as skipped rather than silently failing or blocking the rest of the batch.

---

### User Story 6 - Find a customer faster while creating a pre-order (Priority: P2)

When picking a customer for a new pre-order, a staff member should be able to see the store's customer list right away — scrolling through it — rather than being required to type a search term first before anything appears.

**Why this priority**: For a store with a modest, known customer base, browsing is often faster than typing a partial name; this is a small but real usability gain on the highest-frequency screen in the workflow.

**Independent Test**: Can be fully tested by opening the customer picker on the New pre-order form with no text typed, and confirming a scrollable list of existing customers is already visible.

**Acceptance Scenarios**:

1. **Given** the customer picker is opened with nothing typed, **When** the staff member looks at it, **Then** a list of existing customers is already visible and can be scrolled.
2. **Given** the visible list, **When** the staff member types part of a name or phone number, **Then** the list narrows to matching customers, same as it does today.

---

### User Story 7 - Bulk import/export matches how the business actually talks about orders (Priority: P3)

The pre-order bulk import/export file should read the way staff actually think about an order: an event by its name (not a database ID), how the customer will receive it in the same wording used elsewhere ("pickup" or "mail order"), which day they'll pick it up, and every item and quantity on one line per order — plus the shipping details (cost, courier, expected arrival) that a mail order needs.

**Why this priority**: This makes the bulk file easier for non-technical staff to fill in correctly, but it depends on Story 3's terminology change and is the most mechanically involved piece — lowest priority of the changes in this set.

**Independent Test**: Can be fully tested by downloading the new template, confirming its columns match this new layout, filling in one order with multiple items on a single row, importing it, and confirming a correctly-formed pre-order results; separately, exporting existing pre-orders and confirming the file uses this same column layout.

**Acceptance Scenarios**:

1. **Given** the import template, **When** a staff member opens it, **Then** it has one column for the event's name (not its ID), no customer phone/email columns, a receive-method column accepting "pickup" or "mail order," a pickup-day column, one column listing every item for the order (comma-separated), a matching column listing each item's quantity in the same order (comma-separated), and columns for shipping cost, courier, and expected arrival date.
2. **Given** a filled-in row with multiple items listed in the comma-separated product and quantity columns, **When** it is imported, **Then** one pre-order is created with all of those items attached correctly (each product matched to its quantity by position in the list).
3. **Given** the export of existing pre-orders, **When** a staff member opens the file, **Then** it uses this exact same column layout, so a re-import of an unmodified export produces the same result.

---

### Edge Cases

- What happens if a staff member edits a pre-order's items while a payment has already been recorded against it? The system must not let an edit silently invalidate money already collected — at minimum, the new total must still make sense against what's already been paid (e.g. it must not go below the amount already paid without addressing that mismatch explicitly).
- What happens if the customer's phone or address wasn't recorded when they were added? The shipment form's auto-fill leaves those fields blank rather than showing something incorrect, and the staff member fills them in manually as they do today for a walk-in.
- What happens to an invoice for a pre-order created before the store had a logo, contact person, or payment channel configured? Those specific sections are simply omitted from the invoice rather than showing blank placeholders or broken layout.
- What happens if a bulk invoice download or email is triggered for zero selected pre-orders? The action is not available at all until at least one is selected.
- What happens when importing a comma-separated product/quantity list where the counts don't match (e.g. 3 products but only 2 quantities)? That row is rejected with a clear error identifying the row, the same as any other row-level import problem today.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Staff MUST be able to edit an existing pre-order's items (add, remove, change quantity) and other editable details, as long as the order's status is not "Handed over" or "Cancelled."
- **FR-001a**: Attempting to edit a pre-order that is "Handed over" or "Cancelled" MUST be refused with a clear explanation that a completed/cancelled order can no longer be changed.
- **FR-002**: Editing a pre-order's items MUST keep the store's stock count accurate for whatever stage the order is currently at — no stock effect for an order whose goods haven't arrived yet, and a corrected stock effect (not a doubled or missed one) for an order whose goods already have (i.e. status "Goods arrived" or later, up to but not including "Handed over").
- **FR-003**: Staff MUST be able to delete a pre-order **only while its status is still "Ordered"** — the earliest stage, before any stock movement or payment has ever been recorded for it. Deleting it removes it (and its items) from the pre-order list and reports; no stock reversal is needed since none was ever applied.
- **FR-004**: The system MUST prevent deleting a pre-order whose status is anything other than "Ordered," or that has any recorded payment, directing staff to the existing "Cancel" status action instead — with a clear explanation, never a silent failure.
- **FR-005**: The "Create shipment data" form MUST pre-fill the recipient name, phone, and address from the pre-order's customer record, while still allowing the staff member to edit any of those fields before saving.
- **FR-006**: The shipment form MUST NOT present separate "city" and "postal code" fields — address information is captured as a single field.
- **FR-007**: Every place the pre-order document was previously labeled "Receipt" MUST now read "Invoice," with no functional change other than the label and the redesign described below.
- **FR-008**: The pre-order invoice MUST display the store's logo, name, contact person, phone, email, and full address, when those are configured.
- **FR-009**: The pre-order invoice MUST list the store's configured ways to receive payment.
- **FR-010**: The pre-order invoice MUST display the store's configured closing/footer message.
- **FR-011**: The payment QR code shown during payment collection MUST display larger than it currently does, and MUST be clickable/tappable to open a full-size view.
- **FR-012**: The document generated after recording a payment against a pre-order MUST be labeled "Payment invoice" (not "Payment receipt") and MUST use the same overall visual design as the pre-order invoice from FR-007–FR-010, while clearly distinguishing that specific payment's amount from the order's overall total and remaining balance.
- **FR-013**: Staff MUST be able to select multiple pre-orders from the list and download all of their invoices in one action.
- **FR-014**: Staff MUST be able to select multiple pre-orders from the list and have their invoices (or payment invoices) emailed to each order's customer in one action.
- **FR-015**: A bulk email send MUST report, per pre-order, whether it was sent or skipped (and why), rather than failing the whole batch silently.
- **FR-016**: The customer picker MUST show a scrollable list of existing customers immediately when opened, before any search text is typed, narrowing that list as the staff member types.
- **FR-017**: The pre-order bulk import template and export file MUST use one shared column layout: event name (not ID), receive method as "pickup"/"mail order," pickup day, one comma-separated column for items and a second comma-separated column for their quantities (matched by position), shipping cost, courier, and expected arrival date — and MUST NOT include customer phone/email columns.
- **FR-018**: Importing a row whose comma-separated item list and quantity list have different counts MUST be rejected as a row-level error identifying that row, not silently truncated or guessed.

### Key Entities

- **Pre-order**: Gains the ability to be edited (its items, in particular) and deleted after creation, with both operations kept consistent with the store's stock records.
- **Shipment**: Its address is now a single field instead of address line + city + postal code; its recipient fields can be pre-filled from the linked pre-order's customer.
- **Invoice / Payment Invoice**: The renamed, redesigned documents already described above — not new entities, but a significant change to what a pre-order's existing "document" concept displays and is called.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can correct a mistaken pre-order (wrong item or quantity) without needing to cancel and manually recreate the whole order, and the store's stock count is provably correct (matches a manual recount) immediately afterward.
- **SC-002**: Creating a shipment for a pre-order with an existing customer takes no re-typing of that customer's name, phone, or address.
- **SC-003**: 100% of invoices generated after this change show the store's configured identity, payment options, and footer message — never a document with only line items and a total.
- **SC-004**: A staff member can produce invoices for 10 pre-orders (download or email) in a single action, instead of opening 10 separate screens.
- **SC-005**: A filled-in import file using the new column layout, when exported immediately afterward, produces a file usable to recreate the same pre-orders without manual reshaping (round-trip integrity), matching the guarantee already expected of every other import/export feature in this product.

## Assumptions

- **"Payment terms" means the store's configured payment channels** (bank accounts, e-wallets, etc. — already configured in Settings today), listed on the invoice as the customer's ways to pay, rather than a separately-authored payment-terms sentence (no such text field exists in this product today, and none was described with enough specificity to justify inventing one).
- **The invoice's footer message reuses the store's existing configured receipt footer text** setting, rather than introducing a second, separate footer-text field.
- **The store identity fields shown on the invoice (logo, name, contact person, phone, email, address) are exactly the store profile fields already configured in Settings today** — this feature surfaces them on the invoice, it does not add new settings fields.
- **A pre-order's editable fields, for this feature's scope, are its items (add/remove/change quantity) and the other details already collected on the New pre-order form** (discount, fulfillment, notes, etc.) — not its customer or its historical payment records, which stay as recorded.
- **Bulk invoice email reuses the same underlying email delivery already used for per-order notifications today** (synchronous send, no new queue infrastructure), just triggered for multiple orders in one staff action instead of one.
- **The new import/export column layout replaces the existing one** (this is the same single workbook/template already used for pre-order bulk import/export, updated in place) rather than becoming a second, parallel file format to maintain alongside the old one.
- **Pickup day in the import file is entered as "Day 1" / "Day 2" style text** (matching how many days into the linked event), which the system resolves against that event's actual date range to the same real calendar date already stored for pickup day elsewhere in this product — not a change to how pickup day is stored, only to how it's typed into this one file.

## Resolved Clarifications

- **Edit scope vs. order stage** → Resolved (Option A): editing is allowed for any status except "Handed over" and "Cancelled" — including after "Goods arrived," where the stock effect is recalculated to match the edited items. See FR-001/FR-001a/FR-002 and User Story 1's updated acceptance scenarios.
- **Delete guard** → Resolved (Option B): deletion is only allowed while status is still "Ordered" (before any stock movement or payment exists). Every later status — including "Goods arrived" — must use the existing "Cancel" action instead of delete. See FR-003/FR-004.
- **Import/export format** → Resolved (Option A): the new column layout replaces the existing pre-order import/export template entirely; there is no second, parallel format to maintain. See FR-017/FR-018 and the Assumptions section.
