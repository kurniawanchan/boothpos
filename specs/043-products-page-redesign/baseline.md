**Classification**: INTERNAL

# Baseline and progress log (043)

Recorded 2026-10-11 on branch `043-products-page-redesign`, working tree = the end of 042 (uncommitted).

## Backend

Carried from 042 (no source change since): full suite **1496 passed, 0 failed**. New in this feature: `OrderAvailabilityCharacterizationTest` and `VariantPriceCharacterizationTest` pin today's ordering and price behaviour, including the inherited gap that an inactive PARENT PRODUCT is not checked at checkout (to be closed by `MenuAvailability`; the pinning test is then updated explicitly).

## Frontend

Carried from 042: 137 files, 1341 tests passing after re-running 21 load-induced timeouts, 2 skipped.

## Bundle (SC-007 baseline)

`npm run build` at the start of this branch: main chunk **396.83 kB** (gzip 134.55 kB). The 043 budget is +10 kB over this number. Route chunks: DashboardView 33 kB, PerEventSection 180 kB.

## Stage B gate (T004)

Not yet satisfied: 042 T001 (constitution amendment ratified) and 042 Phase 7 are open. Stage B tasks (T085–T095) must not start.
