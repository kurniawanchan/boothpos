# Implementation Plan: Separate POS Sales from Pre-order Sales in the Reports

**Branch**: `033-seller-recap-pos-preorder-split` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/033-seller-recap-pos-preorder-split/spec.md`

## Summary

Three owner/admin reports blend (or should blend) POS sales with the paid portion of pre-orders. Today:

- **Seller Recap** (`GET /reports/artist-settlements`): `total_sales`/`total_units` come from `artist_settlements`, which `SettlementService::recalculateForEvent()` fills as *POS orders + recognised pre-orders*; the two parts are computed separately in that method and then summed and thrown away.
- **Cost & Profit** (`GET /reports/profit`): revenue/cost are `POS + recognised pre-order`, summed in the controller.
- **Seller Cost** (`GET /reports/artist-profit`): **POS only** — it never included pre-orders (so it disagrees with the Recap). Decision (requester): bring pre-orders in, with a combined total.

Plan: **no new storage**. Expose the two parts that already exist at calculation time:

1. `SettlementService` gets one method, `salesBreakdownForEvent(Event)`, returning per seller `{pos_sales, pos_units, preorder_sales, preorder_units}` from the two aggregation queries that `recalculateForEvent()` already runs; `recalculateForEvent()` is refactored to build the stored totals from that breakdown (so total = POS + pre-order by construction, single implementation).
2. `artistSettlements()` adds `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` per row — the pre-order parts are **derived as the remainder of the stored total** (integer units; cents for money) so POS + pre-order equals the shown total *exactly*, even though the stored unit total is a rounded integer (research Decision 2).
3. `profit()` adds `revenue_pos/preorder`, `cost_of_goods_pos/preorder`, `gross_profit_pos/preorder`; `artistProfit()` gains the pre-order aggregation (sellers with only pre-orders now appear) and returns POS part, pre-order part and total for sales/cost/gross profit. Cent-exact remainders everywhere.
4. Frontend (`ReportsView.vue`): Seller Recap gets four columns (POS unit, Pre-order unit, POS sales, Pre-order sales) beside the existing totals and a matching Grand Total row; Cost & Profit cards and Seller Cost cells get a compact "POS … · Pre-order …" sub-line; exports carry the same fields; locales (en/id); OpenAPI + `CLAUDE.md`.

Unchanged by design: Payable, Paid, Outstanding, Status, deductions, "Record payment", event cost, net profit, who can open the reports (owner/admin).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (service, report controller, exports); Vue 3 SPA (Vite)

**Primary Dependencies**: none added (`maatwebsite/excel` already used)

**Storage**: MySQL 8 — **no schema change** (no new columns; `artist_settlements` totals unchanged)

**Testing**: PHPUnit on the host (`.env.testing`/`boothpos_test`): new `ReportPosPreorderSplitTest` (+ the existing `ReportTest` suite as the regression net); Vitest for `ReportsView`; real-browser check on an isolated server + test DB (needs seeded POS + partially paid pre-orders)

**Target Platform**: Local Laravel app + browser SPA

**Project Type**: Web application (backend + frontend)

**Performance Goals**: Reports stay one pass per aggregation: the breakdown reuses the two existing queries (no extra per-seller queries); Seller Cost adds ONE pre-order aggregation query

**Constraints**: POS + pre-order MUST equal the shown total exactly (units and cents); existing figures (Recap, Cost & Profit) unchanged; DEMO/LIVE filtering explicit on every hand-rolled `DB::table` query (`data_mode`); voided orders / cancelled pre-orders excluded exactly as today; reports remain owner/admin only

**Scale/Scope**: 1 service method (+ refactor), 3 controller methods, 1 export heading list, 1 view (3 tabs), locales, OpenAPI, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / single implementation | **Pass.** The POS-vs-pre-order aggregation lives once in `SettlementService::salesBreakdownForEvent()` and feeds BOTH the stored settlement totals and the report columns (no parallel formulas that can drift). Cent/unit remainder logic is one small helper reused by the three reports. The pre-order fraction expression stays the existing `PREORDER_FRACTION_EXPR`. |
| II. Testing | **Pass (planned).** Reconciliation tests (POS + pre-order = total) over: only-POS, only-pre-order, none, partially paid pre-order, cancelled/voided, rounding (.5 units, sub-cent money), multiple sellers, DEMO/LIVE; drill-down sums by kind equal the columns; exports carry the fields; existing `ReportTest` stays green (regression net for unchanged figures); frontend table/Grand Total tests; real-browser check. |
| III. UX consistency | **Pass.** Same `DataTable`, money formatting and labels as the existing columns; strings in both locales; width handled by compact headers and the table's existing horizontal scroll. |
| IV. Security | **Pass.** No new endpoint or data class; the three reports keep their owner/admin gate (403 for others, including through the export route that re-calls them); no PII added. |
| V. Performance | **Pass.** No N+1: aggregation stays set-based; Seller Cost adds one `GROUP BY` query. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` moves with the new response fields (PRD §9.5); `CLAUDE.md` "Seed data and DEMO/LIVE mode" raw-query note and the settlement/report notes updated (Seller Cost now includes pre-orders). |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/033-seller-recap-pos-preorder-split/
├── plan.md
├── research.md                      # decisions + alternatives
├── data-model.md                    # no schema change; derivation rules
├── quickstart.md                    # automated + isolated real-browser verification
├── contracts/report-split.md        # response fields of the three reports + export columns
├── checklists/requirements.md
└── tasks.md                         # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Services/SettlementService.php                 # salesBreakdownForEvent(); recalculateForEvent() built on it
app/Http/Controllers/Api/ReportController.php      # artistSettlements(), profit(), artistProfit(), exportArtistSettlements() headings
app/Support/ReportSplit.php                        # NEW (small): cent/unit remainder helpers shared by the 3 reports
resources/js/views/ReportsView.vue                 # Recap columns + Grand Total; Cost & Profit sub-lines; Seller Cost sub-lines
resources/js/locales/{en,id}.json
docs/openapi-pos-mvp.yaml, CLAUDE.md
tests/Feature/ReportPosPreorderSplitTest.php (new); tests/Feature/ReportTest.php (regression, unchanged)
qa-tests/component/ReportsView.test.js
```

**Structure Decision**: Extend the existing settlement/report modules; no migration, no new endpoint.

## Complexity Tracking

No constitution violations to justify.
