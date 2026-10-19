# Feature Specification: Android POS Phase 4 — Payment, Order and Shift Fixes, and Where Data Is Saved

**Feature Branch**: `057-android-pos-phase-4-fixes`

**Created**: 2026-10-18

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "update android app phase 4: fix the layout of the left column (IMG_2179); fix the layout of the Verify button, move it to the next row as a long button (IMG_2181); where is the 'Referensi transaksi' saved (IMG_2181); where is the discount ('Potongan') saved (IMG_2183); where is 'Batalkan order' saved (IMG_2184); what is the error 'The copy no field is required' when tapping 'Cetak ulang' (IMG_2185); where is the refund saved (IMG_2186); why is there 'null' (IMG_2187); fix the Refund button, the third button shows no label (IMG_2187); where are 'Kas masuk' and 'Kas keluar' saved (IMG_2188, IMG_2189); does all the data on the shift screen come from the database (IMG_2190); fix the 'Tandai habis' reason that does not show and say where that data is saved (IMG_2191); when the device goes offline and back online it shows 'Unauthenticated' and the button does nothing, why (IMG_2192)."

## Context

Phase 3 (`056-android-qris-sheet-layout`) finished the QRIS sheet. Phase 4 covers the rest of the Android POS counter and the shift screens, and answers the cashier's questions about where each action is stored. Each screenshot below is a tablet screen.

### Answers to the questions (verified in the code on 2026-10-18)

| Question | Where it is saved | Notes |
|---|---|---|
| **Referensi transaksi** (IMG_2181) | `payments.reference` of the QRIS payment row, when the cashier verifies it (`POST /tabs/{id}/payments/{pay}/verify`, `PaymentService::verifyWithApproval`). The reference must be at least 4 characters. | Also recorded in the activity log. The tablet's error about references is shown when it is shorter. |
| **Potongan / discount** (IMG_2183) | The discount amount is saved on the order item (`order_items.discount_amount`, `line_total`) and on the order (`orders.discount_amount`) by `DiscountService`. The **percentage and the reason code are saved only in the activity log** (audit trail), not on the order. | Gap: the order row does not hold the percentage or the reason. Shown on the receipt as an amount. |
| **Batalkan order** (IMG_2184) | Tab cancel (`POST /tabs/{id}/cancel`, `TabService::cancel`) records `cancelled_at` and `cancelled_by` on the kitchen items it cancels; the void path (`TabService::void`, `OrderService::void`) sets `orders.status = voided` and `orders.void_reason`. The reason code is also in the activity log. | Items already sent to the kitchen are marked as void, not deleted. |
| **Cetak ulang** error "The copy no field is required" (IMG_2185) | Not a stored value: the request is refused. See the Reprint story below. | Root cause found (see US4). |
| **Refund** (IMG_2186) | `orders.refunded_at`, `refunded_by`, `refund_reason_code`, `refund_business_date` (`RefundService`). Stock comes back as `return` movements; the cash refund is written as a shift cash movement. | The order stays `completed` and is marked refunded; it is not deleted. |
| **"null"** (IMG_2187) | The closed-order list has no closing time from the server; the tablet prints the missing value as "null". | Root cause found (see US6). |
| **Kas masuk / Kas keluar** (IMG_2188, IMG_2189) | Table `cash_movements`: session, direction (in or out), amount, reason code, reason note, timestamp (`CashMovementService::record`, `POST /sessions/{id}/cash-movements`). | Each request carries an idempotency key, so a retry does not add a second movement. |
| **Shift screen data** (IMG_2190) | Yes, from the database: the shift figures come from the server's X-report (`GET /sessions/{id}/x-report`, `ShiftReportService::figures`). Sales, cash net, transfer, QRIS (verified), cash in and out, and voids are computed by the server from the stored orders, payments and cash movements. The tablet does not compute them. | The blind-count rule applies: the expected cash is not sent to the counter screen. |
| **"Tandai habis" reasons** (IMG_2191) | The reasons are the `reason_codes` rows of category `sold_out` (4 active rows in the dev database). The sold-out marks themselves are stored in `item_availability_marks` (product, reason, outlet, and expiry at the end of the business day), written by `AvailabilityService`. | The tablet shows "Memuat alasan..." forever: see US7 for the likely cause. |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Left column of the payment screen is readable (Priority: P1)

On the payment screen (IMG_2179), the QRIS block in the left column shows its title and the "Menunggu verifikasi" status on one readable line, and the amount beside it. The word "Verifikasi" must not be broken into one letter per line.

**Why this priority**: Payment status is what the cashier reads before taking money. A broken column looks like an error.

**Independent Test**: Open the payment screen with a pending QRIS payment of Rp 13.200 on the pilot tablet (landscape). Confirm "QRIS", "Menunggu verifikasi" and the amount each sit on readable lines, and the verify action is reachable.

**Acceptance Scenarios**:

