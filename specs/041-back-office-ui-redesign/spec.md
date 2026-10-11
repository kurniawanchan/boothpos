**Classification**: INTERNAL

# Feature Specification: Back Office UI Redesign (full design scope)

**Feature Branch**: `041-back-office-ui-redesign`

**Created**: 2026-10-11

**Status**: Draft

**Input**: User description: "update the system to follow this UI design: design/screens/Warkop WJM Back Office.html". Scope decisions (product owner, 2026-10-11): **Q1 = C** — build every capability the design shows, including those that do not exist today; **Q2 = B** — apply the new visual system to all screens, including cashier-facing ones.

## Scope Note (read first)

This is an umbrella specification. The design is not a restyle of the current screens; it is a complete multi-outlet restaurant back office (13 modules, 12 roles, 22 catalogued reports, purchase-to-pay with 3-way match, stock opname, transfers, loyalty, end-of-day closing, device and configuration-release management). Under decision Q1 = C all of it is in scope.

- Each user story below is an independently deliverable and testable slice. They are ordered by dependency and priority so planning can split this umbrella into separate deliverables without rewriting the spec.
- **FACT**: the current system is a single-store installation with no outlet concept, no tenant concept, and none of the modules in stories 5–13. **FACT**: the design's own copy describes one organisation ("Warkop WJM") with three outlets in different locations.
- **ASSUMPTION**: decision Q1 = C supersedes, for this feature, the existing product rules "one store per installation / no multi-outlet" and the earlier scope cuts for the capabilities the design shows. The consequences (shared deployment across outlets, licensing, device clients) are recorded under *Assumptions & Risks* and must be settled in planning.

## What the design shows (reference summary)

Source: 13 exported screens plus the bundled interactive page (12 page definitions, all labels in Bahasa Indonesia).

- **Frame**: dark icon rail (grouped, status dots, collapse), top bar with breadcrumb, global search with keyboard hint, outlet selector ("Semua outlet"), notification bell with count, signed-in user name/role.
- **Page anatomy**: title + one-line purpose + primary/secondary actions; four-figure KPI strip with coloured markers; tabs with count chips; filter bar + sortable table with "N data" counter and pagination; row click opens a right-hand detail panel (kicker, title, status chips, actions, key facts, sections, history timeline); create/edit forms; bulk actions on selected rows; confirmation dialogs that require a reason code for risky actions; toasts for outcomes.
- **Modules**: Dashboard; Pesanan (open orders, table board); Produk (menu items, ingredients, semi-finished, packaging, retail, modifiers & combos); Inventori (on-hand, movements, stock count, transfers, wastage); Pembelian (purchase requests, purchase orders, goods receipts, supplier invoices, returns); Penjualan (immutable sales records, returns/refunds, complimentary & staff meal, shifts & cash); Pelanggan (members, tiers & point rules, feedback); Supplier (suppliers, price catalogue, scorecard); Laporan (catalogue, scheduled, export history); Karyawan & User (users, roles & permission matrix, activity & audit); Konfigurasi POS (end-of-day, sync exceptions, open shifts, tables, menu layout, releases, approval limits, discount presets, cash denominations, receipt, app version policy); Pengaturan (outlets, tax & rounding, payment methods, reason codes, POS devices, printers, shift schedules, data imports).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Work inside the new application frame (Priority: P1)

Every user signs in and finds the modules they may use in the new frame: grouped icon rail, top bar with breadcrumb, outlet selector, global search, notifications and their name/role.

**Why this priority**: The frame is on every screen and carries the outlet scope and search that later modules depend on.

**Independent Test**: Sign in as each role and open every module from the rail. Each opens inside the new frame; the breadcrumb and highlighted rail item match; modules the role may not use are absent; the outlet selector changes the data shown on pages that are outlet-aware; search finds orders, tables, customers and items.

**Acceptance Scenarios**:

1. **Given** a signed-in user, **When** any page opens, **Then** it appears in the frame with breadcrumb "Beranda / <page>" and the matching rail item highlighted.
2. **Given** a role without permission for a module, **When** the rail renders, **Then** that module is not shown and its address is refused if typed directly.
3. **Given** the user selects one outlet, **When** an outlet-aware page opens, **Then** all figures, lists and actions are limited to that outlet; "Semua outlet" aggregates only the outlets the user is scoped to.
4. **Given** the user types in global search, **When** results appear, **Then** orders, tables, customers and menu items matching the text are listed and open their detail.
5. **Given** the rail is collapsed, **When** the user hovers or focuses an item, **Then** its name shows, and the collapsed state is remembered.

---

### User Story 2 - Read, filter and act on every list the same way (Priority: P1)

On any management page the user sees the same anatomy and the same interaction rules: KPI strip, tabs with counts, filters, sortable table, detail panel, forms, bulk actions, reason-coded confirmations and toasts.

**Why this priority**: All business modules reuse these rules; without them each module would be built differently.

**Independent Test**: On two unrelated pages (for example Supplier and Penjualan) verify the same behaviour for search, filters, sorting, pagination, selection, detail panel, create/edit, confirmation with reason, and loading/empty/error states.

