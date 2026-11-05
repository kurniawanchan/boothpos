# Tasks: Seller-Scoped User Access

INTERNAL

**Input**: `specs/058-seller-scoped-user-access/` (plan, spec, research D1–D10, data-model, contracts, quickstart)
**Tests**: INCLUDED. The project constitution (II) requires backend tests on real MySQL and frontend tests plus a real-browser check. Run backend tests only with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=...`, one run at a time.
**Format**: `- [ ] T### [P?] [Story] Description with file path`. [P] = different files, no dependency on an unfinished task.

## Phase 1: Setup

- [X] T001 Confirm the baseline is green before changes: run `--filter=ReportDataModeIsolationTest` and `--filter=MenuActionsRouteCoverageTest`; note the known Code128 failures so they are not mistaken for regressions
- [X] T002 [P] Read the files the survey skipped and record findings in `specs/058-seller-scoped-user-access/research.md` under "Open items": `app/Services/PreorderExportImportService.php`, `app/Services/CustomerExportImportService.php` (or equivalent), `app/Services/DemoClone/CloneRegistry.php`, `resources/js/components/**/PillFilterRow.vue`, `app/Support/MasterDataSheets.php`

## Phase 2: Foundational (blocks every story)

- [X] T003 Create migration `database/migrations/2026_11_24_000001_add_artist_id_to_users_and_customers.php`: `users.artist_id` nullable FK to `artists` restrictOnDelete + index; `customers.artist_id` nullable FK nullOnDelete. No backfill; never rename the date prefix
- [X] T004 Add `artist()` relation, `isSellerScoped()` and `artist_id` to `$fillable` in `app/Models/User.php`; add `users()` relation in `app/Models/Artist.php`. Do NOT add any scope to User
- [X] T005 Create `app/Support/SellerGate.php`: `current(): ?int` (authenticated user's `artist_id`; null in CLI/queue/seeder, when unauthenticated, or when the user's role is Owner, D11), `applyToItems($query, string $column)` for Eloquent and `DB::table` builders, `visibleOrderIds()` / `visiblePreorderIds()` subqueries, `productIdsSubquery()` (includes soft-deleted products)
- [X] T006 [P] Create `app/Models/Concerns/SellerScope.php` family (named scope classes, like `DataModeScope`, so `withoutGlobalScope(SellerScope::class)` is possible) plus trait `app/Models/Concerns/HasSellerScope.php` with per-model rule hooks from data-model.md
- [X] T007 Apply the scope per data-model table to: `Artist`, `Product`, `ProductVariant`, `StockMovement`, `ProductVariantBomLine`, `Order`, `Preorder`, `Customer`, `PurchaseOrder`, `SellerSettlement`, `ArtistSettlement` in `app/Models/*.php`. Leave `OrderItem`/`PreorderItem` relations unscoped (Q1:B)
- [X] T008 Create `app/Http/Middleware/EnsureNotSellerScoped.php` returning `403 {code:'seller_scope_forbidden'}` and register alias `seller.forbidden` in `bootstrap/app.php`
- [X] T009 [P] Write `tests/Feature/SellerScope/SellerScopeFixture.php` (shared helper trait, NOT named put/get/seed/call): sellers A and B, products/variants/stock, an A-only sale, a B-only sale, a mixed A+B sale, pre-orders of each, customers, a PO and a settlement per seller, users `scopedA`, `plain`
- [X] T010 [P] Write `tests/Feature/SellerScope/SellerGateTest.php`: null with no user and in CLI; id for a scoped user; `withoutGlobalScope` releases it; existence-check paths still unscoped

**Checkpoint**: scopes load with no behaviour change for users without a seller (run T001 suites again).

## Phase 3: User Story 1, owner assigns a user to a seller (P1)

**Goal**: assign, change, clear a user's seller; visible in list/detail; guarded and logged.
**Independent test**: assign `kasir01` to seller A, reload the user list, see it; clear it.

- [X] T011 [P] [US1] Tests (incl. Owner role: assigning a seller → 422, role→Owner clears the link, an Owner with a stale `artist_id` still sees everything) `tests/Feature/SellerScope/UserSellerAssignmentTest.php`: assign/change/clear, non-owner refused, nonexistent/soft-deleted/other-mode seller → 422, activity log `user_seller_changed` written in the same transaction, `GET /users?artist_id=` filter
- [X] T012 [US1] Add `artist_id` rules (refuse any value when the chosen role is Owner, 422; `UserController::update` clears `artist_id` when the role becomes Owner)  to `app/Http/Requests/StoreUserRequest.php` and `UpdateUserRequest.php` (`Rule::exists('artists','id')->whereNull('deleted_at')` plus explicit `data_mode`, because `exists` bypasses scopes)
- [X] T013 [US1] Update `app/Http/Controllers/Api/UserController.php`: persist `artist_id`, `artist_id` list filter, include in the `ActivityLogger` old/new values inside the transaction
- [X] T014 [P] [US1] Add `artist {id,name,code}` to `app/Http/Resources/UserResource.php`
- [X] T015 [US1] Add third delete guard in `app/Http/Controllers/Api/ArtistController.php` (409 `master_data.artist_delete_has_users`) and lang strings in `lang/id/*.php` and `lang/en/*.php`
- [X] T016 [P] [US1] Users import/export: optional `artist_code` column in `app/Support/MasterDataSheets.php`, `app/Services/MasterDataExportService.php` (users), `app/Services/MasterDataImportService.php` (`validateUsers`/`applyUsers`, blank = unchanged); extend `RoleMenuActionsImportExportTest`-style test
- [X] T017 [P] [US1] SPA: seller select (hidden/cleared when the Owner role is selected) + list column + filter in `resources/js/views/UsersView.vue`, data from `resources/js/api/users.js` and `api/artists.js`; test in `qa-tests/`
- [X] T018 [US1] Run `UserSellerAssignmentTest`, existing `User*` and `Artist*` tests, `npm test`

