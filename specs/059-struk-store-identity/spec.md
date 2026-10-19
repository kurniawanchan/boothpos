# Feature Specification: Store Identity on the Printed Struk

**Feature Branch**: `059-struk-store-identity`

**Created**: 2026-10-18

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "update the struk to add this store information (logo, store name, address, contact)" (screenshot: the admin Store identity section with a store logo, "Store name (printed on receipts)", "Full address", and "Store contact")

## Context

The admin sets the store's identity once in Settings → Store identity: the logo, the store name printed on receipts, the full address, and the contact (person, phone, email). The server already returns all of these in the receipt it sends the tablet.

The struk that the Android POS prints (58 mm thermal printer) shows only the store name and the address. The contact and the logo are missing, so a customer gets a receipt with no way to reach the shop.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The struk shows the store contact (Priority: P1)

Every printed struk shows the store's contact (person, phone, and email when set) under the address, so the customer can reach the shop.

**Why this priority**: A receipt is the customer's record of the purchase and the shop's contact point. Missing contact details are a visible gap on every sale.

**Independent Test**: Set a contact person, phone, and email in Store identity; complete a sale; print the struk. Confirm the contact lines appear under the address.

**Acceptance Scenarios**:

1. **Given** the store has a contact person, phone, and email, **When** a struk prints, **Then** the contact lines are printed under the address.
2. **Given** the store has only a phone number, **When** a struk prints, **Then** only the phone line is printed; no empty lines or "null" text appear.
3. **Given** no contact is set, **When** a struk prints, **Then** no contact lines are printed and the layout is unchanged from today.

---

### User Story 2 - The struk shows the store logo (Priority: P2)

When the admin's "show logo on receipt" setting is on and a logo is set, the struk prints the logo above the store name.

**Why this priority**: The logo makes the struk recognisable, but the text is more important, so the logo is P2.

**Independent Test**: Upload a logo in Store identity and turn on "show logo on receipt"; print a struk and confirm the logo appears above the store name, readable at 58 mm.

**Acceptance Scenarios**:

1. **Given** a logo is set and the receipt-logo setting is on, **When** a struk prints, **Then** the logo prints above the store name.
2. **Given** the receipt-logo setting is off, **When** a struk prints, **Then** no logo prints.
3. **Given** no logo is set, **When** a struk prints, **Then** the struk prints the store name without a logo and without an error.
4. **Given** the logo cannot be loaded, **When** a struk prints, **Then** the struk still prints with the text header.

---

### User Story 3 - The store name and address match the admin settings (Priority: P1)

The store name and address on the struk are the values set in Store identity for the active system mode (DEMO or LIVE).

**Why this priority**: A struk with the wrong name or address is a correctness problem for the shop.

**Independent Test**: Change the store name in Store identity; print a new struk; confirm the new name prints.

**Acceptance Scenarios**:

1. **Given** the admin changes the store name, **When** the next struk prints, **Then** the new name is printed.
2. **Given** the system mode is DEMO, **When** a struk prints, **Then** the DEMO store name is printed.

---

### Edge Cases

- The address is long: it wraps onto several lines within 58 mm and is not cut.
- The contact email is long: it wraps and is not cut.
- The logo is wider than the paper: it is scaled to fit 58 mm.
- The printer is offline when the struk is queued: the struk and its identity lines print when the printer is back.
- A reprint: the reprint shows the same store identity as the original.

## Requirements *(mandatory)*

### Functional Requirements

**Contact (US1)**

- **FR-001**: The struk MUST print the store contact person, phone, and email under the address when set.
- **FR-002**: Unset contact lines MUST NOT print, and no empty lines or the word "null" MUST appear.

**Logo (US2)**

- **FR-003**: When the receipt-logo setting is on and a logo is set, the struk MUST print the logo above the store name, scaled to the paper width.
- **FR-004**: When the receipt-logo setting is off or no logo is set, no logo MUST print.
- **FR-005**: A logo that cannot be loaded or printed MUST NOT stop the struk; the text header MUST still print.

**Name and address (US3)**

- **FR-006**: The struk MUST print the store name and full address set in Store identity for the active system mode.
- **FR-007**: Reprints MUST show the same store identity as the original struk.

**Behaviour unchanged**

- **FR-008**: Sale amounts, payment lines, and the queue and print rules MUST NOT change.

### Key Entities *(include if feature involves data)*

- **Store identity**: store name, full address, contact person, contact phone, contact email, logo, and the receipt-logo setting. Set by the admin in Settings; read by the struk.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot printer, 100% of struk prints include the contact lines when the contact is set.
- **SC-002**: 100% of struk prints show the store name and address that the admin set for the active mode.
- **SC-003**: 0 struk prints show the word "null" or an empty contact line.
- **SC-004**: With the printer offline, the struk waits and then prints with its identity lines in 100% of tests.

## Assumptions

- **ASSUMPTION**: The contact lines are printed as plain text under the address, in the same style as the address.
- **ASSUMPTION**: The logo is printed in black and white, scaled to 58 mm, only when the admin's receipt-logo setting is on (the same setting the server uses).
- **ASSUMPTION**: The struk layout is otherwise unchanged; no new sections are added.
- **ASSUMPTION**: The server already returns the store identity on the receipt (verified: `OrderController::receipt` returns the store name, address, contact person, phone, email, and logo URL). No server change is planned.

## Out of Scope

- Changes to the admin Store identity form.
- Changes to the web receipt or the POS sales receipt on screen.
- New receipt designs beyond the identity lines.

## Clarifications

### Session 2026-10-18

- Q: Should the logo print on the 58 mm thermal struk? → A: Yes. Print the logo as a black-and-white image above the store name when the admin's receipt-logo setting is on. (Each struk takes a little longer to print.)
