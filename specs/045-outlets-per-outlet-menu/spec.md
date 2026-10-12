**Classification**: INTERNAL

# Feature Specification: Outlets (replacing Sellers) — Outlet-Scoped Operations and Per-Outlet Menu

**Feature Branch**: `045-outlets-per-outlet-menu`

**Created**: 2026-10-11

**Status**: Draft — clarified (Q1 = C, Q2 = B, Q3 = stock and transfers per outlet)

**Input**: User description: "In the Sellers page (`/artists`): (1) change *sellers* to *outlet*; (2) change the endpoint from `artists` to `outlets`; (3) use the multiple outlet data to update the Products pages (table, grid, add, edit, etc.)." Clarified 2026-10-11: **Q1 = C** (orders use the outlet's data **and** tables, floor and users are tied to outlets; "also product should relate to each outlet") and **Q2 = B** (the former seller reports are re-based on the outlet where the sale was made).

## Clarifications (2026-10-11)

- **Q1 → C.** Outlets become a real dimension of operations, not only of the Products pages: a cashier shift belongs to one outlet; orders, tables, the floor board, sales lists and reports are outlet-scoped; users are assigned to outlets; and **every product has a relation to every outlet** (carried or not, price, sold-out state), not a single owner only.
- **Q2 → B.** Reports that grouped by seller (recap, cost, settlement, stock-by-seller, pre-order by seller) group by the **outlet where the sale was made**. History from before the change has no selling outlet; it keeps the attribution it had (see FR-026), so past figures do not move.
- **Q3 → stock and transfers per outlet.** Stock is no longer one organisation-wide number: every outlet has its own balance for each item and each material, every stock movement belongs to an outlet, sales draw from the order's outlet, and stock can be **transferred between outlets** (dispatched from one, received at the other, with the goods "in transit" in between). **Confirmed:** the product's relation to every outlet is *in addition to* a required home outlet (item code, purchase-order/BOM rule, legacy attribution).
- **Earlier owner decisions (2026-10-11):** outlets are the **existing seller records renamed in place** (same identities, history, codes); the constitution amendment is **ratified as part of this feature**.

## Design reference (FACT — read from the screenshot and the earlier design captures)

1. **Current Sellers page** (image 23, `/artists`): title "Sellers", subtitle "Merchandise owners consigning their goods", actions *Export .xlsx*, *Bulk import*, *Add seller*; search "Search seller name…"; table CODE · NAME · CONTACT · PRODUCTS · STATUS · Edit / Delete; paging. Demo data: HOS Hoshizora Craft, NEK Nekoyama Studio, YUK Yukishiro Works, three products each.
2. **Outlet-aware Back Office design** (captures used for 041–044): top bar with an outlet selector ("Semua outlet · 3") and the signed-in user's role; Products list with an availability chip per outlet; variants table with a price column per outlet (empty = follows base, grey); add/edit form with "Aktif di outlet" checkboxes; item page availability table with one row per outlet; the Pesanan/floor board grouped by outlet ("Warkop WJM 1 — 6 dari 16 meja terisi"); outlet pickers in the 86 dialog and bulk-price drawer.

## Current state (FACT — from this repository, 2026-10-11)

- The seller record (code, name, phone, e-mail, notes, active) is served at `/artists` (page) and `/api/v1/artists` (API) and appears as **"Sellers/Penjual"** in ~45 front-end and ~60 back-end files: product owner and product codes, POS filters and cards, stock, pre-orders and documents, purchase orders and the BOM rule tying a purchase order's seller to the variant's seller, sales filters, per-seller opening cash, the recap / cost / settlement / stock-by-seller / pre-order reports and their exports, the master-data Excel workbook (`artists` sheet, `artist_code` columns), the licence tiers (Pro = one seller, Master = several).
- **No operational record is tied to a seller today**: orders, cashier shifts, dining tables, the floor/tab screens, users and the dashboard are not outlet-aware; product availability (86, discontinued) and prices are global; stock is one organisation-wide number per variant (and per material), changed only through one append-only ledger (sales, returns, pre-order arrival/handover, adjustments, purchases, initial) with a reason and a user on every row.
- The Floor and the new Sales "Tables" board (044) read every active table; the dashboard (042) reads every order; reports group order items by the item's owner (seller).
- Sales and orders carry a business date and a shift; a shift is opened by a cashier and is not tied to a place.
- Draft constitution amendment v2.0.0 (`specs/042-dashboard-revamp/constitution-amendment.md`) describes "one organisation, one or more outlets, one server" and is **unratified**.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — The Sellers page becomes the Outlets page (Priority: P1)

An owner opens the menu entry that used to say "Sellers" and finds **Outlets**: the same list, search, export, import, add/edit/delete, now titled "Outlets" with outlet wording ("Add outlet", "Search outlet name…"), at `/outlets`. Every existing seller is now an outlet with its code, name, contact, status and product count.

**Why this priority**: entry point and the rename asked for first; every later story depends on the entity being an outlet.

**Independent Test**: open `/outlets`, confirm wording and data, create/edit/deactivate/delete an outlet; confirm the old page address redirects and the old API address no longer exists.

**Acceptance Scenarios**:

1. **Given** the three existing sellers, **When** the owner opens Outlets, **Then** the same three records appear as outlets (code, name, contact, products, status) in the current language.
2. **Given** the old page address `/artists`, **When** opened, **Then** the user lands on `/outlets` (redirect), and the old API address no longer serves requests.
3. **Given** the outlets API, **Then** list/create/read/update/delete/search/pagination/export behave as the seller operations did.
4. **Given** an outlet with products or recorded history, **When** deleted, **Then** it is refused with an outlet-worded conflict message; deactivating is always allowed.
5. **Given** the Pro licence (one outlet), **When** a second outlet is added, **Then** it is refused with an outlet-worded licence message; the Master licence allows several.
6. **Given** a role whose permissions listed the old Sellers menu, **Then** it keeps access to Outlets with no manual change.

---

### User Story 2 — "Outlet" everywhere the product said "seller" (Priority: P1)

Wherever the product showed or asked for a seller — forms, filters, documents, reports, exports, the Excel workbook, the licence screen — the wording and references are **outlet** in both languages, with behaviour unchanged except where Stories 3–8 deliberately change it.

**Independent Test**: walk every screen and export in both languages; round-trip an Excel export; compare totals before/after the rename.

**Acceptance Scenarios**:

1. **Given** any screen or new document that showed a seller, **Then** it shows the same information labelled outlet (for example "Owner seller" → "Home outlet", "Seller recap" → "Outlet recap").
2. **Given** a workbook exported before the change (sheet `artists`, column `artist_code`), **When** imported, **Then** it is accepted with the same result as before; **and** a workbook exported after the change uses outlet names and imports cleanly.
3. **Given** previously recorded transactions, pre-orders, purchase orders and settlements, **Then** their numbers and links are unchanged.

---

### User Story 3 — The Products list shows every outlet's state (Priority: P1)

On the Products list (grid and table) each item shows one availability chip **per outlet** (available / sold out / not carried), the list filters by outlet, and its figures can be read for one outlet or all outlets.

**Independent Test**: with three outlets and items carried differently (one sold out at one outlet), open grid and table, check each chip, filter by one outlet.

**Acceptance Scenarios**:

1. **Given** three outlets, **Then** each card/row shows a chip per outlet (code + state) in the Outlets page order; with many outlets chips collapse to a summary ("18 available · 2 sold out").
2. **Given** the top-bar outlet selector set to one outlet, **Then** the list shows that outlet's items, chips and figures (86 today counts that outlet's marks).
3. **Given** a single-outlet install, **Then** no chips, filter or selector appear and the page behaves as today.
4. **Given** a user assigned to some outlets only, **Then** only those outlets appear anywhere on the page.

