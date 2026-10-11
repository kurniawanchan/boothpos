# Feature Specification: Restaurant Dine-in Ordering Core (Tables, Open Tabs, Modifiers, Kitchen Tickets)

**Feature Branch**: `028-restaurant-dine-in-ordering`

**Created**: 2026-10-03

**Status**: Draft

**Input**: User description: "Feature 028 — Restaurant dine-in ordering core. Three slices delivered together: (1) tables and open orders (tabs) that are added to over several rounds and settled at the end, including move, merge and split bill; (2) modifiers and notes on menu items, with price deltas and selection rules, snapshotted onto the order; (3) kitchen/bar ticket flow routing items to preparation stations with new → preparing → ready → served statuses. Out of scope: tax and service charge, recipe-based ingredient stock deduction, QRIS/payment-provider integration, delivery-platform integrations, reservations, loyalty, multi-outlet, hardware thermal printing, table QR self-ordering."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set up tables and see the floor at a glance (Priority: P1)

An owner or admin defines the restaurant's tables once — a name or number, how many people it seats, and an optional area such as "Indoor", "Terrace" or "Smoking". From then on, any staff member who takes orders can open a single screen and immediately see every table and whether it is free or occupied, and which occupied tables have food waiting to be served.

**Why this priority**: Tables are the anchor every other dine-in capability hangs off. Without them there is nothing to attach a tab to. It is also the smallest slice that can be built, demonstrated and used on its own.

**Independent Test**: Create five tables in two areas, deactivate one, and confirm the floor screen lists the four active tables grouped by area, all shown as free, and that the deactivated one is not offered for new orders.

**Acceptance Scenarios**:

1. **Given** no tables exist, **When** an owner creates a table with a name, a seat count and an area, **Then** it appears on the floor screen as free, in that area.
2. **Given** two tables with the same name in the same area, **When** an owner tries to create the second, **Then** the system rejects it with a clear message and creates nothing.
3. **Given** a table that currently has an open tab, **When** any staff member views the floor screen, **Then** that table shows as occupied, with how long it has been occupied and the current tab total.
4. **Given** a table whose items have all been marked ready by the kitchen but not yet served, **When** a waiter views the floor screen, **Then** that table is visibly flagged as having items ready to serve.
5. **Given** a table that has ever had a tab, **When** an owner tries to delete it, **Then** the system refuses and offers to deactivate it instead, so past sales keep their table reference.
6. **Given** a cashier (who cannot manage master data), **When** they try to create, edit or deactivate a table, **Then** the system refuses; they can still view the floor screen.

---

### User Story 2 - Open a tab, add items over several rounds, and settle at the end (Priority: P1)

A guest sits at table 7. A waiter or cashier opens a tab for that table and adds the first round of items. Later the guests order more, and the waiter adds those to the same tab. When the guests ask for the bill, the cashier reviews the full tab and takes payment — in cash, by transfer, or split across several payment methods — exactly as the cashier would for any other sale. The tab becomes a completed sale with a receipt. A tab can also be opened without a table for takeaway orders.

**Why this priority**: This is the core of dine-in service and the biggest functional gap versus today's single-step "pay immediately" sale. It delivers value even before modifiers or kitchen tickets exist.

**Independent Test**: With an open cashier shift, open a tab on a free table, add two items, add a third item later, then pay the full amount with a cash and a transfer split. Confirm one completed sale exists with all three items, the table shows as free again, stock moved correctly, and the receipt matches the tab.

**Acceptance Scenarios**:

1. **Given** an open cashier shift and a free table, **When** staff opens a tab on that table, **Then** the table becomes occupied and the tab starts empty and unpaid.
2. **Given** an open tab, **When** staff adds, changes the quantity of, or removes items that have not yet been sent to preparation, **Then** the tab total updates immediately and always reflects current menu prices computed by the system, never an amount supplied by the screen.
3. **Given** an open tab that already has items, **When** staff adds another round of items, **Then** the new items are added to the same tab and the earlier items are unchanged.
4. **Given** a table that already has an open tab, **When** staff tries to open a second tab on it, **Then** the system refuses and takes them to the existing tab.
5. **Given** an open tab with a total of 250,000, **When** the cashier records payments that together cover the total (including a cash payment with change due), **Then** the tab is completed, a receipt is available, the table becomes free, and the same overpayment and change rules as a normal sale apply.
6. **Given** an open tab, **When** the cashier records payments that cover only part of the total, **Then** the system does not complete the tab; it remains open with the outstanding balance shown. (Settling part of a tab by choosing items is covered in User Story 5.)
7. **Given** a payment submission that is repeated after a network interruption, **When** the system receives the same submission again, **Then** the sale is recorded once, not twice.
8. **Given** a tab with no items, **When** staff closes it without payment, **Then** it is discarded with no stock or sales effect and the table becomes free.
9. **Given** a takeaway tab (no table), **When** staff opens and pays it, **Then** it behaves like any other tab but is identified by a number or customer name instead of a table.
10. **Given** the retail-style one-step sale screen, **When** staff completes a normal sale there, **Then** it behaves exactly as it did before this feature.

---

### User Story 3 - Choose modifiers and add notes when ordering an item (Priority: P2)

An owner, admin or inventory manager defines modifier groups for menu items: for example "Size" (Regular / Large +5,000), "Sugar" (Normal / Less / None), "Add-ons" (Extra shot +8,000, Oat milk +10,000, choose any), and "Spice level". Each group says whether the guest must choose, whether one or many options may be chosen, and the minimum and maximum. While adding an item to a tab, staff pick the options and may type a free-text note such as "no onion, sauce on the side". The chosen options and the note travel with that item through the kitchen and onto the receipt, and the item's price includes any option price differences.

**Why this priority**: Without modifiers a menu can't express real restaurant orders, but a simple venue can run on User Stories 1–2 alone, so this ranks below them.

**Independent Test**: Create a "Size" group (required, pick one) with a +5,000 option and attach it to one menu item. Add that item to a tab choosing the paid option and a note, then confirm the line price, the tab total and the receipt all include the +5,000 and show the choice and the note.

**Acceptance Scenarios**:

1. **Given** a modifier group attached to a menu item, **When** staff adds that item to a tab, **Then** the available options and the group's rules (required, single or multiple, minimum, maximum) are presented.
2. **Given** a required group, **When** staff tries to add the item without choosing within the allowed range, **Then** the system refuses with a message naming the group and the allowed range.
3. **Given** options with price differences (positive, zero or negative), **When** the item is added, **Then** the line price is the item price plus the sum of chosen differences, multiplied by quantity, computed by the system; a negative total for a line is rejected.
4. **Given** an item on a tab with chosen options and a note, **When** the menu owner later renames an option or changes its price, **Then** that tab line and every past sale still show the original names and prices.
5. **Given** the same menu item added twice with different modifiers or notes, **When** viewing the tab, **Then** they appear as separate lines, while identical choices may be combined into one line with a higher quantity.
6. **Given** an item with a note, **When** the kitchen or the receipt displays it, **Then** the note is shown in full next to the item.
7. **Given** a modifier option that has been deactivated after being used, **When** staff adds items, **Then** it is no longer offered, but earlier orders that used it are unaffected.

---

### User Story 4 - Send items to the kitchen or bar and track their progress (Priority: P2)

When staff finish a round, they "send" the unsent items. The system splits them by station — for example food to the Kitchen and drinks to the Bar — and each station sees its own incoming tickets on a station screen, oldest first, with the table, the items, their modifiers and notes, and how long they have been waiting. Station staff mark each item as preparing, then ready. Waiters see which items are ready, take them to the table and mark them served. A ticket can be viewed in a printable layout in the browser.

**Why this priority**: This replaces shouting orders across the pass and is the main efficiency gain for a restaurant, but it depends on the tab flow (User Story 2) existing first.

**Independent Test**: Add a food item and a drink to a tab, send them, and confirm that exactly one ticket appears on the Kitchen screen with the food and one on the Bar screen with the drink. Advance each through preparing and ready, mark them served, and confirm the table flag for "items ready" appears and clears at the right moments.

**Acceptance Scenarios**:

1. **Given** each menu category or item is assigned a station, **When** staff sends a round, **Then** one ticket per station is created containing only that station's items, and the items are no longer editable as "unsent".
2. **Given** an item with no station assigned (for example a packaged drink that needs no preparation), **When** it is sent, **Then** it creates no ticket and is immediately treated as ready to serve.
3. **Given** an item has both a station on the item itself and one on its category, **When** it is sent, **Then** the item's own station wins.
4. **Given** a new ticket is sent, **When** the station screen is open, **Then** the ticket appears within a few seconds without anyone refreshing the page.
5. **Given** an item that is new, **When** station staff mark it preparing, then ready, **Then** each change is recorded with who made it and when, and a waiter sees the item as ready.
6. **Given** an item that is ready, **When** a waiter marks it served, **Then** it is shown as served and no longer appears as waiting.
7. **Given** an item that has not yet started preparing, **When** staff cancel it with a reason, **Then** it is removed from the ticket and the tab, the station screen shows it as cancelled so the cook does not make it, and the tab total drops accordingly.
8. **Given** an item that is already preparing or ready, **When** a staff member without the required permission tries to cancel it, **Then** the system refuses; a permitted staff member can cancel it only by giving a reason, and the action is recorded.
9. **Given** a ticket, **When** staff choose to print it, **Then** a clean printable view of the ticket opens in the browser (hardware thermal printing is not part of this feature).
10. **Given** a station screen, **When** it shows tickets, **Then** it shows only that station's tickets and only tickets that still have unfinished items, plus a way to review recently completed ones.

---

### User Story 5 - Move, merge and split tabs (Priority: P3)

Real service is messy. Guests change tables, two parties join, or one friend wants to pay separately. Staff can move an open tab to a different free table, merge one open tab into another, and split a bill: choose some items (or some units of an item) on a tab, pay for just those, and leave the rest open on the table.

**Why this priority**: These handle common but not universal situations and build on a working tab; a restaurant can launch without them and add them in the first weeks.

**Independent Test**: Open a tab with four items, split off two of them and pay for them; confirm a completed sale with just those two items exists, the remaining two are still open on the table, and totals add up to the original.

**Acceptance Scenarios**:

1. **Given** an open tab on table 3 and a free table 8, **When** staff moves the tab to table 8, **Then** table 3 becomes free, table 8 becomes occupied, and the tab, its items and its tickets are unchanged apart from the table shown on them.
2. **Given** a move to an occupied table, **When** staff attempts it, **Then** the system refuses and suggests merging instead.
3. **Given** two open tabs, **When** staff merges one into the other, **Then** all items (with their modifiers, notes and preparation status) end up on the surviving tab, the other tab disappears and its table becomes free, and any ticket already at a station still shows the correct table.
4. **Given** an open tab, **When** staff selects specific items or a number of units of an item and pays for them, **Then** a separate completed sale and receipt are produced for just those items, and the remaining items stay open on the same table.
5. **Given** a split, **When** all items have eventually been paid across several splits, **Then** the table becomes free, and the sum of the separate sales equals what the single bill would have been.
6. **Given** a split where one selected item is still being prepared, **When** staff pays for it, **Then** this is allowed (guests may pay before the food arrives) and the item's preparation continues unaffected.
7. **Given** tabs belonging to different cashier shifts, **When** staff tries to merge them, **Then** the system refuses with an explanation.

---

### User Story 6 - Remove items and void tabs safely, including after cooking has started (Priority: P3)

Guests change their minds, the kitchen runs out, or a dish is sent back. Staff can remove items before they are prepared with no fuss. Once an item is being prepared or is ready, removing it is an explicit, permission-gated action that requires a reason and is recorded. A manager can void an entire open tab, again with a reason.

**Why this priority**: Needed for honest accounting and stock, but only matters once tabs and tickets are in daily use.

**Independent Test**: Add a stock-tracked item to a tab and note its stock level; remove it before sending and confirm stock is restored. Repeat, but remove it after it is marked preparing as a manager with a reason, and confirm the stock is not restored and the action appears in the activity log.

**Acceptance Scenarios**:

