# Feature Specification: Transaction Approval & Seller Audit

**Classification**: INTERNAL

**Feature Branch**: `095-seller-transaction-approval`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Transaction Approval & Seller Audit — sellers audit the items assigned to them in a transaction and formally approve them (drawn signature, uploaded image, or an APPROVED stamp). One unique seller = one required approval; the transaction's overall approval status (Pending / Partially Approved / Fully Approved) is calculated from the individual seller approvals, shown in the Sales list (filter + status icon + popover) and in the transaction detail, with a full audit trail."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A seller approves their items in a transaction (Priority: P1)

A seller (a user linked to one seller) opens a transaction that contains their items, reviews what is recorded against them (items, quantities, amounts), and approves them by drawing a signature, uploading a signature image, or pressing "Stamp Approved". Before the approval is saved they are asked to confirm. Afterwards the transaction detail shows that seller as **Approved**, with who approved, when, and how.

**Why this priority**: This is the core of the feature. With only this story a single-seller transaction can already be audited and signed off, which is a usable MVP.

**Independent Test**: Open a transaction with items from one seller as that seller's user, approve it with each of the three methods (on three different transactions), and confirm each shows Approved with the correct approver, time, method and (where applicable) image.

**Acceptance Scenarios**:

1. **Given** a completed transaction whose items all belong to Seller A and a user linked to Seller A, **When** the user opens the detail, **Then** an Approval section shows Seller A as "Pending Approval" with the three approval methods available, and nothing is approved just by viewing.
2. **Given** the same transaction, **When** the user draws a signature (mouse, touch, or touchpad) and confirms, **Then** Seller A becomes "Approved", the drawn signature is stored and shown, and the overall transaction status becomes "Fully Approved (1/1 Sellers Approved)".
3. **Given** a pending seller approval, **When** the user uploads a PNG or JPG signature image and confirms, **Then** the approval is recorded with method "Upload Image" and the image is shown.
4. **Given** a pending seller approval, **When** the user chooses "Stamp Approved" and confirms, **Then** the approval is recorded with method "Stamp", showing the predefined APPROVED stamp together with the seller's name and the approver's identity, without any drawing or upload.
5. **Given** a user who has opened the approval dialog, **When** they cancel or close it without confirming, **Then** no approval is recorded.
6. **Given** a seller who has already approved, **When** they (or anyone) try to approve the same seller again in the same approval cycle, **Then** the request is refused and the existing approval is unchanged.
7. **Given** a successful approval, **When** it is saved, **Then** the user sees clear confirmation, and a double click or a retried request does not create a second approval.

---

### User Story 2 - Multi-seller transactions need one approval per seller (Priority: P1)

A transaction with items from several sellers lists each unique seller as a separate approval requirement. Each seller approves only their own items; the overall status and progress ("2/3 Sellers Approved") follow automatically.

**Why this priority**: Multi-seller sales are the normal case at multi-artist booths, and the "one unique seller = one approval" rule is the primary business principle of the request.

**Independent Test**: Create a sale with 4 items from 3 sellers (two items from the same seller). Confirm 3 approval requirements exist, approve them one at a time as each seller, and verify the status goes Pending (0/3) → Partially Approved (1/3, 2/3) → Fully Approved (3/3).

**Acceptance Scenarios**:

1. **Given** a transaction with Indomie Goreng and Es Teh (Seller A), Kopi Susu (Seller B), Roti Bakar (Seller C), **When** the Approval section is opened, **Then** exactly three seller approval rows are shown (A: two items, B: one item, C: one item), not four.
2. **Given** Seller A and Seller B have approved and Seller C has not, **When** the transaction is viewed, **Then** the overall status is "Partially Approved" with progress "2/3 Sellers Approved" and Seller C is "Pending Approval".
3. **Given** the above, **When** Seller C approves, **Then** the overall status becomes "Fully Approved (3/3 Sellers Approved)".
4. **Given** a user linked to Seller A, **When** they open a transaction that also contains Seller B's items, **Then** they can approve only Seller A's items; Seller B's approval offers no action to them, and an attempt to approve for Seller B (even by crafting the request) is refused.
5. **Given** no seller has approved, **When** the transaction is viewed, **Then** the overall status is "Pending Approval" with "0/N Sellers Approved".
6. **Given** a seller approves, **When** the status is recalculated, **Then** other sellers' approvals are neither created nor changed.

---

### User Story 3 - See and filter approval status in the Sales list (Priority: P2)

On the Sales list every transaction shows a small approval icon (clock = pending, partial-progress = partially approved, green check = fully approved). Clicking it opens a popover with the overall status, the number of sellers (total / approved / pending), the progress, and per-seller details (approver name, time, method). The list also gets an Approval filter: All, Pending Approval, Partially Approved, Fully Approved.

