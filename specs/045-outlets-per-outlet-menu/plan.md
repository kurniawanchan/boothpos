**Classification**: INTERNAL

# Implementation Plan: Outlets (replacing Sellers) — Outlet-Scoped Operations, Per-Outlet Menu, Stock and Transfers

**Branch**: `045-outlets-per-outlet-menu` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/045-outlets-per-outlet-menu/spec.md` (clarified: Q1 = C, Q2 = B, stock and transfers per outlet; home outlet confirmed).

## Summary

The existing **seller** record becomes the **outlet** (rename in place: table, model, address, menu key, wording) and the product becomes outlet-aware end to end: users are assigned to outlets, a cashier shift belongs to one outlet and stamps it on everything it creates, tables/floor/sales/dashboard/reports are outlet-scoped by **one server-side scope**, every product has a relation to every outlet (carried, price, 86), stock is a **balance per outlet** for items and materials, and stock moves between outlets through **two-step transfers** (dispatch → in transit → receive). Reports re-base on the selling outlet while history keeps its former attribution.

This is the largest change since the project began, so it is planned as **eight independently shippable stages**, each leaving a single-outlet install behaving exactly as today, each ending with a reconciliation (counts and totals before = after) and a browser check.

| Stage | Delivers | Spec stories / FRs | Gate |
|---|---|---|---|
| 0 | Constitution v2.0.0 ratified + dated notes | FR-029 | owner approves final wording |
| 1 | **Rename**: `artists`→`outlets` (table, model, API, page, menu key, Excel names, wording, licence setting) | US1, US2; FR-001…FR-009 | 0 |
| 2 | **Outlet foundation**: assignments, `OutletContext`/scope, selector, shift outlet, stamping, tables/floor/Sales board per outlet | US6 (stamping), US7, US8; FR-018…FR-025 | 1 |
| 3 | **Per-outlet menu**: item–outlet relation, outlet prices, per-outlet 86, Products UI | US3, US4, US5; FR-010…FR-017 | 2 |
| 4 | **Ordering uses outlet data**: price/availability resolution by the order's outlet | US6; FR-020 | 3 |
| 5 | **Stock per outlet** (items + materials) | US10; FR-031…FR-034 | 2 |
| 6 | **Transfers** | US11; FR-035…FR-040 | 5 |
| 7 | **Reports, dashboard, exports by selling outlet** | US9, US7 (board); FR-026, FR-027 | 2, 5 |
| 8 | Hardening: isolation matrix, reconciliation command, performance, full browser pass | all SCs | all |

Stages 3 and 5 can proceed in parallel after Stage 2; Stage 4 needs 3; Stage 6 needs 5; Stage 7 needs 5.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (API), MySQL 8 (required; the suite runs on real MySQL); Vue 3.5 + Vite, Tailwind v4 tokens, vue-i18n (id/en), Pinia.

**Primary Dependencies**: existing only. Reuses `StockService`, `MaterialStockService`, `PriceService`, `MenuAvailability`, `ReleaseService`, `ActivityLogger`, `PermissionCatalog`, `HasDataMode`/`DataModeScope` (pattern for the new scope), `FloorQuery`, `SalesFigures`, the page kit, `TopBar` (already accepts an `outlets` prop from 041), `usePolling`. No new package.

**Storage**: MySQL 8. Renames (`artists`→`outlets`, `artist_settlements`→`outlet_settlements`, `artist_id` columns split into `home_outlet_id` / `outlet_id`), new tables `outlet_user`, `outlet_item_settings`, `variant_outlet_prices`, `variant_outlet_stock`, `material_outlet_stock`, `stock_transfers`, `stock_transfer_lines`, new `outlet_id` columns on operational tables (full list in data-model.md), enum extension on stock movement types.

**Testing**: PHPUnit on MySQL (per-stage Feature tests; an **isolation matrix** test enumerating every outlet-scoped table; ledger/balance invariants incl. a randomised transfer sequence; a **reconciliation test** running the migrations over a populated fixture and comparing totals); Vitest component/unit tests; real-browser walk-through per stage with 3 outlets × the seeded roles.

**Target Platform**: single local machine per store (shape (a)) **or** one shared server (shape (b)) — the amendment adds (b); every stage must work in (a) with one outlet.

**Project Type**: web application (Laravel API + Vue SPA in one repo).

**Performance Goals**: Outlets page, Products list (500 items × 20 outlets), Floor/Tables board (60 tables per outlet) and the scoped Dashboard each < 2 s (SC-010); constant query count for floor/list endpoints; chips/prices for a page of items fetched in one batched query per relation.

**Constraints**: outlet is **never** accepted from a request body (server derives it); a user never receives another outlet's data on any route (lists, details, figures, exports); `StockService` / `MaterialStockService` stay the only balance writers; every migration re-runnable and reconciled; single-outlet installs show no outlet control and behave unchanged; Indonesian-first copy in both languages; audit rows inside the mutating transaction.

**Scale/Scope**: 1–20 outlets, ≤ 500 items, tens of users; ~45 front-end and ~60 back-end files touched by the rename; ~10 stock-writing call sites; 6 tables get `outlet_id` on the order side.

## Constitution Check

*Gate against `.specify/memory/constitution.md` v1.0.0 plus the draft v2.0.0 (`specs/042-dashboard-revamp/constitution-amendment.md`).*

| Principle | Check | Result |
|---|---|---|
| **Stack constraints (v1.0.0 "single local machine, no multi-tenancy")** | Directly contradicted by the feature. The owner has decided the amendment is ratified **as part of this feature** (spec Clarifications); Stage 0 applies it (version 2.0.0, Sync Impact Report, dated notes in CLAUDE.md/PRD). No stage ≥ 2 is released before Stage 0 is approved. Multi-tenancy across unrelated customers stays out of scope. | **GATED → resolved by Stage 0** |
| I. Single implementation | One `OutletScope`/`OutletContext`/`OutletFilter` for all scoping (no per-query `where`s); `StockService`/`MaterialStockService` remain the only balance writers (transfers call them); `PriceService::priceFor` and `MenuAvailability` the only price/availability resolvers; one report-attribution SQL helper; one `StockTransferService`. | PASS |
| II. Verify, don't assume | Per-stage tests on MySQL, isolation matrix, ledger invariants, reconciliation test, browser pass with evidence files per stage. | PASS (evidence per stage) |
| III. UX consistency | Tokens only; id + en for all copy; every outlet-specific control hidden (not disabled) for single-outlet installs and for roles without the permission; 422/409/403 conventions; empty/error states. | PASS |
| IV. Security | Outlet decided server-side from shift/assignment; `X-Outlet-Id` only narrows within the permitted set; transfer steps are separate action permissions checked against assignments; audit rows in-transaction; client money/price never trusted (outlet price resolved server-side); forged outlet ids refused. | PASS |
| V. Performance | Batched relations, constant query counts, indexes on `(outlet_id, …)`, cached total stock kept in the same transaction; board/list budgets in SC-010. | PASS (measure in Stage 8) |
| Docs discipline | OpenAPI updated with every route/response change in the same commit; PRD and CLAUDE.md dated notes (scope change); BUG-header comments where discovered. | PASS (task per stage) |

## Complexity Tracking

| Violation / complexity | Why needed | Simpler alternative rejected because |
|---|---|---|
| Constitution amendment (MAJOR) | The feature redefines the deployment premise | Building outlets without amending would violate the stack constraint |
| In-place rename of a widely used table and columns (`artists`, `artist_id` ×6 tables) | Owner decision: sellers **are** outlets; history and codes must survive | New `outlets` table + copy: duplicates identities and breaks references; alias views: hides the new meaning in the code |
| Two different "outlet" columns on order items (`home_outlet_id` snapshot vs `outlet_id` selling outlet) | Q2 = B needs the selling outlet while history keeps the item's former owner | One column cannot hold both meanings without ambiguity |
| Cached total stock kept beside per-outlet balances | Lists and POS read `current_stock` everywhere | Deriving totals per row on every list = N+1 / heavy joins |
| Staged delivery (8 stages) | Size and risk | One big-bang release cannot be reconciled or rolled back |

## Project Structure

### Documentation (this feature)

```text
specs/045-outlets-per-outlet-menu/
├── spec.md · plan.md (this) · research.md (D1–D22) · data-model.md · quickstart.md
├── contracts/
│   ├── api-outlets-rename.md      # old → new routes, params, fields, menu key, Excel names
│   ├── api-scope.md               # X-Outlet-Id, GET /me/scope, assignments, stamping rules
│   ├── api-menu-outlets.md        # item–outlet relation, outlet prices, per-outlet 86, price resolution
│   ├── api-stock-transfers.md     # per-outlet stock endpoints, transfers, invariants
│   └── ui-contracts.md            # selector, Outlets page, Products, Stock, Transfers, board
├── checklists/requirements.md
└── tasks.md                       # created by /speckit-tasks
```

### Source Code (repository root) — by stage

```text
# Stage 1 — rename
database/migrations/2026_12_01_*_rename_artists_to_outlets*.php        # table, columns, enum/menu key, setting key, roles
app/Models/Outlet.php (from Artist.php) · app/Policies/OutletPolicy.php · Http/Controllers/Api/OutletController.php
Http/Requests/{StoreOutletRequest,UpdateOutletRequest}.php · Http/Resources/OutletResource.php
app/Support/{LicenseGate,MenuKeys,MasterDataSheets,ReportSplit}.php · Services/{SettlementService,OrderService,TabService,…}
routes/api.php  (/outlets, /reports/* renames)  ·  resources/js/{api/outlets.js,views/OutletsView.vue,router,utils/navigation.js}
lang/*, resources/js/locales/{id,en}.json   (wording)   ·  docs/openapi-pos-mvp.yaml

# Stage 2 — foundation
database/migrations/*_create_outlet_user_and_add_outlet_id.php
app/Models/Concerns/{BelongsToOutlet}.php · app/Models/Scopes/OutletScope.php · app/Support/{OutletContext,OutletFilter}.php
app/Http/Middleware/ResolveOutletContext.php · Http/Controllers/Api/MeScopeController.php · UserController (assignments)
Services/{OrderService,TabService,TabSettlementService,KitchenService,PaymentRecorder,FloorQuery,SalesFigures,SalesTransactionsService}
resources/js/{stores/outlet.js,components/layout/OutletSelect (TopBar),api/client.js (header),views/UsersView (assignment)}

# Stage 3/4 — menu per outlet
database/migrations/*_create_outlet_item_settings_and_prices.php · Models/{OutletItemSetting,VariantOutletPrice}.php
Services/{PriceService::priceFor, AvailabilityService (outlet), ProductCatalogQuery, VariantBatchService, BulkPriceService, ProductDetailService}
Support/MenuAvailability.php · Http/Controllers/Api/{ProductController,MenuAvailabilityController,...}
resources/js/components/product/* · views/products/ProductFormDrawer.vue · ProductsView.vue · ProductDetailView.vue

# Stage 5/6 — stock and transfers
database/migrations/*_create_outlet_stock_and_transfers.php · Models/{VariantOutletStock,MaterialOutletStock,StockTransfer,StockTransferLine}.php
Services/{StockService,MaterialStockService (outlet param), StockTransferService, LowStockHistory}
Http/Controllers/Api/{StockController,StockTransferController} · Support/PermissionCatalog (stock.transfer.*)
resources/js/{views/StockView.vue,views/stock/TransfersView.vue,TransferDetail,NewTransferDrawer}

# Stage 7/8 — reports, reconciliation
Http/Controllers/Api/ReportController · Services/{SettlementService,DashboardService,AttentionService}
app/Console/Commands/OutletsReconcile.php (before/after totals)  ·  tests/Feature/{OutletIsolationMatrixTest,OutletReconciliationTest,TransferInvariantTest,...}
```

**Structure Decision**: the existing web-application layout; one cross-cutting feature delivered in stages. Models keep their own folders; the scope is a trait + global scope next to `HasDataMode`.

## Post-design Constitution re-check

The design in research.md / data-model.md / contracts keeps every row PASS **conditional on Stage 0**: one scope and one filter helper, no second balance writer, outlet always server-derived, transfers conserve stock (including recorded discrepancies), and single-machine single-outlet installs are untouched.
