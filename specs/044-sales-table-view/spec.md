**Classification**: INTERNAL

# Feature Specification: Sales Page — Tables View

**Feature Branch**: `044-sales-table-view`

**Created**: 2026-10-11

**Status**: Draft

**Input**: User description: "In the Sales page (`/sales`): add a toggle (image 26: a two-segment control *Daftar order | Meja*), add a Table view (English) / Meja view (Indonesian) as in image 27, and clicking one of the tables shows the details of its transaction."

## Design reference (FACT — read from the two screenshots)

1. **Toggle** (image 26): a small two-segment control, "Daftar order" and "Meja", the active segment filled in the brand colour, placed at the right of the page header (above the page content, level with the title).
2. **Tables view** (image 27, page title "Pesanan"):
   - a strip of four figures: *Order terbuka* (count, with a breakdown line), *Meja terisi* (occupied / total, with a percentage), *Meja > 45 menit* (count, with a hint line), *Nilai order terbuka* (money, with a hint line);
   - a "Status meja" panel with a subtitle ("Live · per 19.30 · klik meja terisi untuk detail order"), a legend (Kosong, Terisi, Pre-bill dicetak, > 45 menit) and the tables grouped under a heading with a count ("Warkop WJM 1 — 6 dari 16 meja terisi");
   - each table is a tile: name, a small status badge (*Kosong*, *Terisi*, *Pre-bill*, *Sebagian*), and for an occupied table the order number, the time it has been open ("19 mnt") and the open amount; an empty table shows "Tersedia";
   - tile colour follows the state (white = empty, light blue = occupied, purple = pre-bill, yellow = partially paid / over 45 minutes).

## Current state (FACT — from this repository, 2026-10-11)

- `/sales` lists **completed POS transactions** only, with filters, a summary, a shift panel and a transaction-detail popup per row. Open (not yet paid) restaurant tabs are not on it. The page is open to every role.
- The **Floor** screen (menu `floor`) already shows every active table, grouped by area, as free or occupied with the open tab's order number, amount and opening time, plus takeaway tabs; it refreshes by short polling and clicking a table opens the tab in the cashier tab screen (it can also open a tab on an empty table).
- A table's state is **derived**: occupied means it has exactly one open tab. There is no guest count, no "pre-bill printed" state, and no outlet grouping in the data today (tables are grouped by an *area* label; outlets are a later, gated stage).
- Back-office users can view an open tab's lines and totals through the tab detail, but the cashier tab screen also lets them edit it, which this feature must not offer.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Switch the Sales page between order list and tables (Priority: P1)

A manager opens Sales and sees a two-segment toggle in the header: **Daftar order / Order list** and **Meja / Tables**. The order list is the default and is exactly today's page. Choosing Tables swaps the content for the tables view; choosing the list brings the page back with its filters and position intact. The choice survives a reload and can be linked to.

**Why this priority**: The toggle is the entry point; without it nothing else is reachable, and it must never disturb the existing list.

**Independent Test**: Open `/sales`, switch to Tables and back, reload, and confirm the list, its filters and its summary are unchanged.

**Acceptance Scenarios**:

1. **Given** a user who may see tables, **When** they open Sales, **Then** the toggle shows "Daftar order / Order list" (active) and "Meja / Tables", and the page content is today's order list.
2. **Given** the toggle, **When** the user chooses Tables, **Then** the tables view replaces the list content, the address records the choice, and reloading the page opens it on Tables.
3. **Given** the user is on Tables, **When** they choose the list again, **Then** the list appears with the filters, sorting and selection they had before they left it.
4. **Given** the interface language is English, **Then** the segments read "Order list" and "Tables"; in Indonesian they read "Daftar order" and "Meja".
5. **Given** a user who may not see table data, **When** they open Sales, **Then** no toggle is shown and the page is exactly today's list (the control is absent, not disabled).

---

### User Story 2 — See every table's status at a glance (Priority: P1)

