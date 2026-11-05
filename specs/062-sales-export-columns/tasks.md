**Classification: INTERNAL**

---

description: "Tasks for the revised Sales transaction Excel export"
---

# Tasks: Sales Transaction Export, Revised Columns

**Input**: Design documents from `/specs/062-sales-export-columns/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/sales-export.md, quickstart.md

**Tests**: Included (constitution Principle II, and the current tests pin the old columns).

**Organization**: Grouped by user story. Backend only; no migration, route or frontend change.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 to US4

**Test command (container, mandatory overrides, see CLAUDE.md):**
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`
Never run `php artisan test` in the container without those `-e` flags: it wipes the dev database. Run test commands one at a time.

**Test helpers in `tests/Feature/SalesTransactionsExportTest.php`**: `makeOrder()` (orders through `OrderService`, customer "Budi" with phone and email), `exported()` (rows from the export object via `Excel::fake()` and `rows()`; sale rows only), `realWorkbook()` (the real file read back with PhpSpreadsheet: returns `[rows incl. header and any total row, drawing count]`).

## Phase 1: Setup

- [X] T001 Run the baseline for `SalesTransactionsExportTest|SalesActionsMatrixTest|SalesTransactionsTest|SalesPageDataTest` and the `tests/Feature/SellerScope` suites; note any failure that exists before editing — Baseline: 52 passed (export, matrix, list, page-data) and 60 passed (SellerScope).

---

## Phase 2: Foundational

None. Each story touches different parts of the same three files and is testable on its own.

---

## Phase 3: User Story 1 - A cleaner, shareable transaction sheet (Priority: P1) 🎯 MVP

**Goal**: The export has no customer, gross sales, discount, cost, margin or paid column for any role, and no customer detail anywhere. Kept columns keep their values.

**Independent Test**: Export as an owner and as a cashier: neither file has the six columns, and `net_sales`, `total`, `cash`, `non_cash`, `balance`, `units` are unchanged.

### Tests for User Story 1 (write first, confirm they fail)

- [X] T002 [US1] In `tests/Feature/SalesTransactionsExportTest.php` rewrite `test_rows_have_readable_columns_with_numeric_money` (~line 109): assert the six removed keys are ABSENT (`assertArrayNotHasKey`) and the kept ones still carry their values (`status`, `sellers`, `cashier`, `payment_methods`, `units`, `total`, `cash`, `payment_status`, `balance`); drop the `customer` and `paid` assertions
- [X] T003 [US1] Rewrite `test_a_partially_paid_sale_exports_its_paid_and_balance` (~line 132) as `..._exports_its_balance_without_a_paid_column`: `balance` is 20000 and `paid` is absent (FR-001, FR-003)
- [X] T004 [US1] Rewrite `test_a_cashier_gets_no_cost_or_margin_but_an_owner_does` (~line 171) as `test_neither_a_cashier_nor_an_owner_gets_cost_or_margin_in_the_file`: both rows lack `cost` and `margin` (FR-001)
- [X] T005 [US1] Rewrite `test_the_columns_follow_the_screen_order_with_total_before_margin_for_an_owner` (~line 339) as `test_kept_columns_keep_their_relative_order`: with `array_intersect` of the expected order against the actual keys, the sequence of kept columns is `transaction_no, status, time, sellers, cashier, items, units, net_sales, payment_methods, payment_status, verification, cash, non_cash, total, balance, reference_note, payment_proofs` (FR-003)
- [X] T006 [US1] Strengthen `test_customer_contact_details_never_leave_in_the_file` (~line 161): use `realWorkbook()` and assert the customer's name ("Budi"), phone and email are found in no cell of the file, not only the phone and email (FR-002, SC-001)
- [X] T007 [US1] Adjust `test_with_a_seller_filter_units_gross_and_net_cover_only_that_sellers_items` (~line 357): rename to `..._units_and_net_cover_only_that_sellers_items`, remove the `gross_sales` assertions, keep units, `net_sales` and `total`
- [X] T008 [US1] Run the rewritten tests and confirm they fail because the columns still exist

### Implementation for User Story 1

