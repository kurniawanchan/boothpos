**Classification: INTERNAL**

# Feature Specification: POS Layout Refresh, Sales Page Upgrades, Sales Reports and Cashier Session List

**Feature Branch**: `085-pos-layout-sales-reports`

**Created**: 2026-11-01

**Status**: Draft

**Input**: User description: "In POS: (1) when a product has variants, do not close the picker after choosing an item, add a close button at the bottom so several variants can be chosen before closing; (2) move Save as draft and Draft Transactions to the top of the cart area; (3) seller filter as horizontally scrollable pill buttons including an All sellers pill, placed next to the search box, search box about half as wide; (4) category filter as pill buttons below the search box. In Sales detail: change payment method; add a transaction reference (text note or image) because a transaction may first be captured through other media such as a WhatsApp photo and entered as a sale later; view the reference from a link in the table; transaction number and total on one line; statistics must match the active filters; the All events filter must be selectable; pagination on the table; download all receipts. In Reports: sales by product with a best-seller mark and search by product name; sales by payment method with a transaction list. Show a list of cashier sessions with open, close and transaction details."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pick several variants in one go (Priority: P1)

A cashier taps a product that has variants. The variant picker opens. Today it closes as soon as one variant is chosen, so a customer who wants three variants of the same product forces the cashier to reopen the picker three times. The picker must stay open after each choice, let the cashier add more variants (or more of the same variant), and close only when the cashier presses a Close button at the bottom of the picker (or the existing close control at the top).

**Why this priority**: It is the most frequent POS action at a busy booth and directly saves taps per sale.

**Independent Test**: Open a product with at least three variants, tap two different variants, confirm both are in the cart and the picker is still open, then press the bottom Close button and confirm the picker closes and the cart is unchanged.

**Acceptance Scenarios**:

1. **Given** the variant picker is open for a product with several variants, **When** the cashier taps one variant, **Then** that variant is added to the cart, the picker stays open, and the cashier gets clear feedback that it was added (for example the variant shows how many are in the cart).
2. **Given** the picker is open and one variant was already added, **When** the cashier taps a second variant, **Then** both variants are in the cart and the picker is still open.
3. **Given** the picker is open, **When** the cashier presses the Close button at the bottom, **Then** the picker closes and the cart keeps everything that was added.
4. **Given** a variant has no stock left, **When** the cashier taps it in the picker, **Then** it is not added, the existing out-of-stock behaviour applies, and the picker stays open.
5. **Given** a product has exactly one variant, **When** the cashier taps it, **Then** behaviour is unchanged from today (added directly, no picker).

---

### User Story 2 - Draft actions at the top of the cart (Priority: P1)

"Save as draft" and "Draft Transactions" currently sit beside the search box. They belong to the sale in progress, so they move to the top of the cart area, next to the cart title.

**Why this priority**: It frees room for the new search and filter layout (Stories 3 and 4) and puts draft controls where the cart is.

**Independent Test**: Open the POS screen and confirm both actions appear at the top of the cart area and no longer appear beside the search box; use each and confirm they behave as before.

**Acceptance Scenarios**:

1. **Given** the POS screen is open, **When** the cashier looks at the cart area, **Then** "Save as draft" and "Draft Transactions" are visible at its top and are absent from the product-search row.
2. **Given** the cart is empty, **When** the cashier looks at "Save as draft", **Then** it is shown disabled (or otherwise clearly unavailable) exactly as today, while "Draft Transactions" stays usable.
3. **Given** the cart has items, **When** the cashier presses "Save as draft" and later opens "Draft Transactions", **Then** the draft saves and can be resumed exactly as before.
4. **Given** a narrow window (tablet), **When** the cart area is shown, **Then** both actions remain reachable without overlapping the cart title or the Clear action.

---

### User Story 3 - Seller pills next to a smaller search box (Priority: P2)

The seller filter changes from a dropdown to a row of pill buttons, starting with an "All sellers" pill. The row scrolls horizontally when the sellers do not fit. It sits on the same row as the search box, and the search box becomes roughly half as wide as it is now.

**Why this priority**: One tap replaces open-dropdown-then-choose, and sellers become visible at a glance. It is a layout improvement, not a new capability.

