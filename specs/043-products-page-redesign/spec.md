**Classification**: INTERNAL

# Feature Specification: Products Page Redesign

**Feature Branch**: `043-products-page-redesign`

**Created**: 2026-10-11

**Status**: Draft

**Input**: User description: "Update product page `/products` to follow these screenshots, adjust the backend also." Nine screenshots of the Back Office design, menu "Produk": grid, table, item detail, mark as sold out (86), discontinue, bulk price change, variants, modifiers, recipe.

**Relationship to earlier work**: `041-back-office-ui-redesign` already restyled the current Products list with the shared page kit (presentation only). `042-dashboard-revamp` introduced a stored business date and a staged plan for outlets and devices (Stage B is blocked on the constitution amendment). This feature is the design's real Products module, not another restyle.

## Design reference (FACT — read from the nine screenshots)

1. **List page** (grid and table): title "Produk" with the line "Master item brand Warkop WJM · harga belum termasuk pajak · rilis menu v42 aktif di 3 outlet"; header actions *Impor / ekspor*, *Ubah harga massal*, *+ Tambah item*; a banner "3 perubahan belum dirilis" listing what changed with a *Rilis v43 ke outlet…* button; four figures — *Menu item aktif* (22 of 24 items · 1 draft · 1 discontinued), *86 hari ini* (6, with which outlet and item), *Belum dirilis* (3 changes, active release since a date), *Kualitas data* (2 items, e.g. "Tanpa station dapur: Tahu Crispy, Kentang Goreng"); item-type tabs with counts (*Menu item, Bahan baku, Setengah jadi, Kemasan, Item ritel, Modifier & combo*); search by name or SKU, *Kategori* and *Status* filters, "24 data", a *Grid / Tabel* switch and *+ Menu item*; 15 per page ("Menampilkan 1–15 dari 24").
2. **Grid card**: photo (placeholder "foto item"), status badge (*Aktif*), name, base price, a line of variants/modifiers ("Varian Panas / Es", "Gula, Ekstra shot", "Tanpa varian") and one availability chip per outlet (*WJM1 Ada*, *WJM2 86*).
3. **Table rows**: checkbox, item (thumbnail, name, "Kategori · Station"), SKU (e.g. `KOP-001`), variants · modifiers, base price, availability chips per outlet, status; sortable headers.
4. **Item detail page** (back link "← Kembali ke Produk"): "Menu item · KOP-001", name, badges (status, category); actions *Edit item*, *Tandai 86 di outlet…*, *Discontinue*; facts *Nama pendek, Kategori pajak, Station, Estimasi persiapan, Harga dasar, Rilis menu*; sections *Varian & harga* (per-variant SKU, base price, a price per outlet where grey = follows base, status, *Kelola varian*), *Grup modifier* (order, group, rule such as "Opsional · 0–1", options, *Kelola modifier*), *Ketersediaan per outlet* (status, daily quota, channels), *Resep (BOM) V3* ("Biaya porsi Rp 3.099 · food cost 38,7%", ingredients with quantity, unit, shrinkage and cost, theoretical portion cost, gross margin, *Edit resep*), and *Riwayat harga* (a timeline of base-price changes with who proposed and approved them and the menu release, ending in "Item dibuat").
5. **Mark as sold out (86)** dialog: "Tandai Kopi Tubruk sold out?", states that the status reaches every device of the outlet within 5 seconds and online channels within 60 seconds and lasts until the business day ends, a required *Reason code*, *Batal* / *Tandai 86*.
6. **Discontinue** dialog: the item can no longer be added to new orders but stays visible in history and reports; takes effect after the next menu release.
7. **Bulk price change** drawer: scope by *Kategori* (multi-select) and *Outlet* (all/base price or specific outlets); change as *Persentase* or *Nominal*, a value, *Berlaku mulai* date, "Bulatkan ke Rp 500 terdekat"; submitted as *Ajukan perubahan*.
8. **Variants & prices** drawer: a table (variant, SKU, base price, a price per outlet, active) with *+ Tambah varian*; rule text "Selisih > 15% dari harga dasar butuh approval Restaurant Manager"; *Berlaku mulai* ("kosong = rilis menu berikutnya"); *Simpan & masukkan ke rilis*. Guidance: a variant is for choices that change SKU, price or recipe (Hot/Iced); choices that only add composition use modifiers.
9. **Modifier** drawer: the groups linked to this item with *Wajib*, *Min*, *Maks* per link, *+ Tautkan grup*; group contents are managed in the "Modifier & combo" tab; required groups are asked by the POS before the item is sent to the kitchen.
10. **Recipe** drawer ("Resep (BOM) v4"): changes create a new version (old transactions keep v3; stock deduction uses the version active when the order closes), *Yield* (portions), *Berlaku mulai*, *Berlaku untuk varian*, ingredients per portion (material or semi-finished item, quantity, unit, shrinkage %, cost), *+ Tambah bahan*, portion cost, a change note, *Simpan sebagai v4*.

