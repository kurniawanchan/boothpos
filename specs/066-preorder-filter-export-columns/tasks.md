**Classification: INTERNAL**

---

description: "Task list for feature 066 — Pre-order filters (category, customer) and export columns"
---

# Tasks: Pre-order Filters (Category, Customer) and Export Columns

**Input**: Design documents from `/specs/066-preorder-filter-export-columns/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-filters-export.md, quickstart.md

**Tests**: INCLUDED. Constitution II makes tests mandatory for backend (`tests/Feature/`, real MySQL) and frontend (`qa-tests/`) changes, and the plan lists them.

**Organization**: grouped by user story (US1 customer filter, US2 category filter, US3 export columns, US4 export follows filters). No migration, no new route.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1–US4 (spec.md); Setup/Foundational/Polish carry none
- Backend tests run ONLY inside the container with explicit overrides, one run at a time:
  `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`
- Frontend: `npx vitest run --exclude '.claude/**' <file>`
- Code comments, locale copy and commit messages are in Indonesian (project convention); `en.json` AND `id.json` both updated.

## Path Conventions (from plan.md)

Backend `app/`, `tests/Feature/`; SPA `resources/js/`; SPA tests `qa-tests/`; API spec `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

**Purpose**: green baseline and the one open research item.

- [x] T001 Record the baseline: run `--filter=Preorder` backend tests and `npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js qa-tests/unit` and note failures that exist BEFORE any change (the 9 `Code128` failures are known and unrelated). Write the result as one line at the bottom of `specs/066-preorder-filter-export-columns/quickstart.md`.
- [x] T002 Resolve research D6 in `specs/066-preorder-filter-export-columns/research.md`: `Artist` uses `SoftDeletes`, so the list's `items.artist` (no `withTrashed`) omits soft-deleted sellers. **Decision: the export loads `items.artist` exactly as the list does (no `withTrashed`) so the `sellers` cell always equals the list's Seller column (US3 scenario 2 / SC-004).** Replace the "ASSUMPTION until read in code" paragraph with this outcome.

---

## Phase 2: Foundational (blocks US1, US2, US4)

**Purpose**: ONE filter scope and ONE seller derivation, introduced behaviour-preserving before anything new is added. Nothing user-visible changes in this phase.

- [x] T003 Add to `app/Models/Preorder.php` the constant `LIST_FILTER_KEYS` (`status`, `dispatch_status`, `event_id`, `customer_id`, `fulfillment`, `search`, `artist_id`) and `scopeMatchingListFilters(Builder $query, array $filters)`, moving the chain from `PreorderController::applyFilters()` **verbatim** (arrays normalised with `Arr::wrap` + `array_filter`; empty values dropped; `search` = PO number OR customer name OR item name/SKU OR outstanding digits; `artist_id` via `whereHas('items')`). `customer_id` stays scalar-equal for now (changed in T011). Keep the existing explanatory comments (translate nothing, move them).
- [x] T004 Make `PreorderController::applyFilters()` in `app/Http/Controllers/Api/PreorderController.php` a one-line delegation: `return $query->matchingListFilters($request->only(Preorder::LIST_FILTER_KEYS));` (depends on T003; callers `index`/`summary`/`neighbors`/`matchingIds` untouched).
- [x] T005 [P] Add `Preorder::sellers(): array` in `app/Models/Preorder.php` (distinct `{id, name}` from `items.artist`, first-appearance order, null-safe — logic moved from `PreorderController::sellersFor()`), and make `sellersFor()` delegate to it at both call sites (~L74 and ~L896) in `app/Http/Controllers/Api/PreorderController.php`.
- [x] T006 Gate: re-run `--filter=PreorderSearchTest`, `--filter=PreorderNeighborsTest`, `--filter=PreorderTest`, `--filter=PreorderBulk`, `--filter=PreorderExportImportTest` (and `PreorderDuplicateSplitReportsTest`). All must match the T001 baseline. If anything moved, fix the refactor before continuing.

**Checkpoint**: filter chain and seller derivation each exist exactly once; behaviour identical.

---

## Phase 3: User Story 1 — Filter by customer (Priority: P1) 🎯 MVP

**Goal**: multi-select, searchable Customer filter on the Pre-orders list.

**Independent Test**: pick customer(s) in the new dropdown → only their pre-orders, summary and row count agree; clear → full list.

### Tests for US1

