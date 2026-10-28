# Feature Specification: Stock Page Search, Variant Details, and Product Stock Totals

**Feature Branch**: `079-stock-movements-search-variant-columns`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "in stock movements page /stock, update: add search and filter; add column Variant Name with images, Seller, Category, Available Stock, History (to show transaction history); add variant name to the transaction history dialog title. In product page /products, update: add column to show total available stock and total available product money before sales"

## Clarifications

### Session 2026-10-28

- Q: What does a row on the Stock page represent? → A: It stays a movement ledger, one row per stock movement; the new columns are added to each movement row (option B).
- Q: Which price defines "total available product money before sales"? → A: Stock × selling price, i.e. potential revenue (option A). No cost-based figure is introduced.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Find a variant's stock quickly on the Stock page (Priority: P1)

A shop owner or inventory staff opens the Stock page and today sees only a flat list of stock movements identified by SKU code. They need to answer "which item is this, whose is it, how many are left, and what happened to it?" without decoding SKUs. Each movement row shows the variant's picture, variant name, seller, category and the variant's current available stock, and the user can search and filter the movements to the item they care about.

**Why this priority**: Finding the right variant and its current stock is the daily task on this page. Without search, a booth with hundreds of variants is unusable.

**Independent Test**: Open the Stock page with movements for several sellers and categories; type part of a variant name, SKU or product name in the search box and confirm only matching movements remain; pick a seller and a category and confirm the list narrows accordingly, with picture, variant name, seller, category and available stock visible on every row.

**Acceptance Scenarios**:

1. **Given** the Stock page lists many movements, **When** the user types part of a variant name, product name or SKU into the search box, **Then** only movements of matching variants are shown and the count/pagination reflects the filtered result.
2. **Given** the user has selected a seller, **When** they also select a category, **Then** only rows matching both are shown; clearing either filter widens the list again.
3. **Given** a variant has no picture, **When** its row is displayed, **Then** a placeholder of the same size is shown instead of a broken image.
4. **Given** a variant's product or seller was later deleted or deactivated, **When** its row is displayed, **Then** the row still renders with whatever name is known and does not break the page.
5. **Given** the user applies search or filters that match nothing, **When** the list is empty, **Then** an explanatory empty message is shown, not a blank table.

---

### User Story 2 - Open a variant's transaction history from the Stock page (Priority: P1)

From any row, the user clicks **History** to see every stock movement for that variant (type, change, before → after, reference/reason, by whom, time), using the existing transaction-history dialog. The dialog title shows the variant name as well as the SKU, so it is obvious which item is being viewed.

**Why this priority**: History is how stock discrepancies are investigated; the dialog already exists, so the value is in reaching it directly from the Stock list and identifying the variant at a glance.

**Independent Test**: Click History on a row, confirm the dialog opens for that variant only, its title contains both the variant name and the SKU, and its existing type and date filters still work.

**Acceptance Scenarios**:

1. **Given** a row for variant "M" of a product, **When** the user clicks History, **Then** the dialog lists only that variant's movements, newest first.
2. **Given** the dialog is open, **When** it renders, **Then** its title shows the variant name together with the SKU (for example `Transaction history — <variant name> · <SKU>`).
3. **Given** a variant has never had a movement, **When** History is opened, **Then** the dialog's empty message is shown.
4. **Given** the history is loading or fails to load, **When** the dialog is open, **Then** the existing loading and retry behaviour is preserved.

---

### User Story 3 - See total stock and stock value per product on the Products page (Priority: P2)

On the Products page, each product row shows the total available stock across all its variants and the total value of that available stock before any of it is sold, so the owner can judge inventory size and potential revenue without opening each product.

**Why this priority**: Valuable for planning and for stock-taking, but the Products page already works without it; it does not block Stories 1–2.

**Independent Test**: Take a product with three variants of known stock and price; confirm the row's total stock equals the sum of the variants' stock and the total value equals the sum of stock × price per variant, formatted like other money values on the page.

**Acceptance Scenarios**:

1. **Given** a product with variants of stock 4, 10 and 0, **When** the Products list loads, **Then** the product's total available stock shows 14.
2. **Given** the same product, **When** the list loads, **Then** the total value equals the sum over variants of stock × selling price.
3. **Given** a product whose variants are all inactive or all at zero stock, **When** the list loads, **Then** totals show 0 and zero money, not blank or an error.
4. **Given** any role that can open the Products page, **When** the list loads, **Then** the new columns reveal no cost price.

---

### Edge Cases