1. **Given** a stock-tracked item on a tab that has not been sent to preparation, **When** staff remove it, **Then** its stock is restored and the tab total drops.
2. **Given** an item that is preparing, ready or served, **When** a permitted manager removes or voids it with a reason, **Then** the tab total drops, the station screen shows it as cancelled, the reason and the person are recorded, and the stock is not restored (the goods were already consumed).
3. **Given** a tab with items, **When** a manager voids the whole tab with a reason, **Then** the tab is closed as voided, items not yet in preparation have their stock restored, items already in preparation or later do not, every unfinished ticket item is shown as cancelled at its station, and the table becomes free.
4. **Given** a tab that has been completed (paid), **When** staff wants to reverse it, **Then** the existing whole-sale void rules apply unchanged.
5. **Given** a tab that has a recorded payment but is not fully settled, **When** a manager tries to void it, **Then** the system refuses with an explanation, because voiding would orphan the money already taken.

---

### Edge Cases

- **Cashier shift closes while tabs are open**: a shift cannot be closed while it still holds open tabs; staff must settle, void or hand each tab over to another open shift first. The close screen lists the open tabs.
- **No open shift**: staff cannot open a tab or take payment without an open cashier shift, as with a normal sale; the message says so.
- **Two staff editing the same tab at once**: both sets of additions are kept; neither overwrites the other. If one removes an item the other is just changing, the second gets a clear "this line changed" message rather than silently re-adding it.
- **Two staff trying to pay the same tab at once**: only one payment completes; the other is told the tab is already settled.
- **Stock runs out between rounds**: adding an item whose stock is insufficient is refused at that moment with the available quantity, without disturbing the rest of the tab.
- **Made-to-order dishes**: a menu item can be marked as not stock-tracked so dishes prepared on demand can be sold without inventing stock levels; such items never block on stock.
- **Price changes mid-meal**: items already on a tab keep the price they were added at; only items added afterwards use the new price.
- **Menu item deactivated or deleted mid-meal**: items already on a tab remain and can be paid; the item can't be newly added.
- **Station reassigned after sending**: tickets already created keep their original station; only future sends use the new station.
- **Station screen left open overnight or after a connection drop**: it recovers by itself and shows the current state, never stale "waiting" items from a previous day.
- **Very large tab** (for example a party of 30 with 100+ lines): adding, viewing, splitting and paying remain usable.
- **Data mode**: tables, tabs, modifier groups and tickets created in DEMO mode are never visible in LIVE mode and vice versa.
- **Negative or zero totals**: a line or a tab whose total would become negative because of option price differences is rejected; a zero-total tab can be closed without payment only if it also has no items or all of its items are zero-priced.
- **A table or modifier used by historical sales is deactivated**: past sales and receipts keep showing it correctly.
- **Reports**: unpaid (open) tabs never count toward sales, profit or seller settlements; they count only once paid.

## Requirements *(mandatory)*

### Functional Requirements

**Tables and floor view**

- **FR-001**: System MUST let authorised staff create, rename, edit the seat count and area of, activate and deactivate tables, with table names unique within an area.
- **FR-002**: System MUST prevent deleting any table that has ever had a tab, offering deactivation instead, and MUST NOT offer deactivated tables for new tabs.
- **FR-003**: System MUST present a floor view listing all active tables, grouped by area, showing for each whether it is free or occupied, and for occupied tables the current tab total, how long it has been occupied, and whether any items are ready to serve.
- **FR-004**: System MUST allow at most one open tab per table at any time.

**Open tabs**

- **FR-005**: System MUST let authorised staff open a tab on a free table or as a takeaway tab with no table, only while the staff member's cashier shift is open, and MUST give every tab a unique number visible to staff.
- **FR-006**: System MUST allow items to be added to an open tab at any time across any number of rounds, and MUST allow quantity changes and removal of items that have not been sent to preparation.
- **FR-007**: System MUST compute every item price, option price difference, line total and tab total itself, using menu prices at the moment each item is added; amounts supplied by the screen MUST NOT be trusted.
- **FR-008**: System MUST record payments against an open tab using the same rules as a normal sale: multiple payment methods per payment, proofs for non-cash methods, overpayment allowed only through cash with correct change, and verification handling.
- **FR-009**: System MUST complete a tab only when payments cover the full outstanding amount; on completion it MUST produce a sale with a receipt in the same form as any other sale, and the table MUST become free.
- **FR-010**: System MUST make payment submissions safe to repeat, so a retried submission never records a sale or payment twice.
- **FR-011**: System MUST refuse to close a cashier shift that still holds open tabs, listing them, and MUST let an authorised user hand an open tab over to another open shift.
- **FR-012**: System MUST let an authorised user close an empty tab without payment, with no stock or sales effect.

