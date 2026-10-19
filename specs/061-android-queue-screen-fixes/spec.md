# Feature Specification: Android Payment-Done Screen: Queue Number Fixes and Call Sound

**Feature Branch**: `061-android-queue-screen-fixes`
**Created**: 2026-10-18
**Status**: Draft
**Input**: User description: "update app android screen IMG_2200.heic: - fix the cropped number - move the green checklist and "Lunas" text to be in one line - when user tap panggil pelanggan, the app will make sound the queue number"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read the queue number in full (Priority: P1)

After a sale is paid, the cashier and customer read the queue number on the large "Panggil pelanggan" card. Today the number is cut off at its edge on the tablet, so the last digit or character is not visible.

**Why this priority**: the queue number is how the customer is called to collect the order; a cut number is a wrong number.

**Independent Test**: complete a paid sale on the pilot tablet and check that the whole queue number (for example A-012) is visible inside its card.

**Acceptance Scenarios**:

1. **Given** the payment-done screen with a queue number of up to 6 characters (for example `A-012`), **When** it is shown in landscape on the pilot tablet, **Then** every character is fully visible inside the card, with no cut edge.
2. **Given** a longer queue number, **When** it does not fit at the largest size, **Then** the number shrinks to fit the card instead of being cut off.

---

### User Story 2 - Paid status on one line (Priority: P2)

The green checkmark and the "Lunas · T03-000008" badge are currently shown on separate rows. They must sit together on one line, so the paid status reads at a glance.

**Why this priority**: a layout fix that improves reading speed; it does not change any money or status logic.

**Independent Test**: check the payment-done screen with a paid order and confirm the checkmark and the badge share one row.

**Acceptance Scenarios**:

1. **Given** a paid order, **When** the payment-done screen is shown, **Then** the green checkmark and the "Lunas" badge (with the transaction number) appear side by side on one line.
2. **Given** a long transaction number, **When** it does not fit beside the checkmark, **Then** the badge text shrinks to fit on the same line rather than wrapping below the checkmark.

---

### User Story 3 - Hear the queue number when calling the customer (Priority: P1)

When the cashier taps "Panggil pelanggan", the tablet plays a sound that announces the queue number, so the cashier can call the customer without looking at the screen.

**Why this priority**: this is the new call-out behaviour the shop asked for; it is the reason the queue number is shown.

**Independent Test**: tap "Panggil pelanggan" on a payment-done screen with queue number A-012 and confirm the tablet announces the number.

**Acceptance Scenarios**:

1. **Given** a payment-done screen with queue number `A-012`, **When** the cashier taps "Panggil pelanggan", **Then** the tablet plays the number aloud in Indonesian (for example "A, nol satu dua").
2. **Given** the tablet is muted or the sound cannot play, **When** the cashier taps "Panggil pelanggan", **Then** the app still shows the call as done and does not block the next sale.
3. **Given** the cashier taps "Panggil pelanggan" twice quickly, **When** the first announcement is still playing, **Then** the number is not announced twice at the same time.

---

### Edge Cases

- A queue number with a letter prefix and dash (`A-012`) must read naturally aloud (letters spelled, digits read one by one).
- A screen with no queue number (queue not issued) must not show an empty call card or play a sound.
- The sound must play on the tablet speaker at the device's media volume; it must not change the device's volume setting.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The queue number on the payment-done screen MUST be displayed in full, without any character cut at the card edge, on the pilot tablet in landscape.
- **FR-002**: When a queue number does not fit at the largest size, its text MUST shrink to fit the card.
- **FR-003**: The green checkmark and the "Lunas" badge with the transaction number MUST be shown on one line on the payment-done screen.
- **FR-004**: The badge text MUST shrink to fit beside the checkmark instead of wrapping onto a second row.
- **FR-005**: Tapping "Panggil pelanggan" MUST play an Indonesian spoken announcement of the queue number shown on that screen.
- **FR-006**: The announcement MUST read letters and digits clearly (letters spelled out, digits read one by one, for example "A-012" → "A, nol, satu, dua").
- **FR-007**: If the sound cannot play, the call MUST still be recorded as done and the cashier MUST NOT be blocked from continuing.
- **FR-008**: A repeated tap while an announcement is playing MUST NOT start a second overlapping announcement.
- **FR-009**: The announcement MUST NOT run when the screen has no queue number.
- **FR-010**: The sale, payment, stock, and receipt logic MUST NOT change; this feature changes presentation and call-out only.

### Key Entities

- **Queue number**: the short code (letter prefix, dash, digits) shown on the payment-done screen and used to call the customer.
- **Paid status badge**: the green "Lunas" label that carries the transaction number.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, 100% of queue numbers up to 6 characters are fully visible on the payment-done screen in the checked cases.
- **SC-002**: The checkmark and the "Lunas" badge share one row for 100% of checked paid orders, including a long transaction number.
- **SC-003**: Tapping "Panggil pelanggan" produces an audible announcement of the queue number within 1 second in 95% of taps on the pilot tablet.
- **SC-004**: A sale can still be completed on the next order when the announcement fails (muted or no audio output), in 100% of checked cases.
- **SC-005**: The Android unit test suite passes with no failures after the change.

## Assumptions

- "Panggil pelanggan" and "Lunas" are the labels already on the screen; the wording of the labels does not change.
- "Make sound the queue number" means a spoken Indonesian announcement of the number. A plain chime is not enough; if the shop wants a chime before the spoken number, that is a follow-up.
- The spoken voice is the device's own Indonesian text-to-speech. If the tablet has no Indonesian voice, the app falls back to the device's default voice and the feature still counts as working.
- The "cropped number" is the large queue number on the right-hand card of the screenshot. If another number is meant, the user should point it out before planning.
- The app is the Android POS (`android-pos/`); the web app is not changed.
- No server or API change is needed; the queue number and the paid status already come from the server.