## Current state (FACT — from this repository, 2026-10-11)

- `/products` is a single table: code with thumbnail, SKU(s), name, seller/artist, category, pre-order and active pills; search plus seller and category filters; row actions Detail / Edit / Deactivate; header actions Export, Bulk Import, New product. There is **no grid, no item-type tabs, no status filter, no bulk selection, no detail page** (detail is a modal) and no figures strip.
- Product data: name, code prefix, segment, description, image, seller (required), category (required), optional station, pre-order flag/ETA, active flag, soft delete. Variants: name, SKU, cost price, sell price, stock, low-stock alert, track-stock, active, image, BOM-complete flag.
- **Not in the data today:** short name, tax category, preparation time, draft / discontinued / sold-out (86) states, daily quota, per-outlet availability or price, menu releases and versions, price history table (price edits only leave an audit-log row), approvals, reason codes, bulk price endpoint ("Apply markup" is a client-side calculator inside the edit drawer), recipe yield, shrinkage, recipe versions, material kinds (ingredient / semi-finished / packaging / retail).
- **Exists:** modifier groups with options and min/max/required on the *group* (028; managed on the Dining setup screen), group links to products and categories, kitchen stations on products and categories, materials with a unit and vendor prices, a per-variant BOM built from purchase-order lines with whole-number quantities, a "complete" action that syncs cost price, a cost-breakdown endpoint, an activity log, Excel import/export of master data.
- No outlet concept exists yet (see 042 Stage B); the outlet selector is a hidden stub.

## Clarifications (resolved with the product owner)

- **Q1 — outlets, menu releases, approvals: C.** Build them in this feature: outlets with per-outlet availability and prices, a menu release engine (versions, unreleased changes, publish to outlets) and an approval path (a price difference above the threshold needs a second person). **Consequence (FACT from 042):** outlets are the 042 Stage B foundation and depend on the constitution amendment in `specs/042-dashboard-revamp/constitution-amendment.md`. This feature therefore **reuses** that foundation (outlets, user-to-outlet assignment, outlet scope, selector) instead of building a second one, and **no outlet, release or approval work starts until the amendment is ratified** (042 task T001). Parts that need only one outlet (the list, detail page, scheduled price changes, price history, 86, discontinue, variants, modifier links, recipes) can be built first on the single default outlet and then gain their per-outlet behaviour.
- **Q2 — item-type tabs: B.** All six tabs are real: materials get a kind (ingredient, semi-finished, packaging) and items get a retail flag, so *Bahan baku, Setengah jadi, Kemasan, Item ritel* and *Modifier & combo* each show their own records with counts. Semi-finished items can be used as an ingredient in a recipe; giving a semi-finished item its own recipe/yield is **not** part of this feature.
- **Q3 — recipe model: B.** Add a new versioned recipe as the design shows (yield, ingredients with quantity, unit and shrinkage %, applicable variants, effective date, change note). The existing purchase-order-based BOM stays beside it, unchanged, for items that use it; an item uses one or the other for its cost at a time.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Browse the menu as a grid or a table with the figures that matter (Priority: P1)

