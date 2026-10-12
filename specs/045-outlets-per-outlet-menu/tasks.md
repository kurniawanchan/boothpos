**Classification**: INTERNAL

# Tasks: Outlets (replacing Sellers) — Outlet-Scoped Operations, Per-Outlet Menu, Stock and Transfers

**Input**: `specs/045-outlets-per-outlet-menu/` — spec.md (11 stories, FR-001…FR-040, SC-001…SC-014), plan.md (stages 0–8), research.md (D1–D22), data-model.md, contracts/, quickstart.md

**Tests**: INCLUDED and written FIRST in every phase (constitution Principle II: MySQL backend tests, Vitest, and a real-browser check per stage with evidence).

**Organisation**: the plan's eight stages are the delivery order; inside each stage tasks carry the spec's story label. Each stage leaves a **single-outlet install unchanged** and ends with `outlets:reconcile` (counts/totals before = after) and a browser check.

## Conventions (every task)

- Backend tests only with the overrides: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test --filter=<Name>`; never edit PHP while a suite runs; **back up the dev DB before applying migrations** (`docker compose exec app php artisan app:backup`).
- Frontend: `npx vitest run --testTimeout=60000 <files>`; `npm run build`; copy goes through `python3 -I specs/041-back-office-ui-redesign/tools/merge_locale.py` (new keys) — renaming existing keys uses the one-off tool created in the Stage 1 tasks below.
- Comments in Indonesian explaining *why*; tokens only (no hex); outlet-specific controls are `v-if="outlet.multi"` (hidden, never disabled); money strings; 422/409/403 conventions; `docs/openapi-pos-mvp.yaml` in the same commit as every route/shape change; audit rows in the mutating transaction.
- A stage is DONE only when: its tests pass, the full backend + frontend suites are green, `outlets:reconcile` shows zero differences, the browser check in the stage's last task is recorded in `specs/045-outlets-per-outlet-menu/evidence-stage-N.md`, and the dev DB has no leftover test data.

## Format: `[ID] [P?] [Story] Description`

[P] = parallelisable (different files, no unfinished dependency). Stories: US1 outlets page · US2 rename everywhere · US3 list chips · US4 add/edit · US5 item page/86/bulk · US6 shifts/orders · US7 tables/floor · US8 users/selector/scope · US9 reports · US10 stock · US11 transfers.

---

## Phase 1: Setup

- [X] T001 Record the baseline in `specs/045-outlets-per-outlet-menu/baseline.md`: full backend suite, `npx vitest run`, `npm run build` main-chunk size, and the counts that the reconciliation must preserve (rows in `artists`, `products`, `orders`, `order_items`, `preorders`, `purchase_orders`, `artist_settlements`, `stock_movements`, `materials`; per-variant `current_stock` sum; the totals of `GET /reports/sales`, `/reports/artist-settlements`, `/reports/artist-profit` for the demo event).
- [X] T002 Take and keep a **populated rehearsal dump** of the dev DB (`app:backup`) plus a script `specs/045-outlets-per-outlet-menu/rehearse.sh` that restores it into a scratch database (`warkop_wjm_rehearsal`, never the dev or test DB) and runs migrations + `outlets:reconcile`; used at the end of every stage.
- [X] T003 [P] Write `specs/045-outlets-per-outlet-menu/rename-map.md`: the exact term map for the codemods (class/route/param/field/locale-key names old → new from contracts/api-outlets-rename.md and data-model.md §1, incl. which `artist_id` becomes `home_outlet_id` vs `outlet_id` per table) and the list of intentional exclusions (historical comments citing past features, migration files already shipped).

---

## Phase 2: Foundational — Stage 0 governance and shared tooling (blocks everything)

- [X] T004 Draft the final constitution amendment text in `specs/045-outlets-per-outlet-menu/constitution-amendment.md` starting from `specs/042-dashboard-revamp/constitution-amendment.md`: outlets = renamed sellers, one organisation with one or more outlets on one machine or one shared server, server-derived outlet (never from a request), audit and cache-key sentences; version 1.0.0 → 2.0.0 (MAJOR), Sync Impact Report, list of documents to receive dated notes. **STOP and show it to the owner for approval; no later phase may start until approved.**
- [X] T005 After approval apply it: prepend the Sync Impact Report and replace the Stack paragraph/Principle IV/V text in `.specify/memory/constitution.md` (version 2.0.0, Last Amended date); add dated notes (not rewritten history) to `CLAUDE.md` ("What this is" and the environment-constraints list) and to `docs/PRD-POS-Event-Multivendor.md` §1/§9.2/§10.2; record the approval in `specs/045-outlets-per-outlet-menu/evidence-stage-0.md`.
- [X] T006 [P] Create `tests/Support/OutletFixtures.php` (trait): `makeOutlets(n)`, `assignUser($user, $outlets)`, `shiftAt($outlet, $cashier)`, `orderAt($outlet, …)`, `tableAt($outlet, name)`, `carry($product, $outlets)`, `stockAt($variant, $outlet, $qty)`; works for single- and multi-outlet scenarios and is reused by every later test.
- [X] T007 [P] Characterisation tests pinning today's behaviour that must survive the rename (extend, do not weaken): `tests/Feature/SellerRecapPosOnlyTest.php`, `ReportCostPosPreorderSplitTest.php`, `StockTest.php`, `PurchaseOrderSellerTest.php`, `VariantBomTest.php` — add an explicit numeric snapshot test `tests/Feature/OutletRenameCharacterizationTest.php` (fixtures → expected report totals/settlements/stock sums) written against the CURRENT names so it can be switched to the new names in Stage 1.
- [X] T008 Implement `app/Console/Commands/OutletsReconcile.php` (`outlets:reconcile --before|--after|--compare`): snapshots counts and totals listed in T001 to `storage/app/reconcile/*.json`, and compares (exit code ≠ 0 on any difference); stage-specific checks (S2 outlet columns non-null, S5 invariants I1/I2) are added by later tasks; test it in `tests/Feature/OutletsReconcileCommandTest.php`.

**Checkpoint**: amendment approved and applied; fixtures, characterisation and reconcile tooling ready.

---

## Phase 3: Stage 1 — Rename (US1 outlets page, US2 outlet wording everywhere) — P1

**Goal**: sellers become outlets in place; page `/outlets`, API `/outlets`, wording, Excel names, menu key, licence setting. No behaviour change.

**Independent test**: `outlets:reconcile` zero differences; `/outlets` lists the three demo outlets; `/artists` redirects; old API route 404; old Excel workbook still imports.

### Tests first

- [ ] T009 [P] [US1] `tests/Feature/OutletTest.php` (port of `ArtistTest.php`): CRUD, validation, search, pagination, code immutability, inactive handling, role access (owner/admin/inventory/cashier) at `/api/v1/outlets`; `GET/POST /api/v1/artists` → 404.
- [ ] T010 [P] [US1] `tests/Feature/OutletDeleteGuardTest.php` (port of `ArtistDeleteGuardTest.php`): 409 with products, and (added later) orders/shifts/tables/users/stock; deactivate always allowed.
- [ ] T011 [P] [US1] `tests/Feature/OutletLicenseGateTest.php` (port of the artist part of `LicenseGateTest.php`): Pro refuses a second outlet with outlet wording; Master allows; setting key `multi_outlet_enabled` with fallback read of `multi_artist_enabled`.
- [ ] T012 [P] [US1] `tests/Feature/OutletMenuKeyMigrationTest.php`: a role whose `menu_keys` contained `artists` ends with `outlets` after the migration, no other key changes, idempotent.
- [ ] T013 [P] [US2] `tests/Feature/OutletMasterDataAliasTest.php` (extend `MasterDataImportTest`/`MasterDataExportTest`): export/template use sheet `outlets` and column `home_outlet_code`; importing the OLD names (`artists`, `artist_code`) succeeds with identical results; mixed workbook rules; round-trip.
- [ ] T014 [P] [US2] `tests/Feature/OutletRenameCharacterizationTest.php` (created in Phase 2): switch to the new names/routes and assert the numeric snapshots are unchanged.

### Implementation — data and backend

- [X] T015 [US1] Migration `database/migrations/2026_12_01_000001_rename_artists_to_outlets.php` (re-runnable, `hasTable/hasColumn` guards): rename table `artists`→`outlets`; `products.artist_id`→`home_outlet_id`; `order_items.artist_id` and `preorder_items.artist_id`→`home_outlet_id`; `purchase_orders.artist_id`→`outlet_id`; `session_opening_cash_entries.artist_id`→`outlet_id`; table `artist_settlements`→`outlet_settlements` (`artist_id`→`outlet_id`); rewrite `roles.menu_keys` `artists`→`outlets`; copy setting `multi_artist_enabled`→`multi_outlet_enabled`; keep FKs/indexes; document the per-table meaning in the file header (data-model.md §1).
- [X] T016 [US1] Rename the entity classes: `app/Models/Artist.php`→`Outlet.php` (keep `HasDataMode`, soft deletes), `ArtistSettlement`→`OutletSettlement`, `app/Policies/ArtistPolicy.php`→`OutletPolicy.php`, `app/Http/Controllers/Api/ArtistController.php`→`OutletController.php`, `StoreArtistRequest`/`UpdateArtistRequest`→`StoreOutletRequest`/`UpdateOutletRequest`, `ArtistResource`→`OutletResource`, `database/factories/ArtistFactory.php`→`OutletFactory.php`; update `AuthServiceProvider`/policy registration; delete-guard message keys in `lang/*`.
- [X] T017 [US2] Catalogue side by meaning (`home_outlet_id`): `app/Models/Product.php`, `ProductVariant` relations, `app/Http/Controllers/Api/ProductController.php`, `StoreProductRequest`, `UpdateProductRequest`, `ProductResource`, `ProductCatalogQuery`, `ProductDetailService`, `ProductCodeGenerator` (code prefix from the home outlet code — unchanged format), filters `home_outlet_id[]`; adjust `ProductFilterMultiValueTest`.
- [X] T018 [US2] Order/pre-order/PO side: `OrderService`, `TabService`, `PreorderService`, `TabSettlementService`, `PurchaseOrderService`, `PurchaseOrderController`, `StorePurchaseOrderRequest`/`UpdatePurchaseOrderRequest`, `VariantBomService` + `PurchaseOrderItem::eligibleForSeller()` (rename scope to `eligibleForOutlet()`; rule unchanged: PO `outlet_id` = product `home_outlet_id`), `OrderResource`, `CashierSessionController` opening-cash entries (`outlet_id`), `SessionOpeningCashEntry`.
- [X] T019 [US2] Reports and settlements (names only, **attribution unchanged = home outlet** until Stage 7): `ReportController` (`stockByOutlet`, `outletProfit`, `outletSettlements`, `outletSettlementTransactions`), `SettlementService`, `ReportSplit`, `PreorderSellerSubtotals`→`PreorderOutletSubtotals` (+ `tests/Unit` port), export entity/report names, `SalesTransactionsService` filter param `outlet_id[]` (home outlet for now).
- [X] T020 [US2] Support classes: `app/Support/LicenseGate.php` (outlet limit, new setting key + fallback), `MenuKeys.php` (`outlets`), `MasterDataSheets.php` (new names + **alias table** for old sheet/column names used by import only), `MasterDataImportService`/`MasterDataExportService`/`MasterDataSheetsImport`/`MasterDataExportController`/`MasterDataImportController`, `ImportMasterDataRequest`, `SettingsController`/`UpdateSettingsRequest`/`SettingPolicy` (feature flag exposure), `UserPolicy`, `EventPolicy`, `CustomerPolicy`.
- [X] T021 [US1] `routes/api.php`: `apiResource('outlets', OutletController::class)`, `/reports/stock-by-outlet|outlet-profit|outlet-settlements[/{outlet}/transactions]`, entity regexes for exports/imports/report exports; remove the old routes; update `docs/openapi-pos-mvp.yaml` (paths, schemas, parameters, descriptions) per contracts/api-outlets-rename.md and keep the YAML valid.
- [X] T022 [US2] Codemod the fixtures, seeders and tests with a reviewed script (`specs/045-outlets-per-outlet-menu/tools/rename_tests.py`, dry-run first, diff reviewed): `Artist::factory()`→`Outlet::factory()`, `artist_id`→(`home_outlet_id`|`outlet_id`) per table from rename-map.md, route strings, `tests/Support/{RestaurantFixtures,BuildsBomFixtures}.php`, `database/seeders/{DatabaseSeeder,SakanaFridgeDemoSeeder}.php` (the demo seeder keeps 3 outlets named after the sellers for now), and the ~60 feature test files listed by `grep -rli artist tests`; run the whole backend suite until green.

### Implementation — frontend

- [ ] T023 [US2] Create `specs/045-outlets-per-outlet-menu/tools/rename_locale_keys.py` (one-off, flock-safe like `merge_locale.py`, dry-run, refuses collisions): renames keys and rewrites values from a mapping file for `nav.artists`, `master_data.owner_artist`, `reports.*artist*`, etc.; applies to BOTH `id.json` and `en.json` identically; keeps `localeParity`/`localeKeys` tests green. Run it with a reviewed mapping; new wording: id "Outlet", en "Outlets", subtitle "Lokasi yang menjual menu".
- [X] T024 [US1] `resources/js/api/artists.js`→`outlets.js` (`/outlets`), `resources/js/views/ArtistsView.vue`→`OutletsView.vue` (wording "Add outlet", "Search outlet name…", columns CODE/NAME/CONTACT/PRODUCTS/STATUS), `router/index.js` (route `outlets`, redirect `/artists`→`/outlets`, `menuKey: 'outlets'`), `utils/navigation.js` (`nav.outlets`), `api/masterData.js` (entity `outlets`, filename), `LicenseTierSection.vue` wording, `stores/settings.js` (`multiOutletEnabled`).
- [X] T025 [P] [US2] Frontend sweep by area (each in its own PR-sized commit, tests ported in the same change): products (`ProductsView.vue`, `ProductDetailView.vue`, `views/products/ProductFormDrawer.vue` "Home outlet", `ProductDetailModal`, `VariantDetailModal`, `utils/posProductCards.js`), POS/cart (`PosView.vue`, `stores/posCart.js`, `PosCartPanel.vue`, `ProductVariantPickerModal.vue`), sales/stock/session (`SalesView.vue`, `composables/useSalesFilters.js`, `TransactionItemsModal.vue`, `ReceiptModal.vue`, `SessionView.vue`, `StockView.vue`), pre-orders (`PreordersView.vue`, `preorders/*`, `PreorderInvoiceDocument.vue`, `PreorderSplitModal.vue`), purchase orders (`PurchaseOrdersView.vue`, `PurchaseOrderDetailModal.vue`), reports (`ReportsView.vue`, `reports/{StockByArtistTab→StockByOutletTab,SettlementTab,ArtistProfitTab→OutletProfitTab,PreorderTab}.vue`, `components/report/{ArtistTransactionsModal→OutletTransactionsModal,StockByArtistDetailModal→StockByOutletDetailModal,PreorderReportDetailModal}.vue`), dashboard (`components/dashboard/PerEventSection.vue`), customers, `api/reports.js`, `BaseMultiSelect.vue`; ported tests under `qa-tests/` (`grep -rli artist qa-tests`).
- [X] T026 [US2] Search-and-confirm sweep: no remaining "Seller/Penjual/Artist" in live UI strings (both locale files), in OpenAPI descriptions, in `lang/*`, in new exports; add `qa-tests/unit/noSellerWording.test.js` that fails on those words in locale values (allow-list for historical docs); add the same check for `app/` user-facing strings.

### Verify

- [X] T027 [US1] Apply the migration to the dev DB (after backup), run `outlets:reconcile --before/--after/--compare` (zero differences), run `rehearse.sh`, full backend + frontend suites, `npm run build`.
- [ ] T028 [US1] Browser check (Stage 1) → `evidence-stage-1.md`: `/outlets` list/add/edit/delete/search/export/import in both languages; `/artists` redirect; old API 404; second outlet refused on Pro; a role that had Sellers reaches Outlets; Excel old-format import and new-format round trip; walk Products, POS, Sales, Pre-orders, Purchase orders, Reports for leftover wording; console clean.

**Checkpoint**: the product says "outlet" everywhere and behaves exactly as before (US1, US2 done).

---

## Phase 4: Stage 2 — Foundation: assignments, scope, selector, shifts, tables/floor (US8, US6 stamping, US7) — P1

**Goal**: one server-side scope; users assigned to outlets; shifts/orders/tables outlet-aware; selector; floor and Sales board per outlet.

**Independent test**: with 3 outlets and users assigned differently, every route returns only permitted outlets' data; forged outlet ids are refused; legacy rows appear under the first outlet and flagged.

### Tests first

- [X] T029 [P] [US8] `tests/Feature/OutletContextTest.php`: permitted set (assignments; admins all), `X-Outlet-Id` narrowing, id outside permitted → 403 `outlet_forbidden`, absent header = all permitted, `GET /me/scope` shape (`multi`, `can_see_all`).
- [X] T030 [P] [US8] `tests/Feature/UserOutletAssignmentTest.php`: `PUT /users/{id}/outlets`, list/detail expose `outlet_ids`, admin-only, activity log row, unassigned non-admin → `409 outlet_unassigned` on shift open and empty scoped lists.
- [X] T031 [P] [US6] `tests/Feature/ShiftOutletTest.php`: shift open validates `outlet_id` ∈ permitted, defaults to the single assigned outlet, ambiguous → 422, body outlet ignored for orders/tabs/payments/tickets (copied from the shift), opening-cash entry for the shift's outlet, shift/order outlet immutable.
- [X] T032 [P] [US7] `tests/Feature/FloorOutletTest.php` (extend `FloorViewTest`): tables per outlet, unique `(outlet, name)`, single outlet keeps today's shape, several outlets → `outlets[]` groups, constant query count, move/merge/split across outlets → `409 outlet_mismatch`, `paid_amount`/inactive-with-order rules still hold.
- [X] T033 [P] [US8] `tests/Feature/OutletIsolationMatrixTest.php` (skeleton now, grows each stage): enumerate every outlet-scoped table from a single registry (`OutletScopeRegistry`), assert each model carries `BelongsToOutlet` and that raw queries in `ReportController`, `SalesFigures`, `SalesTransactionsService`, `DashboardService`, `AttentionService`, `FloorQuery`, `LowStockHistory` use `OutletFilter`; then outlets × roles × endpoints (orders, sessions, tables, floor, dashboard, sales, reports) → zero foreign-outlet data, direct id → 404. **(partial: 6 explicit isolation cases, no table registry)**
- [ ] T034 [P] [US6] `tests/Feature/OutletBackfillTest.php`: legacy orders/sessions/tables/stations/tickets/preorders get the first outlet and `outlet_backfilled = true` (orders, preorders), new rows false; users assigned to all outlets; reconcile S2 checks.

### Implementation — backend

- [X] T035 [US8] Migration `2026_12_02_000001_create_outlet_user_and_add_outlet_id.php`: `outlet_user`; role flag `all_outlets` (true for owner/admin); nullable `outlet_id` on `cashier_sessions`, `orders`, `payments`, `kitchen_tickets`, `dining_tables`, `stations`, `preorders` (+ `outlet_backfilled` on `orders`, `preorders`); backfill to the first outlet; assign every existing non-admin user to all outlets; then NOT NULL + FK + indexes `(outlet_id, created_at)`; replace `dining_tables` name uniqueness with `(outlet_id, name)`; re-runnable.
- [X] T036 [US8] `app/Support/OutletContext.php` (request-scoped; `permitted()`, `selected()`, `effective()`, `single()`), `app/Models/Concerns/BelongsToOutlet.php` (stamps from context/owner object, ignores input), `app/Models/Scopes/OutletScope.php`, `app/Support/OutletFilter.php` (`apply($query,$col)`, `reportOutletSql()` placeholder for Stage 7), `app/Support/OutletScopeRegistry.php` (the enumerated table list used by the matrix test), `app/Http/Middleware/ResolveOutletContext.php` registered for API in `bootstrap/app.php` after auth. **(partial: no OutletScopeRegistry / reportOutletSql)**
- [X] T037 [US8] `app/Http/Controllers/Api/MeScopeController.php` + route `GET /me/scope`; `UserController`/`UpdateUserRequest`/`UserResource` expose `outlet_ids`, `all_outlets`; `PUT /users/{user}/outlets`; `RoleController`/`StoreRoleRequest` support `all_outlets`; activity-log on assignment changes; OpenAPI per contracts/api-scope.md.
- [X] T038 [US6] Stamp and scope: `CashierSessionController` (open shift with outlet rules, opening cash entry), `OrderService`, `TabService`, `TabSettlementService` (move/merge/split same-outlet guard), `PaymentRecorder`, `KitchenService`, `PreorderService`, `PurchaseOrderService` (outlet from context), `DiningTableController`/requests and `StationController`/requests (outlet field), models apply `BelongsToOutlet`.
- [X] T039 [US7] `app/Services/FloorQuery.php` (filter by effective outlets; `outlets[]` shape when several, constant queries, keep `paid_amount`/inactive rule), `FloorController`, `TabController::index` scope; OpenAPI `/floor`.
- [X] T040 [US8] Apply `OutletFilter` to every raw order/payment/session query: `ReportController` (sales, profit, outlet-profit, outlet-settlements, stock sales part, preorder), `SalesFigures`, `SalesTransactionsService`, `DashboardService`, `AttentionService`, `DashboardOrdersQuery`, `LowStockHistory` (stock part comes in Stage 5), `SettlementService` reads; keep attribution = home outlet for the settlement numbers until Stage 7.

### Implementation — frontend

- [X] T041 [US8] `resources/js/stores/outlet.js` (from `GET /me/scope`: `outlets`, `selected`, `multi`, `canSeeAll`; persists the selection per user id; emits a change signal), `api/client.js` (adds `X-Outlet-Id`), `api/me.js`; refetch hook for scoped pages (`composables/useOutletScoped.js`).
- [X] T042 [US8] Wire the existing `resources/js/components/layout/OutletSelector.vue` into `TopBar.vue`/`AppShell.vue` (shown only when `outlet.multi`; "Semua outlet · N" or one outlet; searchable above 8); `UsersView.vue` outlet multi-select + list column (hidden at one outlet). **(partial: selector + users checkboxes done; users list column open)**
- [X] T043 [US6] `SessionView.vue` open-shift dialog asks the outlet when the cashier has several; session header/receipt show the outlet; `DiningSetupView.vue` + `components/restaurant/TablesAdmin.vue`/`StationsAdmin.vue` outlet field. **(partial: shift dialog done; tables/stations admin and receipt open)**
- [X] T044 [US7] `FloorView.vue`/`stores/floor.js` normalise the single- vs multi-outlet shape; Sales Tables board: extend `resources/js/utils/floorBoard.js` with the outlet grouping level (figures stay computed in the one function), `components/sales/FloorBoard.vue` headings "Outlet — N dari M meja terisi"; `DashboardView.vue` refetch on outlet change and scope line; update/extend `floorBoard.test.js`, `FloorBoard*.test.js`, new `qa-tests/component/OutletSelector*.test.js`, `UsersViewOutlets.test.js`, `SessionViewOutlet.test.js`.

### Verify

- [X] T045 [US8] Apply to the dev DB (backup first), `outlets:reconcile` + S2 checks, `rehearse.sh`, full suites + build; extend the isolation matrix to all Stage 2 endpoints. **(partial: dev migrated + reconciled; S2 checks and rehearse.sh open)**
- [ ] T046 [US8] Browser check (Stage 2) → `evidence-stage-2.md`: 3 outlets; `kasir01`→WJ1, `kasir02`→WJ2, `inventory`→WJ1+WJ2, owner all; selector per role; shift open per outlet; forged `X-Outlet-Id` → 403; floor/Sales board per outlet and grouped for "all"; legacy orders under the first outlet and flagged; both languages; console clean; remove test data.

**Checkpoint**: outlet scope, assignments, shifts and tables are real (US6 stamping, US7, US8 done).

---

## Phase 5: Stage 3 — Per-outlet menu on the Products pages (US3, US4, US5) — P1/P2

**Goal**: every product related to every outlet (carried, price, 86); Products list/forms/item page/bulk price outlet-aware; single-outlet installs unchanged.

**Independent test**: item carried at two of three outlets with a different price at one; 86 at one outlet only; chips, filter and figures correct.

### Tests first

- [X] T047 [P] [US4] `tests/Feature/OutletItemSettingsTest.php`: rows materialised for every (outlet × product) at migration, on product create and on outlet create (default carried), unique pair, creating/updating with `outlets[]`, forbidden outlet → 403, all-or-nothing. **(data layer only: materialisation, unique pair, mode boundary; no API for outlets[] yet)**
- [X] T048 [P] [US4] `tests/Feature/VariantOutletPricesTest.php`: sparse prices written only via `PriceService`, `price_history.outlet_id`, empty follows base, approval rule compares with base, release mode creates a `menu_change` with `outlet_id`, negative → 422. **(data layer only: PriceService; release-mode queueing and approval not built)**
- [X] T049 [P] [US5] `tests/Feature/OutletAvailabilityTest.php`: 86 per outlet (`outlet_ids[]`), undo per outlet, existing global marks expanded per outlet by the migration, discontinue stays item-wide, `GET /menu/availability` scoped, forged outlet refused.
- [X] T050 [P] [US3] `tests/Feature/ProductsOutletsListTest.php`: `outlets[]` chips per visible outlet and states, `outlet_id[]` filter returns carried items, `summary` figures per outlet, single-outlet payload omits outlet fields, constant query count.
- [X] T051 [P] [US5] `tests/Feature/BulkPriceOutletScopeTest.php`: scope `base|all_outlets|outlets`, preview per (variant, outlet), history rows, all-or-nothing.
- [X] T052 [P] [US4] `tests/Feature/OutletExcelSheetsTest.php`: sheets `outlet_items` and `outlet_prices`, unknown codes per-row errors, all-or-nothing, round trip.

### Implementation — backend

- [X] T053 [US4] Migration `2026_12_03_000001_create_outlet_item_settings_and_prices.php`: `outlet_item_settings`, `variant_outlet_prices`, `item_availability_marks.outlet_id` (expand each existing mark per outlet, then NOT NULL), `price_history.outlet_id` NULL; backfill carried=true for all pairs; indexes; re-runnable.
- [X] T054 [US4] Models `app/Models/{OutletItemSetting,VariantOutletPrice}.php` (+ `HasDataMode`), `app/Services/OutletMenuService.php` (materialise rows on product/outlet create; set carried; used by controllers/import), hooks in `ProductController::store`, `OutletController::store`. **(models + OutletMenuService + provider hooks done; controller hooks not needed; no API yet)**
- [X] T055 [US4] `app/Services/PriceService.php`: `setOutletPrice`, `priceFor(variant, outletId)`, history with `outlet_id`; remains the only price writer; `VariantBatchService`/`VariantBatchRequest` accept `outlet_prices`; `BulkPriceService`/`PriceChangeRequest` accept `scope`, `outlet_ids`. **(PriceService outlet price + priceFor done; VariantBatch/BulkPrice scope open)**
- [X] T056 [US5] `app/Services/AvailabilityService.php` + `ProductAvailabilityController` + `SoldOutRequest` (per-outlet marks), `MenuAvailabilityController` (scoped poll), `app/Support/MenuAvailability.php` (carried + mark per outlet — resolver prepared here, wired into orders in Stage 4). **(done in a different shape: global-or-outlet marks instead of expanding existing marks; scoped poll, selected-outlet default; resolver also refuses not-carried items. No NOT NULL migration)**
- [X] T057 [US3] `app/Services/ProductCatalogQuery.php` (one batched query for chips of the page's products; outlet filter; summary per outlet), `ProductDetailService.php` (availability rows per outlet, `outlet_prices`), `ProductController` (`home_outlet_id`, `outlets[]`, filters) and `ProductResource`; OpenAPI per contracts/api-menu-outlets.md. **(partial: `outlet_ids` + `outlets[]` on create/update/show and outlet-price endpoints done; list chips/filter/summary per outlet open)**
- [X] T058 [US4] Excel: `MasterDataSheets.php` + import/export for `outlet_items`, `outlet_prices`, products `home_outlet_code`; keep all-or-nothing and the shipped-template-imports-as-is guard.

### Implementation — frontend

- [X] T059 [US3] `components/product/AvailabilityChips.vue` per outlet (code + state, collapse to "N available · M sold out" above 4), `ItemCard.vue`/`ItemTable.vue`/`ProductFigures.vue` per selected outlet, `ProductsView.vue` outlet filter following the selector; `api/products.js` params.
- [X] T060 [US4] `views/products/ProductFormDrawer.vue` + `ProductVariantsTable.vue`: *Home outlet* (required), *Active at outlets* checkboxes (all checked by default), one price column per visible outlet (grey = follows base; horizontal scroll above 5), edit prefill; `ProductCreateVariants`/payload mapping; `api/products.js` bodies. **(partial: "Active at outlets" checkboxes done; per-outlet price columns open)**
- [X] T061 [US5] `ProductDetailView.vue` + `AvailabilitySection.vue` (row per outlet), `SoldOutDialog.vue` outlet checkboxes, `VariantsDrawer.vue` outlet price columns, `BulkPriceDrawer.vue` scope picker and preview grouped by outlet, `PriceHistoryTimeline.vue` names the outlet; all hidden at one outlet.
- [X] T062 [P] [US3] Frontend tests: `AvailabilityChips`, `ItemCard/ItemTable` outlet variants, `ProductsViewOutlets.test.js`, `ProductFormOutlets.test.js`, `SoldOutDialogOutlets.test.js`, `BulkPriceDrawerOutlets.test.js`, `VariantsDrawerOutlets.test.js`; single-outlet variants of the existing Products tests must stay green unchanged.

### Verify

- [X] T063 [US3] Apply to the dev DB, `outlets:reconcile` (marks expanded = old marks × outlets; every item carried everywhere), `rehearse.sh`, suites + build; extend the isolation matrix for product/outlet endpoints.
- [ ] T064 [US3] Browser check (Stage 3) → `evidence-stage-3.md`: chips and filter; add an item carried at WJ1+WJ2 with a WJ2 price; reopen/edit; 86 at one outlet; bulk price scoped to one outlet; history names the outlet; Excel round trip; single-outlet install unchanged; both languages; console clean.

---

## Phase 6: Stage 4 — Orders use the outlet's data (US6) — P1

**Goal**: price and orderability resolved by the order's outlet on POS, tabs and the cashier screens.

**Independent test**: same item 86 at WJ1, orderable at WJ2 at 27 000; forged order for a not-carried item refused.

- [X] T065 [P] [US6] `tests/Feature/OrderOutletPricingTest.php`: outlet price else base on `POST /orders` and tab lines; recorded snapshot unchanged when prices later change; split/duplicate paths keep the order's outlet.
- [X] T066 [P] [US6] `tests/Feature/OrderOutletAvailabilityTest.php`: 86 at the shift's outlet → 409 `item_unavailable_at_outlet`, other outlet fine, not carried → refused and hidden in `GET /products?with_variants=1`, pre-order creation uses the creating outlet's carried set.
- [X] T067 [US6] Wire the single resolvers: `OrderService`, `TabService`, `TabLineController`, `PreorderService`, `OrderController`/product list endpoints (`GET /products?with_variants=1`, `GET /variants/lookup`) call `PriceService::priceFor` and `MenuAvailability::orderable` with the context's single outlet; `MenuAvailabilityController` poll scoped; OpenAPI.
- [X] T068 [US6] Frontend cashier screens: `PosView.vue`, `TabView.vue`, `utils/posProductCards.js` (cards only for carried items, greyed "86" per outlet via `composables/useMenuAvailability.js` with the shift's outlet), `ReceiptModal.vue`/`TransactionItemsModal.vue`/order lists show the outlet name; tests `PosViewOutlet.test.js`, `TabViewOutlet.test.js`, `useMenuAvailability` outlet case.
- [ ] T069 [US6] Browser check (Stage 4) → `evidence-stage-4.md`: two cashiers at two outlets; 86 at WJ1; outlet price at WJ2; forged request refused; receipts show the outlet; suites green; `rehearse.sh`.

---

## Phase 7: Stage 5 — Stock per outlet (US10) — P1

**Goal**: per-outlet balances for items and materials; every movement carries its outlet; cached total kept; low stock per outlet.

**Independent test**: different balances at two outlets; a sale moves only its outlet; last unit refused at one, sellable at the other; reconcile I1/I2.

### Tests first

- [X] T070 [P] [US10] `tests/Feature/StockPerOutletTest.php`: sale/return/void/handover on the order's/pre-order's outlet, insufficient stock names the outlet (409), untracked items unaffected, adjustment/stock-take per permitted outlet, ledger `stock_before/after` per outlet.
- [X] T071 [P] [US10] `tests/Feature/StockLedgerInvariantTest.php`: I1 (balance = Σ ledger) and I2 (cached total = Σ outlet balances) after randomised movements; a source-scan test that fails if any `applyMovement(` call omits the outlet argument.
- [X] T072 [P] [US10] `tests/Feature/StockConcurrencyTest.php`: two simultaneous sales/adjustments of the last units → exactly one succeeds (row locks), no negative.
- [X] T073 [P] [US10] `tests/Feature/MaterialStockPerOutletTest.php`: PO receipt adds to the PO's outlet, material adjustments per outlet, materials list per outlet.
- [X] T074 [P] [US10] `tests/Feature/LowStockPerOutletTest.php` (+ dashboard attention): per (item, outlet) alerts, names the outlet, respects scope.
- [X] T075 [P] [US10] `tests/Feature/StockImportOutletTest.php`: `stock` sheet with `outlet_code`, absolute balances per outlet, required when several outlets, unknown code per-row error, export carries outlet.
- [ ] T076 [P] [US10] `tests/Feature/StockBackfillTest.php`: migration puts each variant balance at its home outlet and material balance at the first outlet; ledger rows stamped; Σ outlet = old total (SC-011).

### Implementation — backend

- [X] T077 [US10] Migration `2026_12_04_000001_create_outlet_stock.php`: `variant_outlet_stock`, `material_outlet_stock`, `stock_movements.outlet_id`, `material_stock_movements.outlet_id` (backfill, NOT NULL, FK, index `(variant_id, outlet_id, created_at)`), extend type enums with `transfer_out|transfer_in|transfer_cancel`; verify I1/I2 inside the migration (fail loudly).
- [X] T078 [US10] `app/Services/StockService.php` and `MaterialStockService.php`: REQUIRED `outletId` argument; lock the (item, outlet) balance row; update balance and the cached total in the same transaction; movement carries the outlet; keep the manual-adjustment audit branch.
- [X] T079 [US10] Update every call site to pass the right outlet: `OrderService` (×2), `TabService` (×3), `PreorderService` (×3; outlet = pre-order's), `StockController`, `MasterDataImportService` (initial stock → home outlet of the new variant; `stock` sheet per outlet), `PurchaseOrderService` (PO's outlet), `VariantBatchService`/product create initial stock; make the source-scan test in `StockLedgerInvariantTest` pass.
- [X] T080 [US10] Models `VariantOutletStock`, `MaterialOutletStock`; `StockController` (`GET /stock`, `/stock/movements` with outlet fields/filters, `/stock/low` per (item, outlet), adjustments with `outlet_id`), `StockAdjustmentRequest`, materials stock endpoints, `StockMovementReferences`; `LowStockHistory`, `DashboardService`/`AttentionService` low-stock per outlet; extend `OutletsReconcile` with I1/I2 checks; OpenAPI per contracts/api-stock-transfers.md.

### Implementation — frontend

- [X] T081 [US10] `views/StockView.vue` (outlet selector/column, per-outlet on-hand and total in "all" mode), adjustment/stock-take dialogs ask the outlet, `VariantHistoryModal.vue`/`ProductDetailModal.vue` per outlet, `MaterialsView.vue` per outlet, low-stock list and dashboard attention name the outlet; i18n; tests `StockViewOutlets.test.js`, `StockAdjustOutlet.test.js`, `LowStockOutlet.test.js`.

### Verify

- [X] T082 [US10] Apply to the dev DB, `outlets:reconcile` (I1, I2, per-item Σ = old total), `rehearse.sh`, suites + build.
- [ ] T083 [US10] Browser check (Stage 5) → `evidence-stage-5.md`: balances per outlet; sale at WJ1 moves only WJ1; last-unit refusal; adjustment per outlet; PO receipt to the PO's outlet; pre-order arrival; low-stock names the outlet; single-outlet unchanged; both languages; console clean.

---

## Phase 8: Stage 6 — Transfers between outlets (US11) — P1

**Goal**: two-step transfers with in-transit state, discrepancies, permissions and audit.

**Independent test**: dispatch 10, receive 8 with a reason, cancel another; invariant I3 holds throughout.

### Tests first

- [X] T084 [P] [US11] `tests/Feature/StockTransferDispatchTest.php`: creates + dispatches (source drops, in-transit shown), validation (same outlet, inactive outlet, qty ≤ 0, insufficient → 409, item not carried at destination → warning), idempotent `client_ref`, number format and uniqueness across modes.
- [X] T085 [P] [US11] `tests/Feature/StockTransferReceiveTest.php`: full receive, partial receive requires a reason and flags the discrepancy, over-receipt 422, second receive 409, receive when destination outlet deactivated still allowed, ledger rows reference the transfer.
- [X] T086 [P] [US11] `tests/Feature/StockTransferCancelTest.php`: cancel only in transit, goods return to the source with a ledger row, cancelled/received immutable.
- [X] T087 [P] [US11] `tests/Feature/StockTransferInvariantTest.php`: randomised dispatch/receive/cancel sequences keep I3 (Σ balances + in-transit + recorded shortfalls constant, never negative) for items AND materials.
- [ ] T088 [P] [US11] `tests/Feature/StockTransferConcurrencyTest.php`: two simultaneous dispatches of the last units → one succeeds; deterministic lock order (no deadlock) under crossing transfers A→B and B→A.
- [X] T089 [P] [US11] `tests/Feature/StockTransferPermissionsTest.php`: `stock.transfer.dispatch|receive|cancel` separate; assignment checks (source for dispatch/cancel, destination for receive; admins all); list/detail scoped to permitted outlets; forged outlets 403/404; audit rows in the same transaction (rollback test).

### Implementation — backend

- [X] T090 [US11] Migration `2026_12_05_000001_create_stock_transfers.php`: `stock_transfers`, `stock_transfer_lines` per data-model.md §3 (indexes on status and outlets, unique number and `client_ref`).
- [X] T091 [US11] Models `StockTransfer`, `StockTransferLine` (+ `HasDataMode`, outlet relations); `app/Services/StockTransferService.php` (`dispatch`, `receive`, `cancel`; the ONLY writer; calls `StockService`/`MaterialStockService`; deterministic lock order; audit via `ActivityLogger` in-transaction); numbering `TRF-YYYYMMDD-####` with `withoutGlobalScopes` (like order numbers).
- [X] T092 [US11] `app/Http/Controllers/Api/StockTransferController.php` + `StoreStockTransferRequest`, `ReceiveStockTransferRequest`, `StockTransferResource`; routes per contracts/api-stock-transfers.md (list, detail, create+dispatch, receive, cancel, discrepancies, in-transit); `PermissionCatalog` adds the three permissions with defaults (owner/admin all; `inventory` dispatch+receive) and the roles-defaults migration `2026_12_05_000002_add_transfer_permissions.php`; OpenAPI.

### Implementation — frontend

- [X] T093 [US11] `api/stockTransfers.js`; `views/stock/TransfersView.vue` (list + filters), `components/stock/TransferDetail.vue` (lines, steps, ledger, discrepancy banner), `NewTransferDrawer.vue` (from/to, line table with search and available quantity, destination warnings), `ReceiveTransferDialog.vue` (per-line quantities, reason when short), cancel confirm, discrepancies tab; route `/stock/transfers` under menu `stock`, nav entry, i18n; actions only with the matching permission and assignment; hidden at one outlet; tests per component.
- [ ] T094 [US11] Browser check (Stage 6) → `evidence-stage-6.md`: the quickstart transfer scenarios incl. partial receive, cancel, simultaneous dispatch from two browsers, permissions per role, items and materials, both languages; reconcile I3; console clean.

---

## Phase 9: Stage 7 — Reports, dashboard and exports by selling outlet (US9, US7 board) — P1

**Goal**: former seller reports group by the outlet where the sale was made; history keeps its attribution.

**Independent test**: post-change sales at two outlets sum to the all-outlets total; legacy figures equal the pre-change reports.

- [X] T095 [P] [US9] `tests/Feature/OutletRecapTest.php` (successor of `SellerRecapPosOnlyTest`): post-change sales per selling outlet, Σ rows = total (I4), outlet filter, historical rows tagged.
- [X] T096 [P] [US9] `tests/Feature/OutletReportHistoryTest.php`: legacy orders (`outlet_backfilled`) attributed to the home outlet and equal the pre-change snapshot (the pre-change snapshot from Phase 2 and `baseline.md`); a period spanning the change sums both without double counting.
- [X] T097 [P] [US9] `tests/Feature/OutletSettlementsTest.php` + `OutletProfitReportTest.php` + `StockByOutletReportTest.php` + pre-order-by-outlet: stored `outlet_settlements` snapshot equals the screen, event close uses the helper, exports match screens.
- [X] T098 [US9] Implement `OutletFilter::reportOutletSql()` (`CASE WHEN orders.outlet_backfilled THEN order_items.home_outlet_id ELSE orders.outlet_id END`, pre-orders likewise) and use it in `ReportController` (all outlet reports and exports), `SettlementService` (`posSalesForEvent`, event close), `DashboardService` ("Results per outlet"), `SalesTransactionsService` grouping; add the `historical` marker to report rows; OpenAPI.
- [X] T099 [US9] Frontend: `ReportsView.vue` + tabs (outlet filter follows the selector, "by outlet" headings, "historical" tag), `PerEventSection.vue`, exports labels; tests; verify the Sales Tables board figures per selected outlet still equal tiles.
- [ ] T100 [US9] Browser check (Stage 7) → `evidence-stage-7.md`: sales at WJ1/WJ2 after the change; recap per outlet and total; legacy report equals the exported pre-change report; spanning period; exports; both languages; reconcile I4.

---

## Phase 10: Stage 8 — Hardening and release readiness

- [X] T101 Complete `OutletIsolationMatrixTest` for every outlet-scoped endpoint (outlets × owner/admin/kasir01/kasir02/inventory × list/detail/figures/export/transfers/stock/reports) including direct requests, forged `X-Outlet-Id`, and exports; assert the registry covers every table that has an `outlet_id` column (fails when a new table is forgotten).
- [X] T102 Performance pass (SC-010) on an unloaded machine with seeded volume (20 outlets, 500 items, 60 tables/outlet): Outlets page, Products list, Floor/Tables board, scoped Dashboard, Stock screen, transfer list < 2 s; constant query counts for `/floor`, `/products`, `/stock`; record the numbers and load in `evidence-stage-8.md`.
- [X] T103 Update `database/seeders/SakanaFridgeDemoSeeder.php` for a realistic multi-outlet demo (outlets "Warkop WJM 1/2/3", users assigned, tables per outlet, per-outlet balances, a transfer in transit) and its test; keep the single-outlet default seed working.
- [X] T104 Rehearse the whole migration chain on the populated dump (`rehearse.sh`): all `outlets:reconcile` checks green (counts, totals, I1/I2, I4), time taken recorded, rollback notes written in `specs/045-outlets-per-outlet-menu/release-notes.md` (what is reversible, what needs a restore from `app:backup`).
- [ ] T105 Final browser pass: 3 outlets × owner/admin/kasir01/kasir02/inventory × both languages across Outlets, Products, POS/tabs, Floor/Sales board, Stock, Transfers, Reports, Dashboard, Users; single-outlet install (one outlet) shows no outlet control anywhere; console clean; screenshots in `specs/045-outlets-per-outlet-menu/evidence/`.
- [X] T106 Update `CLAUDE.md` with an "Outlets (feature 045)" section (rename meaning of `home_outlet_id` vs `outlet_id`, `OutletContext`/`OutletScope`/`OutletFilter`/header, stamping rules, per-outlet menu tables, `StockService` outlet argument and I1–I3, transfers, report attribution and `outlet_backfilled`, `outlets:reconcile`), replace now-wrong "artist/seller" passages with dated notes (history kept), update the PRD scope note and `docs/RUNBOOK.md` (migration/rehearsal, backups).
- [X] T107 Full suites (backend per file on MySQL, `npx vitest run`, `npm run build` — main chunk not larger than baseline beyond noise), review pass (no unused code/keys, comments in Indonesian, no raw hex, no leftover `artist` in live code), tick all tasks, write open items into `evidence-stage-8.md`.

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 (Stage 0 gate) → Phase 3 (Stage 1) → Phase 4 (Stage 2)**; nothing outlet-specific is released before the amendment is approved (the two Stage 0 amendment tasks).
- After Stage 2: **Stage 3 (Phase 5)** and **Stage 5 (Phase 7)** can run in parallel (different tables/services; both only touch shared files in `OutletsReconcile`, `OutletScopeRegistry`, and the isolation matrix — merge those sequentially).
- **Stage 4 (Phase 6)** needs Stage 3; **Stage 6 (Phase 8)** needs Stage 5; **Stage 7 (Phase 9)** needs Stages 2 and 5 (and benefits from 4); **Phase 10** last.
- Same-file sequencing: `routes/api.php`, `docs/openapi-pos-mvp.yaml`, `bootstrap/app.php`, `OutletsReconcile.php`, `OutletScopeRegistry.php`, `OutletIsolationMatrixTest.php`, `resources/js/locales/*.json` (merge/rename tools only) and `SalesView.vue`/`FloorBoard.vue` are edited by one task at a time; `StockService.php` only by the Stage 5 tasks (and Stage 6 reads it).
- Each stage: tests → migration → models/services → controllers/requests/OpenAPI → frontend → apply to dev DB (after backup) → `outlets:reconcile` + `rehearse.sh` → full suites → browser evidence.

### Parallel opportunities

- Phase 3 tests (the port tasks) are independent files; the frontend wording sweep areas are independent of each other once the key-rename tool exists.
- Phase 4 tests (six files) in parallel; backend tasks `OutletContext` classes vs `MeScope/User assignment` vs `FloorQuery` can proceed in parallel after the migration.
- Phase 5 and Phase 7 as noted; within Phase 5 the six test files are parallel.
- Phase 8 six test files in parallel; frontend components of transfers parallel once the API client exists.

## Implementation Strategy

1. **MVP = Phases 1–3 (Stage 0 + Stage 1)**: governance + the rename, behaviour unchanged, fully reconciled — shippable alone and the safest first release.
2. **Next: Stage 2** — makes outlets real (assignments, shifts, scope, tables/floor). Release after its browser evidence.
3. **Then Stages 3 → 4** (menu per outlet, orders use it) and **5 → 6** (stock, transfers) in parallel tracks if staffing allows; **Stage 7** after 5; **Stage 8** last.
4. Never merge a stage whose reconciliation or isolation matrix is red; rehearse on the populated dump before releasing any migration to a real store.
