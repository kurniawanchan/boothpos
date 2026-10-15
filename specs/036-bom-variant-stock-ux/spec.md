# Feature Specification: BOM, Variant History, and Product/Stock List Refinements

**Feature Branch**: `036-bom-variant-stock-ux`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "BOM: quantity no decimal; add save button; add product stock; cost price is for one product; fix 'Copy from another variant' dropdown (can search and scroll). Product variant: add transaction history for each variant. Product list: make thumbnail image larger; make code one-liner; fix the 'master_data.col_type' column. Stock: can show detail product when click on SKU; fix the 'master_data.col_type' column." (with three screenshots: the product list, the stock movements list, and the variant BOM dialog)

## Clarifications

### Session 2026-10-05

- Q: BOM "add product stock" — what should it do? → A: Show the variant's current stock (read-only) in the BOM dialog; no stock is changed from there and no materials are deducted.
- Q: BOM "cost price is for one product" — what is wrong today? → A: Cost already means per ONE finished unit; make that explicit in the labels so it is not misread as a batch total.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A BOM I can edit and save with confidence (Priority: P1)

A shopkeeper opens a variant's BOM. Quantities are whole numbers (a keychain needs 11 chains, never 11.0000 or 1.5), changes are kept as a draft until they press an explicit **Save** button, the dialog shows how many of this product are currently in stock, and every cost figure is clearly labelled as the cost of **one** finished product.

**Why this priority**: the BOM drives the cost price that feeds profit reports and seller settlements, so it must be unambiguous and hard to change by accident. Today a quantity is saved the moment the field loses focus, shows four decimals that mean nothing for counted parts, and the cost cards do not say what unit they describe.

**Independent Test**: open any variant's BOM, edit two quantities, confirm nothing is saved until **Save** is pressed, enter a decimal and see it refused, and read the cost cards and the stock line.

**Acceptance Scenarios**:

1. **Given** a BOM row, **When** the user views its quantity, **Then** it is shown as a whole number (e.g. `11`), and entering a decimal, zero or a negative value is refused with a clear message before anything is sent.
2. **Given** the user changed one or more quantities, **When** they have not pressed Save, **Then** the dialog shows an "unsaved changes" state, the new totals are not yet recorded, and closing the dialog asks to confirm discarding the changes.
3. **Given** unsaved quantity changes, **When** the user presses **Save**, **Then** all changed rows are saved together (all or nothing), the totals refresh from the saved data, and a success message appears.
4. **Given** one changed row is invalid, **When** the user presses Save, **Then** nothing is saved, the invalid row is marked, and the valid edits stay in the draft.
5. **Given** the BOM dialog is open, **When** it loads, **Then** the variant's current stock is shown (read-only) near the cost figures and nothing in the dialog changes stock.
6. **Given** the cost cards and the BOM table, **When** the user reads them, **Then** the BOM cost and the cost price are labelled as "per 1 product / unit" (and the quantity column as "per 1 product"), so the figures cannot be mistaken for a batch total.

---

### User Story 2 - Copying a BOM from another variant works with search and scrolling (Priority: P1)

In the BOM dialog, "Copy from another variant" lets the user find the source variant by typing part of its SKU or name, and scroll a long list; the list is no longer cut off by the dialog.

**Why this priority**: a product with many variants (a keychain in eight sizes/colours) makes the current picker unusable — the list is clipped and cannot be searched — which blocks the copy feature entirely.

**Independent Test**: on a product with many variants, open "Copy from another variant", type part of a SKU to narrow the list, scroll to the last entry, pick it and copy.

**Acceptance Scenarios**:

