# Feature Specification: Bulk Approve Transactions

**Classification**: INTERNAL

**Feature Branch**: `094-bulk-approve-transactions`

**Created**: 2026-11-08

**Status**: Draft

**Input**: User description: "in transaction list, add function to bulk approve using draw signature, upload image, stamp"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Approve many transactions at once with one stamp (Priority: P1)

A seller (or an owner/admin acting on behalf of sellers) is on the Sales list with many transactions waiting for approval. They select several transactions (checkbox selection, or "Select all" on a filtered list such as Approval = Pending), choose **Bulk approve**, pick **Stamp Approved**, read a confirmation that states how many transactions and seller approvals will be recorded, and confirm. Every seller approval they are allowed to give on those transactions is recorded in one go, and a result summary says what was approved and what was skipped and why.

**Why this priority**: The stamp is the fastest method and removes the main pain: approving each sale one at a time. With only this story, a seller can already clear a day's worth of sales in seconds, which is a usable MVP.

**Independent Test**: As a user linked to Seller A, with 10 pending sales containing Seller A's items, select all 10, bulk approve with Stamp, confirm, and verify all 10 show Seller A as Approved (method Stamp, approver, time) and the overall status/progress of each sale updated.

**Acceptance Scenarios**:

1. **Given** the Sales list with 10 selected transactions in which Seller A is pending, **When** a user linked to Seller A chooses Bulk approve → Stamp Approved and confirms, **Then** Seller A's requirement on each of the 10 transactions becomes Approved with method "Stamp", and each transaction's overall status and "X/N Sellers Approved" are recalculated.
2. **Given** the bulk dialog is open, **When** the user cancels or closes it without confirming, **Then** no approval is recorded.
3. **Given** the confirmation step, **When** it is shown, **Then** it names the method, the number of transactions, the number of seller approvals that will be recorded, and the seller(s) and total amount they cover, before anything is saved.
4. **Given** a confirmed bulk approval, **When** it finishes, **Then** a result summary shows the count approved and the count skipped, with a reason per skipped transaction, and the Sales list refreshes to the new statuses.
5. **Given** a double click on Confirm or a retried request, **When** it is processed, **Then** no seller ends up with two active approvals on any transaction.

---

### User Story 2 - Bulk approve with one drawn signature or one uploaded image (Priority: P1)

Instead of the stamp, the user draws a signature once (mouse, touch or touchpad) or uploads one PNG/JPG signature image once, and that same signature is applied as the approval of every seller requirement included in the bulk action. Each resulting approval is its own record showing the signature.

**Why this priority**: The request explicitly asks for all three methods. Some sellers or shops require a visible signature rather than a stamp, so this is as essential as the stamp.

**Independent Test**: Select 5 pending sales, bulk approve once with a drawn signature, and once (on 5 other sales) with an uploaded PNG; open any sale from each batch and verify its approval shows that signature with method "Draw Signature" / "Upload Image".

**Acceptance Scenarios**:

1. **Given** selected pending transactions, **When** the user chooses Draw Signature, draws, and confirms, **Then** every included seller approval is recorded with method "Draw Signature" and displays the drawn signature.
2. **Given** selected pending transactions, **When** the user uploads a valid PNG or JPG and confirms, **Then** every included approval is recorded with method "Upload Image" and displays the image.
3. **Given** a blank drawing, an unsupported file type, or a file over the size limit, **When** the user tries to confirm, **Then** it is refused with a clear message and nothing is recorded.
4. **Given** a drawn or uploaded signature in the dialog, **When** the user moves to the confirmation step and back, **Then** the signature is still there and does not need to be redrawn.
5. **Given** one signature applied to many sales, **When** an approved sale is opened, **Then** the signature is visible only to those allowed to view approval images (owner/admin, the approver, users linked to that seller), exactly as for single approvals.

---

### User Story 3 - Only eligible approvals are recorded; the rest are skipped and explained (Priority: P1)

A bulk selection often contains transactions that cannot or need not be approved by this user: already approved, voided, belonging only to other sellers, or changed since the list was loaded. The bulk action approves what is allowed and skips the rest without failing the whole batch, and it tells the user exactly what happened.

**Why this priority**: Without safe skipping, bulk approval would either be blocked by one bad row or would approve things the user must not. It protects the integrity guarantees of single approvals.

