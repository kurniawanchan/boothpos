# Feature Specification: Purchase Order Row Actions and Out-of-date Database Errors

**Feature Branch**: `035-po-row-actions`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "In purchase order, add detail, edit, delete [list screenshot]; fix error [screenshot: opening a purchase order shows a database error and an empty dialog]; fix error [screenshot: Add BOM items from purchase orders shows a database error]."

## What was found (context, verified before writing)

- **List screenshot**: the Purchase Orders list shows a *Paid* purchase order with **no row actions at all**. Today a row only shows the next-status buttons plus Edit and Delete for *Draft* orders; the only way to see a purchase order's detail is to click its number, which is not discoverable.
- **Both error screenshots are the same root cause, not two code defects**: the database in the running development environment is **behind the application version**. Four database updates are pending there (the payment-proof update, the purchase-order seller update, the BOM source update and the BOM-complete update), and the app container has been running since before they were merged, so they were never applied. Opening a purchase order's detail reads a column that does not exist yet, and the BOM item selector filters on a purchase-order column that does not exist yet. (A fresh test database with all updates applied does not show either error.)
- Two usability gaps surfaced by those errors: the failed detail screen is a **blank dialog with only a Cancel button** (no message, no retry), and the raw database text is shown to the user.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See Detail, Edit and Delete on every purchase order row (Priority: P1)

An owner or admin opens the Purchase Orders list and sees, on every row, clear **Detail**, **Edit** and **Delete** actions next to the existing status actions, regardless of the order's status. They no longer have to guess that clicking the order number opens its detail, and a Paid or Received order no longer looks like it has nothing you can do.

**Why this priority**: This is the headline request; the current row for a finished order shows nothing to act on.

**Independent Test**: With purchase orders in each status (draft, ordered, received, paid, cancelled), confirm every row shows Detail, Edit and Delete, and that Detail opens the same detail screen as clicking the order number.

**Acceptance Scenarios**:

1. **Given** a purchase order in any status, **When** the list is shown, **Then** its row has Detail, Edit and Delete actions in addition to any status actions.
2. **Given** a row, **When** the user chooses Detail, **Then** the purchase order's detail (lines, totals, payments, seller) opens, exactly as when its number is clicked.
3. **Given** an action that is not allowed for that order's status, **When** the list is shown, **Then** the action is still visible but clearly marked unavailable with a short reason (for example "Only draft orders can be deleted"), never silently missing.
4. **Given** a user whose role cannot manage purchase orders, **When** the page is reached, **Then** none of these actions are shown (the screen itself is not available to that role).

---

### User Story 2 - Edit a purchase order at any stage, within what its status allows (Priority: P1)

The user chooses Edit on a row. For a **draft** order they can change everything (vendor, seller, notes and every line). For an order that is **no longer a draft** they can still open Edit and change the fields that remain editable, while the lines are shown but locked with an explanation, so the screen is never a dead end. Once an order is no longer a draft, **vendor, seller and notes remain editable** (the lines stay locked), except that the seller cannot change once a BOM uses the order's lines.

**Why this priority**: Part of the headline request and removes the "Paid order has nothing to do" dead end.

**Independent Test**: Edit a draft (all fields change) and a Paid order (only the allowed fields change; lines are visible but locked with a message).

**Acceptance Scenarios**:

1. **Given** a draft order, **When** the user edits vendor, seller, notes and lines and saves, **Then** all changes are saved and the total is recalculated.
2. **Given** an order that is not a draft, **When** the user opens Edit, **Then** the lines are shown read-only with an explanation, and only the allowed fields can be changed.
3. **Given** an order whose lines are used by a BOM, **When** the user tries to change its seller, **Then** the change is refused with an explanation.
4. **Given** the edit screen is opened from the list, **When** it appears, **Then** it shows the order's existing lines (not an empty list).

---

### User Story 3 - Delete a purchase order safely (Priority: P1)

