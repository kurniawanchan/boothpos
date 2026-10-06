---

description: "Task list for feature 040 — Seller Recap shows POS transactions only"
---

# Tasks: Seller Recap Shows POS Transactions Only

**Input**: Design documents from `/specs/040-recap-pos-transactions-only/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/recap-pos-only.md, quickstart.md

**Tests**: INCLUDED (Constitution II). Tests that pin the feature-033 recap split are **rewritten to the new rule**, not deleted blindly: every behaviour they protected that still applies (data-mode isolation, void handling, zero-sale sellers listed, authorization) stays covered.

**Organization**: By user story. **Foundational (Phase 2) = the POS-only aggregation in `SettlementService`**, because the stored settlement feeds the recap rows (US1), Payable/Outstanding (US3) and the export (US4). The detail list (US2) is independent of it.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1–US4

## Conventions to honour

- Comments, locale copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` (the `localeKeys` test must stay green). Comments explain *why* and cite 040 / the 033 behaviour being reversed.
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`); never inside the `app` container without the `-e` overrides; never touch the dev DB `boothpos`.
- Hand-rolled `DB::table` queries keep the explicit `data_mode` filter (`ModeGate::current()`).
- Remove dead code instead of leaving it: the pre-order aggregation inside the settlement path and the four `pos_*`/`preorder_*` response fields are **deleted**. `ReportSplit` and `preorderRecognizedRevenueBase()` stay (other reports still use them).
- `docs/openapi-pos-mvp.yaml` moves in the same commit as the response change; tokens only in CSS classes (no hex).

---

## Phase 1: Setup

- [X] T001 Baseline on `040-recap-pos-transactions-only`: run `APP_ENV=testing php artisan test --filter='ReportPosPreorderSplit|ReportTest|ReportDataModeIsolation'` and `npx vitest run qa-tests/component/ReportsView.test.js qa-tests/component/ArtistTransactionsModal.test.js qa-tests/component/DashboardView.test.js`; note pass counts (expect all green) and list which test names assert pre-order content in the recap, the detail list or the export (`grep -n "preorder\|pre-order" …`) so T004/T008/T012 know what to rewrite.

---

## Phase 2: Foundational — POS-only settlement

**⚠️ Blocks US1, US3, US4.**

- [X] T002 Rewrite the settlement expectations first (they must FAIL before T003) in `tests/Feature/ReportPosPreorderSplitTest.php` and `tests/Feature/ReportTest.php::test_artist_settlement_recalculation_includes_preorder_recognized_revenue_with_same_proration_and_cancellation_rules`: for an event with a seller that has POS sales + a partially paid pre-order + a cancelled paid pre-order, the stored/returned `total_sales` and `total_units` equal the seller's POS figures only; a pre-order-only seller ends with `0.00` / `0` (including when a settlement row already existed from an earlier pre-order-inclusive calculation — it is reset); a voided POS order is still excluded; `payable_amount = total_sales − deduction`; `paid_amount` of an existing row is untouched by recalculation. Rename the test file/class to `SellerRecapPosOnlyTest` if the 033 name would mislead (keep the git history by renaming with `git mv`).
- [X] T003 In `app/Services/SettlementService.php`: replace `salesBreakdownForEvent()` with `posSalesForEvent(Event $event): Collection` returning `[artist_id => ['sales' => float, 'units' => float]]` from the completed-POS `order_items` aggregation only (keep the explicit `data_mode` filter); delete the `payments`/`preorder_items` aggregation and the fraction expression; make `recalculateForEvent()` use it (`total_sales = sales`, `total_units = (int) units`, `payable = total_sales − deduction`, status derivation unchanged, the "reset every row first" step unchanged); update the class docblock (Indonesian) to say the settlement is POS-only since 040 and why (stored payable must agree with the Sales shown; reverses 033) and drop the now-wrong "033" docblock on the old method. The method may return nothing if no caller needs it (check `ReportController`). Run T002.

**Checkpoint**: settlement totals are POS-only.

---

## Phase 3: User Story 1 — The recap counts only POS sales (P1) 🎯 MVP

**Goal**: recap rows, Grand Total and columns are POS-only.

**Independent Test**: mixed-seller event → Unit/Sales equal POS; no pre-order columns; Grand Total = sum.

- [X] T004 [P] [US1] Update `tests/Feature/ReportTest.php` (artist-settlement tests, e.g. `test_artist_settlement_matches_sum_of_actual_orders`, `test_artists_without_any_sales_still_appear_with_zeroes`, `test_inactive_artist_with_sales_is_still_listed`) and `tests/Feature/ReportDataModeIsolationTest.php::test_artist_settlement_recalculation_is_not_contaminated_by_demo_activity` as needed, and add to the rewritten recap test class: the response rows have **no** `pos_units`/`preorder_units`/`pos_sales`/`preorder_sales` keys (`assertArrayNotHasKey`); a pre-order-only seller is still listed with zeros; the seller of the screenshot shape (1 POS unit Rp 30.000 + a large pre-order) returns exactly `total_units 1`, `total_sales 30000.00`; a DEMO-mode pre-order or POS sale never leaks into the LIVE recap.
- [X] T005 [P] [US1] Update `qa-tests/component/ReportsView.test.js` (and `ReportsPreorderSubtotal.test.js` only if it shares a recap fixture): the recap table renders the headers Seller, Unit, Sales, Payable, Paid, Outstanding, Status (+ actions) and **no** "POS unit"/"Pre-order unit"/"POS sales"/"Pre-order sales"; the Grand Total row sums Unit/Sales/Payable/Paid/Outstanding of the visible rows; the seller filter narrows rows and Grand Total; fixture rows no longer carry `pos_*`/`preorder_*`. Add a `DashboardView.test.js` assertion only if it currently depends on the removed fields (it reads `total_sales`; expect no change).
- [X] T006 [US1] In `app/Http/Controllers/Api/ReportController.php::artistSettlements()`: stop calling for the 033 breakdown (use the stored `total_sales`/`total_units` only), remove the `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` fields and the now-unused `$parts/$posUnits/$posSales` locals and `ReportSplit` usage in this method; update the method comments (the 033 remarks are now wrong). Keep every other field and the "every active seller listed" logic exactly as is. Run T004.
- [X] T007 [US1] In `resources/js/views/ReportsView.vue` recap `DataTable`: remove the `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` column definitions, their `#cell-*` slots and the matching footer `<td>`s; limit `settlementTotals` to `['total_sales','payable_amount','paid_amount','outstanding']` + `['total_units']`; update the stale 033 comment. Remove the locale keys `reports.col_pos_unit`, `col_preorder_unit`, `col_pos_sales`, `col_preorder_sales` from `resources/js/locales/en.json` and `id.json` **only after** `grep -rn` shows no other user (Cost & Profit / Seller Cost use their own keys). Run T005 and `qa-tests/unit/localeKeys.test.js`.

