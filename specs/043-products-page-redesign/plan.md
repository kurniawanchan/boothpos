**Classification**: INTERNAL

# Implementation Plan: Products Page Redesign

**Branch**: `043-products-page-redesign` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/043-products-page-redesign/spec.md` (Q1 = C outlets + releases + approvals, Q2 = B real item types, Q3 = B versioned recipe beside the legacy BOM).

## Summary

Replace the Products screen with the design's Produk module: a list (grid or table) with four figures, item-type tabs, search and filters; a dedicated item page with variants and prices, modifier links, availability, recipe cost and margin, and price history; and the actions around it — mark sold out (86), discontinue, bulk price change, variants, modifier links, recipe versions, and a menu release flow with approval for large price differences.

The size and the outlet dependency drive the shape of the plan. Almost everything **except the outlet dimension** can be built on the single-machine install now; per-outlet behaviour waits for the 042 Stage B outlet foundation. So the plan has three stages:

| Stage | Contents | Needs the constitution amendment? |
|---|---|---|
| **A — Products module on one outlet** | schema + services (statuses, item facts, material kinds, reasons, availability marks, price history, modifier link rules, recipes), the **release engine and approval path in single-target form** (off by default, switched by a setting), list + figures + tabs, item page, 86, discontinue, bulk price, variants, modifier links, recipe drawer | **No** |
| **B — Outlet dimension** | per-outlet availability, quota, price overrides, release targets and delivery state, outlet-scoped reads, per-outlet chips and columns | **Yes** — and it consumes 042 Stage B Phase 7 (outlets, scope, selector); this plan adds nothing to that foundation |
| **C — Polish** | accessibility, evidence, documentation | no |

**The release engine is built in Stage A** because it does not need outlets (its single target is "the whole install"); Stage B only adds *which outlets* it reaches. A new install-level setting `menu_release_enabled` (default **off**, so booth installs behave exactly as today: edits apply immediately and are only recorded) turns the release banner, release dialog and approval requirement on.

## Technical Context

**Language/Version**: PHP ^8.3, Laravel ^13.17; Vue ^3.5.13, Vite, Tailwind v4 CSS-first (FACT, `composer.json`/`package.json`)
**Primary Dependencies**: Sanctum ^4.0; Pinia, vue-i18n, vue-router ^4.5; no new packages
**Storage**: MySQL 8 only; DBs `warkop_wjm` / `warkop_wjm_test`
**Testing**: PHPUnit on real MySQL (`-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array`); Vitest + Testing Library; mandatory real-browser check (Constitution II)
**Target Platform**: shopkeeper laptop (Docker or native); one shared server after the amendment
**Project Type**: web application (Laravel API + Vue SPA in one repository)
**Performance Goals**: list first page ≤ 2 s on the baseline laptop with 500 items (SC-002); bulk-price preview ≤ 3 s for 100 variants (SC-004); the list makes one request for rows and one for figures, never one per card (FR-023); the 86 availability poll is a lean indexed query
**Constraints**: main bundle ≤ measured start-of-branch main chunk + 10 kB (SC-007; note the 042 bundle is already over its own budget, so the baseline for 043 is **its measured start**); every raw query keeps explicit `data_mode`; recorded `order_items` keep their own price/cost snapshots; no new stock writer (`StockService::applyMovement()` unchanged)
**Scale/Scope**: ~500 items, ~2,000 variants, a few outlets (ASSUMPTION; hosting/outlet counts are still an open owner decision from 041)

FACTS this plan relies on (verified 2026-10-11, details in [research.md](research.md)):

- `/products` is one table with seller/category filters, Detail/Edit/Deactivate, no grid, tabs, status filter, bulk selection or detail page; "Apply markup" is client-side only inside the edit drawer.
- Product has no short name, tax category, preparation time, draft/discontinued state, sold-out mark, daily quota; variants hold the live `sell_price`/`cost_price`; price edits leave only an `activity_logs` row (`price_changed`).
- Order and tab creation reject inactive variants in two places (`OrderService`, `TabService`); product activity is `products.is_active`, soft delete is used.
- Modifier groups hold `is_required/min/max` on the **group**; the product↔group pivot has no per-link columns; groups are edited on the Dining-setup screen.
- The BOM is per variant, from purchase-order lines, whole-number quantities, with a "complete" action that syncs `cost_price`; materials have `unit` but no kind and no per-material cost other than vendor prices.
- No outlet, release, approval or reason-code concept exists in backend or database; `ReasonDialog.vue` (041) already supports coded reasons against a not-yet-existing `GET /reason-codes`.

## Constitution Check

*GATE: must pass before Phase 0 research; re-checked after Phase 1 design.* Evaluated against Constitution **v1.0.0** as in force (amendment 2.0.0 is a draft).

| Principle / section | Stage A | Stage B | Notes |
|---|---|---|---|
| I. Single write path, no duplicated logic | PASS | PASS | One `PriceService` writes variant prices and `price_history`; one `MenuAvailability` answers "can this be ordered" for orders, tabs, the POS list and the 86 poll; one `ApprovalService`; one `ReleaseService` applies changes; one `RecipeCost` formula; `RecipeService` is the only writer of `cost_price` for recipe-driven variants (as `VariantBomService` is for BOM-complete ones). |
| II. Verify, don't assume | PASS | PASS | Characterisation tests of the current ordering rules and price edit behaviour before refactoring them; parity tests (list figures = rows; recipe cost = hand formula); real-browser check of every screen and dialog. |
| III. UX consistency | PASS | PASS | Tokens only, shared kit; per-outlet controls, release banner and approval controls hidden when not applicable (hidden, not disabled); Indonesian source copy with English parity. |
| IV. Security | PASS | PASS | Prices, costs and bulk results recomputed on the server; all authorisation server-side (new action-level permissions next to menu keys); `activity_logs` rows written inside the same transaction as 86, discontinue, price, release, approval and recipe writes; recorded order prices/costs immutable; self-approval impossible. Stage B adds outlet scope and the amendment's rule that outlet is never taken from a request body. |
| V. Performance | PASS | PASS | Eager-loaded list (variants, modifier names, availability) in a fixed number of queries; figures in one endpoint; availability poll cached ≤ 2 s; preview computed set-based. |
| **Stack & Environment: "single local machine per store, no multi-tenancy"** | PASS | **VIOLATION — amendment required** | Stage B needs several outlets on one data set. Same gate as 042 T001. |
| Documentation & Change Discipline | PASS | PASS | OpenAPI in the same commit as each route; dated PRD and `CLAUDE.md` notes for statuses, 86, releases, approvals, recipes. |

**Gate result**: Stage A passes unconditionally. Stage B is conditionally cleared by the owner's Q1 = C but **must not start before the 042 amendment (T001) is ratified and 042 Phase 7 is done.**

**Re-check after design (Phase 1)**: unchanged. The design adds no second server and no cross-customer sharing; per-outlet tables carry a nullable `outlet_id` that Stage B backfills to the default outlet.

## Project Structure

### Documentation (this feature)

```text
specs/043-products-page-redesign/
├── spec.md
├── plan.md                      # this file
├── research.md                  # Decisions D1–D18
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── api-products.md          # list, figures, detail, 86, discontinue, facts, variants, modifier links, price history
│   ├── api-menu-release.md      # price changes (bulk), menu changes, releases, approvals, reasons, availability poll, settings
│   ├── api-recipes.md           # recipe versions, cost and margin
│   └── ui-contracts.md          # list, grid/table, detail page, drawers, dialogs, new kit pieces
├── checklists/requirements.md
└── tasks.md                     # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Support/
│   ├── MenuAvailability.php        # NEW: the one "can this be ordered" rule (status, 86 mark, outlet in B)
│   ├── PermissionCatalog.php       # NEW: action-level permission keys next to menu keys
│   └── RecipeCost.php              # NEW: portion cost / food cost % / margin formula (pure)
├── Services/
│   ├── PriceService.php            # NEW: set variant price, write price_history, honour approval/release mode
│   ├── BulkPriceService.php        # NEW: scope → preview → batch (all-or-nothing)
│   ├── ReleaseService.php          # NEW: collect/apply/publish menu changes, due-release publishing
│   ├── ApprovalService.php         # NEW: request, approve, reject; requester ≠ approver
│   ├── AvailabilityService.php     # NEW: 86 mark/undo, expiry at business-day end
│   ├── RecipeService.php           # NEW: versions, variant coverage, cost sync (only writer of recipe-driven cost_price)
│   ├── ProductCatalogQuery.php     # NEW: list/figures/tabs/quality flags in few queries
│   ├── OrderService.php, TabService.php   # EXTEND: use MenuAvailability instead of is_active checks
│   └── ModifierResolver.php        # EXTEND: link rule overrides group rule
├── Models/
│   ├── PriceHistory.php, MenuChange.php, MenuRelease.php, Approval.php, ReasonCode.php   # NEW
│   ├── ItemAvailabilityMark.php, Recipe.php, RecipeLine.php                              # NEW
│   └── Product.php, Material.php, Role.php                                                # EXTEND
├── Http/Controllers/Api/
│   ├── ProductController.php       # EXTEND: list filters/figures/detail; facts
│   ├── ProductAvailabilityController.php, ProductLifecycleController.php   # NEW: 86, discontinue/reactivate
│   ├── PriceChangeController.php, MenuChangeController.php, MenuReleaseController.php, ApprovalController.php   # NEW
│   ├── ReasonCodeController.php, RecipeController.php, MenuAvailabilityController.php   # NEW
│   └── ModifierLinkController.php, VariantBatchController.php   # NEW
database/migrations/                 # dated after 2026_11_04 (the 042 range) — see data-model.md
routes/api.php · docs/openapi-pos-mvp.yaml (same commit as each route) · tests/Feature/*
resources/js/
├── views/ProductsView.vue (rewritten: figures, tabs, grid/table), views/ProductDetailView.vue (NEW, route /products/:id)
├── components/product/…            # NEW: ItemCard, ItemGrid, ItemTable, AvailabilityChips, ReleaseBanner, SoldOutDialog, DiscontinueDialog,
│                                   #      BulkPriceDrawer, VariantsDrawer, ModifierLinksDrawer, RecipeDrawer, PriceHistoryTimeline, ReleaseDialog, ApprovalList
├── components/page/ViewToggle.vue  # NEW kit piece
├── api/products.js (extend), api/menu.js, api/recipes.js, api/reasons.js
├── router/index.js (+ /products/:id), locales id/en
└── views/settings/ReasonCodesSection.vue, MenuReleaseSection.vue (NEW)
qa-tests/                            # component + unit tests per piece
```

**Structure Decision**: web application in the existing repository; business logic in `app/Services` and `app/Support` (Constitution I); new UI pieces in the 041 page kit and `components/product/`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Stack constraint (Stage B) | Per-outlet availability, prices and release targets need several outlets on one data set | One install per outlet would need replication of the menu; the 042 amendment already covers the shared-server shape |
| A release engine built before outlets exist | The design's banner, version and "masukkan ke rilis" are the same engine with one target; building it once avoids a rewrite when outlets arrive, and it is off by default | Building releases only in Stage B would leave Stage A with immediate edits that must later be migrated into changes |
| `status` derived from `is_active` + `published_at` + `discontinued_at` instead of replacing `is_active` | `is_active` is read by POS, imports, reports and tests; replacing it is a wide change with no user value | Two stored sources of truth would drift; deriving the three-state status from three columns keeps one writer (the lifecycle service) |
| Lazy publishing of due releases (no scheduler) | The laptop install has no cron; a scheduled release must still take effect | A queue worker/cron would be new infrastructure; a due-release check at ordering entry points plus an optional artisan command is enough |

## Open decisions (owner)

| # | Decision | Default used in this plan |
|---|---|---|
| O1 | Are edits to modifier **group contents** (Dining setup) release-gated too? The design's banner lists "modifier Level Pedas" | Not in this feature: group contents stay immediate; **links** and items/prices are gated |
| O2 | Who may mark 86 and who may approve prices / publish releases | New permissions `menu.item.86` (owner, admin, inventory by default), `menu.price.approve` and `menu.release.publish` (owner, admin); editable per role in the Roles screen |
| O3 | Default for `menu_release_enabled` on existing installs | **Off** (behaviour unchanged); turned on in Settings, or by default for new restaurant installs |
| O4 | Recipe units: the design shows a unit per line; conversion between purchase unit and recipe unit | The line unit **is** the material's unit (no conversion); conversion is a later feature |
| O5 | Approval threshold basis: 15% of the **base** price (design text) | 15% default, configurable, compared with the base price |
| O6 | Ingredient stock deduction by recipe (the design text says deduction uses the recipe version) | **Not built** — no material stock is deducted on sale today; the recipe drawer must not promise it |

## Delivery order (input to /speckit-tasks)

1. **Foundation (A1)**: migrations, models, `PermissionCatalog`, `MenuAvailability` (characterise then replace the two `is_active` checks), `ReasonCode` + endpoint + defaults, settings.
2. **US1** list/figures/tabs/grid-table → **US2** detail page + price history + recipe summary.
3. **US3** 86 and **US4** discontinue (both with reasons, logs, ordering enforcement, POS poll).
4. **Price engine**: `PriceService` (replaces the direct `sell_price` write path), `ReleaseService`, `ApprovalService`; then **US5** bulk price and **US6** variants drawer; **US10** (single-target) release banner/dialog/approvals behind the setting.
5. **US7** modifier links → **US8** recipes.
6. **Stage B** (after 042 amendment + Phase 7): **US9** per-outlet settings and chips; the outlet half of **US10**.
7. Polish and evidence.
