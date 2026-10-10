# Implementation Plan: Restaurant Dine-in Ordering Core (Tables, Open Tabs, Modifiers, Kitchen Tickets)

**Branch**: `028-restaurant-dine-in-ordering` | **Date**: 2026-10-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/028-restaurant-dine-in-ordering/spec.md`

## Summary

Turn the one-step "pay immediately" sale into a restaurant-capable order lifecycle without disturbing any existing sale, report or settlement. Open tabs are a new `open` status on the existing `orders` table (Decision 1), so a paid tab *is* an ordinary completed sale and every report keeps working; only six places that could wrongly count an open order are guarded (Decision 5). Three slices on top of that:

1. **Tables & tabs** — `dining_tables`, a floor view, add-in-rounds tabs, move / merge / split bill, partial and split payments through the **unchanged** `PaymentRecorder`. One open tab per table is guaranteed by a MySQL generated-column unique index (Decision 10). Stock is deducted when an item is added to a tab and restored only if cancelled before preparation (Decision 4), with a `track_stock` flag for made-to-order dishes.
2. **Modifiers & notes** — modifier groups/options attached to products or categories, validated server-side, **snapshotted** onto the line; the effective unit price goes into `sell_price` so no report formula changes (Decision 7).
3. **Kitchen/bar tickets** — `stations`, a "send" action that splits a tab's unsent lines into per-station tickets, forward-only item statuses with who/when, station and waiter screens refreshed by 3-second **short polling** with a version token (Decision 6) — SSE/sockets are rejected because production serves PHP with `artisan serve`.

Access is by three new menu keys (`floor`, `kitchen`, `dining_setup`) granted to default roles by a data migration; sensitive actions (cancel after cooking starts, void a tab, hand over a tab) use the existing `isOwnerOrAdmin()` tier (Decision 8).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing), Vue 3 + Pinia + Vite + vue-i18n (existing)

**Primary Dependencies**: **None new.** Reuses `StockService`, `PaymentRecorder`, `ActivityLogger`, `OrderService::generateOrderNumber()` (made reusable), `RecordPaymentModal`/`PaymentPanel`, `ProductVariantPickerModal`, `ReceiptModal`, `BaseModal`, `StatusPill`, `useFocusTrap`. Printing uses `window.print()` + a print stylesheet.

**Storage**: MySQL 8 only. Eight additive migrations (6 new tables + 2 pivots, columns on `orders`, `order_items`, `payments`, `product_variants`, `categories`, `products`, plus a data migration for menu keys). See [data-model.md](./data-model.md).

**Testing**: PHPUnit feature tests in `tests/Feature/` against real MySQL (`warkop_wjm_test`, run only via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test`); Vitest component/unit tests in `qa-tests/`; mandatory real-browser pass of the quickstart scenario with the console checked. List in research.md Decision 13.

**Target Platform**: Single-store local install (native or Docker) on one machine; tablets/monitors reach it over the venue's LAN through a browser. No internet required.

**Project Type**: Web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: station/floor changes visible ≤ 5 s (95%) — 3 s poll + tiny unchanged-token query; tab add/remove < 300 ms; settle/split of a 100-line tab < 1 s; floor view = a constant number of queries regardless of table count (no N+1).

**Constraints**: Server computes all money (client never sends a price); no new always-on process; no streaming connections; MySQL-only constructs allowed (already mandatory); existing one-step sale, pre-orders, reports and receipts must behave identically (FR-041); `docs/openapi-pos-mvp.yaml` moves with each route group.

**Scale/Scope**: ≤ ~60 tables, ≤ ~10 concurrent screens (waiters + 2–3 stations), tabs up to ~150 lines, ≤ ~1,000 completed tabs/day; ~30 new endpoints, 4 new screens, 6 new models, 3 new menu keys.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design (below).*