- [x] T007 [US1] Create `tests/Feature/PreorderListFiltersTest.php` with the customer cases: scalar `customer_id=` still works; `customer_id[]` of two customers returns the union (OR); customer + `status[]` returns the intersection (AND); unknown/other-mode id returns an empty list (200, no error, no foreign data); no row duplicated across pages; `GET /preorders/summary`, `GET /preorders/matching-ids` and `GET /preorders/{id}/neighbors` honour `customer_id[]` identically to the list (FR-006). Use real factories; do not name a helper `put`/`get`/`call`/`seed` (PHPUnit clash, see CLAUDE.md).
- [x] T008 [P] [US1] Create `qa-tests/component/BaseMultiSelectRemote.test.js`: default mode unchanged (local filtering still works — regression guard for the 8 existing callers); with `remote` the options are NOT filtered locally and a `search` event is emitted with the typed text.
- [x] T009 [P] [US1] Create `qa-tests/component/CustomerFilterSelect.test.js` (mock `../../api/customers`): first page loaded on open without typing; typing triggers ONE debounced `listCustomers({search, per_page: 25})`; a selected customer's label still shows after a later search that no longer returns it; option labels are the name only (no phone/email rendered).

### Implementation for US1

- [x] T010 [US1] In `app/Models/Preorder.php::scopeMatchingListFilters()` change `customer_id` to `whereIn('customer_id', …)` through the same `Arr::wrap`/`array_filter` handling as `status` (scalar still accepted). Make T007 pass.
- [x] T011 [P] [US1] Add the opt-in `remote` boolean prop to `resources/js/components/ui/BaseMultiSelect.vue`: when true, skip local `filteredOptions` filtering and emit `search` (raw text) as the user types; default behaviour byte-for-byte unchanged. Update its docblock (Indonesian) with why it is opt-in. Make T008 pass.
- [x] T012 [US1] Create `resources/js/components/preorder/CustomerFilterSelect.vue`: wraps `BaseMultiSelect` with `remote`; loads the first page via `listCustomers({search:'', per_page:25})`; debounced search with `useDebouncedFn` (300 ms); keeps an `id → name` map so selected labels survive a new result set; options = selected ∪ results; label = `name` only; `v-model` = array of ids. Make T009 pass.
- [x] T013 [US1] In `resources/js/views/PreordersView.vue`: add `const customerFilter = ref([])`, render `<CustomerFilterSelect v-model="customerFilter" class="w-48" :all-label="t('preorders.all_customers')" @update:model-value="applyPreorderFilters" />` after the seller filter, and add `customer_id: customerFilter.value.length ? customerFilter.value : undefined` to `applyPreorderFilters()`. Confirm (read `applyFilter`) that a filter change resets to page 1 and calls the bulk selection `clear()` exactly like the other filters; if not, wire it the same way as `statusFilter`.
- [x] T014 [P] [US1] Add locale keys `preorders.all_customers` and `preorders.customer_search_placeholder` to `resources/js/locales/en.json` and `resources/js/locales/id.json` ("All customers"/"Semua pelanggan", "Search customer…"/"Cari pelanggan…"). Do not repeat a key already present in the `preorders` section (silent override trap).
- [x] T015 [US1] Extend `qa-tests/component/PreordersView.test.js`: choosing a customer calls `listPreorders` with `customer_id: [<id>]` and page 1; clearing sends `customer_id: undefined`; a bulk selection is cleared by the change.

**Checkpoint**: US1 works and is demonstrable on its own.

---

## Phase 4: User Story 2 — Filter by product category (Priority: P1)

**Goal**: multi-select Category filter; match = at least one item whose product is in a selected category.

**Independent Test**: choose a category → every listed pre-order has an item of that category, none missing, each once and whole.

### Tests for US2

- [x] T016 [US2] Add category cases to `tests/Feature/PreorderListFiltersTest.php` (same file as T007 → sequential): pre-order with two items of category X appears once; mixed X/Y order filtered on X is returned with ALL its items; two categories = union; category + customer + seller + status + search = intersection; soft-deleted variant AND soft-deleted product still match; a category with no pre-orders returns an empty list; other-mode products never match; `summary`, `matching-ids` and `neighbors` honour `category_id[]` (FR-006).
- [x] T017 [P] [US2] Add to `qa-tests/component/PreordersView.test.js` (after T015 lands; same file → do not run in parallel with T015): categories are loaded via `listCategories`, choosing one calls `listPreorders` with `category_id: [<id>]`, clearing sends `undefined`.

### Implementation for US2

