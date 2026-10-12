**Classification**: INTERNAL

# Feature Specification: Hide Events and Pre-orders

**Feature Branch**: `046-hide-events-preorders`
**Created**: 2026-10-12
**Status**: Draft
**Input**: User description: "in events page http://localhost:8010/events, update: hide the event menu; remove dependencies to the event from all function/modules/filters; hide the preorders menu; remove dependencies to the preorders from all function/modules/filters"

## Context

The product has grown around two concepts the owner no longer wants to expose: the **Event** (a bazaar/booth occasion that a shift, a sale, a report and a settlement are tied to) and the **Pre-order** (an order taken before goods arrive, with its own payments, stock arrival and hand-over steps). The product is now run as outlets that trade continuously, so neither concept should appear anywhere or constrain anything.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Trade without an event (Priority: P1)

A cashier opens a shift, sells, and closes the shift without ever choosing or even seeing an event. A shift no longer has to belong to an "active event", and nothing in the sale, receipt, payment, tab or table flows mentions an event.

**Why this priority**: today a shift can only be opened on an active event, so as long as events are required the owner cannot stop managing them. This is the dependency that blocks everything else.

**Independent Test**: With no event in the system at all, open a shift, ring up and pay a sale, print the receipt and close the shift. All steps succeed and no event is asked for or shown.

**Acceptance Scenarios**:

1. **Given** no events exist, **When** a cashier opens a shift, **Then** the shift opens with only the outlet and opening cash (no event field).
2. **Given** an open shift, **When** the cashier completes a sale, **Then** the receipt and the order detail show no event name, location or dates.
3. **Given** an open tab on a table, **When** it is paid and closed, **Then** no event is involved at any step.

---

### User Story 2 - Reports, dashboard and lists work by date, not by event (Priority: P1)

Every report, dashboard panel, list and export that used to be filtered, grouped or titled by event is instead driven by a date range (and the outlet selector). Nobody has to pick an event to see numbers.

**Why this priority**: reports are the main consumer of the event today (recap, cost & profit, dashboard "per event", sales filters). Without this, hiding the menu would leave dead filters and empty screens.

**Independent Test**: Open Sales, Reports and the Dashboard on a system with sales but no events; every figure is available by choosing a date range, and no event filter, column or heading is present.

**Acceptance Scenarios**:

1. **Given** sales on several days, **When** the user opens a report, **Then** the period control is a date range and there is no event selector.
2. **Given** the Dashboard, **When** it loads, **Then** the "per event" section is gone and its useful figures are available by period.
3. **Given** an export, **When** it is downloaded, **Then** its columns and file names contain no event.

---

### User Story 3 - The Events menu and screen disappear (Priority: P1)

The Events entry is removed from the navigation and from role/menu permissions; the Events screen and its address are no longer reachable, and nothing links to it.

**Why this priority**: it is the visible request. It follows from stories 1–2 but is the most direct acceptance check.

**Independent Test**: Sign in as every role; no Events entry exists in the sidebar, the permission editor or any shortcut, and opening the old Events address leads to the normal landing screen.

**Acceptance Scenarios**:

1. **Given** any role, **When** the user looks at the navigation, **Then** there is no Events item.
2. **Given** the old Events address, **When** it is opened, **Then** the user lands on the default screen (no error page).
3. **Given** the role editor, **When** menu permissions are listed, **Then** Events is not one of them.

---

### User Story 4 - The Pre-orders menu and module disappear (Priority: P1)

The Pre-orders entry is removed from the navigation and permissions, the Pre-orders screen and its address are unreachable, and every place that created, showed, filtered or counted pre-orders no longer does: customer history, dashboard figures, sales and report tabs, exports/imports, stock arrival and hand-over, invoices and payment documents, notifications and the navigation badge.

**Why this priority**: the second explicit request; pre-orders touch the most places (payments, stock, reports, customers).

**Independent Test**: With no pre-orders module, search the whole product (every screen, filter, report tab, export, import template) for the word pre-order and its figures: none remains, and stock, payments and reports still reconcile.

**Acceptance Scenarios**:

1. **Given** any role, **When** the user looks at the navigation, **Then** there is no Pre-orders item and no badge.
2. **Given** a customer, **When** the history is opened, **Then** it lists sales only (no pre-orders).
3. **Given** the Reports screen, **When** it is opened, **Then** there is no Pre-order tab and no pre-order revenue is blended into sales, profit or outlet figures.
4. **Given** the stock ledger, **When** stock moves, **Then** no movement type or screen refers to pre-order arrival or hand-over.
5. **Given** the master-data/other import and export tools, **When** their templates and menus are listed, **Then** pre-order import/export is not offered.

---

### User Story 5 - Nothing breaks for existing data and installs (Priority: P2)

An install that already has events and pre-orders (including pre-orders still in progress) upgrades without errors; the historical figures that remain visible do not change unexpectedly, and the person upgrading knows what happened to the old records.

**Why this priority**: removing two modules from a live product must not corrupt money or stock. P2 because it is about the upgrade path rather than the new steady state.

**Independent Test**: Upgrade a copy of an install that has events, finished sales and pre-orders in several states; compare sales, stock and payment totals before and after for everything that remains visible.

**Acceptance Scenarios**:

1. **Given** existing sales that belonged to events, **When** the upgrade is applied, **Then** their amounts, dates and receipts are unchanged.
2. **Given** existing pre-orders, **When** the upgrade is applied, **Then** they are kept untouched as hidden history in the data: no screen, filter, report, figure or export shows them, and the money and stock they already moved remain exactly as recorded (nothing is converted, deleted or reversed).
3. **Given** the upgrade has run, **When** the stock and payment ledgers are checked, **Then** every balance still equals the sum of its ledger rows.