On the Tables view a manager sees the whole floor: four figures at the top and every active table as a tile, grouped by area, with takeaway orders in their own group. Occupied tables show the order number, how long they have been open and the open amount; empty tables say they are available. Tables open longer than 45 minutes stand out. The view keeps itself current without a reload.

**Why this priority**: This is the monitoring value the owner asked for; the click-through in Story 3 depends on it.

**Independent Test**: With a mix of empty tables, occupied tables and a takeaway order, open Tables and check each tile and each figure against the data; open a new order at a table and watch the tile change without reloading.

**Acceptance Scenarios**:

1. **Given** tables in two areas, **When** the Tables view opens, **Then** each area is a group with its name and "N of M tables occupied", and its tables are in the same order as on the Floor screen.
2. **Given** an occupied table, **Then** its tile shows the table name, an "occupied" badge, the order number, the elapsed time since the order was opened, and the order's current amount; **and** an empty table shows its name, an "empty" badge and "Available".
3. **Given** the four figures, **Then** *Open orders* counts all open orders (tables and takeaway), *Occupied tables* shows occupied / active tables with a percentage, *Tables over 45 minutes* counts occupied tables open longer than 45 minutes, and *Open order value* is the sum of the open orders' amounts — each equal to what the tiles show.
4. **Given** a table open for 46 minutes, **Then** its tile is visually flagged and it is counted in *Tables over 45 minutes*; at 44 minutes it is not.
5. **Given** open takeaway orders (no table), **Then** they appear in a separate "Takeaway" group as tiles labelled with their order label, and they are included in *Open orders* and *Open order value* but not in the table occupancy figure.
6. **Given** the view is open, **When** a cashier opens, changes or settles an order, **Then** within a few seconds the affected tile and the figures update without the manager doing anything, and the subtitle shows when the data was last refreshed.
7. **Given** no table is configured, **Then** an empty-state explains that no tables exist and points to where they are set up (only to users who may set them up).
8. **Given** the data cannot be loaded, **Then** the view shows an error with a Retry action, never an empty floor.

---

### User Story 3 — Click a table to see its transaction (Priority: P1)

Clicking an occupied table (or a takeaway tile) opens the details of its open transaction in place: table, order number, who opened it and when, how long it has been open, every line with quantity, modifiers, notes and price, the kitchen progress of the lines, discounts, the total, the amount already paid and the balance. It is **read-only**: nothing in this detail changes the order. Closing it returns to the Tables view exactly as it was.

**Why this priority**: The user explicitly asked for it ("click one of table, show details transaction"); it turns the board from a picture into a tool.

**Independent Test**: Click an occupied table and check every shown value against the order; confirm there is no way to add, remove, send, pay, move or void from it; click an empty table and confirm nothing opens.

**Acceptance Scenarios**:

1. **Given** an occupied table, **When** the manager clicks its tile, **Then** a detail panel opens showing the table name, order number, opened-by and opened-at, elapsed time, all lines (name, quantity, modifiers, note, line amount), the kitchen status per line (not sent / sent / ready / served), the subtotal, discounts, total, amount paid so far and remaining balance.
2. **Given** a partially paid order, **Then** the detail lists the payments already received (method, amount, time) and the tile is flagged "Partial / Sebagian".
3. **Given** the detail is open, **When** the order changes in the POS, **Then** the detail refreshes to the latest state (or marks itself as outdated) instead of showing stale lines silently.
4. **Given** the detail is open, **Then** it offers no add / edit / send / pay / move / merge / split / void action; if the user also has access to the cashier tab screen, a single clearly labelled link opens that screen for the same order.
5. **Given** an empty table, **When** the manager clicks it, **Then** nothing opens and the tile does not look clickable.
6. **Given** the order is paid or voided while the detail is open, **Then** the detail says it is no longer open and, for a paid order, links to its finished transaction in the order list.
7. **Given** keyboard use, **Then** every occupied tile can be reached and activated with the keyboard, the detail traps and returns focus properly, and Escape closes it.

---

### User Story 4 — Respect who may see what (Priority: P2)

