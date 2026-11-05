# Research: Seller-Scoped User Access

INTERNAL

Facts come from a read-only code survey on 2026-11-05. ASSUMPTION marks a decision not grounded in existing code.

## Findings (fact)
- A seller is a direct column only on `products`, `order_items`, `preorder_items`, `purchase_orders`, `seller_settlements`, `artist_settlements`, `session_opening_cash_entries`. Variants, stock movements, BOM lines are reachable through `product_id`. Orders, pre-orders, payments, customers, events, sessions, drafts, vendors, materials have no seller.
- One order can mix sellers (OrderController comment, `order_items.artist_id` is a per-line snapshot).
- About 12 report/service queries use `DB::table` or joins and bypass Eloquent scopes; each already adds `data_mode` by hand (ReportController `sales/profit/artistProfit/preorders/export`, SalesByProductReport, SalesByPaymentMethodReport, SellerSettlementBuilder, SettlementService, PreorderRecognizedRevenue, StockMovementReferences).
- `DataModeScope` is a named class + trait + creating hook: good file-layout precedent. `User` deliberately has no mode scope (login must work in both modes) and must get no seller scope either.
- `withoutGlobalScopes()` is used in existence checks (VariantRemovalService, PaymentService client_ref lookup, PurchaseOrderService); they stay unscoped on purpose.
- Client-side seller filtering in `useSalesFilters`, and `SalesByProductReport` building filter options from all rows, would leak other sellers' names/data to a scoped user.

## D1 Where the rule lives
**Decision**: `App\Support\SellerGate` (`current()`, `applyToItems($query, $column)`, `orderIdsSubquery()`), consumed by global scopes AND the raw queries.
**Rationale**: a scope alone cannot cover raw queries; per-controller checks drift (the `relationLoaded` class of bug). One predicate, many callers.
**Alternatives**: global scopes only (misses raw reports, rejected); per-controller `if` checks (rejected: not coverable by a test).

## D2 Order/pre-order visibility (Q1:B)
**Decision**: header scope = `EXISTS (item with artist_id = mine)`. Items relation unscoped so the whole order shows. Totals shown on the order are whole-order. Statistics, cards, seller reports, settlements, exports of "sales" use only own lines via `applyToItems`.
**Trade-off (accepted by product owner)**: other sellers' lines, prices and the customer are visible inside shared orders. Cost/margin of other lines remain hidden (cost is already gated by the `reports` menu and is never part of the order payload).
**ASSUMPTION**: payments, shipments and payment proofs of a visible order are visible with it.

## D3 Customers (spec FR-006 vs POS operation)
**Decision**: customers visible = linked to a visible order/pre-order, plus customers the scoped user created (stamped `customers.artist_id`, nullable, set only on create by a scoped user). **ASSUMPTION**: needed so a scoped cashier can attach a brand-new customer before any order exists.
**Alternative**: no customer access (breaks partial POS sales, which require a customer).

## D4 Variants, stock movements, BOM
**Decision**: no denormalised column; scope through `product_id IN (select id from products where artist_id = mine)` including soft-deleted products so history stays visible.
**Rationale**: avoids a backfill and drift if a product's seller is edited. **Risk**: extra subquery; booth-scale data, measured in tasks.

## D5 Surfaces refused for scoped users (403 `seller_scope_forbidden`)
users, roles, settings (read and write), backups, activity logs, master-data import/export, customer and pre-order import, customer export, vendor/material/vendor-price WRITES, company/business-type/license/invoice (billing) routes, payment-channel writes, writes to artists/categories/events, sales-by-payment-method report. Vendor and material READS stay open (shared reference data a scoped user needs to create a purchase order). **Rationale**: administrative or cross-seller data with no seller dimension (payments are order-level). Owner is never scoped (D11), so the Owner account always administers; a scoped Admin cannot administer (clear the link or use the Owner account).

## D6 Reference data
Events, categories, payment channels stay visible (no seller data). Artists list returns only the user's seller.

## D7 Raw queries and options leakage
Every hand-rolled query gets `SellerGate::applyToItems()` beside its `data_mode` filter; dropdown option lists (`SalesByProductReport` artists filter, stock-by-artist, POS pills) come from the scoped set. A test greps each raw-query site (parity with `ReportDataModeIsolationTest`).

## D8 Seller lifecycle
Delete blocked (409) while users are linked; deactivation allowed: linked users see an empty view plus notice (`/auth/me` carries `seller_active:false`). No fallback to "see all".

## D9 Writes
Create forces `artist_id`; foreign product/variant ids 404 through scoped binding; `POST /stock/adjust`, BOM copy-from, PO create, settlement store/preview validate the seller. SettlementService recalculation on event close stays system-level (unscoped).

## D10 Clone/seed
New columns are FK into registered tables: extend `CloneRegistry` and the drift test allowlists deliberately. Users are not cloned today (ASSUMPTION, verify in tasks).

## D11 Owner is exempt (product-owner decision 2026-11-05)
**Decision**: a user whose role is Owner (`Role::isOwner()`) cannot hold a seller and `SellerGate::current()` returns null for them even if a stale `artist_id` exists. Server validation refuses `artist_id` for an Owner role (422); changing a user's role to Owner clears `artist_id` in the same transaction; the SPA hides the seller select when the Owner role is chosen.
**Rationale**: the Owner must always be able to administer and see everything; this also removes the lock-out risk where the only Owner gets scoped.
**Defence in depth**: the gate check is the real guard; validation only keeps data clean.

## D12 Pre-order report for scoped users (found during US2)
The default pre-order summary is header-level (`preorders.total_amount`), which would add other sellers' items. For a scoped user it is computed from the seller's item share (the `breakdown=artist` path summed per status x completeness) and the drilldown is forced to the share path. **Known inherited quirk**: that path INNER JOINs `events`, so a pre-order without an event is absent from the share figures (also absent from the existing unscoped `breakdown=artist` report); the unscoped header summary still includes it. Not changed here (would alter existing reports).

## Open items resolved during implementation
- `PreorderExportImportService` export uses the scoped `Preorder::query()` (verified by test); import is `seller.forbidden`. `CustomerExportImportService` export/import are `seller.forbidden`. `PillFilterRow` is a presentational row over the already-scoped artists list. `CloneRegistry`: `customers.artist_id` declared; `users.artist_id` guarded by a pre-check in `LiveToDemoCloner` (users are not cloned).

## Original open items
- `PreorderExportImportService`, `CustomerExportImportService`, `PillFilterRow` source (not read in the survey).
- Whether orders by a scoped cashier on other sellers' variants should be blocked at POS (yes via D9; confirm UX message).
