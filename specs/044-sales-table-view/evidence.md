**Classification**: INTERNAL

# Evidence — 044 Sales Tables View (2026-10-11)

Dev stack, DEMO mode, accounts `owner` and `inventory` (seeded, local). Temporary data (tables/orders prefixed `ZZ-`) was created for the walk-through and removed afterwards; the owner's language was restored to English.

## Automated

| Area | Result |
|---|---|
| Backend `FloorViewTest`, `TabDetailReadTest` (new + extended) | 21 passed; broader `Tab|Floor|DiningTable` sweep 224 passed |
| Backend full suite (`php artisan test`, test DB) | 1675 passed, 8087 assertions, 0 failed |
| Frontend new tests | `floorBoard` 15, `SalesViewToggle` 8, `FloorBoardTile` 10, `FloorBoard` 19, `OpenOrderDetail` 17, `SalesViewTables` 6 |
| Frontend full suite (`npx vitest run`) | 173 files, 1694 passed, 2 skipped, 0 failed |
| Main chunk | baseline 239 614 bytes → 239 747 (+133 B, noise); the board is the lazy chunk `FloorBoard-*.js` (10.4 kB / 3.6 kB gzip) |

## Browser walk-through (real browser)

Seeded: 6 open orders (5 at tables, 1 takeaway) — one over 45 minutes, one partially paid, one on a deactivated table, plus empty tables, a table without an area, and a second area.

| # | Check | Result |
|---|---|---|
| 1 | `/sales` shows the toggle *Order list / Tables*; the list, filters and KPI cards are as before | OK |
| 2 | `?view=tables`: four figures — Open orders 6 (5 at tables · 1 takeaway), Occupied tables 5/10 (50 %), Tables > 45 min 1, Open order value Rp 155.800 | all four equal the seeded data |
| 3 | Tiles: occupied (blue), partial (yellow + "Partial"), over 45 min (outlined + clock), inactive table with an order kept and marked, empty tiles say "Available", groups per area with "N of M tables occupied", "No area" group, separate Takeaway group | OK |
| 4 | Click an occupied tile → read-only detail: opened by, opened at, open for, table/area, kitchen summary, lines with kitchen status, subtotal/discount/total/paid/balance, payments, link "Open in cashier screen"; no changing action | OK |
| 5 | Live refresh: payment cache updated in the DB while the panel was open → within ~6 s the panel showed Paid/Balance and the "Partially paid" chip, elapsed time advanced, no reload | OK |
| 6 | Order completed while the panel was open → "This order has been paid and is no longer open." + "View transaction"; the button opens the existing completed-sale popup; the board dropped the order and the figures updated (5 open, Rp 119.800) | OK |
| 7 | Indonesian: "Daftar order / Meja" toggle, "Status meja", "3 dari 7 meja terisi", "Tersedia", "mnt", legend and figures | OK |
| 8 | Role without floor access (`inventory`): no toggle; `?view=tables` falls back to the order list | OK |
| 9 | Console while using the owner flows | 0 errors |

Screenshots: `evidence/tables-view-en.png`, `tables-view-id.png`, `order-detail.png`, `detail-live-refresh.png`, `no-toggle-without-floor.png`.

## Findings

1. **One console 401 at logout**: an in-flight `GET /floor` poll answered 401 right after the token was revoked. Same behaviour as the existing Floor screen (shared polling); nothing shows to the user. Not changed.
2. **Seed artifact, not a bug**: the first detail showed "Paid Rp 0" next to a Rp 10.000 payment because the walk-through wrote the payment row without the order's cached paid amount; the real payment service keeps both in step. The tile (derived amount) and the detail (cached amount) agree in real data. Worth remembering: the floor snapshot uses the *derived* paid total, `GET /tabs/{id}` the *cached* column.
3. The page-header toggle sits at the right end of the header in Tables view (the list-only actions are hidden); in the list view it sits before the event filter and export button.

## Covered by tests only (not clicked in the browser)

Empty tile not interactive; Escape closes the panel and focus returns to the opener tile; keyboard activation of tiles; stale notice after a failed refresh; 60-tile render; 404 ("cancelled or merged") state; first-load error with Retry; `?view=` with an unknown value.

## Not built (by design, see spec)

Pre-bill state, guest counts, outlet grouping/selector (image 27) — no data exists; configurable 45-minute threshold.
