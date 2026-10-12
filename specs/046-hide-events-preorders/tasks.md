**Classification**: INTERNAL

---
description: "Task list for 046 — Hide Events and Pre-orders"
---

# Tasks: Hide Events and Pre-orders

**Input**: `specs/046-hide-events-preorders/` (spec.md, plan.md, research.md D1–D16, data-model.md, contracts/removed-and-changed.md, quickstart.md)
**Tests**: required (constitution II): backend `tests/Feature` on real MySQL, frontend `qa-tests/`, browser walk when the profile is available.
**Rules of the road** (from the plan): delete code, keep data; never drop a table or row; both suites green at the end of every phase; `docs/openapi-pos-mvp.yaml` moves in the same commit as each route/response change; locale keys only through the merge/remove tools; comments and UI copy in Indonesian.

**Test command (container, explicit overrides — never plain)**:
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test [--filter=...]`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[US1…US5]**: user story from spec.md. US1 event-free trading · US2 reports/dashboard by date · US3 Events menu gone · US4 Pre-orders module gone · US5 upgrade safety

> Phase order follows the plan (D16), not the numeric order of the stories: US1 → US2 → US4 → US3 → US5, because reports must be re-based before a module is deleted, and pre-orders carry an event.

---

## Phase 1: Setup (baseline before any change)

- [x] T001 Take `php artisan app:backup` of the dev DB and run `php artisan outlets:reconcile --before`; record the folder path in `specs/046-hide-events-preorders/baseline.md`.
- [x] T002 Record in `specs/046-hide-events-preorders/baseline.md`: backend and frontend suite results (counts), `npm run build` main-chunk size, row counts of `events`, `preorders`, `preorder_items`, `preorder_notifications`, `shipments`, `payments` (and `payments.preorder_id IS NOT NULL`), `outlet_settlements`, `stock_movements`, and per-business-date Σ order totals, Σ order payments, Σ shift cash (the SC-003 numbers).
- [x] T003 [P] Write `specs/046-hide-events-preorders/tools/totals.sh` (artisan tinker one-liner or SQL) that prints the T002 numbers so the same snapshot can be re-run after each phase and at the end.

---

## Phase 2: Foundational (blocks every story)

**⚠️ No story can start before this phase: sessions/orders cannot be created without an event while the columns are NOT NULL.**

- [x] T004 Migration `database/migrations/2026_12_10_000001_make_event_id_nullable.php`: `cashier_sessions.event_id`, `orders.event_id`, `outlet_settlements.event_id` → NULL allowed (FKs kept, re-runnable via `information_schema` checks); `down()` stops with a clear message when any row has NULL (data-model §1).
- [x] T005 Migration `database/migrations/2026_12_10_000002_remove_event_and_preorder_menu_keys.php`: strip the strings `events` and `preorders` from every `roles.menu_keys` JSON (idempotent; no-op when absent); `down()` documented as non-restorable.
- [x] T006 [P] Test `tests/Feature/EventIdNullableMigrationTest.php`: the three columns accept NULL after migrate (information_schema), the `outlet_settlements` row can be inserted without an event, and the schema still keeps the FK.
- [x] T007 [P] Test `tests/Feature/RoleStaleMenuKeysTest.php`: a role whose saved `menu_keys` still contains `events`/`preorders` signs in, `canAccessMenu('events'|'preorders')` is false, `GET /auth/me` and role payloads never list them, and the data migration removes them from stored rows (FR-015, research D13).
- [x] T008 Apply both migrations to the dev DB (backup from T001 first), run `outlets:reconcile --after` + `--compare`; note the result in `baseline.md`.

**Checkpoint**: the product still runs unchanged; events/pre-orders still exist but are no longer *required* anywhere at the schema level.

---

## Phase 3: User Story 1 — Trade without an event (Priority: P1) 🎯 MVP

**Goal**: a cashier opens a shift, sells, pays, prints the receipt and closes the shift with zero events; nothing in these flows mentions an event.

**Independent Test**: quickstart §A on an install whose `events` table is empty.

### Tests for US1

- [x] T009 [P] [US1] Update `tests/Feature/CashierSessionTest.php` (and any session test that builds an event): open a shift with only `outlet_id`/`opening_cash`; no event in input, output or error; the "only on an active event" case is deleted.
- [x] T010 [P] [US1] Update `tests/Feature/OrderTest.php`, `TabTest.php`, `TabSettlementTest.php`, `PosDraftTest.php`: orders, tabs, split/merge and drafts are created with no event; `OrderResource` and the receipt carry no `event_name`/`event_location`/`event_*_date`/`event_available_on`.
- [x] T011 [P] [US1] New `tests/Feature/NoEventTradingFlowTest.php`: with `events` empty — open shift, sale + payment, receipt, tab on a table, close shift — all succeed and no response key contains `event`.
- [x] T012 [P] [US1] Update `qa-tests/component/SessionViewOutlet.test.js`, `PosView.test.js`, `ReceiptModal.test.js`, `TabView*.test.js`: no event field, selector or footer block; receipt no longer expects the available-on/location block.

### Implementation for US1

- [x] T013 [US1] `app/Http/Controllers/Api/CashierSessionController.php`: remove `event_id` validation, the `Event::findOrFail`/"active event" 409, and the event in the response/summary; keep outlet rules (research D6).
- [x] T014 [P] [US1] `app/Services/OrderService.php`, `app/Services/TabService.php`, `app/Services/TabSettlementService.php`: stop reading/writing `event_id` (the order inherits nothing from an event); `app/Models/CashierSession.php` and `app/Models/Order.php` drop the `event()` relation and `event_id` fillable/casts.
- [x] T015 [P] [US1] `app/Services/PosDraftService.php`, `app/Http/Controllers/Api/PosDraftController.php`, `app/Models/PosDraft.php`: remove the event from drafts.
- [x] T016 [US1] `app/Http/Resources/OrderResource.php` and `app/Http/Controllers/Api/OrderController.php`: remove event fields/loads from the order payload and the receipt (`event_name`, location, dates, `event_available_on*`), and the `event_id` list filter.
- [x] T017 [P] [US1] `resources/js/views/SessionView.vue` (open-shift form, header, `listEvents` call), `resources/js/views/PosView.vue`, `resources/js/views/TabView.vue`: remove event selection/labels and the `api/events.js` imports.
- [x] T018 [P] [US1] `resources/js/components/receipt/ReceiptModal.vue`: remove the event name line and the standout location/available-on block (features 014/023); keep outlet line and store identity.
- [x] T019 [US1] `docs/openapi-pos-mvp.yaml`: `/sessions` (no event input/output), `/orders*` and receipt (no event fields), per `contracts/removed-and-changed.md`; validate with the YAML load check.
- [x] T020 [US1] Run the full backend + frontend suites and `npm run build`; fix fallout from fixtures that still create events for sessions/orders (factories: `database/factories/CashierSessionFactory.php`, `OrderFactory.php` stop requiring an event).

**Checkpoint (US1)**: quickstart §A passes; suites green; events and pre-orders still exist elsewhere.

---

## Phase 4: User Story 2 — Reports, dashboard, sales by date and sales-only (Priority: P1)

**Goal**: every figure formerly "per event" or blended with pre-orders is produced by a business-date range (default current business day) and from sales only.

**Independent Test**: quickstart §B.

### Tests for US2

- [x] T021 [P] [US2] Rewrite `tests/Feature/ReportTest.php` expectations from event to date range (`date_from`/`date_to`, default = current business day); delete event-specific cases.
- [x] T022 [P] [US2] Rewrite `tests/Feature/SellerRecapPosOnlyTest.php` → `tests/Feature/OutletRecapByDateTest.php`: recap is a live aggregate for the period (same reporting-outlet rule as 045), no snapshot table written (`outlet_settlements` row count unchanged), `historical` marker kept.
- [x] T023 [P] [US2] Update `tests/Feature/OutletReportsTest.php` and `tests/Feature/OutletRenameCharacterizationTest.php` (period instead of event; the settlement-snapshot/event-close test is replaced by "no snapshot is written").
- [x] T024 [P] [US2] Update `tests/Feature/ReportCostPosPreorderSplitTest.php` → `ReportCostProfitTest.php`: revenue/cost/gross profit only (no `event_cost`, `net_profit`, no pre-order parts); `DashboardSummaryTest.php`, `DashboardOrdersTest.php`, `SalesPageDataTest.php`, `SalesTransactionsTest.php`: no event grouping/filter/column; customer history test: sales only.
- [x] T025 [P] [US2] Frontend: update `qa-tests/component/ReportsView.test.js`, `SalesView*.test.js`, `DashboardView*.test.js`, `ReportHistoricalTag.test.js`; delete `PerEventSection.test.js` and add `DashboardPeriodFigures.test.js` for whatever replaces it; new `qa-tests/unit/reportPeriod.test.js` for the default-period helper.

### Implementation for US2

- [x] T026 [US2] `app/Http/Controllers/Api/ReportController.php`: `profit`, `outletSettlements`, `outletSettlementTransactions`, `outletProfit`, `sales` and exports take `date_from`/`date_to` (default `BusinessDay::today()`), drop `event_id` validation/joins and `event_cost`/`net_profit`; remove every pre-order query/blend (`preorderRecognizedRevenueBase`, `preorders`, `preordersByOutlet`, drill-down, `PREORDER_FRACTION_EXPR`, preorder export branch, `PreorderSellerSubtotals` use) — research D3/D5/D8.
- [x] T027 [US2] `app/Services/SettlementService.php`: replace `recalculateForEvent`/`posSalesForEvent` with a live `posSalesForPeriod(from, to)` using `OutletFilter::reportOutletSql()`; nothing writes `outlet_settlements` any more (research D4). `app/Models/OutletSettlement.php` stays read-only for history.
- [x] T028 [P] [US2] `app/Services/SalesFigures.php`, `app/Services/SalesTransactionsService.php`, `app/Services/DashboardService.php`, `app/Services/AttentionService.php`: remove event/pre-order inputs and joins; figures from completed orders only.
- [x] T029 [P] [US2] `app/Http/Controllers/Api/CustomerController.php` + `app/Models/Customer.php`: history lists orders only; check `app/Services/CustomerExportImportService.php` (research D12: expected comment-only) and fix if a pre-order column exists.
- [x] T030 [P] [US2] Products: remove `is_preorder`/`preorder_eta` from `app/Models/Product.php`, `app/Http/Resources/ProductResource.php`, `app/Http/Requests/StoreProductRequest.php`, `UpdateProductRequest.php`, `app/Http/Controllers/Api/ProductController.php` (incl. `?is_preorder=`), `app/Support/MasterDataSheets.php`, `MasterDataImportService.php` (old files with those headings still import — add a test), `MasterDataExportService.php`; frontend `resources/js/views/products/ProductFormDrawer.vue`, `components/product/ProductDetailModal.vue`.
- [x] T031 [US2] Frontend reports: `resources/js/views/ReportsView.vue` (event selector → date-range control, default today), `views/reports/ProfitTab.vue`, `OutletProfitTab.vue`, `SettlementTab.vue`, `components/report/OutletTransactionsModal.vue`, `api/reports.js`; delete `views/reports/PreorderTab.vue`, `components/report/PreorderReportDetailModal.vue` and the pre-order tab wiring.
- [x] T032 [P] [US2] Frontend Sales/Dashboard/customer: `resources/js/views/SalesView.vue` (no event filter/column), `components/dashboard/PerEventSection.vue` (delete; keep any still-useful per-period panel as `PeriodFiguresSection.vue`), `views/DashboardView.vue`, `components/customer/CustomerTransactionsModal.vue`, `views/CustomersView.vue` (sales only), `components/sales/TransactionItemsModal.vue` (no pre-order mode).
- [x] T033 [US2] `docs/openapi-pos-mvp.yaml`: report endpoints and exports (period params, removed fields, removed `/reports/preorders` and the `preorder` export key), customers, products, master-data sheet columns.
- [x] T034 [US2] Re-run `tools/totals.sh`: the sales/payment/stock totals from `baseline.md` are unchanged; run both suites + build.

**Checkpoint (US2)**: quickstart §B passes with events and pre-orders still in the database but invisible to every figure.

---

## Phase 5: User Story 4 — The Pre-orders module disappears (Priority: P1)

**Goal**: no pre-order screen, route, filter, count, document, notification, import/export, stock step or payment branch remains; data stays inert.

**Independent Test**: quickstart §C (pre-order half) + §D.

### Tests for US4

- [x] T035 [US4] Delete backend tests of the module (22 files): `tests/Feature/Preorder*Test.php` (all 22: Bulk, Delete, DispatchStatus, DuplicateSplitReports, Duplicate, ExportImport, FormUpdates, InvoiceCreatedAt, InvoiceStoreIdentity, Invoice, Notification, NotifierSkipBranches, PaymentDelete, PaymentLedger, Report, Search, SellerSubtotalsReport, SourceFields, Split, `PreorderTest`, Update), `ShipmentAddressTest.php`; and strip pre-order cases from the shared tests that mention them (`grep -rli preorder tests/Feature`: Payment*, Customer*, StockMovements*, Stock*, Invoice*, MasterData*, OutletExcelSheets, Role/Permission, seeders).
- [x] T036 [P] [US4] New `tests/Feature/PreorderRoutesRemovedTest.php`: every route in `contracts/removed-and-changed.md` (pre-order list) answers 404 for owner; the `preorder` value of `/reports/{report}/export` is unknown (404/422); `/shipments/{id}` 404.
- [x] T037 [P] [US4] New `tests/Feature/PaymentsOrdersOnlyTest.php`: the payment ledger works for orders only (partial/split/verify/confirm/delete unchanged), and a `payments` row with `preorder_id` set is excluded from shift cash, order summaries and every report (research D9).
- [x] T038 [P] [US4] Update `tests/Feature/StockMovementsTest.php`: legacy `preorder_handover`/`purchase` rows with pre-order references still list, with `reference: null` and a neutral label; no endpoint creates them; ledger invariants test still passes.
- [x] T039 [P] [US4] Delete frontend tests of the module: `qa-tests/component/PreordersView*.test.js`, `PreorderDocuments`, `PreorderDuplicateResultModal`, `PreorderInvoiceModal`, `PreorderPaymentReceiptModal`, `PreorderReportDetailModal`, `PreorderSplitModal`, `PreorderStatusStepper`, `ReportsPreorderSubtotal`, `qa-tests/unit/*preorder*` and pre-order cases inside shared tests (payment panel/history, customer, products, receipt, stockMovements util).

### Implementation for US4 — backend

- [x] T040 [US4] `routes/api.php`: delete the pre-order block (CRUD, status, dispatch-status, payments + confirmation/verify, split, duplicate, shipment, invoice, notifications/resend, bulk-invoices/email, summary, export, import, template), `/shipments/{shipment}`, `/reports/preorders`, and the `preorder` alternative in `/reports/{report}/export`; remove the matching `use` lines.
- [x] T041 [US4] Delete `app/Http/Controllers/Api/PreorderController.php`, `ShipmentController.php`, `app/Http/Requests/{StorePreorderRequest,UpdatePreorderRequest,DuplicatePreordersRequest,SplitPreorderRequest}.php`, `app/Services/{PreorderService,PreorderNotifier,PreorderExportImportService}.php`, `app/Imports/PreorderImport.php`, `app/Mail/{PreorderInvoiceMail,PreorderStatusMail}.php` (+ their views under `resources/views/**`), `app/Support/{PreorderDocumentType,PreorderSellerSubtotals}.php`, `app/Support/Couriers.php` (if grep shows no other caller), `app/Models/{Preorder,PreorderItem,PreorderNotification,Shipment}.php`.
- [x] T042 [US4] `app/Services/PaymentService.php`, `PaymentRecorder.php`, `app/Support/PaymentSummary.php`, `app/Http/Controllers/Api/PaymentProofController.php`, `app/Models/Payment.php`: delete the pre-order target branches (27+3+2+1 mentions); keep `PaymentRecorder::record()` as the single write path; payment proof endpoints stay for orders.
- [x] T043 [P] [US4] `app/Services/StockMovementReferences.php` (drop pre-order resolution, keep `stock_transfer`), `app/Services/StockService.php` (comments), `app/Http/Controllers/Api/StockController.php` (no change expected; verify), `app/Support/ModeGate.php`, `app/Support/OutletFilter.php`, `app/Support/OutletScopeRegistry.php` (remove the `preorders` model/table entry and the pre-order raw-query decision; keep `OTHER_TABLES` accounting for tables that still have `outlet_id`: `preorders` moves to a documented "inert history" entry), `app/Console/Commands/OutletsReconcile.php` (keep counting the legacy tables with `DB::table`).
- [x] T044 [P] [US4] `app/Http/Controllers/Concerns/BuildsInvoiceDocument.php` and `app/Support/BuildsInvoiceDocument.php`: remove the pre-order document types and builders (the billing `Invoice` module keeps its own documents); `app/Http/Controllers/Api/InvoiceExportImportController.php`, `app/Services/InvoiceService.php`, `app/Services/PurchaseOrderService.php`, `app/Models/{PurchaseOrder,CompanyActivationNotification}.php`, `app/Mail/LicenseKeyMail.php`: comment/wording references only (research D8) — update the comments, verify no code path.
- [x] T045 [US4] `app/Support/MasterDataSheets.php` / import: none beyond T030; `database/seeders/SakanaFridgeDemoSeeder.php`: stop creating pre-orders (and, ahead of US3, keep the event only if still needed for its shift — removed fully in T058).
- [x] T046 [US4] `docs/openapi-pos-mvp.yaml`: delete the pre-order paths, schemas, shipment, notification and report/export entries; note the 404 behaviour.

### Implementation for US4 — frontend

- [x] T047 [US4] Delete `resources/js/views/PreordersView.vue`, `resources/js/views/preorders/*` (all 18 files), `resources/js/components/preorder/*` (all), `resources/js/api/{preorders,shipments}.js`, `resources/js/utils/invoiceDocument.js` pre-order parts (keep what the POS receipt/`pdfCapture.js` still use), `resources/js/utils/stockMovements.js` pre-order labels → neutral "stock in/out" label for legacy types.
- [x] T048 [US4] Remove the pre-order modes from shared components: `components/payment/{PaymentPanel,AddPaymentModal,PaymentHistoryList,PaymentConfirmationModal,ChannelPicker}.vue`, `components/ui/{BaseInput,ImageLightbox}.vue` (pre-order mentions), `components/receipt/ReceiptModal.vue` (pre-order receipt mode), `components/product/ProductDetailModal.vue`, `api/{payments,invoices,products,reports}.js`.
- [x] T049 [US4] `resources/js/router/index.js`, `utils/navigation.js`, `components/layout/{AppShell,RailNav}.vue`: remove the Pre-orders route, nav item and **badge** (`badgeKey: 'preorders'`, its polling/store), add `redirect` entries for `/preorders` and `/preorders/:pathMatch(.*)*` to the default landing route (FR-008, FR-015); delete `usePreorderBulk.js`, `usePreorderTransfer.js`, `preorderMeta.js`; remove `jszip` from `package.json`/lockfile (only `usePreorderBulk.js` imported it).
- [x] T050 [US4] Locales: remove the dead `preorders.*`, `preorder_*`, `shipment.*`, courier and pre-order report keys from `resources/js/locales/{id,en}.json` and `lang/{id,en}/preorders.php` (+ mail lang) using the remove tool (`specs/041-back-office-ui-redesign/tools/` — extend it with a `remove_locale.py` if none exists); `qa-tests/unit/localeKeys.test.js` and `localeParity` must pass.
- [x] T051 [US4] Run both suites + build; run `tools/totals.sh` (totals identical); fix every remaining reference found by `grep -rli preorder app resources/js routes tests qa-tests` (only deliberate legacy mentions may remain — list them in `baseline.md`).

**Checkpoint (US4)**: quickstart §C (pre-orders) and §D pass; no pre-order code path is reachable.

---

## Phase 6: User Story 3 — The Events menu and screen disappear (Priority: P1)

**Goal**: Events is gone from navigation, permissions, shortcuts, API and every filter; old addresses redirect.

**Independent Test**: quickstart §C (events half).

### Tests for US3

- [x] T052 [P] [US3] Delete `tests/Feature/{EventTest,EventDeleteTest,EventAvailabilityTest}.php`, `qa-tests/component/EventsView.test.js`; strip event setup from the remaining fixtures (`grep -rli "Event::factory\|event_id" tests qa-tests` — 56 backend / 144 frontend files, most only fixture lines).
- [x] T053 [P] [US3] New `tests/Feature/EventRoutesRemovedTest.php`: `GET|POST /events`, `GET|PUT|PATCH|DELETE /events/{id}`, `PATCH /events/{id}/status` → 404; `MenuKeys::keys()` has neither `events` nor `preorders`; `GET /menu-keys` and the role editor payload list neither.
- [x] T054 [P] [US3] New `qa-tests/unit/removedRoutesRedirect.test.js`: router resolves `/events`, `/events?event_id=1`, `/preorders`, `/preorders?preorder_id=1` to the default landing route with no error; `qa-tests/component/AppShellNav.test.js` (or the existing nav test): no Events/Pre-orders item for any role, no badge.

### Implementation for US3

- [x] T055 [US3] `routes/api.php`: delete the `events` apiResource and `PATCH /events/{event}/status` and the `EventController` import; delete `app/Http/Controllers/Api/EventController.php`, `app/Http/Requests/{StoreEventRequest,UpdateEventRequest}.php`, `app/Models/Event.php`, `app/Policies/EventPolicy.php` (and its registration), `database/factories/EventFactory.php`; `app/Http/Controllers/Controller.php` (event helper, if any).
- [x] T056 [US3] `app/Support/MenuKeys.php`: remove `events` and `preorders` from `keys()`/labels/defaults; `database/seeders/DatabaseSeeder.php` default roles lose both; check `app/Models/Role.php::allows` still ignores unknown stored keys (T007 proves it).
- [x] T057 [US3] Frontend: delete `resources/js/views/EventsView.vue`, `resources/js/api/events.js`; remove the Events route/nav entry in `router/index.js`, `utils/navigation.js`, `components/layout/{AppShell,RailNav}.vue`; add `redirect` for `/events` and `/events/:pathMatch(.*)*`; remove event keys from locales (`events.*`, `nav.events`) via the remove tool; role editor (`components/role/RoleMenuPicker.vue`) lists no Events/Pre-orders (driven by `GET /menu-keys`).
- [x] T058 [US3] `database/seeders/SakanaFridgeDemoSeeder.php` (+ `tests/Feature/SakanaFridgeDemoSeederTest.php`, `WarkopOutletsDemoSeederTest.php`): no event, shift without event, no pre-orders; keep the rest of the dataset.
- [x] T059 [US3] `docs/openapi-pos-mvp.yaml`: delete event paths/schemas; `docs/RUNBOOK.md`: remove event/pre-order procedures (event setup, closing an event, pre-order e-mail configuration) and note the redirects.
- [x] T060 [US3] Run both suites + build; `tools/totals.sh`; confirm `grep -rli "Event" app routes resources/js` only hits unrelated words (e.g. DOM events) — list any deliberate leftovers in `baseline.md`.

**Checkpoint (US3)**: quickstart §C passes completely; both modules are unreachable.

---

## Phase 7: User Story 5 — Upgrade safety (Priority: P2)

**Goal**: an install with events and pre-orders (some in progress) upgrades without errors and without any visible figure moving; the legacy data is intact.

**Independent Test**: quickstart §E on a populated copy.

- [x] T061 [US5] Rehearsal script `specs/046-hide-events-preorders/rehearse.sh` (modelled on feature 045's): copy of the dev DB into `warkop_wjm_rehearsal`, snapshot via `tools/totals.sh`, migrate, snapshot, diff — non-zero exit on any difference; refuses to touch `warkop_wjm`/`warkop_wjm_test`.
- [x] T062 [US5] Build a populated fixture on the rehearsal copy via artisan/tinker: several events (one active, one closed), pre-orders in each status incl. paid/partial and arrived/handed-over (so legacy `purchase`/`preorder_handover` rows and `payments.preorder_id` rows exist), a role with the old menu keys; run the rehearsal; record the results in `specs/046-hide-events-preorders/evidence-upgrade.md` (row counts of the inert tables unchanged; Σ order totals/payments/shift cash per business date identical; I1/I2 green; `outlets:reconcile --compare` green).
- [x] T063 [P] [US5] Test `tests/Feature/UpgradeKeepsLegacyDataTest.php`: with legacy `events`/`preorders`/pre-order `payments`/pre-order stock rows inserted directly, every report, the dashboard, shift summary and the stock ledger respond 200 and ignore them (totals equal the sales-only expectation).
- [x] T064 [US5] Apply to the dev DB (backup first), `outlets:reconcile --before/--after/--compare`, `tools/totals.sh` vs `baseline.md`.

---

## Phase 8: Polish & cross-cutting

- [x] T065 [P] Dated notes (history not rewritten): `CLAUDE.md` (a "Events and Pre-orders removed (feature 046, 2026-10-12)" section replacing now-wrong passages with pointers), PRD note in `docs/PRD-POS-Event-Multivendor.md` (events and pre-orders cut as capabilities; data retained), `README.md` history line.
- [x] T066 [P] `specs/046-hide-events-preorders/release-notes.md`: what changed for users, the API removals (404), the retained inert tables, rollback = restore from `app:backup` (`down()` of the nullable migration refuses when NULL rows exist), upgrade proof reference.
- [x] T067 Review pass: no unused imports/keys/files (`grep` for deleted symbols), no raw hex, comments in Indonesian, `README`/OpenAPI consistent, `package.json` without `jszip`, main chunk size vs baseline recorded.
- [x] T068 Final full suites (`php artisan test` with the explicit container overrides, `npx vitest run`, `npm run build`), tick all tasks, write open items into `specs/046-hide-events-preorders/evidence-final.md`.
- [x] T069 Browser pass (constitution II) — quickstart §A–D across the seeded roles and both languages; screenshots into `specs/046-hide-events-preorders/evidence/`. **Blocked while the Playwright browser profile is held by an unrelated Chrome (same as feature 045); record as open if still blocked.**

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2** (hard gate) → **US1 (Phase 3)** → **US2 (Phase 4)** → **US4 (Phase 5)** → **US3 (Phase 6)** → **US5 (Phase 7)** → **Polish (Phase 8)**.
- Why this order (research D16): US1 unblocks creating anything without an event; US2 re-bases every report so nothing reads a module before it is deleted; US4 before US3 because pre-orders reference events and the shared payment code is the riskiest; US5 proves the whole upgrade.
- Same-file sequencing (one task at a time): `routes/api.php` (T040, T055), `docs/openapi-pos-mvp.yaml` (T019, T033, T046, T059), `app/Http/Controllers/Api/ReportController.php` (T026 only), `resources/js/router/index.js` + `navigation.js` + `AppShell.vue` + `RailNav.vue` (T049 then T057), `resources/js/locales/*.json` (remove tool only: T050, T057), `database/seeders/SakanaFridgeDemoSeeder.php` (T045 then T058).
- Each phase ends with both suites + build green and `tools/totals.sh` equal to the baseline.

### Parallel opportunities

- Phase 3: T009–T012 in parallel; T014, T015, T017, T018 in parallel after T013.
- Phase 4: T021–T025 in parallel; T028, T029, T030, T032 in parallel after T026/T027.
- Phase 5: T036–T039 in parallel; T043, T044 in parallel; the frontend deletions (T047) and shared-component edits (T048) touch different files.
- Phase 6: T052–T054 in parallel.
- Phase 8: T065, T066 in parallel.

## Implementation Strategy

1. **MVP = Phases 1–3**: schema relaxed and trading works with no events (US1) — shippable alone, nothing removed yet.
2. Add Phase 4 (reports/dashboard by date): the visible dependency removal, still no module deleted.
3. Phase 5 then Phase 6 delete the modules and menus; Phase 7 proves the upgrade on a populated copy; Phase 8 closes docs and notes.
4. Never merge a phase with a red suite, a moved total (`tools/totals.sh` ≠ baseline) or an unrehearsed migration.
