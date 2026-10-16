# Implementation Plan: Product Export Column Update

**Branch**: `049-product-export-columns` (git) · spec dir `041-product-export-columns` | **Date**: 2026-10-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/041-product-export-columns/spec.md`

**Note**: This plan was rewritten after the setup script overwrote the previous plan.md with the blank template (2026-10-16). Content is the previous decisions plus the spec iteration 3 scope (total stock column, filter-aware export).

## Summary

The products Excel export (`GET /exports/products`) changes in three ways:

1. **Column layout** (spec US1–US4): artist and category names beside their codes, a short SKU `code`, a readable `item_label`, `profit_margin` after `sell_price`, `variant_name` after `product_name`, eight columns removed. **Export only** (product-owner decision, option A): template and import keep the current 17-column list.
2. **Stock column** (spec US5, FR-012): `total_stock_available` = variant `current_stock`, right after `description`.
3. **Filter-aware export** (spec US6, FR-013–FR-017): the export accepts the same `search`, `artist_id[]`, `category_id[]` filters as the Products list, and the Products screen's export button sends the filters on screen.

Technical approach:
- Export keeps its own header list `MasterDataSheets::PRODUCTS_EXPORT` beside the import list `PRODUCTS` (unchanged).
- **One filter definition**: the list-filter rules move from `ProductController::index()` into a query scope on `Product` (`scopeMatchingListFilters`). The list and the export both call it, so they cannot disagree (Constitution I, single implementation).
- Money math for `profit_margin` reuses `App\Support\ReportSplit`.
- No schema change, no new route, no new permission.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 13); Vue 3 SPA (Pinia, axios client)
**Primary Dependencies**: `maatwebsite/excel` (existing `SheetArrayExport` / `MultiSheetArrayExport`); `App\Support\ReportSplit`
**Storage**: MySQL 8 — read-only for this feature; no migration
**Testing**: PHPUnit feature tests (`tests/Feature/MasterDataExportTest.php`; existing `tests/Feature/ProductTest.php` as the list regression guard) against MySQL `boothpos_test`; frontend unit tests under `qa-tests/unit/` (Vitest) for the export call's params; a browser check of the Products screen export is needed (Principle II — screen the user interacts with)
**Target Platform**: Local single-machine install (native or Docker store image)
**Project Type**: Laravel API + Vue SPA (both touched)
**Performance Goals**: One query for the products export (filters applied as a subquery, no per-row lookups); eager loads unchanged
**Constraints**: Export gated by `canManageMasterData()` (owner/admin/inventory) — unchanged; derived values server-computed; import/template unchanged; the products list response shape unchanged
**Scale/Scope**: 1 model scope, 1 controller change (list uses scope), 1 service change (`productRows()` + filters), 1 export controller change (read filters), 1 SPA API helper + 1 view change, tests, OpenAPI description

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code quality (single source per concern, no speculative abstraction) | **Pass with justified exception** | Filter rules move to ONE scope used by both the list and the export — removes a would-be duplicate. Exception: a second product header list (`PRODUCTS_EXPORT`), justified in Complexity Tracking. |
| II. Testing standards | **Pass (planned)** | Backend feature tests against MySQL for every new rule; the list tests must stay green (scope extraction must not change list results). Frontend: Vitest for the params the export sends. Browser check of Products → Export with a search and a filter (console checked), since a screen the user interacts with changes. |
| III. UX consistency | **Pass** | Export button keeps its label and error toast (`export_product_failed`); filters are the same ones the screen already shows; no new control. |
| IV. Security | **Pass** | Export gate unchanged. Filter values are not trusted to change scope beyond the list rules: they select rows only (whereIn/LIKE), they cannot widen access. Server-side only; the SPA sends values, the server decides. |
| V. Performance | **Pass** | Product filter becomes `whereHas('product', scope)` — one SQL with subqueries; eager loads of artist/category kept; no N+1. Export is on-demand, no cache. |
| Documentation & change discipline | **Pass (planned)** | `docs/openapi-pos-mvp.yaml` `/exports/{entity}` updated (new params + columns) in the same change (PRD §9.5). CLAUDE.md SPECKIT block already points to this plan. |

**Gate result**: PASS with one justified exception (Complexity Tracking). No NEEDS CLARIFICATION remain; the stock interpretation (variant's own stock) is recorded as an assumption in spec.md for product-owner confirmation.

## Project Structure

### Documentation (this feature)

```text
specs/041-product-export-columns/
├── spec.md
├── plan.md                    # this file
├── research.md                # Phase 0
├── data-model.md              # Phase 1
├── quickstart.md              # Phase 1
├── contracts/
│   └── products-export.md     # Phase 1: columns + filter params
├── tasks.md                   # NOT created here (/speckit-tasks)
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/Models/Product.php                          # ADD scopeMatchingListFilters(Builder, array): single definition of list/export filters
app/Http/Controllers/Api/ProductController.php  # CHANGE index(): use the scope (behaviour unchanged)
app/Support/MasterDataSheets.php                # ADD PRODUCTS_EXPORT + exportHeadings(); PRODUCTS unchanged
app/Services/MasterDataExportService.php        # CHANGE rows('products', filters): filtered variants, total_stock_available, derived columns
app/Http/Controllers/Api/MasterDataExportController.php  # CHANGE: read search/artist_id[]/category_id[] for products; exportHeadings()
resources/js/api/masterData.js                  # CHANGE exportMasterData(entity, params = {}) → client.get(..., { params, responseType: 'blob' })
resources/js/views/ProductsView.vue             # CHANGE doExport(): pass the same filter object the list uses
tests/Feature/MasterDataExportTest.php          # CHANGE/ADD export layout, derived values, stock, filter tests
tests/Feature/ProductTest.php                   # NO CHANGE expected — regression guard for the scope extraction
qa-tests/unit/masterDataExport.test.js          # ADD Vitest for exportMasterData params
docs/openapi-pos-mvp.yaml                       # CHANGE /exports/{entity}: filter params + products columns
```

**Structure Decision**: Stay inside the existing master-data export path and the Products list. The only new shared unit is the Product list-filter scope; it is a model scope because the rules belong to the Product query, and both callers already work with Product queries.

## Design Details

### 1. One filter definition (`Product::scopeMatchingListFilters`)

Moves the three rules currently inline in `ProductController::index()`:
- `search`: `name LIKE %term%` OR a variant with `sku LIKE %term%` OR `variant_name LIKE %term%`.
- `artist_id[]`: `whereIn` after dropping empty strings; ignored when empty.
- `category_id[]`: same.

`is_preorder` stays in `index()` only (not a screen filter, FR-013 lists three filters).

`index()` calls `Product::query()->matchingListFilters($request->only(...))` (names kept from the request), then the existing eager loads and paginate. Behaviour identical — guarded by the existing products list tests.

### 2. Export rows (`MasterDataExportService::productRows(array $filters)`)

- Base: `ProductVariant::query()->whereHas('product', fn ($q) => $q->matchingListFilters($filters))`, then the existing eager loads and ordering (product name, sku).
- With empty filters the scope is a no-op, so the export returns all variants as today (FR-015).
- Everything else as in the previous plan: `code`, `item_label`, `artist_name`, `category_name`, `profit_margin` via `ReportSplit`.
- New: `total_stock_available` = `$v->current_stock` (integer; zero written as `0`), placed after `description`.

### 3. Export controller

`show()` reads filters only for `products`: `search` (string), `artist_id` (array), `category_id` (array), normalised with `$request->array()` exactly like the list. Other entities ignore them. Gate unchanged.

### 4. SPA

- `api/masterData.js`: `exportMasterData(entity, params = {})` passes `params` to axios (`responseType: 'blob'` kept). Axios serialises arrays as `artist_id[]=…`, the same encoding `listProducts` already uses.
- `ProductsView.vue` `doExport()`: passes the same filter object that `setFilter()`/`listProducts` uses (search, artist_id, category_id). The debounced search value is used, so the export matches the list after the debounce.

### 5. Header list

`MasterDataSheets::PRODUCTS_EXPORT` order (with the new column):
`sku, code, artist_code, artist_name, category_code, category_name, product_name, variant_name, item_label, description, total_stock_available, cost_price, sell_price, profit_margin, low_stock_alert`

Key order in `productRows()` must match this list (the Excel writer uses array order).

### 6. OpenAPI

`/exports/{entity}`: add query parameters `search`, `artist_id[]`, `category_id[]` (products only) and the new column order; keep the note that the products export may not re-import as-is.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Second product header list (`PRODUCTS_EXPORT`) beside the import list `PRODUCTS` | Product owner chose option A (spec FR-011): export layout differs; import/template keep 17 columns. | Changing the shared list breaks import of initial stock, flags and image columns (option B). |
| Export round-trip not guaranteed for `products` | Accepted trade-off (FR-011). | Option C adds import changes outside scope. |

No other exceptions.
