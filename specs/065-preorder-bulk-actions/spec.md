# Feature Specification: Pre-order Bulk Actions

**Classification**: INTERNAL

**Feature Branch**: `095-preorder-bulk-actions`

**Created**: 2026-11-08

**Status**: Draft

**Input**: User description: "Preorder Bulk Management — add bulk selection and bulk actions to the Pre-orders list so many pre-orders can be processed at once instead of one by one: bulk update status, bulk update invoice progress, bulk update shipping progress, bulk add payment, bulk create shipment data; with per-pre-order validation, skipped/failed explanations, atomic processing per pre-order, a confirmation summary, a result summary with retry, permission checks, and an audit trail."

> **Amendment 2026-11-09 (product owner):** User Story 3 (bulk add payment) and User Story 6 (bulk create shipments), and FR-020–FR-029, were built and then REMOVED at the product owner's request. Only selection, bulk status, bulk invoice/shipping progress, results/retry and traceability remain. The text below is kept as the historical record.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Select many pre-orders conveniently and see the bulk actions (Priority: P1)

On the Pre-orders list a user can already tick individual rows. They also need to select every row on the page with one header checkbox, select every pre-order that matches the current filters (across all pages), see how many are selected, and clear the selection in one click. As soon as at least one pre-order is selected, the bulk actions appear: Update status, Update invoice progress, Update shipping progress, Add payment, Create shipments (next to the existing download, email and duplicate actions).

**Why this priority**: Every bulk action starts from the selection. With only this story the user already gets a faster, clearer way to build a selection for the existing bulk actions.

**Independent Test**: With 60 pre-orders over 3 pages, tick the header checkbox (page selected), choose "select all N matching", confirm the count, then clear; verify the action bar appears and disappears accordingly and that the count matches.

**Acceptance Scenarios**:

1. **Given** a page of pre-orders, **When** the user ticks the header checkbox, **Then** every row on the page is selected, the header checkbox shows checked, and the selected count shows the number of rows.
2. **Given** some rows selected on a page, **When** the header checkbox is shown, **Then** it displays a partial (indeterminate) state, and ticking it selects the rest of the page.
3. **Given** a page fully selected and more matching pre-orders on other pages, **When** the user chooses "Select all N matching the current filters", **Then** all matching pre-orders (up to the per-action limit) are selected and the count shows N.
4. **Given** a selection, **When** the user clicks "Clear selection" (or unticks the header checkbox), **Then** nothing is selected and the bulk action bar disappears.
5. **Given** no pre-order is selected, **When** the list is shown, **Then** no bulk actions are shown.
6. **Given** a selection, **When** the user changes a filter, search or sort, **Then** the selection is reset (or reduced to what is still listed) and the count always matches what the user sees as selected.
7. **Given** a role that may not perform an action, **When** the action bar is shown, **Then** that action is not offered at all (hidden, not disabled).

---

### User Story 2 - Update the status of many pre-orders at once (Priority: P1)

The user selects pre-orders, chooses "Update status", picks a target status (for example "Goods arrived"), reviews a confirmation summary that says how many selected pre-orders can move to that status and how many cannot (and why), and confirms. Pre-orders whose current status allows the target move; the others are skipped with a reason. Moving to Cancelled asks for a reason once, applied to all.

**Why this priority**: Moving a whole batch of orders through the lifecycle (for example everything that arrived) is the most common repetitive task.

**Independent Test**: Select 10 pre-orders in mixed statuses, bulk move to "Goods arrived"; verify only those currently "Deposit paid" moved and every other one is skipped with an explanation.

**Acceptance Scenarios**:

1. **Given** selected pre-orders in different statuses, **When** the user opens "Update status", **Then** only target statuses that at least one selected pre-order can validly move to are offered, with the count of pre-orders each target would apply to.
2. **Given** a chosen target, **When** the confirmation summary is shown, **Then** it states how many will be updated and how many will be skipped, and nothing changes until the user confirms.
3. **Given** a confirmed bulk status change, **When** it finishes, **Then** every eligible pre-order has the new status exactly as if it had been changed individually (same rules and side effects), and each ineligible one is unchanged and listed as skipped with the reason (for example "invalid transition from Handed over", "not fully paid", "insufficient stock").
4. **Given** the target "Handed over", **When** a selected pre-order is not fully paid or its goods are not in stock, **Then** it is skipped (not failed silently) with that reason, and the others still proceed.
5. **Given** the target "Cancelled", **When** the user confirms, **Then** a reason is required once and recorded on every cancelled pre-order.
6. **Given** a status change on a pre-order that sends a customer notification when changed individually, **When** it is changed in bulk, **Then** the same notification behaviour applies and a notification problem never undoes or fails the status change.
7. **Given** an invalid transition for a pre-order, **When** the bulk change runs, **Then** that pre-order is never moved by force.

