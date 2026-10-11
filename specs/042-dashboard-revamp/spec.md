**Classification**: INTERNAL

# Feature Specification: Dashboard Revamp

**Feature Branch**: `042-dashboard-revamp`

**Created**: 2026-10-11

**Status**: Draft

**Input**: User description: "revamp the dashboard to be same as in the Dashboard menu from `design/screens/Warkop WJM Back Office.html`. adjust backend as well."

**Relationship to 041**: `041-back-office-ui-redesign` already moved the Dashboard into the new frame and gave it the shared page header and KPI strip, but left its content as it was (event-scoped charts) and listed a real Dashboard as its User Story 4 / Phase 6. This feature delivers that Dashboard on its own, now, without waiting for the multi-outlet phases.

## Design reference (FACT — read from the design file and its Dashboard capture)

The Dashboard in the design has four parts, top to bottom:

1. **Header**: title "Dashboard"; a line with the business date, an "Diperbarui HH.MM" (updated at) marker and, when relevant, a "1 perangkat belum sinkron" notice; a three-way period switch (Hari ini / 7 hari / 30 hari); an "Ekspor" button.
2. **Four KPI cards** in one panel, each with a label, a unit note, a large value, a change chip versus yesterday up to the same time of day, and a small trend line: *Total penjualan* (net, IDR), *Total order* (all statuses), *Rata-rata nilai order* (net ÷ orders), *Item stok menipis* (below reorder point; its change is shown in items and a rise is bad, so it is red).
3. **"Perlu tindakan"** (needs action): a count chip, a scope line ("Semua outlet · per HH.MM · klik untuk menindaklanjuti") and a grid of items, each with an icon, a title, a value and a meta line: *QRIS menunggu verifikasi* (count · amount, per outlet, "memblokir EOD"), *Void setelah fire* (items · amount, share of gross, highest outlet), *Perangkat offline* (device, duration, unsynced events, cash exposure against limit), *Selisih kas shift* (amount, outlet and shift, tolerance). An item with nothing to do shows a calm "all clear" state instead of disappearing.
4. **"Order terbaru"** (latest orders): subtitle with the order count and scope, a "Real-time" marker, "Ekspor CSV"; status tabs with counts (Semua, Terbuka, Pre-bill dicetak, Dibayar sebagian, Lunas, Ditutup, Dibatalkan); a search box ("Cari no. order, meja, item…"); filters for order type (Dine-in, Takeaway), payment (method, payment status), and business-date range; "Reset filter"; removable filter chips; clicking a row opens that order's detail.

## Current state (FACT — from this repository)

- The Dashboard is scoped to the single active **event** and shows event-based charts (sales by day, by category, by seller, by event), customer statistics, shortcut tiles, low-stock and pending pre-order lists, plus a four-card strip built from several report calls. There is no dedicated dashboard endpoint, no "yesterday at the same time" comparison, no period switch, no attention list and no latest-orders table.
- Data that exists today for the design's items: non-cash payments waiting for verification (QRIS and similar); voided items after a kitchen ticket was sent (kitchen tickets exist since 028); per-shift cash difference against a counted close; low-stock items; open, partly paid and paid tabs and orders.
- Data that does **not** exist today: outlets, a business date separate from the creation time, registered devices and their sync state, end-of-day closing, cash tolerance and approval limits, offline cash exposure.

## Clarifications (resolved with the product owner)

- **Q1 — items with no data behind them: B.** Build the missing foundations in this feature rather than showing only what exists today: outlets with an outlet scope, a business date, a registry of POS devices with sync state, an offline cash exposure limit and a cash-difference tolerance. The Dashboard then matches the design including "Semua outlet", "Perangkat offline" and "1 perangkat belum sinkron".
- **Q2 — existing Dashboard content: B.** The design goes on top; the current event charts, customer statistics, shortcut tiles and pre-order / per-seller panels stay below it as a secondary "Per event" section.

**Gate (FACT from the constitution and the 041 plan):** Constitution v1.0.0 says "single machine, no multi-tenancy". Outlets and a shared server contradict its Stack section as written, and 041 already made an amendment a prerequisite for every outlet phase. Choosing Q1 = B therefore makes the amendment (one organisation, one or more outlets, single machine or one shared server, still no multi-tenancy across customers) and the matching dated notes in the PRD and `CLAUDE.md` a **blocking first task of this feature**. Stories 1–4 may be built on the existing single-outlet data in the meantime; Stories 5–6 may not start until the amendment is ratified by the product owner.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See today against yesterday at a glance (Priority: P1)

