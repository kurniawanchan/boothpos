**Classification**: INTERNAL

# Baseline and evidence log (Phase 1 setup)

Recorded 2026-10-11 on branch `041-back-office-ui-redesign` (merged with `028-restaurant-dine-in-ordering`).

## Backend suite (T001, T003–T006)

Run file-by-file against `warkop_wjm_test` only (`-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test`), 140 test files.

| Run | Result | Notes |
|---|---|---|
| 1 (before fixes) | 1386 passed, **15 failed** in 7 files, 0 skipped | the first full run after the migration fix reported 66 failed; one visible cause was a `ParseError` in `ProductController.php:240`, and the count dropped to 15 once that was fixed (the 51 difference is inferred from the count, not itemised) |
| 2 (after fixes) | **1402 passed, 0 failed, 0 skipped** across 140 files | per-file run, 2026-10-11; no file incomplete |

### Causes and fixes of the 15 failures (all pre-existing in the uncommitted 028 work, none caused by this redesign)

| Files / count | Root cause | Fix |
|---|---|---|
| `InvoiceAvailabilityAndLogoTest` (1), `ModeScopingFollowUpTest` (3), `OrderTest` (4), `PosPartialPaymentTest` (1), `TabModifiersTest` (2), `TabSplitMergeMoveTest` (1) — 12 tests | `OrderController::receipt()` used `$summary` after the 028 change dropped its assignment (`Undefined variable $summary`, HTTP 500 on every receipt) | restored `$summary = PaymentSummary::for($order);` in `app/Http/Controllers/Api/OrderController.php` |
| `TabSettlementTest` (2) | `payments.client_ref` (ledger migration) is UNIQUE per payment row; tab payment/split wrote one submission-wide key onto every row → `SQLSTATE 23000/1062`, HTTP 500 | new non-unique `payments.submission_ref` (migration `2026_10_31_000009`), used by `TabSettlementService` in four places; API field name unchanged |
| `TabSettlementTest` (1, masked by the 500 above) | stale assertion: 031-optional-payment-proof made proof optional for sales | replaced by two tests (no proof accepted; no channel refused with 409 and nothing recorded) |

## Frontend suite (T002)

| Run | Files | Tests | Notes |
|---|---|---|---|
| Baseline (before this work) | 92 passed / 3 failed of 95 | 899 passed, **5 failed**, 2 skipped (906) | all 5 in `useSalesFilters` and `SalesViewAdvanced` |
| After fixes + Phase 2/US1 additions | 104 passed of 104 | 995 passed, 2 skipped (997) | |

Cause of the 5 baseline failures: the 028 work renamed `isVoided` to `isCounted` in `useSalesFilters.js` but missed one call site in `shiftSummary` (`ReferenceError: isVoided is not defined`). Fixed in `resources/js/composables/useSalesFilters.js`.

### Production build chunk sizes (constitution V)

| Chunk | Baseline | After frame rewrite |
|---|---|---|
| `main` | 379 kB | 373 kB |
| `jspdf` | 391 kB | 381 kB (separate chunk) |
| `html2canvas` | 202 kB | 197 kB (separate chunk) |
| `DashboardView` | 181 kB | 177 kB |
| CSS | — | 46.5 kB (9.5 kB gzip) |

Heavy modules remain separate chunks; the entry chunk did not grow.

## Environment hazard found while measuring

The backend suite runs inside the `app` container, whose `env_file: .env` injects `CACHE_STORE=file`. phpunit's `<env>` cannot override a real OS variable, so the tests share the **dev app's** `storage/framework/cache`. Observed effect: while the suite ran, `Setting::get('system_mode')` in the dev app flipped back to `live` and back (stale cached value), so a dev database switched to DEMO behaved as LIVE. Cache-dependent tests could likewise read the dev app's values. **Mitigation until fixed at the source**: run the suite with `-e CACHE_STORE=array` as well, and do not use the dev app in a browser while the suite runs. (Not yet changed in `CLAUDE.md`/`phpunit.xml`.)

## Final result

Backend: **140/140 files, 1402 passed, 0 failed, 0 skipped** (after the receipt, `submission_ref`, stale-test and `ProductController` fixes).
Frontend: **126 files, 1203 passed, 2 skipped** (after Phase 2 foundation, US1 frame and the US2 kit and view migrations).