1. **Given** a product with many sibling variants that have a BOM, **When** the user opens the picker, **Then** the whole list is reachable by scrolling and is not clipped by the dialog.
2. **Given** the picker is open, **When** the user types part of a SKU or variant name, **Then** the list narrows to matching variants (case-insensitive), and an empty result says so.
3. **Given** a match, **When** the user picks it, **Then** the selection is clear, can be changed, and the existing copy rules (confirmation before replacing a BOM, same product only) are unchanged.
4. **Given** the keyboard, **When** the user navigates the picker, **Then** they can open it, move through the options, select and close it without a mouse.

---

### User Story 3 - Transaction history for each variant (Priority: P2)

From a product's variant, the user can open that variant's **transaction history**: every movement of that SKU in one chronological list — sales, pre-order hand-overs, purchases/arrivals, returns, adjustments and the initial stock — each with its date, type, quantity change, stock before → after, reference/reason and who did it.

**Why this priority**: today the only history is the global stock-movements screen, which mixes every SKU. Answering "what happened to this variant?" means filtering by hand. It is read-only and builds on data that already exists.

**Independent Test**: open the history of a variant with a sale, an adjustment and a pre-order hand-over; see exactly those entries, newest first, with running stock that reconciles to the current stock.

**Acceptance Scenarios**:

1. **Given** a variant with movements, **When** the user opens its history, **Then** all of that variant's movements appear newest first, each with date/time, type, change (+/−), before → after, reference/reason and the user.
2. **Given** a long history, **When** the user scrolls or pages and filters by type or date range, **Then** only matching entries are shown and the totals/count reflect the filter.
3. **Given** a movement that came from a sale or pre-order, **When** it is shown, **Then** its reference identifies that transaction (order or pre-order number) so the user can find it.
4. **Given** a variant with no movements, **When** the history opens, **Then** a clear empty state is shown (not a blank dialog), and a failed load shows an error with Retry.
5. **Given** a user without access to stock/products, **When** they try to reach the history, **Then** access is denied the same way as the existing stock screens.

---

### User Story 4 - A more readable Product list (Priority: P2)

On the Products list the thumbnail is larger, the product code is always one line (never wrapped across two), and the "Type" column header shows its proper name (Ready stock / Pre-order) instead of the raw text `MASTER_DATA.COL_TYPE`.

**Why this priority**: the raw key in a column header is an obvious defect, and a two-line code plus a tiny thumbnail make rows hard to scan.

**Independent Test**: open the Products list in English and Indonesian; see a larger thumbnail, codes such as `SPF-KC-DMC` on one line, and a properly named Type column.

**Acceptance Scenarios**:

1. **Given** the Products list, **When** it renders, **Then** product thumbnails are noticeably larger than today and keep their aspect ratio, and products without an image keep a tidy placeholder of the same size.
2. **Given** a product code of normal length, **When** it is displayed, **Then** it stays on one line; an unusually long code is shortened with the full code available on hover rather than wrapped.
3. **Given** the Type column, **When** the language is English or Indonesian, **Then** the header shows a translated name in both languages and no raw key is visible anywhere.

---

### User Story 5 - Stock movements: open the product from an SKU, with a proper column header (Priority: P2)

On the Stock movements screen, clicking a SKU opens the details of its product (and that variant), and the "Type" column header shows its proper name instead of `MASTER_DATA.COL_TYPE`.

**Why this priority**: users spot an odd movement and want to see what the SKU is without leaving the screen; the raw header is a visible defect.

**Independent Test**: on Stock movements, click an SKU and see the product detail for that variant; switch language and check the Type header.

**Acceptance Scenarios**:

1. **Given** a movement row, **When** the user clicks its SKU, **Then** the product's detail opens with that variant identified, without losing the movement list, filters or scroll position when closed.
2. **Given** the SKU of a deleted or inaccessible product, **When** it is clicked, **Then** the user gets a clear message instead of a blank or broken dialog.
3. **Given** the Type column, **When** the language changes, **Then** the header is translated in both languages and no raw key is visible.

---

### Edge Cases