A manager opens Produk and sees the header figures (active items, sold out today, unreleased changes, data quality), the item-type tabs with counts, search and filters, and the menu as a grid of cards or a table, with availability and status visible at a glance and paging.

**Why this priority**: It is the landing view of the module and the part every other action starts from.

**Independent Test**: With items in several categories and statuses, check each figure against the list, switch grid/table keeping filters, search by name and SKU, filter by category and status, and page through.

**Acceptance Scenarios**:

1. **Given** items in various statuses, **When** the page loads, **Then** "Menu item aktif" equals the number of active items, its sub-line states the total, drafts and discontinued items, and every count equals the rows it stands for.
2. **Given** a search term, category and status filter, **When** applied, **Then** the list narrows, the "N data" line equals the rows matching, and the choice survives a reload and a switch between Grid and Tabel.
3. **Given** the grid, **When** it renders, **Then** each card shows photo (or the placeholder), status badge, name, base price, a variants/modifiers line and availability; clicking a card opens the item detail page.
4. **Given** the table, **When** rows are selected, **Then** a selection bar offers the bulk actions the user's role may use; headers sort by item, SKU, price, status.
5. **Given** an item lacking data the design flags (e.g. no kitchen station), **When** the page loads, **Then** "Kualitas data" counts it, names examples, and filters the list to those items when clicked.
6. **Given** 24 items and 15 per page, **When** the user pages, **Then** "Menampilkan 1–15 dari 24" and the pager are correct, and an empty result shows an empty state with a way to clear filters.

---

### User Story 2 - One page for everything about an item (Priority: P1)

A manager opens an item and sees, on a dedicated page, its identity and status, its facts, variants and prices, modifier groups, availability, recipe with cost and margin, and a price history timeline, with an action for each section.

**Why this priority**: The design moves editing from a modal to a page of sections; all other stories hang off it.

**Independent Test**: Open an item with variants, modifiers and a recipe; compare each section with the data, follow each action, return with the back link, and reload the address.

**Acceptance Scenarios**:

1. **Given** an item, **When** its address is opened (also directly or after reload), **Then** the page shows code, name, badges, facts, and each section, and "← Kembali ke Produk" returns to the list with the previous filters.
2. **Given** a recipe exists, **When** the page loads, **Then** portion cost, food cost % (cost ÷ base price) and gross margin (base price − cost) are shown and equal the recipe's lines and the price.
3. **Given** price edits over time, **When** the page loads, **Then** the timeline lists each base-price change with date, old and new value and who made it, newest first, ending with the creation entry.
4. **Given** a user without permission to edit, **When** the page loads, **Then** the actions are hidden, not disabled.

---

### User Story 3 - Mark an item sold out (86) and bring it back (Priority: P2)

A manager marks an item as sold out with a required reason; it stops being orderable and is visibly "86" on the list and detail until it is brought back or the business day ends.

**Why this priority**: Daily operational need, visible in two header figures.

**Independent Test**: Mark an item 86 with a reason; check it cannot be added to a new order, shows as 86 everywhere, is counted in "86 hari ini", and returns to available on undo or after the business day ends.

**Acceptance Scenarios**:

1. **Given** an available item, **When** the manager confirms the 86 dialog with a reason, **Then** the item shows 86 with the reason and time and cannot be added to new orders.
2. **Given** no reason, **When** confirming, **Then** the action is refused with the reason field flagged.
3. **Given** an item marked 86, **When** the business day ends, **Then** it is available again automatically; **When** the manager undoes it earlier, **Then** it is available at once.
4. **Given** an item already in an open order, **When** it is marked 86, **Then** the existing order is not altered.
5. **Given** every 86 and undo, **When** it happens, **Then** it is recorded in the activity log with who, when and why.