**Why this priority**: It makes approval work manageable at volume (find what still needs approving), but approvals already work without it.

**Independent Test**: With sales in each of the three states, apply each filter and confirm only matching sales appear, with search, sorting and pagination still working and the filter combinable with the existing ones; click each icon and check the popover content.

**Acceptance Scenarios**:

1. **Given** sales in all three overall states, **When** the user selects "Partially Approved", **Then** only sales whose overall status is Partially Approved remain (a sale where one seller approved out of three qualifies; a sale where just its single seller approved does not).
2. **Given** an Approval filter and a seller filter, a date filter and a search term are set, **When** the list is shown, **Then** all filters apply together, the summary line, selection, export and page count reflect the filtered rows, and the choice survives a page reload through the address like the other filters.
3. **Given** the list, **When** the user clicks a transaction's approval icon, **Then** a popover shows e.g. "Transaction Approval: Partially Approved — Seller A: Approved, Seller B: Approved, Seller C: Pending — Progress: 2/3 Sellers Approved", and for each approved seller the approver's name, timestamp and method.
4. **Given** the icon, **When** the user hovers or focuses it, **Then** a short tooltip with the status is available, and the popover is also reachable by keyboard and closes with Escape.
5. **Given** the list at normal density, **When** the icons are added, **Then** they are small and do not push or wrap the existing columns.

---

### User Story 4 - Changes after approval invalidate only the affected seller (Priority: P2)

If what a seller approved no longer matches the transaction (a line is added, removed, changed, or moved to another seller), that seller's approval stops counting and they must approve again. Other sellers' approvals are untouched. The old approval stays on record.

**Why this priority**: Protects the integrity of the audit; lower priority than the approval itself because sale lines are currently fixed after checkout, so this is a safeguard against future or exceptional changes.

**Independent Test**: Approve a 2-seller transaction fully, then change one seller's lines through any supported path; confirm only that seller returns to Pending (new cycle), the other stays Approved, the overall status drops to Partially Approved, and the earlier approval is still visible in history.

**Acceptance Scenarios**:

1. **Given** a fully approved 2-seller transaction, **When** a line of Seller B is changed, **Then** Seller B shows Pending (re-approval required), Seller A remains Approved, overall status is "Partially Approved (1/2)".
2. **Given** a line moves from Seller A to Seller B, **When** the requirements are recalculated, **Then** both sellers' approvals are invalidated (both lists of items changed), and the required-seller count follows the new set of unique sellers.
3. **Given** the last line of a seller is removed, **When** recalculated, **Then** that seller no longer requires approval (its old approval remains in history) and the progress denominators update.
4. **Given** an invalidated approval, **When** the seller approves again, **Then** a new approval is recorded as a new cycle and the earlier one stays as superseded history.
5. **Given** a sale is voided, **When** its approval state is viewed, **Then** it is shown as voided, no new approvals can be recorded, and existing approvals stay on record unchanged.

---

### User Story 5 - Audit trail and protected records (Priority: P3)

Owners and admins can see the complete history of approval activity for a transaction: who approved what for which seller, by which method, when, and any invalidation or re-approval. Approval records cannot be edited or deleted from the application.

**Why this priority**: Required for the audit purpose, but it adds value on top of the approvals themselves.

**Independent Test**: Approve, invalidate and re-approve; check the history lists every event in order, and confirm there is no way (UI or request) to alter or remove an approval record.

**Acceptance Scenarios**:

1. **Given** approval activity on a transaction, **When** an owner/admin opens its approval history, **Then** every approval, invalidation (with reason) and re-approval is listed with actor, seller, method and time.
2. **Given** an approved seller, **When** anyone tries to edit or delete that approval (UI or direct request), **Then** it is refused; there is no "undo approval" in this feature.
3. **Given** the signature or uploaded image of an approval, **When** a user who is not allowed to see it requests the file, **Then** it is refused, and only those allowed (see FR-014) can view it.

---

### Edge Cases

