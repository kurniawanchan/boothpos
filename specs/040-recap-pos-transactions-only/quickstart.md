# Quickstart / Verification: 040-recap-pos-transactions-only

Host only, TEST database (`APP_ENV=testing`, `boothpos_test`); never touch `boothpos`.

## Automated

```bash
APP_ENV=testing php artisan test --filter='ReportPosPreorderSplit|ReportTest|ReportDataModeIsolation|Settlement'
npx vitest run qa-tests/component/ReportsView.test.js qa-tests/component/ArtistTransactionsModal.test.js qa-tests/component/DashboardView.test.js
APP_ENV=testing php artisan test && npm test
```

## Real browser (isolated server :8091, test DB: `migrate:fresh --seed`, `license:dev-activate`, `npm run build`, seed an event with sellers that have: POS only, POS + pre-order, pre-order only, nothing; one voided POS sale; one seller whose recorded payment exceeds its POS-only payable)

1. Reports → Seller Recap: no POS/Pre-order columns; Unit and Sales = POS only; every active seller listed; Grand Total = sum of rows.
2. "Transaction detail" of the mixed seller lists POS only, no badge/type column; amounts add up to the row's Sales. Pre-order-only seller: empty state.
3. Payable = Sales, Outstanding = Payable − Paid (0, never negative, for the over-paid seller); "Record payment" absent for a seller with nothing outstanding; record a partial payment → Paid/Outstanding/Status update.
4. Seller filter + event change behave as before. Close the event → stored settlement equals the screen.
5. Export: "Rekap" has no pre-order columns and matches the screen; "Detail Transaksi" sums to it.
6. Pre-order tab, Cost & Profit, Seller Cost unchanged (still include pre-orders); Dashboard "Results per seller" = recap. EN ↔ ID, console clean. Screenshots (no customer data) to `specs/040-recap-pos-transactions-only/evidence/`.
