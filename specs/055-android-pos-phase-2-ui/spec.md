# Feature Specification: Android POS Phase 2 — Two-Column Counter, Full Categories, Live Data, Refresh

**Feature Branch**: `055-android-pos-phase-2-ui`

**Created**: 2026-10-18

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "update android app phase 2: add pull to refresh on every page; tidy up this UI to be two column, so that product is in the first column and the second column is the order list; relayout the category so that it can show all, maybe in a scrollable horizontal layout; make all categories shown (the web admin lists 8 categories, the tablet shows only a few); can show or hide the 'subtotal, pajak 10%, total, button kirim, bayar' area; make the text in the 'Kirim' and 'Bayar' buttons visible, not cropped; remove the price in the 'Bayar' button; reduce the order list font by 2 points; make the data match the backend, demo or live system mode."

## Context

Phase 1 (`054-android-pin-layout-fixes`) fixed the sign-in screen. Phase 2 covers the counter screen ("Counter A-001") of the Android POS tablet (`android-pos/`), which cashiers use through the shift.

Evidence from the tablet screenshot (IMG_2174, counter screen with category "Kopi" selected):

1. The product column is narrower than it should be, and the category rail ("Kategori") lists only five categories (Kopi, Tambahan, Camilan, Non-kopi, Makanan). The web admin's Categories page lists **8** categories (Camilan, Gantungan Kunci, Kopi, Makanan, Non-kopi, Pin Emamel, Standee Akrilik, Tambahan). The three missing categories are not reachable on the tablet.
2. The product names on the tablet (Affogato, Americano, Cappuccino, and others) are the server's **DEMO** products (`WarkopMenuDemoSeeder`), correctly fetched from `GET /products?pos=1`. The five categories also match the DEMO seed. The gap is the category list: the tablet builds categories from the products it received, so a category with no sellable product in the active mode disappears. The web admin lists the category table directly, so it shows all of them. Categories are scoped to the system mode on the server (`HasDataMode`), so the tablet must ask the server for the categories of the active mode.
3. The totals area (Subtotal, Pajak 10%, Total, Kirim, Bayar) always takes space on the right, and the Kirim label is cropped ("Kirim (") while Bayar carries an amount ("Bayar Rp 0") that makes it crowded.
4. The order list text is larger than needed for the right column.
5. Every screen can only be refreshed by leaving and re-entering it; the tablet has no pull to refresh.

The web admin Categories screen is the reference for the category set the tablet must show.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The counter shows the products and the order list side by side (Priority: P1)

A cashier at the counter sees the products in the first (left) column and the current order list in the second (right) column, so items can be tapped and checked without switching screens.

**Why this priority**: This is the main working screen of the shift. Its layout decides how fast a cashier can take an order.

**Independent Test**: Open the counter on the tablet in landscape. Confirm the products fill the left column, the order list fills the right column, and tapping a product adds it to the order list on the right.

**Acceptance Scenarios**:

1. **Given** the cashier opens the counter in landscape, **When** the screen is shown, **Then** the product grid is in the left column and the order list is in the right column, with no overlap.
2. **Given** a product is tapped, **When** the cashier looks at the right column, **Then** the item appears in the order list without leaving the product grid.
3. **Given** the tablet is in portrait, **When** the counter is shown, **Then** both columns remain usable (for example the order list scrolls below or beside the products without hiding the Kirim and Bayar actions).

---

### User Story 2 - All categories are reachable in one horizontal strip (Priority: P1)

The categories appear in a single horizontal strip above the product grid. The strip can be scrolled sideways, so every category the server has is reachable, including ones that do not fit on screen at once.

**Why this priority**: A cashier who cannot reach a category cannot sell its products. The tablet currently hides three of the eight categories.

**Independent Test**: With the server holding 8 categories (as in the web admin Categories page), open the counter and scroll the category strip from start to end. Confirm all 8 category names appear and each one filters the product grid.

**Acceptance Scenarios**:

1. **Given** the server has 8 categories, **When** the counter is shown, **Then** all 8 category chips are in the strip and can be reached by sideways scrolling.
2. **Given** a category chip is tapped, **When** the product grid updates, **Then** only products of that category are shown and the chip is highlighted.
3. **Given** a long category name, **When** it is shown in the strip, **Then** the whole name is visible on one line, or the chip is sized to show it fully, and no chip is cut off.

---

### User Story 3 - The tablet shows the same data as the backend (Priority: P1)

The products, categories, prices and stock shown on the counter are the data the server holds for the active system mode: demo data in DEMO mode, live data in LIVE mode. Sample design data is never shown when the server is reachable.

**Why this priority**: Prices and items shown on the tablet must match what the server records and charges. Showing sample items is a correctness defect for a point-of-sale.

**Independent Test**: With the server in DEMO mode, compare the counter's categories and products with the web admin's Categories and Products for DEMO. Switch the server to LIVE mode, refresh, and confirm the tablet shows the LIVE data.