**Acceptance Scenarios**:

1. **Given** a list with filters applied, **When** the user searches or filters, **Then** the "N data" counter, rows and pagination text all reflect the result while tab counts still describe the whole section.
2. **Given** a row, **When** the user clicks it, **Then** a detail panel opens with status chips, key facts, sections and the actions allowed to that user for that status.
3. **Given** a risky action (void, reject, reopen, deactivate, delete data), **When** the user starts it, **Then** a confirmation requires a reason chosen from a managed list, and the reason is recorded.
4. **Given** several selected rows, **When** the user opens bulk actions, **Then** only actions valid for all selected rows are offered, and the result reports successes and failures per row.
5. **Given** a page is loading, empty or failed, **When** it renders, **Then** it shows a loading placeholder, an explanatory empty state with the primary action, or an error with retry — never a blank content area.

---

### User Story 3 - Define organisation, outlets and business rules (Priority: P1)

An owner or admin sets up outlets (name, code, address, timezone, opening hours, business-date cut-off, service modes, tables count), tax and rounding rules, payment methods, reason codes, POS devices, printers, shift schedules, and imports master data, all from the Pengaturan page.

**Why this priority**: Outlets, tax, payment methods and reason codes are referenced by almost every other module.

**Independent Test**: Create an outlet, set its hours and cut-off, publish a tax rule, enable a payment method, add a reason code, register a device and printer, and import a menu file; verify each appears where later modules use it.

**Acceptance Scenarios**:

1. **Given** the Pengaturan page, **When** it opens, **Then** sections are grouped (Organisasi, Keuangan, Perangkat, Operasional) with the selected one's content beside the list and issue counts on sections needing attention.
2. **Given** an outlet with a business-date cut-off, **When** a sale is made before the cut-off time, **Then** it is recorded against the previous business date.
3. **Given** a tax or rounding change, **When** the owner saves and publishes it, **Then** it takes effect from the stated date and the previous rule remains for earlier transactions.
4. **Given** a bulk import file, **When** the user uploads it, **Then** it is validated first, errors are listed per row and column, and nothing is applied unless the file passes (or the user chooses a mode that permits partial application).
5. **Given** a new POS device, **When** the user registers it, **Then** a one-time activation code valid for 15 minutes is shown once and the device appears as pending until used.

---

### User Story 4 - Dashboard with what needs a decision (Priority: P2)

The owner opens the Dashboard to see today's figures against yesterday, a period switch (today / 7 days / 30 days), export, and a "Perlu tindakan" list of items needing a decision across all outlets.

**Why this priority**: Landing page; requires data from the modules below, so it is finished last among the P2 slices but starts with what exists.

**Independent Test**: With a day of activity across two outlets, verify the four headline figures, change versus yesterday, trend lines, period switch, export and that each action item opens the page that resolves it.

**Acceptance Scenarios**:

1. **Given** sales for today and yesterday, **When** the Dashboard loads, **Then** net sales, order count, average order value and low-stock item count show value, change versus yesterday at the same time of day, and a trend line.
2. **Given** pending QRIS verifications, voids after kitchen fire, offline devices, cash variances or stock below reorder point, **When** the Dashboard loads, **Then** each appears in "Perlu tindakan" with count and value and opens the relevant page.
3. **Given** data from one or more devices is not yet synchronised, **When** the Dashboard loads, **Then** a visible notice states how many devices are behind and the "updated at" time.

---

### User Story 5 - Manage the menu, ingredients and recipes (Priority: P2)

Menu managers maintain menu items (category, variants, base and outlet prices, tax category, kitchen station, preparation time, availability by outlet and channel, daily quota with automatic "86"), modifier groups and combos, ingredients, semi-finished goods, packaging and retail goods, and recipes with live costing; then release changes to outlets.

**Why this priority**: Menu and recipes drive sales, stock deduction and cost reporting.

**Independent Test**: Create an item with two variants, a required modifier group and a recipe; verify cost and margin, set an outlet price override, mark it unavailable ("86") in one outlet, and release the change.

**Acceptance Scenarios**:

1. **Given** an item with a recipe, **When** ingredient prices change, **Then** the item's cost and margin update and the recipe keeps a versioned history with effective date.
2. **Given** a price change for several items, **When** the user applies a percentage or nominal change with rounding, **Then** the changes are held as an unreleased draft until released to chosen outlets.
3. **Given** an item marked 86 in an outlet, **When** a cashier searches the menu in that outlet, **Then** the item is shown as unavailable and cannot be ordered.
4. **Given** a daily quota, **When** the quota is reached, **Then** the item becomes unavailable automatically for the rest of the business day.
5. **Given** an item without a recipe, **When** it sells, **Then** no ingredient stock is deducted and the item is flagged "no recipe".

---

### User Story 6 - Control stock across locations (Priority: P2)

Warehouse and outlet staff see stock per location in base units with moving-average cost, reorder suggestions, expiring batches (first-expired-first-out), movement history, stock counts with blind counting and approval, transfers between locations with in-transit stock, and wastage with reason codes and approval thresholds.