**Checkpoint**: US1 complete — the recap table is POS-only.

---

## Phase 4: User Story 2 — "Transaction detail" lists POS only (P1)

**Goal**: the seller's detail list contains POS transactions that add up to the row's Sales.

**Independent Test**: seller with both kinds → only POS listed; sums to the row; pre-order-only seller → empty state.

- [X] T008 [P] [US2] Update `tests/Feature/ReportTest.php`: rewrite `test_artist_settlement_transactions_includes_both_a_regular_sale_and_a_partially_paid_preorder` into "…lists only the regular sale and never the pre-order" (no `preorder-*` key, no `source`), `test_artist_settlement_transactions_amounts_sum_to_the_seller_recap_total_sales` (sum equals the POS-only `total_sales`), `test_a_cancelled_preorder_with_a_prior_payment_does_not_appear_in_artist_settlement_transactions` (still absent; keep), `test_artist_settlement_transactions_never_mixes_demo_and_live_orders_or_preorders` (orders of the other mode still excluded; drop the pre-order half), and add: a pre-order-only seller gets `transactions: []`. Expect FAIL before T010.
- [X] T009 [P] [US2] Update `qa-tests/component/ArtistTransactionsModal.test.js`: no Sale/Pre-order badge and no "Type" column; rows show number, date, items and the amount; empty state when `transactions` is empty. Expect FAIL before T011.
- [X] T010 [US2] In `ReportController::artistSettlementTransactions()`: delete the pre-order query, `$preorderTransactions`, the concat/merge, and the `source` key; keep the order query, per-seller item filtering, `data_mode` filter and the sort (order by `orders.created_at` desc); simplify the response mapping; rewrite the method docblock (Indonesian) to say that since 040 it lists POS only so it sums to the recap, and why `source` is gone. Run T008.
- [X] T011 [US2] In `resources/js/components/report/ArtistTransactionsModal.vue`: remove the type badge/column and its `tx.source` branch; remove `reports.transaction_type_order`, `transaction_type_preorder` and `col_transaction_type` locale keys from both locale files **only if** no other user remains (note: `transaction_type_*` currently appear twice in each locale file — remove every duplicate that becomes unused and leave any key still referenced). Run T009 and the `localeKeys` test.