---

### User Story 3 - Record payments for many pre-orders at once (Priority: P1)

The user selects pre-orders, chooses "Add payment", enters the common payment details once (method, channel where required, reference, notes), and sets the amount for each pre-order — by default the full outstanding balance of each, editable per pre-order. A confirmation summary shows the total amount and the resulting payment status of each. On confirm, each pre-order receives its own payment against its own transaction.

**Why this priority**: Reconciling a batch of transfers or a day of payments is the heaviest manual job on the screen, and correctness of balances matters most.

**Independent Test**: Select 5 pre-orders with different balances, add a bank-transfer payment with the default "pay outstanding" amounts and one edited partial amount; verify each pre-order's paid amount, remaining balance and payment status, and that nothing is recorded twice when the same action is retried.

**Acceptance Scenarios**:

1. **Given** selected pre-orders, **When** the user opens "Add payment", **Then** a form asks for the shared fields (method, channel when the method needs one, reference, notes) and lists each pre-order with its current paid amount, outstanding balance and an editable amount defaulting to the outstanding balance.
2. **Given** different amounts per pre-order, **When** the user confirms, **Then** each pre-order receives a payment of exactly its own amount, recorded against its own transaction, and its paid amount, remaining balance and payment status (unpaid / partially paid / fully paid) are updated accordingly.
3. **Given** an amount larger than a pre-order's outstanding balance, a zero or negative amount, or a pre-order that is already fully paid or cancelled, **When** the user reviews or confirms, **Then** that pre-order is flagged before confirmation and skipped (with the reason) if still invalid, never overpaid.
4. **Given** a non-cash method that needs a channel and none is chosen, **When** the user tries to continue, **Then** it is refused with a clear message and nothing is recorded.
5. **Given** a shared reference and notes, **When** payments are recorded, **Then** each payment carries them, is recorded at the moment of processing (like an individually recorded payment), and each can later receive its own proof, be confirmed or be verified individually, like any payment.
6. **Given** the same bulk payment request is submitted twice (double click, retry after a dropped connection), **When** it is processed, **Then** no pre-order receives a duplicate payment.
7. **Given** a payment that fully pays a pre-order, **When** it is recorded in bulk, **Then** the pre-order's status follows the same automatic rules as an individually recorded payment.

---

### User Story 4 - Clear results, and retry what failed (Priority: P1)

After any bulk action the user sees a result summary: total selected, succeeded, skipped, failed, and — for every skipped or failed pre-order — its number and the reason in plain words. The user can retry just the skipped/failed ones (after fixing the cause) with one click, or open any of them individually. The list and all status indicators refresh to the new state, and a processing indicator shows progress while the action runs.

**Why this priority**: Bulk work is only trustworthy if the user can see exactly what happened to each pre-order; every other story depends on this.

**Independent Test**: Run a bulk action over a selection that contains valid, already-processed and invalid pre-orders; verify the counts add up, every non-success row has a reason, retry selects exactly the non-successes, and the list shows the new state.

**Acceptance Scenarios**:

1. **Given** a bulk action in progress, **When** it is processing, **Then** the user sees a progress/loading indicator, cannot trigger it twice, and cannot lose track of it.
2. **Given** a finished bulk action, **When** the result is shown, **Then** it shows total selected, successful, skipped and failed counts that add up to the selection, and a per-pre-order list of reasons for every skipped or failed one.
3. **Given** a result with skipped or failed pre-orders, **When** the user chooses "Retry these", **Then** exactly those pre-orders become the selection, and the same action can be re-run (for example after correcting an amount or a missing address).
4. **Given** a result row, **When** the user clicks the pre-order number, **Then** its detail opens so it can be fixed individually.
5. **Given** a finished action, **When** the user closes the result, **Then** the list shows the updated statuses, paid amounts and progress markers without a manual reload, and the selection keeps only the pre-orders that were not processed successfully.
6. **Given** an unexpected failure for one pre-order, **When** the action runs, **Then** that pre-order is reported as failed with a generic reason (no technical details), nothing about it is half-applied, and the others are unaffected.
7. **Given** the connection drops before the result arrives, **When** the user returns to the list, **Then** the user is told the outcome is unknown and can safely re-run the action without creating duplicates.