**Why this priority**: Accurate stock is the basis for purchasing and food-cost control.

**Independent Test**: Receive stock, sell items with recipes, record wastage above the approval threshold, run a blind count that differs from the system, approve the adjustment, and transfer stock between two outlets; verify balances, valuation and movement history at each step.

**Acceptance Scenarios**:

1. **Given** items below reorder point, **When** the user opens "Perlu tindakan", **Then** items, location, available, reorder point and suggested order quantity (twice the reorder point minus available) are listed with a button to raise a purchase request from the suggestions.
2. **Given** a stock count, **When** a counter submits blind counts, **Then** system quantities stay hidden, variances are computed afterwards, and the counter cannot approve their own count.
3. **Given** a transfer sent from outlet A to B, **When** it is in transit, **Then** the quantity is held as in-transit and moves to B only when B confirms receipt, with any difference recorded.
4. **Given** wastage above the configured value, **When** it is recorded, **Then** it needs approval by a role allowed to approve it before it affects stock and valuation.
5. **Given** a batch expiring within three days, **When** the user opens the batch list, **Then** it is shown with value and the option to use it first or record wastage.

---

### User Story 7 - Purchase-to-pay and suppliers (Priority: P2)

Purchasing staff manage suppliers (contact, payment terms, lead time, halal certificate and expiry), contract price catalogue, and the chain purchase request → approval → purchase order → goods receipt with quality check → supplier invoice with 3-way match → returns to supplier, plus supplier performance scorecards.

**Why this priority**: Purchasing and payables are the largest back-office workflow in the design and depend on stock and suppliers.

**Independent Test**: Raise a request, approve it as a different user, convert to a purchase order, receive part of it, enter an invoice with a price difference and verify the match exception, approve it with a reason, and return damaged goods.

**Acceptance Scenarios**:

1. **Given** a purchase request, **When** its creator tries to approve it, **Then** the system refuses; only another authorised user can approve or reject (with reason).
2. **Given** a purchase order line, **When** the user edits the unit price, **Then** both the last price and contract price are shown beside it and a price differing from contract requires a reason.
3. **Given** a goods receipt, **When** items are received, **Then** quantities, quality outcome and delivery-note photo are recorded and the purchase order shows received versus ordered.
4. **Given** a supplier invoice, **When** its amount or quantity differs from the purchase order or receipt, **Then** it is marked as an exception with the differences, and approval needs a reason.
5. **Given** a supplier whose halal certificate expires within 60 days, **When** the supplier list opens, **Then** the supplier is flagged and counted in the page summary.
6. **Given** a purchase order past its expected date, **When** the page opens, **Then** it is flagged late and counted in the summary.

---

### User Story 8 - Sales records, returns, cash and shifts (Priority: P2)

Managers review immutable sales records, reprint receipts (marked as copies), create returns and refunds that need approval, record complimentary items and staff meals, and review shifts with cash expected versus counted, variance approval, and X/Z reports.

**Why this priority**: Financial control and audit; core to trust in the numbers.

**Independent Test**: Open a closed sale, view its full calculation, reprint it, create a return with stock decision, approve the refund as a second user, record a staff meal over its daily limit, and approve a shift cash variance with a reason.

**Acceptance Scenarios**:

1. **Given** a closed sale, **When** the user opens it, **Then** it cannot be edited; its calculation (gross, discounts, tax base, tax, rounding, total) and the configuration version used are shown.
2. **Given** a reprint, **When** it is printed, **Then** the receipt carries a "SALINAN" marker and the reprint is counted against the per-receipt limit.
3. **Given** a return, **When** it is created, **Then** the user chooses the stock outcome (not restored, discarded as wastage, or returned to stock) and the refund is held for approval before it is paid out.
4. **Given** complimentary items or staff meals, **When** recorded, **Then** they are valued notionally, excluded from revenue, limited per employee per day, and listed separately.
5. **Given** a shift whose counted cash differs beyond tolerance, **When** the manager reviews it, **Then** the variance can be approved only with a reason from the managed list.

---

### User Story 9 - Monitor open orders and tables (Priority: P2)

Managers see open orders and a live table board per outlet and area, with occupancy, bill state, time seated, and a "table over 45 minutes" prompt; orders are created and changed only at the POS.

**Why this priority**: Operational visibility for the floor; builds on the existing table and open-tab capability.

**Independent Test**: With open tabs on several tables, verify list tabs and counts, table colours (empty, occupied, pre-bill printed, over 45 minutes), and opening an occupied table shows its order detail.

**Acceptance Scenarios**:

1. **Given** open orders, **When** the page opens, **Then** summary shows open orders, occupied tables, tables over 45 minutes and open value, and tabs filter by status (open, pre-bill printed, partially paid, paid, closed, cancelled).
2. **Given** the table board, **When** the user clicks an occupied table, **Then** the order's items, guests, elapsed time and amount are shown.
3. **Given** the page, **When** the user looks for an edit action, **Then** none exists for creating or changing orders (monitor, print, verify QRIS, return, cancel unpaid only).

