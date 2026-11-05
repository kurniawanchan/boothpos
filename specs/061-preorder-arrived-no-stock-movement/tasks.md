**Classification: INTERNAL**

---

description: "Tasks for Pre-order Goods arrived makes no stock movement"
---

# Tasks: Pre-order "Goods arrived" Makes No Stock Movement

**Input**: Design documents from `/specs/061-preorder-arrived-no-stock-movement/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/status-and-correction.md, quickstart.md

**Tests**: Included (constitution Principle II, and about six existing test files encode the old rule).

**Organization**: Grouped by user story. Backend plus two locale strings; no migration, no route.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 to US4

**Test command (container, mandatory overrides, see CLAUDE.md):**
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`
Never run `php artisan test` in the container without those `-e` flags: it wipes the dev database.
Run test commands one at a time (two runs on `boothpos_test` at once corrupt each other).

## Phase 1: Setup

- [X] T001 Run the baseline for the affected suites (`PreorderTest|PreorderUpdateTest|StockMovementsTest|StockTransactionSyncTest|PreorderPaymentLedgerTest|PreorderPaymentDeleteTest|PreorderSplitTest|PreorderInvoiceTest|PreorderDeleteTest`) and note any failure that exists before editing — Baseline: 179 passed.

---

## Phase 2: Foundational

None. US1 carries the one blocking production change (removing the arrival movement); US2 to US4 build on it.

---

## Phase 3: User Story 1 - Arrival no longer changes stock (Priority: P1) 🎯 MVP

**Goal**: Moving a pre-order to Goods arrived changes no stock and writes no history row, even when stock is zero.

**Independent Test**: Pre-order qty 3 of a variant with stock 10; move to Goods arrived; stock is still 10 and `stock_movements` has no row for it.

### Tests for User Story 1 (write first, confirm they fail)

- [X] T002 [US1] In `tests/Feature/PreorderTest.php` replace `test_arrived_status_increases_stock` with `test_arrived_status_makes_no_stock_movement`: stock unchanged and `assertDatabaseMissing('stock_movements', ...)` for the variant (FR-001)
- [X] T003 [US1] In `tests/Feature/PreorderTest.php` add `test_arrival_succeeds_when_stock_is_zero_and_with_several_variants`: two variants, one at stock 0, both unchanged after arrival, response 200 (FR-001, FR-002)
- [X] T004 [US1] Run the two tests and confirm they fail because arrival still adds stock

### Implementation for User Story 1

- [X] T005 [US1] In `app/Services/PreorderService.php` `transitionStatus()` delete the `if ($newStatus === 'arrived')` loop that writes the `purchase` movement. Leave the `handed_over` loop untouched
- [X] T006 [P] [US1] Update stale comments that say arrival adds stock: `app/Services/StockService.php` docblock (line ~12), `app/Services/PaymentService.php` (~131 "stok sudah masuk" and the syncCache note), `app/Services/PreorderService.php` comments near lines ~313 and ~333 (split inherits status, arrival movement)
- [X] T007 [US1] Run T002 and T003 and confirm they pass

### Existing tests and fixtures that relied on the old arrival rule

- [X] T008 [US1] Read `tests/Feature/StockMovementsTest.php` (lines ~160-265) and adjust fixtures that used arrival to create `purchase` rows or to supply stock: create the rows with `StockService::applyMovement()` or direct fixtures instead, and fix the line ~166 manual `settled` call if the pre-order is already settled after arrival
- [X] T009 [P] [US1] Adjust `tests/Feature/StockTransactionSyncTest.php` (line ~117) so its fixture no longer depends on arrival adding stock — Result: no change needed; the test passes unchanged (its fixture only sets stock through the ledger).
- [X] T010 [P] [US1] Adjust `tests/Feature/PreorderInvoiceTest.php` (line ~70): a fully paid pre-order that arrives is now already `settled`, so the explicit move to `settled` there is no longer valid — Result: no change needed; the explicit move to `settled` there is a bare `patchJson` whose response is not asserted, and the test passes.
- [X] T011 [P] [US1] In `resources/js/locales/en.json` and `resources/js/locales/id.json` change `preorders.arrived_marked` so it no longer says stock increased (for example "Marked as arrived. Stock changes when handed over."); keep both languages in step (the locale-key test fails on a missing key)
- [X] T012 [US1] Run `StockMovementsTest`, `StockTransactionSyncTest`, `PreorderInvoiceTest` and fix any remaining fixture that assumed arrival stock

**Checkpoint**: Arrival moves no stock. This is the MVP.

---

## Phase 4: User Story 2 - Hand-over still deducts, exactly once (Priority: P1)

**Goal**: Hand-over deducts as today, and the lifecycle nets to one deduction. Hand-over now needs stock entered beforehand.

**Independent Test**: Stock 10, qty 3: arrive (10), pay in full, hand over (7), exactly one `preorder_handover` row and no `purchase` row.

### Tests for User Story 2

