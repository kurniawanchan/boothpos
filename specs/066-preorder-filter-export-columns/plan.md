**Classification: INTERNAL**

# Implementation Plan: Pre-order Filters (Category, Customer) and Export Columns

**Branch**: `096-preorder-filter-export-columns` (spec dir number ≠ branch number) | **Date**: 2026-11-10 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/066-preorder-filter-export-columns/spec.md`

## Summary

Two new filters on the Pre-orders list (Customer, Product category — both multi-select) and four new export-only
columns (`preorder_number`, `sellers`, `status`, `total_amount`) on the pre-order Excel export. **No migration, no new
route, no new table.**

The one structural move: the list's filter chain (`PreorderController::applyFilters()`, private) and the export's own
hand-copied filter chain (`PreorderExportImportService::export()`) are two diverging implementations — the export only
searches customer name and ignores `artist_id`. Both become ONE model scope, `Preorder::scopeMatchingListFilters()`
(pattern of `Product::scopeMatchingListFilters()` / `StockMovement::scopeMatchingListFilters()`), so the list, summary,
neighbors, matching-ids, bulk preview/apply AND the export can never disagree (FR-006, FR-013, SC-003). Customer filter
becomes array-capable (`customer_id[]`, scalar still accepted). Category filter is a new `category_id[]` evaluated through
`items → variant → product.category_id` (soft-deleted variants/products included, so history stays findable).

Frontend: two `BaseMultiSelect` filters beside the existing four. Category options from the existing `GET /categories`;
customer options need remote search (customers can exceed one page), so `BaseMultiSelect` gets an opt-in `remote` mode
and a thin `CustomerFilterSelect` wrapper keeps selected labels across searches. The existing export button already
forwards the reactive list `params`, so it picks the new filters up once the controller whitelists the keys.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing); Vue 3 + Pinia + vue-i18n (existing)

**Primary Dependencies**: existing only — Maatwebsite Excel (`GenericArrayExport`), Vitest. No new package.

**Storage**: MySQL 8, **no schema change** (`preorders.customer_id`, `preorder_items.variant_id`, `products.category_id`
are already FK-indexed)

**Testing**: PHPUnit feature tests against real MySQL (`tests/Feature/`, run with the explicit `-e` DB overrides inside
the container — see CLAUDE.md warning); Vitest in `qa-tests/`

**Target Platform**: single-store local install (Laravel + SPA on localhost)

**Project Type**: web application (Laravel API + Vue SPA, one repo)

**Performance Goals**: list with both new filters answers in < 2 s on a booth-sized dataset (SC-001); export stays one row
per pre-order with no per-row queries (Constitution V)

**Constraints**: DEMO/LIVE boundary and seller scope unchanged (FR-015); no new personal data in the export (FR-014);
export-only columns must be ignored by import and absent from the template (FR-012)

**Scale/Scope**: hundreds–low thousands of pre-orders per install; customers may exceed one dropdown page

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Single source / no duplicated paths | **Improves** compliance: removes the duplicated filter chain (list vs export) into one scope; seller-name derivation moves from a private controller method to one `Preorder` method used by list AND export. No speculative abstraction — only the two real callers. |
| II. Verify, don't assume | Backend feature tests (filters, parity list↔export↔summary↔neighbors↔matching-ids, export columns, import round-trip, query count) and Vitest. **A real-browser check is required** for the two new dropdowns and export download (quickstart.md); browser tooling was unavailable in recent sessions, so the task stays open until actually run. |
| III. UX consistency | Reuses `BaseMultiSelect`, design tokens only, UI copy in Indonesian + English locale keys (both files, `localeKeys.test.js` guards literals). Filters are visible to every role that has the Pre-orders menu — no role-gated control is shown disabled. |
| IV. Security | Server-side only: scope runs on the already seller-scoped/mode-scoped `Preorder` model; nested lookups keep `DataModeScope` (only soft-delete is lifted with `withTrashed`). Export stays owner/admin + `export` action. No contact details added. |
| V. Performance | Category filter = EXISTS subqueries (no join, no duplicate rows); export eager-loads `items.artist` once (query-count test). Customer dropdown is paginated remote search, not a full dump. |
| Docs discipline | `docs/openapi-pos-mvp.yaml` updated in the same commit (list params, summary/export/matching-ids/neighbors, export columns). No PRD scope change (not a cut/un-cut feature) → no dated PRD note. CLAUDE.md gets a short section. |

No violations → Complexity Tracking empty.

## Project Structure

### Documentation (this feature)

```text
specs/066-preorder-filter-export-columns/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── preorder-filters-export.md
├── checklists/requirements.md
└── tasks.md              # /speckit-tasks — NOT created here
```

### Source Code (repository root)

```text
app/
├── Models/Preorder.php                               # + LIST_FILTER_KEYS, scopeMatchingListFilters(), sellers()
├── Http/Controllers/Api/PreorderController.php       # applyFilters() delegates to the scope; sellersFor() → model;
│                                                     #   export() whitelists the shared keys + date_from/date_to
└── Services/PreorderExportImportService.php          # export() uses the scope; 4 new EXPORT_ONLY columns; items.artist eager-load
resources/js/
├── views/PreordersView.vue                           # customerFilter/categoryFilter refs, 2 selects, applyPreorderFilters()
├── components/ui/BaseMultiSelect.vue                 # opt-in `remote` mode (+ `search` emit); default behaviour untouched
├── components/preorder/CustomerFilterSelect.vue      # NEW thin wrapper: remote search + selected-label memory
└── locales/{en,id}.json                              # preorders.all_customers / all_categories / search placeholder
docs/openapi-pos-mvp.yaml                             # same commit
tests/Feature/
├── PreorderListFiltersTest.php                       # NEW
└── PreorderExportImportTest.php                      # layout pin + new-column cases updated
qa-tests/
├── component/CustomerFilterSelect.test.js            # NEW
├── component/PreordersView.test.js                   # filters → request params, export params
└── component/BaseMultiSelectRemote.test.js           # NEW (default mode regression + remote mode)
```

**Structure Decision**: existing Laravel + Vue layout; no new directories except the spec folder. The only new
production files are one small Vue wrapper and its tests.

## Key Design Decisions (detail in research.md)

1. **One filter scope for list and export** — `Preorder::scopeMatchingListFilters(Builder, array)`; arrays normalised with
   `Arr::wrap` + `array_filter` (same convention as today). Export keeps `date_from`/`date_to` in the service (the list never
   had them). Behaviour change, deliberate and documented: export search now equals list search; export honours `artist_id`.
2. **Customer filter = `customer_id[]`** (was scalar). Scalar callers (the existing deep link / tests) keep working.
3. **Category filter = `category_id[]` via `items → variant → product`**, `withTrashed()` on variant and product.
4. **Export columns** `preorder_number` (first), `sellers` (after `customer_name`), `status`, `total_amount` — read-only,
   importer ignores them by name; the pinned layout tests are updated deliberately. `sellers` = distinct names joined with
   ` | ` in first-appearance order (convention of `variant_names`); `status` = raw status value (like `dispatch_status`);
   `total_amount` = `number_format(…, 2, '.', '')` of the stored grand total (the list's Total column).
5. **Customer options by remote search** through the existing `GET /customers` (name label only — no phone/email shown).

## Risks / Things a Future Change Must Not Break

- The scope is the ONLY place the list filters live — never re-add a filter chain to the controller or the export service.
- `matching-ids`, `neighbors`, `summary` and bulk preview/apply call the same scope, so a new filter is automatically in
  their "select all matching" set; the tests assert this rather than assume it.
- Export-only columns are read by nothing on import; a test guards that an exported file re-imports with zero errors.
- Seller-scoped users cannot export (admin surface), but the list filters work for them: category matching then follows
  their scoped product set — consistent with every other screen, never wider.
- Not verified in a real browser at plan time (see quickstart.md).

## Complexity Tracking

*No constitution violations — nothing to justify.*
