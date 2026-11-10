**Classification: INTERNAL**

# Feature Specification: Pre-order Filters (Category, Customer) and Export Columns

**Feature Branch**: `096-preorder-filter-export-columns` (spec dir number ≠ branch number)

**Created**: 2026-11-10

**Status**: Draft

**Input**: User description: "in preorder, add filter to search by product category, customer. in export preorder, add po number, seller, status, total"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Filter the Pre-order list by customer (Priority: P1)

A shopkeeper handling many pre-orders wants to see only the orders of one customer (or a few customers) — e.g. when that customer comes to the booth asking "what did I order and what do I still owe?". Today the only way is typing the name in the free-text search box, which also matches PO numbers and product names and can surface unrelated rows.

**Why this priority**: Customer lookup is the most frequent reason to narrow the list, and it is the smallest, most independent slice.

**Independent Test**: Open the Pre-orders list, pick one customer in the new Customer filter, and confirm only that customer's pre-orders remain (list, summary figures and row count agree).

**Acceptance Scenarios**:

1. **Given** the list holds pre-orders of several customers, **When** the user selects customer A in the Customer filter, **Then** only pre-orders of customer A are listed and the summary totals reflect only those rows.
2. **Given** the user selects customers A and B, **When** the list reloads, **Then** pre-orders of A or B are shown (OR within the filter).
3. **Given** a Customer filter is active together with a status filter, **When** the list reloads, **Then** only rows matching both are shown (AND across filters).
4. **Given** a Customer filter is active, **When** the user clears it, **Then** the full (otherwise filtered) list returns.
5. **Given** the user opens the Customer filter, **When** the list of choices appears, **Then** it can be searched by typing and does not require typing to see the first choices.

---

### User Story 2 - Filter the Pre-order list by product category (Priority: P1)

The shopkeeper wants to see only pre-orders that contain products of a given category (e.g. all pre-orders with "Keychain" items) to prepare, chase or report on that product group.

**Why this priority**: Same value tier as the customer filter; independent of it and of the export change.

**Independent Test**: Pick a category in the new Category filter and confirm every listed pre-order contains at least one item whose product belongs to that category, and no such pre-order is missing.

**Acceptance Scenarios**:

1. **Given** pre-orders contain items from different categories, **When** the user selects category X, **Then** every pre-order with at least one item of category X is listed, each exactly once, even if it has several such items.
2. **Given** a pre-order mixes category X and category Y items and the user filters on X, **When** the row is shown, **Then** the whole pre-order is shown (all its items), not only the X items.
3. **Given** the user selects categories X and Y, **When** the list reloads, **Then** pre-orders containing X or Y items are shown.
4. **Given** the Category filter is combined with Customer, Seller, status or search filters, **When** the list reloads, **Then** all active filters apply together.
5. **Given** a product's category was changed or deleted after the pre-order was made, **When** filtering by category, **Then** the result follows the product's current category (a deleted category cannot be selected; its pre-orders remain reachable by clearing the filter).

---

### User Story 3 - Export carries PO number, seller, status and total (Priority: P1)

When the owner exports pre-orders to Excel to review or share them, each row today lacks the PO number, the seller(s), the status and the order total, so the file cannot be matched back to the screen or used for follow-up without extra lookups.

**Why this priority**: The export is unusable for reconciliation without these four columns; independent of the list filters.

**Independent Test**: Export pre-orders and verify each row shows its PO number, the seller name(s) of its items, its status, and its total — all equal to what the list shows for that pre-order.

**Acceptance Scenarios**:

1. **Given** an export is requested, **When** the file is opened, **Then** each row has a PO number equal to the one shown in the list.
2. **Given** a pre-order whose items belong to one seller, **When** exported, **Then** the seller column shows that seller's name.
3. **Given** a pre-order spanning several sellers, **When** exported, **Then** the seller column lists each distinct seller once (not once per item), in a stable order.
4. **Given** a pre-order in any status, **When** exported, **Then** the status column shows the same status label/value the list uses for it.
5. **Given** a pre-order with a discount and shipping cost, **When** exported, **Then** the total column equals the order's grand total shown in the list (items − discount + shipping), formatted like the other money columns.
6. **Given** an exported file, **When** it is imported back through the existing Pre-order import, **Then** the import succeeds and the new columns are ignored — they never change, create or block an order.

---

### User Story 4 - Export follows the new filters (Priority: P2)

The user expects "Export" to contain exactly the rows currently on screen. After this change the list can be narrowed by customer and category (and already by seller), so the export must honour the same narrowing.

**Why this priority**: Without it the export silently returns more rows than the screen shows, which would undermine Stories 1–3; it is lower only because the export is still usable as a full dump.

**Independent Test**: Apply customer + category filters, export, and confirm the file's rows equal the listed rows.

**Acceptance Scenarios**:

1. **Given** a Customer and/or Category filter is active, **When** the user exports, **Then** the file contains only the pre-orders matching those filters.
2. **Given** a Seller filter is active, **When** the user exports, **Then** the file contains only pre-orders having an item of that seller (consistent with the list).
3. **Given** a search term is typed, **When** the user exports, **Then** the file matches the same rows the list shows for that term.
4. **Given** no filter is active, **When** the user exports, **Then** all pre-orders in the active mode are exported as today.