---

### User Story 10 - Customers and loyalty (Priority: P3)

Marketing and managers manage members across outlets, tiers and point rules (earning rate, spend threshold, benefits, redemption, expiry), manual point adjustments with reasons, privacy consent, deletion on request, and customer feedback with follow-up.

**Why this priority**: Valuable but not required to run the business day.

**Independent Test**: Register a member with consent choices, record purchases so points accrue, change tier rules, adjust points with a reason, handle a feedback item, and process a data-deletion request.

**Acceptance Scenarios**:

1. **Given** a tier rule, **When** a member's spend in the qualifying window crosses the threshold, **Then** the member's tier changes and earning follows the new tier's rate.
2. **Given** a point adjustment, **When** staff enters it, **Then** a reason is required and the ledger shows who, when and why.
3. **Given** a deletion request, **When** an authorised user confirms with a reason, **Then** personal data is removed or anonymised while transaction totals remain.
4. **Given** member contact data, **When** a user without the privilege views the list, **Then** personal fields are masked.

---

### User Story 11 - Reports catalogue, schedules and exports (Priority: P3)

Users open any of 22 catalogued reports (sales, cash & shift, control & audit, inventory & cost, purchasing) in preview, export CSV/XLSX/PDF, schedule recurring delivery to named recipients, and review export history with expiry.

**Why this priority**: Reporting depends on the data of all modules above.

**Independent Test**: Preview a report, export it in each offered format, schedule it weekly for two recipients, and verify it appears under Scheduled and, after delivery, under export history.

**Acceptance Scenarios**:

1. **Given** the catalogue, **When** it opens, **Then** reports are grouped by area with ID, description, frequency and available formats, and show the data-freshness time and the business-date basis.
2. **Given** a scheduled report, **When** it runs, **Then** the file is delivered to the chosen recipients and recorded in export history with status and expiry.
3. **Given** a user without export permission, **When** they open a report, **Then** they can preview but not export.

---

### User Story 12 - Users, roles and audit (Priority: P3)

Owners and admins invite users, set outlet scope, POS PIN and multi-factor requirement, unlock accounts, deactivate with a reason; define custom roles in a permission matrix with separation-of-duties rules; and review an audit trail with before/after values.

**Why this priority**: Required for secure multi-outlet operation, but the current system already has roles and users.

**Independent Test**: Invite a user scoped to one outlet, assign a role, create a custom role from the matrix, confirm the creator of a request cannot approve it, and find all these actions in the audit trail.

**Acceptance Scenarios**:

1. **Given** the permission matrix, **When** an owner toggles a permission for a custom role, **Then** the change applies to its users and is audited; built-in roles that must stay restricted (for example a read-only auditor) cannot be given write permissions.
2. **Given** a user scoped to one outlet, **When** they use the system, **Then** they cannot see or act on other outlets' data.
3. **Given** five wrong approver PINs, **When** the fifth fails, **Then** the account is locked and appears in "Akun terkunci" until unlocked by an authorised user.

---

### User Story 13 - Run the POS day: end-of-day, sync, shifts, releases and limits (Priority: P3)

Managers close the business day per outlet against a checklist of blockers, resolve sync exceptions from devices, force-close forgotten shifts, manage tables and areas, order the menu layout for POS, publish/schedule/roll back configuration releases, set approval limits per role, discount presets, cash denominations, receipt header/footer, and the minimum app version policy.

**Why this priority**: These operate the POS fleet; they depend on the settings, menu and device capabilities above.

**Independent Test**: Try to close a day with an open order and an unverified QRIS (blocked, with reasons), clear the blockers, close the day, reopen it with a reason, publish a menu release, and roll it back.

**Acceptance Scenarios**:

1. **Given** open orders, pending QRIS, unsynced events or open shifts, **When** the manager tries to close the day, **Then** closing is blocked and each blocker is listed with its outlet and count.
2. **Given** a closed day, **When** a manager with permission reopens it, **Then** a reason is required, the reopen is audited and the report is regenerated on the next close.
3. **Given** a sync exception (duplicate number, table conflict, total mismatch, user revoked while offline, closed business day), **When** the manager opens it, **Then** the conflict is explained and can be resolved by an allowed action (merge, accept, flag, reject) with a reason.
4. **Given** a configuration release, **When** it is published, **Then** it lists the changes, shows per-device applied version and which devices are behind, and can be rolled back to a previous version with a reason.
5. **Given** approval limits per role, **When** an offline limit would exceed the online limit, **Then** the system lowers it to the online limit and tells the user.

---

### User Story 14 - Apply the new visual system to cashier-facing screens (Priority: P3)

Cashiers, waiters and kitchen staff see the POS checkout, cashier shift, floor/tab and kitchen screens in the same visual system (colours, type, spacing, components) as the back office.

**Why this priority**: Decision Q2 = B. These screens are speed-critical, so the change is visual consistency without slowing any action.

**Independent Test**: Run the existing end-to-end cashier flows (open shift, add items, send to kitchen, pay, close shift) before and after; the number of steps and the time to complete each must not increase.

