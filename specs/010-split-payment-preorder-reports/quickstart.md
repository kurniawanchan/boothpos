# Quickstart: Verifying Split Payment Visibility, Preorder Receipt & Reporting

Run after implementation, in spec.md's story order (P1 first). Requires the
app running per CLAUDE.md's standard commands.

1. **POS split payment visibility**: open POS checkout with a total that
   isn't a round cash amount. Confirm a visible split affordance exists
   *before* adding any entry (not only after). Add a partial cash entry,
   confirm the remaining balance and entries list update. Add a second
   entry in a different method covering the remainder, complete the sale,
   and confirm both entries appear on the printed/on-screen receipt.
2. **Preorder split payment**: open a preorder's "record payment" flow.
   Confirm the same visible split UI from step 1 is present. Add a partial
   entry, confirm it actually submits to the backend (check the preorder's
   payment history immediately shows it, not just client-side state), add
   a second entry covering the remainder, confirm both appear as separate
   `Payment` rows in the preorder's history.
3. **Split submission failure handling**: simulate a network failure (or
   an invalid second entry) mid-split on a preorder and confirm the first,
   already-submitted entry is not lost or resubmitted, and the user can
   retry just the failed entry.
4. **Row hover**: visit Products, Customers, Sales, Reports (each tab),
   any master-data screen, and the four non-`DataTable` modals
   (`VariantBomModal`, `ArtistTransactionsModal`, `MasterDataImportModal`,
   `ProductDetailModal`) — confirm every row highlights on hover and
   un-highlights on mouse-out, without obscuring status/selection colors.
5. **Preorder payment receipt**: record a payment against a preorder, open
   its payment receipt, confirm it visually matches the POS receipt's
   layout, is clearly labeled "Pre-order" with the current status shown,
   and — for a preorder with two separate payment events (DP + settlement)
   — confirm each receipt is unambiguous about which event it documents.
6. **Reports include preorder revenue**: create an event with one regular
   sale and one preorder with a partial payment. Run sales/profit/artist-
   settlement reports and confirm the totals equal sale amount + the
   preorder's *collected* amount (not its full order value) — cross-check
   to the rupiah against the proration rule in research.md R1.
7. **Cancelled preorder excluded**: cancel a preorder that had a payment
   recorded, re-run the same reports, confirm its revenue contribution
   drops to zero.
8. **Dedicated preorder report**: with preorders in multiple statuses and
   payment states, open the new preorder report, confirm counts and
   collected/outstanding totals per status × payment-completeness bucket,
   and confirm the event filter narrows correctly.

Each step must also have a `php artisan test` / Vitest counterpart per
Constitution Principle II — this quickstart is the manual/browser
verification layer, not a substitute for automated tests.