---

### User Story 4 - Discontinue an item (Priority: P2)

A manager discontinues an item so it can no longer be ordered, while history and reports keep it.

**Why this priority**: Retiring items safely is required to keep the menu clean; today the only option is a destructive-looking "Deactivate".

**Independent Test**: Discontinue an item; check it cannot be ordered, remains in past orders and reports, is counted as discontinued, and can be reactivated.

**Acceptance Scenarios**:

1. **Given** an item, **When** the manager confirms Discontinue, **Then** its status is *Discontinued*, it is excluded from the active count, and new orders cannot include it.
2. **Given** past sales of that item, **When** reports and history are opened, **Then** they still show it unchanged.
3. **Given** a discontinued item, **When** the manager reactivates it, **Then** it becomes orderable again and the change is recorded.
4. **Given** the dialog, **When** it opens, **Then** it states the effect in plain words and that it takes effect with the next menu release.

---

### User Story 5 - Change prices in bulk (Priority: P2)

A manager selects categories (and outlets where applicable), chooses a percentage or an amount, a value, an effective date and rounding, and submits the change; it is applied to every matching item's price.

**Why this priority**: Price rounds are frequent; today prices are edited one variant at a time.

**Independent Test**: Apply +5% to one category with rounding to Rp 500 and a future date; check the preview/affected items, that only that category changes, that the new price takes effect on the date, and that the price history shows it.

**Acceptance Scenarios**:

1. **Given** a scope, type and value, **When** the manager reviews, **Then** the affected items and each old → new price are shown before submission, with the rounding applied.
2. **Given** a future date, **When** it arrives, **Then** the new prices apply and earlier orders keep their recorded prices.
3. **Given** a change beyond the approval threshold, **When** submitted, **Then** it waits for approval by a second person and is not released until approved.
4. **Given** invalid input (no scope, empty value, a date in the past, a result below zero), **When** submitting, **Then** it is refused with the field named and nothing changes.
5. **Given** a submitted change, **When** it applies, **Then** one history entry per affected variant is written.

---

### User Story 6 - Manage variants and prices (Priority: P2)

From the item page a manager edits the item's variants — name, SKU, base price, per-outlet price where applicable, active — and sets when the change applies.

**Why this priority**: Core of the item; variant editing currently lives in a long drawer.

**Independent Test**: Add a variant, change a price with an effective date, deactivate a variant, and check validation, uniqueness of SKU and the history.

**Acceptance Scenarios**:

1. **Given** the drawer, **When** a variant is added, **Then** it needs a name and a price, gets a unique SKU, and appears on the item and the list.
2. **Given** an effective date left empty, **When** saved, **Then** the change is added to the next menu release.
3. **Given** a price difference above the approval threshold, **When** saved, **Then** it needs approval before release.
4. **Given** a variant with sales history, **When** the manager deactivates it, **Then** it can no longer be ordered but history is kept; it is never deleted.

---

### User Story 7 - Link modifier groups with per-item rules (Priority: P3)

From the item page a manager links modifier groups, marking each *Wajib* with a minimum and maximum for this item, and orders them.

**Why this priority**: Modifiers exist, but their rules are per group today; the design makes them per link.

**Independent Test**: Link a group as required with min 1 / max 2 on one item and optional on another; check the POS asks accordingly and group contents are untouched.

**Acceptance Scenarios**:

1. **Given** a link marked required, **When** the item is ordered, **Then** the POS asks for the group before the item can be sent to the kitchen.
2. **Given** min/max on the link, **When** saved, **Then** they must be valid (0 ≤ min ≤ max, max ≥ 1 or unlimited) and apply to this item only.
3. **Given** the group's contents, **When** edited elsewhere, **Then** every linked item shows the update; contents are not editable from the item drawer.
4. **Given** the order of rows, **When** saved, **Then** the POS shows groups in that order.

---