**Independent Test**: Select a mix: pending, already approved, voided, and a sale with only another seller's items. As Seller A's user, run a bulk approval and verify only Seller A's pending requirements are recorded, and the summary lists each skipped sale with a reason.

**Acceptance Scenarios**:

1. **Given** a selected transaction where this user's seller has already approved, **When** bulk approving, **Then** it is skipped with reason "already approved" and its existing approval is unchanged.
2. **Given** a selected voided transaction, **When** bulk approving, **Then** it is skipped with reason "voided" and no approval is recorded.
3. **Given** a user linked to Seller A and a selected transaction containing no Seller A items, **When** bulk approving, **Then** it is skipped with reason "no approval available for you".
4. **Given** a mixed sale (Seller A and Seller B items), **When** Seller A's user bulk approves, **Then** only Seller A's requirement is recorded; Seller B stays Pending and the sale becomes Partially Approved.
5. **Given** an owner/admin with several pending sellers on the selected sales, **When** they bulk approve, **Then** every pending seller requirement on those sales is approved on behalf of its seller, each record showing seller and approver as different people.
6. **Given** a sale whose items changed after the list loaded (so what the user saw is no longer what exists), **When** bulk approving, **Then** that sale is skipped with a "changed, reload" reason and is not approved.
7. **Given** a user without approval permission, **When** they look at the Sales list, **Then** the Bulk approve action is not offered, and a crafted bulk request is refused with no state revealed or changed.
8. **Given** a selection where nothing is eligible, **When** the user opens Bulk approve, **Then** they are told before confirming that there is nothing to approve, and no approval is possible.

---

### User Story 4 - Select what to approve conveniently and safely (Priority: P2)

Users can build the selection in the ways the Sales list already supports: tick individual rows, tick all rows on the page, or select all rows matching the current filters (all pages). The Approval filter (Pending / Partially Approved / Fully Approved) is the natural way to build a "everything still needing my approval" selection. The size of one bulk action is bounded so it stays fast and reviewable.

**Why this priority**: It makes the feature practical at event volume, but a manual selection already works without it.

**Independent Test**: Filter the list to Approval = Pending for an event, use Select all, open Bulk approve, and confirm the dialog counts match the filtered rows; then try a selection over the limit and confirm a clear message.

**Acceptance Scenarios**:

1. **Given** the Sales list filtered to Pending, **When** the user selects all and opens Bulk approve, **Then** the confirmation counts cover all filtered rows, not only the visible page.
2. **Given** a selection larger than the per-action limit, **When** the user opens Bulk approve, **Then** they are told the limit and asked to reduce the selection; nothing is recorded.
3. **Given** a seller-scoped user, **When** they use Select all, **Then** the selection and the counts only involve sales visible to them.
4. **Given** the user changes the selection or filters while the dialog is closed, **When** they reopen Bulk approve, **Then** it reflects the current selection.

---

### User Story 5 - Bulk approvals are fully traceable (Priority: P2)

Each seller approval created in bulk is an ordinary approval record, indistinguishable in integrity from a single one, and is also traceable to the bulk action that created it (who, when, how many, which method). Owner/admin can see this in the approval history of each transaction, and approvals cannot be edited or deleted.

**Why this priority**: The feature exists for audit sign-off; speed must not weaken traceability. It builds on the records the other stories create.

**Independent Test**: Bulk approve 3 sales, then open each sale's approval history as owner/admin and verify each shows the approval, actor, seller, method, time and that it came from a bulk action; verify no edit/delete exists.

**Acceptance Scenarios**:

1. **Given** a completed bulk approval, **When** an owner/admin opens a transaction's approval history, **Then** the entry shows seller, approver, method, time and that it was part of a bulk action.
2. **Given** bulk approved records, **When** anyone tries to edit or delete one, **Then** it is refused, as for single approvals.
3. **Given** a bulk action in which some transactions are skipped, **When** history is viewed, **Then** skipped transactions have no approval entries and nothing claims they were approved.

---

### Edge Cases