1. **Given** a pending QRIS payment, **When** the payment screen is shown, **Then** the word "Verifikasi" is not split across lines and the status and amount are on readable lines.
2. **Given** a long amount, **When** it is shown, **Then** it wraps or shrinks inside the column and is not cut.
3. **Given** the left column is narrow, **When** the screen is shown, **Then** the payment block gets its own readable width without pushing the payment buttons out of reach.

---

### User Story 2 - Verify button is a long button on its own row (Priority: P1)

In the "Verifikasi QRIS" sheet (IMG_2181), the Verify button moves to its own row below Tutup and Tidak ditemukan, and is a long button across the sheet. Its full label is visible.

**Why this priority**: The cashier must confirm a QRIS payment. A cropped "Veri..." button is hard to tap and read.

**Independent Test**: Open the sheet with a reference of 4 or more characters; confirm the Verifikasi button is on its own row, spans the sheet width, and reads "Verifikasi" in full.

**Acceptance Scenarios**:

1. **Given** the sheet is open, **When** it is shown, **Then** Verifikasi is on a row of its own and is full width.
2. **Given** the reference has fewer than 4 characters, **When** the sheet is shown, **Then** Verifikasi is shown disabled and Tutup and Tidak ditemukan stay usable.

---

### User Story 3 - Reprint works (Priority: P1)

Tapping "Cetak ulang" on a completed order (IMG_2185) prints the next copy. The cashier no longer sees "The copy no field is required".

**Why this priority**: Reprinting receipts is a daily task; the error blocks it.

**Independent Test**: Open a completed order with no reprints, tap "Cetak ulang"; confirm the copy prints and the counter shows "cetak ulang 1×".

**Acceptance Scenarios**:

1. **Given** a completed order with no reprints, **When** the cashier taps "Cetak ulang", **Then** the server accepts copy 2 and the copy is printed.
2. **Given** the order already has a reprint, **When** the cashier taps again, **Then** the next copy number is requested and the counter increases.
3. **Given** a reprint beyond the free copy needs approval, **When** the cashier taps, **Then** the approval sheet appears (existing rule), not an error.

---

### User Story 4 - Refund button shows its label (Priority: P1)

On the order detail (IMG_2187), the third button (Refund) shows its label in full, next to "Lihat struk" and "Cetak ulang", instead of an empty outline.

**Why this priority**: An unlabeled button cannot be used safely; refund is a money action.

**Independent Test**: Open a paid completed order on the pilot tablet; confirm the Refund button reads "Refund" in full.

**Acceptance Scenarios**:

1. **Given** a paid completed order, **When** the detail is shown, **Then** the Refund button shows the word "Refund".
2. **Given** the tablet is in landscape or portrait, **When** the buttons are shown, **Then** all three labels are readable and none is cropped.

---

### User Story 5 - Closed order shows a real closing time (Priority: P1)

The closed-order detail (IMG_2187) shows the time the order closed, not "null". Where the server does not know the time, the screen says nothing rather than printing a null value.

**Why this priority**: "null" on a receipt list looks like a broken system and hides the closing time the cashier needs for the shift check.

**Independent Test**: Open a completed order in the "Selesai" list; confirm the line reads "ditutup HH:MM" with the real time.

**Acceptance Scenarios**:

1. **Given** a completed order, **When** it is listed, **Then** its closing time is shown as HH:MM.
2. **Given** the server gives no closing time, **When** the order is listed, **Then** the closing time part is left out (no "null").

---

### User Story 6 - Tandai habis shows its reasons (Priority: P1)

Tapping "Tandai habis" on a product (IMG_2191) shows the list of reasons (for example Habis, Rusak) within a moment. If the list cannot load, the dialog says so and offers Coba lagi, instead of "Memuat alasan..." forever.

**Why this priority**: A product cannot be marked sold out without a reason, and the cashier is stuck.

**Independent Test**: Open "Tandai habis" on a product with the tablet online; confirm reasons appear. Then with the session expired, confirm a message with Coba lagi appears.

**Acceptance Scenarios**:

1. **Given** the tablet is online, **When** "Tandai habis" opens, **Then** the active sold-out reasons appear and one can be chosen.
2. **Given** the reasons cannot be loaded, **When** the dialog is open, **Then** it shows the reason for the failure and Coba lagi, and does not spin forever.

---

### User Story 7 - Recover after the device goes offline and back online (Priority: P1)

When the tablet loses the network and gets it back, the sign-in screen (IMG_2192) recovers: the "Unauthenticated" message clears, and Coba lagi works.

**Why this priority**: Wi-Fi drops happen at events. A tablet that stays stuck until restart blocks the shift.

**Independent Test**: Sign in, put the tablet offline for a while, turn Wi-Fi back on, press Coba lagi. Confirm the staff list loads or a clear message says a PIN sign-in is needed.

**Acceptance Scenarios**:

1. **Given** the session has expired while offline, **When** the network returns and Coba lagi is pressed, **Then** the tablet does not repeat the same expired request; it either loads the staff list or asks for sign-in again.
2. **Given** the tablet is back online, **When** the screen loads, **Then** the message "Unauthenticated." is not shown without a way out.
3. **Given** the message is shown, **When** Coba lagi is pressed, **Then** something visible happens (loading, then a result).

