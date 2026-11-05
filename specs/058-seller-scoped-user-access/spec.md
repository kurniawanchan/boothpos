# Feature Specification: Seller-Scoped User Access

**Feature Branch**: `094-seller-scoped-user-access`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "i want to assign user to a seller then when the user login, all data only show for that seller"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Owner assigns a user to a seller (Priority: P1)

An owner/admin opens a user's profile and links that user to exactly one seller (or clears the link). A user with no seller keeps today's behaviour (sees everything their role allows).

**Why this priority**: Without the assignment nothing else can work; it is the data foundation.

**Independent Test**: Assign a user to seller A, reload the user list, and see the seller shown on that user; clear it and see it removed.

**Acceptance Scenarios**:

1. **Given** an active user and an active seller, **When** the owner assigns the seller to the user, **Then** the user shows that seller in the user list and detail.
2. **Given** a user already assigned, **When** the owner changes or clears the seller, **Then** the new state is saved and recorded in the activity log.
3. **Given** a non-owner/admin user, **When** they try to assign a seller, **Then** the action is refused.
4. **Given** a seller assigned to a user, **When** someone tries to delete that seller, **Then** the system blocks it with a clear reason (or the owner is told which users are linked).

---

### User Story 2 - Seller-assigned user sees only their seller's data (Priority: P1)

When a seller-assigned user logs in, every screen and report they can reach shows only data belonging to their seller: products, variants, stock and stock history, sales lines, pre-order lines, and per-seller reports. Other sellers' data is never visible, searchable, exportable, or reachable by guessing an address.

**Why this priority**: This is the entire purpose of the feature and a confidentiality requirement (other sellers' sales and costs must not leak).

**Independent Test**: Log in as a user assigned to seller A in a dataset with sellers A and B; products, stock, reports and exports contain only A's data, and orders appear only when they hold an A line.

**Acceptance Scenarios**:

1. **Given** a user assigned to seller A, **When** they open Products, Stock and Stock history, **Then** only seller A's items appear.
2. **Given** a sale or pre-order that contains items from sellers A and B, **When** the A-user views it, **Then** they see the whole order (all lines, totals, payments) because at least one line is theirs. An order with no line of seller A is not visible to them.
3. **Given** the A-user, **When** they open a record belonging only to seller B by direct address or by ID, **Then** the system answers as if it does not exist.
4. **Given** the A-user, **When** they use search, filters, dashboards, totals or Excel exports, **Then** none of them include or count seller B's data, and the seller filter lists only seller A.
5. **Given** a user with no seller, **When** they log in, **Then** behaviour is unchanged.

---

### User Story 3 - Seller-assigned user cannot reach other sellers' data through actions (Priority: P2)

A seller-assigned user cannot create, change, or delete records for another seller (e.g. add a product for seller B, adjust B's stock), even by crafting a request.

**Why this priority**: Prevents write-side leakage and cross-seller tampering; secondary to read isolation.

**Independent Test**: As the A-user, attempt to create/modify seller B's product or stock; it is refused and nothing changes.

**Acceptance Scenarios**:

1. **Given** an A-user permitted to create products, **When** they create one, **Then** it is automatically for seller A and they cannot choose another.
2. **Given** the A-user, **When** they attempt an action on a seller-B record, **Then** it is refused and no data changes.

---

### Edge Cases

- A sale or pre-order mixes several sellers (decision Q1): the scoped user sees the WHOLE order, including other sellers' lines, prices, totals, payments and the customer, as long as one line is theirs. Product-owner decision; it knowingly exposes other sellers' line data within shared orders. Cost price and margin of other sellers' lines stay hidden.
- The assigned seller is deactivated or soft-deleted: the user can still log in but sees no data and a clear notice (no fallback to "see everything").
- The owner changes a user's seller while that user is logged in: the new scope applies on their next request.
- Cost price, margin and other sellers' comparisons: hidden unless the user's role already grants cost access, and then only for their own seller.
- Users whose role is Owner can never be assigned a seller and always see all sellers' data (product-owner decision 2026-11-05). Changing a seller-assigned user's role to Owner clears the seller. Other roles, including Admin, are scoped when assigned.
- DEMO/LIVE mode boundaries continue to apply on top of the seller scope.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST let an owner/admin assign a user to at most one seller, change it, or clear it.
- **FR-002**: A user without a seller MUST keep exactly today's access; all existing users start without a seller.
- **FR-014**: A user with the Owner role MUST NOT be assignable to a seller (the option is not offered and the server refuses it) and MUST always have access to all sellers' data. Making a seller-assigned user an Owner removes their seller link.
- **FR-003**: For a seller-assigned user, every list, detail, search, filter option, summary total, dashboard figure, report and export MUST include only that seller's data.
- **FR-004**: Records that belong to a different seller MUST be indistinguishable from non-existent ones (no "forbidden" hint that reveals existence).
- **FR-005**: A sale or pre-order MUST be visible to a scoped user if and only if at least one of its lines belongs to their seller; when visible, the whole order is shown. Summary statistics and seller-level reports still count only the scoped seller's own lines and share.
- **FR-006**: Outside orders that contain their own seller's lines (FR-005), scoped users MUST NOT see other sellers' names, products, stock, amounts, customers or cost data, including dropdowns and filters. Other sellers' cost and margin are never shown.
- **FR-007**: Create/update/delete actions by a scoped user MUST be limited to their own seller; new records default to that seller.
- **FR-013**: Scoped users MUST be able to operate (POS sales, stock adjustments, pre-orders and so on) within their seller, as far as their existing role and action permissions allow. This is not a view-only portal, so PRD §10.2's cut of the artist self-service portal is not revived.
- **FR-008**: Existing role menu and action permissions MUST still apply; seller scope only narrows, never widens.
- **FR-009**: Seller scope MUST be enforced by the system for every access path, not only by hiding screens.
- **FR-010**: A seller linked to users MUST NOT be deletable without the owner being told, and a deactivated seller leaves the user with an empty, clearly explained view.
- **FR-011**: Assigning/changing/clearing a user's seller MUST be recorded in the activity log.
- **FR-012**: The user list MUST show each user's seller and allow filtering by it; user import/export MUST carry the seller.

### Key Entities

- **User**: an account with a role; gains an optional single seller link.
- **Seller (Artist)**: the owner of products and the earner of sales; may have zero or more linked users.
- **Seller scope**: the rule "this user only sees data attributed to this seller", derived from the user's link.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In a dataset with at least two sellers, a scoped user sees 0 products, stock records, report rows or names of other sellers across all screens, searches, reports and exports; the only exception is the other lines inside an order that contains their own seller's line.
- **SC-002**: 100% of attempts by a scoped user to open or change another seller's record fail without revealing it exists.
- **SC-003**: An owner can assign or change a user's seller in under 30 seconds.
- **SC-004**: Users without a seller experience no change in what they see or can do.
- **SC-005**: A scoped seller's summary totals equal the sum of their own visible rows.

## Assumptions

- One user belongs to at most one seller (many users per seller allowed).
- Seller-assigned users are the shop's own staff/cashiers/inventory or seller representatives who already have an account and role; roles keep deciding what actions are allowed.
- Scoping applies on top of the existing DEMO/LIVE separation and role/action permissions (feature 057).
- Existing settings, backups and role administration remain owner/admin concerns and are unavailable to scoped users unless their role grants it.