| Principle | Pre-research | Post-design evidence |
|---|---|---|
| **I. Code quality** — logic in `app/Services/`; single write path per concern | PASS (intended) | Tab lifecycle/lines → `TabService`; payments/split/complete → `TabSettlementService`; send/status/cancel/feeds → `KitchenService`; option validation/price → `ModifierResolver`. Stock only via `StockService` (new `applyMovementIfTracked()` *delegates to* `applyMovement()` — no second write path). Payments only via `PaymentRecorder`. Logging only via `ActivityLogger`, inside the mutating transaction. Controllers only validate/delegate/shape. No speculative abstractions: no repository layer, no event bus. |
| **II. Testing** — real MySQL, `tests/Feature/` + `qa-tests/` + real browser | PASS (planned) | Per-slice backend suites, one test per impact-map row, a role × endpoint permission matrix, report-exclusion and DEMO/LIVE isolation tests, frontend component tests, and the quickstart browser run — Decision 13. Existing suites run unchanged. |
| **III. UX consistency** — design tokens, 422/409/403 convention, hide unavailable controls, Indonesian copy | PASS with a **pre-existing documented exception** | Tokens from `@theme` only. Errors flow through the central handler. Controls the role cannot use are hidden (menu-key checks mirror the server). UI copy is Indonesian **and** English because FR-044 requires both — the exception feature 002 already established and documented; new keys land in both locale files in the same change. Code comments/commit messages Indonesian. |
| **IV. Security** — server-side money, server-side authz, immutable snapshots, audit inside the transaction | PASS | Prices/deltas/totals computed server-side; option ids from the client are re-resolved against the product's effective groups. Every endpoint checks a menu key server-side; the matrix test proves it per default role. Modifier/price/name snapshots on `order_items` and `kitchen_ticket_items`. Cancel-after-cooking, tab void, handover and table/station deletes are logged **in the same transaction** as the change. No file uploads added. |
| **V. Performance** — no N+1, lean lists, code-split extras | PASS | Floor = one aggregate query + one grouped subquery; tab payload eager-loads lines + payments once; modifier groups on products are opt-in (`?with_modifiers=1`); the print view adds no dependency; polling is visibility-aware with an `unchanged` fast path. |
| **Stack** — MySQL 8, single machine, no cloud tier | PASS | Generated-column unique index and enum `MODIFY` are MySQL-only (allowed — SQLite is already unsupported). No new server process (Decision 6 rejects sockets/SSE). |
| **Documentation** — OpenAPI same commit; dated PRD note; WHY-comments | PASS (tasks assigned) | Tasks update `docs/openapi-pos-mvp.yaml` per route group, add a dated PRD note for the restaurant-variant scope, and CLAUDE.md/RUNBOOK. |

**Gate result**: no unjustified violations → proceed. Items the constitution makes us justify are in *Complexity Tracking*.

## Project Structure

### Documentation (this feature)

```text
specs/028-restaurant-dine-in-ordering/
├── plan.md              # This file
├── research.md          # Phase 0 — 14 decisions incl. the order-impact guard list
├── data-model.md        # Phase 1 — tables, columns, state machines, stock table, validation
├── quickstart.md        # Phase 1 — one-time setup + end-to-end verification script
├── contracts/
│   └── api.md           # Phase 1 — endpoints, payloads, error semantics, changed endpoints
├── checklists/
│   └── requirements.md  # Spec quality checklist (done)
└── tasks.md             # Phase 2 — created by /speckit-tasks (NOT by this command)
```

### Source Code (repository root)