---

### User Story 4 — Add/edit an item with its relation to each outlet (Priority: P1)

The add and edit forms show, for **each outlet**, whether the item is carried there and its optional outlet price per variant (empty = follows the base price), plus the item's **home outlet** (required; drives the item code). Saving stores the item and its relation to every outlet together.

**Independent Test**: create an item carried at two of three outlets with a different price at one; reopen and confirm; edit and change.

**Acceptance Scenarios**:

1. **Given** three outlets, **When** adding an item, **Then** the form shows *Home outlet*, *Active at outlets* (all checked by default) and a price column per outlet in the variants table.
2. **Given** an outlet price left empty, **Then** it follows the base price (shown grey) and later base price changes carry through.
3. **Given** the item unchecked for an outlet, **Then** it is not orderable and not listed at that outlet, and its outlet price is ignored.
4. **Given** an outlet price differing from the base by more than the approval threshold while menu release is on, **Then** the existing approval rule applies (compared with the base price).
5. **Given** a single outlet, **Then** only the home outlet is shown; no checkboxes or price columns.
6. **Given** editing, **Then** current relations and prices are prefilled and image/stock/BOM actions remain.

---

### User Story 5 — Per-outlet 86, prices and bulk change on the item page (Priority: P2)

The item page lists one availability row per outlet (state, who, until when); *Mark 86* asks which outlets (default: the selected outlet); the variants drawer and the bulk price change can target specific outlets; the price history names the outlet.

