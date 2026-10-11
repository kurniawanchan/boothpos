**Classification**: INTERNAL

---
description: "Task list for 043-products-page-redesign"
---

# Tasks: Products Page Redesign

**Input**: `specs/043-products-page-redesign/` — plan.md, spec.md, research.md (D1–D18), data-model.md, contracts/ (api-products, api-menu-release, api-recipes, ui-contracts), quickstart.md

**Tests**: INCLUDED. The constitution (Principle II) requires tests and a real-browser check; each story lists its tests first and they must fail before the implementation exists.

**Owner decisions applied**: Q1 = C (outlets + releases + approvals), Q2 = B (real item types), Q3 = B (versioned recipes beside the legacy BOM). Open decisions O1–O6 in plan.md use their stated defaults.

## Format: `- [ ] T### [P?] [US#?] Description with file path`

- **[P]** different files, no dependency on an incomplete task. **[US#]** story label (story phases only).
- Paths are relative to the repository root. Backend tests run in the container with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array`. **Do not edit PHP files while a backend test run is in progress** (a model change landing before its migration invalidates the run — seen in 042).
- Frontend commands: use `npx vitest run <file>`; the dev laptop can be very loaded — raise per-test timeouts rather than weakening assertions.

## Stages

- **Stage A** = Phases 1–12: no constitution gate (single default outlet; release/approval engine behind `menu_release_enabled`, default off).
- **Stage B** = Phases 13–14: **blocked until the 042 constitution amendment (042 T001) is ratified AND 042 Phase 7 (outlet foundation, 042 T049–T057) is done.** This feature adds nothing to that foundation.
- **Stage C** = Phase 15.

---

## Phase 1: Setup and baseline

- [X] T001 Record the baseline in `specs/043-products-page-redesign/baseline.md`: full backend run (expect the 042 figures: 1496 passed), full frontend run, `npm run build` main chunk size **at the start of this branch** (the 042 bundle is already over its own budget — SC-007 is measured from this number), and the latency of `GET /products?with_variants=1` on the dev stack
- [X] T002 [P] Write characterisation tests that pin TODAY's ordering rejections — an inactive product or an inactive variant refused on `POST /orders` and on adding a tab line, an active one accepted, unaffected by data mode — in `tests/Feature/OrderAvailabilityCharacterizationTest.php`; they must pass on the untouched code
- [X] T003 [P] Write characterisation tests that pin TODAY's price behaviour — `PUT /variants/{id}` changes `sell_price` immediately and writes a `price_changed` audit row, product import writes prices, a BOM-complete variant locks `cost_price` (409 `cost_price_locked_by_bom`) — in `tests/Feature/VariantPriceCharacterizationTest.php`
- [ ] T004 **GATE note (needs the product owner; blocks Phases 13–14 only)**: confirm 042 T001 (amendment ratified) and 042 Phase 7 are done before any Stage B task starts; record the confirmation in `specs/043-products-page-redesign/baseline.md`

---

## Phase 2: Foundational — schema, permissions, availability rule, reasons (blocks all stories)

- [X] T005 Migration `database/migrations/2026_11_05_000001_add_facts_and_lifecycle_to_products.php`: `short_name`, `tax_category`, `prep_minutes`, `is_retail`, `published_at`, `discontinued_at`; backfill `published_at = created_at` for all rows and `discontinued_at = updated_at` for inactive ones; guarded and re-runnable; rehearsal test `tests/Feature/ProductLifecycleBackfillTest.php`
- [X] T006 [P] Migration `database/migrations/2026_11_05_000002_add_kind_to_materials.php`: `materials.kind` enum (default `ingredient`), `standard_cost` decimal(14,4) null
- [X] T007 [P] Migration `database/migrations/2026_11_05_000003_create_reason_codes.php` with default rows (sold_out: out of stock, equipment broken, quality issue, other; discontinue: removed from menu, replaced, seasonal end, other; approval_reject: price too high, price too low, other) in id and en
- [X] T008 [P] Migration `database/migrations/2026_11_05_000004_create_item_availability_marks.php` (data-model; nullable `outlet_id`, index `(data_mode, expires_at, cleared_at)`)
- [X] T009 [P] Migration `database/migrations/2026_11_05_000005_create_price_history.php` and backfill one `created` row per existing variant from its current price and creation time
- [X] T010 [P] Migration `database/migrations/2026_11_05_000006_create_menu_changes_releases_approvals.php`: `menu_changes`, `price_change_batches`, `menu_releases`, `approvals`
- [X] T011 [P] Migration `database/migrations/2026_11_05_000007_add_link_rules_to_modifier_group_product.php` (`is_required`, `min_select`, `max_select`, `display_order`, all nullable)
- [X] T012 [P] Migration `database/migrations/2026_11_05_000008_create_recipes_tables.php`: `recipes`, `recipe_variants`, `recipe_lines`
- [X] T013 [P] Migration `database/migrations/2026_11_05_000009_add_permissions_to_roles.php` (`permissions` JSON) seeding owner/admin with all keys and inventory with `menu.item.86`; add the settings defaults `menu_release_enabled=false`, `price_approval_threshold_pct=15`, `price_rounding_default=500`
- [X] T014 Models with `HasDataMode` where data-model says so: `app/Models/ReasonCode.php`, `ItemAvailabilityMark.php`, `PriceHistory.php`, `MenuChange.php`, `MenuRelease.php`, `Approval.php`, `Recipe.php`, `RecipeLine.php`; extend `Product.php` (status accessor `draft|active|discontinued`, casts, `is_retail`), `Material.php` (`kind`), `ModifierGroup`/`Product` pivot columns, `Role.php` (`permissions` cast); test `tests/Feature/ProductStatusTest.php`
- [X] T015 `app/Support/PermissionCatalog.php` (keys `menu.item.manage`, `menu.item.86`, `menu.price.bulk`, `menu.price.approve`, `menu.release.publish`, `menu.recipe.edit`) and `User::hasPermission()` (owner/admin implicit via their seeded role permissions); accept `permissions` in the role create/update requests and expose the catalogue to the Roles screen; tests `tests/Feature/PermissionCatalogTest.php` (a role without the key is refused 403 on a guarded route; unknown keys rejected 422)
- [X] T016 Settings validation and exposure: add the three new keys to `app/Http/Requests/UpdateSettingsRequest.php` (bool, 0–100, `none|500`) and expose `menu_release_enabled` through `GET /settings/features` in `app/Http/Controllers/Api/SettingsController.php`; test `tests/Feature/MenuSettingsTest.php`
- [X] T017 `app/Support/MenuAvailability.php` — `isOrderable`, `assertOrderable`, `unavailableReason` (product active and not discontinued, variant active, no active 86 mark); replace the `is_active` checks in `app/Services/OrderService.php` and `app/Services/TabService.php` and in `GET /variants/lookup`; **T002 must pass unchanged**; tests `tests/Feature/MenuAvailabilityTest.php` (incl. 86 and discontinued, answer `409 item_unavailable`)
- [X] T018 Reason codes: `app/Http/Controllers/Api/ReasonCodeController.php`, requests, routes `GET/POST/PUT /reason-codes` (list open to logged-in users, writes `settings` menu, never delete a used code), labels in the caller's language; OpenAPI paths; test `tests/Feature/ReasonCodeTest.php`
- [ ] T019 [P] Frontend foundations: `resources/js/api/products.js` (extend), `api/menu.js`, `api/recipes.js`, `api/reasons.js`; `stores/settings.js` exposes `menuReleaseEnabled`; router entry `/products/:id` (name `product-detail`, menu key `products`) in `resources/js/router/index.js`; kit pieces `resources/js/components/page/ViewToggle.vue` and `components/product/AvailabilityChips.vue`; tests `qa-tests/component/ViewToggle.test.js`, `AvailabilityChips.test.js`, `qa-tests/unit/productsApi.test.js`
- [ ] T020 [P] Settings UI for reasons: `resources/js/views/settings/ReasonCodesSection.vue` mounted in `resources/js/views/SettingsView.vue`; Roles screen permission checkboxes in `resources/js/views/RolesView.vue`; i18n keys; tests `qa-tests/component/ReasonCodesSection.test.js`, `RolesView.test.js` (extend)

**Checkpoint**: migrations applied on the dev DB after a dry rehearsal; T002/T003 still green; one availability rule in use.

---

## Phase 3: Foundational — price, release and approval engines (blocks Stories 5, 6, 10; does not block 1–4, 7, 8)

- [X] T021 [P] Tests first for `PriceService` in `tests/Feature/PriceServiceTest.php`: sets `sell_price` and writes exactly one `price_history` row (old, new, source, user) and the audit row in one transaction; immediate mode applies, release mode creates a pending `menu_change`; a difference above the threshold becomes `awaiting_approval`; negative/zero prices refused; **T003 behaviour preserved in immediate mode**
- [X] T022 (done: PriceService is the single sell_price writer; import/export new columns added, `status` export-only) `app/Services/PriceService.php` — the only writer of `product_variants.sell_price`; route `ProductController::updateVariant`, `MasterDataImportService` (price columns, source `import`) and the variant-creation path through it; keep `cost_price` out of it; extend the import/export columns (short name, tax category, prep minutes, retail flag, status) appended after the existing ones so old files still import (`MasterDataSheets`, `MasterDataExportService`); test `tests/Feature/ProductImportNewColumnsTest.php`
- [X] T023 [P] Tests first for `ApprovalService` in `tests/Feature/ApprovalServiceTest.php`: a request above the threshold is `pending`; only a holder of `menu.price.approve` who is not the requester may approve/reject (`409 self_approval`); reject needs a reason code; terminal states; logged in the same transaction; threshold read from the setting
- [X] T024 `app/Services/ApprovalService.php` (request, approve, reject; the single approval path, kind `menu_price`)
- [X] T025 [P] Tests first for `ReleaseService` in `tests/Feature/ReleaseServiceTest.php`: a release applies prices/lifecycle/link changes by writing the live columns inside one transaction, marks changes `released`, writes `price_history` rows with `source=release` and the version, version numbers are sequential per data mode, `awaiting_approval` changes cannot be included (409 `changes_not_ready`), a scheduled release applies at the first call of `publishDue()` after its time, cancelling a scheduled release works, a failure rolls everything back
- [X] T026 `app/Services/ReleaseService.php` (collect, publish, `publishDue()`, cancel) with appliers per kind (`price`, `item_publish`, `item_discontinue`, `item_reactivate`, `modifier_link`) in `app/Services/Release/*Applier.php`; optional command `app/Console/Commands/PublishDueMenuReleases.php` (`menu:publish-due`)
- [X] T027 Lazy publishing hook: one helper `ReleaseService::publishDueCached()` (≤ 5 s cache keyed by data mode) called from the POS product list, `OrderService::create`, `TabService` line add, `GET /menu/availability` and the Products list controller; test `tests/Feature/LazyReleasePublishingTest.php` (one test per entry point with clock travel)

**Checkpoint**: engines work in both modes with services only; no UI yet.

---

## Phase 4: User Story 1 — Browse as grid or table with the figures (P1) 🎯 MVP

**Goal**: new header, four figures, item-type tabs, filters, grid/table, paging, availability chips.

**Independent Test**: items in several categories and statuses; each figure equals its rows; search/filter/sort/page; grid ↔ table keeps filters; reload restores.

### Tests first

- [X] T028 [P] [US1] `tests/Feature/ProductCatalogTest.php`: filters (q over name/short name/SKU/code prefix, category incl. children, status, quality), sorts, pagination 15, `counts` per tab independent of filters, derived status, summary shape, availability array, no N+1 (query count constant as rows grow), DEMO/LIVE isolation, `422` on bad filters, cashier may list but sees no cost
- [X] T029 [P] [US1] `tests/Feature/ProductSummaryTest.php`: active/total/draft/discontinued, 86-today examples, quality count and examples, `unreleased` null when releases are off; every number equals the list rows (SC-001)
- [X] T030 [P] [US1] `qa-tests/component/ProductsViewGrid.test.js`, `ProductsViewTable.test.js`, `ProductFigures.test.js`: figures from the summary, tabs with counts, grid card content, table columns and sorting, selection bar, view toggle persisted, URL round-trip, empty/error states, import/export and "Tambah item" still reachable

### Implementation

- [X] T031 [US1] `app/Services/ProductCatalogQuery.php` (rows with eager-loaded variants, modifier names and availability; tab counts; figures; quality flags) and the extended `ProductController::index` plus new `summary` in `app/Http/Controllers/Api/ProductController.php` and `app/Http/Requests/ProductIndexRequest.php`; routes `GET /products` (extend), `GET /products/summary`; OpenAPI
- [X] T032 [P] [US1] Item types on the backend: `materials` list filter `kind` and counts, retail products in the catalogue query, modifier-group count in `counts`; `MaterialController` + request accept `kind` and `standard_cost`; `ProductController` store/update accept `short_name`, `tax_category`, `prep_minutes`, `is_retail`; tests `tests/Feature/MaterialKindTest.php`, `ProductFactsTest.php`
- [X] T033 [P] [US1] `resources/js/components/product/ItemCard.vue`, `ItemGrid.vue`, `ItemTable.vue`, `ProductFigures.vue` (four cells, three when releases are off; "86 hari ini" and "Kualitas data" act as filters)
- [X] T034 [US1] Rewrite `resources/js/views/ProductsView.vue`: header and actions, figure strip, item-type `TabBar`, `FilterBar` with search/Kategori/Status/`ViewToggle`/"+ Menu item", grid or table, `BulkBar`, pager, URL state via `useQueryState`; non-menu tabs reuse the materials list (filtered by kind) and the modifier-groups list inside the frame; keep Export/Import and the existing product form drawer (`views/products/ProductFormDrawer.vue`, gaining short name, tax category, prep minutes, retail flag) reachable; update the existing `qa-tests/component/ProductsView.test.js` and `ProductsPageStructure.test.js` without weakening coverage
- [X] T035 [P] [US1] Materials form: kind select and standard cost in `resources/js/views/MaterialsView.vue`; i18n keys for the new strings via `specs/041-back-office-ui-redesign/tools/merge_locale.py`; `qa-tests/unit/localeKeys.test.js` and parity pass
- [ ] T036 [US1] Browser check of Story 1 (both languages, empty install and seeded 24 items, grid and table, every filter, reload, console clean) recorded in `specs/043-products-page-redesign/evidence-stage-a.md`

**Checkpoint**: MVP — the new list is live; actions come in later stories.

---

## Phase 5: User Story 2 — One page for everything about an item (P1)

**Independent Test**: open an item with variants, modifier links and a recipe; each section matches the data; back link restores the list query; direct address works.

### Tests first

- [X] T037 [P] [US2] `tests/Feature/ProductDetailTest.php`: extended `GET /products/{id}` (facts, variants, modifier links with effective rules, availability, `can` per caller, cost fields hidden from a cashier), `GET /products/{id}/price-history` (newest first, ends with `created`, paginated), 404 for another data mode
- [X] T038 [P] [US2] `qa-tests/component/ProductDetailView.test.js`: header and actions hidden per `can`, each section loads independently with its own error + Retry, back link keeps the list query, history timeline order, food cost % and margin shown when a recipe exists

### Implementation

- [X] T039 [US2] Extend `ProductController::show` and add `price-history` in `app/Http/Controllers/Api/ProductController.php` (+ `ProductResource`/`ProductVariantResource` additions with the `relationLoaded` guards documented in CLAUDE.md); route `GET /products/{id}/price-history`; OpenAPI
- [X] T040 [P] [US2] `resources/js/views/ProductDetailView.vue` and sections `components/product/ItemFacts.vue`, `VariantsSection.vue`, `ModifierSection.vue`, `AvailabilitySection.vue`, `RecipeSection.vue` (shows the existing BOM summary for BOM-driven items), `PriceHistoryTimeline.vue`
- [ ] T041 [US2] Wire list → page navigation (card, row, SKU) and keep the legacy `ProductDetailModal` for the stock screen and history entry points (`resources/js/components/product/ProductDetailModal.vue` unchanged); browser check of Story 2 appended to `evidence-stage-a.md`

---

## Phase 6: User Story 3 — Mark sold out (86) and bring it back (P2)

**Independent Test**: mark 86 with a reason → next order refused, open tab untouched, POS greys the item within 5 s, ends at the business-day boundary, undo works, logged.

### Tests first

- [X] T042 [P] [US3] `tests/Feature/ProductSoldOutTest.php`: reason required and from the right category (422), permission `menu.item.86` (403), mark expires at the business-day end (clock travel), undo, double-mark 409, discontinued 409, existing order lines untouched, new order/tab line refused `409 item_unavailable`, audit rows in the same transaction, DEMO/LIVE
- [X] T043 [P] [US3] `tests/Feature/MenuAvailabilityPollTest.php`: `GET /menu/availability` returns etag/`menu_version`/sold-out ids, `304` on a matching etag, reflects a new mark within the cache window, scoped to data mode
- [X] T044 [P] [US3] `qa-tests/component/SoldOutDialog.test.js`, `qa-tests/unit/useMenuAvailability.test.js` (polls every 5 s, pauses when hidden, marks cards), `qa-tests/component/PosViewAvailability.test.js`

### Implementation

- [X] T045 [US3] `app/Services/AvailabilityService.php` (mark, undo, expiry from `BusinessDay`, audit) and `app/Http/Controllers/Api/ProductAvailabilityController.php`, `MenuAvailabilityController.php`; routes `POST/DELETE /products/{id}/sold-out`, `GET /menu/availability`; OpenAPI
- [X] T046 [P] [US3] `resources/js/components/product/SoldOutDialog.vue` on `ReasonDialog` coded mode (text states only what the system does; reason required); wire into the item page header and the list; undo action
- [X] T047 [P] [US3] `resources/js/composables/useMenuAvailability.js` and POS/Floor integration (`views/PosView.vue`, `views/FloorView.vue`, `utils/posProductCards.js` — greyed unavailable cards with the reason; the server remains the guard)
- [ ] T048 [US3] Browser check of Story 3 (mark, see it grey out on the POS within 5 s, order refused, undo) appended to `evidence-stage-a.md`

---

## Phase 7: User Story 4 — Discontinue (P2)

**Independent Test**: discontinue → unorderable, in history/reports, not in the active count; reactivate; with releases on it waits for a release.

### Tests first

- [X] T049 [P] [US4] `tests/Feature/ProductLifecycleTest.php`: discontinue needs a reason and `menu.item.manage`, immediate mode flips `is_active` and stamps `discontinued_at` and clears the 86 mark, past sales/reports unchanged, reactivate, release mode creates a pending `item_discontinue` change, draft → active via publish, audit rows
- [X] T050 [P] [US4] `qa-tests/component/DiscontinueDialog.test.js`

### Implementation

- [X] T051 [US4] `app/Services/ProductLifecycleService.php` (publish, discontinue, reactivate; the only writer of `is_active`/`published_at`/`discontinued_at` besides imports) and `ProductLifecycleController`; routes `POST /products/{id}/discontinue|reactivate`; new products are created `draft` when releases are on and `active` otherwise; OpenAPI
- [X] T052 [P] [US4] `resources/js/components/product/DiscontinueDialog.vue` and the item-page actions (hidden per `can`); status badges in list and page
- [ ] T053 [US4] Browser check of Story 4 appended to `evidence-stage-a.md`

---

## Phase 8: User Story 5 — Bulk price change (P2)

**Independent Test**: +5% on one category with Rp 500 rounding and a future date; preview = result; invalid input refused; all-or-nothing; one history entry per variant; past orders unchanged.

### Tests first

- [X] T054 [P] [US5] `tests/Feature/BulkPriceTest.php`: preview set-based and server-computed, rounding half-up to 500, percent and amount, category children included, `422` per invalid case (no scope, empty/zero value, past date, result ≤ 0, collapse to zero, scope matches nothing), apply all-or-nothing on a forced failure, one `price_history` row per variant, release mode creates pending changes with over-threshold ones awaiting approval, recorded `order_items` prices unchanged, permission `menu.price.bulk`
- [X] T055 [P] [US5] `qa-tests/component/BulkPriceDrawer.test.js`: debounced preview, field-level errors, submit label by mode, selection from the list pre-fills the scope

### Implementation

- [X] T056 [US5] `app/Services/BulkPriceService.php` (preview, apply via `PriceService`, batches), `app/Http/Controllers/Api/PriceChangeController.php`, `StorePriceChangeRequest`; routes `POST /price-changes/preview`, `POST /price-changes`; OpenAPI
- [X] T057 [P] [US5] `resources/js/components/product/BulkPriceDrawer.vue` on `FormDrawer` (Cakupan, Perubahan, preview table, footer by mode); header action and selection-bar action; i18n
- [ ] T058 [US5] Browser check of Story 5 appended to `evidence-stage-a.md`

---

## Phase 9: User Story 6 — Variants and prices drawer (P2)

**Independent Test**: add a variant, change a price with an effective date, deactivate a variant; validation, unique SKU, history, approval threshold.

### Tests first

- [X] T059 [P] [US6] `tests/Feature/VariantBatchTest.php`: batch add/update/deactivate in one transaction, unique SKU, name and price required, never deletes a variant that has history, prices via `PriceService`, empty effective date = immediate/next release, over-threshold → awaiting approval, `cost_price` untouched, all-or-nothing
- [X] T060 [P] [US6] `qa-tests/component/VariantsDrawer.test.js`

### Implementation

- [X] T061 [US6] `app/Http/Controllers/Api/VariantBatchController.php` + `VariantBatchRequest`, route `PUT /products/{id}/variants`; OpenAPI
- [X] T062 [P] [US6] `resources/js/components/product/VariantsDrawer.vue` (guidance text, threshold rule text only when releases are on, save label by mode) opened from the item page and replacing the variant-card editing path in `views/products/ProductFormDrawer.vue` for existing items (the old drawer keeps creating new items)
- [ ] T063 [US6] Browser check of Story 6 appended to `evidence-stage-a.md`

---

## Phase 10: User Story 10 (single-target) — Releases and approvals (P2)

**Independent Test**: with releases on, make three changes (one above 15%); banner lists them; over-threshold one cannot be released and cannot be self-approved; another approver approves; release v{n} applies; a scheduled release applies on time; with releases off there is no banner and edits are immediate.

### Tests first

- [X] T064 [P] [US10] `tests/Feature/MenuReleaseApiTest.php`: `GET /menu/changes`, cancel, `POST /menu/releases` (now and scheduled, partial selection, `409 changes_not_ready`), `GET /menu/releases` and detail contents/who/when, `409 release_disabled` when off, permissions `menu.release.publish`
- [X] T065 [P] [US10] `tests/Feature/ApprovalApiTest.php`: list, approve, reject with reason, self-approval refused, a non-holder 403, decisions logged
- [X] T066 [P] [US10] `qa-tests/component/ReleaseBanner.test.js`, `ReleaseDialog.test.js`, `ApprovalList.test.js`, `MenuReleaseSection.test.js`

### Implementation

- [X] T067 [US10] (done; OpenAPI paths added 2026-10-11) `MenuChangeController`, `MenuReleaseController`, `ApprovalController` + requests; routes under `/menu/changes`, `/menu/releases`, `/approvals`; extend `GET /products/summary` (`unreleased`, version, since) and `GET /menu/availability` (`menu_version`); OpenAPI
- [X] T068 [P] [US10] `resources/js/components/product/ReleaseBanner.vue`, `ReleaseDialog.vue`, `ApprovalList.vue`; mount the banner on the list, the approval list for approvers; Settings → `resources/js/views/settings/MenuReleaseSection.vue` (enable switch, threshold, rounding default)
- [ ] T069 [US10] Browser check of Story 10 in both modes (on: banner, approval, release, scheduled; off: immediate, no controls) appended to `evidence-stage-a.md`

---

## Phase 11: User Stories 7 and 8 — Modifier links (P3) and Recipes (P3)

### Story 7 — tests first

- [X] T070 [P] [US7] `tests/Feature/ModifierLinkTest.php`: replace links in one transaction, per-link required/min/max/order validated (0 ≤ min ≤ max, max ≥ 1 or null), group contents untouched, release mode → pending `modifier_link` change, **`ModifierResolver` uses the link rule over the group rule** and the order, falls back to the group/category, a required link makes the tab line require the group (extend `tests/Feature/ModifierResolverTest.php` and `TabModifiersTest.php`)
- [X] T071 [P] [US7] `qa-tests/component/ModifierLinksDrawer.test.js`

### Story 7 — implementation

- [X] T072 [US7] Extend `app/Services/ModifierResolver.php`; `app/Http/Controllers/Api/ModifierLinkController.php` + request; route `PUT /products/{id}/modifier-links`; OpenAPI
- [X] T073 [P] [US7] `resources/js/components/product/ModifierLinksDrawer.vue` and the item-page section action; POS modifier dialog follows the effective rules (`resources/js/components/restaurant/*` as needed)

### Story 8 — tests first

- [X] T074 [P] [US8] `tests/Unit/RecipeCostTest.php` (or `tests/Feature/RecipeCostTest.php`): formula `qty ÷ (1 − shrink) × unit_cost`, portion cost with yield 2, food cost %, gross margin, `incomplete` when a line has no cost, semi-finished `standard_cost`, matches an independent hand calculation (SC-005)
- [X] T075 [P] [US8] `tests/Feature/RecipeApiTest.php`: new version per save with earlier versions kept, validation (yield ≤ 0, no lines, qty ≤ 0, shrink outside 0–99.99, unknown material, no variants), active version per variant by `effective_from`, `409 cost_source_conflict` both directions with BOM-complete, `cost_price` synced only at activation/recalculate, `409 cost_price_locked_by_recipe`, recorded order costs unchanged, cashier sees no cost, permissions
- [X] T076 [P] [US8] `qa-tests/component/RecipeDrawer.test.js`, `RecipeSection.test.js`

### Story 8 — implementation

- [X] T077 [US8] `app/Support/RecipeCost.php` (pure formula) and `app/Services/RecipeService.php` (versions, variant coverage, cost sync — the only writer of `cost_price` for recipe-driven variants; guards against the BOM-complete conflict in `VariantBomService` too)
- [X] T078 [US8] `app/Http/Controllers/Api/RecipeController.php` + requests; routes `GET /products/{id}/recipes`, `GET /recipes/{id}`, `POST /products/{id}/recipes`, `POST /products/{id}/recipes/recalculate`; OpenAPI; list `food_cost_pct` filled from `RecipeCost`
- [X] T079 [P] [US8] `resources/js/components/product/RecipeDrawer.vue` (versioning sentence without a stock-deduction promise, variants, ingredients grouped by material kind, live cost, incomplete-cost notice, "Simpan sebagai v{n}") and the item-page section with cost and margin
- [ ] T080 [US7] [US8] Browser check of Stories 7 and 8 appended to `evidence-stage-a.md`

---

## Phase 12: Stage A verification

- [ ] T081 Roles pass (`owner`, `admin`, `inventory`, `kasir01`): each sees only the controls its menu keys and permissions allow; the same actions answer 403 server-side; record in `evidence-stage-a.md`
- [ ] T082 Performance pass (quickstart): list first page ≤ 2 s with 500 items, bulk preview ≤ 3 s for 100 variants, availability poll lean; measure on an unloaded machine and note the load
- [ ] T083 Run the full backend suite per file and `npm test`; `npm run build` and compare the main chunk with the T001 number (+10 kB budget); console sweep of the Products pages and the POS in both languages; write the Stage A summary and anything not done into `evidence-stage-a.md`
- [X] T084 Update `CLAUDE.md` (a "Products module" section: derived status, `MenuAvailability`, `PriceService` as the single price writer, release engine and its setting, lazy publishing, 86 marks, recipes beside the BOM, permissions) and tick completed tasks here

**Checkpoint**: Stage A shippable on its own. Stage B starts only when T004 is satisfied.

---

## Phase 13: Stage B foundation — outlet tables for the menu (**blocked by T004**)

- [ ] T085 Migration `database/migrations/2026_11_06_000001_create_outlet_menu_tables.php`: `outlet_item_settings`, `variant_outlet_prices`, `menu_release_outlets`; then `2026_11_06_000002_backfill_menu_outlet_ids.php` filling the nullable `outlet_id` of `item_availability_marks`, `price_history`, `menu_changes` with the default outlet and making them NOT NULL with FKs; re-runnable, rehearsed on a populated dump
- [ ] T086 Models and scope: `OutletItemSetting`, `VariantOutletPrice`, `MenuReleaseOutlet`; apply `BelongsToOutlet`/`OutletScope` (from 042) to the three backfilled tables and the new ones; raw reads use `OutletFilter`; extend the 042 enumerating test `tests/Feature/OutletScopeInventoryTest.php` with the new tables
- [ ] T087 `PriceService::priceFor(variant, outlet)` (override else base) used by `OrderService`/`TabService`; `MenuAvailability` becomes outlet-aware (a mark/quota for the order's outlet); characterisation `T002`/`T003` must still pass for a single-outlet install; tests `tests/Feature/OutletPriceResolutionTest.php`

---

## Phase 14: User Story 9 — Per-outlet availability and prices (P2) and the outlet half of Story 10 (**blocked by T004**)

**Independent Test**: three outlets; set a price and an 86 in one; the others unaffected; a user limited to one outlet cannot read or change another's; an empty price follows the base; release to two outlets.

### Tests first

- [ ] T088 [P] [US9] `tests/Feature/OutletAvailabilityTest.php`: 86 per outlet (`outlet_ids[]`), quota/channels, only that outlet's orders blocked, other outlets unaffected, list/detail availability arrays per outlet in scope, forged `outlet_id` in a body ignored/refused
- [ ] T089 [P] [US9] `tests/Feature/OutletPricesTest.php`: per-outlet price set via the variants drawer and bulk change, empty follows base, orders in that outlet use it, recorded prices unchanged, approval threshold compares with the base price
- [ ] T090 [P] [US10] `tests/Feature/MenuReleaseOutletsTest.php`: release to chosen outlets, per-outlet state `pending/applied/failed`, retry, only released outlets see the change, list shows each outlet's delivery state
- [ ] T091 [P] [US9] `tests/Feature/ProductsOutletIsolationMatrixTest.php`: outlets × roles × endpoints (list, detail, 86, prices, releases, approvals) — zero data from another outlet

### Implementation

- [ ] T092 [US9] Extend `AvailabilityService`, `ProductCatalogQuery`, `VariantBatchController`, `BulkPriceService` with the outlet parameter and `OutletFilter`; per-outlet entries in `availability` and `variants[].outlet_prices`; OpenAPI
- [ ] T093 [US10] Extend `ReleaseService`/`MenuReleaseController` with outlet targets, per-outlet delivery state and `POST /menu/releases/{id}/retry`; `GET /menu/availability` scoped to the terminal's outlet
- [ ] T094 [P] [US9] Frontend per-outlet UI: `AvailabilityChips` per outlet with outlet codes, outlet price columns in the variants table and drawer (grey = follows base), outlet checkboxes in the 86 dialog and bulk drawer, outlet targets and per-outlet results in `ReleaseDialog`, availability section rows per outlet with quota and channels; all hidden at one outlet; tests `qa-tests/component/ProductsOutlets.test.js`
- [ ] T095 [US9] Browser check with three outlets × four roles (selector, chips, per-outlet 86 and price, release to two outlets) in `specs/043-products-page-redesign/evidence-stage-b.md`

---

## Phase 15: Polish and cross-cutting

- [ ] T096 [P] Documentation: dated notes in `docs/PRD-POS-Event-Multivendor.md` (item statuses, 86, releases, approvals, recipes, price history) and `CLAUDE.md`; every new route in `docs/openapi-pos-mvp.yaml`; RUNBOOK note for `menu:publish-due`
- [ ] T097 [P] Accessibility pass on the list and item page (keyboard order, grid card as one focus target, `ViewToggle` as a radiogroup, status not colour-only, drawers/dialogs focus trap) in both languages; fix findings
- [ ] T098 Final verification (quickstart Stages A and B): full backend suite per file, `npm test`, build and bundle budget, isolation matrix, console sweep; write `evidence-stage-b.md`
- [ ] T099 Mark every completed task `[X]`, list anything not done with the reason, and prepare the commit split (schema+backend / frontend / specs) for the owner

---

## Dependencies and execution order

- **Phase 1** first; **T004** is an owner gate for Stage B only.
- **Phase 2 blocks everything.** Inside it: T005 → T006–T013 in parallel → T014 → T015/T016 → T017 (needs T002) → T018; T019/T020 in parallel after T016/T018.
- **Phase 3 (engines)** needs Phase 2 and blocks Stories 5, 6, 10 only. Stories 1–4, 7, 8 do not wait for it (US4's release-mode behaviour needs T026 and is completed after it).
- **Stage A stories**: US1 → US2 (needs US1 navigation) → US3, US4 (parallel) → Phase 3 → US5, US6, US10 (parallel) → US7, US8 (parallel).
- **Stage B** (after T004): Phase 13 → Phase 14; Phase 15 last.
- Same-file sequencing: `ProductController.php` (T031, T039, T051 via service), `ProductsView.vue` (T034, T094), `ProductDetailView.vue` (T040, T041, T094), `PriceService.php` (T022, T087), `ReleaseService.php` (T026, T093), `AvailabilityService.php` (T045, T092).

## Parallel examples

```text
Phase 2:  T006–T013            (eight independent migrations)
US1:      T028, T029, T030     (tests)   → T031 ‖ T032 ‖ T033
US3:      T042, T043, T044     (tests)   → T046 ‖ T047
Engines:  T021 ‖ T023 ‖ T025   (tests first), then T022, T024, T026
Stories:  US5 ‖ US6 ‖ US10 after the engines; US7 ‖ US8
Stage B:  T088, T089, T090, T091 (tests) once Phase 13 is done
```

## Implementation strategy

1. **MVP = Phases 1, 2 and 4**: baseline, schema/availability/reasons, then the new list. Stop and show it.
2. Add the item page (US2), then 86 and discontinue (US3, US4) — the daily-operations value.
3. Build the engines, then bulk price, variants and the release flow behind the setting (US5, US6, US10); then modifier links and recipes.
4. Stage A is shippable on its own with releases off. Turn `menu_release_enabled` on per install when the restaurant wants the release flow.
5. Stage B only after the amendment and the 042 outlet foundation; never add outlet tables or scope here before then.
6. Every checkpoint ends with evidence in the matching `evidence-*.md`; nothing is called done on the shape of the diff alone.

## Summary

| Phase | Tasks | Count |
|---|---|---|
| 1 Setup | T001–T004 | 4 |
| 2 Foundational schema/permissions/availability/reasons | T005–T020 | 16 |
| 3 Price, release, approval engines | T021–T027 | 7 |
| 4 US1 list | T028–T036 | 9 |
| 5 US2 item page | T037–T041 | 5 |
| 6 US3 sold out | T042–T048 | 7 |
| 7 US4 discontinue | T049–T053 | 5 |
| 8 US5 bulk price | T054–T058 | 5 |
| 9 US6 variants | T059–T063 | 5 |
| 10 US10 releases and approvals (single target) | T064–T069 | 6 |
| 11 US7 modifier links + US8 recipes | T070–T080 | 11 |
| 12 Stage A verification | T081–T084 | 4 |
| 13 Stage B foundation (gated) | T085–T087 | 3 |
| 14 US9 + outlet half of US10 (gated) | T088–T095 | 8 |
| 15 Polish | T096–T099 | 4 |
| **Total** | | **99** |