The user chooses Delete on a row and confirms in a dialog that names the order. A **draft** order is deleted. For an order that is not a draft, the system explains why it cannot be deleted and points to the alternative (cancel it), without deleting anything. **Only drafts can be deleted**: ordered, received, paid and cancelled orders may already have stock, payments or BOM usage, so the way to void them is Cancel, and Delete stays visible but unavailable with that reason.

**Why this priority**: Part of the headline request; deletion is destructive so the rule must be explicit.

**Independent Test**: Delete a draft (it disappears, with an audit entry); try Delete on an ordered/received/paid order (nothing is deleted, a clear message appears).

**Acceptance Scenarios**:

1. **Given** a draft order, **When** the user confirms Delete, **Then** it is removed from the list and the deletion is recorded in the activity log.
2. **Given** a non-draft order, **When** the user chooses Delete, **Then** nothing is deleted and the user is told why and that Cancel is the alternative.
3. **Given** the confirmation dialog, **When** the user cancels it, **Then** nothing changes.

---

### User Story 4 - Purchase orders and the BOM item selector work again, and a behind-schedule database is explained, not exposed (Priority: P1)

After the pending database updates are applied, opening a purchase order's detail and opening "Add BOM items from purchase orders" both work with no error. Independently of that, if the database ever is behind the installed application (for example after an upgrade that was not applied), the user sees a **plain-language message** telling them an update is required and what to do, instead of raw database text, and the purchase order detail shows an error with a **Retry** button rather than an empty dialog.

**Why this priority**: The two error screenshots block daily work; without the clear message the same class of problem will recur after every upgrade.

**Independent Test**: (a) With all updates applied, open a purchase order detail and the BOM selector — both load. (b) With one update deliberately missing, open the same screens — a clear "update required" message appears, no raw database text, and the detail dialog offers Retry.

**Acceptance Scenarios**:

1. **Given** the database has all updates, **When** the user opens a purchase order's detail, **Then** it shows lines, totals, payments and seller without error.
2. **Given** the database has all updates, **When** the user opens "Add BOM items from purchase orders" for a variant, **Then** the eligible purchase order lines (or the "no eligible lines" explanation) are shown without error.
3. **Given** the database is behind the application, **When** any purchase order or BOM screen that depends on the missing update is opened, **Then** the user sees a short message that the database needs updating (and how, for an administrator), and no raw database text.
4. **Given** the purchase order detail fails to load for any reason, **When** the dialog opens, **Then** it shows the problem in plain words and a Retry button, never a blank dialog with only Cancel.
5. **Given** the pending updates are applied and the user presses Retry, **Then** the detail loads normally without reloading the page.

---

### Edge Cases

- A purchase order that has payments recorded: Delete is refused for non-drafts (payments are financial records); the message says so.
- A purchase order whose lines feed a BOM: Delete and seller change are refused with the BOM reason.
- A cancelled order: Detail is available; Edit is limited to vendor, seller and notes; Delete is unavailable (it is already voided).
- A purchase order with no seller (created before sellers were required): Edit lets an authorized user assign one.
- DEMO and LIVE data stay separate: actions only ever apply to the active mode's purchase orders.
- Two users acting at once: if an order changed state between the list load and the action, the action is re-checked and the user gets the current reason, never a half-applied change.
- Narrow screens: row actions stay reachable (they may wrap or move into a "More" menu) and the list keeps working.
- The same missing-update condition affecting other screens (for example payment proofs) is explained the same way, not only on purchase orders.

## Requirements *(mandatory)*

### Functional Requirements

**Row actions**

- **FR-001**: Every row of the Purchase Orders list MUST show Detail, Edit and Delete actions, whatever the order's status, in addition to the existing status actions.
- **FR-002**: Detail MUST open the same purchase order detail screen that clicking the order number opens.
- **FR-003**: An action not allowed for the order's current status MUST remain visible but be marked unavailable with a short reason, rather than being hidden.
- **FR-004**: Row actions MUST remain usable on narrow screens and MUST be available only to roles that can manage purchase orders.