**Acceptance Scenarios**:

1. **Given** an item carried at three outlets, **When** marked 86 for one, **Then** only that outlet shows sold out; undoing affects only the chosen outlets.
2. **Given** the bulk drawer with one outlet as scope, **Then** only that outlet's prices change; the preview shows old → new for that outlet.
3. **Given** an outlet price change, **Then** the price history names the outlet and the base history is unchanged.
4. **Given** discontinuing, **Then** it applies to the item everywhere, as today.

---

### User Story 6 — Shifts and orders belong to an outlet and use its data (Priority: P1)

A cashier opens a **shift for one outlet**. Every order, tab and payment of that shift belongs to that outlet, uses that outlet's availability (carried / 86 / unavailable) and outlet prices, and the outlet appears on receipts, order lists and exports. The outlet is decided by the server from the shift, never taken from the screen.

**Why this priority**: it is what makes the per-outlet data real (Q1 = C).

**Independent Test**: open shifts for two outlets; make the same item sold out at one; confirm it can only be ordered in the other; confirm prices and receipts.

**Acceptance Scenarios**:

1. **Given** a shift opened for outlet X and an item 86 at X, **Then** it cannot be ordered in that shift (and is greyed on the POS), while a shift for outlet Y can still order it.
2. **Given** an outlet price at X, **When** the item is added in a shift of X, **Then** the line is priced at the outlet price; recorded prices stay snapshots as always.
3. **Given** an item not carried at X, **Then** it does not appear on X's cashier screens and a forged order for it is refused.
4. **Given** a cashier assigned to one outlet, **When** opening a shift, **Then** the outlet is that outlet; a cashier assigned to several chooses among them; a cashier with no outlet cannot open a shift.
5. **Given** a recorded order, **Then** its outlet is fixed and shown on the receipt, the order list and its detail; changing a user's assignment later does not change past orders.
6. **Given** a single-outlet install, **Then** shifts, orders and receipts behave as today with no outlet choice shown.

---

### User Story 7 — Tables, floor and Sales board per outlet (Priority: P1)

Dining tables belong to an outlet; the Floor screen, the tab screens and the Sales **Tables** board (feature 044) show the **selected outlet's** tables and orders (the board groups by outlet when "all outlets" is selected, with each group headed "Outlet name — N of M tables occupied"). Takeaway orders belong to the shift's outlet.

**Acceptance Scenarios**:

1. **Given** two outlets with tables, **When** a cashier of outlet X opens the floor, **Then** only X's tables and open orders appear; the owner viewing "all outlets" sees one group per outlet.
2. **Given** the Sales Tables board, **Then** its four figures are for the selected outlet or for all outlets, and equal what the tiles show.
3. **Given** table setup, **When** a table is created, **Then** it belongs to an outlet (default: the selected/only outlet); tables of one outlet can share names with another's.
4. **Given** an order's table, **Then** moving or merging a tab is only possible within the same outlet.

---

### User Story 8 — Users, the outlet selector and scoped views (Priority: P1)

Each user is assigned to one or more outlets (owners and admins implicitly to all). Signed-in users with more than one outlet see an **outlet selector in the top bar** ("All outlets · 3" or one outlet) that scopes the pages that have outlet data: Products list, Floor and Sales (list and board), Dashboard, Reports and the stock views that show outlet-carried items. A user never sees data of an outlet they are not assigned to; the server enforces it.

**Acceptance Scenarios**:

1. **Given** a user assigned to outlet X only, **Then** no selector appears and every page shows X only; a direct request for another outlet's data is refused.
2. **Given** a user assigned to X and Y, **When** choosing "X", **Then** all scoped pages show X; the choice persists across pages and reloads for that user.
3. **Given** an owner or admin, **Then** "All outlets" is available and is the default.
4. **Given** a single-outlet install, **Then** no selector and no assignment screen appear; all users work at the one outlet.
5. **Given** the Users screen, **Then** an administrator can assign outlets to a user; unassigned non-admin users cannot open a shift or see outlet data until assigned (with a clear message).

---

### User Story 9 — Reports and exports by selling outlet (Priority: P1)

The reports that grouped by seller now group by the **outlet where the sale was made** (Outlet recap, cost and profit by outlet, stock by outlet's carried items where applicable, pre-order by outlet, settlements) and can be filtered to one outlet or all. Before the change there is no selling outlet: that history keeps the attribution it had, so past figures do not move.

**Acceptance Scenarios**:

1. **Given** sales made after the change at two outlets, **Then** the recap shows each outlet's units and sales; the grand total equals the all-outlets total.
2. **Given** history from before the change, **Then** its figures are identical to the previous reports (attributed as before) and are labelled as historical in the report.
3. **Given** a report period spanning the change, **Then** the totals include both, never double-count, and each row says whether it is by selling outlet or historical.
4. **Given** an export, **Then** it has the same grouping and labels as the screen.

---

### User Story 10 — Stock per outlet (Priority: P1)

Every item and every material has its **own balance at each outlet**. The Stock screen shows the selected outlet's balances (or, for "all outlets", the balance per outlet and the total); sales, returns, voids, pre-order arrival and handover, purchases received, stock-takes and adjustments act on **one outlet's** balance, taken from the order's, purchase order's or user's outlet. Low-stock alerts are per outlet.

**Why this priority**: the owner asked for stock per outlet (Q3); without it outlets would still be selling from one shared pool.

**Independent Test**: give two outlets different balances of one item, sell it at each, and confirm each balance and the ledger moved independently; sell the last unit at one outlet and confirm it is refused there but still orderable at the other.

**Acceptance Scenarios**:

1. **Given** outlet X holds 5 and outlet Y holds 20 of an item, **When** 5 are sold at X, **Then** X shows 0 (further sales at X are refused with an insufficient-stock message) while Y still shows 20 and can sell.
2. **Given** a sale, **Then** the ledger row records the outlet, the order reference and the new balance of that outlet; voiding or returning the sale restores the same outlet's balance.
3. **Given** the Stock screen with "All outlets", **Then** each item shows the balance per outlet and the total; with one outlet selected, only that outlet's balance; the screen's totals equal the sum of the rows.
4. **Given** a manual adjustment or stock-take, **Then** it applies to the chosen outlet (a user may only choose outlets they are assigned to), requires a reason as today, and writes a ledger row for that outlet.
5. **Given** a purchase order received, **Then** the goods/materials enter the stock of the **purchase order's outlet**.
6. **Given** a pre-order, **Then** it belongs to the outlet of the user who created it; its arrival increases and its handover decreases that outlet's balance (with the existing paid-in-full rule).
7. **Given** a low-stock threshold on an item, **Then** the alert is raised per outlet (an outlet below the threshold alerts even if another has plenty), and the dashboard's attention list names the outlet.
8. **Given** items that do not track stock (made to order), **Then** they behave as today at every outlet (no balance, never refused).
9. **Given** a single-outlet install, **Then** the Stock screen and ledger look and behave as today.

---

### User Story 11 — Transfer stock between outlets (Priority: P1)

An authorised user moves items and materials from one outlet to another with a **transfer**: they choose the source and destination outlets and the lines (item or material, quantity), and **dispatch** it — the goods leave the source's balance at once and are shown **in transit**. The destination then **receives** it (the whole transfer, or the quantities that actually arrived) and the balance enters the destination. A transfer can be cancelled before it is received, which returns the goods to the source.

**Why this priority**: the owner asked for transfers per outlet (Q3); it is how outlets rebalance stock without fake adjustments.

**Independent Test**: dispatch 10 of an item from X to Y; confirm X dropped by 10, the 10 are in transit and neither outlet can sell them; receive at Y and confirm Y rose by 10 and the transfer is closed with a complete ledger trail on both sides.

**Acceptance Scenarios**:

1. **Given** outlet X holds 30 of an item, **When** a transfer of 10 to Y is dispatched, **Then** X shows 20, the transfer shows "in transit" with 10, and total stock across outlets plus in-transit still equals 30.
2. **Given** a transfer in transit, **When** Y receives it, **Then** Y's balance increases by the received quantity, the transfer becomes "received", and both outlets' ledgers reference the transfer number.
3. **Given** Y receives fewer than were sent, **Then** the received quantity enters Y, the shortfall is recorded on the transfer as a discrepancy with a required reason, and the difference is not silently lost (it stays visible on the transfer and in a discrepancy report).
4. **Given** a transfer in transit, **When** cancelled by an authorised user before receipt, **Then** the goods return to the source's balance with a ledger row, and the transfer shows "cancelled".
5. **Given** a quantity larger than the source holds, **Then** the dispatch is refused with an insufficient-stock message and nothing changes; two users dispatching the last units at the same moment never produce a negative balance.
6. **Given** a user assigned to X only, **Then** they can dispatch from X and see transfers to/from X; only users assigned to the destination can receive; an owner/admin can act on all outlets.
7. **Given** the transfer list, **Then** it shows number, from → to, status (in transit / received / cancelled / with discrepancy), lines, who and when for each step, filterable by outlet, status and date; a detail view shows every line and its ledger rows.
8. **Given** a destination outlet that does not carry an item, **Then** the transfer is allowed with a visible warning ("not carried at the destination — add it to the menu there to sell it"); transfers to or from an inactive outlet are refused.
9. **Given** the permission model, **Then** dispatching, receiving and cancelling are separate action permissions that roles can hold independently, and every step writes an audit row in the same transaction as the stock change.
10. **Given** a single-outlet install, **Then** transfers are not offered.

---

### Edge Cases

- **Stock on migration**: each existing balance (items and materials) is assigned to the item's **home outlet** (items) or to the first outlet (materials, which have no home outlet); the ledger is back-filled with the outlet so history sums per outlet equal the old totals; the owner redistributes with transfers or adjustments. A reconciliation (per item: Σ outlet balances = old total) is part of the migration.
- **Transfer edge cases**: a transfer line for an item or material that is later deactivated still completes; a transfer cannot be edited after dispatch (cancel and recreate); receiving the same transfer twice is refused; a transfer in transit when an outlet is deactivated can still be received or cancelled; receiving more than was sent is refused.
- **Concurrent sale and transfer** of the last units: exactly one succeeds, the other is refused; balances never go negative for tracked items.
- **Existing data on migration**: each existing seller is an outlet; each existing user is assigned to all outlets (nothing disappears for anyone) until an administrator narrows it; each existing item is carried at every outlet with prices following the base; existing dining tables are assigned to the first outlet (or the only outlet); open shifts and open tabs at the moment of change belong to the first outlet.
- **Deactivated outlet**: no new shifts, no new availability/price entry; history, reports and recorded orders stay.
- **Outlet code change**: item codes built from the home outlet's code never change; an outlet's code is fixed once items reference it, as for sellers.
- **User moved between outlets**: past orders keep their outlet; an open shift keeps its outlet until closed.
- **Item carried nowhere / outlet carrying nothing**: allowed; flagged under data quality.
- **Two outlets, one open tab each on a "same" table name**: independent; the floor is per outlet.
- **Many outlets (for example 20)**: chips collapse; price columns scroll; the selector is searchable.
- **Excel import** with outlet columns: unknown outlet codes reported per row, all-or-nothing as today.
- **Stock and the home outlet**: the home outlet does not hold stock by itself; it is only where migrated balances start.
- **Cash and opening cash**: opening cash is recorded per shift for the shift's outlet; legacy per-seller opening-cash entries keep displaying on old shifts.
- **Purchase orders and BOM**: a purchase order belongs to the outlet that buys; the BOM source rule that tied a purchase order's seller to the variant's seller continues to compare the purchase order's outlet with the item's **home outlet** (unchanged rule, new wording).
- **Business date and time zone**: per-outlet figures use the existing store time zone and day cut-off (one setting for the organisation).

## Requirements *(mandatory)*

### Functional Requirements

**Outlets page and entity (US1)**

- **FR-001**: The Sellers page MUST become **Outlets** (title, subtitle, buttons, placeholders, empty states, dialogs, messages) in Indonesian and English, served at `/outlets`; `/artists` MUST redirect.
- **FR-002**: The data interface MUST be exposed at the **outlets** address with the same operations as the sellers' (list, search, paginate, create, read, update, delete, export); the old **artists** address MUST be removed. Existing records keep their identity; no data is copied or lost.
- **FR-003**: Outlet fields: code (fixed once referenced), name, contact phone, contact e-mail, notes, active, product count.
- **FR-004**: Deleting an outlet that has products, orders, shifts, tables or users assigned MUST be refused with a conflict message; deactivating is always allowed.
- **FR-005**: The licence rule MUST limit the number of outlets (Pro one, Master several) with outlet wording.
- **FR-006**: Roles that could use the Sellers menu MUST keep access to Outlets automatically.

**Rename everywhere (US2)**

- **FR-007**: Every user-visible seller/penjual/artist wording MUST become outlet in both languages; documents already issued keep what they showed.
- **FR-008**: The master-data workbook MUST use outlet sheet/column names for export and template and MUST still accept the old names on import.
- **FR-009**: Behaviour of renamed concepts not covered by FR-010…FR-030 (product codes, purchase-order/BOM rules, pre-order item attribution) MUST be unchanged.

**Products pages (US3–US5)**

- **FR-010**: Every item MUST have a **home outlet** (required; drives the item code and the purchase-order/BOM rule) and a **relation to every outlet**: carried or not, an optional price per variant, sold-out state with reason, who and until when.
- **FR-011**: The Products list (grid and table) MUST show one chip per outlet (collapsing when many) and MUST be filterable by outlet; its figures MUST be computable for the selected outlet or all.
- **FR-012**: The add and edit forms MUST show the home outlet, a carried checkbox per outlet (default all) and an optional price per outlet per variant (empty = follows the base); saving stores the item and all outlet relations together.
- **FR-013**: The variants drawer/table MUST show and edit outlet prices; price history MUST record the outlet for an outlet-price change.
- **FR-014**: Mark-86 MUST be per outlet; the item page MUST list one availability row per outlet; undo affects only the chosen outlets; discontinue stays item-wide.
- **FR-015**: Bulk price change MUST accept an outlet scope (base price, all outlets, chosen outlets) with a preview per outlet; approval compares an outlet price with the base price.
- **FR-016**: Excel import/export of products MUST carry the home outlet code and optionally per-outlet carried state and prices; unknown codes reported per row.
- **FR-017**: A single-outlet install MUST show no outlet-specific control on any page and behave exactly as before.

**Shifts, orders, floor (US6, US7)**

- **FR-018**: A cashier shift MUST belong to exactly one outlet, decided by the server from the user's assignment and the cashier's choice among assigned outlets; the outlet MUST NOT be accepted from a request body.
- **FR-019**: Every order, tab, payment and kitchen ticket MUST carry the shift's outlet, fixed at creation and shown on receipts, order lists, order detail and exports.
- **FR-020**: Ordering MUST use the order's outlet: an item not carried at the outlet or sold out there MUST be refused (409) and hidden/greyed on the cashier screens; the line price MUST be the outlet price if set, else the base price (a recorded snapshot as today).
- **FR-021**: Dining tables MUST belong to an outlet; the Floor screen, tab screens and the Sales Tables board MUST show only the selected outlet's tables and orders, grouped by outlet when "all outlets" is selected; moving or merging tabs is limited to one outlet.
- **FR-022**: The Sales Tables board's figures (open orders, occupied tables, over 45 minutes, open value) MUST be for the selected outlet or all, and equal its tiles.

**Users, selector, scope (US8)**

- **FR-023**: Users MUST be assignable to one or more outlets (administrators implicitly all); a non-administrator without an assignment cannot open a shift or see outlet-scoped data, with a clear message.
- **FR-024**: Users with more than one outlet MUST get a top-bar outlet selector ("All outlets" or one outlet) that scopes the Products list, Floor, Sales, Dashboard, Reports and outlet-aware stock views, persists per user, and is absent when only one outlet is visible.
- **FR-025**: Outlet visibility MUST be enforced server-side on every route that returns outlet-scoped data (lists, details, figures, exports); a request for an outlet the user cannot see MUST be refused or return nothing, never another outlet's data.

**Reports (US9)**

- **FR-026**: The recap, cost/profit, settlement, pre-order-by-seller and stock-by-seller reports MUST group by the **outlet where the sale was made** and be filterable by outlet; history recorded before the change MUST keep its former attribution (the former seller, now outlet) so past figures do not change, and rows MUST be labelled as historical where applicable.
- **FR-027**: Report totals over a period spanning the change MUST include both kinds without double counting; exports MUST match the screen.

**Stock and transfers (US10, US11)**

- **FR-031**: Every item and every material MUST have a separate balance per outlet; every stock movement (sale, return, void, pre-order arrival/handover, purchase received, adjustment, stock-take, initial, transfer dispatch/receipt/cancel) MUST record its outlet, and an outlet's balance MUST always equal the sum of its ledger rows.
- **FR-032**: Sales, returns and voids MUST act on the **order's outlet**; purchase receipts on the **purchase order's outlet**; pre-order arrival and handover on the **pre-order's outlet** (the outlet of the user who created it); adjustments and stock-takes on an outlet the acting user is assigned to. A tracked item with insufficient balance at the outlet MUST be refused (409), never going negative.
- **FR-033**: The Stock screen, stock history, stock exports and the master-data stock sheet MUST show and accept the outlet (per-outlet balances; "all outlets" shows per-outlet rows and the total); the stock import sets absolute balances **per outlet**.
- **FR-034**: Low-stock alerts and the dashboard's low-stock attention item MUST be evaluated per outlet and name the outlet.
- **FR-035**: A **stock transfer** MUST have a number, a source outlet, a destination outlet (different, both active), one or more lines (item or material, quantity > 0) and a status (in transit / received / cancelled, plus a discrepancy flag).
- **FR-036**: Dispatch MUST reduce the source balance immediately and record the goods as in transit (not available at either outlet); the total of all outlet balances plus in-transit quantity MUST be unchanged by dispatch, receipt and cancellation.
- **FR-037**: Receipt MUST add the received quantities to the destination's balance; receiving less than sent MUST require a reason and keep the shortfall visible as a discrepancy; receiving more than sent MUST be refused; a transfer can be received once.
- **FR-038**: Cancelling an in-transit transfer MUST return its goods to the source with a ledger row; a received or cancelled transfer cannot be changed.
- **FR-039**: Dispatch, receipt and cancellation MUST be separate action permissions; a user may dispatch from and see transfers of only their assigned outlets and receive only at their assigned outlets (administrators all); each step MUST write an audit row inside the same transaction as its stock movement.
- **FR-040**: The system MUST provide a transfer list (filterable by outlet, status, date) and a transfer detail with its lines, steps, who/when and ledger rows, plus a discrepancy view; transfers MUST not be offered at a single-outlet install.

**Data safety and governance**

- **FR-028**: The migration MUST preserve every existing record and total (SC-002), assign defaults per the Edge Cases, be re-runnable, and be rehearsed on a populated copy before release.
- **FR-029**: The constitution amendment permitting "one organisation with one or more outlets, on one local machine or one shared server" MUST be ratified as part of this feature (version 2.0.0, text shown to the owner for final wording), with dated notes in `CLAUDE.md` and the PRD, before outlet-specific behaviour is released.
- **FR-030**: Single-machine, single-outlet installs MUST keep working unchanged, including offline-from-internet operation.

### Key Entities

- **Outlet**: a place that sells the menu (formerly *seller*): code, name, contact, notes, active; same records as before.
- **Outlet assignment**: which outlets a user may work at and see.
- **Shift (per outlet)**: a cashier shift with exactly one outlet; carries its orders' outlet.
- **Order outlet**: the outlet fixed on every order/tab/payment/ticket from its shift.
- **Dining table (per outlet)**: a table belonging to one outlet.
- **Home outlet** (of an item): the item's recorded owner outlet; drives item codes and the purchase-order/BOM rule.
- **Item–outlet relation**: for each item and outlet: carried or not, sold-out state (reason, who, until when), and optional prices per variant.
- **Outlet stock balance**: for each item or material and each outlet, the quantity on hand, derived from that outlet's ledger.
- **Stock movement (per outlet)**: one ledger row for one outlet (type, quantity, balance after, reason, user, reference).
- **Stock transfer**: a numbered movement of lines from a source outlet to a destination outlet with its status, steps (dispatched, received, cancelled), discrepancy and the quantities in transit.
- **Report attribution**: for each order item, the selling outlet (new data) or, for history, the former seller attribution.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A search of the interface in both languages finds no remaining "Seller / Penjual / Artist" wording on live screens, and the owner reaches Outlets from the menu in one click.
- **SC-002**: A before/after reconciliation of existing sellers, products, orders, pre-orders, purchase orders, settlements and report totals shows zero differences.
- **SC-003**: With three outlets, an owner sees in the Products list, without opening any item, which outlets carry each item and where it is sold out.
- **SC-004**: An owner can add an item carried at two outlets with a different price at one in under 2 minutes.
- **SC-005**: Marking an item sold out at one outlet leaves every other outlet's state unchanged in 100% of cases, and an order at that outlet for that item is refused in 100% of cases.
- **SC-006**: For two outlets, every order, table, tab and floor figure of one outlet is invisible to a user assigned only to the other, in 100% of checked routes (including direct requests and exports).
- **SC-007**: For sales made after the change, the sum of the per-outlet report rows equals the all-outlets total exactly; for history, the figures equal the previous reports exactly.
- **SC-008**: A single-outlet install shows no outlet control on any page, and all its existing scenarios pass unchanged.
- **SC-009**: An Excel workbook exported before the change imports with the same result as before (zero new errors).
- **SC-010**: The Outlets page, the Products list (500 items, 20 outlets), the Floor/Tables board (60 tables per outlet) and the outlet-scoped Dashboard each open in under 2 seconds.
- **SC-011**: After migration, for every item and material the sum of the outlet balances equals the previous single balance exactly, and each outlet's balance equals the sum of its ledger rows (zero differences).
- **SC-012**: For any set of transfers, the total of all outlet balances plus goods in transit is constant across dispatch, receipt and cancellation (checked over a randomised sequence), and no tracked balance ever goes negative, including with two simultaneous dispatches of the last units.
- **SC-013**: A user can create and dispatch a transfer of three lines in under 1 minute, and the destination can receive it in under 30 seconds.
- **SC-014**: Selling an item at one outlet never changes another outlet's balance, in 100% of checked cases.

## Assumptions

- **Rename in place:** outlets are the existing seller records (same identities, history, codes); no second entity.
- **Old API address removed, old page address redirects.** The app and its API ship together on one machine, so no `artists` API alias is kept.
- **Defaults on migration** as in Edge Cases (all users assigned to all outlets; all items carried at all outlets with base prices; tables and open work to the first outlet) — an administrator narrows afterwards.
- **Home outlet stays required** on items; "product relates to each outlet" (owner's wording) is satisfied by a relation to **every** outlet plus the home outlet. If the owner meant to *retire* the single home outlet, the item code, the purchase-order/BOM rule and legacy attribution need a different anchor — raise before planning is finalised.
- **Stock is per outlet (Q3)** for items and for materials; purchase receipts go to the purchase order's outlet; pre-orders belong to the creating user's outlet. Existing balances start at the item's **home outlet** (materials: the first outlet); the owner redistributes with transfers. Items that do not track stock stay unaffected.
- **Transfers move items and materials alike**, are two-step (dispatch then receive) with goods in transit, and have no approval step in this feature (separate action permissions instead). Printing a transfer slip is out of scope.
- **Reports attribution for history:** an order item without a selling outlet is attributed to its former seller (now outlet) so past numbers do not move (Q2 = B, FR-026).
- **Opening cash** is per shift for the shift's outlet; the legacy per-seller entries remain readable on old shifts.
- **Not in this feature:** per-outlet channels (dine-in/take-away/QR/delivery) and daily quotas from the design, per-outlet menu **releases**, device registry/heartbeat and per-outlet cash limits (042 Stage B), copy-menu tooling, per-outlet tax settings, a shared-server deployment guide, transfer slips/printing, transfer approval workflow, inter-outlet transfer pricing or accounting, per-outlet reorder points beyond the existing low-stock threshold.
- **One time zone and day cut-off** for the whole organisation (existing settings).
- **Constitution amendment** text is taken from `specs/042-dashboard-revamp/constitution-amendment.md`, updated for "outlet = renamed seller", and shown to the owner for final wording at ratification.
