# Feature Specification: UI/UX Refinements Batch

**Feature Branch**: `009-ui-ux-refinements`

**Created**: 2026-09-04

**Status**: Draft

**Input**: User description: "Batch UI/UX refinements: login page cleanup (remove local-install/metric badges), unify Purchase/Inventory/Settings menu colors with theme, Sales page redesign (transaction list first, drop product table, transaction-# opens a product-sold popup instead of a receipt, product name in that popup opens a product-detail popup), rename Artist→Penjual (ID) / Sellers (EN), add delete for Events and Customers when no transactions exist, add a customer transaction history view (including preorders), add a per-customer stats table+chart to the Dashboard, add a stock-by-artist drilldown (variant count, total stock) to Reports, remove Settings' Data Backup section, and rework the navbar (active event name, store name, user name + logout moved to top-right, logout text localized)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cleaner, on-brand navigation and login (Priority: P1)

A shopkeeper or cashier opens BoothPOS at the start of an event day. The login screen no longer shows internal deployment details (local install address, hard-coded performance badges) that mean nothing to them. Once logged in, the sidebar's Purchase, Inventory, and Settings menu icons/text match the rest of the menu's color instead of standing out in a fixed gray, and the top navbar clearly shows which store and which event is currently active, with their own name and a properly-labelled logout control grouped together in the top-right corner.

**Why this priority**: This is the first thing every user sees on every session; it's pure UI cleanup with no data dependencies, and it removes literally-wrong/irrelevant marketing copy from a shipped local product.

**Independent Test**: Load the login page and visually confirm the removed elements are gone; log in and confirm sidebar menu coloring is consistent and the navbar shows store name, active event name, user name, and a themed logout button top-right.

**Acceptance Scenarios**:

1. **Given** the login page is rendered, **When** the user views it, **Then** the "Instalasi lokal · 127.0.0.1:8001" line, the "· Instalasi lokal" badge, and the "< 30 dtk / < 15 mnt / 0 transaksi hilang" metric badges are not present anywhere on the page.
2. **Given** a logged-in user viewing the sidebar, **When** they compare the Purchase, Inventory, and Settings menu items to any other top-level menu item, **Then** all use the same color treatment (default and active/hover states), and that color follows the active store theme like every other menu item.
3. **Given** a logged-in user with an active event, **When** they view the top navbar on any screen, **Then** the store name and the active event's name are both visible, and their own logged-in username together with a logout control are shown grouped in the top-right corner.
4. **Given** the interface language is set to Indonesian, **When** the user views the logout control, **Then** its label reads "Keluar"; **Given** the language is set to English, **When** they view it, **Then** it reads the English equivalent ("Log out" / "Sign out" — see Assumptions), matching whatever the app's existing i18n convention uses for that concept elsewhere.

---

### User Story 2 - Streamlined Sales page with drill-down popups (Priority: P1)

A user reviewing today's sales wants to see the list of transactions immediately, without scrolling past a separate product-summary table first. When they click a transaction number, they see exactly what was sold in that transaction (as a table), not a full receipt printout. From there, clicking a product name shows that product's details without leaving the transaction popup.

**Why this priority**: Directly requested workflow change to the most-used reporting screen (Sales), reordering and replacing existing UI — high visibility, no new backend data model needed since order/order-item data already exists.

**Independent Test**: Open the Sales page; confirm the transaction list renders above the fold with no product summary table on the page; click a transaction number and confirm a "products sold" popup (table) appears instead of a receipt; click a product name inside it and confirm a product-detail popup opens.

**Acceptance Scenarios**:

1. **Given** the Sales page loads, **When** the user views the layout, **Then** the transaction list appears above where the product table used to be, and no product summary table is rendered anywhere on the page.
2. **Given** the transaction list is visible, **When** the user clicks a transaction number, **Then** a popup opens showing a table of the products sold in that transaction (product name, quantity, price, subtotal) — the popup is not the printable receipt.
3. **Given** the products-sold popup is open, **When** the user clicks a product name in that table, **Then** a second popup (or the same popup transitioning to a detail view) shows that product's details (e.g., category, artist/seller, price, current stock, image if available).
4. **Given** the products-sold popup is open, **When** the user closes it, **Then** they return to the Sales page transaction list without navigating away.

---

### User Story 3 - Seller terminology rename (Priority: P2)

Any user viewing labels that currently say "Artist" (Indonesian UI) or "Artists" (English UI) instead sees "Penjual" / "Sellers" respectively, consistently across the app.