---

### Edge Cases

- A role whose saved menu list still contains the removed Events or Pre-orders keys: the keys are ignored/removed without locking the user out.
- Bookmarks, links in e-mails or notifications to the old Events or Pre-orders addresses: they must land on a normal screen, not an error.
- A sale or shift recorded under an event before the upgrade: still shown by date and amount, just without the event.
- Business rules that referenced an event, such as "a shift can only be opened on an active event" or "an event can only be closed with no open shifts": they cease to exist (nothing replaces them unless stated).
- Reports that previously offered one period per event now need a date range; the default range must be sensible (for example the current business day).
- Pre-order-specific stock steps (arrival adds stock, hand-over removes it) and the "pickup day"/shipment/courier concepts that only existed for pre-orders.
- Customer, invoice or receipt text that mentions an event or a pre-order.
- Import/export templates that carried event or pre-order columns or sheets.

## Requirements *(mandatory)*

### Functional Requirements

**Events**

- **FR-001**: The Events menu entry MUST NOT appear in the navigation, in role/menu permission lists, or in any shortcut for any role.
- **FR-002**: The Events screen and its address MUST NOT be reachable; opening the old address MUST lead to the default screen.
- **FR-003**: Opening a shift MUST NOT require, ask for, or be blocked by an event.
- **FR-004**: No sale, tab, receipt, payment record, invoice or notification MUST show an event name, location, date range or "available on" information.
- **FR-005**: Every report, dashboard panel, list, filter, search and export that used an event as a filter, grouping, column, file name or title MUST use a date range (and the outlet selector) instead; no event selector or event column MUST remain anywhere.
- **FR-006**: Rules that exist only because of events (active-event requirement, event close, event cost, event availability days) MUST be removed together with their screens and messages.

**Pre-orders**

- **FR-007**: The Pre-orders menu entry and its navigation badge MUST NOT appear anywhere for any role, and the permission MUST be removed from role/menu lists.
- **FR-008**: The Pre-orders screen and its address MUST NOT be reachable; the old address MUST lead to the default screen.
- **FR-009**: No screen, filter, count, history, report tab, dashboard figure, document (invoice, payment document, shipping slip), notification, import, export or template MUST create, show or include pre-orders.
- **FR-010**: Pre-order revenue, cost and payments MUST NOT be blended into sales, profit, outlet recap or dashboard figures; those figures MUST be computed from sales only.
- **FR-011**: Stock MUST NOT have any pre-order arrival or hand-over movement, screen or reference; remaining stock movement types are unchanged.
- **FR-012**: Customer views MUST list sales only.

**Both**

- **FR-013**: The product MUST work end to end (shift, sale, tab, payment, receipt, stock, transfers, reports, dashboard, exports) in an install that has no events and no pre-orders.
- **FR-014**: The upgrade of an install that already has events and pre-orders MUST complete without errors, MUST leave sales, payment and stock totals that remain visible unchanged, and MUST leave every stock and payment balance equal to the sum of its ledger rows.
- **FR-015**: Old addresses, saved role menu lists and saved filters that mention events or pre-orders MUST be tolerated (ignored), never causing an error or a lock-out.
- **FR-016**: Existing pre-orders and events MUST be handled as follows: kept untouched as hidden history in the data — events and pre-orders are never shown, converted, deleted or reversed; sales keep their amounts, dates and receipts and simply lose the event.
- **FR-017**: Reports that previously summarised "per event" MUST offer a date range whose default is the current business day.

### Key Entities

- **Event** (to be hidden): an occasion with dates, location and an "available on" day that shifts, orders and reports were tied to; no longer part of any workflow.
- **Pre-order** (to be hidden): an advance order with its own status, payments, stock arrival/hand-over, shipment and invoices; no longer part of any workflow.
- **Shift**: keeps its outlet, cashier, opening cash and business date; loses its event.
- **Sale / Order**: keeps everything except its event.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A cashier can open a shift, complete a paid sale and close the shift on an install with zero events, with no step asking for an event.
- **SC-002**: A search through every navigation item, screen, filter, report tab, export file, import template and document finds 0 occurrences of an event or pre-order control or figure (verified by a written checklist covering each screen).
- **SC-003**: For a copy of a populated install, sales, payment and stock totals shown after the upgrade equal those before it for everything that remains visible (difference 0), and 100 % of stock/payment balances equal the sum of their ledger rows.
- **SC-004**: Opening any old Events or Pre-orders address, or signing in with a role that still lists them, never produces an error page (0 errors across all roles).
- **SC-005**: Every report and dashboard figure that was available per event can be produced by choosing a date range in no more than 2 actions.

## Assumptions

- "Hide" in the request is read as "remove from every user-facing surface and from all business rules", and "remove dependencies" as "nothing else in the product may require, show, filter by or compute from events or pre-orders". The underlying records are NOT deleted (see the decision below).
- The outlet selector and date range replace the event as the way to scope reports and dashboards; the shift keeps its business date and outlet (feature 045 / 042).
- Anything that exists only for pre-orders (pickup day, shipment/courier record, down-payment purpose, pre-order invoice and payment documents, dispatch marker, pre-order e-mails, pre-order import/export) goes away with the module.
- Master-data import/export and the other modules keep working; only their event/pre-order parts are removed.
- The new behaviour applies to every role, all install sizes (one outlet or several), and both languages; there is no per-role switch to bring the menus back.
- Decided with the owner (2026-10-12): existing events and pre-orders stay in the data as hidden, untouched history (not converted, not deleted); report and dashboard periods default to the current business day.
- This feature does not add any replacement for the removed concepts (no new "campaign" or "advance order" substitute).