- **Legacy sales with no approval records**: shown as "Pending Approval (0/N)" derived from their lines; nothing is created or changed until someone approves. They appear under the Pending filter.
- **Sale with items of a single seller**: one requirement; approving it makes the sale "Fully Approved (1/1)" (no "Partially Approved" is possible).
- **Sale with no seller on a line** (e.g. a seller later deleted): the line's recorded seller is still counted; a deleted seller can still be approved on behalf by an owner/admin.
- **Two users linked to the same seller**: either may approve, but only one approval exists per seller per cycle; the second attempt is refused as "already approved".
- **Two people approve the same seller at the same moment**: exactly one succeeds; the other gets the "already approved" refusal.
- **Owner/admin approving on behalf of a seller**: allowed; the record clearly shows seller and approver as different people, and the stamp shows both.
- **Seller-scoped user viewing a mixed sale**: sees the overall status and every seller's status (the sale is visible whole), but can act only on their own seller.
- **Invalid uploads**: wrong type, over the size limit, or an empty/blank drawing are refused with a clear message and nothing is recorded.
- **Voided sale**: no approval requirements are actionable; existing records are preserved; it is excluded from the Approval filter results unless voided sales are shown, in which case it displays as "Voided".
- **Stale screen**: if the sale's lines or approvals changed since the page was opened, the approval is refused with a message to reload, rather than approving something different from what the user reviewed.
- **Offline / failed save**: a failed approval request shows an error and leaves the seller Pending; retrying never produces two approvals.
- **Viewing never approves**: opening a transaction, the list, or the popover never creates or changes an approval.

## Requirements *(mandatory)*

### Functional Requirements

**Approval requirements and status**

- **FR-001**: For every POS transaction the system MUST derive one approval requirement per **unique seller** among its items; multiple items of one seller share one requirement, so the number of requirements equals the number of unique sellers, never the number of items.
- **FR-002**: Each seller approval MUST be an independent record linked to the transaction and the seller; no approval status or signature is stored as a single field on the transaction itself.
- **FR-003**: Each seller's status MUST be either **Pending Approval** or **Approved**.
- **FR-004**: The overall transaction status MUST be calculated automatically from the seller records: no approvals → **Pending Approval**; some but not all → **Partially Approved**; all → **Fully Approved**. It MUST NOT be settable independently of those records.
- **FR-005**: The system MUST expose the progress as "X/N Sellers Approved" wherever overall status is shown, and number of approved and pending sellers.
- **FR-006**: Transactions that existed before this feature MUST work without migration of data: they appear as Pending Approval with requirements derived from their items, and approval records are created only when someone approves.

**Approving**

