# Feature Specification: Seller-specific BOM Built from Purchase Order Lines

**Feature Branch**: `034-seller-po-bom`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "Bill of Materials (BOM) & Purchase Order Integration — redesign the product variant and BOM flow so the materials and services needed to produce a variant are defined from the selected seller's Purchase Orders. Everything required to produce a specific variant for a specific seller must be traceable to the seller's purchased materials/services and their actual purchase cost (Seller → Product Variant → BOM → PO line → Vendor → Purchase cost). Seller-specific, variant-specific, material + service support, historical cost preservation, fast BOM copying, table-based UI, replace the 'Linked Product' concept." Evidence: the New PO form (Vendor, Notes, line items of type Material/Service with a Material picker, an optional **Linked Product** picker, Qty, Unit Price) and the product edit screen's "Variants & prices" cards, where each variant's **Cost price** is typed by hand.

## What exists today (context, not new scope)

- A **purchase order** has a vendor, a status (draft → ordered → received → paid, or cancelled) and line items. A line is either a *material* or a *service*, has a quantity and a unit price, and may be tied to a **linked product**. A purchase order has **no seller** today.
- A product variant can have a **BOM** of materials with a quantity each, priced from a vendor price list kept per material — not from what was actually bought. A BOM line is not tied to a purchase order, and it has no services. The resulting "BOM cost" is a read-only figure shown beside the variant's hand-typed cost price; the two are never linked.
- A product already belongs to one seller; its variants therefore already belong to that seller.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Build a variant's BOM from the seller's purchase order lines (Priority: P1)

An owner or admin opens a product, picks one of its variants and opens that variant's BOM. They see a compact table (Item, Type, Purchase Order, Vendor, Unit Cost, Quantity, Total Cost, Action) with a running **Total BOM Cost**. They press **Add BOM Item**, search and filter the purchase order lines that belong to **this variant's seller**, tick one or several lines, and add them. Each added row takes the unit cost and vendor from the purchase order line and asks only for the quantity needed to make one finished unit.

**Why this priority**: This is the heart of the request: a BOM that is the real, traceable list of purchased inputs for one seller's variant, with no retyping of prices.

**Independent Test**: For a seller with three purchase orders (two materials from one vendor, one service from another), add the three lines to a variant's BOM and confirm the table shows each line's vendor, source purchase order and unit cost, and a total equal to the sum of unit cost × quantity.

**Acceptance Scenarios**:

1. **Given** a seller has purchase orders for a ball chain (Rp 500), a keychain ring (Rp 300) and an assembly service (Rp 1,000), **When** the user adds those three lines with quantity 1 each, **Then** the BOM shows three rows with the correct type, purchase order and vendor, and Total BOM Cost Rp 1,800.
2. **Given** another seller also has purchase orders, **When** the user opens the item selector for the first seller's variant, **Then** no line of the other seller's purchase orders is listed or can be added.
3. **Given** the selector is open, **When** the user filters by purchase order, vendor, item name, item type (material/service) or purchase order date, **Then** only matching eligible lines are shown, and several lines can be ticked and added in one action.
4. **Given** a BOM row, **When** the user enters a quantity of zero, a negative number or nothing, **Then** it is rejected with a clear message and the BOM is not changed.
5. **Given** a purchase order line is already in this variant's BOM, **When** the user tries to add it again, **Then** the system prevents the duplicate and points to the existing row (to change its quantity instead).
6. **Given** a BOM row, **When** the user removes it, **Then** the row disappears, the total updates, and the purchase order itself is untouched.

---

### User Story 2 - Purchase orders belong to a seller (Priority: P1)

When creating or editing a purchase order, the user records **which seller the purchase is for**. This ownership is what the BOM item selector filters on. The separate "Linked Product" picker on each purchase order line is no longer needed: which products use an item is now expressed by the BOM, not by the purchase order.

**Why this priority**: Without a seller on the purchase order, "only the selected seller's purchase orders" cannot be enforced. It is a prerequisite for User Story 1.

**Independent Test**: Create a purchase order for Seller A and one for Seller B; confirm each shows its seller on the purchase order list and detail, and that Seller A's BOM selector offers only Seller A's lines.

**Acceptance Scenarios**:

