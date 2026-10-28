# Implementation Plan: Stock Page Search, Variant Details, and Product Stock Totals

**Branch**: `079-stock-movements-search-variant-columns` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/048-stock-movements-search-variant-columns/spec.md`

## Summary

Three additive changes, no migration:

1. **Stock page (`/stock`) stays a movement ledger** (spec Q1 = B) and gains a search box, seller and category filters (server-side, on `GET /stock/movements`), plus per-row picture, variant name, seller, category, live available stock and a **History** action that opens the existing `VariantHistoryModal`.
2. **History dialog title** shows `<variant name> · <SKU>` everywhere the dialog opens (Stock page and `ProductDetailModal`).
3. **Products list (`/products`)** gets two derived columns per product: total available stock and total stock value = Σ(stock × selling price) over ACTIVE variants (spec Q2 = A, no cost price), computed in the list query (no N+1) and returned only on the list endpoint.

Technical approach: extend the one existing movements endpoint (additive params + response fields, eager-loaded relations), add one reusable model scope for the variant/product/seller/category filter, add two aggregate columns to `ProductController::index` through relation aggregates (so the DEMO/LIVE and soft-delete global scopes still apply), and wire the Vue screens. See [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.3 (Laravel API), JavaScript (Vue 3 SPA, Vite, Tailwind v4)

**Primary Dependencies**: Laravel Eloquent, Pinia, vue-i18n, existing UI kit (`DataTable`, `BaseMultiSelect`, `BaseInput`, `TablePagination`, `usePaginatedList`, `useDebouncedFn`)

**Storage**: MySQL 8 — read-only for this feature; no schema change, no migration

**Testing**: PHPUnit feature tests (`tests/Feature/StockMovementsTest.php`, `tests/Feature/ProductTest.php`) against real MySQL; Vitest (`qa-tests/component/StockView.test.js`, `VariantHistoryModal.test.js`, `ProductsView.test.js`); real-browser check of `/stock` and `/products` (Constitution II)

**Target Platform**: Single-machine local install (Laravel API + SPA on localhost), native or Docker

**Project Type**: Web application (API + SPA in one repo)

**Performance Goals**: Filtering/searching 1,000+ variants returns in under 2 s (SC-005); a movements page and a products page each stay at a constant number of queries regardless of page size

**Constraints**: No N+1; response additive only (existing consumers — `VariantHistoryModal`, export — keep working); no cost price in any new field; DEMO/LIVE separation preserved; access gate unchanged (`stock` OR `products` menu); UI strings in both `en.json` and `id.json` (i18n guard test)

**Scale/Scope**: Booth-sized catalogue (hundreds to low thousands of variants), tens of thousands of movements

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|-----------|------------|
| I. Code quality / single source | PASS. One filter scope on `StockMovement` (no duplicated filter in the controller); one title-label helper in the dialog; totals computed once server-side, never re-derived in the SPA. No new write path (read-only feature; `StockService` untouched). |
| II. Testing | PASS (planned). Feature tests for filters, new fields, access, DEMO/LIVE, soft-deleted product; Vitest for both screens and the dialog title; browser verification listed in quickstart. |
| III. UX consistency | PASS. Token classes only, no hex; reuse `BaseMultiSelect` filter pattern from Products; 403 convention untouched; History button is shown only on a screen the user can already open. Copy goes through i18n (en + id), matching the existing `master_data.*` keys. |
| IV. Security | PASS. Server is the source of truth for totals; no cost field added; access gate unchanged; new response fields (seller, category, image) are already visible to `products`-menu users. |
| V. Performance | PASS. Relations eager-loaded (`variant.product.artist/category`); totals via relation aggregates in the same list query; default product-list payload stays lean (two scalar fields, no variants added). |
| Docs discipline | OPENAPI MUST move in the same commit (`GET /stock/movements`, `GET /products`); CLAUDE.md + RUNBOOK note; PRD needs no scope note (no cut feature is revived). |

**Post-design re-check**: unchanged — no violation, Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/048-stock-movements-search-variant-columns/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── stock-movements.md
│   └── products-list.md
├── checklists/requirements.md
└── tasks.md              # produced later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Models/StockMovement.php                       # + scopeMatchingListFilters()
├── Http/Controllers/Api/StockController.php       # movements(): filters + eager loads + new fields
├── Http/Controllers/Api/ProductController.php     # index(): total_stock, total_stock_value aggregates
└── Http/Resources/ProductResource.php             # emit totals only when the aggregates are present

resources/js/
├── views/StockView.vue                            # search + seller/category filters, new columns, History
├── views/ProductsView.vue                         # two new columns
├── components/product/VariantHistoryModal.vue     # title shows name · SKU
└── locales/{en,id}.json                           # new keys, history_title wording

tests/Feature/StockMovementsTest.php               # extend
tests/Feature/ProductTest.php                      # extend (totals)
qa-tests/component/{StockView,VariantHistoryModal,ProductsView}.test.js   # extend

docs/openapi-pos-mvp.yaml                          # same commit as the response changes
```

**Structure Decision**: Existing single Laravel + Vue repo layout; every change extends a file that already owns the concern. No new service class is warranted: the logic is one query scope and two aggregates (Constitution I — no speculative abstraction).

## Complexity Tracking

No Constitution violations; table intentionally empty.