**Move, merge, split**

- **FR-013**: System MUST let authorised staff move an open tab to a different free table and refuse a move to an occupied table.
- **FR-014**: System MUST let authorised staff merge one open tab into another within the same cashier shift, carrying over items, modifiers, notes and preparation status, and freeing the emptied tab's table.
- **FR-015**: System MUST let authorised staff split a bill by selecting whole lines or a number of units from a line, paying for the selection as a separate completed sale and receipt, and leaving the remainder open on the same tab and table.
- **FR-016**: System MUST ensure that after any combination of splits the separate sales add up exactly to the original tab total, with no rounding drift, and MUST NOT change stock again when items move from a tab into a split sale.

**Stock**

- **FR-017**: For stock-tracked items, System MUST deduct stock when the item is added to a tab, so over-selling is blocked at ordering time, and MUST refuse the addition with the available quantity if stock is insufficient.
- **FR-018**: System MUST restore stock when a stock-tracked item is removed or cancelled before preparation has started, and MUST NOT restore stock for items cancelled once preparing, ready or served.
- **FR-019**: System MUST ensure each unit of an item is deducted from stock at most once and restored at most once, across additions, removals, merges, splits, moves, voids and retries.
- **FR-020**: System MUST let a menu item be flagged as not stock-tracked, in which case no stock is checked, deducted or restored for it.
- **FR-021**: All stock changes MUST be recorded as ordinary entries in the existing stock history, so that history stays complete and auditable and stock can never be changed without a recorded movement.

**Modifiers and notes**

- **FR-022**: System MUST let authorised staff define modifier groups, each with a name, options, a required/optional flag, single or multiple choice, and minimum and maximum selections, and attach them to menu items or categories.
- **FR-023**: System MUST let each option carry a price difference that is positive, zero or negative, and MUST be able to deactivate options and groups without affecting past orders.
- **FR-024**: System MUST enforce group rules when an item is added to a tab and reject choices outside them with a message naming the group.
- **FR-025**: System MUST allow an optional free-text note on each tab line.
- **FR-026**: System MUST copy chosen options (names and price differences) and the note onto the tab line at the time of adding, so later edits to groups, options or prices never change existing tabs, past sales or receipts.
- **FR-027**: System MUST show chosen options and the note on the tab, on station tickets, on receipts, and in sales detail views and exports of sale lines.

**Kitchen and bar tickets**

- **FR-028**: System MUST let authorised staff define preparation stations (for example Kitchen, Bar) and assign a station to a menu category or to an individual menu item, with the item's own assignment taking precedence.
- **FR-029**: System MUST provide an explicit "send" action that turns all unsent items of a tab into one ticket per station, marks them as sent, and locks them from silent edits.
- **FR-030**: System MUST treat items with no station as ready to serve immediately on sending, without creating a ticket.
- **FR-031**: System MUST track each sent item through the statuses new, preparing, ready and served, recording who changed each status and when, and MUST only allow forward transitions in that order plus cancellation.
- **FR-032**: System MUST provide a station screen showing that station's tickets with table or takeaway identifier, items, modifiers, notes, and waiting time, oldest first, updating without manual refresh within a few seconds of a change.
- **FR-033**: System MUST let waiters see which items are ready and mark them served from the tab or floor view.
- **FR-034**: System MUST show cancelled items on the station screen, clearly marked, so the station does not prepare them.
- **FR-035**: System MUST provide a printable view of a ticket in the browser; hardware thermal-printer output is out of scope.
- **FR-036**: System MUST require a reason and a permitted user to remove or cancel an item that is preparing, ready or served, and MUST record who did it, when and why, in the activity log, within the same operation as the change.
- **FR-037**: System MUST require a reason and a permitted user to void an entire open tab, MUST refuse to void a tab that already has recorded payments, and MUST cancel all of its unfinished ticket items.