- Existing BOM rows saved earlier with fractional quantities (e.g. 1.5 or 0.25): they remain visible exactly as stored and still cost correctly until edited; when the user edits that row it must be changed to a whole number to save. Nothing is rounded silently.
- A whole-number quantity that is very large, or an empty field: refused with the same message as zero.
- Unsaved BOM edits when the user adds/removes a row, marks the BOM complete, copies a BOM, or replaces a source: the user is told the draft would be lost (or the draft is saved/discarded explicitly) — a draft is never silently dropped or silently saved.
- Two people editing the same BOM: if a row changed or disappeared since it was loaded, Save fails clearly, the dialog reloads the current data, and the user's draft for unaffected rows is not lost silently.
- A BOM marked complete: the cost price follows the BOM as before; the per-unit labels apply to both.
- "Copy from another variant" when the product has a single variant, or none of the siblings has a BOM: the control is hidden or disabled with a reason, as today.
- A variant history with thousands of movements: it must stay responsive (paged), and the running before → after values must match the stored movement rows, not be recomputed on screen.
- Very long product code or product name on the Products list: one line with truncation and a full-text hover; layout must not break at tablet width.
- The Type column on Products shows a stock type (Ready stock / Pre-order) while on Stock it shows a movement type (Sale / Adjustment / …): each gets its own correct header; a shared missing key must not remain.
- Clicking an SKU that appears in many rows: each click opens that row's variant; closing returns to the same place.

## Requirements *(mandatory)*

### Functional Requirements

**BOM dialog**

- **FR-001**: The BOM quantity (needed per one finished unit) MUST be a whole number ≥ 1 everywhere it is entered or displayed for newly saved data; decimals, zero, negatives and empty values MUST be refused with a clear message before saving, and the server MUST enforce the same rule independently of the screen.
- **FR-002**: Quantity edits in the BOM dialog MUST NOT be saved automatically; the dialog MUST provide an explicit **Save** action that saves all changed rows together (all or nothing) and refreshes the totals from the saved result.
- **FR-003**: The dialog MUST show when there are unsaved changes, MUST disable Save when there are none, and MUST ask for confirmation before discarding unsaved changes (closing the dialog, or an action that would reload the BOM).
- **FR-004**: The BOM dialog MUST show the variant's current stock (read-only). It MUST NOT change stock, and it MUST NOT deduct materials.
- **FR-005**: The BOM cost figures, the cost price figure and the quantity column MUST be labelled as the amount for ONE finished product/unit in both languages; the calculation itself (quantity per unit × unit cost) is unchanged.
- **FR-006**: Existing BOM rows with fractional quantities MUST keep displaying and costing as stored, and MUST require a whole number the next time that row is edited; no data is rewritten automatically.
- **FR-007**: The "Copy from another variant" picker MUST show every eligible sibling variant in a scrollable list that is not clipped by the dialog, MUST let the user filter it by typing part of a SKU or variant name (case-insensitive, with an empty-result message), and MUST be operable by keyboard. The existing copy rules (confirmation before replacing, same product only, all-or-nothing) are unchanged.

**Variant transaction history**

- **FR-008**: From a variant, a user with access to products/stock MUST be able to open that variant's transaction history: all stock movements of that SKU (initial, purchase, sale, pre-order hand-over, return, adjustment), newest first, each with date/time, type, signed quantity change, stock before → after, reference/reason and the user.
- **FR-009**: The history MUST be paged, MUST allow filtering by movement type and date range, MUST show the same stored before → after values as the Stock movements screen, and MUST present sale and pre-order movements with their order / pre-order number as reference.
- **FR-010**: The history MUST be read-only, MUST follow the same DEMO/LIVE data separation and access rules as the existing Stock movements screen, and MUST show an empty state for no movements and an error state with Retry for a failed load.

**Product list**

