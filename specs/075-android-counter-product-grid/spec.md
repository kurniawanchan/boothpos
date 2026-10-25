# Feature Specification: Android Counter — Product Grid with Variant Picker

**Classification**: INTERNAL

**Feature Branch**: `075-android-counter-product-grid`

**Created**: 2026-10-26

**Status**: Draft

**Input**: User description: "in android Counter screen, make the product list same as POS in web. show one product, when user tap on product with variant, it will show the variant list. show indicator to show that the product has variant [web POS product grid screenshot] [web POS variant list screenshot] - update to be same as this image [totals block screenshot]; show the product images based on the product image saved into the database."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Browse one card per product, with a variant indicator (Priority: P1)

On the Android Counter screen the cashier sees one card per **product** (not one card per variant), like the web POS. A card shows the product photo (or a letter tile when there is no photo), the product name, its category, its price — a single price, or a range such as "Rp 7.000–Rp 8.000" when the variants differ — and the stock. A product with more than one variant shows a small badge, for example "2 varian", so the cashier can tell at a glance that tapping it will ask which variant.

**Why this priority**: This is the core of the request. Today the same product appears several times (one card per variant), which makes the grid long and hides which items belong together.

**Independent Test**: Open the Counter screen on an outlet that has a product with two variants (for example "ABC Klepon" with Hot and Ice) and one with a single variant; confirm the first appears once with a "2 varian" badge and a price range, and the second appears once with no badge and one price.

**Acceptance Scenarios**:

1. **Given** a product with several variants, **When** the grid loads, **Then** the product appears exactly once, with a badge showing the number of variants.
2. **Given** a product whose variants have different prices, **When** its card is shown, **Then** the price shows as a range from the lowest to the highest; **Given** equal prices, **Then** a single price.
3. **Given** a product with exactly one variant, **When** its card is shown, **Then** it has no variant badge.
4. **Given** a product with no photo, **When** its card is shown, **Then** a letter tile (the product's initials) takes the photo's place.
5. **Given** a category selected in the category strip, **When** the grid shows, **Then** only that category's products are listed, one card each.
6. **Given** the cashier types in the search box, **When** results show, **Then** matches are listed so the cashier can still reach a specific variant directly (as on the web).

---

### User Story 2 - Tap a product with variants to choose the variant (Priority: P1)

Tapping a card of a product with several variants opens a variant list for that product: its name, category, a short instruction ("pilih varian untuk ditambahkan ke order"), and one row per variant with its photo, name, code, price and stock. Tapping a row adds that variant to the order and closes the list. Tapping a product with a single variant adds it straight away, with no extra step.

**Why this priority**: Without it a product card with several variants cannot be ordered.

**Independent Test**: Tap "ABC Klepon"; confirm a list with "Hot" and "Ice" rows appears, tap "Ice", and confirm one Ice line is added to the order and the list closes.

**Acceptance Scenarios**:

1. **Given** a product with several variants, **When** the cashier taps its card, **Then** the variant list opens showing every orderable variant with photo, name, code, price and stock.
2. **Given** the variant list, **When** the cashier taps a variant, **Then** that variant is added to the order and the list closes.
3. **Given** the variant list, **When** the cashier taps the close control or outside the list, **Then** it closes and nothing is added.
4. **Given** a product with one variant, **When** the cashier taps its card, **Then** the variant is added immediately.
5. **Given** a variant that is sold out ("habis"), **When** the list shows, **Then** it is visible and marked as sold out and cannot be added.
6. **Given** a product whose variants are all sold out, **When** its card shows, **Then** the card is marked sold out and tapping it adds nothing.
7. **Given** a variant that needs option choices (a menu item with option groups), **When** it is picked, **Then** the existing option step follows exactly as it does today.

---

### User Story 3 - Real product photos on cards and variant rows (Priority: P1)

Every card and every variant row shows the photo that was saved for that product or variant in the store's data (the same photo the web POS shows), not a placeholder. A variant without its own photo shows its product's photo; only when neither has a photo does the initials tile appear. A photo that changes in the back office shows up on the tablet after the next menu refresh.

**Why this priority**: The cashier recognises items by photo; a grid of letter tiles for products that do have photos defeats the point of the card layout.

**Independent Test**: On a menu where one product has a saved photo, one variant has its own photo and one product has none, confirm the card shows the saved photo, the variant row shows its own photo, and the third shows initials.

**Acceptance Scenarios**:

1. **Given** a product with a saved photo, **When** its card shows, **Then** that photo is displayed, matching the web POS.
2. **Given** a variant with its own saved photo, **When** its row shows in the variant list, **Then** the variant's photo is displayed; **Given** none, **Then** the product's photo.
3. **Given** a product and its variants all without photos, **When** shown, **Then** the initials tile appears.
4. **Given** a photo that cannot be loaded (offline, missing file), **When** shown, **Then** the initials tile appears and the grid stays usable, with no error popup.
5. **Given** a photo replaced in the back office, **When** the menu is refreshed, **Then** the new photo shows, not the old one.
6. **Given** a grid of many photographed products, **When** the cashier scrolls, **Then** scrolling stays smooth and photos already seen do not flash back to placeholders.

---

### User Story 4 - Order totals laid out like the reference (Priority: P2)

The order summary on the Counter screen follows the reference image: an order-discount row at the top, then Subtotal, Discount (shown in red), tax with its rate (for example "PPN (10%)"), a dashed divider, and the Total in large bold type.

**Why this priority**: A presentation alignment; the order works without it. The reference image's editable "Discount (Rp)" field is deliberately not added (FR-014).

**Independent Test**: With items in the order, confirm the rows appear in the reference order and styling, the discount amount is red, the tax label shows the store's rate, and the Total is the largest figure.

**Acceptance Scenarios**:

1. **Given** an order with items, **When** the summary shows, **Then** it lists Subtotal, Discount (red), tax with its rate, a dashed divider, then Total in large bold type.
2. **Given** an empty order, **When** the summary shows, **Then** every amount reads "Rp 0".
3. **Given** a discount applied to the order, **When** the summary shows, **Then** the Discount row shows that amount in red and the Total reflects it.
4. **Given** the tax rate set for the store, **When** the summary shows, **Then** the tax label shows that rate rather than a fixed one.

---

### Edge Cases

- A product with no variants at all: shown as unavailable and not orderable (the web shows "no variants").
- A product with six or more variants: the variant list scrolls.
- Very long product or variant names: wrap or truncate without breaking the card or row.
- Products whose variants are all hidden or discontinued for this outlet: the product is not shown.
- Search results (variant level) and the grid (product level) stay consistent: a variant added from either appears as the same order line.
- Phone width: the grid uses fewer columns, and the variant list fits the screen and stays usable with one hand.
- Refreshing the menu while the variant list is open: the list stays on the same product; a variant that became sold out is marked at once.
- Holding a card to mark it sold out ("habis") keeps working: on a single-variant product as today; on a product with variants it is done per variant from the list. [ASSUMPTION]

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Counter product grid MUST show one card per product, never one per variant.
- **FR-002**: A product with more than one variant MUST show a variant-count badge on its card; a product with one variant MUST NOT.
- **FR-003**: A card MUST show the product photo (or an initials tile when none), name, category, price and stock.
- **FR-016**: Photos MUST come from the image saved for the product or variant in the store's data; the Android screen MUST NOT use bundled or invented images. A variant without a photo MUST fall back to its product's photo, then to the initials tile.
- **FR-017**: A photo that fails to load MUST fall back to the initials tile without blocking the card or showing an error.
- **FR-018**: A changed photo MUST appear after the next menu refresh; photos MUST NOT be re-downloaded on every scroll.
- **FR-004**: The card price MUST be a single price when all variants cost the same, and "lowest–highest" when they differ.
- **FR-005**: The card stock MUST be the total across the product's orderable variants.
- **FR-006**: Tapping a product with several variants MUST open a variant list showing, for each orderable variant, its photo, name, code, price and stock, under the product's name and category.
- **FR-007**: Tapping a variant in the list MUST add that variant to the order and close the list; closing the list without choosing MUST add nothing.
- **FR-008**: Tapping a product with exactly one variant MUST add it directly, with no variant list.
- **FR-009**: A sold-out variant MUST be shown as sold out in the list and MUST NOT be addable; a product whose variants are all sold out MUST be shown as sold out.
- **FR-010**: Option groups, notes, discounts and every other existing step after a variant is chosen MUST behave exactly as today.
- **FR-011**: Search MUST keep working and MUST allow reaching a specific variant directly.
- **FR-012**: The grid and the variant list MUST fit tablet and phone widths without clipped text or controls, using the existing responsive column counts.
- **FR-013**: The order summary MUST show, in this order: Subtotal, Discount (red), tax with its rate, a dashed divider, and Total in large bold type; with an empty order every amount is "Rp 0".
- **FR-014**: The summary MUST NOT add an editable rupiah discount field; discounts keep using the existing Android discount flow (percentage, reason code, approver limits) unchanged. The Discount row only displays the amount already applied.
- **FR-015**: Amounts, tax and totals MUST continue to be computed by the server; the layout change MUST NOT change any calculation.

### Key Entities

- **Product**: A menu item with a name, category, optional photo and one or more variants; what the grid card represents.
- **Variant**: An orderable form of a product (for example Hot, Ice) with its own name, code, photo, price and stock; what the order line refers to.
- **Order summary**: Subtotal, order discount, tax (with rate) and Total of the current order.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Each product appears exactly once in the grid: 0 duplicate product cards across all categories on a test menu.
- **SC-002**: A cashier can add a specific variant of a multi-variant product in 2 taps (product, then variant), and a single-variant product in 1 tap.
- **SC-003**: 100% of multi-variant products show the variant badge, and 0% of single-variant products do.
- **SC-004**: The variant list shows photo, name, code, price and stock for 100% of rows.
- **SC-007**: For every product or variant with a saved photo, 100% of cards and variant rows show that photo (not initials) on a test menu; initials appear only where no photo exists or loading failed.
- **SC-005**: Order totals are identical before and after this change for the same order (0 differences in Subtotal, Discount, tax, Total across a test set).
- **SC-006**: The grid and variant list show no clipped or overlapping text at phone and tablet widths in a visual check of 5 representative products (long name, six variants, no photo, sold out, price range).

## Assumptions

- **Source of truth is the web POS**: card layout (badge, photo or initials, name, category, price or range, stock) and the variant list (title, category, instruction, rows) follow the two reference screenshots; wording is Indonesian ("2 varian", "pilih varian untuk ditambahkan ke order", "Stok").
- The web card also prints the outlet name under the category; on the Android screen the outlet is already named in the header, so the line is omitted. [ASSUMPTION — easy to add]
- A product with exactly one variant behaves as today, including the existing option-group step.
- Search stays variant-level, like the web.
- The variant code and stock shown in the list come from the same menu data the tablet already loads, or from additions to it decided during planning; this spec does not change how stock is calculated.
- Scope is the Android Counter screen only; the web POS, table (dine-in) flow and kitchen screens are unchanged.
- Photo files are served by the store's own server and reached with the same server address the tablet already uses; the web POS already shows these photos, so no new upload or storage is needed. How the tablet caches them is decided in planning.
- Decision (2026-10-26): the editable "Discount (Rp)" field from the reference image is skipped; the existing discount mechanism stays as is.
- Server-side pricing, outlet prices, availability and the menu release rules (043, 055) are unchanged.