**Checkpoint**: US2 complete.

---

## Phase 5: User Story 3 — Payable, Paid, Outstanding, Record payment (P2)

**Goal**: settlement amounts follow the POS-only Sales; never a negative Outstanding; recorded payments preserved.

**Independent Test**: payable = POS sales; over-paid seller shows Outstanding 0; Record payment only when outstanding > 0.

- [X] T012 [P] [US3] Add backend tests (in the rewritten recap test class and/or `ReportTest.php`): Payable equals POS `total_sales`; `outstanding = payable − paid`; a settlement whose recorded `paid_amount` exceeds the new POS-only payable returns `outstanding "0.00"`, `paid_amount` unchanged, status `paid`; `POST /reports/artist-settlements/{id}/payment` (`test_settlement_payment_still_works_for_the_id_returned_by_the_report` stays green) still records against the new payable and updates status `unpaid→partial→paid`; a pre-order-only seller returns `id: null` or a zero payable row with no outstanding; closing the event (`EventController`) stores the same POS-only numbers the recap shows (call the close endpoint, then read `artist_settlements`).
- [X] T013 [US3] In `ReportController::artistSettlements()` set `'outstanding' => number_format(max(0, $payable - $paid), 2, '.', '')` with a short Indonesian comment (why: paid may exceed a payable that no longer includes pre-orders; never rewrite `paid_amount`). Confirm in `ReportsView.vue` that the "Record payment" link still requires `row.id !== null && outstanding > 0` (no code change expected) and that `openSettle` prefills the clamped outstanding. Run T012.

**Checkpoint**: US3 complete.

---

## Phase 6: User Story 4 — The recap export matches the screen (P3)

**Goal**: "Rekap" sheet without pre-order columns, equal to the screen; "Detail Transaksi" agrees.

**Independent Test**: export a mixed event; compare with the API response.

- [X] T014 [P] [US4] Update `tests/Feature/ReportTest.php::test_artist_settlements_export_produces_a_real_two_sheet_workbook` (and any export assertion in the rewritten class): "Rekap" headings are exactly `id, artist_id, artist_name, total_sales, total_units, deduction, payable_amount, paid_amount, outstanding, status`; each data row equals the matching API row; "Detail Transaksi" total equals the sum of "Rekap" `total_sales` (open the workbook with the same reader the existing test uses). Expect FAIL before T015.
- [X] T015 [US4] In `ReportController::exportArtistSettlements()`: remove the `pos_units`/`preorder_units` string-cast loop and its 033 comment, and drop the four columns from `$summaryHeadings`; keep the "Detail Transaksi" query unchanged (add a one-line comment that it was always POS-only and now agrees with the summary). Run T014.

