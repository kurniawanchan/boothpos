# Feature Specification: Customer Data Import & Export

**Feature Branch**: `020-customer-data-import-export`

**Created**: 2026-09-23

**Status**: Draft

**Input**: User description: "build import and export for customer data"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Export the customer list to a spreadsheet (Priority: P1)

An owner/admin/inventory staff member on the Customers screen wants a full copy of the customer list (name, phone, address, email, social media, notes) as a spreadsheet file, so they can back it up, review it outside the app, or hand it to someone building a mailing/promo list.

**Why this priority**: Export has no risk of corrupting live data and is immediately useful on its own — it is the simplest possible slice of value and the natural first half of any import/export feature.

**Independent Test**: Can be fully tested by opening the Customers screen, triggering the export action, and confirming the downloaded file contains every visible customer with all of their contact fields, matching what the customer list itself shows for the currently-active DEMO/LIVE mode.

**Acceptance Scenarios**:

1. **Given** the Customers screen has one or more customers in the currently active mode (DEMO or LIVE), **When** the user triggers the export action, **Then** a spreadsheet file downloads containing one row per customer with their name, phone, address, email, social media handle, and notes.
2. **Given** the store is in DEMO mode, **When** the user exports customers, **Then** the file contains only DEMO-mode customers, never LIVE-mode customers (and vice versa) — mirroring the existing data-mode isolation already enforced everywhere else in the app.
3. **Given** the customer list is empty, **When** the user triggers the export action, **Then** the system produces a valid spreadsheet file containing only the column headers (no error).

---

### User Story 2 - Import new customers in bulk from a spreadsheet (Priority: P2)

An owner/admin/inventory staff member has a list of customers in a spreadsheet (e.g. collected at an event sign-up table, or exported from another system) and wants to bring them all into BoothPOS in one action instead of typing each one in individually through the "New customer" form.

**Why this priority**: This is the core value of the feature — bulk data entry — but depends on export existing first so users have a template/reference file shaped the way the system expects.

**Independent Test**: Can be fully tested by preparing a spreadsheet with several customer rows, importing it, and confirming each row appears as a new customer with the correct field values, without needing export to have been run first.

**Acceptance Scenarios**:

1. **Given** a spreadsheet with valid customer rows (at minimum a name for each row), **When** the user imports it, **Then** every row becomes a new customer, all in the mode (DEMO/LIVE) currently active for the store.
2. **Given** a spreadsheet row is missing the required Name field, **When** the user imports it, **Then** that specific row is reported as an error identifying which row and field failed, and no partial/corrupted customer record is created for it.
3. **Given** a spreadsheet row has an invalid value in an optional field (e.g. a malformed email address), **When** the user imports it, **Then** that row is reported as an error the same way, rather than being silently saved with bad data or silently dropped.
4. **Given** the file the user selects is not a real spreadsheet (e.g. a renamed text file, or a corrupted file), **When** the user attempts the import, **Then** the system rejects the whole file with a clear error instead of partially processing it.
5. **Given** a spreadsheet row's email address matches an existing customer's email in the currently active mode, **When** the user imports it, **Then** that existing customer's record is updated with the row's values (blank cells leave the existing value unchanged) instead of a duplicate customer being created.
6. **Given** a spreadsheet row has no email address, **When** the user imports it, **Then** it always creates a new customer, even if its name/phone happen to match an existing customer.

---

### User Story 3 - Preview an import before committing it (Priority: P3)

Before actually creating a large batch of customer records, a staff member wants to see what the import *would* do — how many new customers, and any row-level problems — so they can fix mistakes in their spreadsheet first.

**Why this priority**: This is a safety/confidence feature layered on top of Story 2's core import; valuable, but the system is still useful without it (a user could just import and check the result), so it is lower priority than the import itself.

**Independent Test**: Can be fully tested by running the import in "preview" mode against a spreadsheet with both valid and invalid rows, and confirming the preview reports the expected counts and errors without any customer actually being created.

**Acceptance Scenarios**:

1. **Given** a spreadsheet with a mix of valid and invalid rows, **When** the user requests a preview instead of a real import, **Then** the system reports how many rows would be created and lists every row-level error, without creating any customer records.
2. **Given** a previewed file has zero errors, **When** the user then confirms the real import of that same file, **Then** the result matches exactly what the preview reported.

---

### Edge Cases

