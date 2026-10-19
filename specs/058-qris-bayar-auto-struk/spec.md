# Feature Specification: QRIS Payment Button "Bayar" with Automatic Struk

**Feature Branch**: `058-qris-bayar-auto-struk`

**Created**: 2026-10-18

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "in QRIS payment page, update label 'Catat menunggu verifikasi' to 'Bayar'. after user tap button 'Bayar' the struk will be printed automatically." (screenshot IMG_2202: the static QRIS sheet, title "QRIS Warkop WJM 3", amount Rp 13.200, button "Catat · menunggu verifi…" cut off)

## Context

On the Android POS counter, a cashier takes a static QRIS payment through the QRIS sheet. The sheet's button records the payment as waiting for verification. The screenshot shows the button label cut off, and the cashier wants the button to say "Bayar" and the struk (receipt) to print as soon as the payment is taken, without a separate print step.

Today the struk is printed only when the cashier chooses to print it. Cash and card payments already print the struk when they close; QRIS does not.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The QRIS button says "Bayar" (Priority: P1)

The button on the QRIS sheet reads "Bayar" in full, with no cut-off text and no extra words.

**Why this priority**: It is the main action on the sheet; a cropped label makes the cashier hesitate.

**Independent Test**: Open the QRIS sheet with any amount and confirm the button reads "Bayar" in full.

**Acceptance Scenarios**:

1. **Given** the QRIS sheet is open, **When** it is shown, **Then** the action button reads "Bayar" and no part of the label is cut off.
2. **Given** the amount is zero or the order is not payable, **When** the sheet is shown, **Then** the button is disabled, as it is today.

---

### User Story 2 - Tapping "Bayar" takes the payment and prints the struk (Priority: P1)

When the cashier taps "Bayar", the QRIS payment is recorded for the order and the struk for that order is sent to the printer automatically. The cashier does not have to tap a print button.

**Why this priority**: The automatic struk saves a step on every QRIS sale and matches what cash payments already do.

**Independent Test**: Take a QRIS payment of Rp 13.200 on the pilot tablet with the printer connected; confirm the payment is recorded, and one struk comes out of the printer without any further tap.

**Acceptance Scenarios**:

1. **Given** the QRIS sheet shows Rp 13.200, **When** the cashier taps "Bayar", **Then** the payment is recorded for the order and one struk is sent to the printer.
2. **Given** the printer is not connected, **When** the cashier taps "Bayar", **Then** the payment is still recorded, the struk waits in the print queue, and the cashier sees that it is waiting to print.
3. **Given** the network is lost after the cashier taps "Bayar", **When** the request cannot reach the server, **Then** the payment is not recorded twice when the cashier taps again later (same as today).
4. **Given** the payment is recorded, **When** the struk prints, **Then** it shows the QRIS payment and the amount paid.

---

### User Story 3 - The payment still needs verification (Priority: P2)

A QRIS payment taken with "Bayar" is still recorded as waiting for verification, so the cashier checks it later in the QRIS app, as before.

**Why this priority**: The verification rule protects against fake payment screens (the sheet warns about it). The label changes, the rule does not.

**Independent Test**: Take a QRIS payment with "Bayar"; confirm the sale shows "Menunggu verifikasi" until the cashier verifies it.

**Acceptance Scenarios**:

1. **Given** a payment taken with "Bayar", **When** the sale is listed, **Then** its status is still "Menunggu verifikasi".
2. **Given** the cashier verifies the payment later, **When** the verify action is used, **Then** the status changes as it does today.

---

### Edge Cases

- The printer is busy or out of paper: the struk stays in the queue and prints when the printer recovers; it is not lost.
- The struk print fails: the payment stays recorded; the cashier is told the struk failed and can print it again from the order.
- The cashier taps "Bayar" twice quickly: only one payment and one struk are created.
- The channel is not set up on the server: the sheet tells the cashier and nothing is recorded or printed (as today).

## Requirements *(mandatory)*

### Functional Requirements

**Label**

- **FR-001**: The QRIS sheet's action button MUST read "Bayar" in full, with no cut-off text.
- **FR-002**: The button label MUST NOT include the words "menunggu verifikasi" or any amount.

**Payment**

- **FR-003**: Tapping "Bayar" MUST record the QRIS payment for the order with the remaining amount, as the button does today.
- **FR-004**: The recorded QRIS payment MUST keep the status "Menunggu verifikasi" until verified.
- **FR-005**: Repeated taps MUST NOT create a second payment for the same amount (existing idempotency rule).

**Struk**

- **FR-006**: After the payment is recorded, the struk for that order MUST be sent to the printer automatically, once.
- **FR-007**: If the printer is not available, the struk MUST wait in the print queue and the cashier MUST see that it is waiting.
- **FR-008**: A failed struk print MUST NOT undo the recorded payment; the cashier MUST be able to print the struk again from the order.

**Scope**

- **FR-009**: The payment rules, the verification rules, and the other payment methods (cash, EDC) MUST NOT change.

### Key Entities *(include if feature involves data)*

- **QRIS payment**: amount, method (QRIS), status (waiting for verification until verified), channel, reference.
- **Struk**: the receipt for an order, queued and printed once per payment.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, the QRIS button reads "Bayar" in full in 100% of views, in both orientations.
- **SC-002**: After a QRIS "Bayar" with the printer connected, exactly one struk prints without any further tap, in 100% of test payments.
- **SC-003**: With the printer disconnected, the struk waits in the queue in 100% of test payments, and prints after reconnecting.
- **SC-004**: A QRIS payment taken with "Bayar" still shows "Menunggu verifikasi" until verified, in 100% of test payments.

## Assumptions

- **ASSUMPTION**: "Bayar" keeps today's behaviour: the payment is recorded as waiting for verification. The cashier still checks the QRIS app later. If the product owner wants "Bayar" to mark the payment as verified, that is a rule change and needs its own spec.
- **ASSUMPTION**: the struk is the same receipt the cashier can already print from the order (no new document).
- **ASSUMPTION**: the automatic print uses the existing print queue and printer, so an offline printer does not block the payment.
- **ASSUMPTION**: the button label "Bayar" replaces "Catat · menunggu verifikasi"; the instruction text above the button is out of scope unless the product owner asks for it.

## Out of Scope

- Changing the QRIS verification flow or rules.
- Changing the static QRIS channel or its image.
- Changing the cash or EDC payment sheets.
- Adding a new receipt design.