- **FR-007**: The transaction detail MUST contain an Approval section listing each required seller with their items, status, and (when approved) approver, timestamp, method and image/stamp.
- **FR-008**: A seller's approval MUST support three methods: **Draw Signature** (mouse, touch, touchpad; works on desktop and Android), **Upload Image** (PNG or JPG signature), and **Stamp Approved** (one action, predefined "APPROVED" stamp tied to the seller and approver identity).
- **FR-009**: Approval MUST only happen through an explicit user action followed by a confirmation step; viewing never approves. Confirming a blank drawing or an invalid upload MUST be refused.
- **FR-010**: Each approval record MUST capture: seller (name and id), approver (name and id), timestamp, method, the signature/approval image where applicable, and a record of what was approved (the seller's items, quantities and amounts at that moment).
- **FR-011**: A seller MUST be able to approve only once per approval cycle; repeated, double-submitted or concurrent attempts MUST result in exactly one approval and a clear refusal for the others.
- **FR-012**: If the transaction's items for that seller differ from what the approver reviewed (stale screen), the approval MUST be refused with an instruction to reload.

**Who may approve and see**

- **FR-013**: A user linked to a seller MAY approve only that seller's requirement; owner/admin roles MAY approve on behalf of any seller; all other users MUST NOT approve. Approval MUST also be controllable as a named permission within the Sales menu so a role can be allowed or denied it (existing per-menu action permissions apply additively).
- **FR-014**: Signature and approval image files MUST be visible only to owner/admin, the approver, and users linked to that seller; other users who can see the transaction MUST see only that the seller approved (method, approver, time) without the image.
- **FR-015**: Every approval request MUST be validated server-side for permission, seller ownership of the items, transaction state (not voided), cycle state (not already approved) and content freshness; nothing relies on what the browser claims.

**Sales list**

- **FR-016**: The Sales list MUST show a small approval status icon per transaction (pending / partial / fully approved) and an Approval filter with All, Pending Approval, Partially Approved, Fully Approved, which filters by **overall** transaction status.
- **FR-017**: The filter MUST work together with all existing filters, search, sorting and pagination, MUST cover all matching rows (not just the visible page) for the summary, selection and export, and MUST be restored from the address like the other filters.
- **FR-018**: Clicking the icon MUST open a popover with overall status, total/approved/pending seller counts, progress, and per-seller details including approver name, timestamp and method; it MUST be keyboard-accessible and visually consistent with existing popovers/tooltips.
- **FR-019**: The icon and popover MUST NOT load any signature image in the list.

**Changes, history and integrity**

- **FR-020**: When a transaction's items change after approval (added, removed, modified, or moved to another seller), the system MUST recalculate the set of required sellers and invalidate only the approvals of sellers whose items changed, requiring re-approval; unaffected sellers' approvals stay valid.
- **FR-021**: Invalidated and superseded approvals MUST be kept as history, never overwritten or deleted; a re-approval is a new record in a new cycle.
- **FR-022**: A voided transaction MUST accept no new approvals; its existing approval records MUST be preserved.
- **FR-023**: All approval activity (approve, invalidate, re-approve, refused attempts at the permission/ownership level where practical) MUST be written to the activity log in the same all-or-nothing operation as the change it records, so a rolled-back approval leaves no log claiming it happened.
- **FR-024**: Approval records MUST NOT be editable or deletable through the application; no "undo approval" exists in this feature.
- **FR-025**: Owner/admin MUST be able to view a transaction's full approval history (all cycles).
- **FR-026**: Uploaded and drawn images MUST be stored privately and served only through an authorizing request; the upload MUST be type-checked from its content (PNG/JPG) and size-limited.

**Experience**

- **FR-027**: The approval UI MUST follow the existing design system, work at phone width, make individual seller status visually distinct from overall transaction status, and be available in both supported interface languages.

### Key Entities *(include if feature involves data)*

- **Seller Approval**: One approval record for one seller on one transaction in one cycle. Holds: the transaction, the seller, status (pending/approved/invalidated), approver, approval time, method (drawn / uploaded / stamp), the approval image (if any), a record of the seller's items/quantities/amounts that were approved, and an invalidation reason and time when superseded. Many cycles per (transaction, seller) over time; at most one active (non-invalidated) at a time.
- **Approval Requirement (derived)**: The unique sellers of a transaction's items at a given moment. Not stored separately; calculated from the transaction's items so it can never drift from them.
- **Overall Approval Status (derived)**: Pending / Partially Approved / Fully Approved plus the approved/total counts, calculated from the requirements and active approvals.
- **Approval History Event**: An entry in the existing activity log for each approval, invalidation and re-approval.
- **Transaction / Transaction Item / Seller / User** *(existing)*: A seller's items are those whose recorded seller is that seller; a user may be linked to one seller.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A seller can find a transaction awaiting their approval and complete an approval with any of the three methods in under 1 minute, and the stamp method in under 15 seconds.
- **SC-002**: For 100% of tested transactions (1, 2 and 3+ sellers, with repeated items of the same seller) the number of approval requirements equals the number of unique sellers and the overall status/progress matches the seller records.
- **SC-003**: In testing, 0 approvals can be recorded for a seller by a user who is neither that seller's linked user nor an owner/admin, including by crafted requests.
- **SC-004**: Rapid double submits and simultaneous approvals of the same seller always produce exactly one approval.
- **SC-005**: A user can list "everything still needing approval" with one filter choice, and results always match the overall status of each transaction (0 mismatches in testing across all three states).
- **SC-006**: Every approval, invalidation and re-approval is traceable to a person and time; 100% of tested change scenarios keep prior approvals as history.
- **SC-007**: Drawing a signature works on a desktop browser (mouse and touchpad) and on an Android phone/tablet (touch) in at least one real-device check.
- **SC-008**: Existing transactions without approval data load and filter correctly with no data conversion, and the Sales list remains as responsive as before (no noticeable slowdown at the existing list sizes).

## Assumptions

- **Scope is POS transactions** (the Sales page). Pre-orders have their own screen and are out of scope for this feature.
- **A "seller" is the existing seller (artist) recorded on each transaction item.** The user-to-seller link from the seller-scoped access feature (058) identifies who may approve for that seller; one user is linked to at most one seller.
- **Owner/admin may approve on behalf of any seller** as the "appropriate permissions" holder; this is the assumed meaning of the request's "users with appropriate permissions". Approval is also exposed as a named permission on the Sales menu so it can be granted or withheld per role.
- **Cashiers and other roles not linked to a seller cannot approve** unless granted through the Sales approval permission AND linked to the seller.
- **Transaction lines are currently fixed after checkout** (the only change today is voiding the whole sale), so invalidation (User Story 4) mainly protects future or exceptional changes; the rules must still be implemented and tested so any later change path inherits them.
- **No withdrawal/undo of an approval** is included; correcting an approval happens only via the invalidation rules above.
- **"Stamp Approved" is not a signature**: it is a recorded one-click confirmation by an authenticated user and the stamp artwork is fixed (not per-store customizable) in this version.
- **Signature images are small** (5 MB maximum per file, JPEG/PNG only) and shown at reduced size; no generated thumbnails.
- **Approval does not change money, stock, reports or seller settlements**; it is an audit overlay only.
- **Existing settlement signature (drawn/uploaded) pad** is a reasonable base to reuse for the drawing method, subject to confirming it fits at planning time.
- **Approval images are not included in backups yet**, same known limitation as other private evidence files; to be noted at planning, not solved here.