**Independent Test**: With more sellers than fit on screen, confirm the pills appear beside the search box, scroll sideways, "All sellers" is first and active by default, and tapping a seller filters the product grid.

**Acceptance Scenarios**:

1. **Given** the POS screen is open, **When** it loads, **Then** the seller pills show beside the search box with "All sellers" first and selected.
2. **Given** more sellers than the visible width, **When** the cashier swipes or scrolls the pill row sideways, **Then** the remaining sellers are reachable, and the search box and the rest of the page do not move.
3. **Given** a seller pill is tapped, **When** the product grid refreshes, **Then** only that seller's products show and that pill is visibly selected.
4. **Given** a seller is selected, **When** the cashier taps "All sellers", **Then** the seller filter clears.
5. **Given** the search box is narrower, **When** the cashier types a product name or SKU, **Then** search works exactly as today, including pressing Enter to add an exact SKU.
6. **Given** the previous seller filter allowed more than one seller, **When** the pills replace it, **Then** the number of sellers that can be selected at once is preserved (see Assumptions).

---

### User Story 4 - Category pills below the search box (Priority: P2)

The category filter changes from a dropdown to pill buttons placed below the search box, with an "All categories" pill first.

**Why this priority**: Same quick-tap improvement as Story 3, for categories.

**Independent Test**: Confirm category pills appear on their own row below the search box, "All categories" is active by default, and tapping a category filters the grid, combined with any selected seller.

**Acceptance Scenarios**:

1. **Given** the POS screen is open, **When** it loads, **Then** category pills show below the search box with "All categories" first and selected.
2. **Given** a seller and a category are both selected, **When** the grid refreshes, **Then** only products matching both show.
3. **Given** more categories than fit, **When** the cashier scrolls the row sideways, **Then** the remaining categories are reachable.
4. **Given** a category is selected, **When** the cashier taps "All categories", **Then** the category filter clears.

---

### User Story 5 - Sales statistics, event filter and pagination that agree (Priority: P1)

On the Sales page, the summary figures (transaction count, units, gross and net sales and similar) must always describe exactly the transactions matching the current filters, not the whole data set and not just the current page. The event filter's "All events" choice must be selectable (today it cannot be chosen). The table gets pagination so long lists stay usable.

**Why this priority**: Figures that disagree with the filtered list are a correctness problem, and an unselectable "All events" blocks the user from undoing an event filter.

**Independent Test**: Apply an event, cashier and date filter; confirm the summary equals the sum of the filtered rows across all pages; choose "All events" and confirm the filter clears; confirm paging through the table shows every filtered row once.

**Acceptance Scenarios**:

1. **Given** filters are applied, **When** the summary figures show, **Then** they equal the totals of all transactions matching the filters (across every page).
2. **Given** an event is selected, **When** the cashier chooses "All events", **Then** the event filter clears and the list and summary include every event.
3. **Given** more transactions than one page, **When** the user moves between pages, **Then** each filtered transaction appears exactly once and the page controls show current page and total pages.
4. **Given** the user changes a filter or the sorting, **When** results refresh, **Then** the table returns to the first page and the summary updates.
5. **Given** the filters match nothing, **When** the page renders, **Then** the summary shows zeros and the table shows an empty state, with no stale figures from earlier filters.
6. **Given** the filter state is already carried in the address bar today, **When** the page number is changed, **Then** reloading or sharing the address restores the same page and filters.

---

### User Story 6 - Transaction number and total on one line (Priority: P2)

In the Sales table, the transaction number and the total amount each stay on a single line instead of wrapping.

**Why this priority**: Wrapped numbers make rows tall and hard to scan; small visual fix.

**Independent Test**: Load the Sales table at a typical laptop width and confirm no transaction number or total wraps onto a second line.

**Acceptance Scenarios**:

1. **Given** the Sales table at a typical laptop width, **When** rows render, **Then** every transaction number and every total shows on one line.
2. **Given** a very narrow window, **When** the table renders, **Then** the table scrolls sideways rather than wrapping those two values.

---

### User Story 7 - Change a sale's payment method (Priority: P2)

From the Sales detail, an authorised user can correct the payment method of a recorded payment, for example from cash to a QRIS or bank transfer channel, when it was recorded wrongly.