**Authorisation, data and compatibility**

- **FR-038**: System MUST add new menu permissions so that access to table management, modifier management and station configuration (master-data tier), tab operations (tab-taking tier), and the station screen (station tier) can each be granted per role using the existing configurable-role system.
- **FR-039**: System MUST enforce all permissions on the server, with the screen merely reflecting them; refusals use the existing conventions (invalid input, business-rule conflict, permission denied).
- **FR-040**: System MUST keep tables, tabs, modifier groups, stations, tickets and ticket-item history within the active DEMO or LIVE data mode and never mix them.
- **FR-041**: System MUST NOT change the behaviour of the existing one-step sale, pre-orders, whole-sale void, receipts for non-tab sales, cashier shifts apart from FR-011, or any existing report totals for sales that did not come from a tab.
- **FR-042**: System MUST exclude unpaid tabs from sales, profit and seller-settlement figures, and include a completed tab exactly like any completed sale, including option price differences in revenue.
- **FR-043**: System MUST write every table, tab, modifier, station and ticket change that affects money, stock or accountability to the activity log in the same operation as the change, so a rolled-back change leaves no log entry.
- **FR-044**: All new screens and messages MUST be available in both Indonesian and English, following the existing language setting, and the product's published interface documentation MUST be updated together with any new or changed interface.

### Key Entities *(include if feature involves data)*

- **Table**: A physical seating place. Has a name or number, seat count, optional area, and an active/inactive state. At most one open tab at a time. Never deleted once used.
- **Tab (open order)**: A sale in progress. Belongs to one cashier shift and optionally one table (none for takeaway). Has a unique number, a state (open, completed, voided), lines, running total, and any recorded payments. When completed it is an ordinary sale. Relates to the existing sale, payment, cashier shift and customer concepts.
- **Tab line**: One item on a tab. Holds the item as it was when added (name, price, cost), quantity, chosen modifier options with their price differences, a note, a preparation status (unsent, new, preparing, ready, served, cancelled), the station it was routed to, and who added it.
- **Modifier group**: A named set of options attached to menu items or categories, with rules: required or optional, single or multiple choice, minimum and maximum selections, and an active state.
- **Modifier option**: One choice in a group, with a name, a price difference (positive, zero or negative) and an active state. Copied onto tab lines when chosen.
- **Station**: A preparation point such as Kitchen or Bar. Menu categories or items are assigned to at most one station. Has an active state.
- **Ticket**: A batch of items sent to one station in one "send" action. Has the tab and table it belongs to, a sent time, and its lines. Its completion is derived from its lines' statuses.
- **Item status history**: A record of each status change of a tab line — who, when, from what to what, and the reason when it is a cancellation.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A waiter can open a tab on a free table and add a first round of three items in under 45 seconds.
- **SC-002**: A cashier can take a full payment for an open tab with 10 items in under 60 seconds from opening the tab.
- **SC-003**: Items sent to a station appear on that station's screen within 5 seconds, and status changes made at the station are visible on the floor view within 5 seconds, in at least 95% of cases on the venue's local network.
- **SC-004**: In a test run of 50 tabs including adds, removals, merges, splits, voids and retried submissions, stock reconciles exactly: no stock-tracked item is ever deducted or restored more than once for the same unit, and the final stock equals the expected value in 100% of cases.
- **SC-005**: For every split-bill scenario tested, the sum of the resulting sales equals the original tab total to the cent in 100% of cases.
- **SC-006**: Editing a modifier option's name or price changes none of the existing tabs, completed sales or receipts in 100% of tested cases.
- **SC-007**: A first-time user who has never seen the screen can set up a table and a modifier group and take a tab through to payment without assistance in under 15 minutes.
- **SC-008**: No existing one-step sale, pre-order, report or receipt behaviour changes: every existing regression check continues to pass with no modified expectations, except those that deliberately cover closing a shift that has open tabs.
- **SC-009**: Two staff adding to the same tab at the same moment never lose an item, and two simultaneous payment attempts on the same tab result in exactly one completed sale, in 100% of tested cases.
- **SC-010**: Every cancellation of an item that was already preparing or later is attributable to a named person with a reason in the activity log in 100% of cases.

