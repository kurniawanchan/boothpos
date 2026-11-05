**Classification: INTERNAL**

# Quickstart: Verify the Revised Sales Export

## Automated (container, always with the test-DB overrides)

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app \
  php artisan test --filter='SalesTransactionsExportTest|SalesActionsMatrixTest|SalesTransactionsTest|SalesPageDataTest'
```

Never run `php artisan test` in the container without those `-e` flags: it wipes the dev database.

Expected: all pass. Key assertions:
- The header is exactly the new column list; none of the six removed columns exists, for an owner and a cashier.
- No customer name, phone or email anywhere in the workbook.
- `qr_type` names each non-rejected QR payment's provider once; empty for cash and bank transfer.
- `payment_note` lists notes one per line with the method; rejected payments are ignored.
- `items` has N bulleted lines for N items, with category, and survives a missing category.
- The last row is "Grand total" with the sum of `net_sales`; seller-scoped exports sum the scoped values; voided sales are excluded; no row when empty.
- Query count does not grow with the number of sales.

## Manual (dev database)

1. Sign in as `owner`, open **Sales**, pick an event with several sales, click **Export .xlsx**.
2. Open the file and check: no customer / gross sales / discount / cost / margin / paid columns; `qr_type` shows GoPay or Shopee for QR sales and is empty for cash; a payment with a note shows `qr_ewallet: ...` in `payment_note`; the `items` cell shows bulleted lines with category and every line is visible; the last row says "Grand total" and equals the sum of `net_sales` (compare with Excel's own SUM).
3. Filter the Sales list by one seller and export again: units and net sales are that seller's share and the grand total matches.
4. Export with **Show voided** on and confirm the voided sale's net sales is not in the grand total.
5. Sign in as `kasir01` and export: same columns, no cost or margin.
6. Check a sale that has embedded pictures: the pictures are still in their columns and the row is tall enough.

## Not covered by automation

How the file looks in Excel (wrapped lines, row heights, bold total row). Not verified yet.
