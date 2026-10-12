**Classification**: INTERNAL

# Baseline — 045 (recorded 2026-10-11, branch `045-outlets-per-outlet-menu` from the 044 commit `22a2dbe`)

| Check | Result |
|---|---|
| Backend full suite (`php artisan test`, test DB) | 1675 passed, 8087 assertions, 0 failed (518 s) |
| Frontend full suite (`npx vitest run`) | 173 files, 1694 passed, 2 skipped. One earlier run showed 1 timing flake (passed on re-run, not reproducible) |
| `npm run build` main chunk | 239 747 bytes (`main-BxZxWgNM.js`, 239.75 kB / 87.06 kB gzip) |

## Dev database counts the reconciliation must preserve (demo data)

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| artists | 3 | | stock_movements | 40 |
| products | 9 | | materials | 8 |
| product_variants | 27 | | session_opening_cash_entries | 0 |
| orders | 5 | | cashier_sessions | 3 |
| order_items | 11 | | dining_tables | 2 |
| preorders | 2 | | users | 7 |
| preorder_items | 2 | | roles | 4 |
| purchase_orders | 0 | | artist_settlements | 3 |

Σ `product_variants.current_stock` = **1084**; Σ `materials.current_stock` = 0.000.

Report totals (sales, settlements, cost/profit for the demo event) are captured by `php artisan outlets:reconcile --before` (task T008) so they are snapshotted by the same tool that later compares them.

## Rehearsal dump

`app:backup` taken before any migration of this feature (path printed in the command output, inside the `app` container at `storage/app/backups/<timestamp>`). `specs/045-outlets-per-outlet-menu/rehearse.sh` copies the **dev** database into the scratch database `warkop_wjm_rehearsal` (never the dev/test DB), migrates and compares.
