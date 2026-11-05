**Classification: INTERNAL**

# Implementation Plan: Variant Name in the Stock Excel Export

**Branch**: `085-stock-export-variant-name` | **Date**: 2026-11-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/060-stock-export-variant-name/spec.md`

## Summary

The Stock page's Excel button calls `GET /exports/stock`, which writes the `stock` sheet (one row per variant: `sku, current_stock, reason`). Add one read-only `variant_name` column right after `sku`. Same pattern as feature 041 (product export) and 050 (pre-order export): the export gets its own heading list (`STOCK_EXPORT`), while `MasterDataSheets::headings('stock')` stays as the import/template contract. The importer already tolerates extra columns, so the exported file still re-imports and the name is ignored. No migration, no new route, no frontend change.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (backend only)

**Primary Dependencies**: Existing Maatwebsite Excel exports (`SheetArrayExport`, `MultiSheetArrayExport`)

**Storage**: N/A (read-only; no schema change)

**Testing**: PHPUnit feature tests on MySQL 8 (`tests/Feature/MasterDataExportTest.php`, `MasterDataImportTest.php`). Run inside the container only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` (see CLAUDE.md).

**Target Platform**: Local store install (native or Docker)

**Project Type**: Web service (Laravel API + Vue SPA); this change touches the API only

**Performance Goals**: No change. Same single query, one extra attribute per row.

**Constraints**: Export must stay re-importable (spec FR-007); row set and order unchanged (FR-003); `=`-leading text stays text (FR-005)

**Scale/Scope**: One column on one sheet; about 4 source files plus tests and docs

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Code quality / single source of truth | PASS. Headings stay in `MasterDataSheets` (one place); rows stay in `MasterDataExportService`. No second list for the same data. `STOCK_EXPORT` mirrors the established `PRODUCTS_EXPORT`. |
| II. Testing | PASS. Update the test that pins the stock header; add cases for name, blank name, formula-looking name, re-import. |
| III. UX consistency | PASS. No UI change; header name matches the existing `variant_name` convention. |
| IV. Security | PASS. Same gates (`canAccessAnyMenu` and `canDo(stock, export)`); no new data class exposed (variant name is already visible on the Stock screen). Formula-injection protection is inherited from `SheetArrayExport`. |
| V. Performance | PASS. No extra queries. |
| Stack constraints (MySQL, docs move with the change) | PASS. OpenAPI description of `/exports/{entity}` is updated in the same commit. |

No violations; Complexity Tracking not needed. Re-checked after Phase 1: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/060-stock-export-variant-name/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/stock-export.md
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Support/MasterDataSheets.php               # add STOCK_EXPORT; exportHeadings() returns it for 'stock'
app/Services/MasterDataExportService.php       # stockRows(): add 'variant_name'
tests/Feature/MasterDataExportTest.php         # update header assertion; add new cases
tests/Feature/MasterDataImportTest.php         # add re-import of the new export (if not already covered)
docs/openapi-pos-mvp.yaml                      # /exports/{entity}: document stock column order
docs/RUNBOOK.md                                # only if it lists stock export columns (verify)
CLAUDE.md                                      # short dated note in the master-data export section
```

**Structure Decision**: Backend-only edit inside the existing master-data export path. `StockView.vue` keeps calling `exportMasterData('stock')`.

## Complexity Tracking

None.