An owner or admin opens the Dashboard and reads four headline figures — total sales, order count, average order value, low-stock items — each compared with yesterday at the same time of day, with a trend line, and can switch the period between today, 7 days and 30 days.

**Why this priority**: It is the landing page and the core of the request; every other part depends on these figures being right.

**Independent Test**: Seed a day of orders and a previous day of orders, open the Dashboard and compare each card with the Sales page for the same dates; switch the period and confirm values, comparison basis and trend change together.

**Acceptance Scenarios**:

1. **Given** completed sales today and yesterday, **When** the Dashboard loads for "Hari ini", **Then** each of the first three cards shows today's value so far, a change chip against yesterday up to the same time, and a trend line that continues as a dashed projection or ends at the current time.
2. **Given** items below their reorder point, **When** the Dashboard loads, **Then** the fourth card shows the count, the change in items versus yesterday, and a rise is styled as bad while a fall is styled as good.
3. **Given** the period is switched to "7 hari" or "30 hari", **When** the cards refresh, **Then** values cover that period, the comparison is against the equal preceding period, and the trend line has one point per day.
4. **Given** no sales yet today, **When** the Dashboard loads, **Then** the cards show zero (not blank), the change chip says there is nothing to compare, and nothing divides by zero.
5. **Given** voided and cancelled orders, **When** figures are computed, **Then** voided sales are excluded from net sales and from the average, in line with the Sales page.

---

### User Story 2 - Know what needs a decision (Priority: P1)

The same user sees a "Perlu tindakan" list of items that need a person to act, each with a count and amount, and one click takes them to where it is resolved.

**Why this priority**: This is the part of the design that changes behaviour: the Dashboard stops being a report and becomes a to-do list.

**Independent Test**: Create one of each condition that exists in the data today (a non-cash payment awaiting verification, a void after kitchen send, a shift closed with a cash difference) and check each appears with the right count and amount and opens the page that resolves it; remove the condition and check the item shows its all-clear state.

**Acceptance Scenarios**:

1. **Given** non-cash payments not yet verified, **When** the list loads, **Then** "QRIS menunggu verifikasi" shows the count and total amount and clicking it opens the order list already filtered to those payments.
2. **Given** items voided after the kitchen ticket was sent, **When** the list loads, **Then** "Void setelah fire" shows item count, amount and its share of gross sales, and opens the void/exception view of reports.
3. **Given** a closed shift whose counted cash differs from expected, **When** the list loads, **Then** "Selisih kas shift" shows the amount and which shift, and opens the shift detail.
4. **Given** several items, **When** the list renders, **Then** the header chip shows how many items currently need action and the scope and "as of" time.
5. **Given** a user without permission to the destination of an item, **When** the list renders, **Then** the item is shown without a link rather than leading to a page they cannot open.
6. **Given** every condition is clear, **When** the list renders, **Then** each item shows an "all clear" line and the header chip shows zero.

---

### User Story 3 - Follow the latest orders (Priority: P2)

The user sees the day's latest orders in a table with status tabs and counts, searches by order number, table or item, filters by type, payment and date, opens a row for its detail, and exports what is shown.

**Why this priority**: Turns the attention list into something actionable (the first item links here) and replaces ad-hoc trips to the Sales page.

**Independent Test**: With orders in every status, check each tab's count equals the rows shown, combine a search with a filter, open a row, and compare the CSV with the screen.

**Acceptance Scenarios**:

1. **Given** orders in several statuses, **When** a tab is selected, **Then** only those orders show and every tab count equals the number of rows that tab would show with no other filter.
2. **Given** a search term and filters, **When** applied, **Then** the table narrows, removable chips show what is active, "Reset filter" clears all, and the count line states how many orders are shown.
3. **Given** a row, **When** it is clicked, **Then** that order's detail opens without leaving the Dashboard.
4. **Given** "Ekspor CSV", **When** used, **Then** the file contains exactly the rows currently filtered, with the same columns, and no customer contact details.
5. **Given** an order created while the page is open, **When** the page refreshes on its own, **Then** it appears and counts update without losing the user's filters.

---

### User Story 4 - Export the dashboard (Priority: P3)

The user exports the Dashboard's figures for the chosen period.

**Why this priority**: In the design but least used; the on-screen figures matter first.

**Independent Test**: Export for each period and compare the file with the cards and the attention list.