- [X] T009 [US1] In `app/Http/Controllers/Api/ReportController.php` `exportSalesTransactions()` remove `'customer'`, `'gross_sales'`, `'discount'` from the row, remove the `if (array_key_exists('cost_total', $r))` block that adds `cost` and `margin`, and remove `'paid'`. Leave `$part['gross']` computation alone only if still used elsewhere in the method; otherwise remove it. Do NOT touch `SalesTransactionsService` (the on-screen list still needs those fields). Update the comment above the remaining `$row += [...]` block
- [X] T010 [US1] Run `SalesTransactionsExportTest` and confirm green

**Checkpoint**: Removals done. This is the MVP.

---

## Phase 4: User Story 2 - See which QR was used and any payment note (Priority: P1)

**Goal**: `qr_type` names the provider of each non-rejected QR payment; `payment_note` lists each non-rejected payment's note.

**Independent Test**: Export a sale paid by GoPay QR with a payment note: `qr_type` is `GoPay` and `payment_note` shows `qr_ewallet: <note>`.

### Tests for User Story 2

- [X] T011 [US2] In `tests/Feature/SalesTransactionsExportTest.php` add a private helper that creates a sale with QR payments through a channel (`PaymentChannel::factory()` with `provider`, type `qr_ewallet`) and optional `notes`, following how the proof tests in this file (~line 282) record non-cash payments; for a rejected payment, set `verification` to `rejected` directly on the `payments` row
- [X] T012 [US2] Add `test_qr_type_names_the_provider_of_a_qr_payment` (single GoPay payment gives `GoPay`) and `test_qr_type_lists_each_provider_once_for_several_qr_payments` (GoPay, Shopee, GoPay again gives `GoPay, Shopee`) (FR-004)
- [X] T013 [US2] Add `test_qr_type_is_empty_for_cash_and_for_a_qr_payment_without_a_channel_and_ignores_rejected_ones` (FR-004, spec scenario 6) — Adjusted from plan: a non-cash payment always needs a channel (DB constraint `chk_payments_channel`), so "QR payment without a channel" cannot exist; the test covers cash, bank transfer, rejected and a soft-deleted channel instead.
- [X] T014 [US2] Add `test_payment_note_lists_one_line_per_payment_note_with_its_method` (two payments with notes give `cash: ...` newline `qr_ewallet: ...` in recorded order) and `test_payment_note_is_empty_without_notes_and_ignores_rejected_payments` (FR-005)
- [X] T015 [US2] Add `test_the_sale_reference_note_is_unchanged_and_separate_from_the_payment_note` (both columns present, different values)
- [X] T016 [US2] Run the new tests and confirm they fail (columns missing)

### Implementation for User Story 2

- [X] T017 [US2] In `app/Services/SalesTransactionsService.php` extend the `$detail` eager loads with `payments.channel` (soft-deleted channels included, e.g. `fn ($q) => $q->withTrashed()`), and in the `$exportOnly` block add `qr_types` (unique `channel.provider` of payments with `method === 'qr_ewallet'` and `verification !== 'rejected'`, ordered by payment id, channel provider non-empty) and `payment_notes` (non-rejected payments ordered by id with a non-empty `notes`, mapped to `"{method}: {notes}"`). Keep both under `$detail` so the JSON list is unchanged
- [X] T018 [US2] In `ReportController::exportSalesTransactions()` add `'qr_type' => implode(', ', $r['qr_types'])` right after `payment_methods`, and `'payment_note' => implode("\n", $r['payment_notes'])` right before `reference_note` (order per research D2)
- [X] T019 [US2] Run `SalesTransactionsExportTest` and confirm green; also run `SalesTransactionsTest` to confirm the JSON list did not gain the export-only keys

**Checkpoint**: QR type and payment note are in the file.

---

## Phase 5: User Story 3 - Items listed one per line, with bullets and category (Priority: P2)

**Goal**: Each item on its own bulleted line with its category; the row is tall enough to read all lines and any pictures.

**Independent Test**: Export a sale with three items: the `items` cell has three lines starting with `•`, each with name, quantity and category, and all three are visible.

