# Quickstart: verifying the POS / Pre-order split

## Automated

```bash
APP_ENV=testing php artisan test --filter='ReportPosPreorderSplit|ReportTest|Settlement'     # host, .env.testing (boothpos_test)
npx vitest run qa-tests/component/ReportsView.test.js
npm test
```

Cases that must fail on the old code:
1. Seller Recap: a seller with POS sales AND a partially paid pre-order → `pos_*` and `preorder_*` present; `pos + preorder = total` for sales and units; only-POS seller has pre-order 0; only-pre-order seller has POS 0; zero-sales seller all 0.
2. Rounding: pre-order fractions of .5 units and sub-cent money never make the parts differ from the total (remainder rule).
3. Cancelled pre-order and voided order appear in neither part; a rejected payment does not raise the pre-order part; DEMO rows never leak into LIVE.
4. Drill-down sums by kind (`order-*` / `preorder-*`) equal the seller's `pos_sales` / `preorder_sales`.
5. Cost & Profit: `revenue/cost/gross` POS + pre-order = totals; event cost / net profit unchanged; existing `ReportTest` assertions unchanged.
6. Seller Cost: totals = POS + pre-order; POS parts equal the pre-change numbers; pre-order-only seller listed; `total_sales` equals the Recap's for every seller.
7. Authorization: cashier/inventory → 403 on all three, including through the export route.
8. Exports: Recap summary sheet has the appended headings with matching values; profit / artist-profit exports carry the new columns.

## Real-browser check (Constitution II) — ISOLATED server + test DB

1. `db:seed`, `license:dev-activate`, seed an event with: seller A (POS sales + a partially paid pre-order), seller B (only POS), seller C (only a pre-order), seller D (nothing), one cancelled pre-order and one voided sale.
2. Reports → Seller Recap: POS/Pre-order unit and sales columns; per row they add up to Unit and Sales; Grand Total row matches. Compare with "Transaction detail" for seller A (sums by kind).
3. Cost & Profit: the Revenue / Cost of goods / Gross profit cards show the POS · Pre-order sub-line; event cost and net profit unchanged.
4. Seller Cost: seller C now listed; totals match the Recap's sales; sub-lines per metric; Grand Total row.
5. Export .xlsx of each tab and open: the new columns/values match the screen.
6. As a cashier: the Reports tabs are not reachable. Console clean; EN ↔ ID labels.