1. **Given** the New PO form, **When** the user saves a purchase order, **Then** a seller must be chosen and is shown on the purchase order's list row and detail.
2. **Given** purchase orders that existed before this feature (no seller), **When** they are listed, **Then** they are clearly marked "no seller assigned", are not offered by any BOM selector, and an owner/admin can assign a seller to them (recorded in the activity log).
3. **Given** a purchase order that already feeds one or more BOM items, **When** a user tries to change its seller, **Then** the change is refused with an explanation (it would break the seller-specific rule for those BOMs).
4. **Given** the purchase order form, **When** it is opened, **Then** there is no "Linked Product" field; purchase orders that already carry a linked product keep it readable on their detail (no data is lost).

---

### User Story 3 - Copy a BOM to other variants (Priority: P2)

A seller often has many variants (colours, designs) that need the same inputs. After configuring one variant's BOM, the user can fill the others quickly: **copy this BOM to all variants of the product**, **to the next variant**, **from another variant**, or **start empty**. Copying duplicates the rows (items, quantities, source references and costs); afterwards each variant's BOM is independent.

**Why this priority**: It removes the repetitive data entry that makes per-variant BOMs impractical for products with many variants, but a single BOM already works without it.

**Independent Test**: Configure a BOM on variant Red, copy it to Blue and Green, then change Blue's quantity and confirm Red and Green are unchanged.

**Acceptance Scenarios**:

1. **Given** variant Red has a BOM, **When** the user chooses "Copy BOM from another variant" on Blue and picks Red, **Then** Blue gets the same rows, quantities, source purchase order lines and unit costs.
2. **Given** a product with variants Red, Blue, Green, **When** the user chooses "Copy BOM to all variants" from Red, **Then** Blue and Green receive Red's rows; a variant that already has a BOM asks for confirmation before it is replaced.
3. **Given** a copied BOM, **When** the user edits or removes a row on one variant, **Then** the other variants' BOMs do not change.
4. **Given** a variant is being created, **When** the user picks "Start with empty BOM", **Then** the variant has no BOM rows.
5. **Given** the source and target variants belong to the same product (same seller), **When** copying, **Then** it succeeds; copying across different sellers is not offered.

---

### User Story 4 - BOM cost split into material and service, available as the variant's cost basis (Priority: P2)

The BOM totals are shown separated into **Material Cost**, **Service Cost** and **Total BOM Cost**, calculated automatically as the sum of unit cost × quantity. The variant shows this BOM cost as its production-cost basis so margin and pricing decisions can use it. When the owner marks a variant's BOM **complete**, the variant's **cost price follows the BOM cost automatically** (it is no longer typed by hand) and keeps following it on every later BOM change, until the BOM is reopened. Sales and pre-orders already recorded keep the cost they were recorded with.

**Why this priority**: It turns the traceable BOM into the number the business actually uses, but the BOM itself is valuable before the cost is wired downstream.

**Independent Test**: With a BOM of two materials (Rp 500 + Rp 300) and one service (Rp 1,000), confirm Material Cost Rp 800, Service Cost Rp 1,000, Total Rp 1,800, that the variant screen shows the BOM cost beside its cost price and margin, and that after marking the BOM complete the cost price reads Rp 1,800 and can no longer be typed over.

**Acceptance Scenarios**:

1. **Given** a variant whose BOM has materials and services, **When** the BOM is shown, **Then** Material Cost, Service Cost and Total BOM Cost are displayed and add up.
2. **Given** a BOM with no rows, **When** the BOM is shown, **Then** the cost is Rp 0 and the variant is shown as having no BOM (not as a zero-cost product).
3. **Given** a variant with a BOM, **When** its cost price and margin are shown, **Then** the BOM cost is available next to them as the cost basis.
4. **Given** a BOM row's quantity changes, **When** it is saved, **Then** every cost figure that depends on the BOM is recalculated automatically.
5. **Given** a variant whose BOM is marked complete, **When** the BOM cost changes (a row is added, removed, or its quantity or source changes), **Then** the variant's cost price and margin update to the new BOM cost at once.
6. **Given** a variant whose BOM is marked complete, **When** the user looks at the variant's cost price field, **Then** it is shown as controlled by the BOM (not editable by hand), with a way to reopen the BOM, after which the cost price can be typed again and keeps its last value.
7. **Given** sales or pre-orders recorded before the BOM cost changed the cost price, **When** profit reports and seller settlements are shown, **Then** those past transactions still show the cost they were recorded with; only transactions recorded afterwards use the new cost price.

---

### User Story 5 - Historical cost stays accurate and every change is traceable (Priority: P2)

