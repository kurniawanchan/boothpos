# Feature Specification: Pre-order Import/Export, Printing, Email Notification & Search

**Feature Branch**: `007-preorder-import-export-notify`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "tambah fitur pada: Pre-orders: import and export preorders transaction, print invoice or receipt sesuai dengan status, send message, status and invoice to customer email, search by customer name"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Search pre-orders by customer name (Priority: P1)

A cashier or admin looking for a specific customer's pre-order (e.g. the customer is at the counter asking about their order) needs to find it quickly without knowing the pre-order number or scrolling through the full list.

**Why this priority**: Smallest, most frequently needed change — every other story in this feature depends on being able to find the right pre-order first, and this alone already removes daily friction.

**Independent Test**: On the Pre-orders list, type a customer's name (full or partial) into a search field and confirm only matching pre-orders appear, without reloading the whole list or needing the pre-order number.

**Acceptance Scenarios**:

1. **Given** pre-orders exist for customers "Siti Amalia" and "Budi Santoso", **When** the user types "siti" into the search field, **Then** only Siti Amalia's pre-order(s) are shown.
2. **Given** the user types a name that matches no customer, **When** the search runs, **Then** the list shows an empty-state message, not an error.
3. **Given** the user has also set a status/event filter, **When** they also search by name, **Then** results satisfy both the name search and the existing filters together.
4. **Given** the user clears the search field, **When** the field becomes empty, **Then** the full (filtered by any other active filters) list returns.

---

### User Story 2 - Print an invoice or receipt matching the pre-order's status (Priority: P1)

An admin or cashier needs to hand the customer a printed document proving what was ordered and how much is owed or was paid — an **invoice** (down payment / order confirmation, showing what's still owed) while the order is still open, and a **receipt** (proof of full payment / handover) once it's fully settled and handed over.

**Why this priority**: This is a real, recurring operational need (giving customers paperwork) called out explicitly in the request, and — like the existing sales receipt and PO invoice in this system — is one of the most immediately useful, self-contained pieces of this feature.

**Independent Test**: Open a pre-order in each of its statuses (ordered, DP paid, arrived, settled, handed over, cancelled) and confirm the printed document's title, content, and totals correctly reflect that specific status, without needing any other part of this feature.

**Acceptance Scenarios**:

1. **Given** a pre-order with status "ordered" or "dp_paid" (not yet fully paid), **When** the user prints it, **Then** the document is titled/labeled as an **invoice**, showing items, total due, amount paid so far, and outstanding balance.
2. **Given** a pre-order with status "settled" or "handed_over" (fully paid), **When** the user prints it, **Then** the document is titled/labeled as a **receipt**, showing items, total paid, and (for handed_over) a handover confirmation.
3. **Given** a pre-order with status "cancelled", **When** the user prints it, **Then** the document is clearly marked as cancelled and does not present outstanding balance as if the order were still active.
4. **Given** any status, **When** the document is generated, **Then** it includes the store's identity (name, logo if configured — matching the existing sales receipt), the pre-order number, customer name, and item breakdown.

---

### User Story 3 - Export and import pre-order transactions (Priority: P2)

An owner/admin wants to pull all pre-order data into a spreadsheet for offline record-keeping, reporting, or bulk review (export), and separately wants to bring in a batch of new pre-orders that were collected elsewhere — e.g. via a paper form or a social-media order sheet before this system was in use — without typing each one in individually (import).

**Why this priority**: Valuable but less urgent day-to-day than finding a customer's order or handing them paperwork; also touches financial data, so it deserves to build on the search/print groundwork below it.

**Independent Test**: Export the current pre-order list to a file, verify it contains one row per pre-order with the same figures shown on-screen; separately, import a correctly-formatted file of new pre-orders and confirm they appear in the list with correct customer, items, and totals.

**Acceptance Scenarios**:

1. **Given** the pre-order list has active filters (status/event/customer/date), **When** the user exports, **Then** the exported file contains exactly the filtered set, not the entire unfiltered table.
2. **Given** a correctly filled import file (using the same template/column layout the export produces), **When** the user imports it, **Then** new pre-orders are created with the correct customer, items, quantities, and prices, and the response reports how many rows succeeded.
3. **Given** an import file with an invalid or missing required value on some rows (e.g. unknown SKU, missing customer name), **When** the user imports it, **Then** none of the file is applied silently mixed with errors — the user is shown which rows failed and why, consistent with how master-data import already behaves in this system (all-or-nothing).
4. **Given** an import row references a customer name that doesn't yet exist as a customer record, **When** the row is imported, **Then** a new customer record is created for it (using the name provided), consistent with how a pre-order created manually already requires/creates a customer.

---

### User Story 4 - Send status updates and invoices to the customer's email (Priority: P3)

When a pre-order's status changes (e.g. it arrives, or the customer's balance is fully settled), the store wants to proactively notify the customer by email, optionally attaching the current invoice/receipt, instead of relying on the customer to check in person or by phone.