**Checkpoint**: owners can manage the link; nothing is enforced yet.

## Phase 4: User Story 2, scoped user sees only their seller's data (P1)

**Goal**: every read surface narrowed; foreign records 404.
**Independent test**: quickstart steps 2–3 with the shared fixture.

### Tests first
- [X] T019 [P] [US2] `tests/Feature/SellerScope/CatalogStockReadTest.php`: products, variants, `variants/lookup?exact=1`, catalog JSON/Excel, stock movements, low stock, per-variant history, BOM read; foreign id → 404; filter options list only seller A
- [X] T020 [P] [US2] `tests/Feature/SellerScope/OrdersPreordersReadTest.php`: A-only and mixed order visible (whole order incl. other lines/payments), B-only 404, list/summary/export/neighbors/receipt/bulk-receipts/bulk-invoices, `GET /orders/{id}` cost fields absent
- [X] T021 [P] [US2] `tests/Feature/SellerScope/ReportsReadTest.php`: `reports/sales`, `sales/transactions` and export, sales-by-product (+ export, options), profit, artist-profit, artist-settlements (+ detail with foreign `{artist}` → 404), stock-by-artist, purchases, preorders report, generic export; totals equal the sum of visible own-line rows (SC-005); parity with `ReportDataModeIsolationTest` style
- [X] T022 [P] [US2] `tests/Feature/SellerScope/CustomersSettlementsPoReadTest.php`: customers list/history (visible via order or `artist_id` origin), seller settlements list/show/document/proof, purchase orders
- [X] T023 [P] [US2] `tests/Feature/SellerScope/ForbiddenSurfacesTest.php` and a route-coverage test `SellerScopeRouteCoverageTest.php` that fails when an `api/v1` route is neither scope-aware (documented list) nor behind `seller.forbidden`
- [X] T024 [P] [US2] `tests/Feature/SellerScope/SellerLifecycleTest.php`: deactivated/soft-deleted seller → login ok, empty data, `/auth/me` `seller_active:false`; seller changed mid-session applies next request; no-seller user unchanged (SC-004)

### Implementation
- [X] T025 [US2] Add `artist` / `seller_scoped` / `seller_active` to `AuthController::userPayload()` in `app/Http/Controllers/Api/AuthController.php`
- [X] T026 [US2] Raw-query sweep with `SellerGate::applyToItems()` beside each manual `data_mode`: `ReportController` (`sales`, `profit`, `artistProfit`, `artistSettlements`, `artistSettlementTransactions`, `stockByArtist`, `preorders`, `export`), `app/Services/SalesByProductReport.php`, `SellerSettlementBuilder.php`, `app/Support/PreorderRecognizedRevenue.php`. Keep `SettlementService::recalculateForEvent()` system-level and document why
- [X] T027 [US2] `app/Services/SalesTransactionsService.php`: header visibility via scope; `seller_items`, `items_gross/net`, summary and export rows count only own lines; remove cross-seller names from `artist_names` for non-own lines only in statistics, not in the whole-order detail
- [X] T028 [US2] Build filter options (`SalesByProductReport` artists filter, `stockByArtist`, `ArtistController::index`) from the scoped set so no other seller's name leaks
- [X] T029 [US2] Apply `seller.forbidden` in `routes/api.php` to: users, roles, settings, backups, activity-logs, master-data import/export, vendors, materials and vendor prices, sales-by-payment-method report, demo/clone-related routes; keep `ability:` middleware intact
- [X] T030 [P] [US2] `PreorderExportImportService` export (and customers export) restricted to visible rows; import by a scoped user forces own seller (per T002 findings)
- [X] T031 [P] [US2] `PosDraftController`/`PosDraftService`: lines of other sellers are dropped and flagged when a draft is opened
- [X] T032 [P] [US2] SPA: `resources/js/stores/auth.js` getters `sellerScoped`, `sellerActive`; hide forbidden menus in `AppSidebar.vue`; empty-view notice for an inactive seller (`resources/js/components/**`); seller pills/selects (`PosView.vue`, `ProductsView.vue`, `StockView.vue`, `PreordersView.vue`, `ReportsView.vue`, `SettlementsView.vue`, `PurchaseOrdersView.vue`, `SalesView.vue`, `DashboardView.vue`) show only the user's seller; Indonesian copy in `resources/js/locales/id.json` and `en.json`; `localeKeys.test.js` stays green
- [X] T033 [US2] Run T019–T024 until green, then the whole backend suite serially and `npm test`