**Acceptance Scenarios**:

1. **Given** the server is reachable, **When** the counter loads, **Then** the categories and products are the server's, and no sample item (for example Affogato or Kopi Jahe) appears unless the server holds it.
2. **Given** the server's system mode is DEMO, **When** the counter loads, **Then** the tablet shows DEMO data only.
3. **Given** the admin switches the system mode to LIVE, **When** the cashier pulls to refresh, **Then** the counter shows LIVE data.
4. **Given** the server is unreachable, **When** the counter opens, **Then** the screen shows the failure state with Coba lagi, not sample products presented as real.

---

### User Story 4 - Pull to refresh on every screen (Priority: P1)

On every screen, a downward pull reloads that screen's data from the server, and the screen shows that it is loading.

**Why this priority**: The cashier needs to see the current menu, tables and orders without restarting the app, and it is the standard gesture on tablets.

**Independent Test**: On each screen (Meja, Counter, Order, Shift, Perangkat), pull down and confirm a refresh indicator shows while data reloads, and that the screen's data updates from the server.

**Acceptance Scenarios**:

1. **Given** any screen with server data is open, **When** the cashier pulls down, **Then** a refresh indicator shows and the screen's data reloads.
2. **Given** the reload fails because the server is unreachable, **When** the indicator finishes, **Then** the screen keeps the last loaded data and shows the offline message, rather than an empty screen.
3. **Given** the cashier has an unsaved order line selection, **When** a refresh runs, **Then** the order list is not cleared.

---

### User Story 5 - Totals area can be shown or hidden (Priority: P2)

The totals area (Subtotal, Pajak 10%, Total, the Kirim button and the Bayar button) can be collapsed and expanded with one control, so the order list has more room when the cashier does not need the totals.

**Why this priority**: It gives the right column more room on a small tablet. Its absence does not block sales.

**Independent Test**: Tap the show/hide control; confirm the area collapses to a compact bar and expands again, and that Kirim and Bayar still work when expanded.

**Acceptance Scenarios**:

1. **Given** the totals area is shown, **When** the cashier taps hide, **Then** Subtotal, Pajak, Total, Kirim and Bayar are hidden and the order list uses the space.
2. **Given** the totals area is hidden, **When** the cashier taps show, **Then** the area returns with the same values as before.
3. **Given** the area is hidden, **When** the cashier still needs to pay, **Then** a compact control is still visible to show the area again.

---

### User Story 6 - Kirim and Bayar buttons are readable (Priority: P1)

The labels "Kirim" and "Bayar" are fully visible on their buttons at tablet size. The Bayar button shows only the word "Bayar", without the amount.

**Why this priority**: A cropped button label makes the cashier guess which button sends and which pays.

**Independent Test**: Open the counter with a small order and with a large order. Confirm both labels read in full and the Bayar button shows the word only.

**Acceptance Scenarios**:

1. **Given** the totals area is shown, **When** the screen is shown, **Then** the full word "Kirim" is visible on its button without cropping.
2. **Given** the totals area is shown, **When** the screen is shown, **Then** the full word "Bayar" is visible and no amount appears on that button.
3. **Given** the label text is long in another language setting, **When** the button is shown, **Then** the label is shrunk or wraps inside the button, and is not cut.

---

### User Story 7 - Order list font is two points smaller (Priority: P3)

The order list text is reduced by 2 points, so more lines fit in the right column.

**Why this priority**: A small visual change that makes the right column easier to fit; it does not change behaviour.

**Independent Test**: Compare the order list text size before and after; confirm the size is exactly 2 sp smaller and the lines remain readable.

**Acceptance Scenarios**:

1. **Given** an order with several lines, **When** the order list is shown, **Then** its text is 2 sp smaller than the current size and every line stays readable.

---

### Edge Cases

- The server returns more categories than fit in the strip: the strip scrolls sideways; no category is dropped.
- A category or product has no image: a placeholder shows, the name stays readable.
- A product is sold out (86) on the server while the cashier is on the counter: it is shown as unavailable, not removed silently.
- The tab or order was opened in one system mode and the mode changes before the tab is paid: the open tab keeps its items; the next counter load uses the new mode.
- Pull to refresh runs during an in-progress payment: the payment is not interrupted and the payment screen data is not overwritten by a stale refresh.
- The totals area is hidden when the cashier taps Kirim or Bayar from elsewhere: the area is shown again as part of that action.
- Tax label: the label shown must match the tax rate the server holds, not a fixed 10% in the code (see Assumptions).

## Requirements *(mandatory)*

### Functional Requirements

**Layout (counter)**