**Acceptance Scenarios**:

1. **Given** any cashier-facing screen, **When** it renders, **Then** it uses the same colours, type, spacing, buttons, chips and dialogs as the back office.
2. **Given** a touch device, **When** the user operates the POS or kitchen screens, **Then** tap targets and text remain at least as large as today.
3. **Given** the existing cashier flows, **When** compared before and after, **Then** no flow needs more steps or more time.

---

### Edge Cases

- A user scoped to one outlet selects "Semua outlet": only their outlets are aggregated; no figure silently includes others.
- Two users act on the same record (approve, edit, release) at the same moment: one succeeds, the other is told the record changed.
- A release or price change reaches devices that are offline: they keep the previous version until they sync and are shown as "tertinggal".
- Imports: wrong units, unknown suppliers, duplicate codes and over-large files are reported per row; nothing is half-applied.
- A requester approving their own purchase request, count, wastage, refund or variance is always refused, regardless of role.
- Closed business dates are locked: a late event for a closed day is quarantined for a decision, never silently merged.
- Very long names and very large amounts do not break KPI figures, tabs or table columns; wide tables scroll inside themselves with a fixed header.
- Both interface languages fit the same layouts; the design's Indonesian text is the source copy.
- Figures never disagree with their lists; a figure that cannot be computed shows an explicit "unavailable", never zero.
- Personal data (member contact, user email, phone) is masked unless the viewer has the privilege; exports of personal data require a reason and are logged.
- Existing data (current products, stock, customers, vendors, purchase orders, sales) is carried into the new modules without loss and is assigned to a default outlet.
- The store's theme-colour setting keeps working with the new frame.

## Requirements *(mandatory)*

### Functional Requirements

**Frame, navigation and shared behaviour**

- **FR-001**: The system MUST present all signed-in pages in the new frame (collapsible grouped rail, top bar with breadcrumb, outlet selector, global search, notification bell, user menu), replacing the current layout everywhere, including cashier-facing screens (Q2 = B).
- **FR-002**: The rail MUST show only modules the user's role may open and MUST also refuse direct access to the others.
- **FR-003**: The outlet selector MUST scope every outlet-aware page to the chosen outlet or to all of the user's permitted outlets, and users scoped to specific outlets MUST NOT be able to see others.
- **FR-004**: Global search MUST find orders (by number or table), customers/members (by name or phone), menu items (by name or code) and tables, limited to what the user may see.
- **FR-005**: The notification bell MUST show a count of items needing the user's action (for example approvals waiting, devices offline) and list them with a link to each.
- **FR-006**: Every management page MUST follow the shared anatomy: header with actions, four-figure KPI strip, count-bearing tabs, filter bar, sortable table with data counter and pagination, detail panel, forms, bulk actions, reason-coded confirmations, toasts, and loading/empty/error states.
- **FR-007**: Risky actions (void, reject, reopen, deactivate, delete personal data, force close) MUST require a reason selected from a managed reason-code list, and the reason MUST be stored with the action.
- **FR-008**: Any approval MUST be refused when the approver is the same user who created the item, for purchase requests, stock counts, wastage, refunds and cash variances.
- **FR-009**: Amounts MUST display as `Rp` with dot thousands separators; dates in the Indonesian long form where a full date is shown; all new labels available in Indonesian and English.
- **FR-010**: Every create, update, approve, reject, release, reopen, role change and data-export action MUST be recorded in the audit trail with who, when, outlet, before/after values and reason.

**Organisation and settings (Pengaturan)**

- **FR-011**: The system MUST support multiple outlets, each with name, code (used as receipt-number prefix), address, tax-registration number (optional), timezone, opening and closing times, business-date cut-off, service modes (dine-in, takeaway, counter), and table count.
- **FR-012**: Transactions before an outlet's cut-off time MUST be assigned to the previous business date.
- **FR-013**: The system MUST manage tax rules (name, rate, included or excluded from price, service charge, rounding to nearest Rp 100 or Rp 500, calculation level per order or per line) with an effective date, and transactions MUST keep the rule version they were made under.
- **FR-014**: The system MUST manage payment methods (cash, standalone card terminal with manually entered approval code, static QRIS recorded as pending until verified), with scope per outlet and order type, verification flow and clearing account.
- **FR-015**: The system MUST manage reason codes by category (void, return, discount, complimentary, wastage, cash out, variance, reopen, and others) with label and "needs approval" flag.
- **FR-016**: The system MUST register POS devices with a one-time 8-character activation code valid 15 minutes, show per-device model, applied configuration version, last sync and pending events, and allow resending a release or revoking a device with a reason.
- **FR-017**: The system MUST manage printers (role: receipt, kitchen, bar; model, paper width, connection, assigned outlet/station, status) and shift schedules (slot name, start, end, day pattern, assigned staff).
- **FR-018**: The system MUST import master data (menu and prices, users, ingredients, tables, modifiers, suppliers) from template files with a validation step that lists errors per row and column before anything is applied, and keep an import history with status and the file name.
- **FR-019**: The system MUST manage POS business rules: table and guest count required for dine-in, order lock after pre-bill, reprint limit per receipt, idle lock time, cash variance tolerance, cash-out approval threshold, maximum offline duration and unsynced cash limit, and when counter orders go to the kitchen.