**Why this priority**: Valuable for customer communication but depends on this store having configured outgoing email at all (many single-laptop, single-location shops may not), and is the most operationally complex piece (external email delivery, failure handling) — it's placed last so the rest of the feature stands on its own even where email isn't set up.

**Independent Test**: Change a pre-order's status (or trigger a manual "send" action) for a pre-order whose customer has an email on file, and confirm an email is sent containing the current status and the invoice/receipt appropriate to that status (per User Story 2), without needing export/import or search to be involved.

**Acceptance Scenarios**:

1. **Given** a pre-order's customer has an email address on file, **When** the pre-order's status changes, **Then** the customer receives an email stating the new status, with the current invoice/receipt attached or linked.
2. **Given** a pre-order's customer has no email address on file, **When** the status changes, **Then** the system does not attempt to send an email and does not block or fail the status change itself.
3. **Given** the store has not configured outgoing email, **When** a status change would normally trigger an email, **Then** the status change still succeeds, and the user seeing the pre-order is informed the notification could not be sent (rather than silently pretending it worked).
4. **Given** the user wants to notify a customer without a status having just changed, **When** they use a manual "resend notification" action, **Then** the same email (current status + invoice/receipt) is sent on demand.

---

### Edge Cases

- What happens when a customer's email bounces or the mail server is unreachable? The pre-order's status change must not fail because of it — the failure is recorded and surfaced to the operator, not silently swallowed nor blocking the underlying business action.
- What happens to already-printed invoices when a pre-order's status changes afterward (e.g. from "ordered" to "cancelled")? Each print reflects the pre-order's status *at the time it is generated* — there is no retroactive re-issuing of documents already handed to the customer.
- What happens on export when a pre-order's customer was later deleted/deactivated? The exported row still shows the customer name and details as they were at pre-order time (same snapshot principle already used for order/pre-order items in this system).
- What happens when an import file re-uses a value that looks like an existing pre-order number? Import always creates new pre-orders; it does not update or merge into existing ones.
- What happens if the same import file is submitted twice? Each import creates a new batch of pre-orders — there is no de-duplication against a previous import (no natural unique key ties an import row to a specific real-world order).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Users MUST be able to search the pre-order list by customer name (partial, case-insensitive match), combinable with the existing status/event/customer/fulfillment filters.
- **FR-002**: The system MUST generate a printable invoice for a pre-order whose status is not yet fully paid (`ordered`, `dp_paid`, `arrived`), showing items, total due, amount paid, and outstanding balance.
- **FR-003**: The system MUST generate a printable receipt for a pre-order whose status is fully paid (`settled`, `handed_over`), showing items and total paid, with a handover confirmation shown for `handed_over`.
- **FR-004**: The system MUST generate a printable document for a `cancelled` pre-order that is clearly marked cancelled and does not present an active outstanding balance.
- **FR-005**: Every printed invoice/receipt MUST include the store's identity (name, logo if configured), the pre-order number, customer name, and full item breakdown, consistent with the format of the existing sales receipt.
- **FR-006**: Users with reporting/export access MUST be able to export the pre-order list (respecting any active filters) to a downloadable file containing customer, items, quantities, prices, status, and totals per pre-order.
- **FR-007**: Users with import access MUST be able to import a batch of new pre-orders from a file using the same column layout the export produces, in a single all-or-nothing operation (matching this system's existing master-data import convention) — the file structure and validation rules follow that existing pattern rather than a new authoring style.
- **FR-008**: The import MUST validate every row before applying any of them; if any row fails validation, none of the file's pre-orders are created, and the response MUST report which rows failed and why.
- **FR-009**: The import MUST create a new customer record when a row's customer name does not match an existing customer, mirroring the customer requirement already enforced when creating a pre-order manually.
- **FR-010**: Imported pre-orders MUST NOT be created with a status that has already collected payment or already been marked as arrived/settled/handed over — every imported pre-order starts at the earliest status (`ordered`) so its downstream payment/status history remains accurate and auditable; the operator advances it manually afterward if it already reflects further progress in real life.
- **FR-011**: When a pre-order's status changes, and the pre-order's customer has an email address on file, and the store has outgoing email configured, the system MUST send the customer an email stating the new status with the applicable invoice/receipt (per FR-002–FR-004) attached or linked.
- **FR-012**: If the customer has no email on file, the system MUST skip sending without treating it as an error and without blocking the status change.
- **FR-013**: If outgoing email is not configured or delivery fails, the status change MUST still succeed, and the failure MUST be visibly reported to the user acting on that pre-order (not silently discarded).
- **FR-014**: Users MUST be able to manually trigger a resend of the current status + invoice/receipt email for a given pre-order, independent of a status change just having occurred.
- **FR-015**: Export, import, and manual email-resend MUST be restricted to the same roles already permitted to manage pre-orders and reports in this system (owner/admin), not exposed to cashier-only roles.

### Key Entities

- **Pre-order** *(existing)*: unchanged in shape; gains a searchable relationship to its customer's name and becomes the subject of export/import/print/email actions.
- **Pre-order Import Batch** *(conceptual, not necessarily its own stored record)*: the set of rows submitted together in one import operation; validated and applied as a single all-or-nothing unit, matching the existing master-data import behavior.
- **Notification attempt** *(new)*: a record of an email send attempt tied to a pre-order — status change or manual resend that triggered it, whether it succeeded, and why it failed if it didn't — so failures are visible rather than silent.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member can locate a specific customer's pre-order by typing their name in under 5 seconds, without knowing the pre-order number.
- **SC-002**: A correct invoice or receipt (matching the pre-order's current status) can be produced and handed to a customer in under 15 seconds from opening the pre-order.
- **SC-003**: An owner/admin can export the full pre-order history for a reporting period and reconcile it against the on-screen totals with zero discrepancies.
- **SC-004**: A batch of at least 50 pre-orders collected outside the system can be brought in via import in under 5 minutes, versus the equivalent manual entry time.
- **SC-005**: When a pre-order's status changes and the customer has an email on file (and the store has email configured), the customer receives the notification within a few minutes, with no manual step required from staff.
- **SC-006**: Staff can always tell, without leaving the pre-order screen, whether a notification email actually went out or failed — there is no "silent" failure state.

## Assumptions

- Outgoing email is store-configurable (SMTP-style settings) rather than a hosted email service this product operates centrally — consistent with this being a locally-installed, one-time-license product with no cloud tier; a store that hasn't configured it simply won't get this notification, per FR-013.
- "Print" means generating a document in the browser for the operator to print or save (matching the existing sales receipt and purchase-order invoice's client-side PDF/print pattern in this system), not a server-managed print queue or a physical printer integration.
- Export/import file format and column-layout conventions follow the existing master-data import/export pattern already used elsewhere in this system, for consistency, rather than introducing a new file format.
- Search by customer name is a live, incremental filter on the existing pre-orders list — it does not introduce a separate search page.
- The "message" mentioned in the request (send message, status and invoice to customer email) refers to the notification email's body text, not a separate SMS/WhatsApp channel — email is the only channel this feature covers.
