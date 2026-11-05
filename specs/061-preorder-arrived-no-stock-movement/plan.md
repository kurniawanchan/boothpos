**Classification: INTERNAL**

# Implementation Plan: Pre-order "Goods arrived" Makes No Stock Movement

**Branch**: `086-preorder-arrived-no-stock-movement` | **Date**: 2026-11-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/061-preorder-arrived-no-stock-movement/spec.md`

## Summary

Four changes, all in the pre-order status path:

1. **Arrival writes no stock movement.** Remove the `purchase` movement loop for `arrived` in `PreorderService::transitionStatus()`.
2. **Editing an arrived/settled pre-order writes no stock movement.** Remove the quantity-delta block in `PreorderService::update()` (it only existed because arrival had added stock).
3. **Settle on arrival when fully paid.** After moving to `arrived`, if `PaymentSummary` says the pre-order is fully paid, the same transaction moves it to `settled`. One public method, `PreorderService::settleArrivedIfFullyPaid()`, holds the rule.
4. **One-off correction command** `app:settle-paid-arrived-preorders` (dry-run by default, `--apply --by=<user>`) that settles pre-orders already stuck at `arrived` while fully paid, in both DEMO and LIVE, with one activity-log row each and no stock effect.

Hand-over is untouched: it still deducts via `preorder_handover` and still requires full payment. No migration, no route, no response-shape change. The SPA only needs a corrected toast text.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (locale strings only)

**Primary Dependencies**: Existing `PreorderService`, `StockService`, `PaymentSummary`, `ActivityLogger`, `ModeGate`

**Storage**: MySQL 8, no schema change

**Testing**: PHPUnit feature tests on MySQL (`PreorderTest`, `PreorderUpdateTest`, `StockMovementsTest`, `StockTransactionSyncTest`, `PreorderPaymentLedgerTest`, new tests); Vitest only if a locale test pins the toast. Container runs MUST use `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`.

**Target Platform**: Local store install (native or Docker)

**Project Type**: Web service + SPA

**Performance Goals**: No change (one extra payments read on arrival)

**Constraints**: Stock never negative (existing `StockService` guard, now only reached at hand-over); status change and settle in ONE transaction; correction idempotent; no customer data printed by the command

**Scale/Scope**: 2 production files, 1 new command, 2 locale files, 1 seeder, about 6 test files, docs

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Single write path / DRY | PASS. Stock still moves only through `StockService::applyMovement()`; this feature only removes callers. The "settle if fully paid" rule gets ONE method used by both `transitionStatus()` and the command. `PaymentService::syncCache()` keeps its own one-line check (same `PaymentSummary::FULLY_PAID` constant, different trigger inside a payment lock). Accepted, noted in research D5. |
| II. Testing | PASS. Tests that encode the old rule are rewritten, new ones added for each acceptance scenario, the command is covered including idempotency and DEMO/LIVE. |
| III. UX consistency | PASS. One toast text corrected (EN and ID). No layout change. |
| IV. Security | PASS. Command is CLI-only, requires an existing owner/admin for `--apply`, prints pre-order numbers only (no customer data). No new HTTP surface. |
| V. Performance | PASS. |
| Stack constraints | PASS. MySQL tests; OpenAPI and CLAUDE.md move in the same commit. The 9 known `Code128Test` failures (dev-only reference library) are unrelated. |
| Documented invariant reversed | NOTE. CLAUDE.md "Stock movement invariants" states arrival increases stock. This is a deliberate, product-owner-requested reversal (dated note, same pattern as earlier reversals). |

No unjustified violations. Re-checked after design: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/061-preorder-arrived-no-stock-movement/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/status-and-correction.md
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Services/PreorderService.php                      # transitionStatus(): drop arrival movement, call settleArrivedIfFullyPaid();
                                                      # update(): drop arrived/settled delta block; new public settleArrivedIfFullyPaid()
app/Console/Commands/SettlePaidArrivedPreorders.php   # NEW: dry-run default, --apply --by=<owner|admin>
resources/js/locales/en.json                          # preorders.arrived_marked text
resources/js/locales/id.json                          # same key
database/seeders/SakanaFridgeDemoSeeder.php           # demo full cycle must have stock before hand-over; fix comment
app/Services/StockService.php                         # docblock only: arrival no longer a stock event
app/Services/PaymentService.php                       # comment only (line ~131: "stok sudah masuk" is no longer true)
tests/Feature/PreorderTest.php                        # rewrite arrival/hand-over tests for the new rule
tests/Feature/PreorderUpdateTest.php                  # edit-after-arrival tests: no movement
tests/Feature/StockMovementsTest.php                  # fixtures that relied on arrival 'purchase' rows
tests/Feature/StockTransactionSyncTest.php            # fixture that relied on arrival adding stock
tests/Feature/PreorderPaymentLedgerTest.php           # verify unaffected; add arrival-of-fully-paid case
tests/Feature/SettlePaidArrivedPreordersCommandTest.php  # NEW
docs/openapi-pos-mvp.yaml                             # status + update descriptions (lines ~2574, ~2767)
docs/RUNBOOK.md                                       # how to run the correction after pulling this change
CLAUDE.md                                             # dated note; fix stock invariants bullets and the test-name example
```

**Structure Decision**: Backend logic stays in `PreorderService` (business logic never in controllers). The correction is an artisan command in the same spirit as `app:sync-stock-from-transactions`, but deliberately lighter: no plan hash, no Excel, because nothing about money or stock changes (research D6).

## Complexity Tracking

None.