**Menu, ingredients and recipes (Produk)**

- **FR-020**: The system MUST manage menu items with name, short name (kitchen ticket and receipt, max 24 characters), category, description, base price excluding tax, tax category, kitchen station, preparation time, status, outlet availability, sales channel availability, daily quota, and open price (permission-gated).
- **FR-021**: Items MUST support variants with their own price and outlet price overrides, modifier groups (required/optional, min, max, option surcharges) and combos made of choices.
- **FR-022**: Staff MUST be able to mark an item unavailable (86) in a chosen outlet with a reason, and reaching the daily quota MUST do so automatically.
- **FR-023**: The system MUST manage ingredients (code, category, storage type, supplier, base unit, purchase unit and conversion factor, approximate-conversion flag, batch and expiry tracking, shelf life), semi-finished goods (with batch yield), packaging and retail goods (with barcode).
- **FR-024**: The system MUST manage recipes per item or variant (ingredients per portion, yield, effective date, change note) with versions, and show live cost and margin per item.
- **FR-025**: Price changes MUST be draftable in bulk (percentage or nominal, rounding to Rp 500, per outlet) and take effect only when released; the page MUST show the count of unreleased changes.

**Inventory (Inventori)**

- **FR-026**: Stock MUST be held per location in base units with moving-average cost, and the system MUST show on-hand, reserved, available, reorder point and value per item and location.
- **FR-027**: The system MUST compute reorder suggestions (twice reorder point minus available) and create a purchase request from them.
- **FR-028**: The system MUST track batches and expiry and warn of batches expiring within three days.
- **FR-029**: Stock counts MUST support full, cycle and spot counts with optional blind counting, variance computation by tolerance, and approval by someone other than the counter before the adjustment posts.
- **FR-030**: Transfers between locations MUST hold stock in transit until receipt is confirmed, recording any difference.
- **FR-031**: Wastage MUST require a reason code and optional photo, and MUST require approval above a configurable value.
- **FR-032**: Sales of items with recipes MUST deduct ingredient stock automatically, and every stock change MUST appear in the movement history with its document.

**Purchasing and suppliers (Pembelian, Supplier)**

- **FR-033**: The system MUST manage suppliers (name, category, contact, tax number, payment terms, lead time, halal certificate number and expiry, status) and a contract price catalogue with minimum order and validity.
- **FR-034**: The system MUST support purchase requests with approval or rejection (reason), consolidation into purchase orders, and a "suggested orders" view.
- **FR-035**: Purchase orders MUST have the lifecycle draft → awaiting approval → sent → partly received → fully received → closed or cancelled, with approval, rejection, resend, cancel (reason) and PDF download.
- **FR-036**: Goods receipts MUST record received quantities, quality result, delivery-note photo and difference notes, and update stock.
- **FR-037**: Supplier invoices MUST be matched three ways (order, receipt, invoice); mismatches MUST become exceptions that can be approved (with reason) or returned to the supplier for correction.
- **FR-038**: Returns to supplier MUST reference a goods receipt, items and reason, and track credit received.
- **FR-039**: The system MUST compute supplier scorecards (on-time delivery, fill rate, rejection rate, price variance) and flag suppliers below target.

**Sales, cash and shifts (Penjualan, Pesanan)**

- **FR-040**: Closed sales MUST be immutable; the system MUST show their full calculation and the configuration version, support reprint marked as a copy, and refuse edits.
- **FR-041**: Returns and refunds MUST be created from a sale or item with reason and stock decision, and refunds MUST be approved by an authorised user before payout.
- **FR-042**: Complimentary items and staff meals MUST be recorded with notional value, reason or employee, per-employee daily limit, and excluded from revenue.
- **FR-043**: Shifts MUST show float, sales by method, cash in/out, expected versus counted cash by denomination and variance, X and Z reports, and variance approval with reason.
- **FR-044**: The orders page MUST show open orders, occupied tables, tables over 45 minutes and open value, with a live table board per outlet and area, and MUST NOT allow creating or editing orders from the back office.

**Customers and loyalty (Pelanggan)**

- **FR-045**: The system MUST manage members across outlets (name, phone, email, birth date, tier, points, visits, total spend, favourite outlet, notes) with consent choices for receipts and promotions.
- **FR-046**: Tiers MUST define a qualifying spend over a period, point earning rate, benefits, redemption values and point expiry; members' tiers MUST update when thresholds are crossed.
- **FR-047**: Point adjustments MUST require a reason and be kept in a ledger.
- **FR-048**: The system MUST record customer feedback (outlet, rating, comment, source) and let staff mark it followed up.
- **FR-049**: Members' personal data MUST be deletable on request (with reason) and masked from users without the privilege; member export MUST require a reason.

**Reports (Laporan)**