---

### Edge Cases

- A pre-order with no customer (if possible) must not appear when a Customer filter is active, and must still be listed/exported when it is not.
- A customer filter value that no longer exists (deleted/other mode) returns an empty list, not an error, and never reveals another mode's data.
- Category filter with a category that has no pre-orders returns an empty list with the normal empty state.
- Sort and pagination keep working with the new filters; a row never appears on two pages.
- "Select all N matching" (bulk actions) and the pre-order detail Previous/Next navigation follow the same filtered set as the list.
- A seller-restricted user sees only what their seller scope allows; the category and customer choices and the export never widen that scope.
- A seller name containing a comma or `|`, or a very long list of sellers, stays readable in a single cell.
- Export of a large number of pre-orders keeps one row per pre-order (the new columns never duplicate rows).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Pre-orders list MUST offer a Customer filter that accepts one or more customers and shows only pre-orders of the selected customers.
- **FR-002**: The Customer filter's choices MUST be searchable by name and MUST show initial choices without typing.
- **FR-003**: The Pre-orders list MUST offer a Category filter that accepts one or more product categories and shows only pre-orders having at least one item whose product is in a selected category.
- **FR-004**: A pre-order matching the Category filter MUST appear once, with all its items intact, regardless of how many of its items match.
- **FR-005**: The new filters MUST combine with every existing filter (status, dispatch status, event, fulfillment, seller, search): AND across filters, OR within one filter.
- **FR-006**: The list summary figures, the "select all matching" count, bulk-action targets and detail Previous/Next navigation MUST reflect the same filtered set as the list.
- **FR-007**: The active Customer and Category selections MUST be visible as active filters and clearable individually and together, consistent with how existing filters behave (including being kept when the user returns to the page, if existing filters are).
- **FR-008**: The pre-order export MUST include a PO number column equal to the list's PO number.
- **FR-009**: The export MUST include a seller column listing the distinct seller names of the pre-order's items, each once, in a stable order.
- **FR-010**: The export MUST include a status column using the same status value/label as the list.
- **FR-011**: The export MUST include a total column equal to the pre-order's grand total, formatted like the other money columns.
- **FR-012**: The four new export columns MUST be export-only: re-importing an exported file MUST succeed and MUST ignore them; the downloadable import template MUST NOT list them.
- **FR-013**: The export MUST apply the same filters as the list, including the new Customer and Category filters and the existing Seller filter, and MUST match the list's search behaviour.
- **FR-014**: The export MUST NOT add customer contact details (phone, email, address) beyond what it already contains; no new personal data may be introduced by this feature.
- **FR-015**: Role and seller-scope rules MUST be unchanged: the new filters and columns never expose pre-orders, customers, categories or sellers the user could not already see, and never cross the DEMO/LIVE boundary.

### Key Entities *(include if feature involves data)*

- **Pre-order**: the order being listed/exported; has a PO number, customer, status, items, grand total.
- **Pre-order item**: links a pre-order to a product variant and its seller; the bridge through which category and seller are derived.
- **Product category**: classification of the product; the new filter dimension.
- **Customer**: the buyer; the second new filter dimension.
- **Seller**: owner of the items; surfaced as a new export column (already a list filter).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can narrow the Pre-orders list to one customer in at most 3 interactions (open filter, choose, done) and see the result in under 2 seconds.
- **SC-002**: With a category selected, 100% of listed pre-orders contain an item of that category and 0 matching pre-orders are missing (verified against a sample dataset with mixed-category orders).
- **SC-003**: For any filter combination, the number of rows in the exported file equals the number of rows the list reports.
- **SC-004**: For every exported row, PO number, status and total equal the list's values, and the seller cell lists exactly the distinct sellers of that pre-order.
- **SC-005**: Re-importing an unmodified export produces zero import errors attributable to the new columns.
- **SC-006**: Existing list and export behaviour without the new filters is unchanged (all current tests for list, export and import still pass).

## Assumptions

- ASSUMPTION: "filter to search by product category, customer" means two dedicated dropdown filters (multi-select, searchable) placed with the other list filters, not extra free-text boxes. The free-text search stays as is.
- ASSUMPTION: Category is derived from the item's product's current category (not a stored snapshot on the pre-order), matching how other screens filter by category.
- ASSUMPTION: "Total" means the pre-order grand total (items − discount + shipping), the same figure the list's Total column shows; "status" is the main pre-order status (not the invoice-sent/shipping marker, which is already exported as `dispatch_status`).
- ASSUMPTION: Seller in the export is the distinct sellers of the pre-order's items (a pre-order can span several), not a single seller field.
- ASSUMPTION: The new columns follow the existing export-only convention (like `variant_names`, `created_at`): placed in the export file, ignored by import, absent from the template.
- ASSUMPTION: The export previously matched search only against customer name and ignored the Seller filter; aligning it with the list is in scope because the stated goal is that export equals what is on screen (Story 4). If the owner prefers the old export search behaviour, FR-013 needs revisiting.
- Pre-orders remain separate from POS sales; no change to stock, payments or reports.
- The OpenAPI document and locale files (Indonesian and English) are updated together with the behaviour change, per project convention.
- Existing authorization (the Pre-orders menu and its export permission) already gates both list and export; no new permission is introduced.