**Acceptance Scenarios**:

1. **Given** a period is selected, **When** "Ekspor" is used, **Then** the user gets a file containing the four headline figures with their comparison, the trend points, and the attention items as at that moment.

---

### User Story 5 - Outlets and one business day for every figure (Priority: P1, foundation)

An owner with more than one outlet picks "Semua outlet" or one outlet on the Dashboard; every figure, the action list and the order table follow that choice, and every sale belongs to one business day decided by one rule. An installation with a single outlet looks exactly as before, with no selector.

**Why this priority**: The design's scope line, the per-outlet splits in "Perlu tindakan" and the "business date" in the header all depend on it; Stories 1–3 must read from it.

**Independent Test**: Create two outlets with sales on each, including a sale just after midnight and one before the cut-off hour; check each outlet's figures, the combined figures, and that a user limited to one outlet cannot see or request the other.

**Acceptance Scenarios**:

1. **Given** existing data from before this feature, **When** the outlet foundation is applied, **Then** all of it belongs to one default outlet, the Sales page and reports show the same numbers as before, and no selector is shown.
2. **Given** two or more outlets, **When** the owner chooses one outlet, **Then** every region of the Dashboard shows only that outlet; **When** "Semua outlet", **Then** it shows the sum and the action list names the outlet each item comes from.
3. **Given** a user assigned to one outlet, **When** they open the Dashboard or request its data directly, **Then** only that outlet's data is returned, and a request for another outlet is refused.
4. **Given** a sale completed after midnight but before the outlet's cut-off hour, **When** figures are computed, **Then** it counts on the previous business date, on the Dashboard and on the Sales page alike.
5. **Given** a new sale, payment or shift, **When** it is saved, **Then** its outlet and business date are set by the system and cannot be supplied or changed by the client.

---

### User Story 6 - See which devices are behind (Priority: P2)

The user sees which POS devices are offline or holding data that has not reached the server, how long and how much, how much cash was taken offline against the outlet's limit, and a notice in the header when any device is behind.

**Why this priority**: It is the third attention item and the header notice in the design; useful only once devices are registered.

**Independent Test**: Register two devices, let one stop reporting while it holds unsynced events and offline cash; check the notice, the action item, its tooltip, and that both clear when the device reports in.

**Acceptance Scenarios**:

1. **Given** a registered device that has not reported for longer than the offline threshold, **When** the Dashboard loads, **Then** the header shows "N perangkat belum sinkron" with a hover/focus detail (outlet, device, how long offline, number of unsynced events, note that figures may rise after sync), and "Perangkat offline" appears in the action list.
2. **Given** offline cash taken by that device, **When** the item is shown, **Then** its meta states the exposure as a percentage of the outlet's limit and turns critical above the limit.
3. **Given** the device reports in and its events arrive, **When** the Dashboard refreshes, **Then** the notice and the item clear and "Diperbarui" moves forward.
4. **Given** every device is online, **When** the Dashboard loads, **Then** the item shows "Semua online" with the last sync time and no header notice appears.
5. **Given** a user manages devices, **When** they register a device for an outlet, **Then** it appears pending until it first reports, and a retired device stops counting as offline.

---

### Edge Cases

- A new install or a new day with no orders: cards show zero, trend lines are flat or empty, the table shows an empty state, and the attention list shows all-clear states — never errors.
- "Same time yesterday" when yesterday had a different opening time or no trading: the comparison uses yesterday's cumulative figure up to the same clock time; if yesterday had none, the chip says there is nothing to compare.
- Orders created near midnight or in a shift that straddles midnight: the day a sale belongs to follows one rule used by the cards, the table and the Sales page alike (see Assumptions).
- DEMO versus LIVE mode: every figure, the attention list and the table respect the active data mode and never mix the two.
- A user whose role cannot see money figures or a destination page: the Dashboard hides or de-links exactly what the role's menu permissions already hide elsewhere; the server, not the screen, enforces it.
- Very large day (thousands of orders): the table is paged or capped with the full count stated, and the page stays responsive.
- A failed load of one part (e.g. the table) must not blank the others; it shows an error with Retry for that part only (a failed load is not an empty state).
- An outlet with no devices registered: no device notice and the device item shows its all-clear state; an installation with no devices at all must look healthy, not broken.
- A device that is offline but holds nothing unsynced and no offline cash: counted as offline, with zero events and zero exposure shown.
- Changing an outlet's cut-off hour or timezone: past sales keep the business date they were stamped with.
- Language switch (Indonesian/English): all new labels are translated; amounts and dates follow the existing formatting.