A BOM row **remembers** the purchase order, the purchase order line, the vendor and the unit cost it was created with. If a later purchase order buys the same item at a different price, or the source purchase order is changed after the fact, the existing BOM is **not** silently changed. The user sees that a newer price exists and can **explicitly update or replace** the row. Cancelling a purchase order that feeds a BOM never alters the BOM's recorded cost; the row is flagged so the user can review it. Every change to a BOM (rows added, removed, quantity or source changed, copied) is recorded with who and when.

**Why this priority**: Cost accuracy over time is a stated priority, and silent repricing would corrupt past profit figures.

**Independent Test**: Build a BOM from a purchase order line at Rp 500, create a newer purchase order for the same item at Rp 600, and confirm the BOM still shows Rp 500 with a "newer price available" cue; update the row explicitly and confirm Rp 600 and an audit entry.

**Acceptance Scenarios**:

1. **Given** a BOM row created from a line at Rp 500, **When** a newer purchase order for the same material has a different price, **Then** the row still shows Rp 500 and offers an explicit "use newer price / replace source" action.
2. **Given** a BOM row whose source purchase order is later cancelled, **When** the BOM is viewed, **Then** the row keeps its recorded cost, is visibly flagged "source purchase order cancelled", and the variant is not silently recalculated to zero.
3. **Given** any BOM change, **When** the activity log is viewed, **Then** an entry shows who changed which variant's BOM, what changed (old and new values), and when.
4. **Given** a purchase order that feeds a BOM, **When** a user tries to delete it, **Then** deletion is refused (purchase orders can only be deleted while draft, and a draft cannot feed a BOM).

---

### User Story 6 - Replace "Linked Product" and bring existing BOMs forward (Priority: P3)

The purchase order's "Linked Product" field is retired from the form, and the product configuration no longer relies on it to describe materials. Existing BOM lines created under the old model (a material and a quantity priced from the vendor price list) are not lost. They are kept as **legacy rows**: still visible, clearly flagged "no purchase order source", still counted in the BOM cost at the cost they have today, and the owner can replace each with a purchase-order-sourced row at their own pace. A variant that still has legacy rows cannot be marked complete. The vendor price list and the master-data Excel import/export sheets for BOM and vendor prices stay as they are.

**Why this priority**: It protects existing data and removes the confusing duplicate concept, but is not needed to deliver the new flow.

**Independent Test**: With an existing variant that has an old-style BOM, open it after the change and confirm no row disappeared and each old row is clearly marked according to the chosen rule.

**Acceptance Scenarios**:

1. **Given** a variant with old-style BOM lines, **When** the new BOM is opened, **Then** every old line is still visible, clearly flagged as legacy, and included in the BOM cost at its current cost.
2. **Given** the purchase order form, **When** it is opened, **Then** "Linked Product" is absent.
3. **Given** a variant with a legacy row and PO-sourced rows, **When** the user tries to mark the BOM complete, **Then** it is refused with a message naming the legacy rows, until each is replaced by a PO-sourced row or removed.

---

### Edge Cases

- A purchase order in **draft** is not eligible as a BOM source (its lines can still be rewritten); **cancelled** purchase orders are not offered for new rows. Purchase orders that are ordered, received or paid are eligible.
- Two lines of the same material at different prices (two purchase orders): both are selectable; the user chooses which one this variant's BOM uses; the same line cannot be added twice to one variant.
- A BOM quantity is per **one finished unit** of the variant (for example 1 ball chain per keychain), not the purchased quantity; the purchase order's quantity (for example 1,000) is shown for reference only.
- A variant with no BOM at all (for example a resold item) is valid; a BOM is optional unless the owner marks the variant's BOM as complete (see Assumptions).
- A seller with no eligible purchase order lines: the selector explains why it is empty and links to creating a purchase order.
- Switching the product's seller after BOM rows exist: refused or requires clearing the BOM, because rows from the previous seller's purchase orders would violate the seller rule.
- Deleting a vendor, material or product referenced by a BOM row follows the existing delete guards; the BOM row keeps its recorded names and costs.
- Copying onto a variant that already has rows asks for confirmation and never merges silently.
- A complete BOM whose cost price changes after the owner reopens it: the cost price keeps its last value and becomes editable; closing it again re-applies the BOM cost.
- A variant marked complete whose BOM later becomes invalid (a row removed so the BOM is empty): the BOM can no longer stay complete, it is reopened automatically with a notice, and the cost price keeps its last value.
- Money is shown in the product's existing money format; unit costs keep their recorded precision.
- DEMO and LIVE data never mix: the selector and copy only see rows of the active mode.