**Checkpoint**: MVP, a scoped user reads only their seller (plus whole visible orders).

## Phase 5: User Story 3, no cross-seller writes (P2)

**Goal**: scoped user cannot create/change/delete another seller's records.
**Independent test**: quickstart step 4.

- [X] T034 [P] [US3] `tests/Feature/SellerScope/CrossSellerWriteTest.php`: product create forces own seller and a different `artist_id` → 422; update/delete/image/variant endpoints on a foreign product → 404; `POST /stock/adjust` with a foreign variant → 404 and no movement; `POST /orders` and pre-order create with a foreign variant → 404, nothing written; BOM add/copy-from/copy-out with a foreign variant or PO line → 404/409; PO create/update seller forced; settlement `preview`/`store` forced; customer create stamps `artist_id`; payments/void/verify on an invisible order → 404
- [X] T035 [US3] Force `artist_id` in `ProductController::store`, `PurchaseOrderService` create/update, `StoreSettlementRequest`/`SellerSettlementController::store|preview`; reject a different supplied value with 422
- [X] T036 (verified by `CrossSellerWriteTest`; `PaymentService` `client_ref` lookup left unscoped on purpose: UUIDs are unguessable and a hit on another target only answers 422 without revealing it) [US3] Verify `StockController::adjust`, `OrderService`, `PreorderService`, `VariantBomController::copyFrom`, `MaterialController::storeBomLine` resolve variants through scoped lookups; fix any that use `withoutGlobalScopes()`; audit `PaymentService.php:58` `client_ref` lookup so a scoped user cannot probe other sellers' payments
- [X] T037 [US3] Stamp `customers.artist_id` on create by a scoped user in `app/Http/Controllers/Api/CustomerController.php`; customer import by a scoped user is refused or forced
- [X] T038 [P] [US3] SPA: seller field hidden and pre-filled on the product/PO/settlement forms for scoped users; test in `qa-tests/`
- [X] T039 [US3] Run `CrossSellerWriteTest`, then the whole backend suite serially

## Phase 6: Polish and cross-cutting

- [X] T040 Update `app/Services/DemoClone/CloneRegistry.php` and allowlists in `CloneRegistryDriftTest` for `users.artist_id` / `customers.artist_id`; run `CloneRegistryDriftTest`
- [X] T041 Update `docs/openapi-pos-mvp.yaml` (users, auth/me, 404/403 behaviours, `seller_scope_forbidden`, new 409) in the same commit as the routes
- [X] T042 [P] Add dated notes: CLAUDE.md section "Seller-scoped users (feature 058)" (gate, header-visibility rule, refused surfaces, raw-query rule, Q1:B trade-off) and a PRD §10.2 note that this is operate-within-seller, not the cut self-service portal
- [X] T043 [P] Query-count/N+1 check on `GET /orders`, `GET /products`, `GET /stock/movements` as a scoped user (warm-up call first); record timings in research.md
- [ ] T044 (OPEN: browser tools unavailable. Partly covered 2026-11-05 by an in-process HTTP run on the dev database with a throwaway scoped Admin: own products 8 of 31, orders 36 of 107, pre-orders 3 of 40, customers 3 of 40, sales net 825,000 vs 6,304,000, admin surfaces 403, foreign product 404, temp users deleted. The SPA screens themselves are still unseen.) Real-browser check (constitution II) following quickstart.md as `kasir01` on Sales, POS, Stock, Reports, Dashboard; console clean. If browser tools are unavailable, say so explicitly in the PR instead of claiming it
- [X] T045 (2026-11-05: backend 1767 passed / 9 failed, all `Code128Test` reference-library cases that already fail on the clean base because `picqer` is `require-dev`; `npm test` 1195 passed; `npm run build` OK) Full verification: backend suite serially (inside container with the `-e` flags), `npm test`, `npm run build`; report failures honestly

## Dependencies and order

- Phase 1 → Phase 2 (T003 → T004; T005 → T006 → T007; T008 independent; T009/T010 after T007).
- US1 depends on T003, T004 only; it can ship alone but enforces nothing.
- US2 depends on Phase 2 and benefits from US1 for assigning users in tests (fixtures can set `artist_id` directly).
- US3 depends on US2's scopes (T007, T026).
- Polish after all stories.

## Parallel examples

- Phase 2: T006 with T008, T009, T010 once T005 exists.
- US2 tests T019–T024 are all [P] (separate files). Implementation T030, T031, T032 are [P] after T026–T029.

## Strategy

MVP = Phase 1 + 2 + US1 + US2. Ship US3 next (it hardens writes; until then writes are partly protected by scoped lookups already). Commit per phase; run the full suite serially after each story.
