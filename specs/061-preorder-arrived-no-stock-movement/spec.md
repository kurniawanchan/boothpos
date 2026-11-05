**Classification: INTERNAL**

# Feature Specification: Pre-order "Goods arrived" Makes No Stock Movement

**Feature Branch**: `086-preorder-arrived-no-stock-movement` (spec directory number differs from the branch number)

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "in preorder, when status is goods arrived, deduct the stock not add", then refined by the user: "change to no stock movement for goods arrived, now it is added. the deduct still in hand over". A second request in the same thread: why a pre-order at Goods arrived cannot be moved to Settled or Handed over (screenshot of PO-20261001-0004).

## Background (current behaviour, from the code)

- Creating a pre-order does not touch stock.
- Moving to **Goods arrived** currently **adds** each item's quantity to stock (a purchase-type movement). **This is what changes.**
- Moving to **Handed over** deducts each item's quantity, and is blocked until the balance is fully paid. **This stays.**
- Editing a pre-order that is already Goods arrived or Settled currently adjusts stock by the change in quantity, because arrival had added stock.
- **Settled** is automatic: it is only reached when a payment completes a pre-order that is already Goods arrived. There is no manual Settle button, and the Hand over button only shows for Settled.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Arrival no longer changes stock (Priority: P1)

A shop owner or staff member moves a pre-order to **Goods arrived**. Stock must stay exactly as it was. No stock is added, and none is deducted.

**Why this priority**: This is the core request. Adding stock at arrival does not match how the shop counts its stock.

**Independent Test**: Take a pre-order with two variants (quantity 3 and 2) whose stock is 10 and 8. Move it to Goods arrived. Stock is still 10 and 8, and the stock history has no new rows for this pre-order.

**Acceptance Scenarios**:

1. **Given** a pre-order in Deposit paid with variant A (qty 3, stock 10), **When** it is moved to Goods arrived, **Then** variant A's stock is still 10 and no stock-history row is written.
2. **Given** a pre-order with several variants, **When** it is moved to Goods arrived, **Then** no variant's stock changes.
3. **Given** a variant with zero stock, **When** a pre-order containing it is moved to Goods arrived, **Then** the move succeeds, because arrival no longer needs stock.
4. **Given** any pre-order in Ordered or Deposit paid, **When** it is created, edited or paid, **Then** stock is not touched, as today.

---

### User Story 2 - Hand-over still deducts, exactly once (Priority: P1)

Stock is reduced when the pre-order is handed over to the customer, as today. Because arrival no longer adds stock, the full lifecycle now nets to one deduction.

**Why this priority**: Confirms the one place stock moves. Without it the lifecycle is ambiguous.

**Independent Test**: Stock 10, pre-order qty 3. Move to Goods arrived (stock 10), pay in full, move to Handed over. Stock is 7 and the history shows one hand-over reduction of 3 linked to the pre-order.

**Acceptance Scenarios**:

1. **Given** a fully paid, Settled pre-order with qty 3 of A (stock 10), **When** it is moved to Handed over, **Then** stock is 7 and one hand-over row records the reduction.
2. **Given** a pre-order where a variant's stock is lower than its quantity, **When** it is moved to Handed over, **Then** it is refused with a message naming the item and available stock, nothing changes, and the pre-order stays Settled. This is the existing behaviour.
3. **Given** a pre-order that is not fully paid, **When** it is moved to Handed over, **Then** it is still refused, as today.

---

### User Story 3 - Editing an arrived pre-order does not move stock (Priority: P2)

Since arrival no longer put any stock in, editing quantities of a Goods-arrived or Settled pre-order must not move stock either. Stock only moves at hand-over, using the final quantities.

**Why this priority**: Left as is, an edit after arrival would add or remove stock that was never added.

**Independent Test**: Arrived pre-order with A qty 3 (stock 10). Edit qty to 5. Stock is still 10. Hand it over: stock is 5.

**Acceptance Scenarios**:

1. **Given** an arrived pre-order, **When** a quantity is increased or decreased, **Then** stock does not change and no stock-history row is written.
2. **Given** that edited pre-order, **When** it is handed over, **Then** stock is reduced by the edited quantities.

---

### User Story 4 - A fully paid pre-order that arrives can still be settled and handed over (Priority: P1)

Today a pre-order only becomes Settled when a payment completes it while it is already Goods arrived. If it was paid in full **before** it arrived, moving it to Goods arrived leaves it there with no way forward.

**Found in the data (2026-11-05)**: PO-20261001-0004 is Goods arrived with total 25,000 and paid 25,000, and cannot go to Settled or Handed over. It is the only arrived pre-order in the store today.

