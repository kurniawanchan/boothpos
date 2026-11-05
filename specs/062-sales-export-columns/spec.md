**Classification: INTERNAL**

# Feature Specification: Sales Transaction Export, Revised Columns

**Feature Branch**: `087-sales-export-columns` (spec directory number differs from the branch number)

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "in export transaction list to excel, remove customer column, remove gross sales, remove discount, add qr type value, remove cost, remove margin, remove paid, show payment note, make multiple line for items with bulleted and category, add grand total for net sales."

## Background (current export, from the code)

The Sales page's Excel export has one row per sale. Today its columns are, in order: transaction no, status, time, **customer**, sellers, cashier, **items** (one line, items joined with `;`), units, **gross sales**, net sales, **discount**, payment methods, payment status, verification, cash, non-cash, total, **cost** and **margin** (owner/admin only), **paid**, balance, reference note, payment proofs, and then the embedded pictures. Payment methods show only `cash` or `qr_ewallet`; they do not say which QR was used. Payment notes are not in the file. There is no total row.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A cleaner, shareable transaction sheet (Priority: P1)

A shop owner or admin exports the Sales list to hand to a seller or keep for the books. They do not want customer names, gross sales, discount, cost, margin or the paid amount in this file. The columns that remain should be the ones they actually use.

**Why this priority**: This is the core of the request, and the removals include sensitive columns (customer, cost, margin).

**Independent Test**: Export any set of sales as an owner. The file has none of the removed columns, and every other column still has the same values as before.

**Acceptance Scenarios**:

1. **Given** an export by an owner or admin, **When** the file is opened, **Then** it has no customer, gross sales, discount, cost, margin or paid column.
2. **Given** an export by a cashier, **When** the file is opened, **Then** it has the same columns as the owner's (nothing is added back for any role).
3. **Given** the same sales exported before and after this change, **When** compared, **Then** every kept column (including net sales, total, cash, non-cash, balance, units) has identical values.
4. **Given** a sale with a customer attached, **When** exported, **Then** no customer name, phone or email appears anywhere in the file.

---

### User Story 2 - See which QR was used and any payment note (Priority: P1)

For sales paid with QR, the reader wants to know which QR it was (for example GoPay or Shopee), and wants to read the note staff typed on the payment.

**Why this priority**: Without it a QR payment is just "qr_ewallet", and payment notes are invisible in the file, so reconciling against the QR provider is hard.

**Independent Test**: Export a sale paid by GoPay QR with a payment note. The file shows "GoPay" in the QR type column and the note in the payment note column.

**Acceptance Scenarios**:

1. **Given** a sale paid by one QR payment through the GoPay channel, **When** exported, **Then** the QR type column shows `GoPay`.
2. **Given** a sale with two QR payments through different channels (GoPay and Shopee), **When** exported, **Then** the QR type column lists both, once each, separated by a comma.
3. **Given** a sale paid only by cash or bank transfer, **When** exported, **Then** the QR type cell is empty.
4. **Given** a sale whose payments carry notes, **When** exported, **Then** the payment note column shows each note on its own line, each prefixed with the payment method, in the order the payments were recorded.
5. **Given** a sale whose payments have no notes, **When** exported, **Then** the payment note cell is empty.
6. **Given** a rejected payment, **When** exported, **Then** it does not contribute a QR type or a note, consistent with how payment amounts already exclude rejected payments.

---

### User Story 3 - Items listed one per line, with bullets and category (Priority: P2)

Today all items of a sale are squeezed into one line. The reader wants each item on its own line, bulleted, with its category, and the row tall enough to read it.

**Why this priority**: Improves readability but does not change any figure.

**Independent Test**: Export a sale with three items. The items cell shows three lines, each starting with a bullet, with name, quantity and category, and all three are visible without resizing the row.

**Acceptance Scenarios**:

1. **Given** a sale with items "Sticker A" x2 (category Sticker) and "Keychain B" x1 (category Keychain), **When** exported, **Then** the items cell reads, on two lines: `• Sticker A ×2 (Sticker)` and `• Keychain B ×1 (Keychain)`.
2. **Given** a sale with one item, **When** exported, **Then** the cell has one bulleted line.
3. **Given** an item whose product has no category or the category was deleted, **When** exported, **Then** the line shows the item without a category part, or the deleted category's name if it is still known. The line is never dropped.
4. **Given** a sale with many items, **When** the file is opened, **Then** every item line is visible (the row height fits the number of lines).
5. **Given** a sale with embedded pictures, **When** exported, **Then** the pictures still sit in their own columns on the same row, and the row is tall enough for both the pictures and the item lines.

---

### User Story 4 - Grand total of net sales (Priority: P1)

At the bottom of the sheet the reader wants a grand total of net sales, so they do not have to sum the column themselves.

**Why this priority**: A direct request, and it makes the file usable as a summary.