**Checkpoint**: all four stories complete.

---

## Phase 7: Polish & cross-cutting

- [X] T016 [P] Update `docs/openapi-pos-mvp.yaml` in the same commit: `GET /reports/artist-settlements` (remove the four fields, describe POS-only totals and `outstanding` clamp — around the text mentioning `pos_units`/`preorder_units`/`pos_sales`/`preorder_sales`, ~line 3528), the transactions endpoint (POS only, no `source`), the export description (~line 3659 columns list) and the remark near ~line 6808 that says `pos_units + preorder_units` equals the total; validate with `python3 -c "import yaml;yaml.safe_load(open('docs/openapi-pos-mvp.yaml'))"`. Do not touch `docs/UI-mockups/**`.
- [X] T017 [P] Update `CLAUDE.md`: amend the "Reports: POS vs pre-order split (feature 033)" section with a dated 2026-10-06 note that the Seller Recap is POS-only since 040 (the `SettlementService::salesBreakdownForEvent`/remainder rules no longer apply to the recap; Cost & Profit and Seller Cost keep the 033 split) and **remove the sentence that Seller Cost's total equals the Recap's**; add a short "Seller Recap is POS-only (feature 040)" section (stored settlement is POS-only, outstanding clamped at 0, pre-order revenue yields no payable, Dashboard per-seller panel follows) next to it; keep the Active-feature block for 040.
- [X] T018 [P] Add a README entry "Bug yang ditemukan saat eksekusi fitur 040-recap-pos-transactions-only": record real findings (the Dashboard per-seller panel shares the endpoint and changes with it; the negative-Outstanding case; anything surfaced by the browser check) — only what was actually observed.
- [X] T019 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; all green.
- [X] T020 Real-browser verification per `quickstart.md` on the isolated server (:8091, test DB, `APP_ENV=testing php artisan serve`, `migrate:fresh --seed`, `license:dev-activate`, `npm run build`; seed through tinker with `OrderService`/`PreorderService`/factories: sellers with POS only, POS + pre-order, pre-order only, nothing; a voided POS sale; a seller whose recorded payment exceeds the POS-only payable — create that payment with `SettlementService::recordPayment` before the change is applied, or set `paid_amount` directly in the TEST DB). Check recap columns/totals, Transaction detail, Record payment flow, over-paid seller Outstanding 0, event close, export vs screen (fetch `GET /reports/artist-settlements/export?event_id=` with an owner token and read the sheets), unchanged Pre-order / Cost & Profit / Seller Cost tabs, Dashboard per-seller panel, EN ↔ ID, console clean. Screenshots to `specs/040-recap-pos-transactions-only/evidence/` (no customer data).
- [X] T021 Cleanup: stop the :8091 server, remove `.playwright-mcp`, downloaded xlsx files and any temporary files in the repo (scratch seed scripts stay outside it); tick every task `[X]`.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 → T003) blocks US1, US3 and US4.
- US2 (Phase 4) depends on nothing in Phase 2 and may run in parallel with it.
- Within a story: tests first (must fail), then backend, then frontend.
- US1 (T006, T007) before US3 (T013 edits the same method) and US4 (T015 reads the controller's `artistSettlements()` rows).
- T016–T018 can run in parallel after the code tasks; T019 → T020 → T021 last.

### Parallel opportunities

- T004 ∥ T005; T008 ∥ T009; T012 ∥ T014 (different test files); T016 ∥ T017 ∥ T018.
- US2 (T008–T011) ∥ Phase 2 (T002–T003).

## Implementation Strategy

- **MVP = Phase 2 + US1 + US2** (the recap and its detail agree and are POS-only). Then US3 (clamp + settlement checks) and US4 (export), then Polish.
- Commit order when asked: (1) docs commit with `specs/040-…` + `.specify/feature.json`; (2) feature commit with code, tests, OpenAPI, CLAUDE.md, README. No push unless asked.
