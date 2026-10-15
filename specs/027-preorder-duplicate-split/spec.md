# Feature Specification: Duplicate and Split Pre-orders

**Feature Branch**: `027-preorder-duplicate-split`

**Created**: 2026-10-01

**Status**: Draft

**Input**: User description: "in preorder page: add feature to copy or duplicate a single or multiple preorder transaction and to split the preorder transaction"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Duplicate a single pre-order (Priority: P1)

A shopkeeper has a pre-order for a regular customer (for example a repeat buyer ordering the same set of merchandise again). Instead of re-entering the customer, event, fulfilment choice and every item line, they pick "Duplicate" on that pre-order and get a brand-new pre-order that starts from the same content, ready to be reviewed and, if needed, edited.

**Why this priority**: Re-keying near-identical orders is the most common time sink on the Pre-orders screen, and a single-order duplicate is the smallest slice that delivers value on its own.

**Independent Test**: Open any pre-order in the list, choose "Duplicate", and confirm a new pre-order appears in the list with its own number, status "Ordered", no payments, and the same customer, event, fulfilment and item lines as the original.

**Acceptance Scenarios**:

1. **Given** a pre-order with a customer, event, items, discount, shipping cost and notes, **When** the user duplicates it, **Then** a new pre-order is created with a new, unique pre-order number and the same customer, event, fulfilment type, pickup day/courier preference, items and quantities, discount, shipping cost and notes.
2. **Given** a pre-order that is partially paid, handed over, or cancelled, **When** the user duplicates it, **Then** the new pre-order starts as "Ordered" with no recorded payments, no shipment record, a "pending" invoice/shipping marker, and none of the original's cancel reason or status dates.
3. **Given** a duplicate was just created, **When** the confirmation appears, **Then** it shows the new pre-order number and lets the user open it directly to review or edit.
4. **Given** the original pre-order, **When** a duplicate is created, **Then** the original is unchanged in every respect (status, payments, stock effect, dates).
5. **Given** the new pre-order is opened, **When** the user views its details, **Then** it states which pre-order it was duplicated from.

---

### User Story 2 - Duplicate multiple pre-orders at once (Priority: P2)

A shopkeeper selects several pre-orders using the row checkboxes already on the list and duplicates them in one action — for example to roll a whole batch of orders over to the next event.

**Why this priority**: Builds directly on Story 1 and saves the most time for batch work, but the single-order flow already delivers a usable feature.

**Independent Test**: Tick three pre-orders, choose "Duplicate selected", and confirm three new independent pre-orders appear, each with its own number and each matching its own source order.

**Acceptance Scenarios**:

1. **Given** several pre-orders are selected, **When** the user chooses to duplicate the selection, **Then** one new pre-order is created per selected order (never merged into one) and the user sees a summary listing each source number and its new number.
2. **Given** a selection where one order cannot be duplicated (for example it contains a product that no longer exists), **When** the user duplicates the selection, **Then** the other orders are still duplicated and the summary clearly names the failed order and the reason.
3. **Given** no row is selected, **When** the user looks at the bulk actions, **Then** the duplicate action is unavailable.
4. **Given** the list is filtered or searched, **When** the user duplicates the selection, **Then** only the ticked, visible orders are duplicated.

---

### User Story 3 - Split a pre-order into two (Priority: P2)

A shopkeeper has one pre-order that should really be two: items belong to different sellers, only part of the goods will arrive first, or the customer wants part of the order shipped and part picked up. They choose "Split" on the pre-order, pick which item lines (and, where needed, how many units of a line) move to the new pre-order, and confirm. The original keeps the remainder, and a new pre-order is created with the moved items.

**Why this priority**: A distinct capability from duplication and valuable for real fulfilment problems, but less frequent than re-ordering.

**Independent Test**: Open a pre-order with at least two item lines, split off one line, and confirm the original and the new pre-order each show their own items, the two item sets together equal the original items, and the grand totals reconcile.

**Acceptance Scenarios**:

1. **Given** a pre-order with several item lines that is not yet handed over or cancelled, **When** the user moves some lines to a new pre-order and confirms, **Then** the original keeps the remaining lines and a new pre-order with its own number holds the moved lines, both with the same customer and event.
2. **Given** an item line with quantity 5, **When** the user moves 2 units, **Then** the original keeps 3 units and the new pre-order has 2 units of that product at the same unit price.
3. **Given** a pre-order that already has a recorded payment, **When** the user opens its actions, **Then** the split action is disabled with an explanation that payments must be handled first.
4. **Given** the user selects every unit of every line for the new pre-order, or none at all, **When** they try to confirm, **Then** the split is refused with a message that at least one item must stay and at least one must move.
5. **Given** a successful split, **When** the user views the combined result, **Then** subtotals of the two pre-orders add up exactly to the subtotal of the original before splitting, and each pre-order's total and outstanding amount are recalculated consistently (both are unpaid).
6. **Given** a pre-order that is handed over or cancelled, **When** the user opens its actions, **Then** the split action is unavailable.
7. **Given** a successful split, **When** either resulting pre-order is opened, **Then** it states that it was split from / into the related pre-order so the relationship can be traced.