**Why this priority**: This is the answer to the user's question about why that pre-order cannot move on. Without a fix, more fully paid pre-orders get stranded the same way.

**Independent Test**: Fully pay a pre-order in Deposit paid, then move it to Goods arrived. It ends Settled, and Hand over is available.

**Acceptance Scenarios**:

1. **Given** a fully paid pre-order in Deposit paid, **When** it is moved to Goods arrived, **Then** it becomes Settled in the same step.
2. **Given** a pre-order that is not fully paid, **When** it is moved to Goods arrived, **Then** it stays Goods arrived until a payment completes it, as today.
3. **Given** pre-orders already stuck at Goods arrived but fully paid (like PO-20261001-0004), **When** the one-time correction runs, **Then** each becomes Settled, an activity-log entry records it, no stock changes, and Hand over becomes available.
4. **Given** the correction has run once, **When** it is run again, **Then** nothing changes (it only affects pre-orders that are still stuck).

---

### Edge Cases

- **Pre-orders that arrived before this change** already added stock. That stock is left alone: nothing is reversed or re-calculated.
- **Edit of a pre-order that arrived before this change**: no stock movement, same as new ones. Stock already added at its arrival is not adjusted for later edits.
- **Cancelling after arrival**: unchanged, and with this feature there is no arrival stock to reverse.
- **Splitting an arrived pre-order**: moves lines without changing stock, as today.
- **Pre-order with a deleted variant**: refused at hand-over with a clear message, as today.
- **Reports**: arrival no longer counts as stock coming in. Reports that total stock-in from purchase-type movements will no longer include pre-order arrivals going forward.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Moving a pre-order to Goods arrived MUST NOT change any variant's stock, and MUST NOT write any stock-history entry.
- **FR-002**: Moving to Goods arrived MUST succeed regardless of current stock levels.
- **FR-003**: Moving a pre-order to Handed over MUST keep deducting each item's quantity, one stock-history entry per item linked to the pre-order, as today.
- **FR-004**: A hand-over that would take any variant below zero MUST be refused with a message naming the item and available stock, and no stock or status MUST change. Unchanged.
- **FR-005**: The rule that Handed over needs the balance fully paid MUST NOT change.
- **FR-006**: Editing quantities of a Goods-arrived or Settled pre-order MUST NOT change stock or write stock-history entries.
- **FR-007**: Creating, editing before arrival, recording payments on, splitting, duplicating or cancelling a pre-order MUST NOT change stock, as today.
- **FR-008**: Across the full lifecycle of a pre-order (create to Handed over), the net stock change per variant MUST be exactly one deduction of the final quantity.
- **FR-009**: Existing stock history and stock levels from past arrivals MUST NOT be altered.
- **FR-010**: Moving a fully paid pre-order to Goods arrived MUST also move it to Settled in the same all-or-nothing step.
- **FR-011**: A one-time correction MUST settle every pre-order that is already Goods arrived and fully paid. It MUST write one activity-log entry per pre-order, MUST NOT change stock, payments or totals, and MUST be safe to run more than once. It applies to both DEMO and LIVE data.
- **FR-012**: The product documentation that states "arrival adds stock" MUST be updated with a dated note instead of rewriting history.

### Key Entities

- **Pre-order**: status path Ordered, Deposit paid, Goods arrived, Settled, Handed over (or Cancelled).
- **Pre-order item**: variant and quantity.
- **Stock history entry**: one signed change per variant with a type, a reference and the acting user. After this feature a pre-order writes entries only at hand-over.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After a pre-order moves to Goods arrived, 100% of its variants keep exactly the stock they had, with zero new history rows.
- **SC-002**: For a full pre-order lifecycle, the total stock change per variant equals exactly minus the final quantity, never an addition and never two deductions.
- **SC-003**: Editing quantities of an arrived pre-order produces zero stock changes in 100% of cases.
- **SC-004**: A fully paid pre-order can reach Handed over after arrival with no manual data fix, in 100% of cases (new arrivals).
- **SC-005**: A hand-over that would take stock below zero changes nothing, in 100% of cases.

## Assumptions

- "Goods arrived" is the third status in the pre-order status bar.
- Stock is refused from going below zero everywhere in the product, so a hand-over needs enough stock entered beforehand. This is existing behaviour and becomes more visible now that arrival no longer adds stock.
- Past arrivals are not corrected retroactively.
- Cancel behaviour is unchanged.
- This replaces the documented rule "arrival adds stock, hand-over deducts". Notes and docs get a dated entry.
- The change applies equally in DEMO and LIVE mode.
- The status screen itself (stepper and buttons) does not need a visual change.
