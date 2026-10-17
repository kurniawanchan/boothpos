# Feature Specification: Thermal Printer for the Android POS

**Feature Branch**: `050-android-thermal-printing`

**Created**: 2026-10-16

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "setup the android app to use printer with this type: VSC Desktop thermal printer, Model: VSC TM-58V, Paper width: 58mm. When connected, if user print from the app, it will automatically printed to this printer. Send order to kitchen will also automatically print to this printer. Add print button to needed place."

## Context

The Android POS tablet (`android-pos/`, features 047–048) currently has no way to print. The kitchen action "Kirim ke dapur" shows "Cetak KOT belum tersedia" ("KOT printing not yet available"), and no receipt can be printed from the tablet. The shop uses a **VSC TM-58V** desktop thermal printer with **58 mm** paper, connected to the tablet over **Bluetooth**. This feature pairs that printer with the tablet and makes every print action in the app go to it without a system print dialog.

### Terms used in this spec

| Term | Meaning |
|---|---|
| **KOT** (Kitchen Order Ticket) | A paper slip printed when items are sent to the kitchen or bar. It lists the table or order, the time, and the items to cook or make, with modifiers and notes. It has no prices, because the kitchen does not handle money. |
| **Struk** | The customer receipt, printed after payment. It has prices, totals, and payment details. |
| **Pra-bill** | A bill printed before payment so the guest can check what they owe. It is not a receipt and has no payment details. |
| **BATAL slip** | A slip printed for the kitchen when an item that was already sent is voided. It tells the kitchen to stop making it. |
| **SALINAN n** | Printed on a reprinted struk. "SALINAN 2" means the second copy. |
| **Antrean cetak** | The print queue: documents that failed or were not printed yet, which can be retried. |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Connect the printer and run a test print (Priority: P1)

The owner or a cashier pairs the VSC TM-58V with the tablet once from the app's device settings and prints a test page to confirm the paper width and text are right before the shift starts.

**Why this priority**: Every other story depends on the app knowing which printer to use and proving it can reach it. Without it nothing prints.

**Independent Test**: On a tablet with the printer switched on, open the printer setting, connect, press "Tes cetak", and confirm a test slip comes out fully readable and not cut at the edges.

**Acceptance Scenarios**:

1. **Given** the printer is on and in range/connected to the tablet, **When** the user chooses it in the printer setting, **Then** the app shows the printer as "Terhubung" (connected) with its model name "VSC TM-58V" and paper width "58 mm".
2. **Given** a connected printer, **When** the user presses "Tes cetak", **Then** a test slip prints without any system dialog and shows the store name, the date and time, and a line of sample characters that fits the 58 mm width.
3. **Given** no printer has been chosen yet, **When** a print action is requested, **Then** the app does not print silently; it shows "Printer belum dipasang" with a link to the printer setting.

---

### User Story 2 - Kitchen order prints automatically when sent (Priority: P1)

When a cashier or waiter presses "Kirim ke dapur" (send to kitchen), the new items print as a kitchen order slip (KOT) on the connected printer, without any extra step.

**Why this priority**: The kitchen works from paper. Today nothing reaches them on paper, so this is the main reason for the printer in the restaurant flow.

**Independent Test**: Add three items (one with a modifier and a note) to an open tab, press "Kirim ke dapur", and confirm one KOT slip prints listing exactly those three items with their modifiers and note, and that a second press with no new items prints nothing.

**Acceptance Scenarios**:

1. **Given** an open tab with three unsent items and a connected printer, **When** the user presses "Kirim ke dapur", **Then** one KOT slip prints listing the table/order identity, the time, and the three items with their quantities, modifiers, and notes.
2. **Given** items were already sent, **When** the user presses "Kirim ke dapur" again with no new items, **Then** no slip prints and no duplicate kitchen ticket is created.
3. **Given** the network drops after the server accepted the send and the user presses the button again, **When** the app retries, **Then** the kitchen slip is printed once, not twice.
4. **Given** a voided item that was already sent, **When** it is voided, **Then** a cancellation slip ("BATAL") prints to the kitchen printer (the existing 048 behaviour "Slip BATAL dicetak ke station" now physically prints).

