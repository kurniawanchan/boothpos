**Classification: INTERNAL**

# Research: Sales Export Revised Columns

Facts come from reading the code and the dev database on 2026-11-05. Anything else is marked ASSUMPTION.

## D1. Where each column comes from today

- **Fact**: `ReportController::exportSalesTransactions()` builds rows from `SalesTransactionsService::build(..., detail: true)` and maps explicit keys: `transaction_no, status, time, customer, sellers, cashier, items, units, gross_sales, net_sales, discount, payment_methods, payment_status, verification, cash, non_cash, total`, then `cost, margin` (only when the service row carries `cost_total`, i.e. owner/admin), then `paid, balance, reference_note, payment_proofs`, plus underscore keys for images.
- **Decision**: do the removals in the controller mapping only. The service keeps `customer_name`, `discount_amount`, `paid_amount`, `cost_total`, `margin_*` because the on-screen list and its JSON still use them (spec FR-014).
- **Alternative rejected**: remove them from the service row. That would break the Sales screen.

## D2. Final column order

`transaction_no, status, time, sellers, cashier, items, units, net_sales, payment_methods, qr_type, payment_status, verification, cash, non_cash, total, balance, payment_note, reference_note, payment_proofs`, then the image columns.

- `qr_type` sits right after `payment_methods` (it refines it). `payment_note` sits next to the other note column (`reference_note`). Spec FR-003 only requires the kept columns keep their relative order, which holds.

## D3. "QR type" = channel provider of QR payments

- **Fact (dev DB)**: `payments.method` values are `cash` (91) and `qr_ewallet` (66); QR payments join to channels with provider `Shopee` (60) and `GoPay` (6), channel type `qr_ewallet`. `payment_channels` has `type` (`bank_transfer`/`qr_ewallet`), `provider`, `account_name`, soft deletes.
- **Decision**: `qr_type` = unique `channel.provider` of payments where `method = 'qr_ewallet'` and `verification != 'rejected'`, in payment order, joined by `, `. Empty otherwise. Channel loaded with trashed so a since-deleted channel still names its provider. A QR payment with no channel (null `channel_id`) contributes nothing.
- **Alternative rejected**: export the channel `type` (always `qr_ewallet`, adds nothing) or the account name (identifies a person's account, more sensitive).

## D4. Payment note

- **Fact**: `payments.notes` exists and is shown in the Sales detail; the export did not include it.
- **Decision**: `payment_note` = for each non-rejected payment ordered by id with a non-empty note: `"{method}: {note}"`, joined by newline. Empty when none. Method is the raw value (`cash`, `qr_ewallet`, `bank_transfer`), matching the `payment_methods` column.
- Distinct from `reference_note` (sale-level), which stays.

## D5. Items: bullets, category, one per line

- **Fact**: `items_text` is `name_snapshot ×qty` joined by `; `, built in the service only when `$detail`, used only by the export.
- **Decision**: `• {name_snapshot} ×{qty}` plus ` ({category})` when known, joined by `\n`. The bullet is `•` (U+2022).
- **Category source**: `order_items` has no category snapshot, so it is read live through `variant.product.category`. Loaded in one chain with scopes lifted (`withoutGlobalScopes()`, which also includes soft-deleted rows) for variant, product and category, because (a) a seller-linked user sees whole orders including other sellers' lines (058 Q1:B) and the seller scope would hide those products, and (b) deleted products/categories must not drop a label. ASSUMPTION: showing today's category name (not the one at sale time) is acceptable, as the pre-order export does for variant names.
- Missing variant/product/category: the line is kept without the parenthesised part.

## D6. Multi-line cells in Excel

- **Fact**: `SalesTransactionsExport::styles()` sets bold headings and a fixed row height (70 pt) on rows with pictures. No wrap text anywhere.
- **Decision**: wrap text on the `items` and `payment_note` columns, vertical alignment top for data rows, column widths about 45 and 35, and an explicit row height of `max(70 if the row has pictures, 15 × lines)` for rows with more than one line. An explicit height is used because Excel does not always auto-fit rows written by PhpSpreadsheet, and the existing picture rows already set heights this way.

## D7. Grand total row

- **Decision**: `SalesTransactionsExport::compute()` appends one row after the data rows when there is at least one sale row: first column = `__('sales_export.grand_total')`, `net_sales` = sum, every other cell empty. The sum is taken in integer cents over the `net_sales` cell of every row whose `status` is not `voided`, so seller-scoped values (already in the rows) are respected and the total equals the visible rows by construction. The row is bold. `rows()` still returns sale rows only; `grandTotalRow()` exposes the total row for tests.
- **Why in the export class, not the controller** (changed from the first draft): tests read rows through `rows()` and many assert row counts, and a total row built by the controller would appear as a fake sale there. The class already owns layout and row indexing.
- **Numeric**: a float with two decimals, like the other money cells, so Excel treats it as a number.
- **Images**: the total row has no pictures; it is last, so the `$i + 2` row maths and drawing placements are unaffected.
- **Alternative rejected**: an Excel `SUM()` formula. `KeepsFormulaLookingTextAsText` deliberately blocks `=`-text, and a formula would hide the value from readers that do not recalculate.

## D8. Tests that pin the old shape

- `SalesTransactionsExportTest`: header list (line ~116), `customer` (~119), `paid` (~128, ~139), cost/margin for owner and cashier (~171-181), cost/margin position (~339-347), `gross_sales` (~366-370). Also any test that counts rows will now see the grand total row.
- `test_customer_contact_details_never_leave_in_the_file` stays and gets stronger (customer name too).
- Check `SalesActionsMatrixTest` and `tests/Feature/SellerScope/*` for assertions on export columns; permissions are unchanged.

## D9. Docs

- OpenAPI for the export endpoint and the 056 section in CLAUDE.md list the old columns ("Columns = the on-screen set ... plus gross_sales, net_sales, verification, reference_note, payment_proofs ..."). Update with a dated note; do not rewrite history.

## Open questions

None.