**Why this priority**: Wrong payment methods are a real, common data-entry mistake that currently cannot be fixed, and they distort the new payment-method report (Story 11).

**Independent Test**: Open a sale paid in cash, change the payment to a transfer channel, and confirm the detail, the list, the payment-method report and the shift cash figures all reflect the change; confirm the change is recorded in the activity log.

**Acceptance Scenarios**:

1. **Given** a completed sale with a recorded payment, **When** an authorised user changes its method (and channel where the new method needs one), **Then** the payment shows the new method everywhere it appears.
2. **Given** a payment changed to a non-cash method, **When** the change is saved, **Then** it needs verification again like any other non-cash payment, and a channel is required.
3. **Given** a cash payment received in a shift that is already closed, **When** a user tries to change its method, **Then** the change is refused with a clear reason, because it would break that shift's closed reconciliation.
4. **Given** a voided sale, **When** a user tries to change a payment method, **Then** it is refused.
5. **Given** a successful change, **When** the activity log is viewed, **Then** it records who changed what from which method to which method and when.
6. **Given** the change affects only the method (and channel), **When** it is saved, **Then** the amount, the sale total, the paid and balance figures and the sale status are unchanged.
7. **Given** a user without permission, **When** they open the Sales detail, **Then** the change action is not offered and a direct attempt is refused.

---

### User Story 8 - Transaction reference (note or image) with link in the table (Priority: P2)

A shop sometimes captures a sale first through other media (a photo sent over WhatsApp, a handwritten note) and enters the real sale afterwards. The user can attach a reference to a sale: a text note, an image, or both. The Sales table shows a link when a reference exists; following it shows the note and the image.

**Why this priority**: It lets staff trace a recorded sale back to its original evidence, which reduces disputes and re-checking.

**Independent Test**: Open a sale, add a text note and an image, save, then in the table confirm a reference link appears on that row and opens the note and image; confirm a sale without a reference shows no link.

**Acceptance Scenarios**:

1. **Given** a sale without a reference, **When** an authorised user adds a text note and/or an image in the Sales detail and saves, **Then** the sale now has a reference.
2. **Given** a sale with a reference, **When** the Sales table shows that row, **Then** a link or indicator for the reference is visible, and rows without a reference show none.
3. **Given** the reference link, **When** an authorised user follows it, **Then** they see the note and can view the image without leaving the Sales page.
4. **Given** a reference exists, **When** an authorised user edits it, **Then** the note can change and the image can be replaced or removed, and the change is recorded in the activity log.
5. **Given** the user attaches a file that is not an accepted image, or is too large, **When** they save, **Then** it is rejected with a clear message and nothing is stored.
6. **Given** a user who is not allowed to view references, **When** they use the Sales page, **Then** no reference content or link is exposed, and the image cannot be fetched by guessing its address.
7. **Given** a reference is added or changed, **When** the sale is viewed, **Then** amounts, status, stock and reports are unchanged.

---

### User Story 9 - Download all receipts (Priority: P3)

The user can download the receipts for all transactions matching the current Sales filters in one action, instead of opening each receipt.

**Why this priority**: Useful for end-of-event bookkeeping and sending records to sellers, but not needed for day-to-day selling.

**Independent Test**: Filter to a known set of sales, use "Download all receipts", and confirm the file contains exactly one receipt per matching sale and nothing else.

**Acceptance Scenarios**:

1. **Given** filters match N transactions, **When** the user chooses "Download all receipts", **Then** they get one downloadable file containing N receipts, one per transaction, identifiable by transaction number.
2. **Given** voided sales are hidden by the current filters, **When** receipts are downloaded, **Then** voided sales are not included; when voided sales are shown, they are included and clearly marked.
3. **Given** a large selection, **When** the download runs, **Then** the user sees progress and can tell when it finished, and a failure on one receipt is reported without silently dropping it.
4. **Given** nothing matches the filters, **When** the user looks at the action, **Then** it is unavailable or explains that there is nothing to download.
5. **Given** the receipts are produced, **When** the user opens one, **Then** it matches the receipt shown for that sale today (store identity, items, totals, payments) and contains no customer phone or email beyond what the existing receipt shows.

---

### User Story 10 - Sales by product report with best-seller mark (Priority: P2)

