---

description: "Task list for 048-stock-movements-search-variant-columns"
---

# Tasks: Stock Page Search, Variant Details, and Product Stock Totals

**Input**: Design documents from `/specs/048-stock-movements-search-variant-columns/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/stock-movements.md, contracts/products-list.md, quickstart.md

**Tests**: INCLUDED. The project constitution (Principle II) requires backend tests in `tests/Feature/`, frontend tests in `qa-tests/`, and a real-browser check for any user-facing screen change.

**Organization**: Grouped by user story. Code comments, UI copy and commit messages are Indonesian (project convention); UI strings go through `en.json` AND `id.json`.

**Test-run safety**: Never run `php artisan test` inside the `app` container without `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test ...` (CLAUDE.md: it has wiped the dev DB twice). Prefer a throwaway test database.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 / US2 / US3 — see spec.md

---

## Phase 1: Setup (Resolve the plan's unverified items)

**Purpose**: Settle the three open items from research.md before building on them.

- [X] T001 Confirm the `/stock` route requires the `stock` menu by reading `resources/js/router/index.js` (research D5); if it does not, record the actual gate in `specs/048-stock-movements-search-variant-columns/research.md` and decide whether the History button needs its own `auth.canAccessMenu(...)` guard in `resources/js/views/StockView.vue`
- [X] T002 Write a failing feature test in `tests/Feature/ProductTest.php` proving `withSum` with the expression `current_stock * sell_price` works on the installed Laravel (research D7): two active variants, assert the aggregate equals the hand sum; if the expression form is unsupported, note the `selectSub` fallback in research.md
- [X] T003 [P] Run the current baseline on a throwaway test DB and record pass/fail counts: `php artisan test --filter=StockMovementsTest`, `--filter=ProductTest`, and `npx vitest run qa-tests/component/StockView.test.js qa-tests/component/VariantHistoryModal.test.js qa-tests/component/ProductsView.test.js` (so later failures are attributable)

**Checkpoint**: Route gate known, aggregate approach confirmed, baseline recorded.

---

## Phase 2: Foundational

**Purpose**: Nothing blocks all stories beyond Phase 1 — every story edits a different slice (movements endpoint, history dialog, products list). No shared infrastructure is needed (no migration, no new service).

**Checkpoint**: Proceed to user stories (US1 and US3 can run in parallel; US2 follows US1 because both edit `StockView.vue`).

---

## Phase 3: User Story 1 — Find a variant's stock on the Stock page (Priority: P1) 🎯 MVP

**Goal**: `/stock` stays a movement ledger; users can search and filter by seller and category, and every row shows picture, variant name, seller, category and live available stock.

**Independent Test**: With movements for two sellers/categories, search by variant name/SKU/product name, filter by seller and category, and confirm only matching movements appear with all new columns visible and correct pagination totals.

### Tests for User Story 1

- [X] T004 [P] [US1] Extend `tests/Feature/StockMovementsTest.php`: search matches variant name, SKU and product name (case-insensitive, partial); blank/whitespace search is ignored; `%` and `_` are matched literally; `artist_id[]` and `category_id[]` filter (single and multiple values) and combine with `search`, `type` and `date_from/date_to` by AND; an unknown seller/category id returns an empty list, not an error; `meta.total`/`last_page` reflect the filter
- [X] T005 [P] [US1] Extend `tests/Feature/StockMovementsTest.php`: movements of a soft-deleted variant and of a variant whose product is soft-deleted remain returned AND searchable; a soft-deleted seller still yields `artist_name`
- [X] T006 [P] [US1] Extend `tests/Feature/StockMovementsTest.php`: new row fields `image_url` (variant image → product image → null), `artist_id/artist_name`, `category_id/category_name`, `current_stock` equals the variant's live stock (differs from `stock_after` after a later movement); response contains no cost field; access gate unchanged (403 for a role without `stock`/`products`); DEMO movements never appear in LIVE mode
- [X] T007 [P] [US1] Extend `tests/Feature/StockMovementsTest.php`: constant query count for `per_page=5` vs `per_page=50` (eager-load guard, Constitution V)
- [X] T008 [P] [US1] Extend `qa-tests/component/StockView.test.js`: search input is debounced and sends `search`; seller and category multi-selects send `artist_id`/`category_id` arrays and reset to page 1; each row renders picture or same-size placeholder, variant name, seller, category and available stock; empty result shows the empty message (API mocked, per existing test style)

### Implementation for User Story 1

- [X] T009 [US1] Add `scopeMatchingListFilters()` to `app/Models/StockMovement.php` (single implementation, Indonesian "why" comment): `whereHas('variant', withTrashed)` matching SKU / variant name / product name via `whereHas('product', withTrashed)`, escaped LIKE wildcards, `artist_id`/`category_id` through the variant's product with `whereIn` on non-empty arrays only
- [X] T010 [US1] Update `movements()` in `app/Http/Controllers/Api/StockController.php`: apply the scope with `$request->only(['search','artist_id','category_id'])`, eager-load `variant.product.artist` and `variant.product.category` with `withTrashed()`, and add `image_url`, `artist_id`, `artist_name`, `category_id`, `category_name`, `current_stock` to each row (contracts/stock-movements.md); keep every existing field and the `stock`-OR-`products` gate untouched
- [X] T011 [US1] Add i18n keys used by this story (column headers for variant, picture alt text, available stock, search placeholder, "no results for filters" if distinct from the existing empty message) to BOTH `resources/js/locales/en.json` and `resources/js/locales/id.json` under `master_data.*`, reusing existing keys (`col_artist`, `col_category`, `all_artists`, `all_categories`, `col_stock`) where they exist
- [X] T012 [US1] Update `resources/js/views/StockView.vue`: add a debounced search input (same pattern as `ProductsView.vue`), seller and category `BaseMultiSelect` filters fed by `listArtists({ per_page: 100 })` / `listCategories({ per_page: 100 })`, wired through `setFilter`; keep the existing type and date filters, export/import/adjust buttons and SKU → `ProductDetailModal` flow
- [X] T013 [US1] Update the `columns` and templates in `resources/js/views/StockView.vue`: variant cell with 40–48 px picture (placeholder icon of the same size when `image_url` is null, token classes only, no hex), variant name, seller, category and available-stock columns placed before the existing type/change columns; keep the SKU button behaviour
- [X] T014 [US1] Run T004–T008 and make them pass; run `qa-tests/unit/localeKeys.test.js` to confirm no missing literal key

**Checkpoint**: `/stock` is searchable and filterable with the new columns; independently demonstrable (MVP).

---

## Phase 4: User Story 2 — History from the Stock page, titled by variant (Priority: P1)

**Goal**: A History action on each row opens the existing dialog for that variant; the dialog title shows `<variant name> · <SKU>` wherever it opens.

**Independent Test**: Click History on a row → dialog lists only that variant's movements and its title contains name and SKU; open it from the product detail variant list → same title format.

**Depends on**: US1 (same file `StockView.vue`; the new row data).

### Tests for User Story 2

- [X] T015 [P] [US2] Extend `qa-tests/component/VariantHistoryModal.test.js`: title contains both variant name and SKU separated by ` · `; only SKU when name is empty; only name when SKU is empty; filters, retry and empty message behave as before
- [X] T016 [P] [US2] Extend `qa-tests/component/StockView.test.js`: clicking History on a row opens `VariantHistoryModal` with that row's `variant_id`, `sku`, `variant_name`; closing it leaves the list, active filters and page unchanged
- [X] T017 [P] [US2] Extend `qa-tests/component/ProductDetailModal.test.js` (create the assertion if absent): the history dialog opened from a variant row receives name and SKU

### Implementation for User Story 2

- [X] T018 [US2] Update `resources/js/components/product/VariantHistoryModal.vue`: compute `titleLabel = [variantName, variantSku].filter(Boolean).join(' · ')` and pass it to `t('master_data.history_title', { label: titleLabel })`
- [X] T019 [US2] Update `master_data.history_title` in `resources/js/locales/en.json` ("Transaction history — {label}") and `resources/js/locales/id.json` ("Riwayat transaksi — {label}"); search the repo for other uses of the `{sku}` placeholder of this key before changing it
- [X] T020 [US2] Update `resources/js/views/StockView.vue`: add a **History** column/button per row (reuse existing `master_data.history` label), local state `historyVariant`, and mount `<VariantHistoryModal>` with `variant-id`, `variant-sku`, `variant-name`; apply the guard decision recorded in T001
- [X] T021 [US2] Run T015–T017 and make them pass

**Checkpoint**: History reachable from the Stock page; consistent title at both call sites.

---

## Phase 5: User Story 3 — Total stock and stock value per product (Priority: P2)

**Goal**: Each row on `/products` shows total available stock and total stock value (Σ stock × selling price, active variants only).

**Independent Test**: A product with variants of stock 4, 10, 0 (one inactive) shows the correct total stock and money; zero stock shows 0 / Rp 0; no cost price is exposed.

**Depends on**: Phase 1 (T002). Independent of US1/US2; can run in parallel with them.

### Tests for User Story 3

- [X] T022 [P] [US3] Extend `tests/Feature/ProductTest.php`: `GET /products` rows carry `total_stock` (int) and `total_stock_value` (string `"0.00"` format) equal to hand-computed sums over ACTIVE, non-deleted variants; inactive and soft-deleted variants excluded; product with no variants or no stock returns `0` / `"0.00"`; negative stock is summed as-is
- [X] T023 [P] [US3] Extend `tests/Feature/ProductTest.php`: totals do not mix DEMO and LIVE variants; `GET /products/{id}`, `POST /products` and `PUT /products/{id}` responses do NOT contain the two fields; response never contains cost-derived totals; constant query count for `per_page=5` vs `per_page=50`
- [X] T024 [P] [US3] Extend `qa-tests/component/ProductsView.test.js`: the two columns render server values (`formatIDR` for money), show 0 / Rp 0 for empty totals, and perform no client arithmetic

### Implementation for User Story 3

- [X] T025 [US3] Update `index()` in `app/Http/Controllers/Api/ProductController.php`: add relation aggregates over `variants` restricted to `is_active = true` — `total_stock` (Σ `current_stock`) and `total_stock_value` (Σ `current_stock * sell_price`, using the approach confirmed in T002); keep the default payload lean and the `?with_variants=1` behaviour unchanged; Indonesian comment explaining why relation aggregates (global DEMO/LIVE and soft-delete scopes) and not a raw `DB::table`
- [X] T026 [US3] Update `app/Http/Resources/ProductResource.php`: emit `total_stock` (int, default 0) and `total_stock_value` (money string via `number_format((float) $x, 2, '.', '')`) only when the aggregate attributes are present on the model, so show/store/update responses are unchanged (comment the deliberate `relationLoaded`-style guard)
- [X] T027 [US3] Add i18n keys for the two column headers to BOTH `resources/js/locales/en.json` and `resources/js/locales/id.json` (`master_data.col_total_stock`, `master_data.col_stock_value`, or reuse an existing key where one fits)
- [X] T028 [US3] Update `resources/js/views/ProductsView.vue`: add `total_stock` and `total_stock_value` to `columns` after Category with `#cell-` templates (integer; `formatIDR(row.total_stock_value)`), tokens only
- [X] T029 [US3] Run T022–T024 and make them pass; re-run `qa-tests/unit/localeKeys.test.js`

