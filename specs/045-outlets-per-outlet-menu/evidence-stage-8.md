**Classification**: INTERNAL

# Evidence — Stage 8 (hardening and release readiness) — DONE except the browser passes

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## T101 — isolation matrix

- `OutletIsolationEndpointsTest` (5): a user assigned to ONE outlet receives nothing that belongs only to another outlet from 23 read paths (orders, floor, tables, stations, stock movements/balances/low/in-transit/transfers/discrepancies, materials, sales/recap/profit/stock reports, dashboard summary/attention/orders, catalogue, availability poll, exports); a forged `X-Outlet-Id` is refused (403) on every one; direct requests for another outlet's order/transfer/report outlet are 404/403; owner and admin see everything.
- **Leak found and fixed by the matrix**: the master-data Excel exports for `stock`, `outlet_items` and `outlet_prices` returned every outlet's rows to a scoped user. They now filter on the caller's outlets (`OutletFilter`).
- `OutletScopeRegistryTest` now also fails when a table with an `outlet_id` column is in neither `MODELS` nor the new `OTHER_TABLES` (each with a reason). 20 tables with the column are accounted for (7 scoped + 13 documented).
- Not covered by a role-by-role cross product: the five seeded dev accounts were not each walked through every screen (needs the browser pass).

## T102 — performance (SC-010)

Scratch copy of the dev DB, seeded to **20 outlets, 509 items, 1 202 tables, ~10 000 stock balances**; measured through the HTTP kernel inside the dev container (warm, second request), **not an unloaded machine** (the container answers a trivial request in ≈ 280 ms, so figures are upper bounds):

| Endpoint | Time | Queries |
|---|---|---|
| Outlets page | 406 ms | 3 |
| Products catalogue (`tab=menu`) | 416 ms | 15 |
| Products POS list (100 items, `pos=1`) | 363 ms | 8 |
| Floor (20 outlets, 1 202 tables, 94 KB) | 522 ms | 5 |
| Dashboard summary / attention | 617 ms / 298 ms | 2 / 2 |
| Stock movements / balances / low | 303 / 479 / 280 ms | 11 / 5 / 3 |
| Transfers list | 310 ms | 7 |
| Stock-by-outlet report | 336 ms | 3 |

All **< 1 s**, well under the 2 s target; query counts are constant (floor 5 for 1 202 tables). With a single outlet selected figures are the same order (max 981 ms for `/me/scope`, a one-off in a noisy container).

## T103 — demo

`WarkopOutletsDemoSeeder` (optional, idempotent, after `SakanaFridgeDemoSeeder`): outlets Warkop WJM 1/2/3, kasir01→WJ1, kasir02→WJ2, inventory→WJ1+WJ2, tables per outlet, per-outlet balances through `StockService`, one transfer in transit. The base seeder is unchanged (single default outlet); `WarkopOutletsDemoSeederTest` (2) proves both.

## T104 — rehearsal and release notes

Full chain from the pre-045 backup (`2026-10-11_092245`) into a scratch DB: every migration from `2026_11_05_000001` to `2026_12_05_000002` in **14 s**, `outlets:reconcile` before/after/compare **green**. (The backup could only be loaded after neutralising the generated column `orders.open_table_key` — the pre-existing dump defect.) `rehearse.sh` on the current dev copy also passes. `release-notes.md` records what is reversible, the rollback = restore rule, the known backup/restore defect and the open owner decisions.

## T106 — documentation

`CLAUDE.md` gained "Outlets (feature 045)" (naming, scope mechanism, stamping, menu/stock/transfers/report rules, tests helpers, operations); `docs/RUNBOOK.md` §12.1; PRD already carries the dated notes from Stage 0/1.

## T107 — final suites and review

| Check | Result |
|---|---|
| Backend full suite | **1868 passed, 1 skipped, exit 0** (9039 assertions) |
| Frontend full suite | **191 files, 1768 passed, 2 skipped, exit 0** |
| Build | passes; main chunk **240.97 kB** vs baseline 239.75 kB (+0.5 %, within noise) |
| Review | No raw hex in the Vue code; remaining `artist` strings in live code are the deliberate legacy aliases (old sheet/column names accepted on import, old locale KEY names kept with new values, `/artists` redirect, reconcile table fallbacks) |

## Still open (needs the owner / the browser)

1. **T105 + T028/T046/T064/T069/T083/T094/T099 browser passes**: the Playwright browser profile has been held all session by an unrelated Chrome process (running >1 day, not started by this session, not killed). Close it (or allow it to be closed) and the final pass across 3 outlets × 5 roles × 2 languages can be recorded.
2. T034/T076 automated backfill tests (infeasible in the NOT-NULL test schema; backfill evidenced on the dev DB and rehearsal).
3. T088 true two-process concurrency (lock order and sequential last-unit tests exist).
4. Fix the `app:backup` generated-column defect before relying on `app:restore` in a store.
5. Owner decisions: recipe-effectivity timezone; narrowing the all-outlet assignments migration gave non-admin users.
