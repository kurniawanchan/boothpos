# Quickstart — verifying this feature manually

1. `php artisan migrate` then `php artisan serve` and `npm run dev`.
2. **Purchase Order**: as `owner`/`inventory`, create a PO against a vendor
   with a material line item and a service line item, move it
   draft→ordered→received (confirm the material's stock — visible via a
   new stock indicator on the Material screen or the stock-by-artist-style
   detail — increases by the received qty)→paid, print its invoice, confirm
   it appears on the Purchases report.
3. **Split payment**: at POS checkout with a total due, add a cash entry
   for part of it, add a QRIS entry for the remainder, confirm the submit
   button stays disabled until the remaining balance is zero, complete the
   sale, confirm the receipt lists both entries. Repeat the "add a note to
   a payment" check on one entry.
4. **POS draft**: build a cart, save as draft, confirm the cart clears,
   open the drafts list, resume it, confirm the cart is restored exactly,
   complete checkout. Confirm stock was untouched while the draft was
   merely saved (check `GET /stock/movements` shows nothing for those
   variants until checkout).
5. **Per-artist opening cash**: open a cashier session entering amounts for
   two different artists, confirm the total equals their sum, close the
   session and confirm the breakdown appears in the summary.
6. **Theme**: pick a new accent color in Settings, save, reload, confirm
   buttons/active nav states reflect it across at least Dashboard and
   Products without a full-page flash of the old color.
7. **Receipt display**: set custom footer text, complete a sale, confirm
   the receipt shows it; toggle the logo off, confirm the receipt still
   renders correctly without one.
8. **Activity Log**: as `owner`, open the new Activity Log screen, confirm
   recent actions appear, filter by date range, confirm a `kasir01` login
   cannot see the menu entry at all.
9. **Reports**: open the Purchases report and the Stock-by-Artist report,
   confirm figures match the underlying data from steps 2 and known seeded
   stock.
10. Run `php artisan test` and `npm test` — all existing + new tests pass.
11. Update `docs/openapi-pos-mvp.yaml` for every new/changed route.
