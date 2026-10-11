**Classification**: INTERNAL

# Evidence — Stage A (042 Dashboard revamp)

Recorded 2026-10-11 on branch `042-dashboard-revamp`. Environment note: the development laptop was under extreme load during these runs (load average 57–110, mostly the Docker VM and security software), which made every test and request several times slower than normal. Timings below are therefore upper bounds, and two frontend tests needed longer per-test timeouts.

## Backend

- Full suite, `-e CACHE_STORE=array`: **1496 passed, 0 failed** (7311 assertions) — 94 more than the 041 baseline of 1402. One existing fixture changed on purpose (`ReportTest` group-by-customer date range moves `business_date` together with `created_at`).
- After that run the dashboard queries were optimised (lean base without the redundant joins, one ledger replay, rows reused for the comparison value). The affected group (`Dashboard|LowStock|ReportsBusinessDate|SalesTotals`) was re-run: **58 passed**. A final full run is part of T047.
- Characterisation of the Sales totals (`SalesTotalsCharacterizationTest`) passes unchanged on the refactored code, so `pos_*` figures are the same as before the extraction; the report date filter now follows the stored business date (intended).

## Dev database

- `php artisan app:business-date-dry-run`: 5 orders, 3 would move from 2026-10-10 to 2026-10-11 (all created between 00:00 and 07:00 WIB, my own earlier measurement orders), 0 with a suspicious `updated_at`. Migrations applied; backfilled values checked row by row.

## API against the dev server (owner, admin, kasir01, inventory)

- All five `/dashboard/*` routes answer 200 for the four default roles; a role without the `dashboard` menu answers 403 (covered by tests).
- `kasir01` receives `link: null` for "Void setelah fire" (needs the `reports` menu) and "Stok menipis" (needs `stock`); admin receives every link.

## Browser (real Chrome via Playwright, built SPA on :8010)

- Owner (English UI), full page: header with business date and "Updated 12.24", period tablist, four KPI cards with change chips and trend lines, "Needs attention" with four all-clear items, "Latest orders" with tabs and counts (All 5 / Open 0 / Partially paid 0 / Paid 5 / Cancelled 0; no Pre-bill or Closed tab), filter bar, table, "Per event" section with the previous panels. Screenshot: `evidence/dashboard-owner-today.png`.
- Console on a fresh load: **0 errors, 0 warnings**.
- Interactions (DOM-level clicks; real mouse input is unreliable in this environment, as in 041): period switch → `?period=7d`; tab Open → `?tab=open` (empty state), tab Paid → 5 rows; the order number opens the existing transaction detail dialog; "Ekspor CSV" downloads `order-terbaru-2026-10-11.csv` (UTF-8 BOM, header row, rows equal to the screen).
- `kasir01`: logs in, sees the Dashboard with the same three regions, **no gross-profit figure** (reports menu), rail limited to its menus; console clean.
- Defect found and fixed in this pass: a long money value ("Rp 18.420.500"-sized) was cut with an ellipsis next to the trend line ("Rp 405.0…"); the value no longer truncates and the trend line wraps below it.

## Performance (1,000 completed orders today, rolled-back transaction, dev DB, machine load ≈ 57)

| Operation | Before optimisation | After |
|---|---|---|
| summary today | 6.7 s | **2.2 s** |
| summary 7d | 1.9 s | 1.0 s |
| summary 30d | 3.4 s | 2.1 s |
| attention | 4.3 s | 1.7 s |
| orders page + counts | 0.97 s | 0.33 s |
| orders export (all rows) | 1.7 s | 0.5 s |

Under normal load these are expected to be several times lower, but that was not measured. The three Dashboard requests run in parallel in the browser; the SC-004 target (3 s first paint) has **not yet been confirmed on an unloaded machine**.

## Bundle (SC-007)

`npm run build`: main chunk **396.83 kB** (gzip 134.55 kB) against **383.56 kB** measured for the untouched HEAD → **+13.3 kB, over the +10 kB budget**. Cause: the new `dashboard.*` / `settings.business_day_*` locale strings (~100 keys × 2 languages) are bundled into `main`, as every locale string is. The Dashboard code itself is in its own 33 kB route chunk and the per-event panels (with chart.js) in a separate 180 kB async chunk. Options: lazy-load the non-active locale (changes i18n boot for the whole app) or accept the overshoot. **Open — owner call.**

## Not done / still open for Stage A

- Browser check in Indonesian with live attention conditions (pending payment, void after fire, cash difference) — covered by feature tests and the destination-count tests, not yet by a browser walk-through with those conditions.
- Real-mouse clicks (environment limitation) and a keyboard-only pass.
- Unloaded-machine performance figures.
- Frontend full suite: 137 files, **1320 passed, 21 failed, 2 skipped** in the first run, all 21 failures being 5-second timeouts or timing races in 16 files (several unrelated to this feature, e.g. BomCopyMenu, PaymentPanel) while the machine was at load ≈ 60. The same 16 files re-run with `--testTimeout=60000`: **16 passed, 142 tests passed, 1 skipped**. So the suite is green apart from the load sensitivity, which should be revisited on an idle machine (not a regression introduced here, but not proven unrelated either).