- **Mixed eligibility in one batch**: some approved, some skipped; the summary always distinguishes the two and the counts add up to the selection.
- **Failure on one transaction mid-batch** (e.g. it changed or was voided in the meantime): that transaction is skipped or reported failed with a reason; transactions already approved in the batch stay approved; no transaction is left half-approved.
- **Concurrent approval**: if someone else approves the same seller on a transaction while the bulk runs, that transaction is skipped as "already approved"; no duplicate approval is created.
- **Same user, same sale, two seller requirements** (owner/admin): both approved in the same action, each as its own record.
- **Legacy sales with no approval records**: approved normally; records are created only for the sales actually approved.
- **Network drop or timeout during a large batch**: the user sees the batch outcome after reload (approved sales show Approved); retrying the same batch never creates duplicates.
- **Selection includes sales no longer visible** (filters changed, mode switched between DEMO/LIVE): those are ignored/skipped, never approved.
- **Very large uploaded image or very detailed drawing**: handled within the same file limits as a single approval; refused clearly if exceeded.
- **User leaves the dialog after drawing**: nothing is recorded and the signature is discarded.
- **Viewing never approves**: opening the dialog, the confirmation step or the result summary never creates an approval by itself.

## Requirements *(mandatory)*

### Functional Requirements

**Starting a bulk approval**

- **FR-001**: The Sales list MUST offer a **Bulk approve** action that works on the current selection of transactions, next to the other selection-based actions, and only to users who may approve at all.
- **FR-002**: The action MUST work with the existing selection options (individual rows, all rows on the page, all rows matching the current filters), including selections that span several pages.
- **FR-003**: One bulk action MUST be limited to a maximum number of transactions; a larger selection MUST be refused with a message stating the limit, before anything is recorded.
- **FR-004**: Before confirming, the user MUST be shown the number of transactions and seller approvals that will be recorded, the sellers involved and the total amount covered, and MUST be told when nothing is eligible.

**Methods**

- **FR-005**: The bulk action MUST support the same three methods as single approval: **Draw Signature** (mouse, touch, touchpad; desktop and Android), **Upload Image** (PNG or JPG), and **Stamp Approved**.
- **FR-006**: The user chooses one method and provides the signature or image once; the same signature or image is applied to every seller approval recorded by that bulk action. Each approval is stored as its own record.
- **FR-007**: A blank drawing, an invalid or oversized upload MUST be refused with a clear message before anything is recorded. File rules (types, size, private storage, content checking) MUST be identical to single approval.

**Eligibility and results**

- **FR-008**: A bulk action MUST record a seller approval only where the same user could record it individually: a user linked to a seller approves only that seller's requirements; owner/admin approve on behalf of any pending seller; all other users approve nothing. The existing Sales approval permission applies additively.
- **FR-009**: For each selected transaction the system MUST skip, not fail the batch, when: the seller requirement is already approved; the sale is voided; the user has no approvable requirement on it; the sale is not visible to the user; or the sale's items changed since the user's list was loaded. Each skipped transaction MUST carry a machine- and human-readable reason.
- **FR-010**: Approval of one transaction MUST be all-or-nothing for that transaction and independent of the others: a problem on one transaction MUST NOT roll back or block the approvals already recorded for others, and MUST NOT leave any transaction partially approved.
- **FR-011**: When the user is owner/admin, one bulk action MUST approve every pending seller requirement on each selected transaction, each on behalf of its seller, and each record MUST show seller and approver separately.
- **FR-012**: After the action the user MUST see a result summary: approved count, skipped count with reasons grouped and per transaction, and the Sales list MUST refresh so statuses, icons, popovers, filters and counters reflect the new state.
- **FR-013**: The summary counts MUST add up exactly to the selection size (approved + skipped = selected), with no transaction counted twice or omitted.

**Integrity and safety**

- **FR-014**: Approval MUST only happen after an explicit confirmation step; opening the dialog or viewing results MUST NOT approve anything.
- **FR-015**: Each approval created in bulk MUST satisfy every integrity rule of single approval: one active approval per seller per transaction cycle; bound to the content of that seller's items at approval time; refused for voided sales; no edit, delete or undo; private image storage with authorized access only.
- **FR-016**: Repeated, double-submitted, retried or concurrent bulk requests MUST never produce more than one active approval per seller per transaction; a repeated identical request MUST be safe (already-approved items are skipped).
- **FR-017**: All permission, seller ownership, voided state, cycle state and content freshness checks MUST be enforced on the server for every transaction in the batch; nothing relies on what the browser claims, including the list of selected transactions.
- **FR-018**: Bulk approval MUST NOT change money, stock, reports or seller settlements; it is an audit overlay only, like single approval.

