# Feature Specification: Sales Payment Proof Visibility & List Column Refinements

**Classification**: INTERNAL

**Feature Branch**: `092-sales-payment-proof-column`

**Created**: 2026-11-04

**Status**: Draft

**Input**: User description: "in transaction details, show the payment proof thumbnail, in transaction list, change "Reference" to "Sale Reference" and add "Payment Proof", show the Payment verified status, switch column "Margin" and "Total"", then: "add filter sale reference"; then: "add toggle to show or hide all sale reference and payment proof in transaction list; update the export to excel to include complete data from the transaction list, including sale reference and payment proof image; when filter by seller, the statistic only counts that seller's items (gross and net sales), not the total transaction"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the payment proof right in the transaction detail (Priority: P1)

A cashier or owner opens a POS sale's detail from the Sales page (or from a customer's history). For every non-cash payment that has a proof (transfer receipt, QRIS screenshot), they want to see a small preview picture of that proof directly in the payment history, instead of only a "View proof" text link, so they can confirm at a glance that the right proof is attached. Clicking the thumbnail opens it full size.

**Why this priority**: This is the core ask and the main daily value: checking money received against its evidence without extra clicks.

**Independent Test**: Open a sale that has a non-cash payment with an attached proof; its payment entry shows a thumbnail, and clicking it opens the full-size proof. A sale with no proof shows no thumbnail and the existing "No proof yet" cue.

**Acceptance Scenarios**:

