# Quickstart: Preorder List Filters, Seller Info & Receipt-Style Invoice

Manual verification scenarios, one per user story. Run against the seeded demo dataset
(`php artisan db:seed --class=SakanaFridgeDemoSeeder`) in DEMO mode so preorders spanning
multiple sellers already exist.

## US1 — Seller filter + seller info

1. Open `/preorders`. Confirm a new seller filter (default "All sellers") appears alongside
   the existing status/fulfillment filters and customer-name search.
2. Confirm every row shows a seller column — a single name for a single-seller preorder, all
   distinct names for a multi-seller preorder (do not just show the first one).
3. Pick one seller. Confirm the list narrows to only preorders containing that seller's items,
   while the existing status/fulfillment filters and search (if set) still apply together.
4. Open a preorder's detail. Confirm each line item shows which seller it belongs to.
5. Reset the filter to "All sellers". Confirm the full list returns.

## US2 — Click transaction number to open detail

1. On the Pre-orders list, click a row's preorder number (not the "Detail" button).
2. Confirm the same detail view opens as clicking "Detail" — same data, same layout.

## US3 — Receipt-styled invoice with preorder + status marking

1. Open a preorder's invoice/receipt document (any status).
2. Confirm the layout matches the POS sale receipt's structural conventions (store-header-style
   centered block, dashed-line itemization, prominent total typography).
3. Confirm a "Pre-order" marking is clearly visible, alongside the preorder's current granular
   status (e.g. "Barang tiba" / "Goods arrived"), not just a generic invoice/receipt/cancelled
   badge.
4. Advance the preorder to a new status (e.g. record a payment or mark handed over), reopen the
   invoice, and confirm the status shown updates to match — it is not frozen at creation time.
5. Download the document and confirm the downloaded file shows the same layout and marking.

## US4 — "Print" → "Receipt" rename

1. Switch the interface language to Indonesian. Confirm the Pre-orders screen's document
   actions read the Indonesian "Receipt" wording, not "Cetak".
2. Switch to English. Confirm the same actions read "Receipt", not "Print".
3. Click the renamed action in either language. Confirm it opens the same document as before —
   behavior unchanged, only the label.

## US5 — Summary statistics

1. Open `/preorders` with no filters applied. Confirm a summary area shows: total transaction
   count, a total per status, a grand total, and a total outstanding.
2. Manually sum a few known preorders' `total_amount`/`outstanding` from the list and confirm
   the summary figures match.
3. Apply the seller filter (US1) and/or the existing status/fulfillment filters. Confirm the
   summary figures recompute to reflect only the currently filtered preorders.
4. Confirm a cancelled preorder still contributes its `total_amount` to the "cancelled" status
   bucket (statistics reflect what's actually in the filtered set, not a curated subset).

## Regression check

- `php artisan test --filter=PreorderTest` — all existing preorder tests still pass; new tests
  cover the `artist_id` filter, the `sellers` field, and the new `summary()` endpoint.
- `npm test` — `PreordersView` component tests still pass; new tests cover the seller filter,
  the clickable transaction number, and the summary display.
- Manual browser check of `/preorders` in both DEMO and LIVE mode (per CLAUDE.md's DEMO/LIVE
  convention) — confirm the seller filter's options and the summary figures never mix data
  across modes.