---

### User Story 5 - Update invoice progress and shipping progress for many pre-orders (Priority: P2)

For the progress markers shown on each pre-order (invoice not sent / invoice sent / shipping), the user selects pre-orders and sets the marker for all of them in one operation: "Mark invoice sent" (or back to "Not sent yet") and "Mark shipping". The same rules as one-by-one apply: shipping only for Mail Order pre-orders, never for cancelled ones, and the recorded dates follow the same rules.

**Why this priority**: Useful and frequent, but the markers are informational (they do not change stock or money), so it ranks below status and payment.

**Independent Test**: Select 8 pre-orders (some pickup, some Mail Order, one cancelled), bulk "Mark shipping"; verify only eligible Mail Order ones change, the rest are skipped with reasons, and each keeps its own dates.

**Acceptance Scenarios**:

1. **Given** selected pre-orders, **When** the user chooses "Mark invoice sent", **Then** every non-cancelled selected pre-order gets that marker with its own "invoice sent" date; cancelled ones are skipped with the reason.
2. **Given** selected pre-orders including Self Pickup ones, **When** the user chooses "Mark shipping", **Then** only Mail Order pre-orders change; Self Pickup ones are skipped with the reason "shipping applies to Mail Order only".
3. **Given** pre-orders already at the chosen marker, **When** the action runs, **Then** they are counted as already done (skipped, no date shifted).
4. **Given** the confirmation step, **When** it is shown, **Then** it states how many will change and how many will be skipped before anything is saved.
5. **Given** the marker changes, **When** it is saved, **Then** stock, payments, status and notifications are untouched, exactly as for the individual action.

---

### User Story 6 - Create shipment records for many Mail Order pre-orders (Priority: P2)

The user selects Mail Order pre-orders, chooses "Create shipments", enters the shared shipping details once (courier, shipping cost if applicable, notes), and reviews a list with each pre-order's own recipient (name, phone, address — taken from that pre-order's customer and shipping information) and an editable tracking number. On confirm, one shipment record is created per pre-order, each with its own recipient and tracking number.

**Why this priority**: Saves a lot of re-typing for shipping days, but only applies to Mail Order orders and is lower frequency than status and payment.

**Independent Test**: Select 6 Mail Order pre-orders (one already has a shipment, one has no recipient address, one is Self Pickup); create shipments with a shared courier and per-row tracking numbers; verify three shipments are created with their own details and the other three are skipped with reasons.

**Acceptance Scenarios**:

1. **Given** selected pre-orders, **When** the user opens "Create shipments", **Then** the form asks once for the shared details (courier and any shared fields) and shows one editable row per pre-order with its recipient and an optional tracking number.
2. **Given** a confirmed action, **When** it completes, **Then** each eligible pre-order has its OWN shipment record with its own recipient details and tracking number, linked only to that pre-order.
3. **Given** a pre-order that already has a shipment, **When** the action runs, **Then** it is skipped ("shipment already exists") and the existing shipment is never overwritten or duplicated.
4. **Given** a Self Pickup pre-order, **When** the action runs, **Then** it is skipped ("shipments apply to Mail Order only").
5. **Given** a pre-order whose required recipient information (name, phone, address) is missing, **When** the user reviews, **Then** it is flagged in the list so the user can fill it in, and skipped with the reason if still missing at confirmation.
6. **Given** the same request submitted twice, **When** it is processed, **Then** at most one shipment exists per pre-order.
7. **Given** shipments created in bulk, **When** the pre-order detail is opened, **Then** the shipment appears exactly as if created individually and can still be edited individually.

---

### User Story 7 - Every bulk change is traceable (Priority: P2)

Each pre-order changed by a bulk action leaves the same audit record as the individual action would, and each is also traceable to the bulk operation it came from: who ran it, when, which action, and what changed for that pre-order (old and new values). Owners and admins can see this in each pre-order's history.

**Why this priority**: Required for accountability, built on the records the other stories create.

