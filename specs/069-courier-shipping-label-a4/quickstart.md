**Classification**: INTERNAL

# Quickstart / Verification: Courier Shipping Labels (feature 069)

## Automated

```bash
# Backend (inside the container ONLY with the -e overrides — see CLAUDE.md)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderShippingLabelsTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='MenuActionsRouteCoverageTest|ForbiddenSurfacesTest'

# Frontend
npx vitest run --exclude '.claude/**' qa-tests/unit/courierLabelSheet.test.js qa-tests/unit/shippingSlipSheet.test.js qa-tests/component/PreordersView.test.js
npm run build   # confirm courierLabelSheet/jspdf/html2canvas stay in lazy chunks
```

## Real browser (Constitution II — required before "done")

1. Sign in as `owner`, Pre-orders, filter Mail Order. Open a pre-order with a shipment (courier, recipient, phone,
   address, tracking). Click **Download courier label (A4)** → one A4 page, label top-left, every field equals the form.
2. Edit the tracking number in the drawer WITHOUT saving, download again → PDF shows the saved value; info toast shown.
3. A shipment with no tracking number → blank tracking box, no placeholder text.
4. Select 18 pre-orders incl. 2 Self Pickup and 1 Mail Order without shipment → one PDF, 15 labels on 4 pages (4+4+4+3),
   number order; toast lists the 3 skipped numbers.
5. Address of ~600 characters → refused with the too-tall message naming the pre-order; no file.
6. Sign in as `kasir01` with a role lacking `preorders.export` → both buttons absent.
7. 200 selected → measure total time (SC-002 ≤ 60 s) and note it in this file.
8. Print one sheet at 100 % / actual size; check the cut lines with a ruler (each cell ≈ 105 × 148.5 mm).
9. Browser console: no errors.

## Baseline (T001, 2026-11-15, before any change)

- Vitest `shippingSlipSheet` + `pdfCapture` + `PreordersView`: 3 files, 137 tests passed.
- PHPUnit `MenuActionsRouteCoverageTest|ForbiddenSurfacesTest` (container, `-e` overrides): 9 passed.

## Results (2026-11-15)

- Backend full suite (container, `-e` overrides, `phpunit -d memory_limit=2G`): 2605 tests, 9 errors + 2 failures — ALL
  pre-existing: 9 `Code128Test` (reference library `picqer` is require-dev) and 2
  `CustomerCommerce\StaffFulfilmentVisibilityTest` (checkout fixture returns 409; reproduced identically on the
  unmodified base with `git stash`). `PreorderShippingLabelsTest` 11/11, route-coverage + seller-scope tests green.
  Mutation check: removing `->with('shipment')` turns the query-count test red.
- Frontend full suite (`npx vitest run --exclude '.claude/**'`): 152 files, 1374 passed, 2 skipped (incl. `localeKeys`).
  Mutation check: breaking the skipped-number filter or the empty-result branch turns 2 US2 tests red.
- `npm run build`: lazy chunks `courierLabelSheet-*.js` (4.1 kB), `a4PdfPages-*.js` (1.0 kB), `jspdf.es.min-*.js`,
  `html2canvas.esm-*.js` — none in the entry chunk.

## Real-data and render checks (T026 — PARTIAL, 2026-11-15)

Browser automation was unavailable (Playwright / Chrome DevTools MCP failed to connect; no Puppeteer in the repo), and
the dev `owner` login does not accept the seed password (changed — not guessed). What WAS checked:

- `PreorderShippingLabels::build()` against the real dev database (LIVE, via `artisan tinker`, read-only, counts only):
  40 most recent pre-orders → 18 labels, 20 `not_mail_order`, 2 `no_shipment`; all ids accounted for; labels in number
  order; longest real address 178 characters.
- Headless Chrome render of `buildLabelPageHtml` with synthetic data incl. a 178-character address and a long name:
  measured content heights 393 / 308 / 308 px (cell capacity 560) — the worst real-length label uses ~70 % of its cell;
  empty tracking box stays blank; shared cut lines; only existing labels drawn.

NOT checked yet (steps 1–9 above still open): the click-through in the running SPA, the html2canvas → jsPDF output, the
browser console, the 200-label timing (SC-002), the role without `export` in the real UI.

## Print check (T027) — NOT DONE

No printer in this session. Cut-line accuracy on paper (≈ 105 × 148.5 mm per cell) is unverified.