- [X] T013 [US2] In `tests/Feature/PreorderTest.php` fix the hand-over tests that used arrival to supply stock (the variant starts at stock 0 in `setUp`): `test_handed_over_succeeds_when_fully_paid_and_decreases_stock` (~line 150) and `test_handed_over_rejected_when_not_fully_paid` (~line 135, its "stock stays" assertion) now give the variant stock up front
- [X] T014 [US2] Add `test_full_lifecycle_nets_exactly_one_deduction` to `tests/Feature/PreorderTest.php`: stock 10, qty 3, arrive, pay, hand over; stock 7, one `preorder_handover` row of -3, zero `purchase` rows (FR-003, FR-008, SC-002)
- [X] T015 [US2] Add `test_handover_is_refused_when_stock_is_insufficient_and_changes_nothing` to `tests/Feature/PreorderTest.php`: stock lower than qty gives 409 naming the available stock, status stays `settled`, no movement written (FR-004, SC-005)
- [X] T016 [US2] Run `PreorderTest` and confirm green

### Demo data

- [X] T017 [US2] In `database/seeders/SakanaFridgeDemoSeeder.php` `seedPreorders()` make sure the full-cycle pre-order's variant has enough stock before hand-over (it used to get it from arrival), and fix the docblock that says "arrived (stok naik)". Verify against the TEST database only: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan db:seed --class=SakanaFridgeDemoSeeder`

**Checkpoint**: Stock moves only at hand-over.

---

## Phase 5: User Story 3 - Editing an arrived pre-order does not move stock (Priority: P2)

**Goal**: Editing quantities of an arrived or settled pre-order writes no movement; hand-over uses the final quantities.

**Independent Test**: Arrived pre-order qty 3 (stock 10); edit to 5: stock still 10; hand over: stock 5.

### Tests for User Story 3

- [X] T018 [US3] In `tests/Feature/PreorderUpdateTest.php` rewrite `test_editing_items_after_arrived_corrects_stock_by_exact_delta` (~line 75) as `test_editing_items_after_arrived_writes_no_stock_movement`: stocks unchanged, `assertDatabaseMissing('stock_movements', ...)` (FR-006). Keep `test_editing_items_after_arrived_with_unchanged_quantity_writes_no_new_movement` (~line 100) and fix its setup if it assumed an arrival row
- [X] T019 [US3] Fix `test_edit_is_refused_when_status_is_handed_over` (~line 120-130): pay in full, arrive (now `settled`), hand over; remove the explicit move to `settled` and give the variant stock
- [X] T020 [US3] Add `test_editing_an_arrived_preorder_then_handing_over_deducts_the_edited_quantity` to `tests/Feature/PreorderUpdateTest.php`
- [X] T021 [US3] Run the new and rewritten tests, confirm the first fails (delta still written)

### Implementation for User Story 3

- [X] T022 [US3] In `app/Services/PreorderService.php` `update()` remove the `if (in_array($preorder->status, ['arrived', 'settled'], true))` stock-delta block (~lines 570-598) and its comment; keep the item delete/recreate and the rest of `update()` unchanged. Remove now-unused variables (`$oldQtyByVariant`, `$newQtyByVariant` only if nothing else reads them)
- [X] T023 [US3] Run `PreorderUpdateTest` and `PreorderTest`, confirm green

**Checkpoint**: Edits never move stock.

---

## Phase 6: User Story 4 - Fully paid pre-order can be settled and handed over (Priority: P1)

**Goal**: A fully paid pre-order becomes Settled on arrival, and already-stuck ones are fixed by a one-off, logged, idempotent command.

**Independent Test**: Pay in full while in Deposit paid, move to Goods arrived: status is `settled`, Hand over works. Run the command on a stuck row: it becomes `settled`.

### Tests for User Story 4

- [X] T024 [US4] In `tests/Feature/PreorderPaymentLedgerTest.php` add `test_arriving_while_fully_paid_settles_in_the_same_step`: pay in full in `dp_paid`, move to `arrived`, assert response status `settled`, no stock movement (FR-010). Confirm the existing `test_the_last_payment_settles_an_arrived_pre_order` (~line 221) still passes
- [X] T025 [US4] Add `test_partially_paid_arrival_stays_arrived_until_a_payment_completes_it` to the same file
- [X] T026 [US4] Add `test_a_pre_order_settled_on_arrival_can_be_handed_over` to `tests/Feature/PreorderPaymentLedgerTest.php` (stock provided up front)
- [X] T027 [US4] Create `tests/Feature/SettlePaidArrivedPreordersCommandTest.php` covering: dry-run lists numbers and writes nothing; `--apply --by=<owner>` settles a stuck row and writes one `preorder_status_corrected` activity-log row with old/new status; no stock, payment or total change; a partially paid `arrived` row, a `settled` row and a `cancelled` row are untouched; DEMO and LIVE rows are both handled (use `ModeGate::runAs`); a second run reports 0; `--apply` without `--by`, with an unknown user, or with a non-owner/admin user fails and writes nothing; output contains the pre-order number but no customer name (FR-011). Avoid test helper names that clash with `TestCase` (`put`, `seed`, `get`, `call`)
- [X] T028 [US4] Run the new tests and confirm they fail (method and command do not exist yet)

### Implementation for User Story 4