**Why this priority**: Pure copy change, but touches many screens; grouped as its own story since it's independently shippable and testable via a text search, distinct from the Sales/data-model work.

**Independent Test**: Switch language to Indonesian and confirm no visible UI label reads "Artist"/"Artists" (all read "Penjual"/variants like "Daftar Penjual"); switch to English and confirm all read "Sellers"/"Seller" as grammatically appropriate. Confirm underlying route paths, field names, and API contracts are unaffected (label-only change).

**Acceptance Scenarios**:

1. **Given** the Indonesian locale is active, **When** the user browses menus, tables, filters, and forms referencing this concept, **Then** every visible label reads "Penjual" (or its singular/plural Indonesian form) rather than "Artist"/"Artists".
2. **Given** the English locale is active, **When** the user browses the same surfaces, **Then** every visible label reads "Sellers"/"Seller" rather than "Artist"/"Artists".
3. **Given** the rename is applied, **When** the user performs any Artist/Penjual-related action (create, filter, report), **Then** the underlying behavior is unchanged — only the displayed text differs.

---

### User Story 4 - Delete Events and Customers with no transactions (Priority: P2)

An owner/admin who created a test or duplicate Event or Customer record, and it has never been used in a transaction, can delete it outright instead of leaving clutter in the list.

**Why this priority**: A genuinely new capability (delete endpoints + guards) rather than copy/layout change — moderate scope, valuable for data hygiene, but not blocking the P1 visual work.

**Independent Test**: Create a new Event with no orders/preorders against it and delete it successfully; attempt to delete an Event that has at least one order/preorder and confirm it is blocked with a clear reason. Repeat both cases for Customer.

**Acceptance Scenarios**:

1. **Given** an Event has zero associated orders and zero associated preorders, **When** an authorized user requests deletion, **Then** the Event is removed and no longer appears in Event lists.
2. **Given** an Event has at least one associated order or preorder, **When** deletion is attempted, **Then** the system blocks the deletion and explains why (an existing-transaction conflict), leaving the Event unchanged.
3. **Given** a Customer has zero associated orders and zero associated preorders, **When** an authorized user requests deletion, **Then** the Customer is removed.
4. **Given** a Customer has at least one associated order or preorder, **When** deletion is attempted, **Then** the system blocks the deletion and explains why, leaving the Customer unchanged.
5. **Given** a user without delete authorization, **When** they attempt either deletion, **Then** the system denies the action.

---

### User Story 5 - Customer transaction history (Priority: P2)

A user looking at a specific customer can see every transaction — regular sales and pre-orders alike — that customer has ever made, in one place.

**Why this priority**: New read-only view built on existing Order/Preorder data; valuable for customer service and directly enables/complements User Story 6's dashboard stats, but is independently useful and testable on its own.

**Independent Test**: Open a customer with a mix of completed orders and preorders (including different preorder statuses) and confirm all of them appear in the customer's transaction history, correctly identified by type.

**Acceptance Scenarios**:

1. **Given** a customer with both regular orders and preorders on record, **When** a user opens that customer's transaction history, **Then** all of them are listed, each clearly labeled as either a regular sale or a pre-order (with its status).
2. **Given** a customer with no transactions, **When** a user opens their transaction history, **Then** an empty state is shown rather than an error.
3. **Given** a transaction history entry, **When** the user selects it, **Then** they can see that transaction's detail (items, amounts, dates) — reusing the existing order/preorder detail views rather than duplicating that logic.

---

### User Story 6 - Dashboard per-customer statistics (Priority: P3)

An owner/admin viewing the Dashboard can see, at a glance, which customers are transacting the most — as both a table and a chart.

**Why this priority**: Analytics/nice-to-have layered on top of data already exposed by Story 5; lowest urgency of the batch, reasonable to ship last.

**Independent Test**: Load the Dashboard with seeded sales data across several customers and confirm a per-customer statistics table (e.g., transaction count, total spend) and a corresponding chart both render and agree with each other and with the underlying transaction data.

**Acceptance Scenarios**:

1. **Given** the Dashboard is loaded, **When** the user views the customer statistics section, **Then** a table lists customers with their transaction count and total transaction value for the current filter/period.
2. **Given** the same data, **When** the user views the accompanying chart, **Then** it visually represents the same per-customer figures shown in the table.
3. **Given** the Dashboard's existing day/period filter, **When** the user changes it, **Then** the customer statistics table and chart update to match, consistent with how the rest of the Dashboard already filters.

---

### User Story 7 - Stock-by-artist drilldown in Reports (Priority: P3)

