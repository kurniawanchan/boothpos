**Classification**: INTERNAL

# Tasks: Shipping Notes on Bulk Slips and Total Shipping Fee Statistic

**Input**: `specs/068-slip-notes-shipping-fee-stat/` (plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md)

**Scope**: no migration, no new route. US1 is frontend only; US2 touches one controller method, one view, two locale files and OpenAPI. Tests are included because the constitution (II) requires them.

**Conventions**: code comments, locale copy and commit messages in Indonesian (comments explain *why*; cite the research decision, e.g. `D3`). Run only this tree's frontend tests: `npx vitest run --exclude '.claude/**' <file>`. Backend tests inside the container ONLY with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`, one run at a time. Restore a mutated file with `command cp -f` (plain `cp` is interactive here).

**Stories are independent**: US1 and US2 share no file; either can ship alone.

## Format: `- [ ] T### [P?] [US?] Description with file path`

## Phase 1: Setup

- [X] T001 Baseline: run `npx vitest run --exclude '.claude/**'` and `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderTest`, and record the pass counts (and the known pre-existing Code128 failures) in a comment line at the top of this file so later regressions are attributable

<!-- Baseline 2026-11-12 (T001): vitest 149 files / 1336 tests — on a quiet machine 1333 passed, 1 failed (PreordersView 'duplicate one pre-order … shows the server error', pre-existing, load-sensitive), 2 skipped; a first run concurrent with PHP tests had 26 load-related failures (not a baseline). Backend PreorderTest filter: 55 passed. -->

**Phase 2 (Foundational)**: none. No schema, shared util or route is needed before the stories.

---

## Phase 3: User Story 1 — Shipping notes on every printed slip (P1) 🎯 MVP

**Goal**: the pre-order's own `notes` appear directly under the "Shipping Info" title on each bulk slip, escaped, wrapped, never clipped; slips without notes are unchanged (spec FR-001..FR-005, FR-012; research D1, D2).

**Independent test**: build the slip HTML for an invoice with and without `notes`; the first has the note between the title and the number, the second is byte-identical to today's output.

- [X] T002 [P] [US1] Add Vitest cases in `qa-tests/unit/shippingSlipSheet.test.js` (new `describe('buildShippingSlipCellHtml — notes (068)')`, written first and seen failing): (a) with `notes: 'Fragile, wrap twice'` the note appears AFTER `Shipping Info` and BEFORE the pre-order number (compare `indexOf`); (b) for `notes` undefined / `null` / `''` / `'   '` the output equals the output of the same invoice without the field (byte-identical → FR-002, no blank line); (c) `notes: '<b>x</b> & "y"'` is rendered escaped (`&lt;b&gt;`, `&amp;`) and the raw `<b>x</b>` string is absent; (d) the notes block carries `white-space:pre-wrap` and the `overflow-wrap:anywhere` wrap style; (e) a multi-line note keeps its `\n` characters (no `<br>`); (f) a ~600-character note still lays out: `layoutSlipRows([120, 480, 120], { cols: 3, pageHeight: 1122 })` gives one row of height 480 (row = tallest slip, FR-005)
- [X] T003 [P] [US1] Pin the existing payload in `tests/Feature/PreorderBulkInvoiceTest.php` (research D1: the slip relies on it): `POST /preorders/bulk-invoices` for a pre-order created with `notes` returns that exact text in `data.0.notes`, and `null` when the pre-order has none. This guards against a future `present()` change silently emptying the slip
- [X] T004 [US1] In `resources/js/utils/shippingSlipSheet.js::buildShippingSlipCellHtml`, render the notes block inside the header `div`, right after the "Shipping Info" title line and before the pre-order number, only when `String(invoice?.notes ?? '').trim() !== ''`: a `div` with `font-size:9px;line-height:12px;color:#444;white-space:pre-wrap;` plus the existing `wrap` style, text through `escapeHtml(invoice.notes)`; no label. Add a header comment citing research D2 (why escaped: the markup is injected through `innerHTML` for measuring and cloned by html2canvas) and that height is already measured from the real markup (067 D3), so no height code changes. Update the doc comment of the function (it lists title, number, From | To)
- [X] T005 [US1] Run `npx vitest run --exclude '.claude/**' qa-tests/unit/shippingSlipSheet.test.js` (all green, old 067 cases included). Mutation checks: (1) replace `escapeHtml(invoice.notes)` with the raw value → T002(c) goes red; (2) remove the blank check → T002(b) goes red. Restore the file after each with `command cp -f` and re-run green
- [ ] T006 [US1] Real-browser check (constitution II; quickstart §1) with pre-orders A (short note), B (no note), C (~600 characters with line breaks), D (`<b>x</b> & "y"`): download shipping slips; confirm A's note under the title, B unchanged, C fully printed in a taller row with shared borders intact, D shown literally; print at 100 %. Record the evidence in `quickstart.md`. NOTE: the Chrome DevTools / Playwright MCP servers failed to connect in the spec session; if still unavailable leave this task OPEN and say so in the final report (never mark it from the diff)

**Checkpoint**: US1 is deliverable on its own (frontend-only change plus one backend payload pin test).

---

## Phase 4: User Story 2 — Total shipping fee in the statistics bar (P2)

**Goal**: `GET /preorders/summary` returns `total_shipping_fee` over the same filtered, non-cancelled set as `grand_total`, Mail Order only; the Pre-orders statistics bar shows it (spec FR-007..FR-011; research D3, D4, D5).

**Independent test**: with courier fees 20.000 and 30.000 plus a pickup order, the statistic reads Rp 50.000; filtering to the 30.000 order reads Rp 30.000; a no-match filter reads Rp 0.

- [X] T007 [P] [US2] Create `tests/Feature/PreorderSummaryShippingFeeTest.php` (RefreshDatabase, owner via `actingAs(..., 'sanctum')`, own `makePreorder()` helper like `PreorderListFiltersTest` using `Preorder::create` with `fulfillment`, `shipping_cost`, `status`), written first and seen failing: (a) no filter → `total_shipping_fee` equals the hand sum of courier fees as a 2-decimal string; (b) a `status[]` filter and a `customer_id[]` filter each narrow the sum; (c) a no-match search → `"0.00"`; (d) a cancelled courier order is excluded while `transaction_count` still counts it; (e) a pickup order with a stored non-zero `shipping_cost` is excluded (FR-010); (f) a DEMO-mode pre-order is not counted in LIVE (copy the pattern of `PreorderTest::test_summary_excludes_demo_mode_preorder_when_running_in_live_mode`); (g) existing keys (`grand_total`, `total_outstanding`, `by_status`) are unchanged for the same data
- [X] T008 [P] [US2] Add the locale key `preorders.summary_shipping_fee` to `resources/js/locales/en.json` ("Total shipping fee") and `resources/js/locales/id.json` ("Total ongkos kirim"), in the `preorders` section next to `summary_outstanding` (line ≈ 898). Confirm the key is not already defined elsewhere in that section (a repeated key silently overrides — project trap) and run `qa-tests/unit/localeKeys.test.js`
- [X] T009 [US2] In `app/Http/Controllers/Api/PreorderController.php::summary()`, add `$totalShippingFee = (float) (clone $query)->where('status', '!=', 'cancelled')->where('fulfillment', 'courier')->sum('shipping_cost');` and return `'total_shipping_fee' => number_format($totalShippingFee, 2, '.', '')` next to `total_outstanding`. Comment in Indonesian citing research D3/D4: same `applyFilters()` set as `grand_total`; cancelled excluded for the same reason; `courier` only so pickup is Rp 0 whatever is stored. Do NOT touch `applyFilters()` (066: one filter chain). Make T007 green
- [X] T010 [US2] Resolve the unverified edge from research D3 with the real code: through the API create a courier pre-order with a shipping fee, then `PUT` it to `fulfillment=pickup` without sending `shipping_cost`, and read the stored `shipping_cost` and `total_amount`. Record the outcome (kept or cleared) in `research.md` D3 (replace "Unverified" with the fact). If the fee is kept, add one case to T007 pinning that the statistic excludes it and tell the requester Grand total still includes it (do not change fee storage — out of scope)
- [X] T011 [US2] Update `docs/openapi-pos-mvp.yaml` in the same commit as T009: add `total_shipping_fee` (with the Indonesian description from `contracts/api-deltas.md`) beside `total_outstanding` in the `/preorders/summary` 200 response (≈ line 3268) and mention it in that operation's description
- [X] T012 [US2] In `resources/js/views/PreordersView.vue`, add a block to the statistics bar between "Grand total" and "Total outstanding" copying the sibling blocks' classes (`flex min-w-[150px] flex-col gap-0.5 border-l border-line-6 pl-4`, 11.5 px label, 19 px extra-bold value): label `t('preorders.summary_shipping_fee')`, value `formatIDR(summary.total_shipping_fee ?? 0)`, `data-testid="summary-shipping-fee"`. No raw hex values, no new reactive state (it rides the existing `summary` ref refetched by `applyFilter()`)
- [X] T013 [P] [US2] In `qa-tests/component/PreordersView.test.js` (`describe('PreordersView — summary panel (013 US5)')`): add `total_shipping_fee: '50000.00'` to the mocked `SUMMARY`, and assert the label and `Rp 50.000` render; add a case where the mocked summary lacks the key and the block shows `Rp 0` (no `NaN`); keep the existing assertions green
- [X] T014 [US2] Run `php artisan test --filter=PreorderSummaryShippingFeeTest` and `--filter=PreorderTest` (container, with the `-e` overrides) and `npx vitest run --exclude '.claude/**' qa-tests/component/PreordersView.test.js qa-tests/unit/localeKeys.test.js`. Mutation checks: (1) drop `->where('fulfillment','courier')` → T007(e) red; (2) drop the `cancelled` condition → T007(d) red. Restore with `command cp -f` and re-run green
- [ ] T015 [US2] Real-browser check (quickstart §2–§4): add the shipping fees by hand and compare with the statistic with no filter, a status filter, a customer filter and a no-match search; select rows (figure must not change); Indonesian label; DEMO↔LIVE switch; console clean. Record the evidence in `quickstart.md`. Leave OPEN if no browser tooling is available and say so

**Checkpoint**: US2 is deliverable on its own.

---

## Phase 5: Polish & cross-cutting

- [X] T016 Run the full suites one at a time: `npx vitest run --exclude '.claude/**'` and `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`; compare with the T001 baseline (the 9 Code128 failures on a clean base are known and pre-existing — anything else is ours). Report real numbers, including failures
- [X] T017 Constitution V check: run `npm run build` and confirm `shippingSlipSheet` (and html2canvas/jspdf) are still separate lazy chunks, not folded into the page chunk
- [X] T018 Add a dated section "Slip notes and total shipping fee (feature 068, 2026-11-12)" to `CLAUDE.md` (Indonesian-style comments rule applies to code, the file itself is English): the notes come from `preorders.notes` (D1, default pending confirmation), escaped + `pre-wrap`, height measured not fixed; `total_shipping_fee` definition (non-cancelled, courier only, same filter set); detail-drawer slip and Excel export untouched; any verification still open (T006/T015)
- [ ] T019 Confirm FR-012 with the requester (pre-order notes vs shipment notes). If they choose shipment notes or both, update `spec.md`, `research.md` D1, `contracts/api-deltas.md` (the bulk-invoices endpoint would then change: add `shipment` to its `with()` and the payload) and add the matching tasks before implementing

---

## Dependencies & execution order

- T001 first. US1 (T002–T006) and US2 (T007–T015) are independent and may run in parallel; Polish (T016–T019) after the stories it covers. T019 is best answered BEFORE T004.
- Within a story: tests first (T002/T003, T007) and seen failing → implementation (T004, T009) → docs/UI (T011, T012) → runs and mutation checks (T005, T014) → browser (T006, T015).
- US2 internal order: T009 → T010 (needs the endpoint and data) → T011 (same commit as T009). T008 before T012/T013 (label key).

## Parallel opportunities

- T002 ‖ T003 (different files); T007 ‖ T008 ‖ T013 (different files); whole US1 ‖ whole US2.

## Implementation strategy

- **MVP = US1** (frontend-only, prevents a missed handling note at the booth). Then US2.
- Commit per story: one commit for US1 (T002–T005), one for US2 (T007–T014 including OpenAPI). Do not push or open a PR unless asked.

## Counts

- Total 19 tasks: Setup 1, US1 5 (T002–T006), US2 9 (T007–T015), Polish 4 (T016–T019).
- Parallel-marked: T002, T003, T007, T008, T013.

## Final status (2026-11-12)

- **Done**: T001–T005, T007–T014, T016–T018. Full suites: Vitest 149 files / 1346 passed / 2 skipped (all green); backend 1981 passed / 9 failed — the 9 are the known pre-existing `Code128Test` reference-library failures (`picqer` is `require-dev`). `npm run build` OK; `shippingSlipSheet`, html2canvas and jspdf remain separate lazy chunks.
- **OPEN**: T006 and T015 (real-browser verification of the PDF/print path and the statistic in the running SPA — no browser tooling connected; partial headless-Chrome render of the slip markup is recorded in quickstart.md), and T019 (requester to confirm FR-012: pre-order notes vs shipment notes).

## Addendum 2026-11-12 — "add shipping fee to the table" (FR-013)

- [X] T020 [US2] Add a non-sortable "Shipping fee" column after Fulfillment in `resources/js/views/PreordersView.vue` (cell shows `formatIDR(row.shipping_cost)` only when `fulfillment === 'courier'`, otherwise "—") and the `preorders.col_shipping_fee` key in `resources/js/locales/en.json` ("Shipping fee") / `id.json` ("Ongkos kirim"). No API change: list rows already carry `shipping_cost`
- [X] T021 [US2] Component test in `qa-tests/component/PreordersView.test.js` (courier 25.000 → "Rp 25.000", pickup with a stored 45.000 → "—", courier 0 → "Rp 0", header present); mutation check (gate removed → red) done and file restored; PreordersView/PreorderBulkBar/PreordersBulkShippingSlips/localeKeys: 104 passed
- [ ] T022 [US2] Look at the column and its sort header in the running SPA (width, wrapping beside the other columns, phone width) — not done, no browser tooling
- [X] T023 [US2] Make the column sortable (request: "add sort order to shipping fee column"): `shipping_cost` branch in `PreorderController::applySort()` — non-courier rows last in both directions, their stale fee ignored (second key is NULL for them), `preorders.id` tie-breaker kept (052); `sortable: true` in `PreordersView.vue`; OpenAPI `sort_by` enums (list + neighbors) and description updated; neighbors/prev-next inherit it through the same `applySort()`
- [X] T024 [US2] Tests: `PreorderSummaryShippingFeeTest::test_list_sorts_by_shipping_fee_with_pickup_rows_always_last` (asc/desc, tie, stale-fee pickup), `PreorderNeighborsTest::test_neighbors_follow_every_sort_option` now includes `shipping_cost`, a PreordersView header-click test (`sort_by=shipping_cost`, asc then desc). Found and fixed a real bug on the first run (pickup rows were ordered among themselves by their stale fee); mutation check (partition key removed → red) done. 98 backend / 94 frontend tests in the touched files green
- [X] T025 [US2] Change the Shipping fee cell value to `{courier}` / `{shipping cost}` / `{notes}` (request): additive list field `courier_name` (= `Preorder::courier_name`, the invoice's courier — not `shipments.courier_name`) in `PreorderController::index()` rows + OpenAPI `PreorderSummary` (`shipping_cost`, `courier_name`, `notes`); cell in `PreordersView.vue` (Mail Order only; courier and notes skipped when blank; notes `line-clamp-2` + `BaseTooltip` with the full text). Tests: backend row-fields test, component test rewritten (pickup with stored courier/notes leaks nothing; mutation check → red, restored). Side effect fixed: the flag-popup test now looks inside the dialog because the notes text also appears in the table. 83 backend / 105 frontend tests in the touched files green