- [X] T029 [US4] In `app/Services/PreorderService.php` add `public function settleArrivedIfFullyPaid(Preorder $preorder): bool`: only when `status === 'arrived'` and `PaymentSummary::for($preorder->load('payments'))['status'] === PaymentSummary::FULLY_PAID` (and `total_amount > 0`), set status `settled` and return true. Call it inside the `transitionStatus()` transaction right after the `arrived` update, before the `fresh()` return
- [X] T030 [US4] Create `app/Console/Commands/SettlePaidArrivedPreorders.php` (signature `app:settle-paid-arrived-preorders {--apply} {--by=}`): dry-run default; resolve `--by` to an existing user with `isOwnerOrAdmin()`; loop modes `demo` and `live` with `ModeGate::runAs()`; per pre-order one `DB::transaction` that calls `settleArrivedIfFullyPaid()` and `ActivityLogger::log(action: 'preorder_status_corrected', entityType: 'preorder', ..., oldValues: ['status' => 'arrived'], newValues: ['status' => 'settled'])`; a failure on one row is reported and the rest continue; print only preorder numbers and counts; Indonesian comments citing why (see research D6). Confirm the command is auto-registered like the existing ones in `app/Console/Commands`
- [X] T031 [US4] Run `PreorderPaymentLedgerTest` and `SettlePaidArrivedPreordersCommandTest`, confirm green
- [X] T032 [US4] Verify what `PreorderNotifier` does for a pre-order whose status is `settled` right after arrival (research D7). Record the finding in `specs/061-preorder-arrived-no-stock-movement/research.md` D7 and change code only if it misbehaves (for example a wrong or duplicate email) — Result: one email with the "Lunas" theme, no duplicate; recorded in research.md D7.

**Checkpoint**: No fully paid pre-order can get stuck, and the existing stuck one has a fix.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T033 [P] Update `docs/openapi-pos-mvp.yaml`: status endpoint (~line 2574 and ~2811) and the pre-order update description (~line 2767-2768) so they no longer say arrival adds stock, edits adjust by delta, and add that arrival may return `settled` when fully paid. Same commit as the code
- [X] T034 [P] Add a section to `docs/RUNBOOK.md` for `app:settle-paid-arrived-preorders` (dry-run first, then `--apply --by=<owner>`, Docker form, when to run: once after pulling this change)
- [X] T035 [P] Update `CLAUDE.md`: the "Stock movement invariants" bullets (line ~59: arrival no longer increases stock; hand-over unchanged), the Commands example that names `test_arrived_status_increases_stock` (line ~17), and add a dated note (2026-11-05) in the pre-order section. Dated notes, not rewritten history
- [X] T036 [P] Search `README.md`, `docs/PRD-POS-Event-Multivendor.md`, `docs/wbs-pos-mvp.md`, `docs/uml-pos-mvp.md` for statements that arrival adds stock and add a short dated note where found (do not rewrite history). Note which files had none
- [X] T037 Run the full backend suite the safe way (command above, no `--filter`) and compare with T001 and the known 9 `Code128Test` failures; report anything else with its output — Result: 1850 passed, 9 failed, all 9 `Tests\Unit\Code128Test` (known, dev-only reference library).
- [X] T038 Run the frontend suite: `npx vitest run --exclude '.claude/**'`; report failures — Result: 136 files, 1247 passed, 2 skipped.
- [ ] T039 Walk through `specs/061-preorder-arrived-no-stock-movement/quickstart.md` on the dev database. Run the command in dry-run mode first and show the list; ask the user before running `--apply` on the dev database. Mark the real-browser check as not verified if it was not done

---

## Dependencies & Execution Order

- T001 first. US1 (T002 to T012) next: T005 is the blocking production change; T008 to T012 depend on it.
- US2 (T013 to T017) depends on T005. US3 (T018 to T023) depends on T005. US4 (T024 to T032) depends on T005 for the arrival path and is otherwise independent of US2 and US3.
- Several tasks edit the same files (`PreorderTest.php`: T002, T003, T013 to T015; `PreorderService.php`: T005, T022, T029; `PreorderUpdateTest.php`: T018 to T020), so do those in order.
- Polish last; T037 and T038 after all code tasks.

### Parallel Opportunities

- T006, T009, T010, T011 (different files) after T005.
- T033 to T036 (docs) at any time after the behaviour is settled.
- US3 and US4 test writing can proceed in parallel with each other once T005 is done (different test files), but not with US1's edits to shared files.

## Implementation Strategy

- **MVP**: T001 to T012 (User Story 1): arrival stops moving stock.
- **Then**: US2 (lifecycle and hand-over proof), US3 (edits), US4 (stuck pre-orders), then polish.
- **Commit**: one commit with code, tests, locale, OpenAPI and docs, as in earlier features.
- **After merge**: run the correction command once per install (T034 documents it).

## Notes

- Total: 39 tasks. US1: 11 (T002 to T012). US2: 5 (T013 to T017). US3: 6 (T018 to T023). US4: 9 (T024 to T032). Setup: 1. Polish: 7 (T033 to T039).
- Production edits: `PreorderService.php` (three places), one new command, two locale files, one seeder, comments in two services.