- A variant's stock is negative or zero: shown as-is (zero is a valid value, not blank).
- Search text contains special characters or only spaces: treated as plain text / ignored, never an error.
- Very large lists: search and filters apply to the whole dataset, not only the visible page, and pagination stays correct.
- Filtering by a seller or category that no longer exists is not possible from the UI, but a stale link/URL with such a value must degrade to an empty list, not a crash.
- Soft-deleted products or variants that still have movement history remain reachable for history and show a clear "deleted" cue rather than disappearing.
- DEMO and LIVE data must never mix on this page or in the totals.
- A cashier-type role without access to Stock/Products menus continues to be refused, as today.

## Requirements *(mandatory)*

### Functional Requirements

**Stock page**

- **FR-001**: The Stock page MUST provide a search box that matches variant name, product name and SKU (partial, case-insensitive).
- **FR-002**: The Stock page MUST provide filters for seller and category, combinable with search and with the existing movement-type and date-range filters where those still apply.
- **FR-003**: Each movement row MUST show, in addition to its existing columns (type, change, before → after, reference/reason, by, time): variant picture (placeholder when none), variant name, seller, category, the variant's current available stock, and a **History** action. Available stock is the variant's live figure at the time the list is loaded, not the "after" value of that movement, and may therefore repeat across rows of the same variant.
- **FR-004**: Search and filters MUST apply to the full dataset on the server side of pagination (results and page counts reflect the filter, not just the loaded page).
- **FR-005**: Existing capabilities MUST keep working: stock adjustment, Excel export, bulk import, clicking a SKU to open the product detail (for users with Products access).
- **FR-006**: The Stock page MUST remain a movement ledger (one row per stock movement, newest first); it MUST NOT be converted into a per-variant list.

**History dialog**

- **FR-007**: The History action MUST open the existing transaction-history dialog scoped to that single variant.
- **FR-008**: The dialog title MUST show the variant name together with the SKU.
- **FR-009**: The same title change MUST apply everywhere the dialog is opened (Stock page and the product variant list), so the dialog is consistent.

**Products page**

- **FR-010**: Each product row MUST show total available stock (sum of current stock over the product's variants).
- **FR-011**: Each product row MUST show total available product money before sales, defined as the sum over the product's variants of available stock × selling price (potential revenue if everything sells at current prices). Cost price is not used.
- **FR-012**: Totals MUST count the product's active variants only [ASSUMPTION: inactive variants are excluded; confirm during planning if the owner wants them included], and MUST show 0 when there is no stock.
- **FR-013**: Money MUST be shown in the same currency format as other money values in the app.
- **FR-014**: The new totals MUST NOT expose or allow derivation of cost price; they rely on selling price only.

**Cross-cutting**

- **FR-015**: Access control is unchanged: Stock page requires the Stock menu; History opened from Products requires the Products menu; the new data MUST NOT be exposed to roles outside those menus.
- **FR-016**: All new labels MUST exist in both supported interface languages.

### Key Entities

- **Variant**: a sellable version of a product with a name, optional picture, SKU, current available stock, selling price and cost price.
- **Product**: groups variants; belongs to one seller (artist) and one category.
- **Stock movement**: an append-only record of a stock change for a variant (type, quantity change, before/after, reference or reason, user, time).
- **Seller / Category**: the labels shown and filtered on the Stock page.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can locate any specific variant's stock by typing part of its name in under 10 seconds, with no need to read SKU codes.
- **SC-002**: Every row on the Stock page shows picture (or placeholder), variant name, seller, category and available stock without opening another screen.
- **SC-003**: A user can reach any variant's complete transaction history in at most 2 clicks from the Stock page, and the dialog identifies the variant by name and SKU 100% of the time.
- **SC-004**: For every product, the displayed total stock and total value equal the hand-calculated sums over its variants (0 discrepancies across a sample of at least 20 products).
- **SC-005**: Search and filters narrow a list of 1,000+ variants to the right results in under 2 seconds as perceived by the user.
- **SC-006**: No new figure on the Stock or Products pages is based on, or reveals, cost price.

## Assumptions

- Variant picture falls back to the product picture, then to a placeholder, matching how pictures behave elsewhere in the app.
- "Seller" is the artist/seller that owns the product (the app labels artists as sellers).
- "Available stock" is the variant's current stock figure, the same number the POS uses; on a movement row it is the live figure, which can differ from that row's "after" value.
- Because the Stock page stays a ledger, History on a row opens the same variant dialog that the existing SKU-driven flow offers; the movement-type and date filters remain on the page.
- Totals on the Products page are per product row; a grand total across all products is out of scope unless requested.
- Stock adjustment, export and import behaviour themselves are unchanged by this feature (their file formats are not extended here).
- The existing transaction-history dialog and its filters are reused rather than redesigned.
- DEMO/LIVE separation and role-based menu access follow the existing rules without change.
