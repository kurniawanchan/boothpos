**Classification**: INTERNAL

# Quickstart / verification: 068-slip-notes-shipping-fee-stat

Use DEMO data. Never run `php artisan test` in the `app` container without `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` (it has wiped the dev database twice).

## Automated

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderListFiltersTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderSummary   # if a new file is added
npx vitest run --exclude '.claude/**' qa-tests/unit/shippingSlipSheet.test.js qa-tests/component/PreordersView.test.js qa-tests/unit/localeKeys.test.js
```

## Manual, real browser (required by the constitution; not yet done)

Prepare four Mail Order pre-orders in DEMO: A (note "Fragile, wrap twice"), B (no note), C (a note of ~600 characters with line breaks), D (note `<b>x</b> & "y"`); plus one Self Pickup and one cancelled pre-order, each with some fee data.

1. **Slip notes** — select A–D → Download shipping slips.
   - A shows the note right under "Shipping Info", above the number.
   - B looks exactly like before (no blank line).
   - C is fully printed, its row is taller, neighbours in the row keep the shared borders; nothing clipped.
   - D shows `<b>x</b> & "y"` literally, not bold.
   - Print at 100 %; cut lines still align.
2. **Statistic** — on the Pre-orders list with no filter, read "Total shipping fee"; add it by hand from the rows' shipping costs (courier, non-cancelled only). Apply a status filter, a customer filter and a no-match search: the figure follows each and shows Rp 0 for no match. Select some rows: the figure does not change.
3. Switch the interface to Indonesian: the label reads "Total ongkos kirim". Browser console: no errors.
4. Switch DEMO ↔ LIVE: the statistic changes with the mode.

## Evidence to record when done

Test run output, the filter combinations checked, a screenshot or note of the printed slip, console clean. Until then "done" must not be claimed.

## Evidence recorded (2026-11-12, implementation session)

- **Automated**: `PreorderSummaryShippingFeeTest` (8) and `PreorderBulkInvoiceTest` (4) green in the container with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`; `shippingSlipSheet.test.js` (29) and `PreordersView.test.js` (91) green; mutation checks caught: dropping `courier`, dropping the `cancelled` condition, raw (unescaped) notes, no-trim blank check. The `?? 0` mutation survived, so that guard was removed as dead code.
- **Partial real-layout check (NOT the PDF path)**: the real `buildShippingSlipCellHtml` output for 4 invoices rendered with headless Chrome at the real column widths (265/264/264) via `vite-node` + `--screenshot` (scratchpad `slips.png`): A's note sits under the title; B has no blank line; C's ~600-character note with a line break wraps fully, its row grows and the neighbours stretch; D shows `<b>x</b> & "y"` literally. The grid used there was an ad-hoc test page, not the production `buildSheetPageHtml`.
- **STILL OPEN (manual, needs a person at the app)**: the html2canvas → PDF rasterization of a long note, printing at 100 % and checking the cut lines (§1), and the whole §2–§4 statistic check in the running SPA (filter changes, selection, Indonesian label, DEMO↔LIVE, console). No browser MCP server connected in this session.