---

### User Story 8 - Discount percentage and reason are stored on the order (Priority: P2)

When a cashier applies a discount (IMG_2183), the order keeps the percentage, the scope (whole order or one line), and the reason, so the receipt and the order detail show them and reports can use them.

**Why this priority**: The amount alone cannot be explained at audit. The product owner decided the reason and percentage belong on the order.

**Independent Test**: Apply a 11 % discount with reason "Promotion" to an order; confirm the order detail and the receipt show 11 % and "Promotion", and that the stored values survive a reload.

**Acceptance Scenarios**:

1. **Given** an order discount of 11 % with reason "Promotion", **When** it is applied, **Then** the order stores 11 %, scope "order", and reason "Promotion".
2. **Given** a line discount, **When** it is applied, **Then** the line stores its percentage and reason.
3. **Given** an order discounted before this change, **When** it is shown, **Then** only the amount is shown.

---

### Edge Cases

- A reprint request sent twice (double tap) prints one copy only (idempotency key).
- A refund of an order that has a pending QRIS payment is refused, with the existing message.
- The verify reference is shorter than 4 characters: the button stays disabled.
- A discount is applied and then the order is refunded: the refund returns the discounted amount (existing rule).
- The sold-out reasons list is empty on the server: the dialog says there are no reasons to choose from.

## Requirements *(mandatory)*

### Functional Requirements

**Payment screen (US1, US2)**

- **FR-001**: The QRIS block in the left column MUST show the title, status, and amount on readable lines; no word MAY be split one letter per line.
- **FR-002**: The Verifikasi button MUST sit on its own row and span the sheet width, with its full label visible.
- **FR-003**: Verifikasi MUST stay disabled until the reference has at least 4 characters (existing rule, unchanged).

**Reprint (US3)**

- **FR-004**: Tapping "Cetak ulang" MUST request the next copy number from the server, and the request MUST carry that number.
- **FR-005**: A double tap MUST NOT print two copies.

**Order detail (US4, US5)**

- **FR-006**: The Refund button MUST show the label "Refund" in full.
- **FR-007**: The closed-order time MUST show HH:MM from the server; if the server gives no time, no time text is shown.

**Sold-out reasons (US6)**

- **FR-008**: The "Tandai habis" dialog MUST show the sold-out reasons from the server, or a failure message with Coba lagi.
- **FR-009**: The dialog MUST NOT stay in "Memuat alasan..." after the request has finished.

**Reconnect (US7)**

- **FR-010**: After the network returns, the tablet MUST NOT keep reusing an expired session for the staff list.
- **FR-011**: The "Unauthenticated." message MUST be shown with a way forward (sign in again), and Coba lagi MUST produce a visible result.

**Data (answers, no change)**

- **FR-012**: Every discount applied to an order MUST store its percentage, scope (order or line), and reason code on the order (not only in the activity log). The receipt and the order detail MUST show the stored percentage and reason.
- **FR-013**: Orders discounted before this change have no stored percentage or reason; they MUST show the amount only, with no invented percentage.

### Key Entities *(include if feature involves data)*

- **Payment** (`payments`): amount, method, verification state, reference.
- **Order** (`orders`): status, void reason, discount amount, refund fields, closing time.
- **Cash movement** (`cash_movements`): session, direction, amount, reason, note.
- **Sold-out mark** (`item_availability_marks`): product, reason, outlet, expiry.
- **Reason code** (`reason_codes`): category, label, active flag.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, the payment block and the Verifikasi button read fully in 100% of checks.
- **SC-002**: "Cetak ulang" succeeds on the first tap in 100% of checks on a completed order, with no "copy no" error.
- **SC-003**: "Tandai habis" shows its reasons, or a message with Coba lagi, within 5 seconds in 100% of checks.
- **SC-004**: After an offline period, Coba lagi gives a visible result in 100% of checks, with no "Unauthenticated." dead end.
- **SC-005**: No closed order shows the word "null" in the list or the detail.

## Assumptions

- **ASSUMPTION**: The "null" in IMG_2187 is the closing time of the closed-order list, which the server does not send. The fix shows the time the server gives, or nothing.
- **DECIDED (product owner, 2026-10-18)**: the discount percentage and reason are stored on the order (FR-012, US8). Earlier discounts are not back-filled (FR-013).
- **DECIDED (product owner, 2026-10-18)**: the reconnect failure is caused by the PIN session token (12 hours, `PinAuthService::TOKEN_HOURS`) expiring while the tablet is offline, after which the tablet keeps sending it. Accepted as the basis for the plan; the device check still confirms it before the fix is accepted.
- **ASSUMPTION**: The empty "Tandai habis" dialog comes from the same expired session. The reason rows exist in the database (4 active). Same device check applies.
- **ASSUMPTION**: The Refund button's missing label is a width problem in the order detail row. To be confirmed on the device.
- **ASSUMPTION**: The shift figures on IMG_2190 are correct server values; the tablet shows them as received.

## Out of Scope

- Changing the refund, void, or QRIS rules.
- Changes to the server's payment, cash or shift rules.