- **FR-050**: The system MUST provide the catalogue of 22 reports named in the design across sales, cash & shift, control & audit, inventory & cost and purchasing, each with preview and export in its offered formats.
- **FR-051**: Reports MUST state their data-freshness time and date basis (business date), and sales figures in all reports and pages MUST come from the same source so they agree.
- **FR-052**: Users MUST be able to schedule a report (frequency, outlets, format, recipients), and see scheduled reports and an export history with status and expiry.

**Users, roles and audit (Karyawan & User)**

- **FR-053**: The system MUST manage users (name, email, role, outlet scope, POS access with PIN, MFA requirement, status, last active), invitations, account unlock, PIN reset and deactivation with reason.
- **FR-054**: The system MUST provide twelve built-in roles (Owner, Admin, Restaurant Manager, Outlet Manager, Supervisor, Kasir, Waiter, Kitchen, Purchasing, Warehouse, Finance, Auditor), allow custom roles, and manage permissions in a matrix grouped by area, with separation-of-duties rules (for example admin cannot transact; auditor is read-only).
- **FR-055**: Five consecutive wrong approver PINs MUST lock the account and block the override for 15 minutes.
- **FR-056**: The audit page MUST list actions with actor, outlet, object, and before/after values, filterable by actor, action and date.

**POS operations (Konfigurasi POS)**

- **FR-057**: End-of-day MUST be run per outlet in the back office against a checklist (no open orders, no pending QRIS, all events synced, all shifts closed, no unresolved variance); any failing item MUST block closing and be listed; reopening MUST require permission and a reason.
- **FR-058**: Sync exceptions (number conflict, table conflict, total mismatch, user revoked while offline, closed business day, unknown item) MUST be listed with explanation and resolved through allowed actions with a reason.
- **FR-059**: Managers MUST be able to force-close shifts left open, with reason, and see open shifts overdue.
- **FR-060**: The system MUST manage tables and areas (name, area, capacity, self-order code, bulk create with numbered names) and the order and visibility of menu categories in the POS.
- **FR-061**: Configuration releases (menu, prices, modifiers, tax, rules, reason codes, printers) MUST be draftable, publishable now or scheduled, show per-device applied version, and be rolled back to an earlier version with a reason.
- **FR-062**: Approval limits MUST be configurable per role (discount, void, refund, reprint, variance, cash, offline); discount presets (code, value, minimum role, reason code), cash denominations, receipt header/footer/copies, and minimum app version with grace period and forced update MUST be configurable.

**Dashboard**

- **FR-063**: The Dashboard MUST show net sales, order count, average order value and low-stock count with change versus yesterday and a trend line, a period switch (today, 7 days, 30 days), export, and a "Perlu tindakan" list with counts and links.

**Visual system and behaviour preserved**

- **FR-064**: The new visual system MUST apply to all screens (Q2 = B) while the store's theme-colour setting continues to control the accent colour.
- **FR-065**: Cashier-facing flows MUST NOT need more steps, taps or time than today.
- **FR-066**: Existing data (products, stock, customers, vendors, purchase orders, pre-orders, sales, users, roles) MUST be preserved and assigned to a default outlet; existing capabilities not depicted in the design (pre-orders, events, sellers, licensing and administration, activity log, backup and restore) MUST remain available inside the new frame.
- **FR-067**: Text and controls MUST keep readable contrast and visible keyboard focus in all new screens.

### Key Entities