A new report lists sales per product (units sold, sales value) for a chosen period or event, ranks them, and marks the best sellers. The user can search the report, and the transactions behind a product, by product name.

**Why this priority**: It answers "what sells" for restocking and seller conversations, which no current report does directly.

**Independent Test**: With known sales across several products, open the report, confirm per-product units and sales match the underlying transactions, confirm the top sellers carry the mark, search by a product name, and open the transaction list behind one product.

**Acceptance Scenarios**:

1. **Given** sales exist in the chosen period or event, **When** the report opens, **Then** each product shows units sold and sales value, sorted with the best seller first.
2. **Given** the report, **When** products are ranked, **Then** the top sellers carry a visible "Best seller" mark, and the rule for which products count as best sellers is stated on the screen.
3. **Given** the user types part of a product name, **When** the search applies, **Then** only matching products (and their transactions) are shown and totals follow the match.
4. **Given** a product row, **When** the user opens it, **Then** the list of transactions that contain it appears, with transaction number, date, quantity and amount, and each links to the sale.
5. **Given** voided sales, **When** the report is built, **Then** they are excluded from figures and rankings.
6. **Given** a product has variants, **When** the report is built, **Then** sales are grouped by product and the variant breakdown is visible without changing the product total.
7. **Given** no sales match, **When** the report opens, **Then** an empty state is shown instead of zeros posing as a ranking.
8. **Given** the report is in the Reports area, **When** a user without access to reports tries to open it, **Then** access is denied exactly as for the other reports, and cost or margin figures are not shown to anyone without the existing profit permission.

---

### User Story 11 - Sales by payment method report with transaction list (Priority: P2)

A new report totals sales by payment method (cash, each non-cash channel) for a chosen period or event, with the number of transactions and amount per method, and lets the user open the list of transactions behind any method.

**Why this priority**: It supports reconciliation against bank, QRIS and cash totals.

**Independent Test**: With payments recorded across cash and two channels, open the report, confirm per-method counts and amounts equal the underlying payments, open one method and confirm the list matches.

**Acceptance Scenarios**:

1. **Given** payments exist in the chosen period or event, **When** the report opens, **Then** each method (and channel) shows its transaction count and amount received, plus a grand total.
2. **Given** a method row, **When** the user opens it, **Then** the list of transactions paid by that method appears, with number, date, cashier, amount paid by that method, and a link to the sale.
3. **Given** a sale paid with two methods (split payment), **When** the report is built, **Then** each method counts only its own portion of the amount, and the sale appears under both methods' lists.
4. **Given** a cash payment that included change, **When** the report is built, **Then** the cash figure is the amount kept (tendered minus change), consistent with the Sales page.
5. **Given** a payment whose method was corrected (Story 7), **When** the report is built, **Then** it appears under the corrected method only.
6. **Given** voided sales and rejected payments, **When** the report is built, **Then** they are excluded.
7. **Given** payments still not verified, **When** the report is built, **Then** the user can see how much of each non-cash method is verified and how much is not.

---

### User Story 12 - Cashier session list with details (Priority: P2)

A screen lists cashier sessions (shifts). Each entry shows who opened it, when it opened and closed, opening cash, and its status. Opening a session shows the opening and closing details (opening cash, expected and counted closing cash, difference) and the list of transactions made during it.

**Why this priority**: Owners need to review shifts and trace cash differences back to transactions.

**Independent Test**: Open the list with several open and closed sessions, open one closed session and confirm its opening/closing figures and its transaction list match the shift's recorded data.

**Acceptance Scenarios**:

1. **Given** sessions exist, **When** the list opens, **Then** each session shows cashier, opened time, closed time (or "Open"), opening cash and status, newest first.
2. **Given** a session in the list, **When** the user opens it, **Then** they see opening cash, closing details (expected cash, counted cash, difference) if closed, and the transactions made during that session.
3. **Given** a session still open, **When** it is opened, **Then** the closing section shows that it is not closed yet and the expected cash so far.
4. **Given** a transaction in a session's list, **When** the user selects it, **Then** it opens the sale detail.
5. **Given** an owner or admin, **When** they view the list, **Then** they see every cashier's sessions and can filter by cashier, event and date.
6. **Given** a cashier, **When** they view the list, **Then** they see only their own sessions, as with the existing rule that a cashier may inspect only their own session.
7. **Given** a payment received in a session for an earlier sale, **When** the session's figures are shown, **Then** cash is attributed to the session in which it was received, consistent with existing shift cash rules.
8. **Given** many sessions, **When** the list renders, **Then** it is paginated.