- **FR-011**: The Products list MUST show larger product thumbnails (aspect ratio kept, placeholder of the same size when there is no image) without breaking the row layout at tablet width.
- **FR-012**: The product code MUST be displayed on a single line; an overlong code MUST be truncated with the full value on hover/title, never wrapped.
- **FR-013**: The Products list "Type" column header MUST display a translated name (English and Indonesian); no raw translation key may appear.

**Stock movements**

- **FR-014**: In the Stock movements list, the SKU MUST be a clickable control that opens the detail of its product with that variant identified; closing it MUST leave the list, its filters and its scroll position as they were; an SKU whose product is gone MUST produce a clear message.
- **FR-015**: The Stock movements "Type" column header MUST display a translated name (English and Indonesian); no raw translation key may appear.

**Cross-cutting**

- **FR-016**: Every new or changed label, message and header MUST exist in both supported languages, and a missing translation key MUST be caught by an automated check so a raw key can never reach a screen again.
- **FR-017**: The API documentation MUST move with any response/validation change (whole-number quantity rule, the history source, and any new field) in the same change.

### Key Entities *(include if feature involves data)*

- **BOM line**: one row of a variant's bill of materials — a purchase-order line reference with its snapshot cost, and the quantity needed per ONE finished unit (now a whole number). Edited as a draft and saved together.
- **Variant stock movement**: an existing, append-only record of a stock change for a SKU (type, signed change, before → after, reference/reason, user, time). The variant's transaction history is a filtered, read-only view of these records.
- **Variant**: a sellable SKU of a product; owns a BOM, a current stock and its movement history.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of newly saved BOM quantities are whole numbers; entering a decimal, zero or negative never reaches stored data.
- **SC-002**: With unsaved BOM edits, 0 changes are recorded until Save is pressed, and 100% of attempts to close or reload with a draft are confirmed first.
- **SC-003**: A user can find a specific variant in a product with 10+ variants inside the copy picker, and complete the copy, in under 30 seconds without touching the mouse wheel more than once.
- **SC-004**: Opening a variant's transaction history shows its first page in under 2 seconds for a variant with 1,000 movements, and its newest entry's "after" value equals the variant's current stock.
- **SC-005**: 0 raw translation keys (for example `MASTER_DATA.COL_TYPE`) appear on any screen in either language, verified by an automated check over the screens touched.
- **SC-006**: On the Products list, 100% of normal-length codes display on one line and thumbnails are at least 50% larger than before, and the list's horizontal fit is no worse than before by more than the thumbnail's own growth (measured 2026-10-05: at 1440 px wide the list fits without horizontal scrolling; at 1100 px with the sidebar open it already overflowed before this change — 919 px of content in an 808 px container — and now overflows by about 170 px; hiding the sidebar removes it).
- **SC-007**: From any Stock movements row, the product detail is reachable in one click, and returning leaves the list state unchanged.

## Assumptions

- "Transaction history for each variant" means the variant's stock movement ledger (which already records sales, pre-order hand-overs, purchases/arrivals, returns, adjustments and initial stock with references); it does not introduce a new transaction type or any write path. (ASSUMPTION: if "transaction" should instead mean only customer sales/pre-orders, the type filter covers that.)
- The history is opened from the variant's row inside the product detail/management view and is reachable only by roles that already see products/stock.
- "Add save button" means an explicit Save for all pending quantity edits at once (not per-row auto-save); the existing add/remove/complete/copy actions keep their own confirmations.
- "Make thumbnail larger" and "make code one-liner" are visual refinements with no data change; the exact thumbnail size is a design choice within the SC-006 minimum.
- The "Type" column on the Products list (Ready stock / Pre-order) and the Stock list (movement type) are two different columns that both lost their header because one shared translation key was never defined; each gets a correct, separate header.
- Existing fractional BOM quantities are rare; they are kept as-is until a user edits them (no automatic data migration or rounding).
- Out of scope: changing stock or deducting materials from the BOM dialog (explicitly rejected in clarification), production scheduling, material stock, and any change to how BOM cost, cost price completion or settlements are calculated.
