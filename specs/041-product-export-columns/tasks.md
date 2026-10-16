# Tasks: Product Export Column Update

**Input**: `specs/041-product-export-columns/` (plan.md, spec.md, research.md, data-model.md, contracts/products-export.md, quickstart.md)

**Regenerated 2026-10-16** for spec iteration 3 (total stock column; filter-aware export). Tasks marked `[X]` were completed in earlier iterations and are kept for traceability; their code is in the working tree (uncommitted). IDs were renumbered in execution order.

**Tests**: INCLUDED. Constitution Principle II requires backend changes to ship with tests that pass on MySQL, and the frontend change needs a Vitest unit plus a browser check.

**Test commands (safe)**: never run tests in the `app` container without the overrides (CLAUDE.md wipe warning):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='MasterDataExportTest|MasterDataImportTest|ProductTest'`
Frontend: `npm test` (Vitest, `qa-tests/`).

**Organization**: by user story, in priority order: P1 (US1, US2, US6), then P2 (US3, US4, US5). Story tasks that edit the same method or test file are sequential, not `[P]`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US6 map to spec.md user stories

---

## Phase 1: Setup

**Purpose**: green baseline before the scope change.

- [X] T001 Baseline: run the export and import suites with the safe command; 42 passed before any change (`tests/Feature/MasterDataExportTest.php`, `tests/Feature/MasterDataImportTest.php`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the export header list and its wiring. Done in an earlier iteration.

- [X] T002 Add `PRODUCTS_EXPORT` constant in `app/Support/MasterDataSheets.php`; `PRODUCTS` (import) left unchanged
- [X] T003 Add `exportHeadings(string $sheet)` in `app/Support/MasterDataSheets.php`
- [X] T004 Use `MasterDataSheets::exportHeadings($entity)` in `app/Http/Controllers/Api/MasterDataExportController.php`
- [X] T005 Compare products headers to `exportHeadings()` in `tests/Feature/MasterDataExportTest.php`

**Checkpoint**: foundation done.

---

## Phase 3: User Story 1 — Artist and category names beside their codes (Priority: P1)

**Goal**: `artist_name` after `artist_code`; `category_name` after `category_code`.
**Independent Test**: artist "Ryu Illustration" and category "Keychain" appear in the cells after their codes.

- [X] T006 [US1] Add `artist_name` and `category_name` to `productRows()` in `app/Services/MasterDataExportService.php`
- [X] T007 [US1] Assert artist and category names in `test_products_export_derived_values_are_computed_on_the_server` in `tests/Feature/MasterDataExportTest.php`

---

## Phase 4: User Story 2 — Short SKU code and readable item label (Priority: P1)

**Goal**: `code` = last 7 SKU characters (whole SKU if shorter); `item_label` = `{code} - {category_name} {variant_name}`.
**Independent Test**: SKU `RYUKYSAK0001` → `code` `SAK0001`, `item_label` `SAK0001 - Keychain Standard`.

- [X] T008 [US2] Add `skuTail()` (`substr($sku, -7)`) in `app/Services/MasterDataExportService.php`
- [X] T009 [US2] Add `code` and `item_label` to `productRows()` in `app/Services/MasterDataExportService.php`
- [X] T010 [US2] Test short SKU uses whole SKU and derived label values in `tests/Feature/MasterDataExportTest.php`

---

## Phase 5: User Story 6 — Export only what the screen shows (Priority: P1)

**Goal**: the products export applies the same `search`, `artist_id[]`, `category_id[]` filters as the Products list; the Products screen's export button sends the filters on screen.
**Independent Test**: a search matching one product exports only that product's variants; with no filter, every variant is exported.

**Design**: the filter rules move into one scope on `Product`, used by the list and the export (Constitution I, single definition).

- [X] T011 [US6] Add `scopeMatchingListFilters(Builder $query, array $filters)` to `app/Models/Product.php`. Rules copied exactly from `ProductController::index()` lines 56–75: `search` matches `name LIKE %term%` OR a variant with `sku LIKE` or `variant_name LIKE`; `artist_id` and `category_id` use `array_filter` then `whereIn` only when non-empty
- [X] T012 [US6] Replace the inline search/artist/category rules in `ProductController::index()` in `app/Http/Controllers/Api/ProductController.php` with the scope; keep `is_preorder`, `per_page`, `with_variants` and eager loads unchanged
- [X] T013 [US6] Change `MasterDataExportService::rows()` to `rows(string $entity, array $filters = [])` and `productRows()` to use `ProductVariant::query()->whereHas('product', fn ($q) => $q->matchingListFilters($filters))` in `app/Services/MasterDataExportService.php`; empty filters must return all variants
- [X] T014 [US6] In `MasterDataExportController::show()` in `app/Http/Controllers/Api/MasterDataExportController.php`, read `search`, `artist_id` (`$request->array()`) and `category_id` for the `products` entity only, and pass them to `rows()`; other entities pass no filters; gate unchanged
- [X] T015 [US6] Test: a search matching only "Keychain Sakura" exports only that product's variants, in `tests/Feature/MasterDataExportTest.php`
- [X] T016 [US6] Test: a search matching a variant SKU exports all sibling variants of that product, in `tests/Feature/MasterDataExportTest.php`
- [X] T017 [US6] Test: `artist_id[]`, `category_id[]` (two values, OR within kind) and the combination of search + artist (AND across kinds), in `tests/Feature/MasterDataExportTest.php`
- [X] T018 [US6] Test: no filter exports every variant; filters with no match give a header-only file; more than 25 matching products are all exported (no pagination), in `tests/Feature/MasterDataExportTest.php`
- [X] T019 [US6] Regression: the list endpoint returns the same products as the export for the same filters, and the existing list tests still pass, in `tests/Feature/ProductTest.php`
- [X] T020 [P] [US6] Change `exportMasterData(entity, params = {})` to pass `params` to axios with `responseType: 'blob'` kept, in `resources/js/api/masterData.js`
- [X] T021 [P] [US6] `doExport()` passes the same filter values the list uses (search, artist_id, category_id; the search value after debounce), in `resources/js/views/ProductsView.vue`
- [X] T022 [P] [US6] Vitest: `exportMasterData('products', params)` sends `params` and keeps the blob response; in `qa-tests/unit/masterDataExport.test.js`
- [ ] T023 [US6] Browser check (manual): Products screen — search, artist filter, Export; the file matches the screen; browser console has no errors (quickstart §3)

**Checkpoint**: US6 testable on its own.

---

## Phase 6: User Story 3 — Profit margin beside the sell price (Priority: P2)

**Goal**: `profit_margin` = sell − cost, two decimals, may be negative, directly after `sell_price`.

- [X] T024 [US3] Add `profit_margin` via `ReportSplit::cents()/money()` in `app/Services/MasterDataExportService.php`
- [X] T025 [US3] Test negative margin (`-500.00` style) in `tests/Feature/MasterDataExportTest.php`

---

## Phase 7: User Story 4 — Narrower export, variant name placement (Priority: P2)

**Goal**: eight columns removed; `variant_name` directly after `product_name`.

- [X] T026 [US4] Ensure `productRows()` emits only `PRODUCTS_EXPORT` keys, in `app/Services/MasterDataExportService.php`
- [X] T027 [US4] Tests: exact layout order and removed headers, in `tests/Feature/MasterDataExportTest.php`

---

## Phase 8: User Story 5 — Stock on hand next to the description (Priority: P2)

**Goal**: `total_stock_available` = variant `current_stock`, directly after `description`, zero written as `0`.
**Independent Test**: stock 12 → `12`; stock 0 → `0`.

- [X] T028 [US5] Insert `'total_stock_available'` after `'description'` in `PRODUCTS_EXPORT` in `app/Support/MasterDataSheets.php`. Do T028 and T029 together: the writer uses key order
- [X] T029 [US5] Add `'total_stock_available' => $v->current_stock` after `'description'` in `productRows()` in `app/Services/MasterDataExportService.php`
- [X] T030 [US5] Update the expected list in `test_products_export_uses_the_export_layout_in_order` to include `total_stock_available` after `description`, in `tests/Feature/MasterDataExportTest.php` (this test fails between T028 and T030 by design)
- [X] T031 [US5] Test: `total_stock_available` equals the variant's `current_stock` (seed 12 → `12`) and a zero-stock variant writes `0`, in `tests/Feature/MasterDataExportTest.php`

---

## Phase 9: Import and template regression

- [X] T032 Test: `MasterDataSheets::headings(PRODUCTS)` still has the 17 import columns, including `initial_stock`, `image_filename`, `is_preorder`, in `tests/Feature/MasterDataExportTest.php`
- [X] T033 Re-run `MasterDataImportTest` unchanged (no edits); must pass, including `test_the_shipped_template_imports_as_is` (`tests/Feature/MasterDataImportTest.php`)

---

## Phase 10: Polish & cross-cutting concerns

- [X] T034 [P] Update `/exports/{entity}` in `docs/openapi-pos-mvp.yaml`: add query parameters `search`, `artist_id[]`, `category_id[]` (products only), and the new column order including `total_stock_available`
- [X] T035 [P] Products-export column description in `docs/openapi-pos-mvp.yaml` (earlier iteration; the parameters and stock column are T034)
- [X] T036 Run `MasterDataExportTest`, `MasterDataImportTest` and `ProductTest` with the safe command; record pass counts
- [X] T037 Run frontend tests `npm test`; the existing 44 plus the new Vitest pass
- [X] T038 Run `vendor/bin/pint` on changed PHP files. Known: Pint also rewrites an unrelated `\App\Models\ProductVariantBomLine` reference in `app/Services/MasterDataExportService.php`; revert that hunk so the diff stays on scope
- [ ] T039 Manual checks per quickstart §2 and §4: export headers, one row's stock and labels, template unchanged
- [X] T040 Confirm the SPECKIT block in `CLAUDE.md` points to `specs/041-product-export-columns/plan.md`

---

## Dependencies & Execution Order

- **Done**: Setup, Foundational, US1–US4 (T001–T010 and T024–T027). Current working tree already holds these.
- **US6 (T011–T023)**: depends on nothing new. T011 → T012 and T013 → T014 (controller passes filters into the service). Tests T015–T019 after T014. Frontend T020–T022 are `[P]` (other files) and rely only on the param names. T023 after everything in US6.
- **US5 (T028–T031)**: T028 and T029 together, then T030 (fixes the layout test), then T031.
- **Regression (T032–T033)** and **Polish (T034–T040)** last.
- **Coupling**: T013 changes `rows()` signature; the existing tests calling `rows()` with no args still work because the parameter defaults to `[]`.

### Parallel opportunities
- T020, T021, T022 (frontend) in parallel with T011–T019 (backend).
- T034 (OpenAPI) any time after T014.

---

## Implementation Strategy

### Next step (MVP of this iteration)
1. US6 backend: T011–T014, then tests T015–T019.
2. US6 frontend: T020–T022 in parallel with the backend.
3. Browser check T023.
4. Then US5 (T028–T031) as one slice.

### Totals
- 40 tasks: 17 done `[X]` (T001–T010, T024–T027, T032, T035, T040), 23 open.
- Open by story: US6 13 (T011–T023), US5 4 (T028–T031), regression 1 (T033), polish 5 (T034, T036–T039).

---

## Notes
- Every task names an exact file path.
- Do not change `MasterDataSheets::PRODUCTS` or `headings()` for products (spec FR-011); the import depends on it.
- Commit only when asked; no commit is part of these tasks.