**Independent Test**: Run each bulk action over 3 pre-orders; open each pre-order's history and verify an entry with user, time, change and "via bulk"; verify skipped/failed pre-orders have no change entry.

**Acceptance Scenarios**:

1. **Given** a successful bulk action, **When** an owner/admin views a changed pre-order's history, **Then** there is an entry with the user, time, action, old and new values, and a marker that it came from a bulk operation.
2. **Given** a skipped or failed pre-order, **When** its history is viewed, **Then** it contains no entry claiming a change.
3. **Given** a bulk action that is rolled back for a pre-order, **When** history is viewed, **Then** no entry claims it happened.

---

### Edge Cases

- **Mixed selection**: some eligible, some not — eligible ones always proceed; the result lists every non-success with a reason; counts always add up to the selection.
- **Selection over the limit**: refused up front with a message stating the limit; nothing is processed.
- **Selection changed on the server meanwhile** (another user updated a pre-order after the list loaded): each pre-order is judged on its current state at processing time; one that no longer qualifies is skipped with a clear reason (never forced).
- **Same pre-order in two bulk actions at once**: no duplicate payment, shipment or marker; the second attempt is skipped as already done.
- **Cancelled pre-orders** in a payment, shipping, shipment or status selection: skipped with the reason where the action does not apply.
- **Partially paid and fully paid mixes** in a payment batch: the default amounts only cover the outstanding balance; fully paid ones are skipped.
- **Payment amount rounding / cents**: amounts are exact to the cent and never exceed the remaining balance.
- **Handing over many pre-orders** when stock is short for some: those are skipped with the stock reason; the rest are handed over.
- **Role without a given action** (for example may update status but not record payments): only the permitted actions are offered, and the action is refused for anyone who crafts the request.
- **Seller-scoped users**: can only see and act on pre-orders visible to them, exactly like individual actions; anything outside is reported as not found/skipped.
- **Demo vs live data**: a selection never mixes modes; pre-orders from the other mode are never touched.
- **Notification failure** (email not configured or failing): never fails or reverts the status change; reported only as a notification note.
- **Large selections and slow connections**: the user always sees progress, and a dropped connection never leaves a pre-order half-processed.
- **Empty selection**: no action can be started.
- **Nothing eligible**: the user is told before confirming and cannot confirm an action that would change nothing.
- **Retry after partial success**: already-processed pre-orders are never processed twice.

## Requirements *(mandatory)*

### Functional Requirements

**Selection**

- **FR-001**: The Pre-orders list MUST let the user select pre-orders by row, select all rows on the current page with a header checkbox (with a partial state), and select all pre-orders matching the current filters across all pages, up to the per-action limit.
- **FR-002**: The list MUST show the number of selected pre-orders, MUST offer a one-click "Clear selection", and MUST reset or reduce the selection when filters, search or sort change so the count is always truthful.
- **FR-003**: Bulk actions MUST be shown only while at least one pre-order is selected, and each action MUST be shown only to users who may perform it individually (hidden, not disabled).
- **FR-004**: One bulk action MUST be limited to a maximum number of pre-orders; a larger selection MUST be refused before anything is processed, with a message stating the limit.

**Common processing rules**

- **FR-005**: Every bulk action MUST judge each selected pre-order independently, on its state at the moment of processing.
- **FR-006**: A pre-order that does not qualify MUST be skipped with a clear human-readable reason; skipping or failing one pre-order MUST NOT block or undo the others.
- **FR-007**: Processing of each pre-order MUST be all-or-nothing: either all of its changes for that action are applied (with its audit entry) or none are.
- **FR-008**: Bulk actions MUST apply exactly the same business rules, side effects and notifications as the corresponding individual action; they MUST NOT offer a shortcut that an individual action would refuse.
- **FR-009**: Bulk actions MUST NOT create duplicate payments, shipments or markers when the same request is repeated or submitted concurrently, and MUST NOT overwrite existing data unintentionally (for example an existing shipment).
- **FR-010**: Each bulk action MUST be authorized per action, on the server, with the same permissions as the individual action; pre-orders the user cannot see or act on MUST be reported as skipped and never changed or revealed.
- **FR-011**: A bulk action MUST never mix demo and live data and MUST never change anything other than what the action describes.

**Update status**