### User Story 8 - Maintain the recipe and see cost and margin (Priority: P3)

A manager edits a recipe — yield, ingredients with quantity, unit and shrinkage, the variants it applies to, an effective date and a note — saves it as a new version, and sees portion cost, food cost % and gross margin.

**Why this priority**: Gives the menu its cost picture; depends on the recipe decision in Q3.

**Independent Test**: Save a recipe, then change an ingredient; check a new version exists, the old one is kept with its dates, costs follow the formulas, and an order closed before the change keeps its recorded cost.

**Acceptance Scenarios**:

1. **Given** ingredients with quantities, shrinkage and unit costs, **When** saved, **Then** the cost of each line and the portion cost are computed as quantity ÷ (1 − shrinkage) × unit cost, divided by yield, and shown.
2. **Given** an existing recipe, **When** it is edited and saved, **Then** a new version is created from its effective date and the previous version is kept; orders closed earlier keep the cost recorded at the time.
3. **Given** a recipe applying to some variants only, **When** saved, **Then** only those variants use it.
4. **Given** invalid input (no ingredients, quantity ≤ 0, shrinkage outside 0–99%, yield ≤ 0), **When** saving, **Then** it is refused with the field named.
5. **Given** a change note, **When** saved, **Then** it appears in the version history with who and when.

---

### User Story 9 - Per-outlet availability and prices (Priority: P2, needs outlets)

With more than one outlet, availability (including 86), daily quota and prices can differ per outlet; the list, the detail page and the dialogs show and edit them per outlet, and a price left empty follows the base price.

**Why this priority**: The design's chips and price columns are per outlet; it is meaningful only once outlets exist.

**Independent Test**: With three outlets, set a different price and an 86 mark in one outlet; check the others are unaffected, the chips and the price table show it, a user limited to one outlet cannot see or change another's, and an empty price follows the base.

**Acceptance Scenarios**:

1. **Given** several outlets, **When** an item is marked 86 in one outlet, **Then** only that outlet shows 86 and only its orders are blocked.
2. **Given** a per-outlet price, **When** it is set, **Then** it applies in that outlet only, an empty one follows the base price (shown grey), and recorded orders keep their prices.
3. **Given** a user assigned to one outlet, **When** they open the page or call the server, **Then** they see and can change only that outlet's availability and prices.
4. **Given** one outlet only, **When** the page renders, **Then** no per-outlet columns or chips appear and nothing looks broken.

---

### User Story 10 - Release menu changes to outlets, with approval where needed (Priority: P2, needs outlets)

Changes to items, prices, modifier links and recipes are collected as unreleased changes; a manager reviews them, releases a new menu version to chosen outlets (now or scheduled), and changes that exceed the approval rule wait for a second person.

**Why this priority**: It is the banner and the "Rilis menu" fact on every page of the design, and the reason variants/prices say "Simpan & masukkan ke rilis".

**Independent Test**: Make three changes, see the banner list them, release to two outlets, check version numbers and that only those outlets see the change, make a change above the threshold and check it cannot be released until approved by someone other than the requester.

**Acceptance Scenarios**:

1. **Given** unreleased changes, **When** the page loads, **Then** the banner shows how many and what, and the "Belum dirilis" figure matches.
2. **Given** the release dialog, **When** the manager chooses outlets and a time (now or later) and confirms, **Then** a new version is created, the chosen outlets use it from that time, and the others keep their version.
3. **Given** a price difference above the threshold, **When** the change is saved, **Then** it is marked "needs approval", cannot be included in a release, and can be approved or rejected by a permitted person who is not the requester.
4. **Given** a requester who is also the only approver, **When** they try to approve their own change, **Then** it is refused.
5. **Given** a release, **When** it is opened later, **Then** it lists exactly what it contained, who requested, approved and released it, and when.
6. **Given** a published release, **When** a device or screen of a released outlet asks for the menu, **Then** it receives that version; a failure to reach some outlets is shown per outlet and can be retried.

