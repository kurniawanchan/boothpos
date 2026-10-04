---

description: "Task list for feature 033 — separate POS sales from pre-order sales in the reports"
---

# Tasks: Separate POS Sales from Pre-order Sales in the Reports

**Input**: Design documents from `/specs/033-seller-recap-pos-preorder-split/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/report-split.md, quickstart.md

**Tests**: INCLUDED. The spec's success criteria (SC-001..SC-006) are reconciliation guarantees and the constitution (II) requires tests; the existing `ReportTest` is the regression net for the unchanged figures.

**Organization**: By user story. US1 (Seller Recap columns) and US2 (drill-down + export reconcile) are P1; US3 (Cost & Profit + Seller Cost) is P2. No schema change, no new endpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 / US2 / US3

## Conventions to honour

- Comments, locale `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` (a key repeated within a section silently overrides — check for existing names first).
- Every new hand-rolled `DB::table(...)` query filters `data_mode = ModeGate::current()` explicitly.
- Backend tests run on the HOST only: `APP_ENV=testing php artisan test --filter=...` (database `boothpos_test`); never inside the `app` container without the `-e` overrides.
- Money in responses is a 2-dp string; units are integers.

---

## Phase 1: Setup

- [X] T001 Read the current code that this feature extends and note the exact line ranges before editing: `app/Services/SettlementService.php::recalculateForEvent`, `app/Http/Controllers/Api/ReportController.php` (`artistSettlements`, `profit`, `artistProfit`, `exportArtistSettlements` + `$summaryHeadings`, `PREORDER_FRACTION_EXPR`, `preorderRecognizedRevenueBase`), `resources/js/views/ReportsView.vue` (settlement table, profit cards, artist-profit table), and the existing assertions in `tests/Feature/ReportTest.php` + `tests/Feature/PreorderReportTest.php` (they must stay green unchanged).
- [X] T002 Run the baseline once and record the result: `APP_ENV=testing php artisan test --filter='ReportTest|PreorderReportTest|ReportDataModeIsolationTest|PreorderDuplicateSplitReportsTest'` and `npx vitest run qa-tests/component/ReportsView.test.js` (all must pass before any change).

---

## Phase 2: Foundational (blocks all stories)

**Purpose**: the single-source breakdown and the reconciliation helpers every report uses.

- [X] T003 Create `app/Support/ReportSplit.php` with small pure helpers: `cents(float|string): int` (round half up of value×100), `money(int $cents): string` (2-dp string), and `remainder(float|string $total, float|string $part): string` (= total − part in cents, as 2-dp money). Indonesian docblock explaining why the pre-order part is a REMAINDER (research Decision 2).
- [X] T004 [P] Unit-test the helpers in `tests/Unit/ReportSplitTest.php` (.5 cents, 0.1+0.2 float traps, negative never produced for consistent inputs, zero).
- [X] T005 Refactor `app/Services/SettlementService.php`: extract the two aggregations of `recalculateForEvent()` into a public `salesBreakdownForEvent(Event $event): Collection` keyed by `artist_id` with `pos_sales`, `pos_units` (int), `preorder_sales`, `preorder_units` (float, fractional). Same queries, same `data_mode` filters, same completed-order / non-cancelled-pre-order and fraction rules. Rebuild `recalculateForEvent()` on it: `total_sales = pos + preorder`, `total_units = (int) round(pos_units + preorder_units)`, `payable_amount = total_sales − deduction` — behaviour must be byte-for-byte identical (the stored values do not change).
- [X] T006 Run the baseline from T002 again; the refactor must not change a single existing assertion (checkpoint — stop and fix before continuing).

**Checkpoint**: stored settlement totals unchanged; breakdown available to the controller.

---

## Phase 3: User Story 1 — Seller Recap POS / Pre-order columns (Priority: P1) 🎯 MVP

**Goal**: each Seller Recap row (and the Grand Total) shows POS units/sales and pre-order units/sales that add up exactly to the existing totals.

**Independent Test**: seed one seller with a POS sale and a partially paid pre-order → row shows both parts; `pos + preorder = total` for units and sales; Grand Total reconciles.

### Tests for US1 (write first, expect them to fail)

- [X] T007 [P] [US1] Create `tests/Feature/ReportPosPreorderSplitTest.php` (setUp: owner/admin user, event, two sellers, products/variants, helper to create completed POS orders and pre-orders with payments through the existing services/factories used by `ReportTest`/`PreorderReportTest`). Add settlement cases: seller with POS + partially paid pre-order (parts present, `pos_units + preorder_units = total_units`, `pos_sales + preorder_sales = total_sales`); only-POS seller (pre-order parts 0); only-pre-order seller (POS parts 0); zero-sales active seller (all `0` / `"0.00"`).
- [X] T008 [P] [US1] Same file — rounding and exclusion cases: pre-order fractions of .5 units (two half-paid single-unit pre-orders) never make the parts differ from the total; sub-cent money; cancelled pre-order and voided order appear in neither part; a payment with `verification = rejected` does not raise the pre-order part.
- [X] T009 [P] [US1] Same file — DEMO/LIVE isolation (a demo order/pre-order never leaks into live parts) and authorization (cashier and inventory get `403` on `GET /api/v1/reports/artist-settlements`).

### Implementation for US1

- [X] T010 [US1] In `app/Http/Controllers/Api/ReportController.php::artistSettlements()` call `SettlementService::salesBreakdownForEvent()` (after the existing recalculation) and add to every row (including the zero-earning left-joined rows): `pos_units`, `preorder_units = total_units − pos_units`, `pos_sales`, `preorder_sales = ReportSplit::remainder(total_sales, pos_sales)`. Keys and order of every existing field unchanged; no extra per-seller query.
- [X] T011 [US1] `resources/js/views/ReportsView.vue` — Seller Recap table: add columns `POS unit`, `Pre-order unit`, `POS sales`, `Pre-order sales` next to the existing `Unit`/`Sales` (compact headers, `whitespace-nowrap` right-aligned numeric cells, existing money/number formatters); extend `settlementTotals` (`sumRows`) so the footer Grand Total row carries the four new columns; keep the row key on `artist_id`.
- [X] T012 [P] [US1] Locale keys in `resources/js/locales/en.json` and `id.json` under the existing `reports` section (check first that the names are unused): `col_pos_unit`, `col_preorder_unit`, `col_pos_sales`, `col_preorder_sales` (EN: "POS unit", "Pre-order unit", "POS sales", "Pre-order sales"; ID: "Unit POS", "Unit pre-order", "Penjualan POS", "Penjualan pre-order").
- [X] T013 [P] [US1] Extend `qa-tests/component/ReportsView.test.js`: a settlement fixture with the new fields renders the four columns and a Grand Total row whose POS + pre-order equal the Unit/Sales totals; a fixture without the new fields (old response) still renders without errors.
- [X] T014 [US1] Run `ReportPosPreorderSplitTest` (settlement cases) + the T002 regression set + `npx vitest run qa-tests/component/ReportsView.test.js`; all green.

**Checkpoint**: US1 is demonstrable on screen and shippable on its own.

---

## Phase 4: User Story 2 — Reconcile with the detail view and the export (Priority: P1)

**Goal**: the same split appears in the Recap export and equals the sums of the seller's "Transaction detail" by kind.

**Independent Test**: for one seller, sum `order-*` rows and `preorder-*` rows of the drill-down and compare with the columns; export the recap and compare headings/values.

### Tests for US2

- [X] T015 [P] [US2] In `tests/Feature/ReportPosPreorderSplitTest.php`: drill-down reconciliation — for a seller with both kinds, the sum of `GET /reports/artist-settlements/{artist}/transactions` amounts for `order-*` rows equals `pos_sales` and for `preorder-*` rows equals `preorder_sales` (compare in cents; document the rounding rule if the drill-down shows per-row rounded values), and the same for units.
- [X] T016 [P] [US2] Same file — export: the `artist-settlements` export (`GET /reports/artist-settlements/export` via the existing dispatcher, read the generated sheet with the same helper `ReportTest` uses) has all existing headings unchanged and in place, plus appended `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` (same names as the API fields, so the sheet needs no aliasing) with the row values equal to the API; non-owner/admin → `403` through the export route.

### Implementation for US2

- [X] T017 [US2] `ReportController::exportArtistSettlements()`: append the four headings to `$summaryHeadings` (after `status`, old shape preserved) and fill them from the same rows used by `artistSettlements()` (reuse the builder, do not recompute). Leave the "Detail Transaksi" sheet untouched (known gap noted in research).
- [X] T018 [US2] If T015 exposes a mismatch caused by the drill-down (`artistSettlementTransactions`) rounding or filtering differently from the breakdown, fix it in the SMALLEST way that makes the by-kind sums match (shared fraction expression / same status filters); otherwise record in research.md that none was needed.
- [X] T019 [US2] Run `ReportPosPreorderSplitTest` fully + T002 regression set; all green.

**Checkpoint**: US1 + US2 reconcile screen, detail and file.

---

## Phase 5: User Story 3 — Cost & Profit and Seller Cost (Priority: P2)

**Goal**: revenue / cost of goods / gross profit split (Cost & Profit); Seller Cost shows POS | Pre-order | Total for sales, cost and gross profit and now includes pre-orders.

**Independent Test**: event with both kinds → each metric POS + pre-order = total; Seller Cost POS parts equal the old numbers; pre-order-only seller listed; Seller Cost `total_sales` equals Recap `total_sales`.

### Tests for US3

- [X] T020 [P] [US3] In `tests/Feature/ReportPosPreorderSplitTest.php` — Cost & Profit: new keys `revenue_pos/preorder`, `cost_of_goods_pos/preorder`, `gross_profit_pos/preorder` present; `revenue = pos + preorder`, `cost_of_goods = pos + preorder`, `gross_profit = pos + preorder` (cents); `event_cost`/`net_profit` unchanged; event with no pre-orders → pre-order parts `"0.00"` and every old value identical; partially paid pre-order counts only the paid portion for revenue AND cost.
- [X] T021 [P] [US3] Same file — Seller Cost: rows have `sales_pos/preorder`, `modal_pos/preorder`, `gross_profit_pos/preorder` with each total (`total_sales`, `modal`, `gross_profit`) = POS + pre-order; a POS-only fixture equals the pre-change numbers; a pre-order-only seller appears; ordering by seller name; for every seller `total_sales` equals the same seller's Seller Recap `total_sales`; cancelled pre-orders / voided orders excluded; DEMO/LIVE isolation; cashier/inventory → `403`.
- [X] T022 [P] [US3] Same file — exports `profit` and `artist-profit` carry the new columns after the existing ones with values equal to the API.

### Implementation for US3

- [X] T023 [US3] `ReportController::profit()`: compute the POS-only revenue/cost from the existing `order_items` query result and set `revenue_pos`, `cost_of_goods_pos`; pre-order parts via `ReportSplit::remainder()` from the existing totals; `gross_profit_pos = revenue_pos − cost_pos` (cents), `gross_profit_preorder = remainder(gross_profit, gross_profit_pos)`. Keep all existing keys; no change to `event_cost`/`net_profit`.
- [X] T024 [US3] `ReportController::artistProfit()`: add ONE `GROUP BY artist_id` pre-order aggregation using `preorderRecognizedRevenueBase()` / `PREORDER_FRACTION_EXPR` (revenue = `line_total × fraction`, cost = `cost_price × qty × fraction`, explicit `data_mode` filter, non-cancelled pre-orders), merge with the POS rows by `artist_id` (include pre-order-only sellers, order by seller name), and emit per row `total_sales`, `modal`, `gross_profit` (now POS + pre-order) plus `sales_pos`, `sales_preorder`, `modal_pos`, `modal_preorder`, `gross_profit_pos`, `gross_profit_preorder` (pre-order parts as cent-exact remainders).
- [X] T025 [US3] `resources/js/views/ReportsView.vue` — Cost & Profit: give the Revenue / Cost of goods / Gross profit cards a muted sub-line `POS Rp … · Pre-order Rp …` (only when the response carries the new keys); event cost and net profit cards unchanged. Seller Cost table: Sales / Cost / Gross profit cells show the total with the same sub-line; extend `artistProfitTotals` so the footer Grand Total carries the parts; add a short note (reuse/extend `reports.artist_profit_note*`) that Seller Cost now includes the paid part of pre-orders.
- [X] T026 [P] [US3] Locale keys (en + id): `pos_label` ("POS"/"POS"), `preorder_label` ("Pre-order"/"Pre-order"), `split_line` pattern with named params for the sub-line, and the updated Seller Cost note text. Check for existing identical names first.
- [X] T027 [P] [US3] Extend `qa-tests/component/ReportsView.test.js`: Cost & Profit cards render the POS/Pre-order sub-line from a fixture and omit it for an old response; Seller Cost renders sub-lines and a Grand Total whose parts add up.
- [X] T028 [US3] Run `ReportPosPreorderSplitTest` fully + T002 regression set + Vitest for ReportsView; all green.

---

## Phase 6: Polish & Cross-cutting

- [X] T029 [P] Update `docs/openapi-pos-mvp.yaml` in the same change: new fields on `/reports/artist-settlements` rows, `/reports/profit`, `/reports/artist-profit` (including the behaviour change — totals now include pre-orders, POS parts equal the old figures), the extended Recap export headings (description near the existing `report=artist-settlements` export note ~line 3542), per `contracts/report-split.md`.
- [X] T030 [P] Update `CLAUDE.md`: in the "Seed data and DEMO/LIVE mode" raw-query note mention that `artistProfit()` now also reads pre-order items and filters `data_mode`; add a short "Seller Recap POS vs pre-order split (feature 033)" note (remainder rule, `salesBreakdownForEvent()` single source, Seller Cost now includes pre-orders, export detail sheet still POS-only). Keep the Active-feature block already written.
- [X] T031 [P] Add a README "bugs found during execution" / history line only if implementation reveals a real defect (e.g. T018); otherwise skip.
- [X] T032 Full verification: `APP_ENV=testing php artisan test` (host, whole suite) and `npm test`; both green. Report any pre-existing failure separately.
- [X] T033 Real-browser verification per `quickstart.md` on an ISOLATED server + test DB (seed: seller A POS + partially paid pre-order, B POS only, C pre-order only, D nothing, one cancelled pre-order, one voided sale): Recap columns + Grand Total, Cost & Profit sub-lines, Seller Cost incl. seller C, the three exports opened, cashier cannot reach the tabs, EN ↔ ID labels, console clean, narrow width scrolls. Save evidence screenshots (no customer data) under `specs/033-seller-recap-pos-preorder-split/evidence/`.
- [X] T034 Mark every completed task `[X]` here, clean up scratch seed scripts / `.playwright-mcp` copies, and prepare the commit (Indonesian message) — commit/PR only on the user's request.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T003 → T005 → T006; T004 parallel to T005) → user stories.
- **US1 (T007–T014)** needs Phase 2. **US2 (T015–T019)** needs US1's controller change (T010) because the export and the by-kind comparison reuse it. **US3 (T020–T028)** needs only Phase 2 (T003) and may run in parallel with US1/US2 on a second branch of work, but it touches the same two files (`ReportController.php`, `ReportsView.vue`), so run sequentially in one working tree: US1 → US2 → US3.
- Polish after all stories; T033 last because it needs the built SPA (`npm run build`) on the isolated server.

### Within a story

Tests first (fail) → controller/service → view → locales → run the story's tests.

### Parallel opportunities

- T004 with T005; T007/T008/T009 together (same file, written as separate test methods by one author — treat as one sitting); T012/T013 with T010/T011 after the contract is fixed; T015/T016 together; T020/T021/T022 together; T026/T027 with T025; T029/T030/T031 together.

## Implementation Strategy

- **MVP = Phase 1–3 (US1)**: the Recap columns alone answer the original request and are shippable.
- Then US2 (reconciliation + export — needed before sending the recap to sellers), then US3 (cost reports; contains the one intended behaviour change).
- Stop at each checkpoint and run that phase's tests; never leave the stored settlement totals changed (T006 / T014 / T019 / T028 all re-run the regression set).

## Notes

- Every task above is scoped to one file or one test group; if a task reveals a conflict with `docs/` or the unchanged-figures guarantee (SC-005), stop and surface it rather than adjusting the existing assertions.