1. **Given** a sale with a non-cash payment that has a proof the viewer may see, **When** the detail opens, **Then** that payment entry shows a thumbnail of the proof.
2. **Given** a visible thumbnail, **When** the viewer clicks it, **Then** the full-size proof opens (same viewer as today's "View proof").
3. **Given** a payment with no proof, **When** the detail opens, **Then** no thumbnail is shown and the "No proof yet" indication remains.
4. **Given** a proof the viewer is not allowed to see, **When** the detail opens, **Then** no thumbnail (and no image data) is revealed to that viewer.
5. **Given** a sale paid in cash, **When** the detail opens, **Then** no proof area appears.
6. **Given** the proof file cannot be loaded, **When** the thumbnail is requested, **Then** a neutral "unavailable" placeholder is shown instead of a broken image, and the rest of the detail still works.

---

### User Story 2 - Reach the payment proof from the transaction list (Priority: P1)

On the Sales list, next to each transaction number, the cashier sees a "Payment Proof" link for sales that have a viewable payment proof, alongside the (renamed) "Sale Reference" link. Clicking it shows the proof(s) without opening the full detail.

**Why this priority**: Lets staff audit many transactions quickly from the list; pairs with Story 1 and is explicitly requested.

**Independent Test**: In the list, a sale with a proof shows "Payment Proof" under its number; a sale without one does not. Clicking it displays the proof.

**Acceptance Scenarios**:

1. **Given** a sale with at least one payment proof the viewer may see, **When** the list renders, **Then** a "Payment Proof" link appears under the transaction number.
2. **Given** a sale with no proof (or only cash), **When** the list renders, **Then** no "Payment Proof" link appears.
3. **Given** a sale with several payments that each have a proof, **When** the link is used, **Then** every viewable proof is reachable (each labelled with its payment method/amount).
4. **Given** a sale with both a sale reference and a payment proof, **When** the list renders, **Then** both links appear, each clearly distinguishable by its label and icon.
5. **Given** the list is large, **When** it loads, **Then** it does not load any proof image up front; images load only when the link is opened.

---

### User Story 3 - Rename "Reference" to "Sale Reference" (Priority: P2)

The existing transaction-reference link in the list is relabelled "Sale Reference" so it is not confused with the payment reference/proof now sitting next to it.

**Why this priority**: Pure clarity change, required to avoid ambiguity once Story 2 adds a second link.

**Independent Test**: The list link reads "Sale Reference" (Indonesian: equivalent) and still opens the same reference dialog.

**Acceptance Scenarios**:

1. **Given** a sale that has a reference, **When** the list renders, **Then** the link reads "Sale Reference" in the active language.
2. **Given** the link is clicked, **Then** behaviour is unchanged from today (opens the transaction reference dialog).

---

### User Story 4 - Show "Payment verified" status in the list (Priority: P2)

Today the Payment column flags only payments needing attention (not verified / rejected). Staff also want to see positively when a sale's non-cash payments are verified, so a glance tells verified from unchecked.

**Why this priority**: Completes the payment-evidence picture alongside the proof; smaller than the proof work.

**Independent Test**: A sale whose non-cash payments are all verified shows a "Verified" indicator in the Payment column; one with any unverified payment still shows "Not verified"; cash-only sales show neither.

**Acceptance Scenarios**:

1. **Given** a sale whose non-cash payments are all verified, **When** the list renders, **Then** the Payment column shows a "Verified" status.
2. **Given** a sale with any non-cash payment still pending, **Then** the existing "Not verified" status shows (not "Verified").
3. **Given** a sale with any rejected payment, **Then** the existing rejected status shows (rejected outranks the others, as today).
4. **Given** a cash-only or unpaid sale, **Then** no verification status is shown.
5. **Given** the "Needs verification" filter and the bulk "Mark verified" action, **Then** they behave exactly as today.

---

### User Story 5 - Swap the "Margin" and "Total" columns (Priority: P3)

For owner/admin, the Sales table currently shows Margin before Total. The two swap: Total comes first, then Margin.

**Why this priority**: Layout-only preference.

**Independent Test**: As owner/admin the header order ends "… Payment, Total, Margin, (actions)"; as cashier (no Margin) Total is the last data column.

**Acceptance Scenarios**:

1. **Given** an owner/admin viewing Sales, **Then** the Total column appears immediately before the Margin column.
2. **Given** a cashier (who never receives margin), **Then** there is no Margin column and nothing else changes.
3. **Given** sorting by either column, **Then** sorting still works on the correct data after the swap.

---

### User Story 6 - Filter the Sales list by Sale Reference (Priority: P2)

A cashier or owner wants to narrow the Sales list to sales that have a Sale Reference (note and/or images), or to those that still lack one, for example to find sales whose reference was never filled in, or to review only the referenced ones.

**Why this priority**: Makes the Sale Reference actionable at list level; builds on the existing list filters and the already-known "has reference" marker, so it is small and independent.

**Independent Test**: In the Sales filters choose "Has Sale Reference": only sales showing the "Sale Reference" link remain. Choose "No Sale Reference": only sales without it remain. Clear it: the full list returns.

**Acceptance Scenarios**:

1. **Given** the Sales list with mixed sales, **When** the user selects "Has Sale Reference", **Then** only sales that have a reference (note and/or at least one image) are listed.
2. **Given** the same list, **When** the user selects "No Sale Reference", **Then** only sales without a reference are listed.
3. **Given** an active reference filter, **When** the user clears it (or picks "All"), **Then** the list is no longer restricted by reference.
4. **Given** the reference filter combined with other filters (event, cashier, seller, payment, date, search), **Then** results satisfy all of them (AND across filters, as today).
5. **Given** an active reference filter, **Then** the summary line, the four summary cards, "select all", export and pagination all cover exactly the filtered rows, and the page resets to the first page.
6. **Given** the filter is set and the page is reloaded or the address is shared, **Then** the filter is restored from the address like the other Sales filters; an unknown value is ignored, not an error.
7. **Given** a voided sale that has a reference, **Then** it follows the same rule and appears only when voided sales are shown.

---

### User Story 7 - Show or hide every Sale Reference and Payment Proof at once (Priority: P2)

A cashier or owner reviewing many sales wants one switch that reveals the Sale Reference (note and image thumbnails) and the Payment Proof thumbnails of every listed sale directly in the transaction list, and the same switch hides them again, instead of opening each sale's dialog one by one.

**Why this priority**: Turns per-row look-ups into a single review pass (e.g. checking that every transfer has its proof), but the list must stay light by default.

**Independent Test**: Open the Sales list: reference/proof details are collapsed (only the links). Turn the toggle on: each row on the page shows its reference note + image thumbnails and its payment proof thumbnails. Turn it off: back to links only.

**Acceptance Scenarios**:

1. **Given** the toggle is off (default), **Then** the list looks and loads exactly as without the toggle (links only, zero proof/reference images loaded).
2. **Given** the toggle is turned on, **Then** every row on the current page shows, under its transaction number, its Sale Reference (note and image thumbnails) and its viewable Payment Proof thumbnails; rows with neither show nothing extra.
3. **Given** the toggle is on, **When** the user clicks a thumbnail, **Then** the full-size viewer opens (gallery per sale), as for the links.
4. **Given** the toggle is on and the user changes page, sorts, filters or changes page size, **Then** the new rows follow the toggle and only the rows on screen load images.
5. **Given** the toggle is turned off, **Then** all expanded content collapses at once.
6. **Given** a viewer without permission to see a proof, **Then** that proof never appears, with the toggle on or off (same server rule as FR-008).
7. **Given** the page is reloaded, **Then** the toggle keeps its last position for that viewer on that device (not shared with other users).

---

### User Story 8 - Export the full transaction list to Excel, with images (Priority: P1)

An owner or cashier exporting the Sales list to Excel wants the file to contain everything the list shows (including Sale Reference and Payment Proof, with the actual images embedded), so the file can be used for checking and archiving without returning to the app.

**Why this priority**: The current export omits most of what the list shows, so the file cannot stand in for the screen.

**Independent Test**: Export a filtered selection that contains a sale with a Sale Reference (note + images) and a sale with a viewable payment proof. The workbook has one row per sale, a column for every list column the viewer sees, the reference note as text, and the reference and proof images embedded in that sale's row.

**Acceptance Scenarios**:

1. **Given** an export of the current view (or the selected rows), **Then** the workbook carries every column visible in the list for this viewer: transaction number, status, time, customer, seller(s), cashier, items, units, discount, payment methods, payment status, verification status, cash, non-cash, total, paid, balance, and (only for users who see it) cost and margin, in the same order as on screen (Total before Margin).
2. **Given** sales with a Sale Reference, **Then** the file carries the reference note as text and the reference images embedded in that sale's row.
3. **Given** sales with payment proofs the exporter is permitted to see, **Then** the current proof image of each non-cash payment is embedded in that sale's row, identified by its payment (method and amount); proofs the exporter may not see are absent, with no placeholder that hints at them.
4. **Given** an image that is missing, unreadable or not an image file (e.g. a PDF proof), **Then** the export still succeeds and that cell states the proof exists but could not be embedded.
5. **Given** a very large selection, **Then** the export either completes or is refused with a clear message stating the limit; it never produces a partial file without saying so.
6. **Given** the seller filter is on, **Then** the exported units/gross/net columns follow User Story 9.
7. **Given** the export, **Then** no customer phone/email is added (unchanged rule) and cost/margin appear only for users who see them on screen.

---

### User Story 9 - Seller filter statistics count only that seller's items (Priority: P1)

When the Sales list is filtered by seller, the owner wants every statistic to describe only that seller's part of the sales: units, Gross Sales and Net Sales must sum only the items belonging to the selected seller(s), not the whole transactions that merely contain one of their items.

**Why this priority**: Figures that include other sellers' items are misleading for seller reviews and settlements.

**Independent Test**: Take a sale with one item from seller A (Rp 50.000) and one from seller B (Rp 70.000). Filter by seller A: the summary cards and summary line count Rp 50.000 (gross/net of A's item only) and A's unit count, not Rp 120.000.

**Acceptance Scenarios**:

1. **Given** a seller filter, **Then** the Units, Gross Sales and Net Sales cards and the "Showing X of Y" summary count only the selected seller(s)' items, for all filtered rows across all pages.
2. **Given** a sale containing items of the selected and of other sellers, **Then** only the selected sellers' items contribute; a sale with no item of the selected sellers is not listed.
3. **Given** several sellers selected, **Then** the figures are the sum of those sellers' items, with no item counted twice.
4. **Given** no seller filter, **Then** the figures are the whole-sale figures as today.
5. **Given** the seller filter combined with the other filters, **Then** the statistics follow exactly the filtered rows.
6. **Given** a seller filter, **Then** the Excel export's units/gross/net columns carry the same seller-scoped values as the cards, so file and screen agree.
7. **Given** a voided sale, **Then** it stays excluded from all statistics as today.

---

### Edge Cases

- A sale with a superseded (replaced) proof: only the current proof is shown as the thumbnail; older versions stay available to those already allowed to see them via the detail.
- A voided sale with proofs: thumbnails/links still appear (audit), marked as the sale is today.
- A proof added or replaced from the detail while the list is open: the list reflects it after its next refresh/close of the dialog.
- A viewer without permission to see proofs: neither the list link nor the thumbnail appears for them (no placeholder that hints at hidden content).
- Many payments with proofs on one sale: thumbnails wrap/scroll without breaking the detail layout.
- Very narrow columns: the two links under the transaction number stack and never overlap or truncate the label.
- Receipts are unchanged. The Excel export is expanded by User Story 8; filters (incl. the reference filter) still decide which rows are exported.
- Toggle on with 100 rows per page: images load progressively, only for rows on screen; a slow or failed image shows a placeholder without blocking the list.
- Export with many images (e.g. hundreds of sales with several proofs each): bounded by a stated limit and memory-safe; the user is told if the limit is hit.
- A sale mixing sellers with the seller filter on: its Total, cash and non-cash columns stay whole-sale figures (a payment is not split per seller); only units/gross/net are seller-scoped.
- A reference added or removed from the list's reference dialog while the filter is on: the row enters or leaves the filtered list after the list refreshes.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The transaction detail MUST show a thumbnail of the current payment proof on each non-cash payment entry that has one the viewer is allowed to see.
- **FR-002**: Clicking a proof thumbnail MUST open the full-size proof using the existing proof viewer.
- **FR-003**: The detail MUST NOT show a thumbnail for cash payments, payments without a proof, or proofs the viewer is not permitted to see; the existing "No proof yet" cue MUST remain for non-cash payments lacking one.
- **FR-004**: A thumbnail that cannot be loaded MUST degrade to a neutral placeholder without affecting the rest of the detail.
- **FR-005**: The Sales list MUST show a "Payment Proof" link under the transaction number for every sale having at least one payment proof the viewer is permitted to see, and MUST NOT show it otherwise.
- **FR-006**: Using the "Payment Proof" link MUST let the viewer see every viewable proof of that sale, each identifiable by its payment (method and amount).
- **FR-007**: The list MUST know which sales have a viewable proof without loading any proof image; images MUST load only when requested.
- **FR-008**: The visibility of proofs (list link and detail thumbnail) MUST be decided by the server using the existing proof-viewing permission rules; the interface MUST NOT infer permission from the user's role.
- **FR-009**: The existing list link "Reference" MUST be relabelled "Sale Reference" in every supported language, with unchanged behaviour.
- **FR-010**: The list's Payment column MUST show a "Verified" status when the sale has non-cash payments and all of them are verified; "Not verified" and rejected statuses MUST keep their current meaning and precedence (rejected > not verified > verified).
- **FR-011**: Cash-only and unpaid sales MUST show no verification status.
- **FR-012**: For users who see the Margin column, the Total column MUST be placed immediately before it; for users without Margin, the table is otherwise unchanged.
- **FR-013**: Sorting, filtering, selection, pagination and bulk actions on the Sales page MUST be unaffected by this feature (the export and the seller-scoped statistics are changed deliberately by FR-022 to FR-027).
- **FR-014**: All new visible text MUST exist in both supported interface languages.
- **FR-015**: The feature MUST NOT change any stored payment, proof, verification or reference data, nor how proofs are uploaded, replaced or permitted.
- **FR-016**: The Sales filters MUST offer a Sale Reference filter with three choices: all (default, no restriction), has Sale Reference, and no Sale Reference. A sale "has" a reference when it carries a note and/or at least one image.
- **FR-017**: The reference filter MUST combine with the other Sales filters using the same rules (AND across filters), reset pagination to page 1, and drive the summary line, summary cards, select-all and export over exactly the filtered rows.
- **FR-018**: The reference filter state MUST be kept in the page address like the other Sales filters (restored on reload, ignored when invalid).
- **FR-019**: The Sales list MUST offer one toggle, default off, that shows or hides at once the Sale Reference (note and image thumbnails) and the viewable Payment Proof thumbnails of every row on the current page.
- **FR-020**: With the toggle off, the list MUST load no reference or proof image (SC-003 unchanged); with it on, only rows currently on the page load images, progressively, and a failed image degrades to a placeholder.
- **FR-021**: The toggle MUST NOT change what a viewer is allowed to see (proof permission per FR-008), and its position MUST be remembered per viewer on their device only.
- **FR-022**: The Sales Excel export MUST contain every column the viewer sees in the list, in list order, plus the verification status, one row per sale.
- **FR-023**: The export MUST include each sale's Sale Reference note as text and its reference images embedded in the sale's row.
- **FR-024**: The export MUST embed the current proof image of each non-cash payment the exporter is permitted to see, labelled with the payment's method and amount; non-permitted proofs MUST be omitted without trace.
- **FR-025**: An image that cannot be embedded (missing, unreadable, non-image) MUST NOT fail the export; the cell MUST state it could not be embedded. Exports exceeding the supported size MUST be refused with a clear message, never silently truncated.
- **FR-026**: With a seller filter active, the Units, Gross Sales and Net Sales statistics (cards and summary line) MUST count only the items of the selected seller(s); without it they MUST count whole sales. The same seller-scoped values MUST appear in the export.
- **FR-027**: Cost and margin MUST appear in the export only for users who see them on screen; no customer phone/email may be exported.

### Key Entities

- **Payment Proof**: A photo/file attached to a non-cash payment as evidence; one current proof per payment (older ones are superseded, not deleted); viewable only by permitted users.
- **Payment (of a sale)**: Method, amount, verification state (pending / verified / rejected), optionally a current proof.
- **Sale (POS transaction)**: Groups its payments; the list row summarises them (methods, verification state, and now whether a viewable proof exists).
- **Sale Reference**: The free-form note and images attached to the sale itself (separate from payment proofs).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can confirm a payment's proof from the sale detail in one step (open detail, see thumbnail) with no extra click.
- **SC-002**: A user can reach a sale's payment proof from the Sales list in at most one click.
- **SC-003**: With a list of 100 sales on screen, the page shows no more proof-image downloads than before the feature until a proof link is used (zero up-front proof images).
- **SC-004**: 100% of sales whose non-cash payments are all verified display "Verified" in the list, and 0% of sales with a pending or rejected payment do.
- **SC-005**: Users without permission to view proofs see no proof link or thumbnail in 100% of cases.
- **SC-006**: In every language, no "Reference" label remains in the list where "Sale Reference" is intended, and the Total column precedes Margin for 100% of owner/admin views.
- **SC-007**: Opening the Sales page takes no noticeably longer than before (list load time within 10% of current for the same data).
- **SC-008**: With "Has Sale Reference" selected, 100% of listed sales show the "Sale Reference" link; with "No Sale Reference" selected, 0% do.
- **SC-009**: Turning the toggle on shows reference and proof content for 100% of rows on the page that have any (and nothing for those without), and turning it off restores the collapsed list in one action; with it off the page loads zero such images.
- **SC-010**: An exported workbook contains 100% of the columns the viewer sees on screen, and every viewable reference/proof image of the exported sales is embedded or explicitly marked as not embeddable.
- **SC-011**: With a seller filter on, the Units, Gross Sales and Net Sales equal the sum of only that seller's items for 100% of tested mixed-seller sales, and the exported values match the cards.

## Assumptions

- The two screenshots supplied with the request could not be viewed here; "Payment Proof" is assumed to follow the same look as the existing "Sale Reference" link (icon + text under the transaction number) and the thumbnail the same look as the existing sale-reference thumbnails. To be confirmed against the screenshots at planning.
- "Payment verified status" means showing a positive "Verified" pill in the Payment column for sales whose non-cash payments are all verified; the per-payment verified-by/time detail already exists in the detail dialog and is unchanged.
- A sale with a mix of verified and pending non-cash payments is shown as "Not verified" (the worst state wins, consistent with today's rule).
- The "Payment Proof" link opens the proof(s) in a lightweight viewer (gallery when several); it does not open the full detail.
- Proof permission rules (owner/admin, uploader, or the payment's recorder for the current proof) are reused as-is; this feature adds no new permission.
- Cash payments have no proof; Pre-order screens are out of scope (the Sales page lists POS transactions only).
- "Filter sale reference" is interpreted as filtering by whether a sale has a Sale Reference (has / has none). Searching inside the reference note text is NOT included; it can be added later if wanted.
- The reference filter needs no new data per row: whether a sale has a reference is already known to the list.
- Bilingual UI (English/Indonesian) is required, as everywhere else in the product.
- No customer personal data is added to the list or any export by this feature.
- ASSUMPTION (toggle): "show or hide all sale reference and payment proof" is read as one list-level switch that expands the reference note/thumbnails and proof thumbnails inline on every row of the current page (not a per-row control, and not a column hide). Confirm if a different behaviour was meant.
- ASSUMPTION (export): "complete data from the transaction list" means every column the list shows for that viewer plus the reference note and embedded images; exports stay one row per sale with images placed in that row. Existing export gating (cost/margin, no phone/email) is kept. A proof that is a PDF (not an image) is not embedded.
- Seller-scoped statistics: the list already scopes units, gross and net to the selected seller's items (feature 054); this story pins that as a requirement, extends it to the export, and its first task is to reproduce the reported behaviour against real mixed-seller data, because the requester still observed whole-transaction figures. The transaction count counts sales that contain at least one item of the selected seller.
- Proof and reference images are financial evidence: the export file inherits the INTERNAL classification and should be handled as such.