### Tests for User Story 3

- [X] T020 [US3] In `tests/Feature/SalesTransactionsExportTest.php` rewrite `test_every_line_is_listed_not_just_the_two_shown_in_the_preview` (~line 143) for the new format: items text equals `"• Name ×qty (Category)"` lines joined by `\n`, one per item, in order (FR-006)
- [X] T021 [US3] Add `test_an_item_without_a_known_category_is_still_listed_without_the_category_part` (soft-delete the category, and a second case with a soft-deleted product) and `test_a_single_item_has_one_bulleted_line` (FR-007) — Adjusted from plan: `products.category_id` is NOT NULL with restrictOnDelete, so a product without a category cannot exist. The test covers soft-deleted category, product and variant instead; the "omit the category part" branch remains as defensive code only.
- [X] T022 [US3] Add `test_item_text_starting_with_an_equals_sign_stays_plain_text` using `realWorkbook()`, and `test_the_items_text_does_not_add_queries_per_sale` comparing query counts for 2 versus 6 sales after one warm-up request (constitution V) — Adjusted: item lines start with a bullet so they can never begin with `=`; the formula-text test targets `qr_type` and `payment_note` instead.
- [X] T023 [US3] Add `test_multi_line_cells_wrap_and_rows_are_tall_enough` using the real file: for a 3-item sale the `items` cell has wrap text on and its row height is at least 3 lines tall (45 pt); for a sale with an embedded picture and one item the row height stays at least 70 pt (FR-008, spec scenario 5)
- [X] T024 [US3] Run the new tests and confirm they fail

### Implementation for User Story 3

