---

description: "Task list for Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports"

---

# Tasks: Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

**Input**: Design documents from `/specs/012-seller-preorder-report-detail-export/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: Included — Constitution Principle II requires `tests/Feature/` coverage for every backend change and a real-browser check for every touched screen; not optional for this project.

**Organization**: Tasks are grouped by user story (US1–US4, matching spec.md's priorities P1/P2/P2/P2) so each can be delivered and verified independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US4)

## Path Conventions

Existing single Laravel + Vue repo, branched from `010-split-payment-preorder-reports`: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`, `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Confirm dev environment is ready: `laradock-mysql-1` running, `.env.testing` present, `npm run dev`/`php artisan serve` both start clean — no code changes

---

## Phase 2: Foundational

**Note**: No schema changes and no shared blocking infrastructure — `PreorderItem.artist_id` already exists. Each user story below extends a different part of `ReportController.php`/`ReportsView.vue`; the only cross-story file overlap is noted in Dependencies below. Proceed directly to User Story phases.

---

## Phase 3: User Story 1 - Seller's transaction detail includes their preorder sales (Priority: P1) 🎯 MVP

**Goal**: A seller's Seller Recap transaction-detail drilldown shows preorder-sourced transactions alongside regular sales, summing back to the already-correct (010-fixed) total

**Independent Test**: For a seller with both a sale and a partially-paid preorder contributing to their total, open their transaction detail and confirm both appear, summing to the reported total exactly

### Implementation for User Story 1

- [X] T002 [US1] In `app/Http/Controllers/Api/ReportController.php`, extend `artistSettlementTransactions()`: (a) add the missing explicit `data_mode = ModeGate::current()` filter to the existing `OrderItem`/`orders` query (a real pre-existing gap, per research.md R1); (b) add a parallel query over `preorder_items` joined to `preorders` and a `payments`-collected subquery (mirroring `010`'s `preorders()` pattern), filtered to `preorder_items.artist_id`, `preorders.event_id`, `preorders.status != 'cancelled'`, and explicit `data_mode` on both joined tables; (c) shape both query results into the unified entry format from data-model.md (`key`, `number`, `source`, `created_at`, `items[]`, `amount_for_artist` — the preorder side's `amount_for_artist` uses the R1/R2-established proration of collected cash, never full `line_total`); (d) merge the two collections and re-sort by `created_at` descending before returning
- [X] T003 [US1] Update `resources/js/components/report/ArtistTransactionsModal.vue` to render the new unified entry shape: use `tx.key` instead of `tx.order_id` for the list key, use `tx.amount_for_artist` instead of `tx.order_total_for_artist`, and add a small type badge/label distinguishing `source: 'order'` ("Penjualan"/"Sale") from `source: 'preorder'` ("Pre-order") entries
- [X] T004 [P] [US1] Extend `tests/Feature/ReportTest.php`: merged transaction list includes both a sale and a preorder entry for a seller with both; the preorder entry's amount reflects collected-and-prorated cash, not full order value; a cancelled preorder's entry is absent; the sum of all entries equals the seller's Seller Recap `total_sales`; both the order and preorder halves respect `data_mode` isolation (a demo-mode order/preorder must not appear when querying live, and vice versa)
- [X] T005 [P] [US1] Extend `qa-tests/component/ArtistTransactionsModal.test.js`: renders a mix of order- and preorder-sourced entries with the correct distinguishing badge/label, using the new `key`/`amount_for_artist` field names
- [X] T006 [US1] Manual browser verification per `quickstart.md` steps 1–2 (mixed-source detail sums correctly; cancelled preorder excluded)

**Checkpoint**: User Story 1 fully functional and independently testable/demoable

---

## Phase 4: User Story 2 - Pre-order report shows a per-seller breakdown (Priority: P2)

**Goal**: The Pre-order report can show figures broken down by seller, not only by status/payment-completeness

**Independent Test**: With preorders spanning multiple sellers (including one preorder spanning two sellers), view the report and confirm per-seller figures are visible and a multi-seller preorder's value is correctly split

### Implementation for User Story 2

- [X] T007 [US2] In `app/Http/Controllers/Api/ReportController.php`, extend `preorders()` to accept an optional `breakdown=artist` param: when present, join `preorder_items` (on `artist_id`) to the existing `preorders`+collected-subquery structure, group by `artist_id, status, payment_completeness`, and compute `total_order_value`/`total_collected`/`total_outstanding` per artist using the same proration rule as T002 (item value share of that preorder's totals) — one grouped SQL query, no per-preorder PHP loop, per Constitution V
- [X] T008 [P] [US2] Extend `tests/Feature/ReportTest.php`: `breakdown=artist` returns correct per-seller rows for a concrete multi-seller preorder fixture (assert exact prorated numbers, not just non-zero), and summing all artist rows for a fixed status/completeness bucket equals that bucket's existing non-broken-down total
- [X] T009 [US2] Confirm `resources/js/api/reports.js`'s `preorderReport(params)` already passes through an arbitrary `params` object (it should, per research.md R6 — verify, don't assume) so `breakdown: 'artist'` can be sent without a function-signature change
- [X] T010 [US2] Add a per-seller breakdown view to the Pre-order tab in `resources/js/views/ReportsView.vue` (e.g. a toggle or an additional grouped table showing `artist_name` alongside the existing status/completeness columns), calling `preorderReport({ ...existing params, breakdown: 'artist' })`
- [X] T011 [US2] Add new locale keys to `resources/js/locales/id.json`/`en.json` for the seller-breakdown view's labels, reusing the existing `PREORDER_STATUS_LABEL`/`PAYMENT_COMPLETENESS_LABEL` mappings already in `ReportsView.vue` rather than duplicating them
- [X] T012 [US2] Manual browser verification per `quickstart.md` step 3

**Checkpoint**: User Stories 1–2 both independently functional

---

## Phase 5: User Story 3 - Pre-order report rows drill down to the individual preorders behind them (Priority: P2)

**Goal**: Clicking a Pre-order report row (summary or per-seller) reveals the individual preorder(s) behind it, and selecting one opens its existing full detail view

**Independent Test**: Click a report row representing more than one preorder, confirm the listed preorders sum back to the row's totals, select one, confirm it opens that preorder's existing detail view

### Implementation for User Story 3

- [X] T013 [US3] In `app/Http/Controllers/Api/ReportController.php`, extend `preorders()` further to accept `status`, `payment_completeness`, and optional `artist_id` params together: when present, return the individual-preorder drilldown rows from data-model.md (`preorder_id, preorder_number, customer_name, order_value, collected, outstanding`) matching that bucket, instead of an aggregate — reuse the same endpoint per research.md R3, not a new route
- [X] T014 [P] [US3] Extend `tests/Feature/ReportTest.php`: drilldown params return the correct individual preorders for a given status/completeness (and artist, when supplied) bucket, and their amounts sum back to that bucket's aggregate row
- [X] T015 [US3] Create `resources/js/components/report/PreorderReportDetailModal.vue`, mirroring `StockByArtistDetailModal.vue`'s exact structural pattern (props: `open`, `status`, `paymentCompleteness`, optional `artistId`; `watch` on open+keys; on-demand fetch via `preorderReport({...keys})`; `emit('close')`)
- [X] T016 [US3] Wire a row-click action in `ReportsView.vue`'s Pre-order tab (and its per-seller breakdown from T010) to open `PreorderReportDetailModal.vue` with that row's keys, mirroring the existing `openStockDetail`/`showStockDetail` pattern already used for the Stock by Seller tab
- [X] T017 [US3] In `PreorderReportDetailModal.vue`, wire selecting an individual preorder to navigate via `router.push` to `/preorders?preorder_id=<id>`, reusing `PreordersView.vue`'s existing `route.query.preorder_id` deep-link handler (`onMounted` + `openDetailById`) — no new detail UI
- [X] T018 [P] [US3] Create `qa-tests/component/PreorderReportDetailModal.test.js`: renders the individual-preorder list for given keys, amounts sum to a provided expected total, selecting a row triggers navigation to `/preorders?preorder_id=<id>`
- [X] T019 [US3] Manual browser verification per `quickstart.md` step 4

**Checkpoint**: User Stories 1–3 all independently functional

---

## Phase 6: User Story 4 - Every report has an Excel export (Priority: P2)

**Goal**: Purchases, Stock by Seller, and Pre-order report tabs each have a working Excel export

**Independent Test**: Open each of the three tabs, export, confirm a workbook downloads matching on-screen data and respecting the active event filter

### Implementation for User Story 4

- [X] T020 [US4] In `app/Http/Controllers/Api/ReportController.php`'s `export()` method, extend the whitelist to accept `'purchases'` and `'stock-by-artist'`, adding `match()` branches that wrap `purchases()`'s `rows` and `stockByArtist()`'s summary `data` in `GenericArrayExport`, following the exact pattern already used for `sales`/`artist-profit`
- [X] T021 [US4] Add `'preorder'` to the same whitelist and implement a new private `exportPreorderReport(Request $request)` method following `exportArtistSettlements()`'s exact structure: build summary rows (existing `preorders()` default response) and per-seller rows (T007's `breakdown=artist` response), then `Excel::download(new MultiSheetArrayExport([new SheetArrayExport('Ringkasan', ..., $summaryRows), new SheetArrayExport('Per Seller', ..., $breakdownRows)]), 'laporan-preorder.xlsx')`
- [X] T022 [P] [US4] Extend `tests/Feature/ReportTest.php`: `GET /reports/{report}/export` succeeds (200, correct content-type) for `purchases`, `stock-by-artist`, and `preorder`; the `preorder` export's workbook has exactly two sheets named "Ringkasan" and "Per Seller"; all three respect the `event_id` filter param
- [X] T023 [US4] Add three new `<BaseButton v-if="activeTab === '<tab>'" @click="doExport('<report-type>')">` call sites in `resources/js/views/ReportsView.vue` for the Purchases, Stock by Seller, and Pre-order tabs, copy-pasting the existing pattern exactly (no change needed to `doExport()`/`exportReport()`, per research.md R6)
- [X] T024 [US4] Manual browser verification per `quickstart.md` steps 5–6 (all three exports produce correct workbooks; filter is respected)

**Checkpoint**: All 4 user stories independently functional

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T025 [P] Update `docs/openapi-pos-mvp.yaml` for every delta in `contracts/api-deltas.md` (transactions response shape, `preorders()`'s new `breakdown`/drilldown params, `export()`'s extended whitelist) — in the same commit as the corresponding code change per this repo's Documentation & Change Discipline rule, or consolidated here if not already done per-task
- [X] T026 [P] Run `php artisan test` (full suite) — confirm no regressions
- [X] T027 [P] Run `npm test` (full Vitest suite) — confirm no regressions
- [X] T028 Run the full `quickstart.md` end-to-end validation in a real browser
- [X] T029 If any non-obvious bug is found and fixed during implementation, record it in code with a `BUG YANG DITEMUKAN & DIPERBAIKI` comment and in README.md's bug list, per repo convention

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: No blocking tasks
- **US1 (Phase 3, P1)**: Can start after Setup
- **US2 (Phase 4, P2)**: Can start after Setup — touches `preorders()` and the Pre-order tab, disjoint from US1's `artistSettlementTransactions()`/`ArtistTransactionsModal.vue`
- **US3 (Phase 5, P2)**: Depends on **T007** (US2) being complete — the drilldown's backend (T013) extends the same `preorders()` method US2 already modified, and its frontend (T016) hooks into the same Pre-order tab UI US2 adds to (T010). Not independent of US2 at the implementation level, though it remains a separately testable increment once US2 lands.
- **US4 (Phase 6, P2)**: T021 (the `preorder` export) depends on **T007** (US2's `breakdown=artist` response) for its "Per Seller" sheet; T020 (Purchases/Stock-by-Seller exports) has no dependency on any other story and can start immediately after Setup
- **Polish (Phase 7)**: Depends on all desired user stories being complete

### Within Each User Story

- Backend query changes before their tests (or written alongside, TDD-style, per Constitution II)
- Backend changes before frontend changes that consume them
- Manual browser verification last in each phase

### Parallel Opportunities

- **T020** (US4, Purchases/Stock-by-Seller export) can run in parallel with US1 and US2 from the start — no shared file, no shared dependency
- Within US1: T004/T005 (tests) are `[P]` with each other
- Within US2: T008 (test) is `[P]` with T009 (frontend API-client confirmation, likely a no-op)
- Within US3: T014 (test) and T018 (qa-test) are `[P]` with each other
- Within US4: T022 (test) is `[P]` with T023 (frontend buttons) once T020/T021 land
- **Sequencing note**: T002 (US1) and T007 (US2) both edit `ReportController.php` but different methods (`artistSettlementTransactions()` vs `preorders()`) — safe to parallelize across two sessions working the same file if careful, but a single session should do them sequentially to avoid merge friction within one edit session

---

## Parallel Example: User Story 1 tests

```bash
Task: "Extend tests/Feature/ReportTest.php for merged seller transaction detail"
Task: "Extend qa-tests/component/ArtistTransactionsModal.test.js for preorder-sourced entries"
```

## Parallel Example: US4's independent export (alongside US1/US2)

```bash
Task: "Extend ReportController::export() whitelist for purchases/stock-by-artist"
Task: "Extend artistSettlementTransactions() for US1"
Task: "Extend preorders() for US2's breakdown=artist"
# All three touch ReportController.php but different methods — coordinate within one session, or split across sessions carefully
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1: Setup
2. Complete Phase 3: US1 (seller transaction detail includes preorders — also fixes the data_mode gap)
3. **STOP and VALIDATE**: US1 independently, per its Independent Test criteria
4. Ship/demo — closes the most visible correctness gap (a total that can't be traced to its own detail)

### Incremental Delivery

1. Setup → US1 → verify → ship (the correctness fix)
2. US2 (per-seller Pre-order breakdown) → verify → ship
3. US3 (drilldown, depends on US2's T007) → verify → ship
4. US4 (exports — T020 could ship even earlier, in parallel with US1/US2; T021 waits on US2) → verify → ship
5. Phase 7 polish as a final cross-cutting pass

### Suggested MVP Scope

**User Story 1** alone — it's the only P1, it's a correctness fix (not a new capability), and it's fully independent of the other three stories.

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- US3 is the one story with a real dependency on another (US2's T007) — not fully parallelizable with it, unlike every other pair of stories in this feature
- The proration rule used throughout (T002, T007, T013) MUST be the same one `010` established (research.md R1/R2 here cite it explicitly) — do not derive a second, divergent calculation
- Verify tests fail before implementing (Constitution II / TDD discipline)
- Commit after each task or logical group
- Stop at any checkpoint to validate a story independently
- Avoid: same-file conflicts flagged as [P], cross-story dependencies that break independence (already flagged for US3 above — this is the one accepted exception)
