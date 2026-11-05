**Classification: INTERNAL**

# Research: Variant Name in the Stock Excel Export

All findings come from reading the code in this repo (facts), except where marked ASSUMPTION.

## D1. Which export does the Stock page use?

- **Fact**: `StockView.vue` calls `exportMasterData('stock')`, which hits `GET /exports/stock` (`MasterDataExportController::show`). It writes a single `stock` sheet built by `MasterDataExportService::stockRows()`: one row per `ProductVariant`, ordered by `sku`, with `sku`, `current_stock`, `reason` (null).
- **Decision**: change this path. The on-screen movement ledger (`GET /stock/movements`) is not involved.

## D2. Shared headings: how to avoid breaking import and the template

- **Fact**: `MasterDataSheets::headings('stock')` is used by the export, the import-template download, and the importer's heading check. `exportHeadings()` already splits export from import for `products` (`PRODUCTS_EXPORT`, feature 041).
- **Decision**: add `STOCK_EXPORT = ['sku', 'variant_name', 'current_stock', 'reason']` and have `exportHeadings('stock')` return it. Leave `headings('stock')` untouched, so the template and importer contract do not change (spec FR-008).
- **Alternatives rejected**:
  - Add `variant_name` to `headings('stock')`: it would appear in the import template and invite users to type a name that is never saved.
  - A separate export class for stock: duplicates the existing sheet/export machinery for no gain.

## D3. Does the importer accept the extra column?

- **Fact**: `MasterDataImportService::headingsAreRecognized()` only fails a sheet when none of its headings match the known list. Unknown extra columns are ignored, and values are read by column name.
- **Decision**: no importer change. The name column is ignored on import (FR-007). A test pins this.

## D4. Column position

- **Decision**: `variant_name` directly after `sku` (FR-001). Importer reads by name, so position does not affect re-import.

## D5. Source of the name

- **Fact**: `ProductVariant` has a `variant_name` attribute (in `$fillable`). `stockRows()` already loads every variant through the model, so the value is on hand with no extra query.
- **Decision**: use the live attribute, never a snapshot (FR-002). Column header equals the attribute name, matching the products sheet (FR-010).

## D6. Formula safety

- **Fact**: `SheetArrayExport` uses `KeepsFormulaLookingTextAsText` (only `=`-prefixed strings are forced to text). Stock rows go through `SheetArrayExport`.
- **Decision**: nothing to add; add a test with a name like `=1+1` (FR-005, SC-005).

## D7. Row set, DEMO/LIVE and soft-deletes

- **Fact**: `stockRows()` uses `ProductVariant::query()`, so the DEMO/LIVE global scope and soft-delete scope already apply.
- **Decision**: no change to the query (FR-003). Blank names pass through as null/empty (FR-004).

## D8. Existing test that must change

- **Fact**: `MasterDataExportTest::test_stock_export_lists_every_variant_with_its_current_stock` asserts the header is exactly `['sku', 'current_stock', 'reason']`.
- **Decision**: update it to `['sku', 'variant_name', 'current_stock', 'reason']` in the same commit. Expected, intentional change.

## D9. Docs

- **Fact**: `docs/openapi-pos-mvp.yaml` documents `/exports/{entity}` and already describes the products sheet's own column order. CLAUDE.md requires OpenAPI to move with any response change.
- **Decision**: add a sentence for the stock sheet's order. Check `docs/RUNBOOK.md` for stock column mentions (ASSUMPTION: likely none; verify during implementation).

## Open questions

None. No NEEDS CLARIFICATION remain.