## Requirements *(mandatory)*

### Functional Requirements

**Seller ownership of purchase orders**

- **FR-001**: Every purchase order MUST record the seller it was bought for, set when the purchase order is created or edited, and shown on the purchase order list and detail.
- **FR-002**: Purchase orders created before this feature MUST remain usable and visible but be marked as having no seller; they MUST NOT be offered as a BOM source until an owner or admin assigns a seller (recorded in the activity log).
- **FR-003**: A purchase order that feeds at least one BOM row MUST NOT have its seller changed. A purchase order belongs to ONE seller as a whole (chosen once on the purchase order, not per line); a vendor order that serves several sellers is entered as separate purchase orders.
- **FR-004**: The purchase order form MUST no longer offer "Linked Product"; values already stored remain readable.

**Seller- and variant-specific BOM**

- **FR-005**: Each product variant MUST have its own BOM; a BOM belongs to exactly one variant, and therefore to that variant's seller.
- **FR-006**: A BOM MUST be able to hold both material rows and service rows.
- **FR-007**: A BOM row MUST reference one purchase order line, and MUST record at creation: the item name and type, the purchase order number, the vendor, and the unit cost taken from that line.
- **FR-008**: The BOM item selector MUST list only lines of purchase orders that belong to the variant's seller and are ordered, received or paid (not draft, not cancelled), and MUST NOT show any other seller's lines.
- **FR-009**: The selector MUST support filtering by purchase order, vendor, item or material name, item type, and purchase order date, and MUST allow ticking and adding several lines at once.
- **FR-010**: A BOM row's quantity MUST be greater than zero; the unit cost defaults to the purchase order line's price and is not typed again.
- **FR-011**: The same purchase order line MUST NOT appear twice in one variant's BOM.
- **FR-012**: The BOM MUST be shown as a compact table with Item, Type, Purchase Order, Vendor, Unit Cost, Quantity, Total Cost and a Remove action, with a Total BOM Cost row and an Add BOM Item action.
- **FR-013**: The system SHOULD show, for reference only, the quantity purchased on the source line; it MUST NOT block a BOM row because of it at this stage (consumption tracking is a later extension).

**Copying**

- **FR-014**: Users MUST be able to fill a variant's BOM by: copying to all variants of the product, copying to the next variant, copying from another variant, or starting empty.
- **FR-015**: A copy MUST duplicate rows with the same items, quantities, source references and recorded unit costs; afterwards each variant's BOM MUST be independently editable, and a change to one MUST NOT alter another.
- **FR-016**: Copying onto a variant that already has BOM rows MUST require explicit confirmation before replacing them; copying across sellers MUST NOT be possible.

**Cost calculation**

- **FR-017**: Item cost MUST equal unit cost × quantity; BOM cost MUST equal the sum of item costs; material cost and service cost MUST be shown separately and add up to the total.
- **FR-018**: The BOM cost MUST be recalculated automatically whenever a BOM row is added, removed or its quantity changes.
- **FR-019**: The variant MUST expose its BOM cost as its production-cost basis wherever its cost, margin or price is shown, so that product cost, margin, pricing, profit reporting and inventory valuation can use it.
- **FR-028**: While a variant's BOM is marked complete, the variant's cost price MUST equal its Total BOM Cost, MUST be updated automatically on every change that alters that cost, and MUST NOT be editable by hand; reopening the BOM MUST make it editable again and leave the last value in place.
- **FR-029**: A change to a variant's cost price MUST affect only transactions recorded afterwards; sales and pre-orders already recorded, and the profit and settlement figures built from them, MUST keep the cost they were recorded with.

**History, safety and audit**

- **FR-020**: An existing BOM row MUST keep the unit cost it was created with when the source purchase order's price changes, when a newer purchase order is created, or when the source purchase order is cancelled.
- **FR-021**: When a newer eligible purchase order line exists for the same item at a different price, the BOM MUST show a cue and offer an explicit action to update or replace the row; it MUST NOT change the row on its own.
- **FR-022**: A BOM row whose source purchase order is cancelled MUST stay in the BOM with its recorded cost and be visibly flagged for review.
- **FR-023**: Deleting or editing a purchase order MUST NOT silently alter, remove or recost any BOM row that references it; purchase orders that feed a BOM cannot be deleted.
- **FR-024**: Every BOM change (row added, removed, quantity changed, source replaced, copy applied) MUST be recorded in the activity log with who, when, the variant, and the old and new values.
- **FR-025**: A variant MUST NOT be markable as "BOM complete" while its BOM is empty, contains a legacy row, or contains a row with a missing or invalid source; variants without any BOM remain valid (the BOM is optional unless marked complete).
- **FR-030**: Existing BOM lines that have no purchase order source MUST be kept, shown as legacy rows, included in the BOM cost at their current cost, and replaceable one by one by a purchase-order-sourced row; the vendor price list and the BOM / vendor-price Excel import/export sheets MUST keep working as they do today.