---

### User Story 4 - Split by seller in one step (Priority: P3)

For a pre-order whose items belong to more than one seller, the user can choose "Split by seller" and have the system propose one pre-order per seller, so each seller's goods can be tracked, paid and handed over independently.

**Why this priority**: A convenience shortcut over Story 3 for the most common reason to split; the manual split already covers the need.

**Independent Test**: Take a pre-order with items from two sellers, choose "Split by seller", and confirm two pre-orders result, each containing only one seller's items.

**Acceptance Scenarios**:

1. **Given** a pre-order whose items belong to two or more sellers, **When** the user chooses "Split by seller" and confirms, **Then** the original keeps one seller's items and a new pre-order is created for each additional seller.
2. **Given** a pre-order whose items all belong to one seller, **When** the user opens its actions, **Then** "Split by seller" is unavailable or explains that there is nothing to split.

---

### Edge Cases

- Duplicating an order whose product variant has since been deleted or deactivated: the duplicate for that order fails with a clear message naming the item; other selected orders are unaffected.
- Duplicating an order whose event has ended or been removed: the duplicate keeps the link only if the event still exists; otherwise it is created without an event and the user is told.
- Duplicating an order whose customer, product or event belongs to the other data mode (DEMO/LIVE): the copy always lives in the currently active mode, and cross-mode references are never carried over.
- A pickup day on the original that no longer falls inside the event's date range: the duplicate drops the pickup day rather than carrying an invalid one.
- Splitting an order that has recorded payments (even a small deposit): refused; the user must resolve the payments first (for example by duplicating instead, or by using the existing payment/cancel flow).
- Splitting when the order has a shipping cost, an order-level discount or a shipment record: these must end up on exactly one resulting order (or be divided by a stated rule), never be counted twice or lost.
- Two users duplicate or split the same order at the same moment: each action produces consistent, uniquely numbered orders, and a split cannot move the same units twice.
- Splitting an order that has already received goods (stock increased): total stock must be unchanged by the split; only the ownership of the units between the two pre-orders changes.
- Duplicating or splitting must never send customer notifications on its own.

## Requirements *(mandatory)*

### Functional Requirements

**Duplicate**

- **FR-001**: Users MUST be able to duplicate a single pre-order from the Pre-orders list (row action) and from the pre-order detail view.
- **FR-002**: Users MUST be able to duplicate several pre-orders in one action by selecting rows with the existing checkboxes; one new pre-order is created per selected order.
- **FR-003**: A duplicate MUST copy the customer, event, fulfilment type, pickup day or courier preference, expected date, item lines (product variant and quantity), order-level discount, shipping cost and notes from the source.
- **FR-004**: A duplicate MUST start fresh: a new unique pre-order number, status "Ordered", the invoice/shipping marker reset to its initial value, creation date set to now, no payments, no payment proofs, no shipment record, no cancel reason, and no status-change history carried over.
- **FR-005**: Duplicating MUST NOT change the source pre-order or any stock level; stock is only affected later, through the normal pre-order lifecycle of the new order.
- **FR-006**: Any source status (including partially paid, goods arrived, settled, handed over and cancelled) MUST be duplicable.
- **FR-007**: A duplicate MUST price every item at the product's current price (exactly as a newly created pre-order would), not at the unit prices recorded on the original; the duplicate's subtotal and total are therefore recalculated and may differ from the original's.
- **FR-008**: In a bulk duplicate, each order MUST be processed independently: failures MUST NOT block the others, and the user MUST get a per-order summary of new numbers and failure reasons.
- **FR-009**: The new pre-order MUST record, and show in its details, which pre-order it was duplicated from.

**Split**