The Sales page is open to every role today, but table and open-order data belongs to the people who work the floor. The toggle, the tiles and the detail appear only for roles that may see floor data; cost, margin and other figures that cashiers must not see never appear in the detail.

**Why this priority**: Protects existing role rules; small, but it must be right before release.

**Independent Test**: Open Sales as a role with and without floor access and compare; request the table data directly as the role without access.

**Acceptance Scenarios**:

1. **Given** a role without floor access, **Then** it sees no toggle, and a direct request for table data is refused.
2. **Given** a role with floor access but without cost visibility, **Then** the detail shows no cost or margin.
3. **Given** the demo/live data mode, **Then** the board and the detail show only the active mode's tables and orders.

---

### Edge Cases

- A table is deactivated while it has an open order: the tile still appears (flagged inactive) until the order is closed, so no open money disappears from the board.
- Two cashiers act on the same table at the same moment: the board shows the latest state after its next refresh and never shows one table as holding two orders.
- Very many tables (for example 60): the view stays usable and refreshes without noticeable delay; tiles wrap into rows.
- The browser tab is hidden or the device goes offline: refreshing pauses, the "as of" time stops advancing, and an offline notice appears; refreshing resumes when the tab or connection returns.
- A takeaway order without a label shows its order number.
- Business-day rollover: the 45-minute rule and the figures use the real time elapsed, not the business date, so an order opened before midnight is not mis-aged.
- The order list's filters (date, cashier, etc.) do not apply to the Tables view, which always shows the live floor; the view says so when the user switches.
- Time shown as "mnt/min" rolls over to hours and minutes for long-open orders.

## Requirements *(mandatory)*

### Functional Requirements

**Toggle**

- **FR-001**: The Sales page MUST show a two-segment toggle in its header, labelled "Daftar order / Order list" and "Meja / Tables" according to the interface language, with the active segment visually distinct.
- **FR-002**: The order list MUST remain the default and MUST be unchanged in content, filters, sorting, summary, export and actions.
- **FR-003**: The chosen view MUST be reflected in the page address so it survives reload and can be shared, and unknown values MUST fall back to the order list.
- **FR-004**: Switching views MUST NOT lose the order list's filters, sorting or selection, and MUST NOT reload unrelated data.
- **FR-005**: The toggle MUST be absent (not disabled) for users who may not see floor data.

**Tables view**

- **FR-006**: The view MUST show four figures: open orders, occupied tables out of active tables (with percentage), occupied tables open longer than 45 minutes, and the total value of open orders; each MUST equal what the tiles show.
- **FR-007**: The view MUST show every active table as a tile, grouped by area in the same order as the Floor screen, each group headed by its name and "N of M tables occupied"; tables without an area MUST form a group of their own, last.
- **FR-008**: An occupied tile MUST show the table name, an occupied badge, the order number, the elapsed time since the order was opened and the order's current amount; an empty tile MUST show the table name, an empty badge and "Available".
- **FR-009**: A tile MUST be flagged when its order has been open longer than 45 minutes, and a tile whose order has received part of its payment MUST be flagged as partially paid; the legend MUST explain every state used.
- **FR-010**: Open takeaway orders MUST be shown in a separate group, counted in *open orders* and *open order value*, and excluded from table occupancy.
- **FR-011**: States that the data cannot support today (a "pre-bill printed" state, guest counts, outlet grouping) MUST NOT be shown or faked; the legend and tiles show only real states.
- **FR-012**: The view MUST keep itself current automatically, show the time of the last refresh, pause while the page is hidden and resume when it returns, and keep the last good data visible with a notice when a refresh fails.
- **FR-013**: The view MUST show a loading state, an empty state (no tables configured) and an error state with Retry; a failed load MUST NOT look like an empty floor.

**Transaction detail**