- [x] T018 [US2] Add `category_id` to `Preorder::LIST_FILTER_KEYS` and to `scopeMatchingListFilters()` in `app/Models/Preorder.php`: `whereHas('items', fn ($i) => $i->whereHas('variant', fn ($v) => $v->withTrashed()->whereHas('product', fn ($p) => $p->withTrashed()->whereIn('category_id', $ids))))`; comment (Indonesian) why EXISTS not JOIN (one row per pre-order), why `withTrashed` (history stays findable) and that only soft-delete is lifted — `DataModeScope` still applies (research D3). Make T016 pass.
- [x] T019 [US2] In `resources/js/views/PreordersView.vue`: add `categoryFilter` ref, `categories` loaded with the existing `listCategories({ per_page: 100 })` next to the `listArtists` load, `categoryOptions` computed, a `BaseMultiSelect` with `:all-label="t('preorders.all_categories')"` after the customer filter, and `category_id` in `applyPreorderFilters()`. Resolve T017 against it.
- [x] T020 [P] [US2] Add locale key `preorders.all_categories` ("All categories"/"Semua kategori") to `resources/js/locales/en.json` and `resources/js/locales/id.json`.

**Checkpoint**: US1 + US2 both work, also combined.

---

## Phase 5: User Story 3 — Export carries PO number, seller, status, total (Priority: P1)

**Goal**: four new read-only columns in the pre-order export that equal the list's values.

**Independent Test**: export, then compare `preorder_number`/`sellers`/`status`/`total_amount` of sampled rows with the list.

### Tests for US3

- [x] T021 [P] [US3] Update `tests/Feature/PreorderExportImportTest.php` (deliberate layout change, research D5): new `EXPORT_HEADINGS` order (`preorder_number`, `customer_name`, `sellers`, `event_name`, `fulfillment`, `pickup_day`, `products`, `variant_names`, `quantities`, `unit_prices`, `shipping_cost`, `courier_name`, `expected_date`, `discount`, `total_amount`, `notes`, `status`, `dispatch_status`, `invoice_sent_at`, `shipping_at`, then `created_at`, `updated_at`); the `HEADINGS`-minus-export-only assertion also excludes the four new keys; new cases: value of each new column against the list row; one-seller and multi-seller cells (distinct, once each, ` | `-joined, first-appearance order, comma inside a name stays readable); discount + shipping total equals the list's Total, formatted `1234.00`; raw status for each status; no phone/email/address key in the row; query count does NOT grow with the number of pre-orders (warm up once first); sellers cell equals the list's Seller column for a pre-order with a soft-deleted seller (T002); `template()` keys unchanged; **exporting then importing the file with `dry_run` yields zero row errors and created counts equal to the rows**.

### Implementation for US3

- [x] T022 [US3] In `app/Services/PreorderExportImportService.php::export()` add the four columns in the order of data-model.md: `preorder_number` first, `sellers` after `customer_name` (names from `Preorder::sellers()` joined with ` | `, `''` when none), `total_amount` after `discount` (`number_format((float) $p->total_amount, 2, '.', '')`), `status` before `dispatch_status` (raw). Add `items.artist` to the existing eager-load (no `withTrashed`, T002). Update the class docblock and the `EXPORT_ONLY` comment (Indonesian): these four are read-only, read by nothing on import, and import still always creates `ordered` orders. Confirm by reading `import()` that no heading lookup collides with `status`/`total_amount`/`preorder_number`/`sellers`. Make T021 pass.

**Checkpoint**: export carries the columns; US3 verifiable alone (no filter change needed).

---

## Phase 6: User Story 4 — Export follows the new filters (Priority: P2)

**Goal**: the export contains exactly the rows on screen for any filter combination.

**Independent Test**: apply customer + category (+ seller, + search) and export — row count and PO numbers equal the list.

### Tests for US4

- [x] T023 [P] [US4] Create `tests/Feature/PreorderExportFilterParityTest.php` (owner via HTTP `GET /preorders/export` with the xlsx parsed, or through the service with the same key arrays — pick the one the existing export tests use): for each of customer, category, seller, `search` by PO number, `search` by product name, status, and combinations, the exported PO numbers equal the list's (`GET /preorders?per_page=100`); no filter = all rows of the active mode (unchanged); `date_from`/`date_to` still narrow; legacy scalar `customer_id` still works; DEMO rows never leak into a LIVE export. Also assert a non-owner/admin still gets 403.
- [x] T024 [P] [US4] Add to `qa-tests/component/PreordersView.test.js` (sequential after T017): with customer + category + seller selected, clicking Export calls `exportPreorders` with `customer_id`, `category_id` and `artist_id` arrays in its params.

### Implementation for US4