---

### Edge Cases

- A product's variant picker is open and the cashier switches the seller or category pill, or the cart is cleared elsewhere. The picker must not show stale "in cart" counts.
- The picker is open and the cashier presses Enter in the search box or scans a barcode. The scan still adds the exact SKU and the picker state stays consistent.
- A seller or category has a very long name. The pill truncates cleanly and stays tappable without breaking the row.
- Zero sellers or zero categories exist. Only the "All" pill shows, or the row is hidden with no empty gap.
- A seller is deactivated or deleted while its pill is selected. The filter falls back to "All sellers" and the grid refreshes.
- Narrow tablet widths. The search box, seller pills and cart header actions must not overlap; pills scroll rather than wrap.
- Sales page: a filter combination matches more rows than the maximum a single "download all receipts" or page can handle. The user gets a clear limit message rather than a silent partial file.
- Sales page: the Sales list contains partially paid sales. Payment-method change must apply to a specific payment entry, not blindly to the whole sale.
- Payment method change on a sale with a payment already verified. Changing it from non-cash to anything else resets verification rules as in Story 7 and is recorded.
- Reference image replaced while another user views the old one. The old image is deleted and no longer served once replaced; the activity log records that the reference changed (a reference is working evidence, not a financial record, so the old file is not retained).
- A cash payment on a sale where change was given cannot be re-categorised to a non-cash method (the change belongs to the cash that was handed over); the user is told why and can void and re-enter the sale instead.
- Reports: two products share the same name (different sellers). They are kept distinct, with the seller shown.
- Reports: a deleted product or variant that still has historical sales. It still appears with its recorded name.
- Reports: ties for best seller. All tied products at the cutoff are marked; no arbitrary cut.
- Cashier sessions: a session never closed (cashier left). It stays "Open" and is visible to owners and admins with its elapsed time.
- DEMO and LIVE data never mix on any of these screens or reports.

## Requirements *(mandatory)*

### Functional Requirements

**POS screen**

- **FR-001**: When a product has more than one variant, choosing a variant in the picker MUST add it to the cart without closing the picker.
- **FR-002**: The picker MUST show a Close button at its bottom, always reachable without scrolling the variant list, and closing MUST keep everything already added.
- **FR-003**: The picker MUST show, per variant, how many units of it are currently in the cart, updating as the cashier adds more.
- **FR-004**: Out-of-stock and stock-limit rules that apply today when adding a variant MUST continue to apply inside the picker.
- **FR-005**: "Save as draft" and "Draft Transactions" MUST appear at the top of the cart area and MUST NOT appear in the product-search row. Their behaviour MUST be unchanged.
- **FR-006**: The seller filter MUST be shown as pill buttons, first pill "All sellers", in a single row that scrolls horizontally when it overflows, placed on the same row as the search box.
- **FR-007**: The search box MUST be approximately half its current width and keep all current behaviour (name or SKU search, Enter to add an exact SKU).
- **FR-008**: The category filter MUST be shown as pill buttons, first pill "All categories", on a row below the search box, scrolling horizontally on overflow.
- **FR-009**: Selecting a seller and a category MUST combine (both must match), and the selected pills MUST be visibly distinguishable from unselected ones.
- **FR-010**: Pill rows MUST be usable by touch (swipe) and by mouse or keyboard, with selected state announced to assistive technology.

**Sales page**

