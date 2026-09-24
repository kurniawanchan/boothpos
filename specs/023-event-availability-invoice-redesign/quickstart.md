# Quickstart: Manual Verification (Constitution II)

Run against the dev stack, logged in as `owner`/`password123`.

## US1 — Event "available on"

1. Open Events, create/edit a multi-day event (start ≠ end date). Confirm
   an "Available on" selector appears with two options showing the actual
   dates (e.g. "Day 1 (1 Nov 2026)" / "Day 2 (2 Nov 2026)").
2. Select "Day 2", save. Reopen the event for editing — confirm the choice
   persisted.
3. Edit the same event so start_date = end_date. Confirm the "Available
   on" control disappears, and reopen the event to confirm the previously
   saved choice was cleared (not silently kept, hidden).

## US2 — Standout available-on + location

1. Open a pre-order invoice for a preorder linked to the event from US1
   (with "Day 2" set and a location configured). Confirm both facts render
   in a visually prominent block near the top — not as small muted text.
2. Open that pre-order's payment invoice (record a payment first if none
   exists) — confirm the same standout block appears there too.
3. Complete a POS sale tied to the same event, open its receipt — confirm
   the same standout block appears.
4. Open an invoice/receipt for an event with no location and no available
   day set — confirm the block is fully absent (no empty box).

## US3 — Invoice redesign

1. Open a Mail Order pre-order's invoice with a shipping cost > 0. Confirm:
   - The document reads as header → item table → footer.
   - The modal is visibly wider than before.
   - Items appear in a table with Product/Qty/Unit price/Line total
     columns.
   - A "Shipping cost" line appears in the totals, included in the total.
   - The payment QR (if a QR channel is configured) is noticeably bigger
     than before, and still opens the full-size popup on click.
2. Open a Self Pickup pre-order's invoice with no shipping cost — confirm
   no "Shipping cost" line appears.
3. Download as PDF — confirm it still renders correctly at the new width
   (no cut-off content).

## US4 — Store logo bug fix

1. Go to Settings → General, upload a store logo image. Confirm it appears
   immediately.
2. Reload the page from scratch. Confirm the logo still appears.
3. Open any pre-order invoice and any POS sale receipt — confirm the logo
   appears in both document headers.

## Regression check

Run `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`
and the full frontend suite (`npm test -- --run`) before considering the
feature done.
