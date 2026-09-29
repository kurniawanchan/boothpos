# Feature Specification: Pre-order Invoice/Shipping Progress, Print Menu & List Refinements

**Feature Branch**: `025-preorder-dispatch-status-list-refinements` (not yet created — the work is uncommitted on `develop`, on top of feature 024's tip)

**Created**: 2026-09-29

**Status**: Implemented (automated tests green); real-browser verification per quickstart.md still pending

**Input**: User description (four rounds, same day):
1. "in preorder page: in detail, add option status to mark invoice has been sent and shipping is already in progress can be change manually and can be filtered. in detail, add dropdown button to print preorder invoice and payment invoice"
2. "fix the payment invoice link in the list row too; Shipping in progress only for mail order"
3. "fix the customer name alignment to left; add action column name, use dropdown for multiple action more than 3; add created date and updated date column and detail; add invoice sent and shipping date in the list (no need new column"
4. "yes, show these dates in the existing status cell" / "yes also update for export and import" / "update the related specs file"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mark a pre-order's invoice as sent / shipping in progress, and filter by it (Priority: P1)

Staff can record, by hand, how far a pre-order has got outside the system — "invoice sent" and "shipping in progress" — from the pre-order's detail panel, correct it if they mis-clicked, and filter the list by it to see, for example, everything whose invoice is still unsent.

**Why this priority**: The core request; every other story in this feature displays, prints, or exports this marker.

**Independent Test**: Open a pre-order's detail, click "Invoice sent", confirm the list row shows the same status; filter the list by "Invoice sent" and confirm only such rows remain.

**Acceptance Scenarios**:

1. **Given** a new pre-order, **When** its detail is opened, **Then** it shows "Not sent yet" as the active option.
2. **Given** any non-cancelled pre-order, **When** staff click "Invoice sent", **Then** the marker changes, a confirmation appears, and the list row reflects it without a manual refresh.
3. **Given** a pre-order marked "Shipping in progress", **When** staff click "Invoice sent" or "Not sent yet", **Then** the marker moves back (corrections are allowed in both directions).
4. **Given** the marker changes, **Then** stock, payments, notifications, and the main order status (Ordered → Handed over) are all unchanged.
5. **Given** a cancelled pre-order, **Then** the marker's buttons are disabled and the server refuses the change (409).
6. **Given** the list, **When** one or more "Invoice / shipping" filter values are chosen, **Then** only matching rows (and a matching summary panel) are shown, combined with the other filters.

---

### User Story 2 - "Shipping in progress" applies to Mail Order only (Priority: P1)

A Self Pickup pre-order is collected at the booth and is never shipped, so "Shipping in progress" is not offered for it.

**Why this priority**: Prevents an impossible state from ever being recorded.

**Independent Test**: Open a Self Pickup pre-order's detail and confirm only "Not sent yet" and "Invoice sent" are offered; open a Mail Order one and confirm all three are.

**Acceptance Scenarios**:

1. **Given** a Self Pickup pre-order, **Then** the detail offers no "Shipping in progress" option, and a direct API request for it is refused (409).
2. **Given** a Mail Order pre-order marked "Shipping in progress", **When** it is edited to Self Pickup, **Then** its marker becomes "Invoice sent" and its shipping date is cleared.
3. **Given** the list filter, **Then** all three values remain selectable (a filter spans both fulfillment types).

---

### User Story 3 - Print the pre-order invoice or the payment invoice from the detail panel (Priority: P2)

From the detail panel, one "Print" dropdown offers "Preorder invoice" and "Payment invoice".

**Independent Test**: Open a detail, click "Print"; confirm both items appear, and "Payment invoice" is disabled until a payment is recorded.

**Acceptance Scenarios**:

1. **Given** the detail panel, **When** "Print" is opened and "Preorder invoice" chosen, **Then** the existing invoice modal opens for that pre-order.
2. **Given** at least one recorded payment, **When** "Payment invoice" is chosen, **Then** the existing payment-invoice modal opens for the latest payment (each earlier payment keeps its own button in the payment history).
3. **Given** no payment yet, **Then** "Payment invoice" is disabled, with a tooltip explaining why.

---

### User Story 4 - A tidier, working list row: name alignment, Actions column, overflow menu, payment-invoice link (Priority: P2)

The customer name is left-aligned; the last column is titled "Actions"; when a row has more than three actions, "Detail" stays visible and the rest move into a "More" dropdown; and the row's "Payment invoice" link actually opens that row's payment invoice.

**Independent Test**: In the list, confirm names are left-aligned even when wrapped; a Handed-over row shows three inline links; an Ordered row shows "Detail" plus "More" containing Invoice, Payment invoice, Edit, Delete; choosing "Payment invoice" opens that row's document.

**Acceptance Scenarios**:

1. **Given** a customer name that wraps onto two lines, **Then** both lines are left-aligned.
2. **Given** a row with three or fewer available actions, **Then** all are shown inline and no "More" appears.
3. **Given** a row with more than three, **Then** "Detail" is inline and the others are in the "More" dropdown, which closes on outside click, Escape, scroll, or resize and is never clipped by the table.
4. **Given** a row with no recorded payment, **Then** its "Payment invoice" item is disabled.
5. **Given** a row with a payment, **When** "Payment invoice" is chosen, **Then** the payment invoice for that row's pre-order opens (previously: nothing happened).

---

### User Story 5 - Created / updated dates in the list and the detail (Priority: P3)

The list gains sortable "Created" and "Updated" columns; the detail shows both.

**Acceptance Scenarios**:

1. **Given** the list, **Then** both columns show date and time and can be sorted ascending/descending.
2. **Given** the detail panel, **Then** "Created" and "Last updated" are shown under the status stepper.

---

### User Story 6 - See when the invoice was sent and shipping started (Priority: P2)

The date/time of each marker appears inside the existing Invoice / shipping cell of the list (no new column) and in the detail.

**Acceptance Scenarios**:

1. **Given** a pre-order marked "Invoice sent", **Then** its list cell shows the pill plus "Invoice sent: <date time>".
2. **Given** a pre-order marked "Shipping in progress" that also has an invoice date, **Then** both dates are shown.
3. **Given** the marker is set again to the value it already has, **Then** its date does not move.
4. **Given** the marker goes back from "Shipping in progress" to "Invoice sent", **Then** the shipping date is cleared and the invoice date kept; back to "Not sent yet" clears both.
5. **Given** any request that tries to send its own date, **Then** it is ignored — dates are set by the server only.

---

### User Story 7 - Export and import carry the new data (Priority: P2)

The Excel export includes the marker, its two dates, and read-only created/updated columns; the import accepts the marker and its dates; a file exported from the system still imports as-is.

**Acceptance Scenarios**:

1. **Given** the exported file, **Then** it has `dispatch_status`, `invoice_sent_at`, `shipping_at`, then read-only `created_at`, `updated_at`.
2. **Given** the list is filtered (including by the new filter, or by several status/fulfillment values), **When** exporting, **Then** only the filtered rows are exported.
3. **Given** an import row with blank marker columns, **Then** the pre-order is "Not sent yet" with no dates.
4. **Given** an import row breaking a rule (unknown value, `shipping` on a Self Pickup row, a date on a "Not sent yet" row, a shipping date on an "Invoice sent" row, an unreadable date), **Then** the whole file is rejected with a row-level error (409) and nothing is created.
5. **Given** an active marker with a blank date, **Then** the import time is used.
6. **Given** a file containing `created_at`/`updated_at`, **Then** they are ignored — an imported pre-order is created at import time.
7. **Given** a date written with a timezone offset, **Then** it is stored as the same instant in the application timezone.

---

### Edge Cases

- A pre-order jumped straight from "Not sent yet" to "Shipping in progress" has a shipping date but no invoice date (no invoice date is invented).
- "Updated" changes on *any* edit, including changing the marker; that is why the marker's own dates are stored separately.
- A list row from an older cached response with no `dispatch_status` is shown as "Not sent yet".
- The list "Export" button forwards array-shaped filters; the export previously threw on more than one selected status/fulfillment value.
- A Mail Order pre-order edited to Self Pickup must not keep a "shipping" marker (see US2).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every pre-order MUST carry a `dispatch_status` of `pending` (default), `invoice_sent`, or `shipping`, independent of its main `status`.
- **FR-002**: Changing `dispatch_status` MUST NOT alter stock, payments, notifications, or the main `status`.
- **FR-003**: `dispatch_status` MUST be changeable manually in both directions via `PATCH /preorders/{id}/dispatch-status`; a cancelled pre-order MUST be refused with 409.
- **FR-004**: `shipping` MUST be accepted only when `fulfillment = courier`; otherwise 409. Editing a `shipping` pre-order to pickup MUST set it to `invoice_sent` and clear `shipping_at`.
- **FR-005**: `GET /preorders` (and `/summary`, `/export`) MUST accept a repeatable `dispatch_status` filter combined with the existing filters.
- **FR-006**: `invoice_sent_at` and `shipping_at` MUST be set by the server from the target state: re-marking the active value keeps its date; leaving `shipping` clears `shipping_at`; returning to `pending` clears both; client-supplied dates MUST be ignored.
- **FR-007**: Responses MUST carry `dispatch_status`, `invoice_sent_at`, `shipping_at`, `created_at`, `updated_at`; `GET /preorders` MUST sort by `created_at` and `updated_at`.
- **FR-008**: The detail panel MUST offer a "Print" dropdown (Preorder invoice / Payment invoice); Payment invoice MUST be disabled without a payment and otherwise target the latest payment.
- **FR-009**: The list's last column MUST be titled "Actions"; rows with more than three actions MUST show "Detail" inline and the rest in a "More" dropdown that is not clipped by the table.
- **FR-010**: The list row's Payment invoice action MUST open the payment invoice of that row's pre-order and be disabled when the row has no payment.
- **FR-011**: The customer name in the list MUST be left-aligned.
- **FR-012**: The list MUST show the invoice-sent and shipping dates inside the existing Invoice / shipping cell — no new column.
- **FR-013**: The export MUST include `dispatch_status`, `invoice_sent_at`, `shipping_at` (ISO 8601 with offset) followed by read-only `created_at`, `updated_at`; the import template MUST include the first three and not the last two.
- **FR-014**: The import MUST accept the three columns under the same rules as FR-003/FR-004/FR-006, treat a violation as a row error rejecting the whole file (409), ignore `created_at`/`updated_at`, and normalise dates to the application timezone.
- **FR-015**: The export MUST accept `status`, `fulfillment`, and `dispatch_status` as either a single value or an array.
- **FR-016**: Every new UI string MUST exist in both `en.json` and `id.json` under unique keys (no duplicate JSON keys).

### Key Entities

- **Preorder**: gains `dispatch_status`, `invoice_sent_at`, `shipping_at` (see data-model.md).
- **Row actions menu / Print menu**: two small presentational components with no business logic.

## Success Criteria *(mandatory)*

- **SC-001**: Staff can mark a pre-order "Invoice sent" and see it reflected in the list in one click, with no page reload.
- **SC-002**: 0 pre-orders can be in `shipping` while Self Pickup, through the UI, the API, or an edit.
- **SC-003**: Filtering by the new marker, then exporting, produces a file containing exactly the rows shown.
- **SC-004**: A file exported from the system imports back without modification and reproduces the marker and its dates exactly.
- **SC-005**: Every existing preorder test and the full frontend suite still pass.

## Assumptions

- **"Invoice sent and shipping date in the list (no need new column" was cut off** in the request; interpreted as "show those dates inside the existing status cell", which the requester then confirmed.
- **The marker is a manual, informational tracker**, not an automation: nothing is emailed or sent by changing it (the existing status-change email is unaffected).
- **"Updated" means the database `updated_at`**, which changes on any edit; it is not a dedicated audit trail.
- **Exported timestamps are ISO 8601 in the application timezone (currently UTC)**, so they read 7 hours behind Jakarta wall-clock time; chosen because it is unambiguous and round-trips exactly. A local-time format could replace it if preferred.
- **The list's seller filter (`artist_id`) is still not honoured by the export** — pre-existing, outside this request.
- **`updated_at` sorting** reflects the last edit of any kind.

## Resolved Clarifications

No [NEEDS CLARIFICATION] markers were needed; the one truncated request line was resolved by the requester's follow-up (see Assumptions).