---

### Edge Cases

- An item with no variants ("Tanpa varian") is shown with its base price and one implicit variant; an item with one price per outlet different from base shows the difference in its detail.
- Two items with the same name in different categories; names and SKUs with accents; very long names truncated with the full name available.
- An item that is 86 and discontinued at once: discontinued wins in the status; the 86 mark is cleared.
- A bulk price change whose rounding makes two variants equal, or pushes a price to zero: refused with the variant named.
- A category with no items selected in a bulk change: the preview says nothing matches and submission is disabled.
- DEMO versus LIVE data never mix in any figure, list or detail.
- Language switch (Indonesian/English): every new label exists in both.
- A failed load of one section of the detail page shows an error with Retry for that section only.
- Roles: a role that may view but not manage sees no edit, 86, discontinue, bulk-price or recipe controls; the server refuses the same actions.

## Requirements *(mandatory)*

### Functional Requirements

**List page**

- **FR-001**: The page MUST show the title and descriptive line, the header actions (import/export, bulk price change, add item) and the four figures: active items (with total, draft and discontinued counts), sold out today (with where and which), unreleased changes (see Q1), and data quality (with example items).
- **FR-002**: The page MUST show item-type tabs with live counts; the tabs are *Menu item, Bahan baku, Setengah jadi, Kemasan, Item ritel* and *Modifier & combo*, each listing its own records (Q2 = B).
- **FR-003**: The user MUST be able to search by name and SKU, filter by category and status, switch between Grid and Table, sort the table by item, SKU, price and status, select rows, and page (15 per page); filters, view mode, sort and page MUST survive reload through the address.
- **FR-004**: Each list entry MUST show status, base price, a variants/modifiers summary and availability; every count and total MUST equal the entries it describes.
- **FR-005**: The data-quality figure MUST list the items missing something the design flags (at least: no kitchen station; no category; no price; no recipe where a recipe is expected) and filter the list when clicked.
- **FR-006**: Existing capabilities MUST remain reachable: Excel import/export, creating an item, editing, the seller/artist and pre-order attributes where the install uses them, and stock adjustment; nothing reachable today may disappear without being named in the report.

**Detail page**

- **FR-007**: Each item MUST have its own page at its own address, showing identity, status, facts (short name, tax category, kitchen station, preparation estimate, base price), variants and prices, modifier groups, availability, recipe with cost and margin, and a price-history timeline; sections load independently with their own error state.
- **FR-008**: The facts the data does not hold today (short name, tax category, preparation time) MUST become editable fields on the item and shown on the page.
- **FR-009**: The price history MUST record every change of a variant's base price with date, old and new value, who changed it and why/how (single edit, bulk change, import), and MUST be readable without opening the audit log.

**Sold out, discontinue**

- **FR-010**: Marking an item sold out (86) MUST require a reason from a defined list, MUST make the item unorderable for new orders, MUST end automatically at the end of the business day, and MUST be undoable; each action MUST be logged.
- **FR-011**: The "86 hari ini" figure and the availability chips MUST come from the same state; an order already containing the item MUST NOT be changed.
- **FR-012**: Discontinue MUST make an item unorderable, keep it in all history and reports, exclude it from the active count, be reversible, and be logged; it takes effect with the next menu release that includes it (Q1 = C).

**Bulk price change**

- **FR-013**: The user MUST be able to scope a bulk price change by category (and outlet where outlets exist), choose percentage or amount, enter a value and an effective date, and choose rounding to the nearest Rp 500 (or none).
- **FR-014**: Before submitting, the user MUST see the affected items and each old → new price; the server MUST recompute everything (client-supplied prices are never trusted), refuse invalid input, and apply changes all-or-nothing.
- **FR-015**: A bulk change with a future date MUST apply on that date without altering recorded past orders; each affected variant MUST get a price-history entry; a change whose price difference exceeds the approval threshold MUST be approved by a second person before it can be released (FR-026).

