# Feature Specification: Purchase Orders, Store Customization, Activity Log Screen, New Reports, POS Drafts, Per-Artist Opening Cash, Split Payment

**Feature Branch**: `006-purchase-order-and-ops`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "tambah fitur ini: Purchase Order — Ubah vendor dan bahan baku menjadi Purchase Order atau Pembelian yang memungkinkan memasukkan pembelian bahan baku atau jasa dari vendor tertentu (toko offline atau online). Ada status pembelian juga. Bisa link bahan baku ke produk tertentu. Bisa cetak faktur purchase order. crud lengkap. Setting: Tema (warna, sediakan color picker); setting tampilan struk (text dan logo). Activity log. Laporan: pembelian; stok per artist. Kasir: simpan transaksi kasir sebagai draft; pada cashier session, buat opening cash per artist. Payment: split payment; catatan di payment."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record and track purchase orders from vendors (Priority: P1)

An inventory manager or owner needs to record what raw materials or services were bought from which vendor, at what price, track the purchase through its lifecycle from being placed to being received and paid, and print an invoice/document for the purchase.

**Why this priority**: This is the largest, most-requested piece of this batch — it completes the vendor/material loop that today only tracks reference prices, turning it into an actual purchasing record. It is also the most complex, so getting its data model and workflow right first de-risks everything else in this feature.