- **FR-011**: The Sales summary figures MUST be computed over every transaction matching the current filters, regardless of the current page.
- **FR-012**: The event filter MUST offer a selectable "All events" choice that removes the event restriction.
- **FR-013**: The Sales table MUST be paginated, show the current page and total pages, and reset to page one when filters or sorting change. The page MUST be restorable from the address.
- **FR-014**: The transaction number and the total amount MUST each display on a single line in the Sales table.
- **FR-015**: An authorised user MUST be able to change the payment method (and channel, where the method requires one) of a recorded payment from the Sales detail.
- **FR-016**: A payment-method change MUST NOT alter amounts, sale total, paid and balance figures or sale status; it MUST be refused for a voided sale and for a cash payment belonging to an already closed shift.
- **FR-017**: A payment changed to a non-cash method MUST require a channel and MUST become unverified, subject to the existing verification rules.
- **FR-018**: Every payment-method change MUST be recorded in the activity log with actor, previous and new method, and time.
- **FR-019**: An authorised user MUST be able to attach to a sale a transaction reference consisting of a text note, an image, or both, and later edit or remove it.
- **FR-020**: The Sales table MUST show a visible link or indicator on rows that have a reference and none on rows that do not, and following it MUST show the note and the image.
- **FR-021**: Reference images MUST be restricted to accepted image types and a maximum size, rejected with a clear message otherwise, and MUST only be viewable by users allowed to view the sale's reference.
- **FR-022**: Adding, changing or removing a reference MUST be recorded in the activity log and MUST NOT change amounts, status, stock or any report figure.
- **FR-023**: The user MUST be able to download, in one action, the receipts of all transactions matching the current Sales filters, one receipt per transaction, with a stated maximum and a clear message when exceeded.
- **FR-024**: Downloaded receipts MUST match the on-screen receipt for the same sale and MUST NOT add customer contact details.

**Reports**

- **FR-025**: A sales-by-product report MUST list per product the units sold and sales value for a chosen period or event, sorted best seller first, excluding voided sales.
- **FR-026**: The report MUST mark best sellers with a visible mark, using a rule stated on screen (see Assumptions), including all products tied at the cutoff.
- **FR-027**: The report MUST support searching by product name, applying to both the product rows and the transactions behind them.
- **FR-028**: Each product row MUST open the list of transactions containing that product, each linking to the sale.
- **FR-029**: A sales-by-payment-method report MUST list per method (and channel) the transaction count and amount received for a chosen period or event, with a grand total, excluding voided sales and rejected payments.
- **FR-030**: Each payment-method row MUST open the list of transactions paid by that method, showing the amount paid by that method, each linking to the sale. Split payments MUST count each method's own portion only.
- **FR-031**: Cash in the payment-method report MUST be the amount kept (tendered minus change), consistent with the Sales page, and verified versus unverified amounts MUST be distinguishable for non-cash methods.
- **FR-032**: Both new reports MUST follow the same access rules as existing reports, MUST show cost or margin only to users who already see profit data, and MUST respect the DEMO/LIVE boundary.

**Cashier sessions**

- **FR-033**: A cashier-session list MUST show, per session, cashier, opened time, closed time or "Open", opening cash and status, newest first, paginated, filterable by cashier, event and date.
- **FR-034**: Opening a session MUST show opening cash, closing details (expected cash, counted cash, difference) when closed or expected cash so far when open, and the transactions made in that session, each opening the sale detail.
- **FR-035**: Owners and admins MUST see all sessions; a cashier MUST see only their own.
- **FR-036**: Session cash figures MUST follow the existing rule that cash belongs to the session in which it was received.

### Key Entities *(include if feature involves data)*

