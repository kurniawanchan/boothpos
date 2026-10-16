**Classification: INTERNAL**

# Feature Specification: POS Cart Summary Stays Visible

**Feature Branch**: `051-pos-cart-pinned-summary`

**Created**: 2026-10-17

**Status**: Draft

**Input**: User description: "in POS: make the page not too long because of the many product, so that the bottom area of cart is still visible without scrolling" (with a screenshot showing the cart's bottom area — walk-in customer, discount, subtotal, total, the Pay button and the "Open a cashier session first" message — cut off below the visible screen)

## Current behaviour (for reference)

- The POS screen shows the product grid and the cart side by side.
- As the product grid grows, the page gets taller, and the whole screen scrolls. The cart's summary area (discount, subtotal, total, Pay button, and any status message) moves below the visible edge, so the cashier must scroll to find the Pay button.
- The cart's summary sits at the end of the cart panel, so it is the part most likely to be pushed out of view.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pay button always in view (Priority: P1)

A cashier at a busy booth has many products on screen. The total and the Pay button stay visible at the bottom of the cart without scrolling the page, however many products the catalogue has.

**Why this priority**: The Pay button is the most used control on this screen. Hiding it behind a scroll slows every sale and can cause a missed payment.

**Independent Test**: Open the POS screen with a catalogue of at least 50 products on a typical booth screen. Without scrolling the page, the total, the discount field, the Pay button and any status message are all visible.

**Acceptance Scenarios**:

1. **Given** 50 or more products in the catalogue, **When** the cashier opens the POS screen, **Then** the cart summary (discount, subtotal, total, Pay button, status message) is fully visible without scrolling the page.
2. **Given** the cart summary is visible, **When** the cashier scrolls the product grid, **Then** the cart summary stays in place and remains visible.
3. **Given** the cashier has no open cashier session, **When** the screen opens, **Then** the "Open a cashier session first" message is visible together with the Pay button, without scrolling.

---

### User Story 2 - Product list and cart scroll on their own (Priority: P1)

The product grid and the cart item list each have their own scroll area, so the cashier can scroll products or cart lines without moving the summary.

**Why this priority**: Without this, fixing the summary alone would hide products or cart lines.

**Independent Test**: With 50 products and 10 or more cart lines, scroll the product grid and then the cart item list. Both move; the summary and the Pay button do not move and stay visible.

**Acceptance Scenarios**:

1. **Given** more products than fit on screen, **When** the cashier scrolls the product grid, **Then** only the product grid moves.
2. **Given** more cart lines than fit in the cart panel, **When** the cashier scrolls the cart item list, **Then** only the cart item list moves and the summary stays in place.
3. **Given** a single product or a short cart, **When** the screen opens, **Then** the layout looks the same as today, with nothing cut off or stretched.

---

### User Story 3 - Works on the booth devices (Priority: P2)

The layout keeps the summary visible on the screen sizes used at booths: tablets held in landscape and laptops. Nothing moves to a second column or a sideways scroll.

**Why this priority**: Booth staff use the POS on the devices they bring to the event; a layout that works only on a large monitor does not help them.

**Independent Test**: Check the POS screen at a tablet landscape size and a laptop size with 50 products. The Pay button is visible without page scroll and there is no horizontal scroll.

**Acceptance Scenarios**:

1. **Given** a tablet in landscape orientation, **When** the POS screen is open with 50 products, **Then** the Pay button is visible without scrolling the page.
2. **Given** a laptop-sized window, **When** the POS screen is open with 50 products, **Then** the Pay button is visible without scrolling the page.
3. **Given** any of these sizes, **When** the screen is open, **Then** there is no horizontal scroll.

---

### Edge Cases

- A catalogue with only a few products: the layout stays as it is today; the summary is still at the bottom of the cart.
- Many cart lines (for example 30 or more): the cart item list scrolls, the summary and Pay button stay visible.
- A long customer name or a long status message: the summary still fits; the text wraps and does not push the Pay button out of view.
- The customer picker or a dialog is open: the summary is not covered in a way that hides the Pay button when the dialog closes.
- The browser window is resized while the cashier is working: the summary stays visible at the new size.
- A very short window (for example a phone-height screen): the summary remains visible and the product and cart areas each scroll; this is best effort, not a supported booth device.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: On the POS screen, the cart summary (discount, subtotal, total, Pay button, and status message) MUST stay visible without scrolling the page, regardless of how many products the catalogue has.
- **FR-002**: The product grid MUST scroll independently of the cart summary.
- **FR-003**: The cart item list MUST scroll independently of the cart summary when it has more lines than fit in the cart panel.
- **FR-004**: The page itself MUST NOT grow taller with the number of products or cart lines.
- **FR-005**: The layout MUST keep the Pay button visible on tablets in landscape orientation and on laptop-sized windows with at least 50 products.
- **FR-006**: The POS screen MUST NOT scroll horizontally at the supported sizes.
- **FR-007**: The "Open a cashier session first" message MUST appear with the Pay button, in view, when no session is open.
- **FR-008**: Product search, filters, adding or removing cart lines, discounts, customer selection and payment behaviour MUST stay exactly as they are today; only where the screen scrolls changes.

### Key Entities *(include if feature involves data)*

- None. This is a layout change only; no data is stored, read, or changed differently.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With 50 or more products, the Pay button is visible without scrolling the page on every supported size (tablet landscape and laptop-sized window), verified at each size.
- **SC-002**: While scrolling the product grid, the cart summary stays in the same place on screen in 100% of checks.
- **SC-003**: With 30 or more cart lines, the cart item list scrolls and the summary stays visible in 100% of checks.
- **SC-004**: No horizontal scroll appears on the POS screen at the supported sizes.
- **SC-005**: A cashier can complete a sale with 50 products on screen without scrolling the page, in under the same time as before the change or faster.

## Assumptions

- "Not too long" means the POS page fits the height of the screen, and the scrolling happens inside the product grid and the cart item list, not the whole page.
- The supported booth devices are tablets in landscape orientation and laptop-sized windows. Phone-height screens are best effort only.
- The POS layout keeps the product grid and cart side by side as it is today.
- Only the screen layout changes; prices, totals, discounts and stock behaviour are unchanged.
- Product search and filters keep working as they do today.