**Checkpoint**: Products list shows totals; stories 1–3 each demonstrable alone.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T030 [P] Update `docs/openapi-pos-mvp.yaml` IN THE SAME COMMIT as the code: `GET /stock/movements` (new `search`, `artist_id[]`, `category_id[]` parameters and row fields) and `GET /products` (`total_stock`, `total_stock_value` on list rows, absent elsewhere); validate the YAML still parses (previous features hit a quoting issue here)
- [X] T031 [P] Add a dated "Stock page filters, variant details, product totals (feature 048, 2026-10-28)" section to `CLAUDE.md` under the existing feature sections (ledger stays a ledger; `current_stock` is live not `stock_after`; totals = selling price, active variants, list-only; why relation aggregates); add a short note in `docs/RUNBOOK.md` only if an operator-visible behaviour changed
- [X] T032 Run the full suites on a throwaway DB: `php artisan test` (with the container `-e` overrides if inside Docker) and `npm test`; report real pass/fail counts, including any pre-existing failures found in T003
- [ ] T033 Real-browser verification following `quickstart.md` steps 1–10 with the console open (Constitution II): `/stock` search, filters, History and title; `/products` totals; DEMO vs LIVE; role without access; en ⇄ id; confirm no cost price appears in any new field or network response
- [X] T034 [P] Check the production build still succeeds (`npm run build`) and that no new raw hex literals or non-token colours were introduced in the touched Vue files

