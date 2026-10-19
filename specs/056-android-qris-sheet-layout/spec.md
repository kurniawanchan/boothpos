# Feature Specification: Android POS Phase 3 — Static QRIS Sheet Layout

**Feature Branch**: `056-android-qris-sheet-layout`

**Created**: 2026-10-18

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "update android app phase 3: change QRIS (Statis) to {Provider name} {Account holder name}; reduce the size of warning text; show the QRIS image in the middle and remove the text 'Standee QRIS statis di meja kasir'; change the layout of the total amount and the text 'Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi QRIS'; tidy up the layout of the 'Catat · menunggu verifikasi' button text." (screenshots: the tablet's static QRIS sheet, and the admin payment channel form showing Provider name "QRIS" and Account holder name "Warkop WJM 3")

## Context

On the Android POS counter, a cashier who takes a static QRIS payment opens the QRIS sheet (`ui/Sheets.kt`). The sheet shows the QRIS code the customer scans, the amount, and a button that records the payment as waiting for verification. The screenshot of the current sheet shows:

1. The title reads "QRIS (statis)". The cashier needs the provider and the account holder name to confirm which account the customer is paying into.
2. A red warning banner, "Jangan terima bukti dari layar ponsel pelanggan.", is set in a large text size that competes with the content.
3. The QR code sits to the left, with the explanatory text "Standee QRIS statis di meja kasir" beside it and the total amount and instruction text stacked in the right column. The QR code is not centred.
4. The total amount ("Rp 67.100") and the instruction "Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi QRIS" are cramped in the right column, and the instruction text is cut off at the bottom of the sheet.
5. The button "Catat · menunggu verifikasi" wraps and is not tidy beside its arrow icon.

The admin payment channel form (reference screenshot) stores the channel's Provider name ("QRIS") and Account holder name ("Warkop WJM 3") and the QR image. The tablet already receives these values for the channel.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The sheet names the provider and the account holder (Priority: P1)

The cashier sees the provider and the account holder name in the sheet title, so they can confirm the account the customer is paying into.

**Why this priority**: Paying into the wrong account is a money error. The name is the check the cashier needs before the customer scans.

**Independent Test**: With the channel set to Provider "QRIS" and Account holder "Warkop WJM 3", open the QRIS sheet and confirm the title reads "QRIS Warkop WJM 3" (not "QRIS (statis)").

**Acceptance Scenarios**:

1. **Given** the channel has provider "QRIS" and account holder "Warkop WJM 3", **When** the sheet opens, **Then** the title reads "QRIS Warkop WJM 3".
2. **Given** the account holder name is empty, **When** the sheet opens, **Then** the title shows the provider name only, and the sheet does not show an empty gap.
3. **Given** the name is long, **When** the sheet opens, **Then** the title shrinks or wraps within the sheet and is not cut.

---

### User Story 2 - The warning is readable but not dominant (Priority: P1)

The warning "Jangan terima bukti dari layar ponsel pelanggan." stays fully visible, at a smaller text size, so it reads as a notice and does not take over the sheet.

**Why this priority**: The warning matters (it prevents accepting a fake payment screen), so it must stay readable; it must not crowd the QR and the amount.

**Independent Test**: Open the sheet on the tablet and confirm the warning is fully readable and visibly smaller than the title.

**Acceptance Scenarios**:

1. **Given** the sheet is open, **When** it is shown, **Then** the warning text is smaller than the sheet title and the full sentence is visible.
2. **Given** the warning is smaller, **When** it is shown in landscape or portrait, **Then** it does not overlap the QR code or the amount.

---

### User Story 3 - The QR code is centred and the standee text is gone (Priority: P1)

The QRIS image is shown in the middle of the sheet, and the text "Standee QRIS statis di meja kasir" is removed.

**Why this priority**: The customer scans the code; a centred, unobstructed code is easier to scan and easier to show to the customer.

**Independent Test**: Open the sheet and confirm the QR image is horizontally centred, the words "Standee QRIS statis di meja kasir" do not appear, and the QR image is fully visible.

**Acceptance Scenarios**:

1. **Given** the sheet is open, **When** it is shown, **Then** the QR image is horizontally centred in the sheet.
2. **Given** the sheet is open, **When** it is shown, **Then** the text "Standee QRIS statis di meja kasir" does not appear anywhere on the sheet.
3. **Given** the channel has no QR image, **When** the sheet opens, **Then** the sheet shows a clear "QR belum diatur" message in the same centred position (the message is not the removed standee text).

---

### User Story 4 - The total and the instruction are laid out clearly (Priority: P1)

The total amount is shown as a clear block, and the instruction "Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi {provider}" is fully visible below it.

**Why this priority**: The amount is what the customer pays; the instruction tells the cashier what happens next. Both must be fully visible.

**Independent Test**: Open the sheet with a total of Rp 67.100 and confirm the amount is on its own line, and the whole instruction sentence is visible without cut-off.

**Acceptance Scenarios**:

1. **Given** the sheet is open, **When** it is shown, **Then** the total amount is shown on its own line and is the most prominent figure after the title.
2. **Given** the instruction sentence, **When** it is shown, **Then** the whole sentence is visible (it wraps onto as many lines as needed) and is not cut at the bottom of the sheet.
3. **Given** the total is large, **When** it is shown, **Then** it stays on one line and is not split across two lines.

---

### User Story 5 - The record button text is tidy (Priority: P2)

The button "Catat · menunggu verifikasi" shows its label on one line, with its arrow icon aligned beside it, and is not cropped.

**Why this priority**: A cropped or wrapped button label slows the cashier's confirmation. It is cosmetic, so it is P2.

**Independent Test**: Open the sheet and confirm the button label is on one line with the icon aligned, and the full label is readable.

**Acceptance Scenarios**:

1. **Given** the sheet is open, **When** it is shown, **Then** the button label "Catat · menunggu verifikasi" is on one line with the arrow icon aligned to it.
2. **Given** the label is long in another language setting, **When** the button is shown, **Then** the label shrinks or wraps inside the button without cropping.

---

### Edge Cases

- The provider or account holder name contains characters outside plain text: they are shown as entered.
- The channel is changed on the server while the sheet is open: the sheet shows the channel it was opened with until it is closed.
- The QR image fails to load: the "QR belum diatur" message appears in the centred position and recording is still allowed only if the server allows it.
- The amount is zero or missing: the sheet does not open a recording action.

## Requirements *(mandatory)*

### Functional Requirements

**Title**

- **FR-001**: The sheet title MUST read "{Provider name} {Account holder name}" from the static QRIS channel the tablet holds.
- **FR-002**: If the account holder name is empty, the title MUST show the provider name only.
- **FR-003**: The title MUST NOT be cut; it MUST shrink or wrap within the sheet.

**Warning**

- **FR-004**: The warning "Jangan terima bukti dari layar ponsel pelanggan." MUST be shown at a text size smaller than the sheet title, fully visible, and in the same wording.

**QR and standee text**

- **FR-005**: The QRIS image MUST be horizontally centred in the sheet.
- **FR-006**: The text "Standee QRIS statis di meja kasir" MUST NOT appear on the sheet.
- **FR-007**: When the channel has no QR image, the sheet MUST show "QR belum diatur" in the same centred position.

**Total and instruction**

- **FR-008**: The total amount MUST be shown on its own line, as the most prominent figure after the title, and MUST stay on one line.
- **FR-009**: The instruction "Catat sebagai menunggu verifikasi, lalu cek transaksi di aplikasi {provider}" MUST be fully visible, wrapping as needed, and MUST NOT be cut at the bottom of the sheet.

**Button**

- **FR-010**: The button label "Catat · menunggu verifikasi" MUST be on one line with its arrow icon aligned, or shrink/wrap inside the button without cropping.

**Behaviour unchanged**

- **FR-011**: Recording the payment, the amount, the verification flow, and the server rules for static QRIS MUST NOT change. This feature changes presentation only.

### Key Entities *(include if feature involves data)*

- **Static QRIS channel**: provider name, account holder name, QR image, and the active flag. Set by the admin on the payment channel form; read by the tablet.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, 100% of QRIS sheet views show the provider and account holder in the title.
- **SC-002**: The words "Standee QRIS statis di meja kasir" appear in 0 of all QRIS sheet views.
- **SC-003**: The QR image is centred in 100% of views in landscape and portrait, verified by side-by-side screenshot.
- **SC-004**: The full instruction sentence and the button label are fully visible in 100% of views, with no cut-off text.
- **SC-005**: Recording a static QRIS payment works the same before and after the change in 100% of test payments (presentation only).

## Assumptions

- **ASSUMPTION**: The title format is provider name, then a space, then account holder name, with no separator, as requested ("{Provider name} {Account holder name}").
- **ASSUMPTION**: "Reduce the size of warning text" means the warning becomes smaller than the title; the exact size is chosen on the tablet and must keep the sentence fully visible.
- **ASSUMPTION**: "Show QRIS image in the middle" means horizontally centred within the sheet; the total and instruction move below it.
- **ASSUMPTION**: "Change the layout of total amount and text" means the total is its own line, and the instruction is fully visible below; the exact arrangement is a design choice confirmed on the tablet.
- **ASSUMPTION**: The admin payment channel form (provider name, account holder name, QR image) is the source of truth; this feature does not change that form.
- **ASSUMPTION**: The static QRIS flow, the amount, and the server rules are unchanged (FR-011).

## Out of Scope

- Changes to the admin payment channel form.
- Changes to the QRIS verification flow, the pending-payment rules, or the EDC flow.
- Changes to other payment sheets (cash, EDC) or the counter layout.

## Amendment (2026-10-18): two-column sheet and saving the QR image from the sheet

**Layout.** The title and the warning span the full width of the sheet. Below them two columns:

- **Column 1:** the full QRIS image (whole image, not cropped), with a "Ganti gambar QR" control.
- **Column 2:** the total amount, the instruction, and the buttons (Tutup, Catat · menunggu verifikasi).

**Saving the QR image from this sheet.** A cashier or manager with the right can replace the channel's QR image from the sheet. The image is chosen from the device gallery and must be JPEG or PNG. It is saved to the server, which keeps the image on the channel (the same rule as the admin payment channel form). The control appears only when the server says the signed-in user may manage the channel (`can_manage_qr`, true for users with the settings menu). The server enforces the same rule on the save.

**Assumptions**
- **ASSUMPTION**: only users with the settings menu (owner/admin) can replace the QR. The cashier does not see the control. This follows the server's existing rule for payment channels; confirm with the product owner if cashiers should be able to replace it.
- **ASSUMPTION**: removing a QR image from the tablet is out of scope (only replacing is requested).

**New requirements**
- **FR-012**: the sheet MUST show the full QR image in column 1 without cropping.
- **FR-013**: the replace control MUST appear only when `can_manage_qr` is true; other users MUST NOT see it.
- **FR-014**: a replaced image MUST be saved to the server and shown on the sheet after the save; a refused save MUST show the server's message and keep the previous image.
- **FR-015**: only JPEG and PNG images MUST be sent; any other type MUST be refused on the tablet with a message.
