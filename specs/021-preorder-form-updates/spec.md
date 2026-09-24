# Feature Specification: Pre-order Form & Workflow Updates

**Feature Branch**: `021-preorder-form-updates`

**Created**: 2026-09-23

**Status**: Draft

**Input**: User description: "preorder: [screenshot of New preorder form], update: change the 'search name/phone' field from text to dropdown list with search; add diskon field; add quantity item; change Courier to Mail Order; when Self Pickup selected, show option to select Day 1, Day 2 (this will show in preorder invoice); adjust the import and export also; add shipping courier field dropdown, auto select to JNE"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pick a customer faster while creating a pre-order (Priority: P1)

Today, creating a pre-order requires clicking "Pick a customer…", which opens a second pop-up on top of the pre-order form, typing a search term, and clicking a result in a list. A staff member wants this collapsed into a single searchable dropdown right on the pre-order form itself, so picking (or adding) a customer takes one interaction instead of two.

**Why this priority**: This touches every single pre-order created — it is the highest-frequency interaction in this whole workflow, so a rough edge here costs time on every transaction.

**Independent Test**: Can be fully tested by opening "New pre-order," typing a few letters of a customer's name or phone directly into the customer field, seeing a filtered list of matches appear inline, and selecting one — all without a second pop-up appearing.

**Acceptance Scenarios**:

1. **Given** the New pre-order form is open, **When** the staff member types part of a customer's name or phone number into the customer field, **Then** a dropdown list of matching customers appears inline, showing each match's name and phone.
2. **Given** the dropdown list of matches is showing, **When** the staff member selects one, **Then** that customer is attached to the pre-order and the dropdown closes — no separate pop-up window is involved.
3. **Given** no existing customer matches what was typed, **When** the staff member wants to proceed anyway, **Then** they can still create the pre-order as a walk-in (no customer) or add a brand-new customer without leaving the pre-order form.

---

### User Story 2 - Apply a discount to a pre-order (Priority: P1)

A staff member negotiating a pre-order (e.g. a bulk buyer, a loyal customer, a promo) needs to record a discount on the order, so the total the customer actually owes reflects the agreed price — not just the sum of line items.

**Why this priority**: Money accuracy is core to a POS system — without this, staff currently have no correct way to record an agreed discount, and may resort to manually adjusting item prices, which corrupts sales reporting.

**Independent Test**: Can be fully tested by creating a pre-order with items totaling a known amount, entering a discount value, and confirming the pre-order's total (and later, its printed invoice) reflects items minus the discount.

**Acceptance Scenarios**:

1. **Given** a pre-order with one or more items added, **When** the staff member enters a discount amount, **Then** the displayed estimated total updates to reflect the items' total minus the discount.
2. **Given** a saved pre-order that had a discount applied, **When** its invoice or detail is viewed later, **Then** the discount amount is shown alongside the item subtotal and the final total.
3. **Given** no discount is entered, **When** the pre-order is saved, **Then** it behaves exactly as it does today (zero discount, total equals item subtotal plus shipping).

---

### User Story 3 - Fulfillment-specific details: pickup day and mail courier (Priority: P2)

When a staff member selects how the customer will receive their order, the form should immediately ask for the one extra detail that choice requires: which day the customer will pick it up (for self-pickup), or which courier will ship it (for mail order) — and rename "Courier" to "Mail Order" to match how the business actually talks about this option.