---

### User Story 3 - Receipt prints automatically after payment (Priority: P1)

When a payment is completed and the sale is closed, the receipt (struk) prints automatically on the connected printer.

**Why this priority**: The customer receipt is the main thing a shop prints, and "print from the app goes straight to the printer" is an explicit requirement.

**Independent Test**: Pay a tab in cash with change, confirm the struk prints automatically with the store header, every item, subtotal, tax, discount, total, cash received, change, and the receipt number from the server, without the cashier pressing anything else.

**Acceptance Scenarios**:

1. **Given** a connected printer and a tab that is fully paid, **When** payment completes, **Then** the struk prints automatically once, and the cashier can start the next sale immediately.
2. **Given** the payment completed but the printer is off, **When** payment completes, **Then** the sale is still closed and saved, the struk is marked "Belum tercetak" (not printed), and the cashier can print it later from the same order (User Story 4).
3. **Given** a receipt is printed, **Then** it contains no customer phone number or e-mail address.

---

### User Story 4 - Print buttons where a printed copy is needed (Priority: P2)

The user can print manually from the places listed below. Each button sends the document to the connected printer directly.

| Place | Button | Document |
|---|---|---|
| Sale completed / Sales detail | "Cetak struk" | Struk (receipt) |
| Orders list row and order detail | "Cetak ulang" | Struk, with copy number |
| Open tab (editor) | "Cetak pra-bill" | Pre-bill (bill before payment) |
| Open tab (editor), sent items | "Cetak ulang KOT" | KOT slip |

**Why this priority**: Automatic printing covers the main path; manual printing covers reprints, pre-bills for the guest, and missed prints.

**Independent Test**: From each listed screen press the button and confirm the right document prints once, and that a second press produces a numbered copy as described below.

**Acceptance Scenarios**:

1. **Given** a struk was already printed once, **When** the user presses "Cetak ulang", **Then** the copy is labelled "SALINAN 2" and the first reprint needs no approval.
2. **Given** a struk has been reprinted, **When** the user presses "Cetak ulang" again, **Then** the app requires a supervisor PIN different from the requester, following the existing 048 reprint rule, and the reprint is logged.
3. **Given** an open tab, **When** the user presses "Cetak pra-bill", **Then** a pre-bill with the current items and totals prints and the table is marked "Bill tercetak" on the floor view.

---

### User Story 5 - Printer trouble never stops selling (Priority: P1)

If the printer is off, out of paper, or disconnected while the cashier is working, the sale and the kitchen order still go through. The failed print is visible and can be retried.

**Why this priority**: A broken printer must not lose a sale, a payment, or a kitchen order. The server record is always the source of truth; paper is a copy.

**Independent Test**: Turn the printer off, complete a sale and send a kitchen order, and confirm both are saved on the server and the app shows a "Belum tercetak" marker and a retry action; then turn the printer on, press retry, and confirm the document prints once.

**Acceptance Scenarios**:

1. **Given** the printer is unreachable, **When** a payment completes, **Then** the payment is recorded and the sale is closed within the same time as without a printer, and a "Belum tercetak" marker appears on that sale.
2. **Given** the printer is unreachable, **When** the user sends items to the kitchen, **Then** the items are sent to the kitchen on the server and a prominent warning "KOT belum tercetak — kirim ulang ke dapur secara manual" appears until the slip is printed or the user dismisses it with a reason.
3. **Given** a failed print is pending, **When** the printer becomes reachable again, **Then** the user can retry each pending document from a "Antrean cetak" list; each document prints exactly once even if retry is pressed twice.
4. **Given** the printer reports out of paper, **When** a print is attempted, **Then** the app shows "Kertas habis" and keeps the document pending.

---

### Edge Cases

