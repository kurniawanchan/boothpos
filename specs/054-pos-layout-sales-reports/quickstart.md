**Classification: INTERNAL**

# Quickstart: running and verifying 054

## Run

```bash
# backend tests - safe ONLY with explicit overrides inside Docker (it has wiped the dev DB twice)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='PaymentMethodChange|OrderReference|SalesByProduct|SalesByPaymentMethod|CashierSessionList|BulkReceipts|SalesTransactions'
docker compose exec app php artisan migrate        # applies the reference columns to the dev DB
npx vitest run --exclude '.claude/**'              # frontend
npm run build                                       # or `npm run dev` for Vite on :5173
```

Seed for manual checks: `php artisan db:seed` then `php artisan db:seed --class=SakanaFridgeDemoSeeder` (DEMO mode, 8-variant "CATS!!!" product exists in the user's data; otherwise use any product with ≥ 3 variants). Log in as `owner` / `password123` (cashier checks: `kasir01`).

## Manual verification (real browser, console open, no errors)

**POS (US1–US4)** — tablet width (~1024 px) and laptop width
1. Open a product with 3+ variants; tap two variants. Picker stays open, each shows "×N in cart", cart has both. Press bottom **Close** — picker closes, cart unchanged. Tap a 1-variant product: adds directly, no picker.
2. Cart header shows **Save as draft** and **Draft transactions**; the search row has neither. Save a draft, resume it.
3. Seller pills sit beside the (about half-width) search box; "All sellers" first and selected; swipe/scroll sideways; select two sellers; "All sellers" clears. Category pills on their own row below the search; combine with a seller.
4. Scan path unchanged: type an exact SKU + Enter adds it.

**Sales (US5–US9)**
5. Choose an event, a cashier and a date; KPI cards, the summary line and the row count all agree. Pick **All events** from the dropdown (selectable now). Change a filter: back to page 1; page through, reload the page: same page/filters.
6. Transaction numbers and totals never wrap (shrink the window: table scrolls sideways).
7. Open a cash sale in an open shift → change a payment to QRIS (channel required) → appears as QRIS, "Not verified"; activity log has the row. Try a cash payment whose shift is closed and one with change given → refused with a clear message. As `kasir01` on someone else's sale: no change action.
8. Add a note and an image to a sale; a link/icon shows on its row; click opens note + image. Upload a `.pdf`/oversize image → rejected. As a cashier who did not make the sale: can view, cannot edit. Image URL requested without a token → 401/403.
9. Filter to ~10 sales → **Download all receipts** → zip with one PDF per sale; progress shown; try > 200 → clear limit message.

**Reports (US10–US11)** — as `owner`
10. Reports → **Sales by product**: units/sales match Sales page for the same scope; top 3 carry "Best seller"; search a product name; open a row → its transactions → click opens the sale.
11. Reports → **Sales by payment method**: per method counts/amounts; total equals Sales page cash+non-cash; a split-payment sale appears under both; the sale corrected in step 7 appears under QRIS only.

**Sessions (US12)**
12. Sessions history: owner sees all cashiers; `kasir01` only own. Open a closed shift: opening, expected, counted, difference match what `close` stored; transactions list; click a sale. Open an in-progress shift: "not closed yet" + expected so far.

## Regression guards to run

- `CloneRegistryDriftTest` + clone parity test (new `orders` file column).
- `localeKeys.test.js` (both locales have every new key).
- Existing `PaymentLedgerTest`, `PaymentVerificationTest`, `SalesPageDataTest`, `SalesTransactionsTest`, `ReportDataModeIsolationTest` (new raw queries filter `data_mode`).
- Parity test: client-derivable KPI sums over an unfiltered list equal `GET /reports/sales` `pos_*` totals.

## Cleanup notes

- Reference images live in `storage/app/private/order-references/`; a migration rollback drops the columns but leaves files (delete the folder manually on a dev machine if needed).
