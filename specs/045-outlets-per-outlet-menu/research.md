**Classification**: INTERNAL

# Research & Decisions — 045 Outlets

All spec questions are answered; this file records the design choices and what was rejected. FACT = read from the repository (2026-10-11); ASSUMPTION = labelled.

## D1 — Rename in place: the `artists` table becomes `outlets`
**FACT**: `artists` (code char(3) unique, name, phone, e-mail, notes, is_active, soft deletes) is referenced by FK from `products`, `order_items`, `preorder_items`, `artist_settlements`, `session_opening_cash_entries`, `purchase_orders`; `Artist` is a `HasDataMode` model; ~45 front-end and ~60 back-end files mention it.
**Decision**: `Schema::rename('artists','outlets')` (MySQL keeps FKs pointing at the renamed table), model `Artist`→`Outlet`, `ArtistPolicy`→`OutletPolicy`, controller/requests/resource renamed. Same ids, codes and history.
**Rationale**: owner decision (sellers *are* outlets); no data copy, no identity drift.
**Rejected**: new `outlets` table + copy/mapping (two identities, broken references); DB view alias `artists` (hides the new meaning, MySQL views complicate FKs).

## D2 — Two meanings of the old `artist_id`: *home outlet* vs *selling outlet*
**FACT**: `products.artist_id` = owner; `order_items.artist_id` = the item's owner at sale time (snapshot); `preorder_items.artist_id` likewise; `purchase_orders.artist_id` = the PO's seller (BOM rule compares it with the variant's product owner); `session_opening_cash_entries.artist_id`; `artist_settlements.artist_id`.
**Decision**: rename by **meaning**, not mechanically:
- `products.artist_id` → `products.home_outlet_id`; `order_items.artist_id` / `preorder_items.artist_id` → `home_outlet_id` (snapshot of the item's home outlet — stays immutable);
- `purchase_orders.artist_id` → `outlet_id` (the buying outlet; BOM rule compares `purchase_orders.outlet_id` with `products.home_outlet_id`, unchanged logic, new names);
- `session_opening_cash_entries.artist_id` → `outlet_id` (legacy rows keep their value);
- `artist_settlements` → `outlet_settlements` (`artist_id`→`outlet_id`).
New **selling** outlet is a *new* column `outlet_id` on `orders`, `cashier_sessions` etc. (D7), and the report attribution is a function of both (D9). Keeping two distinct names prevents the classic bug of reading the owner where the selling outlet was meant.
**Rejected**: keeping `artist_id` columns under the old name (violates SC-001 for code readers and invites confusion); one `outlet_id` for both meanings (cannot express Q2 = B).

## D3 — API surface renames (single release, no alias)
**FACT**: routes `apiResource('artists')`, `/reports/{stock-by-artist,artist-profit,artist-settlements,artist-settlements/{artist}/transactions}`, `/exports|/imports` entity names (`artists`), query params `artist_id[]` (products, stock, sales filters), response fields `artist_id/artist_name/artist_names/artist_code`.
**Decision**: `/outlets`, `/reports/stock-by-outlet`, `/reports/outlet-profit`, `/reports/outlet-settlements[/{outlet}/transactions]`, entity `outlets`, params `outlet_id[]` / `home_outlet_id[]` (by meaning: filter by *home* outlet on catalogue pages, by *selling* outlet on sales/reports), fields renamed accordingly (full table in contracts/api-outlets-rename.md). The old routes are **removed**; the SPA and API ship together on one machine. OpenAPI changes in the same commit.
**Rejected**: dual routes (two write paths to document/test); a generic `/sellers` alias (the owner asked for the rename).

## D4 — Menu key, setting key and Excel names with compatibility
- Menu key `artists` → `outlets`: a migration rewrites every `roles.menu_keys` JSON containing `artists` (and the role factory/seed defaults); `MenuKeys` constants updated; route meta `menuKey`.
- Setting `multi_artist_enabled` → `multi_outlet_enabled`: migration copies the value and `LicenseGate` reads the new key (falls back to the old key for one release); Pro = one outlet, Master = several.
- Excel workbook: sheet `outlets` (columns `code,name,contact_phone,contact_email,notes,is_active`), products sheet column `home_outlet_code`; **import accepts the old names** (`artists`, `artist_code`) via an alias table in `MasterDataSheets` (one place); export/template use new names. New optional sheets: `outlet_items` (product/sku, outlet_code, carried), `outlet_prices` (sku, outlet_code, price), and `outlet_code` on the `stock` sheet.

## D5 — Outlet scoping = the 041 design, now concrete
**FACT**: `HasDataMode` + `DataModeScope` is the proven pattern; its known trap is raw `DB::table()` queries (reports) bypassing the scope; 041 Decision 4 already designed `BelongsToOutlet`/`OutletScope`/`OutletContext`/`OutletFilter`.
**Decision**: adopt it unchanged in spirit:
- `OutletContext` (request-scoped singleton): `permitted` = the user's assigned outlets (administrators: all active), `selected` = `X-Outlet-Id` header narrowed to the permitted set (absent ⇒ all permitted; an id outside the permitted set ⇒ **403**), `effective()` = ids to filter by, `single()` = the one outlet when exactly one is effective.
- `OutletScope` (global scope) on every operational model; `BelongsToOutlet` stamps `outlet_id` on create **from context/owner object, never from input**.
- `OutletFilter::apply($query, $column)` for raw queries and a test that enumerates every outlet-scoped table and asserts every raw report/figure query in `ReportController`, `SalesFigures`, `SalesTransactionsService`, `DashboardService`, `AttentionService`, `FloorQuery`, `LowStockHistory` uses it.
**Which tables carry `outlet_id`** (D7 lists stamping): `cashier_sessions`, `orders`, `payments`, `kitchen_tickets`, `dining_tables`, `stations`, `preorders`, `purchase_orders` (already), `stock_movements`, `material_stock_movements`, `activity_logs` (nullable, informational), `stock_transfers` (from/to). **Not scoped**: users, roles, settings, products/categories/modifiers/vendors/materials (organisation-wide masters; their per-outlet state lives in the relation tables).
**Rejected**: per-outlet databases (blocks organisation-wide reporting); scoping only in controllers (the 3-mechanism authorisation already shows how that drifts).

## D6 — Assignments, selection and `GET /me/scope`
`outlet_user(user_id, outlet_id)` pivot. Owners/admins are implicitly assigned to **all active outlets** (no rows needed; a role flag `all_outlets` on the role, true for owner/admin). Migration assigns every existing non-admin user to all current outlets (nothing disappears), the Users screen then narrows. `GET /me/scope` returns `{outlets:[{id,code,name}], selected, can_see_all, multi}`; the SPA `stores/outlet.js` persists the selection per user (localStorage keyed by user id) and `api/client.js` sends `X-Outlet-Id`. `multi` (more than one **visible** outlet) drives every "hidden at one outlet" rule.

## D7 — Who stamps the outlet, and from where
| Record | Outlet comes from |
|---|---|
| Cashier shift | request body `outlet_id` **validated** against the permitted set; omitted ⇒ the single assigned outlet; none/ambiguous ⇒ 422 with a clear message; a non-admin with no assignment ⇒ 409 |
| Order, tab, payment, kitchen ticket, void/return | the **shift's** outlet (copied at creation, immutable) |
| Dining table, station | chosen at creation among permitted outlets (default selected/only); name unique **per outlet** |
| Pre-order | the creating user's effective single outlet (must be exactly one: selected or the only assigned) |
| Purchase order | like pre-order (the buying outlet) |
| Stock adjustment / stock-take / import | explicit outlet among the permitted set |
| Stock transfer | from/to chosen; from ∈ permitted for dispatch, to ∈ permitted for receive |
A body-supplied `outlet_id` that is not in the permitted set is refused; one that is permitted but conflicts with the shift is ignored (the shift wins).

## D8 — Orders get the selling outlet; legacy orders are flagged, not guessed
`orders.outlet_id` (non-null after backfill) and `orders.outlet_backfilled` (boolean, true only for rows that existed before the migration). Backfill assigns legacy orders/shifts/tables/stations/tickets to the **first outlet** so scope and visibility work; the flag keeps their *report* attribution on the former owner (D9). Open shifts/tabs at migration time belong to the first outlet.
**Rejected**: inferring the selling outlet from history (no reliable signal); leaving `outlet_id` null (breaks scope and unique-per-outlet rules).

## D9 — Report attribution (Q2 = B) in ONE SQL helper
`OutletFilter::reportOutletSql()` = `CASE WHEN orders.outlet_backfilled THEN order_items.home_outlet_id ELSE orders.outlet_id END`. Every report that grouped by `order_items.artist_id` (recap, cost/profit, settlements, stock-by-outlet's sales part, pre-order by outlet, dashboard "results per seller") uses this expression and the same filter. Rows built only from legacy orders are labelled *historical*; a period spanning both sums both without double counting (a single GROUP BY over the expression). `SettlementService::recalculateForEvent()` and the stored `outlet_settlements` snapshot use it too, so event close numbers equal the screen.
Pre-order revenue keeps its existing recognition rule; its outlet = `preorders.outlet_id` (legacy: flagged the same way).

## D10 — Per-outlet menu data: materialised relation, sparse prices
- `outlet_item_settings(id, outlet_id, product_id, is_carried, timestamps)` **materialised** — one row per (outlet, product) — maintained by `OutletMenuService` when a product or an outlet is created (default carried = true: "new outlet starts with every active item", spec edge case) and by migration for existing data. Materialised, not implicit, so "no row" can never silently mean carried or not carried and joins stay simple. Unique (outlet, product).
- `variant_outlet_prices(variant_id, outlet_id, price)` **sparse** (only overrides; absent = follows base). Unique (variant, outlet).
- `item_availability_marks` gains `outlet_id` (migration expands each existing global mark into one row per outlet, preserving reason/expiry); `price_history` gains nullable `outlet_id` (null = base price); `menu_changes` payload carries `outlet_id` for outlet-price changes (releases themselves stay global — per-outlet releases are out of scope).
- Home outlet stays `products.home_outlet_id` (required).
**Rejected**: implicit rows (default flips for new outlets/products); JSON columns on products (unqueryable, no FK).

## D11 — One resolver each for price and orderability
`PriceService::priceFor(ProductVariant, int $outletId): string` (outlet override else base) and `MenuAvailability::orderable(Product, ?ProductVariant, int $outletId): Result` (active product, carried at the outlet, no mark for the outlet, stock rule). `OrderService`, `TabService`, `TabLineController`, the POS list (`GET /products?with_variants=1` resolves price/availability for the context's single outlet), the 86 poll (`GET /menu/availability` scoped to the terminal's outlet) and the cashier cards call only these. The recorded line price remains a snapshot; `PriceService` stays the **only writer** of base *and* outlet prices (+ history).

## D12 — Stock per outlet: balances table + cached total, one writer
**FACT**: `product_variants.current_stock` + append-only `stock_movements` (qty_change, stock_before/after, type enum of 6, reference, reason, user) written **only** by `StockService::applyMovement` (≈10 call sites: `OrderService` ×2, `TabService` ×3, `PreorderService` ×3, `StockController`, `MasterDataImportService` ×2); materials: `materials.current_stock` + `material_stock_movements` via `MaterialStockService` (called by `PurchaseOrderService`).
**Decision**: `variant_outlet_stock(variant_id, outlet_id, current_stock)` and `material_outlet_stock(material_id, outlet_id, current_stock)`; `stock_movements.outlet_id` / `material_stock_movements.outlet_id` (non-null after backfill); `stock_before/after` now refer to the **outlet's** balance. `applyMovement` gains a **required** `outletId` argument (no default — every caller must decide; a test greps the call sites). Locking: lock the (item, outlet) balance row (`lockForUpdate`), update it, then `increment` the cached total on the variant/material **in the same transaction**. `current_stock` stays as the cached sum of on-hand (excludes in-transit) so lists, POS, imports and reports that read it keep working; a reconciliation test asserts `current_stock = Σ outlet balances` and `outlet balance = Σ its ledger rows`.
**Rejected**: derive totals with a join per row (N+1/heavy); drop `current_stock` (touches every list, POS, report).

## D13 — Ledger types
Extend the `stock_movements.type` enum (and the material equivalent) with `transfer_out`, `transfer_in`, `transfer_cancel`. `StockMovementReferences` learns `reference_type = stock_transfer`. Existing types and meanings unchanged; the activity-log branch for manual adjustments keeps working.

## D14 — Transfers: two-step with explicit in-transit state
`stock_transfers(id, number unique, from_outlet_id, to_outlet_id, status ∈ in_transit|received|cancelled, has_discrepancy, note, dispatched_by/at, received_by/at, cancelled_by/at, client_ref unique null, data_mode)`; `stock_transfer_lines(id, transfer_id, kind ∈ variant|material, item_id, qty_sent, qty_received null, discrepancy_reason null)`. **In-transit quantity = Σ `qty_sent` of lines of `in_transit` transfers** (no separate balance row to drift).
`StockTransferService` (the only writer): `dispatch` (validate outlets active/different/permitted, lock balances in a deterministic order (by kind, item, outlet) to avoid deadlocks, `transfer_out` movements, status in_transit, audit); `receive` (once; `qty_received ≤ qty_sent`; short receipt needs a reason; `transfer_in` of the received quantity; shortfall recorded on the line and flagged; audit); `cancel` (only in_transit; `transfer_cancel` movements back to the source; audit). Idempotent dispatch via `client_ref` (existing pattern). Numbering `TRF-YYYYMMDD-####` computed with `withoutGlobalScopes` like order numbers.
**Invariant**: Σ outlet balances + in-transit + recorded shortfalls (written off at receipt) is constant across dispatch/receipt/cancel — asserted by a randomised-sequence test (SC-012).
**Receiving an item the destination does not carry**: allowed with a warning payload/UI note; transfers to/from inactive outlets refused; a transfer already in transit when an outlet is deactivated can still be received/cancelled.
**Rejected**: single-step transfers (no in-transit visibility); a negative "transit" balance row per outlet pair (drift-prone); approval workflow (out of scope).

## D15 — Transfer permissions and assignments
`PermissionCatalog` adds `stock.transfer.dispatch`, `stock.transfer.receive`, `stock.transfer.cancel` (defaults: owner/admin all; `inventory` role dispatch+receive). Authorisation = permission **and** assignment: dispatch/cancel require the source ∈ permitted, receive requires the destination ∈ permitted (administrators: all). The list/detail are scoped to transfers where either outlet is permitted. Menu key `stock` gates the screens; a new sub-screen route `/stock/transfers`.

## D16 — Low stock and the dashboard per outlet
`/stock/low`, `LowStockHistory` (stock_after replay) and the dashboard attention item evaluate **per (item, outlet)** against `variants.low_stock_alert`, return the outlet, and respect the effective scope; the attention row names the outlet when more than one is visible.

## D17 — Floor, tables and the Sales Tables board (044) per outlet
`dining_tables.outlet_id` + unique (outlet, name). `FloorQuery` filters by the effective outlets and, when more than one is effective, returns `outlets:[{id,code,name,areas:[…],takeaway_tabs:[…]}]` (single outlet keeps today's shape: `areas`, `takeaway_tabs` — frontend normalises). The 044 `floorBoard.js` gets the outlet level as an extra grouping (figures stay computed in the one function). Move/merge/split tab guard: same outlet only (409). `GET /floor` stays constant-query.

## D18 — Frontend shape
- `stores/outlet.js` (permitted outlets, selection, `multi`), `TopBar` selector (component exists), header injection in `api/client.js`; selection change refetches scoped pages (event on the store).
- `OutletsView.vue` (from `ArtistsView.vue`), route `/outlets` + redirect `/artists`, nav entry, i18n `nav.outlets`, `outlets.*`.
- Users screen: outlet multi-select (hidden at one outlet).
- Products: `AvailabilityChips` per outlet (+ summary collapse > 4), outlet filter, form (home outlet + carried checkboxes + price per outlet columns in the shared variants table), item availability section rows per outlet, 86 dialog outlet picker, bulk-price outlet scope.
- Stock: outlet column/selector, per-outlet balances, `TransfersView` (list), `TransferDetail`, `NewTransferDrawer`, discrepancy list.
- Everything outlet-specific is `v-if="outlet.multi"` (hidden, never disabled).

## D19 — Migration and reconciliation strategy
Forward migrations in dependency order (D1 rename → D2 columns → D7 outlet_id adds as nullable → backfill → NOT NULL + FKs + indexes → D10/D12/D14 tables → balances/marks expansion), each re-runnable (`hasColumn/hasTable` guards, as 043 did). An artisan command `outlets:reconcile` prints and asserts before/after figures (counts of outlets/products/orders/pre-orders/POs/settlements, per-item stock sums, report totals) and is executed by `OutletReconciliationTest` over a populated fixture and by the operator on a copy of production before release (rehearse on a dump — SC-002/SC-011). `down()` is best-effort for rename steps and drops new tables/columns only.

## D20 — Licence tier wording and enforcement
`LicenseGate` (one place) limits the number of outlets; `OutletPolicy::create` enforces it; Excel import re-checks (existing rule). Messages worded for outlets.

## D21 — Constitution amendment (Stage 0)
Start from `specs/042-dashboard-revamp/constitution-amendment.md` (sections A–E), adjust: outlets = renamed sellers; remove "registered devices/heartbeat" (not in this feature) while keeping the Principle IV paragraph about server-derived outlet and bounded client time **only if** needed (here: *server-derived outlet; request outlet never trusted*). Prepend the Sync Impact Report to `.specify/memory/constitution.md` (1.0.0 → 2.0.0), add dated notes to CLAUDE.md ("What this is") and PRD §1/§9.2/§10.2. **The final wording is shown to the owner before it is written.**

## D22 — What is explicitly deferred
Per-outlet channels and daily quotas, per-outlet menu releases, devices/heartbeat/cash limits (042 Stage B), copy-menu tooling, per-outlet tax settings, transfer slips, transfer approval and transfer pricing, per-outlet reorder points beyond the threshold, a shared-server hosting guide. Each is recorded in the spec Assumptions.