- What happens when the spreadsheet contains two rows with the same email address? The second (and any later) row with that email is treated as an update to the same customer, applied in file order — the last row for a given email wins for any field it fills in.
- What happens when the file is imported a second time (e.g. the user re-runs the same file after fixing one row)? Rows with an email are recognized against the existing customer and updated rather than duplicated; rows without an email always create another new customer (see FR-011/FR-012).
- What happens if the file has far more rows than the system can process in one request (e.g. several thousand)? The system should report a clear "file too large" error rather than timing out silently, mirroring how the existing master-data import already caps file size.
- What happens if a column the user renamed or reordered in their spreadsheet doesn't match an expected heading? The system should ignore unrecognized columns and flag genuinely missing required columns, rather than failing the whole file for a heading mismatch.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST let an authorized user export the full customer list of the currently active data mode (DEMO or LIVE) to a downloadable spreadsheet file.
- **FR-002**: The exported file MUST include, per customer, at minimum: name, phone, address, email, social media handle, and notes.
- **FR-003**: The system MUST let an authorized user upload a spreadsheet file to import customer records in bulk.
- **FR-004**: The system MUST validate every row of an imported file before creating any customer records from it, and MUST report every invalid row (with enough detail to identify which row and which field failed) rather than guessing or silently skipping bad data.
- **FR-005**: The system MUST require, at minimum, a customer name for every imported row; all other fields are optional per row.
- **FR-006**: The system MUST reject a file that is not a genuine spreadsheet (e.g. wrong format, corrupted, or disguised as one) with a clear error, without partially importing it.
- **FR-007**: The system MUST let an authorized user preview an import (see what would happen) without actually creating any customer records, before committing to the real import.
- **FR-008**: Every customer created via import MUST be tagged to whichever data mode (DEMO/LIVE) is active for the store at the time of import, consistent with how every other customer record is tagged today.
- **FR-009**: Import and export of customer data MUST be restricted to the same roles already trusted with other bulk master-data operations in this system (owner, admin, and inventory), rather than being available to every role that can otherwise view or create individual customers.
- **FR-010**: The system MUST record the outcome of an import (counts of rows processed/created/updated, and any errors) in a way the user can review immediately after the import completes.
- **FR-011**: When an imported row's email address matches an existing customer's email address (in the currently active data mode), the system MUST update that existing customer's record with the row's values rather than creating a duplicate customer.
- **FR-012**: When an imported row has no email address, or its email does not match any existing customer, the system MUST create it as a new customer record.
- **FR-013**: A blank cell on an update row MUST leave the existing customer's corresponding field unchanged, rather than clearing it — matching the "blank means unchanged" convention already used by the existing master-data import.

### Key Entities

- **Customer**: A store's contact record for a person who buys or pre-orders merchandise — name (required), phone, address, email, social media handle, and free-text notes. Already exists in the system; this feature adds bulk import/export on top of the existing one-at-a-time create/edit/delete flow.
- **Import Result**: The outcome of one import attempt — how many rows were read, how many customers were created (or updated, pending Question 2), and a list of any row-level errors with enough detail (row number, field, reason) for the user to fix their file and retry.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can export the entire customer list to a file in under 5 seconds for a list of up to 1,000 customers.
- **SC-002**: A user can bring in 100 new customers from a prepared spreadsheet in a single import action, in under 30 seconds, versus the many minutes it would take to add them one at a time through the individual "New customer" form.
- **SC-003**: When an imported file contains mistakes, 100% of the invalid rows are identified individually (row + reason) in the result the user sees, with zero bad rows silently accepted or silently dropped.
- **SC-004**: Re-exporting the customer list immediately after a successful import and comparing it against the originally imported file shows the same customers with the same field values (round-trip integrity) for every row that had an email; rows without an email appear as additional, separate customers rather than being merged into an existing one.
- **SC-005**: Re-running the exact same import file a second time (with no changes) does not create any duplicate customers for rows that have an email address — the second run updates the same customers the first run created.

## Assumptions

- **Standalone customer import/export flow.** This system already has one combined Excel workbook (multiple sheets, one file) for bulk master-data import/export covering artists, categories, products, stock, vendors, and materials. Customer import/export is deliberately kept separate from that workbook — its own template file and its own entry point on the Customers screen — since customers aren't tied to the product catalog, mirroring how this system already keeps Pre-order import/export (007) separate from the master-data workbook for the same reason.
- **Column headers mirror the "New customer" form.** The exported/imported columns are: name, phone, address, email, social media handle, notes — the same fields already collected by the existing New/Edit Customer form, so the file a user gets from Export is immediately valid to re-import.
- **A blank cell on an update row means "leave unchanged"**, matching the convention already used by the existing master-data import.
- **No deletion via import.** Import can only create or update (by matching email) customers — it is never a way to delete an existing customer. Deletion stays exclusively in the existing one-at-a-time delete flow, which is already restricted to owner/admin.
- **DEMO/LIVE isolation applies exactly as it does everywhere else** in the system: an import always tags new rows with the currently active mode, matching for updates is only ever checked within the currently active mode, and export only ever includes the currently active mode's customers.
- **File size and format limits mirror the existing master-data import** (spreadsheet-only, with a maximum file size), rather than introducing a new, different limit just for this one entity.
- **Matching by email is case-insensitive and exact** (no fuzzy/partial matching) — e.g. `Jane@Example.com` and `jane@example.com` are treated as the same customer, but `jane@example.com` and `jane.doe@example.com` are not.

## Resolved Clarifications

- **Standalone vs. combined workbook** → Resolved: standalone, separate customer import/export flow (not folded into the existing master-data workbook). See Assumptions.
- **Re-import matching key** → Resolved: match existing customers by email address (case-insensitive, exact match) when present and update them; rows without an email always create a new customer. See FR-011/FR-012/FR-013 and Edge Cases.