## Requirements *(mandatory)*

### Functional Requirements

**Header and period**

- **FR-001**: The Dashboard MUST show the title, the business date, an "updated at" time, and a period switch with Hari ini, 7 hari and 30 hari (default Hari ini); changing it MUST update every figure on the page that depends on the period.
- **FR-002**: The "updated at" time MUST be the time the displayed figures were computed, not the time the page was opened.
- **FR-003**: The user's chosen period MUST survive a reload (kept in the address).

**Headline figures**

- **FR-004**: The Dashboard MUST show four figures: total sales (net), total orders, average order value (net sales ÷ orders), and low-stock item count.
- **FR-005**: Each figure MUST show a change against the comparison basis — yesterday up to the same time of day for "Hari ini", the equal preceding period for 7 and 30 days — as a percentage for money and counts and as a number of items for low stock, with direction and good/bad colouring (a rise in low-stock is bad).
- **FR-006**: Each figure MUST show a trend line (by hour for "Hari ini", by day otherwise); the part of the line not yet reached MUST be visually distinct from the actual part.
- **FR-007**: Total sales MUST equal the Sales page's net sales for the same dates and mode; total orders MUST count every non-draft order regardless of status, labelled "all statuses"; voided orders MUST NOT count in sales or average.
- **FR-008**: A zero denominator or an absent comparison MUST render a neutral "no comparison" chip and never "NaN", infinity or a blank.

**Needs action**