- **Outlet**: a physical location with hours, cut-off, timezone, service modes, tables, devices and stock locations.
- **Business Date / Day Close**: the trading day of an outlet and its closing record with checklist results and any reopen history.
- **Tax Rule / Rounding Rule**: versioned financial rules with an effective date.
- **Payment Method / Reason Code / Approval Limit / Discount Preset**: managed lists driving POS behaviour and approvals.
- **POS Device / Printer / Shift Schedule / Activation Code**: the fleet registered to an outlet.
- **Configuration Release**: a versioned bundle of menu, price and rule changes with status and per-device applied version.
- **Menu Item / Variant / Modifier Group / Combo / Recipe (versioned) / Outlet Price**: the sellable catalogue and its cost.
- **Ingredient / Semi-finished / Packaging / Retail Good**: stocked goods with units, conversions, storage and expiry rules.
- **Stock Location / Stock Balance / Batch / Stock Movement**: quantities per location with cost and history.
- **Stock Count / Transfer / Wastage Record**: controlled stock changes with approval.
- **Supplier / Price Catalogue Entry / Scorecard**: vendor master data and performance.
- **Purchase Request / Purchase Order / Goods Receipt / Supplier Invoice / Supplier Return**: the purchase-to-pay chain with its match results.
- **Sales Record / Return / Complimentary or Staff-Meal Record**: immutable financial records and their controlled exceptions.
- **Shift / Cash Count / Variance Approval**: cash control per shift.
- **Sync Exception**: a device event that conflicts with server state and awaits a decision.
- **Member / Tier / Point Ledger Entry / Feedback**: loyalty data and customer comments.
- **Report Definition / Schedule / Export**: the catalogue, recurring deliveries and generated files.
- **User / Role / Permission / Audit Entry**: identity, access and the record of changes.
- **Attention Item**: a derived, read-only item used for the Dashboard list, rail dots and notifications.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of pages in every module, for every role, open inside the new frame; zero pages use the old layout.
- **SC-002**: Every module and tab named in the design exists and follows the shared page anatomy, verified page by page against the 13 reference screens and the interactive page.
- **SC-003**: Every capability that existed before the change remains available, with zero regressions on the existing automated tests and a before/after walkthrough of each module showing no lost action.
- **SC-004**: A user can open any module in one click from the rail, and any order, customer or item from global search in under 10 seconds.
- **SC-005**: On the Dashboard, 5 of 5 test users with the owner or admin role can state today's net sales, order count and what needs attention within 10 seconds.
- **SC-006**: For every page, the figure shown equals the count or sum of the rows beneath it when no filter is applied, and sales totals are identical across Dashboard, Penjualan and Laporan for the same business date.
- **SC-007**: A purchase request can go through request, approval, order, receipt and invoice match in one working session by two users, and a self-approval is refused in 100% of tested approval types (FR-008).
- **SC-008**: A manager can close a business day for an outlet in under 5 minutes when no blockers exist, and every blocker is named when closing is blocked.
- **SC-009**: A stock count with 50 lines is completed, approved and posted in under 15 minutes.
- **SC-010**: Cashier flows (open shift, order, pay, close shift) take no more steps and no more time than before the change (measured with the same test script).
- **SC-011**: All pages meet WCAG 2.1 AA contrast and are fully operable by keyboard.
- **SC-012**: List pages with 1,000 rows filter and sort without visible delay, and each page shows its frame and a loading placeholder within 1 second.
- **SC-013**: 100% of risky actions listed in FR-007 store a reason, and 100% of audit-listed actions in FR-010 appear in the audit trail with actor and before/after values.

## Assumptions & Risks

**ASSUMPTION** — not stated by the design or the product owner; each must be confirmed in planning.

- **ASSUMPTION — deployment**: three outlets in different locations cannot share a single laptop install. A shared deployment reachable by all outlets (head-office server or hosted) is required. This changes the current "one laptop per store, localhost only" model, the one-time-licence-per-store model, and the backup and restore approach. **Risk**: highest-impact decision; blocks planning.
- **ASSUMPTION — one organisation, many outlets**: the design shows one organisation (Warkop WJM) with outlets, not several independent customers in one system. Multi-tenancy across unrelated customers is not assumed.
- **ASSUMPTION — POS clients**: the design's end-of-day, sync exceptions, offline limits, device activation and configuration releases are written for a native Android POS app that is a separate product (design handoff in the same folder). This feature builds the back-office screens and the server-side rules and data those clients need; it does not build the Android app. The existing web POS is registered as a device and follows the same release and limit rules.
- **ASSUMPTION — sales channels**: the design shows per-item toggles for "QR self-order", "GoFood" and "GrabFood". This feature stores availability per channel; connecting to marketplace or self-order services is not shown in the design and is out of scope.
- **ASSUMPTION — payments**: card payments are a standalone terminal with a manually entered approval code (no card data), and QRIS is a static code recorded as pending until verified in the merchant app, as the design states. No payment-gateway integration is built.
- **ASSUMPTION — reports**: the design names 22 reports with source, frequency and formats; their exact columns beyond the visible sample are defined with the report owners during planning.
- **ASSUMPTION — source captures**: the 13 PNG captures are cropped viewports; field-level detail was read from the interactive page definitions. Anything not visible in either (for example the full column list of a table) follows the shared page anatomy.
- **ASSUMPTION — existing modules**: pre-orders, events, sellers, company/licence/invoice administration, activity log, profile and backup remain, restyled, and are placed in the rail's groups; the existing seller concept is not merged with outlets.
- **ASSUMPTION — data migration**: existing single-store data becomes the data of one default outlet; no data is lost. Demo/live data modes continue to apply.
- **ASSUMPTION — delivery**: because of size, delivery is in ordered slices (frame and shared components first, then organisation and settings, then the business modules), each releasable on its own; there is no old/new layout toggle once the frame ships.
- **ASSUMPTION — screens**: laptop and desktop windows from about 1024 px wide are the primary target; tablet widths stay usable; cashier-facing screens keep their touch-friendly sizes.
- **Risk — scope**: the design equals a multi-quarter programme (roughly a dozen modules). Recommending planning to confirm the slices and their order with the product owner before any build starts.
- **Risk — conflicting product rules**: this feature reverses documented rules (single store, no multi-tenancy, earlier scope cuts such as granular roles, QR self-order, marketplace channels). The product owner's Q1 = C decision is recorded here so those rules are updated together with the plan.
- **Risk — privacy**: members and users carry personal data (phone, email, birth date). Masking, consent, deletion on request and export logging (FR-045, FR-049, FR-056) are required, not optional (data-protection law and the company's ISO 27001/27701 commitments).
- **Dependency**: the existing role and permission system, tables and open-tab capability, products, stock, vendors, purchase orders, customers, reports and dashboard data are the starting point for the matching modules.
