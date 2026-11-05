**Classification: INTERNAL**

# Implementation Plan: Sales Transaction Export, Revised Columns

**Branch**: `087-sales-export-columns` | **Date**: 2026-11-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/062-sales-export-columns/spec.md`

## Summary

All changes are in the Sales Excel export (`POST /reports/sales/transactions/export`). No migration, no new route, no frontend change, and the on-screen Sales list and its JSON do not change.

1. **Remove six columns** in `ReportController::exportSalesTransactions()`: `customer`, `gross_sales`, `discount`, `cost`, `margin`, `paid`.
2. **Add two columns**: `qr_type` (providers of non-rejected `qr_ewallet` payments) and `payment_note` (one line per non-rejected payment note, prefixed with the method). Both are built in `SalesTransactionsService` under `$detail` only.
3. **Items cell**: `items_text` becomes one bulleted line per item with category, `• name ×qty (category)`, joined by newlines. This needs the item's category, loaded in one eager-load chain (no per-row queries).
4. **Row layout**: in `SalesTransactionsExport` turn on wrap text for the items and payment-note columns and set row height from the number of lines (never below the existing picture row height).
5. **Grand total row**: `SalesTransactionsExport` itself appends one "Grand total" row after the data rows, holding the sum of the `net_sales` cells it is laying out (so it cannot disagree with them), excluding voided sales, bold, and none when there are no rows. `rows()` keeps returning sale rows only, so existing row counts and the `exported()` test helper are unaffected; a new `grandTotalRow()` accessor exposes the total for assertions.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel

**Primary Dependencies**: Maatwebsite Excel via the existing `SalesTransactionsExport`; PhpSpreadsheet styles

**Storage**: None (read-only)

**Testing**: PHPUnit feature tests on MySQL 8 (`SalesTransactionsExportTest`, plus the seller-scope and actions-matrix suites that touch the export). Container runs MUST use `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`.

**Target Platform**: Local store install (native or Docker)

**Project Type**: Web service + SPA (backend-only change)

**Performance Goals**: No extra queries per row. Category and channel are eager-loaded once per export.

**Constraints**: Same permissions, filters, seller restriction and embedded-image limit; money stays numeric; `=`-leading text stays text; no customer data in the file

**Scale/Scope**: 3 production files, 2 lang files, 1 test file (plus checks on 2 others), docs

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Single source / DRY | PASS. Row data stays in `SalesTransactionsService` (one builder for the screen and the file); the file's column mapping stays in the controller; layout stays in `SalesTransactionsExport`. The grand total is computed by the export class from the very `net_sales` cells it writes, so it cannot disagree with the rows. |
| II. Testing | PASS. Tests that pin the old columns are rewritten; new tests for each story, including seller-scoped total, voided exclusion and the empty case. |
| III. UX consistency | PASS. New lang keys in EN and ID; column names follow the existing snake_case style; no screen change. |
| IV. Security / privacy | PASS. This REMOVES customer, cost and margin from the file; a test greps the whole workbook for customer name, phone and email. No new data class: payment notes and channel provider are already visible in the Sales detail. Category lookup ignores scopes only to read a label for lines the user already sees (Q1:B whole-order rule). |
| V. Performance | PASS. Three eager-load chains (`payments.channel`, `items.variant.product.category`), detail only; a test asserts the query count does not grow with rows. |
| Stack constraints | PASS. MySQL tests; OpenAPI and CLAUDE.md move in the same commit. 9 known `Code128Test` failures are unrelated. |

No violations; Complexity Tracking not needed. Re-checked after design: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/062-sales-export-columns/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/sales-export.md
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/ReportController.php   # exportSalesTransactions(): drop 6 columns, add qr_type + payment_note
app/Services/SalesTransactionsService.php       # detail-only: qr_types / payment_notes export keys, bulleted items_text, eager loads (payments.channel, items.variant.product.category)
app/Exports/SalesTransactionsExport.php         # wrap text, row height from line count, grand total row (computed + bold), column widths
lang/en/sales_export.php, lang/id/sales_export.php  # grand_total label
tests/Feature/SalesTransactionsExportTest.php   # rewrite column/cost/margin/customer/gross tests, add new ones
tests/Feature/SalesActionsMatrixTest.php        # check only (export permission), expected unchanged
tests/Feature/SellerScope/*                     # check only if any reads export columns
docs/openapi-pos-mvp.yaml                       # export column list
CLAUDE.md                                       # 056 section: columns list (dated note)
```

**Structure Decision**: Keep the three-layer split already used by feature 056 (service builds rows, controller maps the file's columns and applies seller scoping, export class lays out the sheet). No new classes.

## Complexity Tracking

None.
