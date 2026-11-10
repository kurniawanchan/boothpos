**Classification: INTERNAL**

# Quickstart / Verification: Pre-order Filters and Export Columns

## Backend (inside the `app` container — the explicit `-e` flags are mandatory, see CLAUDE.md)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderListFiltersTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderExportImportTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=Preorder
```

Run one `artisan test` at a time (two runs corrupt `boothpos_test`). The 9 `Code128` failures are pre-existing and unrelated.

## Frontend

```bash
npx vitest run --exclude '.claude/**' qa-tests/component/CustomerFilterSelect.test.js qa-tests/component/BaseMultiSelectRemote.test.js qa-tests/component/PreordersView.test.js
npx vitest run --exclude '.claude/**'      # full suite incl. localeKeys guard
```

## Manual / real-browser scenarios (Constitution II — must be run before "done")

Dev data: `php artisan db:seed` + `SakanaFridgeDemoSeeder` (DEMO mode). Sign in as `owner` / `password123`.

1. **Customer filter** — Pre-orders → Customer dropdown opens with names without typing; type part of a name → list narrows;
   pick two customers → rows of both; the summary cards and the row count follow; clear → full list.
2. **Category filter** — pick one category → every row contains an item of it (open two rows to confirm); a pre-order with
   two same-category items appears once; pick two categories → union.
3. **Combination** — Category + Customer + a status → intersection; "Select all N matching" count equals the list total.
4. **Detail navigation** — open a row, use Previous/Next: it walks only the filtered set.
5. **Export** — with Customer + Category (+ Seller) active, click Export: row count in the file = list total; first column is
   `preorder_number`; `sellers`, `status`, `total_amount` match the list for 3 sampled rows (include a multi-seller order and one
   with discount + shipping).
6. **Round-trip** — import the exported file through "Import" with dry run: zero row errors, nothing about the new columns.
7. **Console** — no errors in the browser console during 1–6; no failed requests other than expected.
8. **Phone width** — the filter row wraps without horizontal page scroll.

**Status of the manual scenarios above (2026-11-10): NOT run in a real browser** — no browser tool was connected in the implementing
session (playwright / chrome-devtools MCP failed to connect), so scenarios 1–8 remain OPEN (task T029). Do not treat this feature as
browser-verified until they are run.

**Baseline (T001, 2026-11-10, before any change):** backend `--filter=Preorder` 368 passed / 3358 assertions, 0 failures; frontend `qa-tests/unit` + `PreordersView.test.js` 34 files / 305 tests passed.

## What WAS verified (2026-11-10)

- **Backend** (inside the container, `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`): full suite 1969 passed, 9 failed — all 9 are
  the known `Tests\Unit\Code128Test` failures (`Picqer\Barcode\Types\TypeCode128B` not found, `picqer` is `require-dev`), unrelated.
  New/changed: `PreorderListFiltersTest` (15), `PreorderExportFilterParityTest` (12 incl. data-provider cases), `PreorderExportImportTest` (37).
- **Frontend**: `npx vitest run --exclude '.claude/**'` → 147 files, 1306 passed, 2 skipped. New: `BaseMultiSelectRemote` (3), `CustomerFilterSelect` (4),
  4 added cases in `PreordersView.test.js`.
- **Mutation checks** (each turned the intended test red, then was restored): dropping `withTrashed()` on the product lookup; dropping the
  `items.artist` eager-load.
- **Real dev data, no HTTP** (`php artisan tinker`, live mode, 40 pre-orders; counts only, no customer data printed): for 3 categories and 2
  customers the filter scope count = raw SQL count = export row count; all 40 exported rows have `sellers`/`total_amount`/`status` equal to
  the list's derivation; first export columns are `preorder_number, customer_name, sellers`. The dev data has no multi-seller or seller-less
  pre-order, so those shapes are covered by the feature tests only.
- **Not done**: the seeded `owner / password123` login returned 401 on the dev server (credentials differ in this database), so no HTTP
  call was made against the dev instance.