A user viewing the "stock by artist" report can click into an artist/seller row to see the detail behind the summary number: how many variants they have and total stock across those variants.

**Why this priority**: Incremental enhancement to an existing report; smallest, most isolated change in the batch.

**Independent Test**: Open the stock-by-artist report, click a seller row, and confirm a detail view shows variant count and total stock consistent with the underlying product/stock data for that seller.

**Acceptance Scenarios**:

1. **Given** the stock-by-artist report is displayed, **When** the user clicks a seller's row, **Then** a detail view (popup or expanded row) shows that seller's variant count and total stock.
2. **Given** the detail view is open, **When** the user closes it, **Then** they return to the summary report unchanged.

---

### User Story 8 - Remove Settings Data Backup section (Priority: P3)

A user opening Settings no longer sees the "Data Backup" section, since backup is a CLI-only operation (`app:backup`) not meant to be triggered from the UI.

**Why this priority**: One-line removal; bundled at the end as the smallest, most mechanical change.

**Independent Test**: Open Settings and confirm no Data Backup section, controls, or references remain.

**Acceptance Scenarios**:

1. **Given** the Settings page is open, **When** the user scans all sections, **Then** no "Data Backup" (or "Cadangkan sekarang") section or control is present.

---

### Edge Cases

- Deleting an Event or Customer that has a *soft-deleted* or otherwise indirectly-linked order/preorder still counts as "has transactions" and must be blocked — the guard checks for existence of history, not just active/visible records.
- A customer's transaction history must respect the existing DEMO/LIVE data-mode boundary — only transactions in the currently active mode are shown, matching every other list in the app.
- The Sales page's products-sold popup must handle a transaction whose items reference a since-deleted or since-changed product/variant gracefully (show the item as recorded, not crash), consistent with how the rest of the app treats historical line items.
- The per-customer dashboard stats and stock-by-artist drilldown must both apply the same role-based visibility rules as their parent screens (Dashboard, Reports) — no new exposure of data a given role couldn't already see.
- Renaming "Artist" → "Penjual"/"Sellers" is a display-label-only change; it must not rename database columns, API fields, route segments, or the `Artist` model/entity itself, to avoid an unscoped backend rewrite.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The login page MUST NOT display the local-install address badge ("Instalasi lokal · <host>"), the standalone "· Instalasi lokal" badge, or the three hard-coded performance metric badges ("< 30 dtk per transaksi", "< 15 mnt rekap artist", "0 transaksi hilang").
- **FR-002**: The sidebar's Purchase, Inventory, and Settings menu items MUST use the same color treatment (default, hover, and active states) as every other top-level sidebar menu item, and MUST follow the active store theme color the same way the other items already do.
- **FR-003**: The Sales page MUST display the transaction list as the primary/topmost content and MUST NOT display a separate product summary table.
- **FR-004**: On the Sales page, clicking a transaction number MUST open a popup listing the products sold in that transaction as a table, and MUST NOT open or trigger the printable receipt view.
- **FR-005**: Within that products-sold popup, clicking a product name MUST open a product detail popup for that product.
- **FR-006**: Every UI label reading "Artist" in the Indonesian locale MUST be changed to "Penjual" (with correct singular/plural/possessive forms as used in context).
- **FR-007**: Every UI label reading "Artists" in the English locale MUST be changed to "Sellers" (with "Seller" used where singular is grammatically required).
- **FR-008**: This rename MUST be limited to user-visible text; underlying identifiers (routes, field names, model names, API contracts, database columns) are unaffected.
- **FR-009**: The system MUST allow an authorized user to delete an Event only when that Event has zero associated orders and zero associated preorders.
- **FR-010**: The system MUST reject Event deletion with a clear conflict response when the Event has any associated order or preorder, leaving the Event unchanged.
- **FR-011**: The system MUST allow an authorized user to delete a Customer only when that Customer has zero associated orders and zero associated preorders.
- **FR-012**: The system MUST reject Customer deletion with a clear conflict response when the Customer has any associated order or preorder, leaving the Customer unchanged.
- **FR-013**: Event and Customer delete actions MUST be restricted to the same authorization tier already used for other Event/Customer management actions in this app (owner/admin, per existing master-data conventions).
- **FR-014**: The system MUST provide a view, per customer, of all of that customer's transactions — both regular orders and preorders — each clearly labeled by type and current status.
- **FR-015**: The customer transaction history view MUST support opening an individual transaction to see its existing order/preorder detail.
- **FR-016**: The Dashboard MUST display per-customer transaction statistics (at minimum: transaction count and total transaction value) as both a table and a chart, for the same period/filter the Dashboard already applies elsewhere.
- **FR-017**: The stock-by-artist report MUST allow drilling into a seller row to reveal that seller's variant count and total stock.
- **FR-018**: The Settings page MUST NOT display a Data Backup section or any control associated with it.
- **FR-019**: The top navbar MUST display the active event's name and the store's name, visible on every authenticated screen.
- **FR-020**: The top navbar MUST group the logged-in user's name together with the logout control in the top-right corner.
- **FR-021**: The logout control's label MUST follow the currently selected interface language, consistent with this app's existing i18n mechanism (excluding the login screen and receipts, which remain Indonesian-only per existing convention).
- **FR-022**: All new/changed screens and popups MUST continue to respect existing role-based authorization and the DEMO/LIVE data-mode boundary already enforced elsewhere in the app.

