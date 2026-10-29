# Feature Specification: Tax Setting (Rate, Label, Show/Hide) across Web, Sales Detail, Receipt and Android

**Feature Branch**: `083-tax-setting-sales-receipt-android`

**Created**: 2026-10-29

**Status**: Draft

**Input**: User description: "add a Tax section to Settings (rate and label, with a show/hide toggle). show it also in Sales detail and receipt when toggle show, right tax not showing but the total is added with tax. update also for the android app to read the tax setting."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Owner configures tax in Settings (Priority: P1)

An owner or admin opens Settings and finds a **Tax** section. They enter the tax rate (a percentage), a label (for example "PPN"), and switch "Show tax on screens and documents" on or off. Saving applies the setting to the whole system immediately, without touching the database or calling an API by hand.

**Why this priority**: Today the tax rate and label can only be changed outside the app. Without a Settings section nothing else in this feature can be used by shop staff.

**Independent Test**: Open Settings as owner, set rate 10 and label "PPN", save, reload the page: the values are still there. Add an item to the POS cart: a "PPN (10%)" line and a total including 10% appear.

**Acceptance Scenarios**:

1. **Given** the owner is on Settings, **When** they open the Tax section, **Then** they see the current rate, label and show/hide toggle (rate 0, label "Pajak", and toggle on for an install that never configured tax).
2. **Given** the owner enters rate 10 and label "PPN" and saves, **When** a cashier adds a Rp 7.000 item, **Then** the cart shows "PPN (10%)" Rp 700 and total Rp 7.700.
3. **Given** the owner enters a rate below 0, above 100, or non-numeric, **When** they save, **Then** the save is refused with a clear message and the previous values stay in force.
4. **Given** a cashier (not owner/admin), **When** they open Settings, **Then** they cannot change the Tax section.
5. **Given** the system is in DEMO mode, **When** the owner changes the tax section, **Then** [see Assumptions: the setting is shared by both modes unless stated otherwise].

---

### User Story 2 - Hide the tax line but still charge it (Priority: P1)

The owner turns the show/hide toggle **off** because they do not want a separate tax line in front of customers. The tax is still charged: totals everywhere still include it, and only the tax line itself disappears.

**Why this priority**: This is the exact behavior asked for and the easiest to get wrong. A hidden tax that silently drops out of the total would under-charge every sale.

**Independent Test**: With rate 10 and the toggle off, add a Rp 7.000 item: no tax line appears in the cart, payment screen, sales detail or receipt, yet the total is Rp 7.700 everywhere.

**Acceptance Scenarios**:

1. **Given** rate 10 and toggle off, **When** a cashier builds a Rp 7.000 cart, **Then** the cart shows no tax line and the total is Rp 7.700.
2. **Given** the same order is paid, **When** the receipt is printed or downloaded, **Then** it shows no tax line and its total is Rp 7.700.
3. **Given** the same order, **When** the owner opens it in Sales, **Then** the detail shows no tax line and the total is Rp 7.700.
4. **Given** the toggle is off and the rate is 0, **When** an order is made, **Then** nothing is added and nothing is shown.

---

### User Story 3 - Tax shown in Sales detail and receipt when the toggle is on (Priority: P1)

With the toggle **on** and a rate above 0, the tax line (label, percentage and amount) appears in the POS cart, the payment screen, the Sales detail and the receipt, so that subtotal, discount, tax and total add up visibly.

**Why this priority**: Sales detail and receipt currently do not show a tax line, so the total cannot be reconciled by the reader.

**Independent Test**: Toggle on, rate 10, label "PPN". Pay a Rp 7.000 order. Open it in Sales and view the receipt: both show Subtotal Rp 7.000, PPN (10%) Rp 700, Total Rp 7.700.

**Acceptance Scenarios**:

1. **Given** toggle on and rate 10, **When** the owner opens a paid order in Sales, **Then** the detail lists subtotal, discount, "PPN (10%)" with its amount, and the total.
2. **Given** the same order, **When** the receipt is shown, **Then** it lists the same tax line between the discount and the total.
3. **Given** toggle on but an old order was made when the rate was 0, **When** it is opened, **Then** no tax line appears for that order (it had no tax).
4. **Given** the owner later changes the rate from 10 to 11, **When** they open an older order, **Then** it still shows 10% and its original tax amount: past orders are never recalculated.

---

### User Story 4 - Android POS follows the tax setting (Priority: P2)

The Android POS reads the tax rate, label and show/hide toggle from the server. Carts, payment, the receipt printed on the thermal printer, and order lists on the tablet behave exactly like the web: the tax is added to the total, and the tax line shows only when the toggle is on.

**Why this priority**: Required for consistency, but the web flow is usable without it. Tablets are the main counter device, so mismatched totals between web and tablet would be serious.

**Independent Test**: Set rate 10, label "PPN", toggle on, on the web. Refresh the tablet's settings. A Rp 7.000 item shows PPN Rp 700, total Rp 7.700 and the printed receipt matches. Turn the toggle off on the web, refresh the tablet: the line is gone, total still Rp 7.700.

**Acceptance Scenarios**:

