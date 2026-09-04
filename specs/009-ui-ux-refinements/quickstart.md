# Quickstart: Verifying UI/UX Refinements Batch

Run after implementation, in the order the stories are numbered in spec.md
(P1 first). Requires the app running per CLAUDE.md's standard commands
(`php artisan serve` + `npm run dev`, MySQL via `laradock-mysql-1`).

1. **Login page**: open `/login` logged out. Confirm no "Instalasi lokal"
   text and no "< 30 dtk / < 15 mnt / 0 transaksi hilang" badges anywhere.
2. **Sidebar colors**: log in, compare Purchase/Inventaris/Pengaturan
   default-state icon/text color against another top-level item side by
   side (screenshot diff against the two images from the spec's input).
3. **Navbar**: confirm store name + active event name are visible on any
   authenticated screen, and username + logout are grouped top-right.
   Switch language and confirm the logout label changes with it.
4. **Sales page**: open Sales, confirm the transaction list is the primary
   content and no separate product summary table renders. Click a
   transaction number → confirm a products-sold table popup opens (not a
   receipt). Click a product name inside it → confirm the product detail
   popup opens.
5. **Seller rename**: toggle ID/EN and scan Sidebar, Products, POS,
   Reports for "Penjual"/"Sellers" — no leftover "Artist"/"Artists" text.
6. **Event delete**: create a throwaway Event with no sessions/orders,
   delete it — succeeds. Attempt to delete an Event that has an order —
   blocked with a clear message.
7. **Customer delete**: same as above for Customer.
8. **Customer transaction history**: open a customer with both a regular
   order and a preorder on record; confirm both show, correctly labeled,
   and open into their existing detail views.
9. **Dashboard customer stats**: confirm a customer table + chart render
   and agree with the customer's totals from step 8.
10. **Stock-by-artist drilldown**: open Reports, click a seller row in the
    stock-by-artist table, confirm variant count + total stock detail.
11. **Settings**: confirm no "Data Backup"/"Cadangkan sekarang" section
    remains.

Each step must also have a `php artisan test` / Vitest counterpart per
Constitution Principle II — this quickstart is the manual/browser
verification layer, not a substitute for automated tests.
