**Classification: INTERNAL**

# Feature Specification: Product Export Column Update

**Feature Branch**: `049-product-export-columns` (git) · spec directory `041-product-export-columns`

**Created**: 2026-10-16

**Status**: Draft (clarified: FR-011 resolved, export-only; updated 2026-10-16: total stock column and filter-aware export)

**Input**: User description: "in product page, update export to show: artist name after artist code; category name after category code; remove product segment; remove is_preorder, preorder_eta, product_is_active, variant_is_active, initial_stock, image_filename, variant_image_filename; move variant_name after product_name; add item_label with format: {last 7 char from sku} - {category name} {variant name}; add code after sku with value last 7 char from sku; add profit margin after sell price (in money format)"

## Current state (from code, for reference)

The product export is `GET /exports/products` (`MasterDataExportService::productRows()`), one row per product variant, with headers defined in `App\Support\MasterDataSheets::PRODUCTS`. Current column order:

`sku, artist_code, category_code, product_segment, product_name, description, is_preorder, preorder_eta, product_is_active, variant_name, cost_price, sell_price, low_stock_alert, variant_is_active, initial_stock, image_filename, variant_image_filename`

The same header list is shared by the Excel template and the import (`MasterDataImportService`), which is the basis of the round-trip rule in the project guidance.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read a product export that names its artist and category (Priority: P1)

A booth owner exports the product list and wants to read each row without looking up codes: the artist name appears right after the artist code, and the category name right after the category code.

**Why this priority**: Codes alone are not readable in a spreadsheet; this is the most-requested readability gain.

**Independent Test**: Export products for a store with at least one artist and one category; check that `artist_name` sits directly after `artist_code` and `category_name` directly after `category_code`, with the correct names on each row.

**Acceptance Scenarios**:

1. **Given** a variant whose artist code is `RYU` named "Ryu Studio", **When** the owner exports products, **Then** the row shows `RYU` in `artist_code` and "Ryu Studio" in the column immediately after it.
2. **Given** a variant whose category code is `KY` named "Keychain", **When** the owner exports products, **Then** the row shows `KY` in `category_code` and "Keychain" in the column immediately after it.

---

### User Story 2 - Identify each variant by a short code and a readable label (Priority: P1)

The owner wants a short SKU suffix (`code`) and a single human-readable label (`item_label`) per variant, so a row can be recognised at a glance, for example on a stock count sheet.

**Why this priority**: These two columns are the stated purpose of the change; they are derived, so they add no data entry.

**Independent Test**: For a variant with SKU `RYU-KY-SAK-001`, category "Keychain" and variant name "Standard", the export shows `code = SAK-001` directly after `sku`, and `item_label = SAK-001 - Keychain Standard`.

**Acceptance Scenarios**:

1. **Given** SKU `RYU-KY-SAK-001`, **When** the owner exports, **Then** `code` is `SAK-001` and sits immediately after `sku`.
2. **Given** that same variant, **When** the owner exports, **Then** `item_label` is `SAK-001 - Keychain Standard` (the SKU's last 7 characters, a space, a hyphen, a space, the category name, a space, the variant name).
3. **Given** a variant whose SKU is shorter than 7 characters, **When** the owner exports, **Then** `code` is the whole SKU and `item_label` is built from that whole SKU.

---

### User Story 3 - See the profit per unit next to the selling price (Priority: P2)

The owner wants to see the margin per unit without doing the subtraction, in the same money format as the cost and sell columns.

**Why this priority**: Useful for pricing review, but the export works without it.

**Independent Test**: For a variant with sell price 25000.00 and cost price 10000.00, the export shows `profit_margin = 15000.00` directly after `sell_price`.

**Acceptance Scenarios**:

1. **Given** sell price 25000.00 and cost price 10000.00, **When** the owner exports, **Then** `profit_margin` is `15000.00` directly after `sell_price`.
2. **Given** a variant whose sell price is lower than its cost price, **When** the owner exports, **Then** `profit_margin` is a negative money value (for example `-500.00`), not clamped to zero.

---

### User Story 4 - Export only the columns the owner reads (Priority: P2)

The owner removes columns that are not needed for reading or stock counting: the product segment, the preorder and active flags, the initial stock, and the image filenames.

**Why this priority**: Reduces noise; the removals are simple once the layout is set.

**Independent Test**: Export products and confirm that none of the removed headers appear, and that the remaining columns keep their values unchanged.

**Acceptance Scenarios**:

1. **Given** any store with products, **When** the owner exports, **Then** the headers `product_segment`, `is_preorder`, `preorder_eta`, `product_is_active`, `variant_is_active`, `initial_stock`, `image_filename` and `variant_image_filename` do not appear.
2. **Given** a variant, **When** the owner exports, **Then** its `variant_name` appears directly after `product_name`.

---

### User Story 5 - Stock on hand next to the description (Priority: P2)

The owner wants the stock available for each variant in the same export row, right after the description, so a count or a stock check does not need the separate stock sheet.

**Why this priority**: Stock on hand is a common reason to open the list during a booth count. It adds one read of data that already exists and no new entry.

**Independent Test**: For a variant with current stock 12, the export shows `total_stock_available = 12` directly after `description`.

**Acceptance Scenarios**:

1. **Given** a variant with 12 units in stock, **When** the owner exports products, **Then** `total_stock_available` is `12` and sits directly after `description`.
2. **Given** a variant with no stock left, **When** the owner exports products, **Then** `total_stock_available` is `0`, not blank.

---

### User Story 6 - Export only what the screen shows (Priority: P1)

The owner searches or filters the Products screen by text, artist or category, then exports. The file contains exactly the products the screen lists under those filters, not the whole catalogue. With no filter applied, the export still contains every variant.

**Why this priority**: An export that ignores the filters hands the owner products they did not select and forces manual deletion of rows before a file is shared or used for counting.

**Independent Test**: With a search term that matches one product, the export returns only the variants of that product. With no filter, all variants are returned as before.

**Acceptance Scenarios**:

1. **Given** a search term that matches only "Keychain Sakura", **When** the owner exports with that search active, **Then** every row belongs to "Keychain Sakura" and no other product appears.
2. **Given** the artist filter is set to one artist, **When** the owner exports, **Then** only variants of products owned by that artist appear.
3. **Given** two categories are selected, **When** the owner exports, **Then** variants of products in either category appear (same meaning as on screen).
4. **Given** a search term and an artist filter are both active, **When** the owner exports, **Then** only products matching both appear.
5. **Given** no search or filter is active, **When** the owner exports, **Then** every variant of every product appears, as today.
6. **Given** the filters match no product, **When** the owner exports, **Then** the file contains the header row only.
7. **Given** more products match than fit on one screen page, **When** the owner exports, **Then** all matching products are exported, not only the first page.

---

### Edge Cases

- A SKU shorter than 7 characters: `code` and the label prefix use the whole SKU (no padding, no error).
- A SKU whose last 7 characters contain the separator (e.g. `SAK-001` already contains `-`): the value is taken literally, no re-formatting.
- A variant with an empty or deleted-looking category name: the label still uses the category name as stored; the export must not fail.
- A cost price or sell price with more than 2 decimals or stored as zero: margin is computed from the stored values and formatted to 2 decimals.
- Products with several variants: one row per variant, each with its own `code`, `item_label` and `profit_margin`.
- Export of a store with no products: header row only, no error.
- Search text matching a variant SKU or variant name: the screen lists the product, so the export contains every variant of that product, not only the matching variant. This mirrors what the owner sees.
- Inactive products: the screen does not hide them, so the export does not hide them either.
- A filter value that refers to an artist or category that no longer exists: it matches nothing, as it does on screen.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The products export MUST include `artist_name` immediately after `artist_code`, holding the artist's display name for that row.
- **FR-002**: The products export MUST include `category_name` immediately after `category_code`, holding the category's name for that row.
- **FR-003**: The products export MUST NOT include the columns `product_segment`, `is_preorder`, `preorder_eta`, `product_is_active`, `variant_is_active`, `initial_stock`, `image_filename` and `variant_image_filename`.
- **FR-004**: The products export MUST place `variant_name` immediately after `product_name`.
- **FR-005**: The products export MUST include `code` immediately after `sku`, whose value is the last 7 characters of that row's `sku` (or the whole `sku` if it is shorter than 7 characters).
- **FR-006**: The products export MUST include `item_label` with the value `{code} - {category name} {variant name}`, using the same `code` as FR-005.
- **FR-007**: The products export MUST include `profit_margin` immediately after `sell_price`, computed as `sell_price − cost_price` from the stored values, formatted as a money string with two decimals (the same format as `cost_price` and `sell_price`), and allowed to be negative.
- **FR-008**: Every other column that remains in the export MUST keep its current value and meaning.
- **FR-009**: The export MUST keep one row per product variant and the existing sort order (product name, then SKU).
- **FR-010**: Derived columns (`artist_name`, `category_name`, `code`, `item_label`, `profit_margin`) MUST be computed on the server, never supplied by the client.
- **FR-011**: The change applies to the products EXPORT only. The Excel template (`GET /imports/master-data/template`) and the import (`POST /imports/master-data`) keep the current 17-column product header list unchanged, so import behaviour does not change. Decision by product owner (option A): an exported product file is not guaranteed to re-import as-is; this is an accepted trade-off against the usual export/import round-trip rule.

- **FR-012**: The products export MUST include `total_stock_available` immediately after `description`, holding the variant's current stock on hand as a whole number. Zero MUST be written as `0`, never blank.
- **FR-013**: The products export MUST accept the same filters the Products screen uses: search text, artist (one or more), and category (one or more). They MUST have the same meaning as on screen: search matches product name, variant SKU, or variant name; filters combine with AND across kinds and OR within one kind.
- **FR-014**: The products export MUST contain exactly the variants of the products that match those filters, with no page limit.
- **FR-015**: With no filter supplied, the products export MUST contain every variant, as it does today.
- **FR-016**: The Products screen's export action MUST send the filters currently applied on that screen, so the downloaded file matches what the owner sees. Today the action sends no filters.
- **FR-017**: Filter values are validated by the server the same way as the Products list. A value that matches nothing produces no rows; the export MUST NOT return products the Products list would not return for the same filters.



### Key Entities *(include if feature involves data)*

- **Product variant row (export)**: One line per variant. Derived fields: `code` (last 7 characters of the SKU), `item_label` (code, category name, variant name), `profit_margin` (sell price minus cost price).
- **Artist**: Contributes `artist_name` through its code (`artist_code`).
- **Category**: Contributes `category_name` through its code (`category_code`).
- **Export filter set**: The search text, artist ids and category ids currently applied on the Products screen. Sent with the export request; they decide which products appear.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For a store with 100 variants, the exported product file has exactly the columns listed in the target layout (see Assumptions), with none of the 8 removed headers present.
- **SC-002**: 100% of exported rows have `artist_name`, `category_name`, `code`, `item_label` and `profit_margin` filled (except `profit_margin` may be negative; never blank) for variants that have an artist and category.
- **SC-003**: The owner can identify a variant's full name from one exported row in under 5 seconds, without consulting any other sheet.
- **SC-004**: `profit_margin` equals sell price minus cost price for every exported row, checked against the stored values.
- **SC-005**: For any filter combination on the Products screen, the export contains exactly the variants of the products that screen lists, checked on a seeded store with at least three artists and three categories.
- **SC-006**: `total_stock_available` equals the stock shown for that variant on the Stock screen, for every exported row.

## Assumptions

- "Product page" means the Products screen (`ProductsView`) and its export action, which calls `GET /exports/products` (file `data-produk.xlsx`). Today that action sends no filters; FR-016 changes this, so the SPA is in scope for this feature, not only the endpoint.
- "Total stock available" is read as the variant's current stock on hand (`current_stock`), because every export row is one variant and the stock sheet is per variant. ASSUMPTION: a product-level sum across its variants was considered and rejected, since it would repeat the same number on every variant row of that product. To be confirmed by the product owner if they meant the sum.
- The export returns all matching products in one file; it does not reuse the list's pagination (FR-014).
- Target column order after this change (ASSUMPTION, derived from the request):
  `sku, code, artist_code, artist_name, category_code, category_name, product_name, variant_name, item_label, description, total_stock_available, cost_price, sell_price, profit_margin, low_stock_alert`.
  The request did not give a position for `item_label`; it is placed after `variant_name`. `description` keeps its relative place after the name columns.
- "Profit margin" means the absolute amount per unit (sell price minus cost price) in money format, not a percentage, because the request said "in money format".
- The `{category name}` and `{artist name}` values are the names as stored on the category and artist records, with no transformation.
- The shared header list `MasterDataSheets::PRODUCTS` is NOT changed; the export uses its own layout (product-owner decision, option A). Template and import keep the current columns.
- Existing sales, stock and settlement data are not affected: this change is limited to the export layout and derived values.
- Image columns were already blank in the export; removing them loses no data in the export file itself.
- DEMO/LIVE filtering behaves exactly as today (the export continues to read only the active mode's variants).
- No new endpoint, no database change, no new permission: the export stays gated as it is today (owner/admin/inventory).