- **FR-014**: Activating an occupied tile or a takeaway tile MUST open the details of that open transaction without leaving the Sales page; activating an empty tile MUST do nothing.
- **FR-015**: The detail MUST show: table (or takeaway label), order number, who opened it and when, elapsed time, every line (name, quantity, modifiers, note, line amount), kitchen status per line, subtotal, discounts, total, amount paid so far with the payments received, and the remaining balance.
- **FR-016**: The detail MUST be read-only: it MUST NOT offer any action that changes the order; if the user may use the cashier tab screen, it MAY link to that screen for the same order.
- **FR-017**: The detail MUST refresh while open, and MUST say clearly when the order has since been paid, voided, moved or merged (linking to the finished transaction when one exists).
- **FR-018**: The detail MUST be keyboard-operable, closable with Escape, return focus to the tile that opened it, and be announced properly to assistive technology.

**Access, data and language**

- **FR-019**: Table and open-order data MUST be available only to roles that may see floor data, enforced on the server; hiding controls in the interface is not sufficient.
- **FR-020**: Cost, margin and any figure cashiers cannot see elsewhere MUST NOT appear in the detail.
- **FR-021**: The view and the detail MUST show only the active demo/live mode's data.
- **FR-022**: All new text MUST exist in Indonesian and English; money uses the same format as the rest of the Sales page.
- **FR-023**: The feature MUST NOT change how orders are created, paid or stored, and MUST NOT write any data.

### Key Entities

- **Table**: a named seat location in an area, active or not; its state (empty / occupied) is derived from whether it has one open order.
- **Open order (tab)**: an unpaid order attached to a table or to takeaway, with lines, kitchen progress, discounts, payments received so far and an opening time.
- **Area**: the label that groups tables on the floor; tables with no label form their own group.
- **Floor snapshot**: the current set of tables with their open orders and the four figures, with the time it was taken.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A manager can tell which tables are occupied, which have been open over 45 minutes and the total value of open orders within 5 seconds of opening the Tables view.
- **SC-002**: From the Sales page, a manager reaches the full details of any occupied table's order in two actions (switch to Tables, click the table).
- **SC-003**: A change made at the POS (order opened, item added, order paid) is visible on the board within 5 seconds, with no manual reload, in 95% of cases.
- **SC-004**: The four figures equal the sum of what the tiles show in 100% of checked snapshots (no drift between figures and tiles).
- **SC-005**: The Tables view for 60 tables is usable (first tiles visible) in under 2 seconds on the store's normal device and network.
- **SC-006**: The order list behaves exactly as before for all existing scenarios (zero regressions in its current tests and behaviour).
- **SC-007**: A role without floor access sees no trace of the feature and cannot obtain table or open-order data by any route.
- **SC-008**: Zero actions that change an order are reachable from the board or the detail.

## Assumptions

- **Who sees it (ASSUMPTION):** the toggle, tiles and detail are offered to roles that already see the Floor screen's data; the Sales page itself stays open to every role as today. If the owner wants cashiers to see the board too, that is a separate permission decision.
- **Scope of "details transaction" (ASSUMPTION):** the detail is the read-only view of the *open* order on that table (lines, kitchen progress, payments so far, totals). Finished transactions stay in the order list with their existing detail popup.
- **Statuses shown (ASSUMPTION):** *Empty*, *Occupied*, *Partially paid* and *Over 45 minutes* are supported by the data. *Pre-bill printed* and guest counts in image 27 are not backed by data today and are left out until the data exists; the figure strip's "pre-bill" wording follows suit.
- **Grouping (ASSUMPTION):** tiles are grouped by the existing table area, not by outlet (outlets are not built; image 27's "Warkop WJM 1/2/3" corresponds to a later, gated stage). The "all outlets" selector and per-outlet figures are out of scope.
- **The 45-minute threshold (ASSUMPTION):** fixed at 45 minutes, not configurable in this feature.
- **KPI wording:** the four figures follow image 27, minus anything depending on unavailable data; their sub-lines show only values that exist (for example "N open · M with a table").
- **Refresh:** the board refreshes on a short interval (a few seconds) like the Floor screen; push updates are out of scope.
- **Empty table click:** does nothing — opening orders stays a cashier action on the Floor screen.
- **Out of scope:** editing orders from the back office, printing pre-bills, QRIS verification and returns from this view, per-outlet data, reservations, and any change to the order list.