**Traceability**

- **FR-019**: Each approval created in bulk MUST be traceable to the bulk action (who, when, method, how many transactions) in addition to its own approval record, and MUST appear in the transaction's approval history for owner/admin.
- **FR-020**: Activity logging for each approval MUST be written in the same all-or-nothing operation as the approval it records, so a rolled-back approval leaves no log claiming it happened.

**Experience**

- **FR-021**: The bulk approve dialog MUST follow the existing design system, work at phone width, be keyboard accessible, preserve the drawn/uploaded signature between the choice step and the confirmation step, show progress while processing, prevent double submit, and be available in both supported interface languages.

### Key Entities *(include if feature involves data)*

- **Bulk Approval Action**: One user-initiated batch: who ran it, when, the method chosen, how many transactions were selected, approved and skipped. Links to the seller approvals it created. Used for traceability only; it has no effect on money or stock.
- **Seller Approval** *(existing, feature 059)*: One record per seller, transaction and cycle, with approver, time, method, image (if any) and the content approved. Bulk creates ordinary Seller Approvals.
- **Approval Result (per transaction)**: For each selected transaction in a bulk action: approved (with which sellers) or skipped (with reason). Shown to the user in the result summary.
- **Transaction / Seller / User** *(existing)*: Unchanged. A user may be linked to one seller; owner/admin may act on behalf of any seller.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A seller can approve 50 pending transactions with the stamp in under 30 seconds from opening the Bulk approve dialog to seeing the result, versus one transaction at a time before.
- **SC-002**: A seller can approve 50 pending transactions with one drawn signature or one uploaded image in under 1 minute.
- **SC-003**: In testing, 0 approvals are recorded for a seller by a user who is neither that seller's linked user nor an owner/admin, including by crafted bulk requests containing transactions the user cannot see or approve.
- **SC-004**: For 100% of tested batches, approved + skipped equals the number of selected transactions, and every skipped transaction shows a reason.
- **SC-005**: Rapid double submits, retried batches and simultaneous bulk actions by two users always produce exactly one active approval per seller per transaction.
- **SC-006**: For 100% of tested batches mixing eligible, already-approved, voided and other-seller transactions, only the eligible approvals are recorded and no other transaction changes.
- **SC-007**: Every bulk-created approval is traceable to a person, time, method and bulk action; owner/admin can see this in 100% of tested transactions.
- **SC-008**: Drawing a signature for a bulk approval works on a desktop browser (mouse and touchpad) and on an Android phone/tablet (touch) in at least one real-device check.
- **SC-009**: After a bulk action, the Sales list's statuses, icons, Approval filter results and counters match each transaction's true state with 0 mismatches in testing.

## Assumptions

- **Builds on feature 059 (Transaction Approval & Seller Audit)**: the three methods, one-approval-per-seller rule, content binding, privacy of images, append-only records and permissions all come from it; this feature adds a batch entry point, not new approval rules.
- **Scope is POS transactions** on the Sales list; pre-orders remain out of scope, as in 059.
- **One signature or image per bulk action is applied to all included approvals.** This mirrors how an approver would sign a stack of sheets; each approval still records who and when. A seller who needs a separate signature per sale can use single approval.
- **Owner/admin bulk approval covers every pending seller** on the selected sales (on behalf), rather than letting them pick sellers; filtering the selection (e.g. by seller) is the way to narrow it. If narrowing by seller inside the dialog is wanted, that can be added later.
- **Partial success, not all-or-nothing, across a batch**: skipping ineligible transactions with reasons follows the existing bulk "mark verified" behaviour on the Sales list; each individual transaction is all-or-nothing.
- **Freshness**: the list the user sees is treated as the basis of the approval; a transaction whose seller items changed after the list loaded is skipped rather than approved. Transaction lines are currently fixed after checkout, so this rarely triggers today but must hold for future change paths.
- **Per-action limit**: a bound on transactions per bulk action (assumed in the order of 200, consistent with other bulk actions in the Sales list; exact value decided at planning).
- **Stamp is not a signature**: as in 059, it is a recorded one-click confirmation by an authenticated user with fixed artwork.
- **Approval does not change money, stock, reports or settlements**, and bulk approval images are not in backups yet (same known limitation as 059).
- **Interface languages**: English and Indonesian, as elsewhere in the app.