- [x] T025 [US4] In `app/Services/PreorderExportImportService.php::export()` replace the hand-copied chain (status/event/customer/fulfillment/dispatch/search) with `->matchingListFilters($filters)`, keeping only `date_from`/`date_to` and `orderByDesc('created_at')` locally; delete the now-dead `$listOf` closure if unused. In `PreorderController::export()` change the whitelist to `$request->only([...Preorder::LIST_FILTER_KEYS, 'date_from', 'date_to'])`. Comment (Indonesian) the deliberate behaviour change: export search now equals list search and the export honours `artist_id`/`category_id`. Make T023 pass; re-run T021's suite (must stay green).

**Checkpoint**: all four stories independently functional.

---

## Phase 7: Polish & Cross-Cutting

- [x] T026 [P] Update `docs/openapi-pos-mvp.yaml` in the same change set: on `GET /preorders` make `customer_id` array-capable (`oneOf` integer / array of integer) and add `category_id[]`; reference the same params on `/preorders/summary`, `/preorders/matching-ids`, `/preorders/{preorder}/neighbors`; on `/preorders/export` add `artist_id`/`category_id`/`customer_id[]`, correct the `search` description, and list the four new export-only columns (and the layout order). Validate the YAML still parses.
- [x] T027 [P] Add a short dated section "Pre-order filters and export columns (feature 066, 2026-11-10)" to `CLAUDE.md` (after the 065 section) recording: the single scope + `LIST_FILTER_KEYS` rule, the export-search behaviour change, export-only columns and their placement, `sellers` equals the list (no `withTrashed`), the opt-in `remote` mode of `BaseMultiSelect`, customer labels name-only. Keep the Speckit block already pointing at this plan.
- [x] T028 Full verification, one run at a time: full backend suite with the `-e` flags (expect only the known 9 `Code128` failures), then `npx vitest run --exclude '.claude/**'` (includes the `localeKeys` guard). Report exact counts; do not claim green with unexplained failures.
- [ ] T029 Real-browser verification of the quickstart scenarios 1–8 (customer filter, category filter, combination + "select all matching", Previous/Next, export file vs list, dry-run re-import, console clean, phone width). If browser tooling is unavailable, leave this task OPEN and say so explicitly — do not mark it done from tests alone (Constitution II).
- [x] T030 Update `specs/066-preorder-filter-export-columns/quickstart.md` "Not yet run" line with what was actually run, and tick this list. Do not commit/push/open a PR unless the user asks.

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** (T003 → T004; T005 parallel; T006 gate after all three) → user stories.
- **US1** needs Phase 2. **US2** needs Phase 2 and shares `PreorderListFiltersTest.php`, `Preorder.php` and `PreordersView.vue` with US1, so run it after US1's edits to those files (T016 after T007, T018 after T010, T019 after T013, T017 after T015).
- **US3** needs only T005 (and T002); it does not need the scope and can run in parallel with US1/US2 (different files: `PreorderExportImportService.php`, `PreorderExportImportTest.php`).
- **US4** needs Phase 2 and T018 (category key) for its category cases, and touches `PreorderExportImportService.php` → after T022.
- **Polish** after the stories it documents; T028 last among automated checks; T029 can only follow a working build.

### Parallel opportunities

- Phase 2: T005 alongside T003→T004.
- US1: T008 ‖ T009 (and T011 after T008's test exists); T014 ‖ T012.
- US3 (T021, T022) ‖ US1/US2 work.
- Polish: T026 ‖ T027.

### Parallel example (after Phase 2)

```text
Dev A: T007 → T010 → T016 → T018            (backend scope: customer, then category)
Dev B: T008 → T011 → T009 → T012 → T013 → T014 → T015 → T019 → T020   (SPA)
Dev C: T021 → T022 → T025 (after T018) → T023   (export)
```

## Implementation Strategy

- **MVP = Phase 1 + 2 + US1** (customer filter): smallest slice that delivers visible value and proves the scope refactor.
- Then US2 (category), US3 (export columns), US4 (export parity) — each is independently shippable; US4 is the only one that changes existing export behaviour (flagged in plan and spec, FR-013).
- Stop after each checkpoint and run the matching `--filter=` suite; the full suite only in T028.

## Notes

- Total: 30 tasks — Setup 2, Foundational 4, US1 9, US2 5, US3 2, US4 3, Polish 5.
- Every task names a file. `[P]` only where files differ and no unfinished dependency exists.
- The pinned layout assertions in `PreorderExportImportTest` change on purpose (T021); this is not drift.
- Known pre-existing failures (9 `Code128`) are not this feature's; never "fix" them here.