- **Transaction Reference**: Evidence attached to one sale: an optional text note and an optional image, who added it and when. A sale has at most one current reference; replaced images are not shown. It carries no money.
- **Payment (existing)**: One entry of the sale's payment ledger with method, channel, amount, verification state and the shift it was received in. Its method and channel become correctable (Story 7); its amount and date do not.
- **Sale / Transaction (existing)**: A POS order with items, payments, cashier and shift. Gains the optional reference and a "has reference" indicator in lists.
- **Cashier Session (existing)**: A shift with opener, opening cash, closing counted cash, expected cash, difference and the transactions and payments belonging to it.
- **Best Seller Mark**: A report-time label on products at the top of the ranking by the stated rule; not stored.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A cashier can add 3 different variants of one product to the cart with one open of the picker, and needs no more than one extra tap (Close) after the last choice.
- **SC-002**: On the POS screen, filtering by a seller takes 1 tap (instead of 2 or more with the dropdown), and every seller is reachable on a tablet-width screen by sideways scrolling alone.
- **SC-003**: "Save as draft" and "Draft Transactions" are within the cart area at 100% of tested screen widths from tablet up, with no overlap with other controls.
- **SC-004**: For 100% of filter combinations tested on the Sales page, the summary figures equal the totals of the matching rows summed across all pages.
- **SC-005**: A user can switch the event filter back to "All events" in one action in 100% of attempts.
- **SC-006**: A list of 1,000 transactions can be browsed page by page, and each page appears within 2 seconds on the store's normal hardware.
- **SC-007**: A user can correct a wrong payment method on a sale in under 30 seconds, and the corrected method shows in the Sales page and in the payment-method report immediately.
- **SC-008**: A user can attach a reference (note and image) to a sale in under 1 minute and open it again from the Sales table in 2 taps or fewer.
- **SC-009**: Downloading receipts for a filtered set of up to 200 transactions completes in under 2 minutes, with exactly one receipt per matching transaction.
- **SC-010**: In test data, per-product units and sales in the sales-by-product report match the underlying transactions exactly, and the best-seller marks match the stated rule in 100% of cases.
- **SC-011**: In test data, the sum of the payment-method report's methods equals the total money kept for the same period, and each method's transaction list matches its count.
- **SC-012**: An owner can find a given shift and see its opening, closing and transaction details in under 1 minute, and a cashier can never see another cashier's session.

## Assumptions

- **Scope and size**: The request bundles four areas (POS screen, Sales page, new reports, cashier sessions). They are specified together as one feature, as the user submitted them, but each user story is independently deliverable, so planning may split delivery by story.
- **Seller multi-select**: The current seller filter allows choosing several sellers at once. The pill version keeps that: tapping pills toggles them, and "All sellers" clears the selection. Category filtering keeps whatever selection rule it has today.
- **Variant picker feedback**: "Added" feedback means a per-variant cart count in the picker. A separate toast is not required.
- **Sales pagination**: The Sales page previously filtered, sorted and summarised everything in the browser on the full list, with no pagination. This feature changes it so that totals still reflect the whole filtered set while the table shows one page at a time. The default page size is 25 rows (adjustable choices are optional).
- **Who may change a payment method**: Owner and admin, plus the user who recorded that payment while the sale is not voided, mirroring who may already edit a payment's confirmation. Payment amounts and dates remain uneditable. This reverses the earlier rule that a payment entry's method has no edit path, but only for method and channel, and it is a deliberate, logged exception.
- **Cash and closed shifts**: A change that would add or remove cash in a closed shift is refused; changes involving only non-cash methods, or cash in a still-open shift, are allowed.
- **Reference permissions**: A reference can be added, edited or removed by an owner, an admin, or the cashier who made that sale. It can be viewed by every role that can open the Sales page (they already see the sale and its receipt), through an authorising request only, never a public address.
- **Reference limits**: One image per sale, common image types only (for example JPEG, PNG, WebP), up to 5 MB, text note up to 1,000 characters. These can be tuned at planning time.
- **Reference is not a payment proof**: It is separate from payment proof (payment evidence attached to a specific payment). It documents the sale itself.
- **Download all receipts**: Delivered as one downloadable archive containing one receipt file per transaction, matching how bulk document downloads already work elsewhere in the product. The maximum is 200 transactions per download.
- **Best-seller rule**: Best sellers are the top 3 products by units sold in the selected scope; ties at the cutoff are all marked, and a product needs at least 1 unit sold. The stated rule on screen can be adjusted at planning time. Units, not sales value, decide the rank.
- **Report scope**: Both new reports use the existing Reports area, filter by period and by event the same way the other reports do, and only count completed, non-voided POS sales. Pre-orders are not included, consistent with the Sales page and Seller Recap.
- **Cashier session list placement**: Shown as its own screen reachable from the cashier-session area. Owners and admins can see all sessions; cashiers only their own, as with the existing per-session ownership rule.
- **Existing behaviour retained**: DEMO/LIVE separation, role-based menu access, activity logging of changes and server-side computation of all amounts apply unchanged. Nothing here lets a client dictate a price, total or stock change.
- **Out of scope**: Partial returns and refunds, editing a payment's amount or date, camera barcode scanning, pre-order changes, and any change to the Seller Recap, settlement or profit reports.