**Why this priority**: This directly affects fulfillment operations (which day to expect a pickup, which courier's tracking to use) but is secondary to the core customer/pricing accuracy fixed by Stories 1–2.

**Independent Test**: Can be fully tested by creating a pre-order linked to a multi-day event, choosing "Self Pickup," confirming the day choices offered match that event's actual dates, then separately creating another pre-order choosing "Mail Order" and confirming a courier dropdown appears defaulted to JNE.

**Acceptance Scenarios**:

1. **Given** the New pre-order form is linked to an event running from a start date to an end date, **When** the staff member selects "Self Pickup," **Then** the form offers one selectable day per calendar day in that event's date range (e.g. a 2-day event offers "Day 1 (<date>)" and "Day 2 (<date>)"; a 3-day event offers three), and a day must be chosen before saving.
2. **Given** the linked event runs exactly one day, **When** the staff member selects "Self Pickup," **Then** only that single day is offered (effectively pre-selected, since there is no other choice).
3. **Given** the pre-order has no linked event at all, **When** the staff member selects "Self Pickup," **Then** no specific pickup day can be derived, so the pickup-day choice is not shown and is not required to save (there is nothing meaningful to choose from).
4. **Given** a saved pre-order with "Self Pickup" and a chosen day, **When** its invoice is printed or viewed, **Then** the chosen pickup day (shown as its real calendar date, not just a generic "Day 1" label) is shown on the invoice.
5. **Given** the New pre-order form, **When** the staff member selects "Mail Order" (the option previously labeled "Courier"), **Then** a courier dropdown appears, pre-selected to "JNE," which the staff member may change to a different courier.
6. **Given** every place in the product that previously showed the label "Courier" for this fulfillment option, **When** a user views it after this change, **Then** it reads "Mail Order" instead — consistently, not in only one place.
7. **Given** a pre-order is linked to an event, **When** the staff member changes which event it's linked to (if this product allows changing that after creation) or the event's dates change, **Then** a pickup day chosen against the old dates that's no longer valid must be cleared, not silently kept.

---

### User Story 4 - Bulk import/export reflects the new fields (Priority: P3)

A staff member who imports or exports pre-orders in bulk (e.g. to bring in orders collected at an external sign-up table) needs the spreadsheet format to carry the discount, pickup day, and courier information too — otherwise bulk-imported orders would be missing data that orders entered one-by-one now always have.

**Why this priority**: This depends on Stories 2–3 existing first (there is nothing new to import/export until the fields themselves exist), and bulk import/export is a lower-frequency action than day-to-day order entry.

**Independent Test**: Can be fully tested by exporting existing pre-orders and confirming the file includes columns for discount, pickup day, and courier; then importing a file with values in those columns and confirming the resulting pre-orders have them set correctly.

**Acceptance Scenarios**:

1. **Given** pre-orders exist with a discount, a pickup day, and/or a courier set, **When** the list is exported, **Then** the exported file includes each of those values in its own column.
2. **Given** an import file has a discount value for a row, **When** it is imported, **Then** the resulting pre-order's discount is set accordingly, exactly as if it had been entered through the form.
3. **Given** an import file marks a row as "Self Pickup" with a pickup day, or "Mail Order" with a courier, **When** it is imported, **Then** the resulting pre-order carries that same fulfillment-specific detail.
4. **Given** an import row that specifies a pickup day while its fulfillment is "Mail Order" (or a courier while fulfillment is "Self Pickup") — a combination that cannot happen through the form itself, **When** it is imported, **Then** the mismatched, inapplicable value is reported as a row-level error rather than being silently accepted or silently ignored.

---

### Edge Cases

- What happens if a staff member changes fulfillment from "Self Pickup" (with a day already chosen) to "Mail Order"? The previously chosen pickup day no longer applies and must not be silently kept — switching fulfillment clears the field that no longer applies and requires the newly-relevant one (courier) to be set instead.
- What happens if a discount entered is larger than the items' subtotal? The system must reject a discount that would make the total negative, the same way an over-payment is already rejected elsewhere in this product.
- What happens when searching the customer dropdown for a term that matches nothing? The same "not found" state already shown today, plus the existing option to continue as a walk-in or add a new customer, must still be reachable from the single dropdown interaction.
- What happens to a pre-order created before this change (no discount, no pickup day, using the old "Courier" label)? It must continue to display correctly — zero discount, no pickup day shown (since it predates the concept), and its fulfillment shown as "Mail Order" if it was "Courier" (label-only change, not a data change).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The New pre-order form MUST let a staff member find and select an existing customer by typing part of their name or phone number into a single inline dropdown field, without opening a separate pop-up window.
- **FR-002**: The customer dropdown MUST still offer, from within the same interaction, the two options already available today: continuing without a customer ("walk-in"), and adding a brand-new customer.
- **FR-003**: The New pre-order form MUST let a staff member enter a discount amount that reduces the pre-order's total.
- **FR-004**: The system MUST reject a discount amount that would make the pre-order's total go below zero.
- **FR-005**: A pre-order's discount MUST be visible wherever its total is shown to staff or the customer, including its printed/downloaded invoice.
- **FR-006**: The New pre-order form MUST let a staff member set the quantity of each added item directly (typing a number), not only by incrementing/decrementing one at a time.
- **FR-007**: The fulfillment option previously labeled "Courier" MUST be relabeled "Mail Order" everywhere it appears to staff or customers (the form, lists, invoices, reports), with no functional change to what the option itself does.
- **FR-008**: When "Self Pickup" is selected and the pre-order is linked to an event, the form MUST offer one selectable pickup day per calendar day in that event's start-to-end date range, labeled by day number and real date (e.g. "Day 1 (12 Okt)"), and MUST require one to be chosen before the pre-order can be saved.
- **FR-008a**: When "Self Pickup" is selected and the pre-order has no linked event, the form MUST NOT require a pickup day (there is no date range to offer choices from) and MUST NOT show a meaningless generic picker in its place.
- **FR-009**: The chosen pickup day MUST appear on the pre-order's invoice as its real calendar date, not a bare "Day 1"/"Day 2" label with no date attached.
- **FR-009a**: If a pre-order's linked event changes (or its dates change) such that a previously chosen pickup day falls outside the event's date range, that pickup day MUST be cleared rather than silently retained.
- **FR-010**: When "Mail Order" is selected as the fulfillment option, the form MUST show a courier dropdown, defaulted to "JNE," which the staff member may change.
- **FR-011**: Switching a pre-order's fulfillment option between "Self Pickup" and "Mail Order" MUST clear whichever fulfillment-specific value (pickup day, or courier) no longer applies.
- **FR-012**: The bulk export of pre-orders MUST include the discount, pickup day, and courier values for each pre-order.
- **FR-013**: The bulk import of pre-orders MUST accept the discount, pickup day, and courier columns and apply them to the created pre-orders exactly as the form would.
- **FR-014**: The bulk import MUST reject, as a row-level error, any row where a fulfillment-specific value is supplied for the fulfillment option it does not apply to (e.g. a courier value on a "Self Pickup" row).

### Key Entities

- **Pre-order**: Adds a discount amount and (when self-pickup) a chosen pickup day to its existing fields (customer, items, fulfillment, status, totals).
- **Pre-order Shipment/Courier detail**: The courier chosen for a "Mail Order" pre-order — previously free-text, now chosen from a known list of couriers with "JNE" as the default.
- **Pre-order Item**: Its existing quantity becomes directly editable by typing a number, in addition to the existing increment/decrement control.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can attach an existing customer to a new pre-order in one interaction (type + select), down from the two separate steps (open pop-up, then search) required today.
- **SC-002**: 100% of pre-orders created with a discount show that discount consistently on both the transaction record and its printed invoice — never on only one of the two.
- **SC-003**: 100% of "Self Pickup" pre-orders that are linked to an event have a real, valid pickup day (one of that event's actual calendar dates) recorded, and that date is visible on every invoice printed for them.
- **SC-004**: A bulk-exported file of pre-orders, when immediately re-imported unchanged, recreates pre-orders with the same discount, pickup day, and courier values as the originals (round-trip integrity), matching the same guarantee already expected of every other import/export feature in this product.
- **SC-005**: Zero pre-orders can be saved, through the form or through import, with a total that is negative or with a fulfillment-specific value that contradicts the chosen fulfillment option.

## Assumptions

- **The customer field becomes a single inline searchable dropdown**, replacing today's two-step "button opens a pop-up, which has its own search box" pattern — the underlying search behavior (match by name or phone, offer walk-in, offer add-new-customer) stays the same; only the interaction collapses from two steps to one.
- **Discount is a single flat Rupiah amount applied to the whole pre-order** (not a percentage, and not set per line item) — this matches how every other discount-shaped field in this product (e.g. an invoice's discount) is already represented, as a fixed amount rather than a percentage. *(Resolved — Question 2, Option A.)*
- **Pickup day is derived from the linked event's real date range**, not a fixed generic "Day 1"/"Day 2" pair — see FR-008/FR-008a/FR-009/FR-009a. *(Resolved — Question 1, Option B.)*
- **"Courier" → "Mail Order" is a label-only change.** The underlying fulfillment option itself (and any previously saved pre-orders using it) is unaffected — only the text shown to people changes, everywhere it's shown.
- **The shipping courier list is a short, fixed set of couriers commonly used in this market** (e.g. JNE, J&T, SiCepat, Pos Indonesia, and an "Other" option for anything not listed), with JNE pre-selected by default. The exact list is a content detail, not a scope decision, and can be adjusted later without changing how the feature works.
- **Quantity direct-entry keeps today's existing minimum of 1 item** — a staff member cannot type 0 or a negative quantity; the existing increment/decrement control remains available alongside the new direct-entry option, not replaced by it.
- **Import/export changes extend the existing single pre-order import/export file format** (already separate from the store's combined master-data file, per this product's existing convention for pre-orders) rather than introducing a second, new file format. The pickup day column in that file is expressed as a real date (not a bare "Day 1"/"Day 2" label), consistent with FR-009's invoice treatment.

## Resolved Clarifications

- **Day 1 / Day 2 semantics** → Resolved: derived from the linked event's actual date range, one selectable day per calendar day in that range; not required (and not shown) when there is no linked event. See FR-008/FR-008a/FR-009/FR-009a and User Story 3's updated acceptance scenarios.
- **Discount type** → Resolved: fixed Rupiah amount, matching every other money field in this product (not a percentage). See FR-003/FR-004.