1. **Given** the tablet is online, **When** the owner changes the Tax section on the web, **Then** the tablet uses the new rate, label and toggle after its next settings refresh, without reinstalling the app.
2. **Given** toggle off, **When** the tablet prints a receipt, **Then** no tax line prints and the printed total equals the total on the web for the same order.
3. **Given** the tablet is offline, **When** it creates an order, **Then** it uses the last known tax settings, and the server remains the final authority on the amount charged.
4. **Given** the server's total differs from what the tablet displayed (rate changed mid-order), **When** the order is confirmed, **Then** the receipt shows the server's figures.

---

### Edge Cases

- Rate is 0: no tax is added and no tax line is shown, regardless of the toggle.
- Rate has decimals (for example 11.5): up to two decimals are accepted; amounts are rounded to whole rupiah in the same way on every surface.
- Label is empty: the default label "Pajak" is used. Label longer than 30 characters is refused.
- Toggle changed while a cashier has an open cart: the cart picks up the change on its next refresh; the server computes the charge at order time.
- Orders created before this feature keep their stored tax amounts; their display follows the toggle.
- Open tabs (dine-in) display and total tax the same way as paid orders.
- Split or reprinted receipts of an existing order show the tax figures stored on that order, not the current rate.
- Excel/CSV exports of sales: [see Assumptions: tax columns are unaffected by the toggle].

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Settings MUST include a Tax section with three fields: tax rate (percent), tax label, and a show/hide toggle.
- **FR-002**: Only owner and admin MUST be able to change the Tax section; other roles MUST NOT.
- **FR-003**: The rate MUST accept 0 to 100 with up to two decimals; anything else MUST be refused with a message and leave the stored value unchanged.
- **FR-004**: The label MUST default to "Pajak" when empty and MUST be limited to 30 characters.
- **FR-005**: The toggle MUST default to **on** so that an install that already uses a tax rate keeps showing it after the upgrade.
- **FR-006**: A rate above 0 MUST always be added to order totals, whatever the toggle says. The toggle MUST control display only.
- **FR-007**: With the toggle on and a rate above 0, the tax line (label, percentage, amount) MUST appear in the POS cart, the payment screen, the Sales detail and the receipt.
- **FR-008**: With the toggle off, the tax line MUST NOT appear in any of those places, while subtotal, discount and total remain unchanged and still include the tax.
- **FR-009**: Totals MUST be identical across web cart, Sales detail, receipt and Android for the same order, in both toggle states.
- **FR-010**: Each order MUST keep the rate and tax amount it was charged with, so changing the setting never alters past orders.
- **FR-011**: The tax amount MUST be computed by the server; amounts supplied by a client MUST NOT be trusted.
- **FR-012**: The Android POS MUST read rate, label and toggle from the server, and apply them to cart, payment, printed receipt and order views.
- **FR-013**: When the Android POS has no fresh settings (offline), it MUST use the last known values; if it has never received any, it MUST behave as rate 0 until it syncs.
- **FR-014**: Saving a change to the Tax section MUST be recorded in the activity log with the old and new values and the user who changed it.
- **FR-015**: The Tax section MUST be available in Indonesian and English like the rest of Settings.

### Key Entities *(include if feature involves data)*

- **Tax setting**: the system-wide rate (percent), label, and show/hide flag. One set per install (see Assumptions about DEMO/LIVE).
- **Order tax record**: the rate and the amount charged on one order, stored with the order at the time it was created.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner can set the tax rate, label and toggle in under 1 minute, with no developer help.
- **SC-002**: For 100% of test orders in both toggle states, the total on web cart, payment, Sales detail, receipt and Android equals the same figure.
- **SC-003**: With the toggle off, 0 tax lines appear on any screen or document, and 0 orders are under-charged compared to the toggle-on total.
- **SC-004**: Changing the rate affects 0 existing orders; all past orders reopen with their original figures.
- **SC-005**: The Android POS shows the new tax setting within one settings refresh after a change on the web (target: under 1 minute while online).
- **SC-006**: A shop with no tax configured sees no change in any screen, receipt or total after the upgrade.

## Assumptions

- The toggle hides the tax line on **all** staff- and customer-facing surfaces (cart, payment, Sales detail, receipt, tablet), not only on the receipt. The request names Sales detail and receipt; extending to the cart avoids a tax line appearing on screen but not on paper.
- Tax stays **exclusive** (added on top of the subtotal after discount), as it works today; inclusive pricing is out of scope.
- One rate per install, not per outlet and not per item. Per-outlet or per-category rates are out of scope.
- The setting is shared by DEMO and LIVE modes unless the existing settings design already splits a value per mode; planning should confirm which applies and follow the same pattern as other settings.
- Exports of sales data keep their tax columns as they are today; the toggle is a display setting and does not change exported figures.
- The default rate remains 0 and the default label "Pajak", so existing installs that never set tax are unaffected.
- Android reads the setting through the same settings refresh it already uses for other store options; no new login or pairing step is needed.
- Outlet-specific receipt identity from feature 079 is unaffected; only the tax block is added to the receipt.