- **FR-012**: The user MUST choose one target status for the whole selection; the choices offered MUST be limited to statuses that at least one selected pre-order can validly move to, with the number of pre-orders each would apply to.
- **FR-013**: A pre-order whose current status does not allow the target MUST be skipped with the reason; invalid transitions MUST never be forced.
- **FR-014**: Moving to "Handed over" MUST still require full payment and sufficient stock for each pre-order; those that fail MUST be skipped with the specific reason.
- **FR-015**: Moving to "Cancelled" MUST require one reason, applied to every cancelled pre-order.
- **FR-016**: A confirmation MUST state, before anything is saved, how many pre-orders will be updated and how many skipped.

**Invoice progress and shipping progress**

- **FR-017**: The user MUST be able to set the invoice-progress marker ("invoice sent" / "not sent yet") and the shipping marker ("shipping") on all selected pre-orders in one operation.
- **FR-018**: The shipping marker MUST only apply to Mail Order pre-orders; cancelled pre-orders MUST be skipped; a pre-order already at the target marker MUST be reported as already done and keep its dates.
- **FR-019**: Marker changes MUST NOT affect stock, payments, status or notifications and MUST record each pre-order's own dates by the same rules as the individual action.

**Add payment**

- **FR-020**: The user MUST enter shared payment details once (method, channel when the method requires one, reference, notes) and see one row per selected pre-order with current paid amount, outstanding balance and an editable amount defaulting to the outstanding balance.
- **FR-021**: Each payment MUST be recorded against its own pre-order with its own amount; paid amount, outstanding balance and payment status MUST be updated exactly as for an individually recorded payment (including automatic status effects of becoming fully paid).
- **FR-022**: A payment MUST be refused (pre-order skipped with the reason) when its amount is zero, negative, or exceeds the outstanding balance, or when the pre-order is already fully paid or cancelled; overpayment MUST never occur.
- **FR-023**: Missing required payment information (such as a channel for a non-cash method) MUST be refused before anything is recorded.
- **FR-024**: A confirmation MUST show the number of payments, the total amount and each pre-order's resulting payment status before anything is saved.
- **FR-025**: Repeating the same payment request MUST NOT create duplicate payments.

**Create shipments**

- **FR-026**: The user MUST enter shared shipping details once (such as courier and shared notes) and see one editable row per selected pre-order showing its own recipient (name, phone, address) and an optional individual tracking number.
- **FR-027**: One shipment record MUST be created per eligible pre-order, with that pre-order's own recipient details and tracking number; shipments MUST NOT be shared between pre-orders.
- **FR-028**: Pre-orders that are not Mail Order, already have a shipment, are cancelled, or lack required recipient information MUST be skipped with the specific reason; an existing shipment MUST NEVER be overwritten or duplicated.
- **FR-029**: Pre-orders with missing recipient information MUST be flagged in the review list before confirmation so the user can correct them.

**Feedback and recovery**

- **FR-030**: Before executing, the user MUST see a confirmation summary of the action, the target values and the counts (to be processed / to be skipped).
- **FR-031**: While an action runs, the user MUST see a progress indicator and MUST NOT be able to submit it a second time.
- **FR-032**: When finished, the user MUST see a result summary: total selected, succeeded, skipped, failed (adding up exactly to the selection), and a reason per skipped or failed pre-order, each with a way to open that pre-order.
- **FR-033**: The user MUST be able to retry the skipped/failed pre-orders in one click; already-successful pre-orders MUST NOT be processed again.
- **FR-034**: After an action, the list and all dependent indicators (status, paid amounts, progress markers, summary cards) MUST refresh without a manual reload.
- **FR-035**: Technical details (database errors, file paths) MUST never be shown to the user; unexpected failures are reported with a generic reason.

**Audit**

- **FR-036**: Every pre-order changed by a bulk action MUST get the same audit entry as the individual action, written together with the change, including user, time and old/new values.
- **FR-037**: Every audit entry created by a bulk action MUST be traceable to the bulk operation (who, when, which action, how many pre-orders) and be viewable by owners and admins (in the existing Activity Log).
- **FR-038**: A skipped, failed or rolled-back pre-order MUST have no entry claiming a change.

**Experience**

- **FR-039**: The bulk UI MUST follow the existing design system, work at phone width, be keyboard accessible, and be available in both supported interface languages.

### Key Entities *(include if feature involves data)*