```text
database/migrations/                                  # new, continuing after 2026_10_30_000001 (order is FK-load-bearing)
├── 2026_10_31_000001_create_dining_tables_table.php
├── 2026_10_31_000002_create_stations_table.php
├── 2026_10_31_000003_add_station_id_to_categories_and_products.php
├── 2026_10_31_000004_create_modifier_groups_options_and_pivots.php
├── 2026_10_31_000005_add_track_stock_to_product_variants.php
├── 2026_10_31_000006_add_tab_columns_to_orders_items_and_payments.php   # enum MODIFY, service_type, table_id, tab_label, split_from_order_id, open_table_key (generated+unique), modifiers/note/sent_at (payments.client_ref already exists from the ledger migration)
├── 2026_10_31_000007_create_kitchen_tickets_and_items_tables.php
└── 2026_10_31_000008_add_restaurant_menu_keys_to_default_roles.php

app/
├── Models/            DiningTable, Station, ModifierGroup, ModifierOption, KitchenTicket, KitchenTicketItem  (all HasDataMode)
│                      + edits: Order (status consts, table/tab relations, fillable), OrderItem (modifiers cast, sent_at),
│                        Payment (client_ref), ProductVariant (track_stock), Category/Product (station relation)
├── Services/          TabService, TabSettlementService, KitchenService, ModifierResolver, FloorQuery
│                      + edits: StockService (applyMovementIfTracked), OrderService (void guard, local_ref guard, tracked-stock calls,
│                        generateOrderNumber made reachable by TabService)
├── Http/Controllers/Api/   DiningTableController, StationController, ModifierGroupController, FloorController,
│                           TabController, TabLineController, TabPaymentController, KitchenController
│                           + edits: OrderController (index default filter, receipt gate), CashierSessionController (close guard + explicit
│                             completed filter), CustomerController (transactions filter), ProductController/CategoryController (station_id,
│                             track_stock, ?with_modifiers=1)
├── Http/Requests/     Store/Update for tables, stations, modifier groups; OpenTabRequest, AddTabLineRequest, UpdateTabLineRequest,
│                      CancelTabLineRequest, TabPaymentRequest, SplitTabRequest, MoveTabRequest, MergeTabRequest, VoidTabRequest,
│                      HandoverTabRequest, TicketItemStatusRequest   (authorize() = menu-key check, pattern 1 in CLAUDE.md)
├── Http/Resources/    TabResource, TicketResource, DiningTableResource, StationResource, ModifierGroupResource
│                      + edits: OrderResource (modifiers/note/table/tab_label — whenLoaded, no cost)
└── Support/           MenuKeys (+3 keys)

routes/api.php          # new route groups (contracts/api.md); every group behind existing auth + licence middleware
lang/{id,en}/restaurant.php                                        # validation + business messages
database/factories/    DiningTableFactory, StationFactory, ModifierGroupFactory, ModifierOptionFactory (HasDataMode-aware like peers)
database/seeders/      (follow-up, not 028) restaurant demo data

resources/js/
├── router/index.js                      # +4 routes (floor, tab, kitchen, dining-setup) with meta.menuKey
├── components/layout/AppSidebar.vue     # +'restaurant-group' (Floor, Kitchen, Dining setup)
├── views/                               FloorView, TabView, KitchenView, DiningSetupView
├── components/restaurant/               TableCard, TabLinesList, ModifierPickerModal, SplitBillModal, MoveTabModal, MergeTabModal,
│                                        StationBoard, TicketCard, PrintableTicket, TablesAdmin, StationsAdmin, ModifierGroupsAdmin
├── api/                                 diningTables.js, stations.js, modifierGroups.js, floor.js, tabs.js, kitchen.js
├── stores/                              floor.js, kitchen.js
├── composables/usePolling.js            # visibility-aware, in-flight guard, version token, self-healing
├── locales/{id,en}.json                 # +floor.*, kitchen.*, diningSetup.* namespaces (both files, same change)
└── views/SessionView.vue, composables/useSalesFilters.js   # edits: 409 open-tabs handling; harden to status==='completed'

tests/Feature/          DiningTablesTest, StationsTest, ModifierGroupsTest, TabLifecycleTest, TabLinesTest, TabModifiersTest, TabStockTest,
                        TabSettlementTest, TabSplitMergeMoveTest, KitchenTicketsTest, TabVoidAndCancelTest, TabImpactGuardsTest,
                        TabPermissionsTest, TabReportsExclusionTest, TabDataModeTest, MenuKeysMigrationTest
                        + new cases in OrderTest / CashierSessionTest only (shift-close guard)
qa-tests/               component/{FloorView,TabView,ModifierPickerModal,KitchenView}.test.js, unit/{usePolling,localeParity}.test.js

docs/                   openapi-pos-mvp.yaml (+routes), PRD dated note, RUNBOOK (default event, PHP workers), CLAUDE.md summary
docker/php/entrypoint.sh, docker/store/entrypoint.sh   # PHP_CLI_SERVER_WORKERS (Decision 6 — verify first, risk 1)
```