**Independent Test**: Export five sales. The last row is labelled "Grand total" and its net sales cell equals the sum of the five rows' net sales.

**Acceptance Scenarios**:

1. **Given** an export of several sales, **When** opened, **Then** the last row, after the data rows, is labelled "Grand total" and shows the sum of the net sales column.
2. **Given** the export is scoped to selected sellers, **When** exported, **Then** each row's net sales is that sellers' share (as today) and the grand total is the sum of those same row values.
3. **Given** the export includes voided sales (shown for audit), **When** exported, **Then** the grand total excludes voided sales, consistent with the on-screen totals that never count them.
4. **Given** an export of zero sales, **When** opened, **Then** no grand total row is written.
5. **Given** the net sales column holds numbers, **When** the grand total is read, **Then** it is also a number (not text), so it can be used in further calculations.

---

### Edge Cases

- **Sale reference note vs payment note**: the existing "reference note" (a note on the sale as a whole) stays as it is. The new "payment note" is the note on each individual payment. They are different columns.
- **Embedded pictures**: reference images and payment proofs keep working, including the limit on the number of images per export.
- **Existing exports**: files exported before this change are not touched.
- **Formula-looking text**: payment notes, item names and categories starting with `=` stay plain text, as in all other exports.
- **Very long notes**: shown in full in the cell.
- **Permissions**: who may export, the seller restriction for seller-linked users, and the filters (event, dates, selected rows, include voided) do not change.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Sales transaction export MUST NOT contain the customer, gross sales, discount, cost, margin or paid columns, for any role.
- **FR-002**: The export MUST NOT contain customer name, phone or email anywhere in the file.
- **FR-003**: All remaining columns MUST keep their current names, order (with the removed ones simply missing) and values.
- **FR-004**: The export MUST include a QR type column that shows the provider name of each non-rejected QR payment's channel, each name once, in the order first used, separated by a comma. It MUST be empty when the sale has no QR payment.
- **FR-005**: The export MUST include a payment note column that shows the note of each non-rejected payment that has one, one note per line, each prefixed with that payment's method, in the order recorded. It MUST be empty when no payment has a note.
- **FR-006**: The items cell MUST list each item on its own line, starting with a bullet, showing name, quantity and category.
- **FR-007**: An item without a known category MUST still be listed, without a category part.
- **FR-008**: Row height MUST fit the multi-line items (and payment note) text so that all lines are visible, and MUST stay tall enough for embedded pictures.
- **FR-009**: The export MUST end with one "Grand total" row holding the sum of the net sales column as a number, and MUST NOT write it when there are no sales rows.
- **FR-010**: The grand total MUST equal the sum of the net sales values shown in the rows above it, including when exports are scoped to selected sellers, and MUST exclude voided sales.
- **FR-011**: Reference note, payment proofs and embedded images MUST keep working as they do today.
- **FR-012**: Export permissions, filters, seller restriction and the embedded-image limit MUST NOT change.
- **FR-013**: Text cells starting with `=` MUST be written as plain text.
- **FR-014**: The on-screen Sales list MUST NOT change.

### Key Entities

- **Sale (order)**: one export row; carries status, time, seller names, cashier, units, net sales, total and balance.
- **Sale item**: a line of a sale with name, quantity and its product's category.
- **Payment**: has a method, an optional note, and, for QR payments, a payment channel.
- **Payment channel**: the account or QR used, with a provider name (for example GoPay, Shopee) and a type (bank transfer or QR e-wallet).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of exports, none of the six removed columns appears and no customer detail appears anywhere.
- **SC-002**: For identical sales, every kept column has the same value before and after this change.
- **SC-003**: For every sale paid by QR, the QR type cell names the provider of each non-rejected QR payment.
- **SC-004**: Every payment note recorded on a non-rejected payment is readable in the file, in 100% of exports.
- **SC-005**: For a sale with N items, the items cell shows N bulleted lines and all N are visible without adjusting row height.
- **SC-006**: The grand total equals the sum of the net sales rows above it in 100% of exports, for all sellers and for seller-scoped exports.
- **SC-007**: A user can answer "how much did we sell in net terms" from the file in under 10 seconds, without any calculation.

## Assumptions

- "QR type" means the provider name of the payment channel used for a QR payment (for example GoPay, Shopee). This is based on the data: every QR payment today has method `qr_ewallet` with a channel whose provider names the QR. Bank-transfer payments are not QR and are not listed here.
- "Payment note" means the note on each individual payment, not the sale-level reference note, which stays.
- "Category" is the product category name of each item.
- "Net sales" is the existing net sales column (item-level, already after item discounts). The grand total is only for that column; other numeric columns have no total.
- Balance, total, cash, non-cash, units, verification, payment status, payment proofs and reference note stay, because they were not named for removal.
- The change applies to the Excel export only. The on-screen list, other reports and the other exports do not change.
- The grand total row is a plain data row at the bottom with a text label in the first column.