**Variants, modifiers, recipe**

- **FR-016**: The variants drawer MUST edit name, SKU (unique), base price, active flag and effective date; deactivating replaces deleting for any variant with history.
- **FR-017**: A modifier link MUST carry required, minimum, maximum and display order **per item**; groups and their options remain managed in one place; the POS MUST follow the link rules.
- **FR-018**: A recipe MUST have a yield, ingredients with quantity, unit and shrinkage, the variants it applies to, an effective date and a change note, and MUST be versioned: a change creates a new version, earlier versions are kept, recorded order costs never change; the existing purchase-order-based BOM is kept unchanged beside it, and an item uses one cost source at a time (Q3 = B).
- **FR-019**: Portion cost, food cost % and gross margin MUST be computed on the server by one formula and shown on the detail page, the recipe drawer and (as food cost %) the list.

**Cross-cutting**

- **FR-020**: Every figure, list and action MUST apply the active DEMO/LIVE mode and the user's menu permissions on the server; controls the role cannot use MUST be hidden, not disabled.
- **FR-021**: The page MUST use the shared page kit and tokens (page header, figure strip, tabs, filter bar, bulk bar, drawer, reason dialog, loading/error/empty states); new reusable pieces MUST join the kit.
- **FR-022**: All new text MUST exist in Indonesian and English; interactive elements MUST be keyboard reachable with visible focus; status MUST NOT be conveyed by colour alone.
- **FR-023**: The list MUST NOT make one request per card or row; first paint of the list MUST meet the success criteria on a store laptop.

**Outlets, releases, approvals, item types (Q1 = C, Q2 = B)**

- **FR-024**: Availability, daily quota and price MUST be settable per outlet, with an empty outlet price following the base price; the list, the detail page and the dialogs MUST show them per outlet when more than one outlet exists and MUST hide per-outlet controls with one outlet. This reuses the outlet foundation, scope and selector of 042 Stage B.
- **FR-025**: Changes to items, variants, prices, modifier links, recipes, discontinue and availability defaults MUST be collected as unreleased changes; the page MUST show their number and a summary; a release MUST publish a numbered menu version to the chosen outlets now or at a scheduled time, and MUST record what it contained, who requested, approved and released it.
- **FR-026**: A price difference above a configurable threshold (default 15% from the base price, as in the design) MUST require approval by a permitted person who is not the requester; the requester MUST never approve their own change; approved, rejected and pending changes MUST be visible with who and when.
- **FR-027**: A sold-out mark (86) MUST take effect for the outlet's ordering screens within 5 seconds of being set and MUST NOT wait for a release; online sales channels are updated only if an integration exists (none does today) and the dialog MUST state only what the system actually does.
- **FR-028**: Materials MUST have a kind (ingredient, semi-finished, packaging) and items MUST have a retail flag so each type tab lists its own records with accurate counts; semi-finished materials MUST be selectable as ingredients in a recipe.
- **FR-029**: The reason for an 86 mark, a discontinue and an approval decision MUST come from administrator-editable reason lists with an optional note, shared with the existing reason dialog.
- **FR-030**: Everything in FR-024 to FR-029 MUST be enforced on the server by outlet scope and permission; a request for another outlet, an unauthorised release or a self-approval MUST be refused and change nothing.

### Key Entities

