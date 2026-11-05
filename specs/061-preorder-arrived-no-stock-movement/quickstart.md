**Classification: INTERNAL**

# Quickstart: Verify Arrival Without Stock Movement

## Automated (container, always with the test-DB overrides)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app \
  php artisan test --filter='PreorderTest|PreorderUpdateTest|StockMovementsTest|StockTransactionSyncTest|PreorderPaymentLedgerTest|PreorderSplitTest|SettlePaidArrivedPreordersCommandTest'
```

Never run `php artisan test` in the container without those `-e` flags: it wipes the dev database.

Expected: all pass. Key assertions:
- Moving to Goods arrived leaves stock unchanged and writes no `stock_movements` row.
- Hand-over deducts once and is refused (409) when stock is too low.
- Editing quantities after arrival writes no movement.
- Paying fully, then moving to Goods arrived, ends `settled`.
- The command: dry-run writes nothing; `--apply` settles stuck rows in DEMO and LIVE, logs each, and a second run does nothing.

## Manual (dev database `boothpos`)

1. Note the stock of a variant (Stock page). Create a pre-order for it, record a down payment, click **Mark goods arrived**. Stock is unchanged and there is no new history row for the pre-order.
2. Pay the remaining balance. The pre-order becomes Settled. Click **Mark handed over**. Stock drops by the quantity, with one hand-over history row.
3. Repeat but pay in full BEFORE marking arrived. After **Mark goods arrived** the status is Settled and **Mark handed over** is available.
4. Edit the quantity of an arrived pre-order. Stock stays the same.
5. Correction on dev data (PO-20261001-0004 is the known stuck row):

   ```bash
   docker compose exec app php artisan app:settle-paid-arrived-preorders
   docker compose exec app php artisan app:settle-paid-arrived-preorders --apply --by=owner
   docker compose exec app php artisan app:settle-paid-arrived-preorders   # now lists nothing
   ```

   Open PO-20261001-0004: Settled, and **Mark handed over** appears. (Handing it over needs enough stock of its variant.)
6. Check the toast text after marking arrived no longer says stock increased (EN and ID).

## Not covered by automation

The real browser view of the status buttons and the toast. Not verified yet.