- **Bulk Operation**: One user-initiated batch: who, when, which action (status / invoice progress / shipping progress / payment / shipments), the shared settings chosen, how many pre-orders were selected, succeeded, skipped and failed. Used for traceability and the result summary.
- **Bulk Item Result**: For each selected pre-order within a Bulk Operation: succeeded, skipped (reason) or failed (reason), plus the change applied.
- **Pre-order** *(existing)*: Status, payment totals, invoice/shipping progress marker, fulfillment (Self Pickup / Mail Order), customer. Changed only through the same rules as the individual actions.
- **Payment** *(existing)*: One entry per payment against one pre-order; bulk creates ordinary payments.
- **Shipment** *(existing)*: At most one per Mail Order pre-order with its own recipient and tracking number; bulk creates ordinary shipments.
- **Audit Entry** *(existing activity log)*: Same entry as the individual action, additionally linked to the Bulk Operation.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can move 50 pre-orders to a new status in under 1 minute from selecting them to seeing the result, versus changing them one at a time before.
- **SC-002**: A user can record payments for 50 pre-orders (shared details entered once, default amounts) in under 2 minutes.
- **SC-003**: A user can create shipment records for 30 Mail Order pre-orders, with individual tracking numbers, in under 3 minutes.
- **SC-004**: For 100% of tested batches, succeeded + skipped + failed equals the number selected, and every skipped or failed pre-order shows a reason in plain words.
- **SC-005**: For 100% of tested batches, each pre-order's resulting status, paid amount, outstanding balance, marker or shipment is identical to what the corresponding individual action would have produced.
- **SC-006**: Repeating or double-submitting any bulk action produces 0 duplicate payments, 0 duplicate shipments and no shifted marker dates in testing.
- **SC-007**: A bulk action on a mixed selection never changes a pre-order that the individual action would refuse (invalid transition, overpayment, existing shipment, wrong fulfillment), in 100% of tested cases.
- **SC-008**: 0 bulk changes succeed for a user without the matching individual permission, including crafted requests with pre-orders they cannot see.
- **SC-009**: Every pre-order changed in bulk is traceable to a person, time, action and bulk operation in 100% of tested cases; skipped/failed ones have no false entries.
- **SC-010**: After any bulk action the list shows the correct new state for 100% of the processed pre-orders without a manual reload.

## Assumptions

- **Scope is the Pre-orders list** (not POS sales). Existing bulk actions on that list (download documents, email invoices, duplicate) keep working and share the same selection.
- **"Invoice progress" and "shipping progress" mean the existing manual progress marker** on each pre-order (not sent yet / invoice sent / shipping). Generating invoice documents is already available as the existing bulk download. Updating the delivery status of an existing shipment record (for example delivered) is out of scope for this version.
- **Status moves follow the existing lifecycle** (Ordered → Deposit paid → Goods arrived → Settled → Handed over, with Cancelled reachable from earlier steps); bulk adds no new states or shortcuts.
- **Payments follow the existing payment rules**: exact amount up to the outstanding balance, no overpayment, non-cash needs a channel, verification is a separate later step. **Amended at planning (2026-11-08) after reading the existing payment flow:** a payment is always dated at the moment it is recorded (the individual flow has no editable payment date), and a proof attaches to exactly ONE payment, so bulk payment does not take a payment date or a proof — a proof can be added to each payment afterwards, as today. Backdating payments would change a shared money rule and is a separate decision. Different amounts per pre-order are supported; the default is each pre-order's outstanding balance.
- **Shipments follow the existing rule** of one shipment per Mail Order pre-order with required recipient name, phone and address; recipient details default from each pre-order's own customer/shipping information, and a pre-order without them is skipped until fixed.
- **Partial success, not all-or-nothing across the batch**: each pre-order is all-or-nothing, but a problem on one never blocks the rest — consistent with other bulk actions already on the Sales and Pre-orders lists.
- **Select all matching the filters** means all pre-orders that match the current filters across pages, up to the same per-action limit used by other bulk actions (assumed 200, final value decided at planning); beyond the limit the user narrows the filters.
- **Notifications** on status change keep their existing behaviour and never block or revert the change.
- **Permissions**: each bulk action requires the same permission as its individual action; users who lack it do not see it. Seller-scoped users act only on pre-orders visible to them.
- **Retry is user-driven** (one click re-selects the failed/skipped); there is no automatic background retry.
- **Interface languages**: English and Indonesian, as elsewhere in the app.