- **FR-001**: The counter MUST show the product grid in a left column and the order list in a right column in landscape.
- **FR-002**: In portrait, the counter MUST keep the order list and the Kirim and Bayar actions reachable without a second screen.
- **FR-003**: The categories MUST appear as one horizontal strip above the product grid that scrolls sideways.
- **FR-004**: Every category returned by the server MUST be present in the strip. None may be dropped because of screen width.
- **FR-005**: A category name MUST be shown in full. It MUST NOT be cut with an ellipsis.

**Totals area**

- **FR-006**: The totals area (Subtotal, tax line, Total, Kirim, Bayar) MUST have one control that shows or hides it.
- **FR-007**: When hidden, a compact control MUST remain visible to show the area again.
- **FR-008**: Showing or hiding MUST NOT change the values or the order.
- **FR-009**: The Kirim and Bayar labels MUST be fully visible. They MAY shrink or wrap within the button, but MUST NOT be cropped.
- **FR-010**: The Bayar button MUST NOT show an amount.

**Text**

- **FR-011**: The order list text size MUST be 2 sp smaller than the current size, and no other text size on the counter changes because of this feature.

**Data**

- **FR-012**: When the server is reachable, the counter MUST show the categories, products, prices and stock from the server for the active system mode (DEMO or LIVE).
- **FR-013**: The counter MUST NOT derive its category list from products. Categories MUST come from the server for the active system mode, including categories that currently have no sellable product.
- **FR-013a**: Sample design data MUST NOT be shown as real data when the server is reachable.
- **FR-014**: When the server cannot be reached, the screen MUST show the failure state with "Coba lagi" and MUST keep the last loaded data where one exists.
- **FR-015**: The tax line MUST show the tax label and rate that the server holds.

**Refresh**

- **FR-016**: Every screen that shows server data MUST support pull to refresh (Meja, Counter, Order, Shift, Perangkat, and any other screen with server data).
- **FR-017**: While refreshing, the screen MUST show a refresh indicator.
- **FR-018**: A failed refresh MUST keep the last loaded data and show the offline message.
- **FR-019**: A refresh MUST NOT clear the current order selection or the entered payment amount.

### Key Entities *(include if feature involves data)*

- **Category**: a product group the server holds (code, name, product count). The counter shows all categories from the server for the active mode.
- **Product**: a sellable item with name, category, price, stock and availability (86). Shown on the counter from the server.
- **Order (tab)**: the current order list on the counter: lines, subtotal, tax, total. Unchanged by this feature.
- **System mode**: DEMO or LIVE, set by the admin on the server. Decides which data the counter shows.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet in landscape, 100% of counter views show the product grid and the order list side by side, with no overlap, verified by side-by-side screenshot.
- **SC-002**: With 8 categories on the server, 8 of 8 category names are reachable on the counter in 100% of views.
- **SC-003**: With the server in DEMO mode, the counter's categories and product names match the web admin's DEMO data in 100% of checked items; the same holds for LIVE after a pull to refresh.
- **SC-004**: No sample design item (for example Affogato, Kopi Jahe) appears on the counter while the server is reachable, in 100% of checks.
- **SC-005**: On every screen with server data, a pull down shows the refresh indicator and reloads the data, in 100% of checks.
- **SC-006**: The Kirim and Bayar labels are fully visible in 100% of counter views, at the tablet's normal size and in landscape.
- **SC-007**: The totals area is shown or hidden with one tap in 100% of attempts, and the values are the same after showing again.

## Assumptions

- **ASSUMPTION**: "Pajak 10%" is the tax rate the server holds (feature 048 settings: `tax_rate`, `tax_label`). The label is taken from the server, so a change of rate on the server is shown on the tablet without a new build.
- **ASSUMPTION**: "Pull to refresh on every page" means every screen that loads data from the server. Screens with no server data (for example the sign-in screen's keypad) are excluded.
- **ASSUMPTION**: "Two column" applies to the counter (the screen in the screenshot). Other screens keep their layouts, except for pull to refresh.
- **ASSUMPTION**: "Reduce 2 points" means 2 sp (Android scaled points) from the current order list text size.
- **ASSUMPTION**: The totals area is shown by default; the choice is remembered on the device until changed.
- **ASSUMPTION**: The category strip shows all server categories in the server's order; it does not add a "Semua" (all) chip unless the design requires it.
- **ASSUMPTION** (corrected 2026-10-18 after tracing the code): product names come from the server already. The missing categories come from the tablet deriving categories from products; the fix is a server category list for the active mode. The design sample object `PosCatalog` is only used for the cash denominations and its product list is unused.
- **ASSUMPTION**: The server API already returns every category and product for the active mode (`GET /products?pos=1`); no backend change is expected. If the API truncates the list, the fix is server-side and is recorded as a dependency.

## Out of Scope

- Changes to the sign-in screen (phase 1, feature 054).
- Changes to the web admin screens, including the Categories page.
- New order features (discounts, split, refunds) beyond what the counter already offers.
- Changing the system mode itself; this feature only reads the mode the server holds.
- Offline ordering or a local product cache beyond showing the last loaded data.
