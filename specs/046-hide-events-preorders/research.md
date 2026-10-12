**Classification**: INTERNAL

# Research — 046 Hide Events and Pre-orders

Facts below come from reading the repository on 2026-10-12; anything not read is marked ASSUMPTION.

## D1 — Delete the code, keep the data (no feature flag)
- **Decision**: remove routes, controllers, services, screens, mail, imports/exports, documents and tests of both modules; keep every table and row.
- **Rationale**: the spec forbids a per-role switch and asks to remove dependencies "from all function/modules/filters"; Q1 = Q2 = A forbids deleting or converting data. A flag would leave the dead code paths and their tests in place.
- **Alternatives**: a `modules_enabled` setting (rejected: dead code, two behaviours to test); dropping the tables (rejected by Q1/Q2).

## D2 — Schema: three columns become nullable, nothing is dropped
- **Fact**: `cashier_sessions.event_id`, `orders.event_id`, `outlet_settlements.event_id` are NOT NULL; `pos_drafts.event_id`, `preorders.event_id` are nullable.
- **Decision**: one migration makes the three nullable (FKs stay). New rows carry NULL. `events`, `preorders`, `preorder_items`, `preorder_notifications`, `shipments` stay as inert tables. `payments.preorder_id` and `preorders.*` stay. `down()` refuses to restore NOT NULL while NULL rows exist.
- **Models**: `Event`, `Preorder*`, `Shipment` Eloquent classes are deleted with their last caller; if a migration or reconcile query needs a table it uses `DB::table`. `OutletsReconcile` keeps counting the legacy tables (so "nothing was deleted" stays provable).
- **Risk**: `outlet_settlements.event_id` NOT NULL → see D4.

## D3 — A date range (business date) replaces the event everywhere
- **Fact**: `SalesFigures::posBase` already filters `orders.business_date` and the dashboard already works by period; `profit`, `outlet-settlements`, `outlet-profit` and the recap transactions currently *require* `event_id`.
- **Decision**: those endpoints take `date_from`/`date_to` (business dates, inclusive); omitted = the current business day (`BusinessDay::today()`, Q3 = A). Validation messages and OpenAPI updated. Sales page, Reports, Dashboard and exports use the same pair; the outlet selector (feature 045) stays the other scope.
- **Alternatives**: month/7-day default (rejected by Q3); keeping an optional event filter (rejected: spec FR-005).

## D4 — The recap is computed live; `outlet_settlements` stops being written
- **Fact**: `SettlementService::recalculateForEvent()` writes a per-event snapshot read back by the recap screen (040 already made payable/status vestigial).
- **Decision**: the recap reads a live aggregate (`SettlementService::posSalesForPeriod`, same reporting-outlet rule as feature 045 Stage 7) and nothing writes the table; event close (which triggered the snapshot) disappears with events. The table keeps its rows as history.
- **Rationale**: a per-date-range snapshot would be stale or unbounded; the live aggregate is the "single implementation" the screen and exports already share.

## D5 — Cost & Profit loses the per-event cost
- **Fact**: `profit()` subtracts `events.event_cost` to get `net_profit`; `outlet-profit` has no event cost.
- **Decision**: the profit report returns revenue, cost of goods and gross profit for the period; `event_cost` and `net_profit` fields and their UI are removed (contract change recorded in `contracts/removed-and-changed.md`).
- **Alternative**: keep a free-form period cost (rejected: new feature, out of scope).

## D6 — Shift without an event
- **Decision**: `POST /sessions` no longer accepts `event_id` (ignored if sent by an old client, never required); the "session only on active event" rule and its message are deleted. A shift keeps outlet, cashier, opening cash entries and business date. `GET /sessions/*` no longer returns `event_name`.
- **Fact**: shift summaries/close already use the shift's own payments and business date, so no money logic depends on the event.

## D7 — Orders, tabs, drafts, receipts
- **Decision**: `OrderService`, `TabService`, `TabSettlementService`, `PosDraftService` stop reading/writing `event_id`; `OrderResource` and `GET /orders/{id}/receipt` drop `event_name`, location, dates and `available_on`; the receipt/invoice "event & available-on" block (features 014/023) is removed. Old orders keep their `event_id` value in the DB but it is never returned.
- **Fact**: `OrderController::index` has an `event_id` filter and the Sales page an event column/filter — both removed (D3).