### Key Entities

- **Event**: Existing entity; gains a delete capability guarded by absence of associated Orders/Preorders.
- **Customer**: Existing entity; gains a delete capability guarded by absence of associated Orders/Preorders, and a consolidated transaction-history view spanning Orders and Preorders.
- **Order / Preorder**: Existing entities; read from (not modified) to power the customer transaction history, the Sales page's products-sold popup, and the Dashboard's per-customer statistics.
- **Artist**: Existing entity, unchanged in structure — only its user-facing label changes to "Penjual" (ID) / "Seller(s)" (EN).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the login page, a scan of all rendered text finds zero occurrences of the removed install-address/metric copy.
- **SC-002**: A visual/theme comparison of all top-level sidebar menu items shows 100% consistent color treatment across Purchase, Inventory, Settings, and all other items, across at least two different theme colors.
- **SC-003**: From the Sales page, a user can go from viewing the transaction list to seeing a specific transaction's sold products in at most 2 clicks (transaction # → popup), without a receipt ever appearing in that path.
- **SC-004**: A full-app text scan in each supported locale finds zero remaining instances of "Artist"/"Artists" used as a seller-facing label (excluding non-UI identifiers).
- **SC-005**: 100% of Event/Customer delete attempts against records with existing transactions are blocked; 100% of delete attempts against records with no transactions succeed.
- **SC-006**: A user can find any of a customer's past transactions (order or preorder) from that customer's own page, without navigating to the general Sales/Preorder list and filtering manually.
- **SC-007**: The Dashboard's per-customer table and chart figures match the sum of that customer's underlying transactions to the cent, for 100% of sampled customers.
- **SC-008**: A user can view an individual seller's variant count and total stock from the stock-by-artist report in at most 1 additional click beyond the summary view.
- **SC-009**: The Settings page contains no backup-related UI element.
- **SC-010**: From any authenticated screen, a user can identify the current store name, the active event, their own username, and find the logout control without scrolling, all within the top-right region of the navbar for the last two.

## Assumptions

- "Delete" for Events and Customers means a hard delete of records with no transaction history (not a soft-delete/archive), consistent with this being a data-hygiene cleanup action; if the codebase's existing pattern for comparable master-data deletes (Artist/Category, per CLAUDE.md) uses soft-delete, this feature follows that same existing pattern rather than introducing a new deletion model.
- "No transactions" for the delete guard means no `orders` or `preorders` rows referencing the record (matching the same relations already used for reporting), not merely no *completed* transactions — a `draft`/`ordered`-status preorder still blocks deletion.
- The English logout label follows whatever term this app's existing i18n dictionary already uses for the concept elsewhere in English screens (e.g. "Log out"); if no prior English string exists, "Log out" is used.
- "Sellers"/"Seller" is the correct English pluralization/singularization pattern to mirror "Artists"/"Artist" 1:1 across all label sites (menu items, table headers, filter chips, form labels).
- The Sales page's products-sold popup and product-detail popup are net-new UI components layered on already-available order-item and product data; no new backend fields are assumed necessary beyond what `GET /orders`/order detail and `GET /products` already expose.
- Dashboard per-customer statistics reuse the existing report/dashboard data source and its existing period filter rather than introducing a new customer-specific analytics endpoint, unless investigation during planning shows the existing endpoint cannot supply per-customer aggregation.
- The stock-by-artist drilldown's "variant count" and "total stock" are computed from the same `products`/`stock_movements`/`current_stock` data already used elsewhere in Reports and Products, not a new stock concept.
- This feature is UI/UX-focused; where a requirement implies a new backend capability (Event/Customer delete, customer transaction history, dashboard customer stats, stock-by-artist drilldown), the corresponding minimal API surface is in scope, but no unrelated backend refactor is implied.