- A long item name, note, or modifier list must wrap onto the next line inside the 58 mm width; it must never be cut off at the right edge.
- Rupiah amounts and Indonesian text (including "Rp", "SALINAN", "BATAL") print correctly; characters the printer cannot draw are replaced, not shown as garbage.
- The printer is paired but on another tablet: the app on this tablet reports "Tidak terhubung" and does not block selling.
- Bluetooth is switched off on the tablet, or the printer goes out of range, during a print: the job becomes "Belum tercetak" and the app says "Bluetooth mati" or "Printer di luar jangkauan" instead of a generic error.
- The tablet goes to sleep or the app is backgrounded in the middle of a print: the job stays in "Antrean cetak" and does not print twice when the app returns.
- Two print requests for the same document in quick succession (double tap) produce one printed copy.
- The printer runs out of paper mid-slip: the job is marked "Gagal" (failed) and the user can reprint the full document; a half slip is never recorded as printed.
- Receipts printed in the app's English setting still print in Indonesian, consistent with the existing rule that transaction receipts are always Indonesian (feature 002).

## Requirements *(mandatory)*

### Functional Requirements

**Printer setup**

- **FR-001**: The app MUST let the user choose and save one printer of type **VSC TM-58V** (58 mm paper) in the device settings, and show its connection state as "Terhubung" or "Tidak terhubung".
- **FR-002**: The app MUST provide "Tes cetak" (test print) that prints a sample slip and shows success or the exact failure reason.
- **FR-003**: The app MUST treat the paper width as 58 mm for every document; the layout MUST NOT depend on a width setting that the user can change.
- **FR-004**: The saved printer MUST be reconnected automatically when the app starts, if it is in range/available, without asking the user again.

**Automatic printing**

- **FR-005**: When the printer is connected, a completed payment MUST print the struk automatically, with no system print dialog and no extra confirmation.
- **FR-006**: When the printer is connected, "Kirim ke dapur" MUST print one KOT slip containing exactly the items sent in that action, with modifiers and notes.
- **FR-007**: A KOT or struk MUST NOT be printed twice for the same action, including after network retries, double taps, or app restarts.
- **FR-008**: Voiding an item that was already sent MUST print a cancellation slip ("BATAL") to the kitchen printer.

**Manual print buttons**

- **FR-009**: The app MUST offer "Cetak struk" on a completed sale, "Cetak ulang" on the orders list and order detail, "Cetak pra-bill" on an open tab, and "Cetak ulang KOT" on an open tab with sent items.
- **FR-010**: Reprints of a struk MUST be numbered "SALINAN n". The first reprint needs no approval; further reprints MUST require a supervisor/manager PIN that differs from the requester, consistent with feature 048's reprint rule. Every reprint MUST be logged.
- **FR-011**: Printing a pre-bill MUST mark the table as "Bill tercetak" on the floor view.

**Layout and content**

- **FR-012**: Every document MUST fit 58 mm paper width; long text MUST wrap, never be truncated silently.
- **FR-013**: A struk MUST show the store name and address, the receipt number issued by the server, date and time, every line item with quantity and price, discount, tax, total, payment method, cash received and change where applicable.
- **FR-014**: A KOT MUST show the table or order identity, the time sent, the terminal/cashier name, and the items; it MUST NOT show prices.
- **FR-015**: A printed document MUST NOT include customer phone number or e-mail address.
- **FR-016**: Printed text MUST be Indonesian regardless of the app language.

**Failure handling and queue**

- **FR-017**: A print failure (printer off, disconnected, out of paper, or write error) MUST NOT prevent a payment from being recorded, a sale from being closed, or items from being sent to the kitchen on the server.
- **FR-018**: A failed or skipped print MUST be stored as a print job with status "Belum tercetak" (pending) or "Gagal" (failed), visible on the affected order, and listed under "Antrean cetak".
- **FR-019**: The user MUST be able to retry any pending print job from "Antrean cetak"; a job that has printed successfully MUST NOT be sent again by retry.
- **FR-020**: When a KOT is pending, the app MUST show a persistent warning on the tab until the slip prints or the user records a reason for not sending it manually.
- **FR-021**: Print jobs MUST survive app restarts and backgrounding and remain in the queue until printed or dismissed.
- **FR-022**: The app MUST NOT print a document silently to a printer the user has not chosen.