## D8 — Pre-orders: what goes
Verified surface: ≈ 30 routes in `routes/api.php` (CRUD, status, dispatch-status, payments incl. confirmation/verify, split, duplicate, shipment, invoice, notifications/resend, bulk invoices/email, import/export/template/summary, `/reports/preorders`, `preorder` in `/reports/{report}/export`); 59 backend files and 62 frontend files mention pre-orders.
- **Decision**: delete `PreorderController`, `ShipmentController`, `PreorderService`, `PreorderNotifier`, `PreorderExportImportService`, `PreorderImport`, `PreorderInvoiceMail`, `PreorderStatusMail`, their requests, `PreorderDocumentType`, `PreorderSellerSubtotals`, `Couriers` (if no other caller), the pre-order branches of `BuildsInvoiceDocument`, `PaymentService`, `PaymentRecorder`, `PaymentSummary`, `PaymentProofController`, `CustomerController`, `StockMovementReferences`, `ProductController`/`Product*` (the `is_preorder`/`preorder_eta` flags), `MasterDataSheets` (the two product columns), and in the SPA `PreordersView` + `views/preorders/*`, `components/preorder/*`, `PreorderReportDetailModal`, `PreorderTab`, pre-order modes of `PaymentPanel`/`AddPaymentModal`/`PaymentHistoryList`/`ChannelPicker`, `ReceiptModal`, customer history, products form/detail.
- **Fact (comments only)**: `InvoiceService`, `PurchaseOrderService`, `InvoiceExportImportController`, `CustomerExportImportService`, `StockService`, `Customer` mention pre-orders mostly in comments; they only need comment/wording edits and the customer export check (D12).

## D9 — Payment ledger becomes orders-only
- **Fact**: `PaymentService`/`PaymentRecorder`/`PaymentSummary` serve both targets (feature 028).
- **Decision**: delete the pre-order target branch (27 mentions in `PaymentService`), keep the single write path `PaymentRecorder::record()`; payment rows that have `preorder_id` remain and are excluded from every query because they already have no order/shift. Shift cash (`payments.session_id`) is unaffected (pre-order payments had NULL session).
- **Check in tasks**: assert no report/figure sums `payments` without an `order_id`/`session_id` condition.

## D10 — Legacy stock rows stay, labels become neutral
- **Fact**: pre-order arrival wrote `purchase` movements and hand-over wrote `preorder_handover` with `reference_type` `preorder`/`preorder_item`; invariants I1/I2 (feature 045) depend on those rows.
- **Decision**: rows stay and still show in the ledger, but `StockMovementReferences` no longer resolves pre-order numbers and the label for `preorder_handover` becomes a neutral "Stock out"; no screen or API offers creating either. The ENUM value stays (a migration would be needless risk).
- **Spec note**: FR-011 is read as "no way to create or navigate to pre-order stock steps"; history rows cannot be hidden without breaking the ledger.

## D11 — Frontend utilities that only pre-orders used
- **Decision**: delete `utils/invoiceDocument.js` pre-order parts, `usePreorderBulk`, `usePreorderTransfer`, `preorderMeta`; `pdfCapture.js` is also used by the POS receipt, so it stays. `jszip` (bulk invoice zip) is imported only by `usePreorderBulk.js` (verified by grep: no other `resources/js` caller), so the dependency is removed from `package.json` together with that module.

## D12 — Customers, products, imports/exports
- **Decision**: customer history lists sales only (`CustomerController` + `CustomerTransactionsModal`); product screens/forms/detail drop the pre-order flag and ETA (columns stay); the master-data products sheet loses `is_preorder`/`preorder_eta`; the importer must **tolerate** old files that still carry those headings (ignored, not an error); `CustomerExportImportService` is checked for pre-order columns (ASSUMPTION: only a comment).

## D13 — Menu keys, saved roles, old addresses
- **Fact**: `MenuKeys` registers `events` and `preorders`; roles store `menu_keys` JSON; `PermissionCatalog` has no event/pre-order action key.
- **Decision**: remove both keys from `MenuKeys`; a data migration strips them from every `roles.menu_keys` (idempotent); `Role::allows`/`canAccessMenu` already ignore unknown keys (verified in the registry test of feature 045 — re-asserted by a new test). The SPA router redirects `/events`, `/preorders` (and children) to the default screen; the server's catch-all already serves the SPA for those paths. `?preorder_id=` / `?event_id=` query params are ignored.
- **Security**: removal is server-side (routes deleted → 404 for API clients); the redirect is UX only (constitution IV).

## D14 — Seeders and fixtures
- **Decision**: `DatabaseSeeder` roles lose the two keys; `SakanaFridgeDemoSeeder` stops creating an event and pre-orders and opens its shift without an event; `WarkopOutletsDemoSeeder` unaffected. Test factories lose `event_id` defaults where the column is now nullable; suites that characterise per-event/per-pre-order figures are deleted or rewritten by date.

## D15 — Verification and the browser
- **Decision**: backend and frontend suites per stage; a rehearsal on a populated copy compares sales, payment and stock totals before/after (SC-003) and runs `outlets:reconcile`; the real-browser pass required by the constitution is **blocked** while the Playwright profile is held by an unrelated Chrome (same blocker as feature 045) — recorded as an open item.

## D16 — Order of work
- **Decision**: stage 1 (schema + event-free trading) first because it unblocks everything; stage 2 re-bases reports before any module is deleted so no report is ever left reading a deleted table; pre-orders (3) before events (4) because pre-orders carry an event and the shared payment code is the riskiest part; stage 5 sweeps and proves the upgrade.