## Assumptions

- **Standalone copy**: This feature is built in a restaurant-oriented copy of the product that is deliberately separate from any other installation of it. Nothing in this specification depends on or assumes the existence, data or configuration of any other installation.
- **Existing capabilities are reused, not rebuilt**: cashier shifts, payment recording (including split payments, proofs, change rules), receipts, whole-sale void, stock movements, activity log, DEMO/LIVE data modes, configurable roles with menu permissions, and the two-language setting all exist and are reused as they are.
- **Cashier shift and event dependency**: Every sale today belongs to a cashier shift, and every shift belongs to an event. A restaurant has no concept of one-off events, so this feature assumes the venue runs a long-lived, always-available default event under which shifts are opened. Whether that is configured by an administrator or made implicit is a planning decision; this feature does not require changing how shifts themselves work.
- **Stock timing decision (explicit, as requested)**: Stock is deducted when a stock-tracked item is added to a tab, not when the tab is paid. Reason: it matches how sales already deduct stock, it prevents promising items that no longer exist, and it keeps stock correct if a tab is abandoned. Items cancelled before preparation return to stock; items cancelled after preparation has begun do not (the goods are treated as consumed or wasted), and the cancellation reason is recorded. Recording such waste as a distinct loss category is a later enhancement.
- **Not stock-tracked items**: Menu items default to the current behaviour (stock-tracked). Restaurant dishes made to order can be flagged as not tracked. Ingredient-level (recipe-based) stock deduction is out of scope.
- **Shift close default**: Closing a shift with open tabs is blocked rather than carried over silently, because carry-over would blur whose cash drawer each payment belongs to. A handover action to another open shift is provided.
- **Merge and split boundaries**: Merge works only between tabs in the same shift. Split is by items or units of an item, not by an equal share or an arbitrary amount; equal-share splitting is a possible later enhancement.
- **Voiding a tab with money taken**: A tab with recorded payments that is not fully settled cannot be voided until the payments are dealt with; a refund workflow is out of scope. The existing whole-sale void for completed sales is unchanged.
- **Partial payments**: Recording payments that cover only part of a tab is allowed as a running balance, but the tab only completes when fully covered; item-based part-settlement is the split-bill flow.
- **Default permissions** (mapped onto existing roles; adjustable per store because roles are configurable): the owner and admin get everything; the inventory role manages tables, modifier groups and station assignments (master-data tier); the cashier role opens tabs, adds items, sends rounds, moves, merges, splits, takes payment and marks items served, and can view the station screen; removing or cancelling items already preparing, ready or served, and voiding a tab, require the owner or admin tier, matching who may void a sale today.
- **Open question for the product owner (non-blocking, default applied)**: Should dedicated roles such as "Kitchen" and "Waiter" be seeded by default? The default applied is no: because roles are already configurable, a store can create them itself by granting the new menu permissions, and a station-only role can be limited to the station screen. If seeded roles are wanted, it is a small addition at planning time.
- **Station screens run on the venue's local network** on ordinary tablets or monitors in a browser; the system need not work when the venue loses its local network, and no internet connection is required.
- **Modifier prices affect revenue, not cost**: option price differences change the sale price; the item's cost used for profit is unchanged in this feature.
- **Tax and service charge are not applied**: totals are item prices plus option differences, less any existing discounts. Restaurant tax and service charge are a separate future feature, to be designed so they layer on top of tab totals.
- **Receipts**: Receipts for tab sales show chosen options and notes and the table or takeaway identifier; the receipt layout otherwise stays as it is.
- **Out of scope**: tax and service charge, recipe-based ingredient stock deduction, QRIS or payment-provider integration, delivery-platform integrations, reservations, loyalty, multi-outlet, hardware thermal printing, table QR self-ordering, and import/export of tables, stations or modifiers.