---

## Dependencies & Execution Order

### Phase dependencies

- Phase 1 (T001–T003) first; T002 gates T025, T001 gates T020.
- Phase 2 is empty.
- US1 (Phase 3) and US3 (Phase 5) are independent and can proceed in parallel (different files, different endpoints).
- US2 (Phase 4) after US1 because `StockView.vue` is edited by T012, T013 and T020 (sequential edits of one file).
- Polish (Phase 6) after the stories being shipped are complete.

### Within each story

Tests first and failing → backend (scope/controller/resource) → i18n keys → frontend → run tests.

### Parallel opportunities

- T003 alongside T001/T002.
- T004–T008 are different test cases/files; the four `StockMovementsTest.php` tasks edit one file, so write them together or serially, while T008 (Vitest) runs in parallel with them.
- T022–T024 likewise (T022/T023 same file, T024 separate).
- US1 backend (T009–T010) in parallel with US3 backend (T025–T026).
- T030, T031, T034 in parallel.

### Parallel example: US1 + US3 together

```text
Developer A: T004–T007 → T009 → T010 → T011 → T012 → T013 → T014
Developer B: T022–T023 → T025 → T026 → T027 → T028 → T024 → T029
Then: US2 (T015–T021), then Polish (T030–T034)
```

---

## Implementation Strategy

### MVP first

1. Phase 1 → US1 (search, filters, new columns on `/stock`) → validate in the browser → demo.
2. Add US2 (History + title) → validate.
3. Add US3 (product totals) → validate.
4. Polish: OpenAPI, CLAUDE.md, full suites, browser pass, build.

### Notes

- No migration, no new service class, no write path: every change is read-side.
- Do not convert `/stock` into a per-variant list (spec FR-006).
- Do not add any cost-based figure (spec FR-014, SC-006).
- Commit per task or logical group in Indonesian; the OpenAPI change must ship in the same commit as the response change.