**Access and data modes**

- **FR-026**: Viewing and editing BOMs, purchase order seller assignment, and BOM copying MUST be limited to the roles that already manage master data and purchase orders (owner, admin, inventory); cashiers MUST NOT see BOM costs.
- **FR-027**: BOM and purchase order data MUST respect the active DEMO/LIVE mode; rows of the other mode are never listed, copied or counted.

### Key Entities *(include if feature involves data)*

- **Purchase order (changed)**: gains a seller; keeps vendor, status, lines and totals. Its status decides whether its lines are eligible as BOM sources.
- **Purchase order line**: an item bought (material or service) with quantity and unit price; the traceable origin of a BOM row's cost.
- **BOM row (new shape)**: belongs to one product variant; references one purchase order line; remembers item name, type, purchase order number, vendor and unit cost at the time it was added; has a quantity per finished unit; flagged when its source is cancelled or a newer price exists.
- **Product variant**: belongs (through its product) to one seller; has zero or one BOM; shows BOM material cost, service cost and total, and whether its BOM is marked complete.
- **Seller**: owns products (and thus variants) and the purchase orders bought for them; the filter for the BOM item selector.
- **Vendor / Material**: unchanged master data; vendor appears on each BOM row through its purchase order.
- **BOM change log entry**: who, when, which variant, what changed (old and new values).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can build a BOM of three purchased items (two materials, one service) for a variant in under 2 minutes without typing any price.
- **SC-002**: In 100% of checked cases, a variant's BOM selector offers zero lines from another seller's purchase orders.
- **SC-003**: For every BOM row, a user can read the vendor, purchase order number and unit cost it came from without leaving the BOM table (full traceability: seller → variant → BOM row → purchase order line → vendor → cost).
- **SC-004**: Total BOM Cost equals the sum of unit cost × quantity for every row in 100% of checked BOMs, and material cost + service cost equals the total.
- **SC-005**: After changing or cancelling a source purchase order, 0 BOM rows change their recorded cost without an explicit user action.
- **SC-006**: Configuring the same BOM on 10 variants of one product takes no more than 1 minute using copy, versus re-adding rows 10 times.
- **SC-007**: Editing one variant's copied BOM leaves the other variants' BOMs unchanged in 100% of checked cases.
- **SC-008**: 100% of BOM changes appear in the activity log with who, when and old/new values.
- **SC-009**: No existing purchase order, variant, BOM line or cost figure disappears or silently changes after the feature is introduced (0 data-loss regressions).

## Assumptions

- "Seller" is the product's existing seller (artist); a variant cannot belong to more than one seller, so "seller + variant" is satisfied by the variant's product. The example of the same keychain sold by two sellers is represented by two products (one per seller), each with its own variants and BOMs.
- Only purchase orders that are ordered, received or paid are eligible BOM sources: a draft can still be rewritten line by line, and a cancelled purchase order is not a real purchase.
- The BOM quantity is per one finished unit; the purchase order's total quantity is shown for reference and is not enforced as a consumption limit now (consumption and inventory tracking are future extensions).
- A variant's BOM is optional (for example a resold item with no production inputs); it is only checked for completeness when the owner marks it complete.
- The purchase order's existing money, status and payment behaviour is unchanged; only the seller field is added and the linked-product field retired from the form.
- Reports that already use the variant's cost price and the seller settlements must not change their historical numbers: sales and pre-orders keep the cost recorded at the time, so only new transactions use a cost price that follows a BOM.
- Decisions confirmed by the requester (2026-10-05): the BOM cost becomes the cost price automatically once the BOM is marked complete; legacy BOM lines are kept and flagged (with the vendor price list and Excel sheets unchanged); a purchase order belongs to one seller as a whole.
- "Other applicable production costs" in the requested cost formula are out of scope for this feature (no new cost types are added); the cost basis is material cost plus service cost.
- Seller-agnostic shared BOM templates are out of scope: copying duplicates rows, it does not link variants.
