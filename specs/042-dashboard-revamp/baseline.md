**Classification**: INTERNAL

# Baseline and progress log (042)

Recorded 2026-10-11 on branch `042-dashboard-revamp` (on top of `041-back-office-ui-redesign`).

## Backend suite

| Run | Result | Notes |
|---|---|---|
| Baseline carried from 041 | 140 files, **1402 passed, 0 failed** | recorded in `specs/041-back-office-ui-redesign/baseline.md`; no source change between that run and the first 042 edit |
| First baseline attempt for 042 | **discarded** | started before the code edits and overlapped them (model hooks landed before their migration ran), so it measured a half-edited tree; not used |
| After Phase 2 foundation (business-day columns, stamping hook, settings) | **1434 passed, 0 failed** | full suite, `-e CACHE_STORE=array`; +32 new tests |
| After Stage A backend (SalesFigures wiring, dashboard services/endpoints, exports, tests) | **1496 passed, 0 failed** (7311 assertions) | full suite, +94 tests over the 041 baseline; one existing fixture (`ReportTest` group-by-customer date range) had to move `business_date` together with `created_at` — the intended basis change |

Environment note (from 041, still true): run the suite only with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array`, and **do not edit PHP files while a run is in progress** — a model change that lands before its migration made the first attempt unreliable.

## Frontend suite

Baseline from 041: 126 files, 1204 passed, 2 skipped. After the Phase 3 frontend work (worker report): 1272 passed, 2 skipped, 1 timeout under load (`PreordersView` "seller filter", passes alone 62/62).

## Bundle

`npm run build` main chunk: 390.15 kB after the Phase 3 frontend work, against 383.56 kB measured for the untouched HEAD in a temporary worktree (+6.6 kB, budget +10 kB). The 374 kB figure in the spec is the 041 end-of-phase measurement; the same code builds at 383.56 kB today, so the budget is applied to the measured HEAD value. The per-event panels and chart.js are a separate 179.9 kB async chunk.