**Edit**

- **FR-005**: Edit on a draft order MUST allow changing vendor, seller, notes and all lines, and MUST recalculate totals on the server.
- **FR-006**: Edit on an order that is not a draft MUST open, show the lines read-only with an explanation, and allow changing only vendor, seller and notes; the seller MUST NOT be changeable once a BOM uses any of the order's lines.
- **FR-007**: The edit screen opened from the list MUST show the order's existing lines.
- **FR-008**: Saving an edit MUST be recorded in the activity log when it changes the vendor, seller or lines.

**Delete**

- **FR-009**: Delete MUST require a confirmation that names the purchase order, and MUST do nothing if the user cancels.
- **FR-010**: A draft order MUST be deletable, and the deletion MUST be recorded in the activity log.
- **FR-011**: A purchase order that is not a draft MUST NOT be deleted; the user MUST be told why and offered Cancel as the alternative (a cancelled order cannot be deleted either); an order that has payments or whose lines are used by a BOM MUST NOT be deleted in any case.

**Errors and upgrades**

- **FR-012**: Opening a purchase order's detail and opening the BOM item selector MUST work without error when the database has all current updates.
- **FR-013**: When the database is behind the installed application, screens that depend on the missing update MUST show a plain-language message that an update is required and how an administrator applies it, MUST NOT show raw database text to the user, and MUST NOT leave a blank dialog.
- **FR-014**: The purchase order detail dialog MUST show a load failure in plain words with a Retry action; Retry MUST reload the detail without a page refresh.
- **FR-015**: Technical details of a failure MUST remain available to administrators in the application log, not in the user's screen.

**Access and data modes**

- **FR-016**: All of the above MUST respect the existing purchase-order permissions and DEMO/LIVE separation; no new role or data class is introduced.

### Key Entities *(include if feature involves data)*

- **Purchase order**: vendor, seller, status (draft, ordered, received, paid, cancelled), lines, totals, payments; the subject of Detail, Edit and Delete.
- **Purchase order line**: item bought (material or service) with quantity and price; locked after the draft stage; may be used by a BOM.
- **Activity log entry**: records edits and deletions of purchase orders.
- **Database update (migration) state**: whether the stored structure matches the installed application version; drives the "update required" message.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of purchase order rows, in every status, show Detail, Edit and Delete (available or marked unavailable with a reason).
- **SC-002**: A user can open any purchase order's detail from its row in one click without knowing the number is clickable.
- **SC-003**: Opening a purchase order's detail and the BOM item selector succeeds in 100% of checks on a database with all updates applied.
- **SC-004**: In 100% of checks with a deliberately missing database update, the user sees a plain-language "update required" message and 0 raw database error texts.
- **SC-005**: The detail dialog never appears blank on failure: 100% of failures show a message and a Retry action.
- **SC-006**: 0 purchase orders with payments, or whose lines feed a BOM, are deleted through the interface.
- **SC-007**: Every edit that changes vendor, seller or lines, and every deletion, appears in the activity log with who and when.

## Assumptions

- The two reported errors are caused by **pending database updates in the running development environment** (verified: four pending updates, app container started before they were merged). Applying them to that existing database is an operational step (restart the app container, which applies updates on start, or run the update command); this feature adds the clear message and the recovery path, it does not silently change an existing database.
- Existing business rules stay: purchase order lines are editable only while the order is a draft; deleting is limited to drafts; payments are recorded against received/paid orders.
- Decisions confirmed by the requester (2026-10-05): after draft, vendor/seller/notes stay editable and lines locked; only drafts are deletable (everything else is cancelled, not deleted).
- The Detail action reuses the existing detail screen; no new detail layout is requested.
- Row actions follow the existing pattern used on the Pre-orders list (visible, disabled with a reason when not allowed, overflow into a "More" menu when many).
- Users are the owner, admin and inventory roles that already have the Purchase Orders menu.