- **FR-009**: The Dashboard MUST show a "Perlu tindakan" panel with a count of items currently needing action, the scope, and the "as of" time.
- **FR-010**: The panel MUST contain these items, each with title, value and meta line: payments awaiting verification (count · amount, split by origin where known); voids after kitchen send (items · amount · share of gross); shift cash difference (amount · shift); and stock below reorder point (count) *(the last is added because the data exists and the umbrella 041 spec lists it; it does not repeat the fourth KPI)*.
- **FR-011**: The panel MUST also contain *Perangkat offline* (devices count, outlet, device, minutes offline, unsynced events, offline cash exposure against the outlet's limit; critical when over the limit), and *Selisih kas shift* MUST count only shifts whose difference exceeds the outlet's tolerance and state that tolerance in its meta line. A closed shift within tolerance is not an action item.
- **FR-012**: Each item MUST open the page that resolves it, already filtered to exactly the records counted; if the user lacks access to that page the item MUST be shown without a link.
- **FR-013**: Every item MUST have an all-clear state; the attention list is computed on read, nothing about it is stored, and it MUST use the same definitions as any other place that counts the same condition (no second formula).

**Latest orders**

- **FR-014**: The Dashboard MUST show a latest-orders table with tabs and live counts for Semua, Terbuka, Pre-bill dicetak, Dibayar sebagian, Lunas, Ditutup and Dibatalkan, mapped onto the statuses orders already have; any design status that has no equivalent today is handled as described in Assumptions.
- **FR-015**: The table MUST support search by order number, table and item name, filters for order type (Dine-in, Takeaway), payment method, payment status and business-date range, removable chips, "Reset filter", and a count line; filters and tab MUST be kept in the address.
- **FR-016**: Each tab count MUST equal the number of rows shown when that tab is selected with no other filter (the figure shown equals the rows beneath it).
- **FR-017**: A row MUST open that order's detail in place; the table MUST refresh itself periodically while visible, keeping the user's filters, and show the "Real-time" marker only while it is actually refreshing.
- **FR-018**: "Ekspor CSV" MUST export exactly the filtered rows with the same columns, excluding customer contact details.

**Export and existing content**

- **FR-019**: "Ekspor" MUST produce a file for the selected period with the four figures, comparison, trend points and attention items as of export time.
- **FR-020**: The current event charts, customer statistics, shortcut tiles, pre-order and per-seller panels MUST remain on the Dashboard below the new content, under a "Per event" heading, behaving as they do today (scoped to the active event, same permissions, same data), with their own loading and error states. They follow the selected outlet only when the underlying report supports it; otherwise the section states that it covers all outlets.

**Outlets, business date and devices (Q1 = B; blocked on the constitution amendment)**

- **FR-026**: The system MUST support one or more outlets per installation. Existing data MUST be migrated onto one default outlet without changing any figure. When only one outlet exists no outlet control is shown.
- **FR-027**: Users MUST be assignable to outlets; the Dashboard, its table and its exports MUST return only the outlets the user is assigned to, enforced on the server; an owner may choose "Semua outlet" or one outlet.
- **FR-028**: Every order, payment and cashier shift MUST carry an outlet and a business date set by the system when it is created — from the outlet's timezone and cut-off hour — and never accepted from the client. Existing rows MUST be backfilled with the same rule. The Dashboard and the Sales page MUST both attribute sales by business date.
- **FR-029**: The system MUST keep a registry of POS devices (name, outlet, status, last contact, unsynced event count, offline cash taken) that devices update by reporting in; a device is *offline* when it has not reported within a configurable threshold, and the Dashboard header notice and the "Perangkat offline" item MUST be derived from it.
- **FR-030**: Each outlet MUST have an offline cash exposure limit and a cash-difference tolerance as settings, editable by owner/admin, with the design's values (Rp 3.000.000 and Rp 5.000) as defaults.
- **FR-031**: Setting up outlets, devices and these limits MUST be possible from the existing Settings area by owner/admin, with every change written to the activity log.

**Cross-cutting**

- **FR-021**: All figures, the attention list, the table and the exports MUST apply the active DEMO/LIVE mode, the user's outlet assignment and each user's existing menu permissions on the server; no cost or margin figure is introduced.
- **FR-022**: The Dashboard MUST use the shared page kit and tokens from 041 (page header, KPI strip, tab bar, filter bar, loading/error/empty states) and add shared pieces (trend line, change chip, attention item) to the kit rather than local copies.
- **FR-023**: Each region MUST have its own loading, error-with-retry and empty state; one failing region MUST NOT blank the others.
- **FR-024**: The Dashboard MUST load within the budgets in the success criteria on a store laptop and MUST NOT make one request per card or per row.
- **FR-025**: All new text MUST exist in Indonesian and English; interactive elements MUST be keyboard reachable with visible focus; change direction MUST NOT be conveyed by colour alone.

### Key Entities

- **Dashboard Summary**: for a chosen period — the four headline figures, each with current value, comparison value, change, and a series of trend points; plus the "computed at" time and the period/comparison dates used. Derived on read.
- **Attention Item**: a derived, read-only item — kind, count, amount, meta text, severity (needs action / all clear) and the destination with the filter that lists exactly what was counted. Not stored.
- **Order Row (dashboard)**: the summarised order shown in the table — number, table or type, items summary, status, payment method and status, total, time, and the order it opens.
- **Outlet**: a place of business under one organisation, with a name, timezone, business-day cut-off hour, offline cash limit and cash-difference tolerance; users are assigned to outlets.
- **Business Day**: the date a sale, payment or shift is attributed to, set by the system from its outlet's timezone and cut-off hour.
- **POS Device**: a registered terminal belonging to an outlet, with status, last contact, unsynced event count and offline cash taken.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any selected period, total sales and average order value on the Dashboard equal the Sales page for the same business dates and mode, and the order count equals the number of orders of every status that day (the "Semua" tab count), in 100% of tested cases (including voided orders and sales near midnight).
- **SC-002**: Five of five owner/admin test users can state today's net sales, order count and how many items need action within 10 seconds of the page showing.
- **SC-003**: Each "Perlu tindakan" item lands the user on a list whose row count equals the number shown on the item, in 100% of tested cases.
- **SC-004**: With 1,000 orders in the day the Dashboard is usable (all four regions drawn) within 3 seconds on the baseline store laptop, and switching period completes within 2 seconds.
- **SC-005**: Every tab count equals its row count with no other filter, and the exported CSV equals the screen rows exactly, in 100% of tested cases.
- **SC-006**: No role sees a figure, item or link it could not already reach elsewhere in the product; a request for the dashboard data by a user without the Dashboard permission is refused.
- **SC-007**: The production main script bundle does not grow by more than 10 kB over the 041 baseline (374 kB); chart code stays in a separate on-demand chunk.
- **SC-008**: No console errors on the Dashboard in any role, in either language, with data and without data.
- **SC-009**: A user assigned to one outlet can never obtain another outlet's figures, rows or exports: 0 leaks across all Dashboard requests in the test matrix of outlets × roles.
- **SC-010**: After applying the business-date and outlet foundations to existing data, every figure on the Sales page, Reports and Dashboard is unchanged except for the sales listed in the pre-migration dry-run report as moving to their correct local business day, and a single-outlet install shows no new controls.
- **SC-011**: A device going offline appears on the Dashboard within 2 minutes of crossing the offline threshold, and disappears within 1 minute of reporting in.

## Assumptions

- **Outlets arrive in this feature** (Q1 = B), as the minimum slice of 041 phases 2a, 2b and 8 that the Dashboard needs: outlets and scope, business date, device registry, limits. The per-outlet permission matrix, approval limits, shared-server hardening, printers, releases and the rest of those phases stay in their own features. The existing outlet selector hides itself at one outlet.
- **Day, not event.** The design is by business date; the current Dashboard is by event. Dashboard figures are attributed to a day, and an event is just another reason for sales to exist that day. *(ASSUMPTION — a booth install loses its "this event" view on the Dashboard unless Q2 keeps it.)*
- **Business day rule.** Backfilled rows get their business date from their completion time in the app timezone and a default cut-off of midnight; new rows use the outlet's own timezone and cut-off. *(ASSUMPTION — the default cut-off hour and the offline threshold, default 5 minutes, are to be confirmed in planning.)*
- **Devices need something to report.** The device registry and its Dashboard display are in scope; making the POS client queue work offline and report unsynced events is only in scope as far as the POS already does it. If the current POS client has no offline queue, a device reports zero unsynced events and zero offline cash until one exists. *(ASSUMPTION — to be verified against the POS client in planning; this is the largest risk in Q1 = B.)*
- **End-of-day closing stays out of scope.** The design's "memblokir EOD" note on pending verifications is omitted until an end-of-day close exists. *(ASSUMPTION.)*
- **Order statuses.** Design statuses map onto existing ones: Terbuka = open tab; Dibayar sebagian = open or completed with a partial payment; Lunas = fully paid; Dibatalkan = voided; Ditutup = completed and closed. "Pre-bill dicetak" has no equivalent unless pre-bill printing is recorded; if it is not, the tab is hidden rather than always zero. *(ASSUMPTION — to confirm in planning.)*
- **Cashiers.** Role visibility follows the existing Dashboard menu permission; sales totals are already visible to anyone with that menu. No cost, margin or per-seller payable is shown.
- **"Real-time"** means automatic refresh on a short interval while the tab is visible, not a push connection. *(ASSUMPTION.)*
- **Export delivery.** The design says the dashboard export is queued and a link arrives in notifications; this product has no notification centre or job queue. The export is generated and downloaded straight away. *(ASSUMPTION — queued delivery arrives with the notification phase.)*
- **Not in scope**: end-of-day closing, approval limits, shared-server hardening, POS releases and printers, the notification bell content, global search, and the Reports catalogue (REP-031/035/053 are named in the design as destinations; where a destination does not exist, the item links to the closest existing page or is shown without a link).
- **Dependencies**: 041 frame, tokens and page kit; existing sales, payments, kitchen ticket, cashier-shift, stock and permission capabilities; **ratification of the constitution amendment** before Stories 5–6 start.

## Planning refinements (2026-10-11, from research.md)

These make the spec precise against what the repository actually contains; none changes a decision you made.

- **Stages**: Stories 1–4 and FR-020 are built first on the single-machine install (Stage A); Stories 5–6 follow the constitution amendment (Stage B). The amendment text was rewritten for this requirement: `constitution-amendment.md`.
- **Order tabs** (FR-014): this product has statuses open / completed / voided and no pre-bill or day-close record, so the tabs are **Semua, Terbuka, Dibayar sebagian, Lunas, Dibatalkan**; "Pre-bill dicetak" and "Ditutup" stay hidden until their data exists. Payment-status filter: Belum dibayar, Menunggu verifikasi, Sebagian, Berhasil.
- **Order types** (FR-015): Retail, Dine-in, Takeaway (the stored values).
- **Attention items** describe the current business date, not the selected period; payments awaiting verification are POS/tab payments (pre-order payments stay on their own screen); the "Void setelah fire" amount is known only for lines cancelled after this feature starts recording a price on the kitchen ticket.
- **Low stock** is organisation-wide until stock is split by outlet (a later phase); the card says so when an outlet is selected.
- **Total order** counts every status (design label "Semua status"); the average uses completed orders only.
- **Inherited behaviour** (open decision O3): the Sales page's net sales is the sum of item totals and does not deduct order-level discounts; the Dashboard matches it.
- **Web POS devices**: the web POS has no offline queue, so its device shows online/offline only; unsynced events and offline cash stay zero until an offline-capable client reports them.