- **FR-010**: Users MUST be able to split a single pre-order from the Pre-orders list (row action) and from the pre-order detail view; splitting is available only for pre-orders that are not "Handed over" or "Cancelled" and that have no recorded payment.
- **FR-011**: The split flow MUST let the user choose, per item line, how many units (from 0 up to the line's quantity) move to the new pre-order, and MUST show before confirmation what each resulting pre-order will contain and its resulting subtotal.
- **FR-012**: A split MUST be refused unless at least one unit moves and at least one unit stays on the original.
- **FR-012a**: A split MUST be refused, with a message telling the user to handle the payment first, when the pre-order has any recorded payment (including a deposit); the split action MUST be shown disabled with that explanation rather than hidden.
- **FR-013**: Moved units MUST keep the unit price, cost and seller they had on the original; the sum of both resulting orders' subtotals MUST equal the original subtotal.
- **FR-014**: The new pre-order MUST copy the customer, event, fulfilment type, pickup day or courier preference and expected date from the original, start with its own new unique pre-order number, and take the same lifecycle status as the original. Because a split is only allowed while no payment exists, neither resulting pre-order carries any payment.
- **FR-015**: The order-level discount, shipping cost and any shipment record MUST each remain on exactly one resulting pre-order (the original) unless the user edits them afterwards; they MUST NOT be duplicated or dropped.
- **FR-016**: A split MUST NOT change total stock; if goods have already arrived, the units simply belong to whichever pre-order now holds them.
- **FR-017**: A split MUST be all-or-nothing: either both resulting pre-orders are correct and saved, or nothing changes.
- **FR-018**: Both resulting pre-orders MUST record and show their relationship ("split from" / "split into") so they can be traced.
- **FR-019**: Users MUST be able to choose "Split by seller" for a pre-order containing items from more than one seller, producing one pre-order per seller with the same rules as a manual split.

**Common**

- **FR-020**: Duplicate and split MUST be available to the same roles that may create and edit pre-orders today, and unavailable to everyone else, enforced on the server.
- **FR-021**: New pre-orders MUST be created in the currently active data mode (DEMO or LIVE); duplicating or splitting MUST never mix modes.
- **FR-022**: Duplicate and split actions MUST be written to the activity log with who performed them and the numbers of the source and resulting pre-orders.
- **FR-023**: Neither action MUST trigger customer email notifications automatically.
- **FR-024**: Summary figures on the Pre-orders screen (transaction count, per-status totals, grand total, outstanding) MUST reflect the new pre-orders immediately after either action.
- **FR-025**: Exports, invoices and reports MUST treat duplicated and split pre-orders as ordinary pre-orders; the split MUST NOT cause revenue to be counted twice.

### Key Entities

- **Pre-order**: A customer's advance order for merchandise, with a number, customer, optional event, fulfilment type, status, item lines, discount, shipping cost, notes, payments and optional shipment. Gains an optional reference to the pre-order it was duplicated or split from.
- **Pre-order item line**: A product variant, seller, quantity, unit price and cost snapshot belonging to one pre-order; the unit that moves between pre-orders in a split.
- **Pre-order relationship**: The traceable link between a pre-order and its origin (duplicated from, split from) or its split siblings, shown in details.
- **Payment**: A recorded payment against a pre-order; its existence blocks splitting, and it is never copied by a duplicate.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can duplicate a single pre-order in under 10 seconds and 3 interactions, compared with re-entering the order from scratch.
- **SC-002**: A user can duplicate 20 selected pre-orders in one action in under 30 seconds, with a clear per-order result.
- **SC-003**: A user can split a pre-order into two in under 1 minute, and 95% of first-time users complete a split without help.
- **SC-004**: For 100% of splits, the combined item quantities and subtotals of the resulting pre-orders equal those of the original before the split.
- **SC-005**: 100% of duplicates start as "Ordered" with zero recorded payments and never alter the source order.
- **SC-006**: Total stock and total recognised revenue are identical before and after any split.
- **SC-007**: After either action, the Pre-orders list and its summary figures show the new orders without a manual refresh.

## Assumptions

- "Copy" and "duplicate" are the same action; this feature offers a single "Duplicate" action.
- Bulk duplicate uses the row checkboxes already present on the Pre-orders list; no separate selection mechanism is introduced.
- A duplicate is created immediately (no pre-filled form step) and the user can then open it and use the existing edit capability; the user is not asked to change the customer or items during duplication.
- One action produces one copy per source order; making several copies of the same order at once is out of scope.
- Duplicates always use today's catalog prices; users who need the old price edit the duplicate afterwards.
- Splitting produces exactly two pre-orders (original + one new), except "Split by seller", which produces one per seller; further splits can be done by splitting again.
- The order-level discount, shipping cost, shipment record and notes stay with the original on a split; the user can adjust them with the existing edit capability afterwards.
- Splitting an order that has already received goods does not move or recreate stock — it only re-attributes units between the two pre-orders — so no new stock movement is needed by the split itself.
- Permissions follow the existing pre-order create/edit permissions; no new role or menu permission is introduced.
- Out of scope: merging pre-orders, splitting by quantity ratio automatically, scheduled/recurring duplication, and duplicating from the POS Sales screen.
- Depends on the existing pre-order lifecycle (status, payments, dispatch marker, shipment), item snapshots, customer picker and activity log; no change to those rules is intended.
