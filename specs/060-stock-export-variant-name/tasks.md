**Classification: INTERNAL**

---

description: "Tasks for Variant Name in the Stock Excel Export"
---

# Tasks: Variant Name in the Stock Excel Export

**Input**: Design documents from `/specs/060-stock-export-variant-name/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/stock-export.md, quickstart.md

**Tests**: Included. The project constitution (Principle II) requires backend tests for backend changes, and one existing test pins the old header.

**Organization**: Grouped by user story. Backend only; no migration, route or frontend change.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 or US2

**Test command (container, mandatory overrides, see CLAUDE.md):**
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`
Never run `php artisan test` in the container without those `-e` flags: it wipes the dev database.

## Phase 1: Setup

No setup needed. The export path, test files and docs all exist.

- [X] T001 Confirm the baseline is green before editing: run `MasterDataExportTest` and `MasterDataImportTest` (command above) and note any pre-existing failures so they are not blamed on this change

---

## Phase 2: Foundational

No blocking prerequisites beyond T001. Both stories depend only on the heading and row change in US1.

---

## Phase 3: User Story 1 - Recognise each stock row by its variant name (Priority: P1) 🎯 MVP

**Goal**: The stock export shows each variant's current name next to its SKU.

**Independent Test**: Export stock (`GET /api/v1/exports/stock`) and read the `stock` sheet. The header is `sku, variant_name, current_stock, reason`, and every row carries the right name.

### Tests for User Story 1 (write first, confirm they fail)

- [X] T002 [US1] Update `test_stock_export_lists_every_variant_with_its_current_stock` in `tests/Feature/MasterDataExportTest.php` so the expected header is `['sku', 'variant_name', 'current_stock', 'reason']`, and assert the seeded variant's `variant_name` value (FR-001, FR-006)
- [X] T003 [US1] Add `test_stock_export_variant_name_follows_a_rename` to `tests/Feature/MasterDataExportTest.php`: rename a variant after seeding and assert the export shows the new name (FR-002)
- [X] T004 [US1] Add `test_stock_export_keeps_rows_with_a_blank_variant_name` to `tests/Feature/MasterDataExportTest.php`: one variant with an empty/null name still has its row, the name cell is empty, and the row count and SKU order equal the variant count and `sku` order (FR-003, FR-004, SC-004)
- [X] T005 [US1] Add `test_stock_export_writes_a_formula_looking_variant_name_as_text` to `tests/Feature/MasterDataExportTest.php`: a name such as `=1+1` is read back as the literal string, not a computed value. Also include a name with a comma and a quote (FR-005, SC-005)
- [X] T006 [US1] Run the four tests above and confirm they fail for the right reason (missing column), before any implementation

### Implementation for User Story 1

- [X] T007 [US1] In `app/Support/MasterDataSheets.php` add `public const STOCK_EXPORT = ['sku', 'variant_name', 'current_stock', 'reason'];` with a short Indonesian comment saying why it is export-only (read-only helper column; import/template keep `headings('stock')`), and make `exportHeadings()` return it for `self::STOCK` (FR-001, FR-010)
- [X] T008 [US1] In `app/Services/MasterDataExportService.php` update `stockRows()` to include `'variant_name' => $v->variant_name` in each row. Do not change the query, ordering or scopes (FR-002, FR-003)
- [X] T009 [US1] Run `MasterDataExportTest` and confirm T002–T005 now pass and no other test in the file regressed

**Checkpoint**: Stock export shows variant names. This is the shippable MVP.

---

## Phase 4: User Story 2 - Exported file still works for re-import (Priority: P2)

**Goal**: A stock file exported with the new column imports without error, changes only edited stock numbers, and never changes a variant name.

**Independent Test**: Export stock, re-upload it to `POST /api/v1/imports/master-data` (dry run and real), and check the results.

### Tests for User Story 2

- [X] T010 [US2] Add `test_unmodified_stock_export_reimports_with_no_changes` to `tests/Feature/MasterDataImportTest.php`: export via the endpoint, upload the same bytes, assert success, zero errors and no new `stock_movements` rows (FR-007, SC-003)
- [X] T011 [US2] Add `test_edited_stock_number_in_an_export_with_variant_name_is_applied` to `tests/Feature/MasterDataImportTest.php`: change one `current_stock` cell, import, assert only that variant's stock changed
- [X] T012 [US2] Add `test_variant_name_column_in_a_stock_file_is_ignored_on_import` to `tests/Feature/MasterDataImportTest.php`: edit a `variant_name` cell, import, assert the stored variant name is unchanged (FR-007)
- [X] T013 [US2] Add `test_stock_import_template_does_not_list_variant_name` to `tests/Feature/MasterDataImportTest.php` (or the existing template test): the template's `stock` sheet header is exactly `MasterDataSheets::headings('stock')` = `sku, current_stock, reason` (FR-008)

### Implementation for User Story 2

- [X] T014 [US2] Run `MasterDataImportTest`. No production code change is expected, because the importer already ignores unknown columns (research D3). If T010–T013 fail, fix only `app/Services/MasterDataImportService.php` where needed and record the reason in `research.md`

**Checkpoint**: Re-import verified with the new column.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T015 [P] Update `docs/openapi-pos-mvp.yaml` under `/exports/{entity}` (around line 5602): add a sentence that the `stock` sheet's export order is `sku, variant_name, current_stock, reason`, `variant_name` is read-only and ignored on import, and the template is unchanged. Must land in the same commit as the code (CLAUDE.md, PRD §9.5)
- [X] T016 [P] Add a short dated note (2026-11-05) to the master-data export section of `CLAUDE.md` stating that the stock export has an export-only `variant_name` column via `STOCK_EXPORT`
- [X] T017 Confirm `docs/RUNBOOK.md` needs no change: the grep for stock found only the `app:sync-stock-from-transactions` section, not the export columns
- [X] T018 Run the full backend suite the safe way (command above, without `--filter`) and compare with the T001 baseline. Report any failure with its output, noting any that were already failing at T001 — Result: 1837 passed, 9 failed; all 9 are `Tests\Unit\Code128Test` (reference library `picqer` is require-dev, not installed in the container; documented known failure, unrelated to this change)
- [ ] T019 Walk through the manual steps in `specs/060-stock-export-variant-name/quickstart.md` (export from the Stock page, rename and re-export, dry-run re-import, 403 for a cashier). Mark opening the file in Excel as not verified if it was not done

---

## Dependencies & Execution Order

- T001 first, then US1 (T002–T009), then US2 (T010–T014). US2 tests need the US1 export change to exist, so US2 follows US1.
- Within US1: T002–T005 are in one file, so write them in sequence. T006 before T007. T007 before T008 (T008 reads the new column order). T009 last.
- Within US2: T010–T013 are in one file, so write them in sequence, then T014.
- Polish: T015 and T016 are parallel (different files). T018 and T019 come last.

### Parallel Opportunities

- T015 and T016 can run together.
- Docs (T015/T016) can be drafted while US2 tests run.
- Most of the work is in a few files, so parallelism is limited. That is fine for a change this size.

## Implementation Strategy

- **MVP**: T001–T009 (User Story 1). It delivers the requested value on its own.
- **Then**: US2 to lock in the re-import guarantee, then polish.
- **Commit**: one commit with code, tests and the OpenAPI change together.

## Notes

- Total: 19 tasks. US1: 8 (T002–T009). US2: 5 (T010–T014). Setup: 1. Polish: 5.
- Expected production edits: 2 files (`MasterDataSheets.php`, `MasterDataExportService.php`).
