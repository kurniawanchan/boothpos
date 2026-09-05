# Quickstart: Restore Sales Receipt Action & Event Info in Receipt Footers

## US1 — View receipt from the Sales list

1. Open `/sales`. Confirm each transaction row shows both "View items" (unchanged) and a new
   "View receipt" action.
2. Click "View receipt". Confirm the same `ReceiptModal` document opens as would be shown
   right after completing that sale (store header, items, totals, payment summary).
3. Download the receipt (image and/or PDF). Confirm the downloaded file matches.
4. Click "View items" on the same row. Confirm the existing products-sold popup still opens
   unchanged.

## US2 — Event info in receipt/invoice footers

1. Open a POS receipt (via US1's new action) for a sale tied to an event with a name,
   location, and both start/end dates set. Confirm the footer shows all three.
2. Open a pre-order invoice/receipt for a preorder tied to the same kind of event. Confirm the
   footer shows the same info.
3. Create/use a pre-order with no `event_id`. Confirm its invoice's footer has no event-info
   block at all — not blank labels.
4. Use an event with a location but only a start date (no end date). Confirm the footer shows
   the location and just the start date, not a broken range.
5. Use an event whose start date equals its end date. Confirm the footer shows one date, not a
   "same – same" range.
6. Download both documents and confirm the footer appears identically in the downloaded file.

## Regression check

- `php artisan test --filter=OrderTest` and `php artisan test --filter=PreorderTest` — existing
  tests still pass; new tests cover the four new response fields on both endpoints.
- `npm test` — `SalesView`/`ReceiptModal`/`PreorderInvoiceModal` component tests still pass; new
  tests cover the restored receipt action and the footer's conditional rendering.
- Manual browser check in both DEMO and LIVE mode.
