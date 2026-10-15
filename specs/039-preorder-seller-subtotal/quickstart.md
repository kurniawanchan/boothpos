# Quickstart / Verification: 039-preorder-seller-subtotal

Host only, TEST database (`APP_ENV=testing`, `boothpos_test`); never touch `boothpos`.

## Automated

```bash
APP_ENV=testing php artisan test --filter='PreorderSellerSubtotals|PreorderReport|ReportTest'
npx vitest run qa-tests/component/ReportsPreorderSubtotal.test.js
npm test
```

## Real browser (isolated server :8091, test DB; seed an event with several sellers and pre-orders across statuses/payment states, including a seller with one row and a paid pre-order with collected > order value)

1. Reports → Pre-order → By Seller: after each seller's last row a "Subtotal — <seller>" row (lighter than Grand Total, heavier than a data row), empty status/completeness cells, no Detail button; single-row seller also has one.
2. Add up one seller's rows by hand = its Subtotal; Σ subtotals = Grand Total (all four figures); outstanding subtotal = Σ of the shown outstanding values (also for the clamped row).
3. Choose a single seller in "All sellers": only that seller + its subtotal + Grand Total (equal). Change event → numbers recompute; empty event → no subtotal / grand total.
4. Export → open the "Per Seller" sheet: Subtotal rows after each seller, matching the screen; Summary sheet unchanged.
5. EN ↔ ID ("Subtotal" label), console clean. Screenshots (no customer data) to `specs/039-preorder-seller-subtotal/evidence/`.