- **Menu Item**: the sellable product shown as a card or row — name, short name, code, category, kitchen station, tax category, preparation estimate, image, status (draft, active, discontinued) and its variants.
- **Variant**: a choice that changes SKU, price or recipe — name, SKU, base price, active, price history.
- **Sold-out Mark (86)**: item, reason, who, when, expiry at end of business day, undone-at.
- **Price Change**: variant, old and new base price, effective date, source (edit, bulk, import), who, status.
- **Price Change Batch** (bulk): scope, type, value, rounding, effective date, resulting changes.
- **Modifier Link**: item ↔ modifier group with required, min, max, order.
- **Recipe Version**: item, applicable variants, yield, ingredient lines (material, quantity, unit, shrinkage), effective date, note, version number.
- **Item Type**: the class shown as a tab (menu item, raw material, semi-finished, packaging, retail item, modifier & combo).
- **Outlet Item Setting**: item or variant ↔ outlet — availability, 86 mark, daily quota, price override.
- **Menu Version (Release)**: a numbered snapshot of the menu published to chosen outlets, with its contents, requester, approver, releaser, scheduled and published times, and per-outlet delivery state.
- **Unreleased Change**: a pending change (item, price, modifier link, recipe, status) waiting to be included in a release.
- **Approval**: a request tied to a change — requester, approver, decision, reason, amount of difference — never decided by its requester.
- **Reason**: an administrator-defined reason (category, label) used by 86, discontinue and approvals.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tested cases each figure and count on the list equals the entries it describes, with and without filters.
- **SC-002**: A manager can find an item by name or SKU and open its page in under 10 seconds with 500 items in the catalogue, and the list renders its first page within 2 seconds on the baseline store laptop.
- **SC-003**: Marking an item sold out takes at most 3 interactions after opening it, and the item is unorderable on the very next order attempt (0 orders accepted after the mark).
- **SC-004**: A bulk change over 100 variants shows the preview within 3 seconds, applies all-or-nothing, and 100% of affected variants get exactly one history entry; recorded past orders change in 0 cases.
- **SC-005**: Portion cost, food cost % and margin equal an independent hand calculation for every tested recipe, including shrinkage and multi-portion yield.
- **SC-006**: No role sees or can execute an action (86, discontinue, bulk price, recipe edit) it could not already perform; refused requests change nothing.
- **SC-007**: The production main script bundle does not grow by more than 10 kB over the measured baseline of this branch's start; Products code ships in route chunks.
- **SC-008**: No console errors on the list and detail pages in any role, in either language, with data and without data.

## Assumptions

- **Restaurant vocabulary, existing booth attributes kept.** The design speaks of categories, stations and outlets; the product also holds seller/artist and pre-order attributes from the booth variant. They stay in the edit form and in filters for installs that use them, and are not shown in the grid cards or the main table columns. *(ASSUMPTION.)*
- **Statuses.** Draft, Active, Discontinued are item states; "86" is a separate, temporary availability mark, not a status. The existing active/inactive flag maps to Active/Discontinued. *(ASSUMPTION.)*
- **Reason codes** for 86 start as a small administrator-editable list (e.g. out of stock, equipment broken, quality issue, other) with a note field; the full reason-code catalogue of the design arrives with its own feature. *(ASSUMPTION.)*
- **Prices shown** are base prices excluding tax, as the page subtitle says; tax category is stored and displayed but tax calculation is out of scope.
- **Daily quota and channels** (Dine-in, Takeaway, GoFood) shown in "Ketersediaan per outlet" are displayed only if data for them exists; they are not built here. *(ASSUMPTION.)*
- **Single-machine installs** keep working with no new controls that make no sense for one outlet: per-outlet columns, the outlet selector, release targets and approval controls appear only when more than one outlet exists or the install enables releases.
- **Not in scope**: orders, purchasing, stock counts, the notification bell, global search, integrations with online ordering channels (GoFood etc.), semi-finished items with their own recipes, tax calculation, and approvals for anything other than menu changes.
- **Dependencies**: 041 page kit; 042 business date; existing products, variants, modifier groups, stations, materials, BOM, activity log and import/export; **for Stories 9–10 and every per-outlet behaviour: ratification of the constitution amendment and the 042 Stage B outlet foundation** (outlets, user assignment, outlet scope, selector). Release delivery to devices assumes terminals fetch the menu from the server; the only terminal today is the web POS, which polls.
- **Rough size (ASSUMPTION)**: this is the largest feature so far — roughly three times 042. The plan will split it into stages so single-outlet value ships first.