**Structure Decision**: Single existing web-application repo (Laravel API + Vue SPA). New code mirrors the established per-resource layout (controller + FormRequest + Resource + Service + feature test + api module + view), so nothing is introduced that a maintainer of the existing codebase would not recognise.

## Delivery slices (input for `/speckit-tasks`; each independently shippable and testable)

| Slice | Content | Spec stories | Ships value on its own? |
|---|---|---|---|
| **A. Foundation** | migrations 1,5,6,8 (orders/items/payments columns, `track_stock`, menu keys); `StockService::applyMovementIfTracked`; **the six impact guards + their tests** (Decision 5) | — | Yes: zero behaviour change, proves the guards before any tab can exist |
| **B. Tables** | `dining_tables`, setup screen tab, `GET /floor` (tables only) | US1 | Yes |
| **C. Tabs core** | open / add / edit / remove-unsent / close-empty / settle (partial+full, idempotent) / receipt; `TabView`, `FloorView`; shift-close guard + handover | US2 | **MVP** (with A+B) — a restaurant can already take orders and get paid |
| **D. Modifiers** | groups/options/pivots, resolver, picker, snapshots on receipts/lines | US3 | Yes |
| **E. Kitchen** | stations, station assignment, send, tickets, status flow, station screen, polling, print view, `served` from floor | US4 | Yes |
| **F. Move/merge/split** | `move`, `merge`, `split` | US5 | Yes |
| **G. Cancel/void** | cancel-after-prep, tab void, activity-log entries, stock rules for cancellation | US6 | Yes |
| **H. Hardening & docs** | permission matrix, report-exclusion + DEMO/LIVE tests, OpenAPI/PRD/RUNBOOK/CLAUDE.md, `PHP_CLI_SERVER_WORKERS` decision, full quickstart in a real browser | all | — |

Order constraint: **A before everything**; B before C; C before D/E/F/G; E before G's kitchen branch. (Rationale: the highest risk is an open order leaking into money figures, so its guards ship and are proven first.)

## Complexity Tracking

> Items the constitution asks us to justify (additions beyond the simplest possible design).

| Addition | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Generated column + UNIQUE index `orders.open_table_key` | FR-004 must hold when two waiters open the same table at once | "Check then insert" has a race window; a stored `is_occupied` flag can drift. This is one column and one index, enforced by the database (Decision 10). |
| Separate `kitchen_tickets` / `kitchen_ticket_items` tables instead of status columns on `order_items` | Preparation lifecycle, snapshots of cancelled items, per-station routing | Putting prep state on `order_items` would force a hidden "ignore cancelled" filter into every report query that reads `order_items` and couple the kitchen to the money model (Decision 2). |
| New `usePolling` composable | There is no polling code anywhere yet; three screens need identical, correct behaviour (visibility, recovery, in-flight guard) | Copy-pasting `setInterval` per screen would repeat the stale-screen and leak bugs the spec's edge cases call out. One composable, one test. |
| `PHP_CLI_SERVER_WORKERS` in both entrypoints | Station polls must not queue behind a long report/export on the single-process built-in server | Leaving it single-process makes a 5-second export freeze every kitchen screen. **Flagged as UNVERIFIED** (research risk 1) — the task verifies it first and drops it if it misbehaves. |
| `StockService::applyMovementIfTracked()` | Made-to-order dishes need to skip stock without fake inventory; retail and tab flows must treat them identically | Branching on `track_stock` inside each caller duplicates the rule; changing `applyMovement()`'s contract would touch every existing caller. |

## Post-design re-check

All five principles still pass after Phase 1: the design adds no second write path for stock, payments or the activity log; the largest risk (open tabs leaking into money figures) is reduced to a named list of six backend guards (plus two frontend hardening items), each with a dedicated test, shipped first (Slice A). No new gate violations were introduced by the data model or contracts.
