# Quickstart: Verifying Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

Run after implementation, in spec.md's story order. Requires the app
running per CLAUDE.md's standard commands, with test data spanning at
least: one seller with both a regular sale and a partially-paid preorder,
one preorder spanning two sellers, and one cancelled preorder that had a
payment recorded before cancellation.

1. **Seller transaction detail includes preorders**: open Seller Recap,
   click "Transaction detail" for a seller with both a sale and a
   preorder contributing to their total. Confirm both appear, clearly
   typed, and that summing every listed amount equals the seller's
   Seller Recap total exactly (to the rupiah).
2. **Cancelled preorder excluded from detail**: for a seller whose
   cancelled preorder had a payment recorded before cancellation, confirm
   it does NOT appear as a contributing entry in their transaction detail.
3. **Pre-order report per-seller breakdown**: open the Pre-order report,
   confirm seller-level figures are visible (not just status ×
   completeness), and that a multi-seller preorder's value is split
   across sellers proportional to their item share.
4. **Pre-order report drilldown**: click a Pre-order report row, confirm
   a detail view lists the individual preorder(s) behind it with amounts
   summing back to the row's totals, then select one and confirm it opens
   that preorder's existing full detail view (not a new one).
5. **Exports on the three previously export-less tabs**: open Purchases,
   export, confirm a workbook downloads matching on-screen rows. Repeat
   for Stock by Seller. Repeat for Pre-order, confirming the downloaded
   workbook has both a summary sheet and a per-seller sheet.
6. **Export respects the active filter**: change the event filter on
   Purchases/Stock by Seller/Pre-order, export again, confirm the
   downloaded data reflects only the filtered event.

Each step must also have a `php artisan test` / Vitest counterpart per
Constitution Principle II — this quickstart is the manual/browser
verification layer, not a substitute for automated tests.