**Independent Test**: Create a purchase order against a vendor with one or more material line items, move it through Draft → Ordered → Received (confirm the received materials' stock/reference data updates) → Paid, print its invoice, and confirm it appears in purchase history.

**Acceptance Scenarios**:

1. **Given** the Purchase screen, **When** the user creates a new purchase order, **Then** they select a vendor, add one or more line items (each a material and/or a described service, quantity, unit price), and save it as Draft.
2. **Given** a Draft purchase order, **When** the user marks it as Ordered, **Then** its status updates and it appears in the "awaiting receipt" view.
3. **Given** an Ordered purchase order, **When** the user marks it as Received, **Then** each material line item's quantity is added to that material's available stock, and the order moves to Received status.
4. **Given** a Received purchase order, **When** the user records it as Paid, **Then** its status updates to Paid and it is included in the purchases report.
5. **Given** a purchase order in any status before Received, **When** the user cancels it, **Then** it moves to Cancelled and no stock or payment effects occur.
6. **Given** a purchase order with material line items, **When** the user links a line item to a specific product, **Then** that link is retained and visible when reviewing the order or that product's cost breakdown context.
7. **Given** any purchase order, **When** the user requests to print its invoice, **Then** a printable/downloadable document is produced showing vendor, line items, quantities, prices, totals, and status.
8. **Given** the Purchase screen, **When** the user edits, views, or deletes a purchase order, **Then** the same role-based access rules that govern other master-data screens apply, and a Draft order can be freely edited or deleted while a non-Draft order's line items become read-only (status and payment can still change).

---

### User Story 2 - Accept a payment split across multiple methods (Priority: P2)

A cashier finalizing a sale (or recording a pre-order payment) needs to accept part of the amount in cash and part by transfer or QRIS — a common real-world scenario at an event booth (e.g., a customer pays half in cash, half by e-wallet because they're short on cash).

**Why this priority**: High daily-use value at checkout, and the underlying data already supports multiple payment entries per transaction — this is primarily about exposing that capability in the UI, making it a comparatively fast, high-impact win once Purchase Order's larger data-model work is out of the way.

**Independent Test**: Start a checkout (or pre-order payment) with a total due, add a cash entry for part of the amount, add a second entry with a different method for the remainder, confirm the payment only completes once the entries fully cover the amount due, and confirm both entries are recorded and visible on the resulting receipt/history.

**Acceptance Scenarios**:

1. **Given** a checkout or pre-order payment screen with an amount due, **When** the user adds a payment entry with a method and amount less than the full amount due, **Then** the screen shows the remaining balance still owed.
2. **Given** a remaining balance greater than zero, **When** the user adds another payment entry (a different or the same method), **Then** the remaining balance decreases accordingly, and the user may continue adding entries until it reaches zero.
3. **Given** entries that together do not yet cover the full amount due, **When** the user attempts to confirm the transaction, **Then** confirmation is blocked with a clear message describing the shortfall.
4. **Given** entries that together exactly cover (or, for cash, exceed) the amount due, **When** the user confirms, **Then** the transaction completes and every entry is individually recorded against it.
5. **Given** a completed split payment, **When** the user views the transaction's receipt or detail, **Then** every payment method and amount used is listed individually, not merged into one line.

---

### User Story 3 - Add a note to a payment (Priority: P2)

A cashier or admin recording a payment needs to attach a short free-text note to it (e.g., "customer paid with a torn bill, verified", "partial refund pending manager approval") for later reference.

**Why this priority**: Small, low-effort addition (the underlying field already exists in storage) that materially improves the auditability of every payment recorded in the system — high value for the effort, but smaller in scope than split payment.

**Independent Test**: Record a payment with a note attached, then view that payment's detail (in order history, pre-order history, or activity log) and confirm the note is visible.

**Acceptance Scenarios**:

1. **Given** any screen where a payment is recorded (checkout, pre-order payment, purchase order payment), **When** the user fills in an optional note field before confirming, **Then** the note is saved together with the payment.
2. **Given** a payment that was recorded without a note, **When** it is viewed later, **Then** the note field is simply empty, not an error state.
3. **Given** a payment with a note, **When** the note text exceeds a reasonable length, **Then** the system rejects it with a clear validation message rather than silently truncating it.

---

### User Story 4 - Save a POS transaction as a draft (Priority: P2)

A cashier who is partway through building a cart (picking items, applying a discount) needs to set it aside — because the customer stepped away, needs to check something, or another customer needs quick service — without losing the work in progress, and resume it later.

**Why this priority**: Directly reduces lost time and re-entry work at a busy booth during a live event — one of this product's core value propositions — but is scoped narrower than Purchase Order or split payment (no stock or financial state changes, purely a saved-cart convenience).

**Independent Test**: Build a cart with items and a discount, save it as a draft, clear the active cart, reopen the draft from a drafts list, confirm the cart is restored exactly as saved, then either complete checkout or discard the draft.

**Acceptance Scenarios**:

1. **Given** a cart with at least one item, **When** the cashier chooses to save it as a draft, **Then** the cart's contents (items, quantities, discount, selected customer) are stored and the active cart is cleared for a new transaction.
2. **Given** one or more saved drafts, **When** the cashier opens the drafts list, **Then** each draft shows enough summary information (item count, total, customer, saved time) to identify it.
3. **Given** a saved draft, **When** the cashier resumes it, **Then** the cart is restored to its saved state and can be edited further or checked out normally.
4. **Given** a saved draft, **When** the cashier discards it instead of resuming it, **Then** it is permanently removed and no longer appears in the drafts list.
5. **Given** a draft that references a product/variant later deactivated or a customer later removed, **When** the cashier resumes it, **Then** the system clearly flags the affected line/field rather than silently dropping it or crashing.
6. **Given** any saved draft, **When** stock levels are checked, **Then** a draft has no effect on available stock — stock is only affected when a transaction is actually completed, exactly as if the draft's cart had never been saved.

---

### User Story 5 - Record opening cash per artist in a cashier session (Priority: P2)

An owner/admin or cashier opening a cashier session at a multi-artist booth needs to record that different artists contributed different amounts of starting cash (float) into the shared cash drawer, so the session's cash reconciliation at close-out can be broken down per artist rather than as one lump sum.

**Why this priority**: Directly requested and improves settlement accuracy for the multi-artist booths this product is built around, but it's a scoped addition to an existing, well-understood screen (Sesi Kasir) rather than new subject matter — lower complexity than Purchase Order or POS drafts.

**Independent Test**: Open a cashier session, enter starting cash amounts against two or more different active artists, confirm the session's total opening cash equals their sum, and confirm the per-artist breakdown is visible on the session summary/close-out screen.

**Acceptance Scenarios**:

1. **Given** the session-opening screen, **When** the user opens a new session, **Then** they may enter a starting cash amount against one or more active artists, in addition to (or instead of) a single non-attributed opening amount.
2. **Given** a session opened with per-artist amounts, **When** the total opening cash for the session is displayed anywhere it currently appears, **Then** it equals the sum of all per-artist (and any non-attributed) amounts.
3. **Given** an open session with per-artist opening cash recorded, **When** the user views the session summary or closes the session, **Then** the per-artist breakdown is shown alongside the existing cash-reconciliation figures.
4. **Given** a session opened the old way (a single opening amount, no per-artist breakdown), **When** it is viewed or closed, **Then** it continues to work exactly as before — per-artist detail is optional, not required.

---

### User Story 6 - Customize the store's theme color (Priority: P3)

An owner wants the app's accent color to match their store's branding instead of the default color, using a visual color picker rather than typing a hex code from memory.

**Why this priority**: Cosmetic, store-wide preference with no effect on business logic or data correctness — lowest risk and lowest urgency of the six areas in this batch.

**Independent Test**: Open Settings, pick a new accent color via the color picker, save, and confirm the chosen color is now used for the app's primary accent (buttons, active states) across at least two different screens without a page-breaking contrast/readability issue.

**Acceptance Scenarios**:

1. **Given** the Settings screen, **When** the owner/admin opens the theme section, **Then** they see a color picker (not a raw hex-code-only input) defaulted to the current accent color.
2. **Given** a newly picked color, **When** the user saves it, **Then** the app's primary accent color updates everywhere that token is used, without requiring every individual screen to be updated separately.
3. **Given** a picked color that would fail basic legibility (e.g., near-white on the app's white backgrounds), **When** the user attempts to save it, **Then** the system warns them rather than silently producing an unreadable interface.
4. **Given** no custom color has ever been set, **When** any screen renders, **Then** it uses the existing default accent color exactly as today — this feature is additive, not a forced re-theme.

---

### User Story 7 - Customize receipt text and logo (Priority: P3)

An owner wants to control what appears on printed/shown customer receipts beyond the store name already configurable today — specifically custom footer text (e.g., a thank-you message, return policy) and whether/which logo image appears.

**Why this priority**: Cosmetic/branding, scoped narrowly to the receipt output; independent of every other area in this batch.

**Independent Test**: Set custom receipt footer text and confirm/upload a logo in Settings, complete a sale, and confirm the resulting receipt shows the configured footer text and logo.

**Acceptance Scenarios**:

1. **Given** the Settings screen's receipt section, **When** the owner/admin enters custom footer text, **Then** it is saved and appears on every receipt generated afterward.
2. **Given** the Settings screen's receipt section, **When** the owner/admin uploads or changes the store logo, **Then** it appears on receipts going forward (reusing the existing logo upload already available for the store profile, now also surfaced on the receipt itself).
3. **Given** no custom footer text has been set, **When** a receipt is generated, **Then** it omits the footer section rather than showing an empty/broken block.
4. **Given** the owner/admin wants no logo on receipts, **When** they clear the logo setting, **Then** subsequent receipts render correctly without a logo, preserving layout.

---

### User Story 8 - View the activity log (Priority: P3)

An owner/admin wants to browse a record of sensitive actions taken in the system (who deleted what, who adjusted stock, who changed a role) from within the app, instead of only via a raw API response.

**Why this priority**: The audit data itself is already recorded system-wide today — this story is purely about giving owner/admin a screen to see it, which is valuable but not urgent since no data or capability is currently missing, only its visibility.

**Independent Test**: As an owner/admin, open the Activity Log screen, see a list of recent recorded actions with who/what/when, filter or search it, and confirm a cashier/inventory role cannot see this screen at all.

**Acceptance Scenarios**:

1. **Given** an owner/admin account, **When** they open the Activity Log screen, **Then** they see a reverse-chronological list of recorded actions, each showing who performed it, what it was, and when.
2. **Given** the Activity Log screen, **When** the user filters by date range, action type, or the user who performed it, **Then** the list narrows accordingly.
3. **Given** a role without access to this area, **When** they attempt to reach the Activity Log screen, **Then** the screen/menu entry is not shown to them at all.
4. **Given** a large volume of log entries, **When** the user scrolls or pages through the list, **Then** it loads incrementally rather than requiring the entire history to load at once.

---

### User Story 9 - View a purchases report (Priority: P3)

An owner/admin wants a summarized view of purchasing activity — how much was spent, with which vendors, over what period — to understand the cost side of running the store, mirroring the sales-side reports that already exist.

**Why this priority**: Directly depends on User Story 1's purchase order data existing before it has anything to report on, and is a read-only convenience once that data exists — appropriately lower priority than the data-producing story it depends on.

**Independent Test**: With several purchase orders in different statuses recorded, open the purchases report, filter by a date range and/or vendor, and confirm the totals and per-order rows shown match the underlying purchase orders.

**Acceptance Scenarios**:

1. **Given** purchase orders exist in various statuses, **When** the user opens the purchases report, **Then** they see totals (e.g., total spent, order count) and a breakdown they can filter by date range and/or vendor.
2. **Given** the purchases report, **When** the user narrows by status (e.g., only Paid, or only Received-but-unpaid), **Then** the totals and rows update to reflect only matching orders.
3. **Given** the purchases report is open, **When** the user requests to export it, **Then** they receive a downloadable file consistent with how other reports in the system are already exported.

---

### User Story 10 - View stock per artist report (Priority: P3)

An owner/admin wants to see current stock levels grouped by the artist who owns each product, to understand at a glance how much inventory each artist currently has in the system.

**Why this priority**: A read-only report over data that already exists (products already link to artists, stock levels already tracked) — independent of every other story in this batch and lowest-effort of the reporting additions.

**Independent Test**: With products from multiple artists holding various stock levels, open the stock-per-artist report and confirm each artist's total and per-product stock breakdown matches the actual current stock levels.

**Acceptance Scenarios**:

1. **Given** products from multiple artists with recorded stock, **When** the user opens the stock-per-artist report, **Then** stock is grouped by artist, each showing a total and the per-product/variant breakdown beneath it.
2. **Given** the stock-per-artist report, **When** the user filters to a single artist, **Then** only that artist's breakdown is shown.
3. **Given** an artist with no active products or all-zero stock, **When** the report is viewed, **Then** that artist still appears with a zero/empty state rather than being silently omitted.

---

### Edge Cases

- What happens if a purchase order is marked Received twice, or Received is attempted out of order (e.g., a Draft order marked Received without ever being Ordered)? → Only the allowed forward transitions in the defined status sequence are permitted; an out-of-sequence or repeated transition is rejected with a clear message, mirroring how the existing pre-order status workflow already guards its own transitions.
- What happens if a purchase order line item's material is later deleted or deactivated? → The purchase order retains its own historical record of what was purchased (name, price, quantity) independent of the current state of the material record, the same "immutable snapshot" principle already applied to order line items elsewhere in this system.
- What happens when split-payment entries, summed, exceed the amount due using a non-cash method? → Rejected — only cash may produce change/overpayment, exactly as the existing single-payment checkout already enforces; a non-cash split entry must not push the total past the amount due.
- What happens to an open cashier session's per-artist opening cash if an artist is deactivated mid-session? → The recorded amount is preserved as history; deactivation does not retroactively remove or alter money already recorded as contributed.
- What happens when a user without Purchase-area access tries to view purchase orders or the purchases report? → Hidden entirely from their navigation and rejected server-side, consistent with every other role-gated area in this system.
- What happens to a POS draft if the cashier session it was created under gets closed before the draft is resumed? → The draft remains saved; resuming it under a newly opened session is allowed, since a draft carries no session-specific state of its own (no payment or stock has been recorded yet).

## Requirements *(mandatory)*

### Functional Requirements

**Purchase Orders**

- **FR-001**: The system MUST allow creating a purchase order against a vendor with one or more line items, each specifying a material and/or a described service, quantity, and unit price.
- **FR-002**: The system MUST support a purchase order status sequence of Draft → Ordered → Received → Paid, plus a Cancelled status reachable from any state prior to Received.
- **FR-003**: Marking a purchase order as Received MUST add each material line item's quantity to that material's available stock, through the same single sanctioned stock-mutation path already used elsewhere in the system, never a direct stock write.
- **FR-004**: The system MUST allow a purchase order line item to be linked to a specific product.
- **FR-005**: The system MUST allow generating a printable/downloadable invoice document for any purchase order, showing vendor, line items, quantities, prices, totals, and current status.
- **FR-006**: The system MUST support full create, read, update, and delete operations on purchase orders, with line items editable only while the order is in Draft status, and status/payment fields editable per the sequence in FR-002 regardless of line-item lock state.
- **FR-007**: Purchase order access MUST be gated by the same role tier that already governs Vendor/Material master data.

**Payments**

- **FR-008**: The system MUST allow a single transaction's total amount due to be covered by two or more payment entries, each with its own method and amount.
- **FR-009**: The system MUST prevent completing a transaction until its payment entries fully cover the amount due, and MUST prevent a non-cash entry (individually or in combination) from exceeding the amount still due.
- **FR-010**: The system MUST allow an optional free-text note to be attached to any individual payment entry, and MUST display that note wherever the payment is later reviewed.

**POS Drafts**

- **FR-011**: The system MUST allow a cashier to save an in-progress cart (items, quantities, discount, selected customer) as a draft without recording any payment or altering stock.
- **FR-012**: The system MUST allow a cashier to list, resume, and discard their saved drafts.
- **FR-013**: Resuming a draft MUST NOT affect stock availability beyond what completing the resulting transaction normally would — a saved draft itself must never reserve or deduct stock.

**Cashier Session**

- **FR-014**: The system MUST allow a cashier session's opening cash to be recorded as one or more per-artist amounts, in addition to the existing single opening-cash amount, without breaking sessions that don't use this breakdown.
- **FR-015**: Wherever a session's total opening cash is shown, it MUST equal the sum of its non-attributed amount plus all per-artist amounts.

**Settings**

- **FR-016**: The system MUST allow an owner/admin to set the app's primary accent color via a visual color picker, applied store-wide.
- **FR-017**: The system MUST allow an owner/admin to set custom receipt footer text and to reuse the existing store logo upload for display on receipts.

**Activity Log**

- **FR-018**: The system MUST provide a dedicated screen, restricted to the same role tier already enforced by the existing activity-log endpoint, listing recorded actions in reverse-chronological order with who/what/when, filterable by date range, action type, and acting user, and loaded incrementally rather than all at once.

**Reports**

- **FR-019**: The system MUST provide a purchases report summarizing purchase orders, filterable by date range, vendor, and status, with export consistent with the system's existing report-export convention.
- **FR-020**: The system MUST provide a stock-per-artist report showing current stock grouped by artist, with each artist's total and per-product/variant breakdown, filterable to a single artist, including artists with zero stock rather than omitting them.

### Key Entities

- **Purchase Order**: A record of a purchase from a vendor — vendor reference, one or more line items (material and/or described service, quantity, unit price, optional product link), status (Draft/Ordered/Received/Paid/Cancelled), and payment/receipt tracking. Supersedes the vendor/material screens' current reference-price-only scope by adding actual purchasing transactions on top of them.
- **Payment Entry**: One method+amount+optional-note record contributing toward a transaction's total due; a transaction (sale, pre-order payment, or purchase order payment) now composes of one or more of these rather than exactly one.
- **POS Draft**: A saved, resumable snapshot of an in-progress cart — line items, quantities, discount, selected customer — with no financial or stock effect until resumed and completed.
- **Session Opening Cash Entry**: A starting-cash amount attributed to a specific artist (or left non-attributed) within one cashier session, summing to that session's total opening cash.
- **Theme Setting**: A store-wide accent color preference, store-level (not per-user), defaulting to the system's existing accent color when unset.
- **Receipt Display Setting**: Store-level configuration of custom footer text and logo display for printed/shown receipts, on top of the store identity fields already configurable today.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can record a full purchase order — vendor, line items, and moving it from Draft through to Paid — in under 5 minutes.
- **SC-002**: 100% of purchase orders marked Received correctly increase their linked materials' stock by the ordered quantities, with zero discrepancy against the recorded stock-movement history.
- **SC-003**: A cashier can split a single transaction's payment across two methods and complete checkout in under 30 seconds beyond the time a single-method payment already takes.
- **SC-004**: A cashier can save a cart as a draft and resume it later with 100% of its original contents (items, quantities, discount, customer) intact.
- **SC-005**: An owner/admin can identify, from the stock-per-artist report alone, which artist currently holds the most/least inventory without cross-referencing any other screen.
- **SC-006**: A store's chosen accent color is reflected consistently across every screen that uses the primary accent token, with no screen left showing the old default after the change is saved.
- **SC-007**: An owner/admin can locate a specific past sensitive action (e.g., "who deleted this product") in the Activity Log screen in under 1 minute, without needing direct API access.

## Assumptions

- **Un-cutting a prior scope decision**: PRD §10.2 originally cut "purchase management (PO to vendors)" from MVP, and the 2026-09-01 Vendor/Material/BOM addition was explicitly scoped to exclude purchase orders. This feature deliberately reverses that specific cut at the product owner's explicit request (2026-09-03) — the same pattern already used once before for Vendor/Material/BOM itself. This is recorded here as the dated scope-change note; the PRD/README should receive the same dated note during implementation, consistent with this project's established convention (never silently rewriting prior scope history).
- Purchase order line items for a "service" (not a stockable material) are recorded as a described cost with no stock effect — they exist for cost/record-keeping only, not inventory tracking.
- The existing Vendor and Material master-data screens (list, CRUD, vendor prices) are retained largely as-is; Purchase Order is a new capability layered on top of them (Vendor/Material remain reference data; Purchase Order is the transactional record of actually buying from that reference data), not a replacement or removal of what exists today.
- Split payment and payment notes apply uniformly everywhere a payment is already recorded today: POS checkout, pre-order payments, and (per User Story 1) purchase order payments.
- "Opening cash per artist" is additive to the existing single opening-cash figure per confirmed direction — a cashier session remains one session per cashier/booth; only how its starting cash is itemized changes.
- Theme customization is limited to the app's primary accent color (the single token already used for buttons, active nav states, links) — not a full multi-token theme editor or per-screen customization, keeping this a lightweight preference rather than a design-system overhaul.
- Receipt display settings extend the existing receipt-related settings group (store name, contact, logo) rather than introducing a separate configuration surface.
- The Activity Log screen surfaces the existing activity-log data and access rule as-is; no new categories of actions are logged as part of this feature — only a frontend screen for what the backend already records.
- Purchases and stock-per-artist reports follow the same access tier, filter/export conventions, and DEMO/LIVE data-mode scoping already established by every existing report in this system.