**In-app context**

- **FR-023**: Every print button MUST show a short explanation in the app's language (Indonesian or English) of what it prints and where the slip goes. For example, "Kirim ke dapur" explains that a KOT (kitchen order ticket) prints in the kitchen. Explanations MUST be reachable by a tooltip or an info icon, so they never take space from the buttons.
- **FR-024**: The printer setting MUST include step-by-step Bluetooth pairing help (turn on the printer, turn on Bluetooth on the tablet, choose the printer, run "Tes cetak") and the messages a user can see when pairing fails.
- **FR-025**: The "Antrean cetak" screen MUST explain each status in plain words: "Belum tercetak" (not printed yet, safe to retry), "Gagal" (failed, check the printer then retry), and "Tercetak" (printed).
- **FR-026**: The KOT and BATAL slips MUST print the words "KOT" and "BATAL" in capitals so kitchen staff recognise them without reading the rest of the slip.

## Key Entities *(include if feature involves data)*

- **Printer**: The one configured device on this tablet: model (VSC TM-58V), paper width (58 mm), connection details, last known state.
- **Print Job**: One document to print: kind (struk, KOT, pra-bill, BATAL slip), the order or tab it belongs to, copy number, status (pending, printed, failed, dismissed), attempt count, and the time of each attempt. Printed documents are linked to the same action that created them so duplicates can be detected.
- **Print Event Log**: Record of reprints and PIN-approved reprints (who asked, who approved, which document, copy number).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With the printer connected, 100% of completed payments print a struk automatically, with no dialog and no extra tap.
- **SC-002**: A cashier can go from "Kirim ke dapur" to a printed kitchen slip in under 5 seconds on a normal shop network and the printer on.
- **SC-003**: Across 50 "Kirim ke dapur" presses that include network retries and double taps, every kitchen order prints exactly once.
- **SC-004**: With the printer off, 100% of payments and kitchen sends still complete and are saved, and every missing paper copy is listed under "Antrean cetak".
- **SC-005**: After the printer is turned back on, a user can print every pending document with at most 3 taps per document, and no document prints twice.
- **SC-006**: On a test set of 20 slips that include the longest item names, modifiers and notes used in the shop, 100% fit the 58 mm width with no cut-off text.
- **SC-007**: A new cashier can print a manual reprint from the orders list in under 30 seconds without help.

## Assumptions

- **CONFIRMED**: The printer connects to the tablet over **Bluetooth** (confirmed by the product owner). Planning MUST check the printer's Bluetooth mode from its documentation (for example classic Bluetooth or Bluetooth Low Energy) and the Android permissions that mode needs.
- **ASSUMPTION**: One printer serves the whole outlet, including the kitchen and bar stations. Routing a KOT to a different printer per station is out of scope and is listed below.
- **ASSUMPTION**: The tablet is the device that talks to the printer; the server does not print and does not need to know about the printer.
- **ASSUMPTION**: Receipt and KOT content comes from data the app already loads from the server (feature 048's receipt and order data). Printing needs the data to be available on the tablet at print time; the offline print queue holds jobs whose data is already loaded.
- **ASSUMPTION**: Pre-bill ("bill sementara") and its "Bill tercetak" table marking follow the 048 description of pre-bill; the floor view already shows the table state.
- **ASSUMPTION**: Paper is 58 mm for this printer only. Other paper sizes are not supported in this feature.
- **ASSUMPTION**: The supervisor PIN rule for reprints reuses the existing 048 approval flow; no new approval rules are created.

## Out of Scope

- Multiple printers, per-station printer routing, and cash-drawer control.
- Printing reports (shift X/Z report, daily totals), labels, or barcodes/QR codes on paper.
- Printing from the web POS (the browser SPA); this feature covers the Android app only.
- Paper widths other than 58 mm, and printer models other than the VSC TM-58V.
- Changes to the server-side receipt or KOT data format beyond what the app already receives, unless planning finds a missing field.