- [X] T025 [US3] In `app/Services/SalesTransactionsService.php` (detail only) add the eager chain `items.variant.product.category` with scopes lifted at every level (`withoutGlobalScopes()`, which also returns soft-deleted rows; seller scope must not hide other sellers' lines of a whole order, research D5), and change `items_text` to `"• {name_snapshot} ×{qty}"` plus `" ({category})"` when a category name is known, joined by `"\n"`
- [X] T026 [US3] In `app/Exports/SalesTransactionsExport.php` `styles()`: find the `items` and `payment_note` columns from `headings()`, turn on wrap text, set vertical alignment top for data rows, give those columns widths of about 45 and 35, and set each multi-line row's height to `max(IMAGE_ROW_HEIGHT_PT if the row has pictures, 15 × the larger line count)`. Keep the existing 70 pt rule for picture rows
- [X] T027 [US3] Run `SalesTransactionsExportTest` and confirm green

**Checkpoint**: Items are readable one per line.

---

## Phase 6: User Story 4 - Grand total of net sales (Priority: P1)

**Goal**: One last row, "Grand total", with the numeric sum of the `net_sales` cells above it.

**Independent Test**: Export five sales: the last row is labelled "Grand total" and equals the sum.

### Tests for User Story 4

- [X] T028 [US4] In `tests/Feature/SalesTransactionsExportTest.php` add `test_the_last_row_is_a_grand_total_of_net_sales` using `realWorkbook()`: last row's `transaction_no` is the label, its `net_sales` equals the sum of the data rows, other cells are empty, the value is numeric (FR-009, SC-006)
- [X] T029 [US4] Add `test_the_grand_total_follows_a_seller_filter` (the sum of the scoped `net_sales` values, FR-010) and `test_voided_sales_are_not_in_the_grand_total` (export with `include_voided`, the voided row is present but not summed)
- [X] T030 [US4] Add `test_no_grand_total_row_when_there_are_no_sales` (header only or empty file, no label row) and `test_rows_stays_sale_rows_only_and_exposes_the_total_separately` (`exported()` count equals the sales; `grandTotalRow()` carries the sum)
- [X] T031 [US4] Add `test_the_grand_total_label_is_localised` (English `Grand total`, Indonesian from `lang/id/sales_export.php`)
- [X] T032 [US4] Run the new tests and confirm they fail

### Implementation for User Story 4

- [X] T033 [P] [US4] Add `'grand_total'` to `lang/en/sales_export.php` ("Grand total") and `lang/id/sales_export.php` ("Total keseluruhan"), keeping both files in step
- [X] T034 [US4] In `app/Exports/SalesTransactionsExport.php` `compute()` append the total row when `$this->rows` is not empty: sum the `net_sales` of rows whose `status` is not `voided` in integer cents (divide by 100 for the cell value, a float), label in the first column from `__('sales_export.grand_total')`, all other cells empty, no pictures. Store it for a new public `grandTotalRow()` accessor; leave `rows()` unchanged. In `styles()` bold that last row
- [X] T035 [US4] Run `SalesTransactionsExportTest` and confirm green (including image placement tests, since the extra last row must not shift drawings)

**Checkpoint**: Grand total in place.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T036 Add `test_the_columns_are_exactly_the_revised_list` to `tests/Feature/SalesTransactionsExportTest.php`: the real header row equals `transaction_no, status, time, sellers, cashier, items, units, net_sales, payment_methods, qr_type, payment_status, verification, cash, non_cash, total, balance, payment_note, reference_note, payment_proofs` followed only by image columns
- [X] T037 [P] Update `docs/openapi-pos-mvp.yaml` for `POST /reports/sales/transactions/export`: new column list, the six removals, `qr_type`, `payment_note`, the multi-line `items` format and the "Grand total" row; same commit as the code
- [X] T038 [P] Update `CLAUDE.md` 056 section ("Excel export (US8)" bullet that lists the columns) with a dated note (2026-11-05, feature 062) of the new columns; dated note, not rewritten history
- [X] T039 Run `SalesActionsMatrixTest`, `SalesTransactionsTest`, `SalesPageDataTest` and the `tests/Feature/SellerScope` suites; confirm permissions and the JSON list are unchanged
- [X] T040 Run the full backend suite the safe way (command above, no `--filter`) and compare with T001 and the known 9 `Code128Test` failures; report anything else with its output — Result: 1867 passed, 9 failed, all 9 `Tests\Unit\Code128Test` (known, dev-only reference library).
- [X] T041 Walk through `specs/062-sales-export-columns/quickstart.md` on the dev database (needs the dev stack running); mark the Excel visual check (wrapped lines, row heights, bold total) as not verified if it was not done in a real spreadsheet app — Dev login with the seeded password was refused (401) and was not guessed further. Instead the real export was built in-process as an Owner-role user against the dev data (read-only, temp files removed): 104 sale rows, the Grand total (5,040,000) equals the sum of the rows, `qr_type` shows GoPay/Shopee, items are multi-line with category. NOT verified: how the file looks in Excel (wrapped lines, row heights, bold total) and the Sales page button in a browser.

---

## Dependencies & Execution Order

- T001 first. US1 (T002 to T010) first because it changes the shared row mapping. US2, US3 and US4 are independent of each other and can follow in any order, but all edit `SalesTransactionsExportTest.php` and `ReportController.php`/`SalesTransactionsExport.php`, so do them in sequence unless two people work on separate branches.
- Within a story: tests, then the failing run, then implementation, then the green run.
- T033 (lang files) is independent and can run any time before T034.
- Polish after all stories; T036 needs US1 to US2 done; T040 and T041 last.

### Parallel Opportunities

- T033 (lang) alongside any US4 test writing.
- T037 and T038 (docs, different files) together once the behaviour is settled.
- Little else: most tasks share one test file and two production files.

## Implementation Strategy

- **MVP**: T001 to T010 (User Story 1): the six columns are gone.
- **Then**: US2 (QR type and payment note), US4 (grand total), US3 (items layout), then polish.
- **Commit**: one commit with code, tests, lang, OpenAPI and CLAUDE.md, as in earlier features.

## Notes

- Total: 41 tasks. US1: 9 (T002 to T010). US2: 9 (T011 to T019). US3: 8 (T020 to T027). US4: 8 (T028 to T035). Setup: 1. Polish: 6 (T036 to T041).
- Production edits: `ReportController.php`, `SalesTransactionsService.php`, `SalesTransactionsExport.php`, two lang files.
