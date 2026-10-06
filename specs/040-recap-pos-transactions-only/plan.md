# Implementation Plan: Seller Recap Shows POS Transactions Only

**Branch**: `040-recap-pos-transactions-only` | **Date**: 2026-10-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/040-recap-pos-transactions-only/spec.md`

## Summary

Make the Seller Recap (`GET /reports/artist-settlements` + its screen, detail list and Excel export) count **POS sales only**. Feature 033 made the recap a blend of POS and the paid portion of pre-orders and then split every figure into POS / pre-order parts; this feature removes the pre-order half entirely instead of hiding it in the UI, because the stored settlement (`artist_settlements.total_sales/total_units/payable_amount`, which "Record payment" and event close read) is built from the same aggregation. The approach: `SettlementService` aggregates POS only (the pre-order query and the 033 breakdown go away), the controller drops the four `pos_*`/`preorder_*` fields and clamps Outstanding at 0, `artistSettlementTransactions()` loses its pre-order half, the export loses four columns, and the SPA recap table/footer/detail badge lose the pre-order parts. No migration; no new endpoint.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API), Vue 3 SPA (Vite, Tailwind v4)

**Primary Dependencies**: existing only — `maatwebsite/excel` (export), Pinia, vue-i18n; no new package

**Storage**: MySQL 8, existing `artist_settlements` table (columns reused, none added). Stored totals are recomputed on every read and on event close, so no data migration.

**Testing**: PHPUnit on host (`APP_ENV=testing php artisan test`, DB `boothpos_test`); Vitest + Testing Library under `qa-tests/`

**Target Platform**: single-store local install (native or Docker)

**Project Type**: web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: unchanged; the recap gets one fewer aggregate query (the pre-order `GROUP BY` is removed)

**Constraints**: money as 2-dp strings; 403 for roles without the `reports` menu; DEMO/LIVE filter stays explicit on the hand-rolled queries

**Scale/Scope**: a handful of sellers per event; ~10 files touched

## Constitution Check

*GATE: passes — no violations, Complexity Tracking not needed.*

- **I. Code quality / single implementation** — PASS. The POS aggregation stays in `SettlementService` only; the recap, the stored settlement and event close all read it. The now-dead pre-order aggregation and 033 split fields are **deleted**, not left behind a flag (no speculative extension points).
- **II. Testing** — PASS (planned). Backend tests under `tests/Feature/` on MySQL; the tests that pin the 033 recap split (`ReportPosPreorderSplitTest`, parts of `ReportTest`, `ReportDataModeIsolationTest`) are rewritten to the new rule; frontend tests under `qa-tests/`; real-browser check of the recap, detail dialog, Record payment and export with the console checked.
- **III. UX consistency** — PASS. Only tokens/components already in use; Indonesian + English locale keys; role gate unchanged; the Record-payment link is already hidden (not disabled) when nothing is outstanding.
- **IV. Security** — PASS. Server remains the source of every figure; `canAccessMenu('reports')` gates unchanged; recorded payments untouched; no audit-log-bearing mutation is added (recording a payment is unchanged).
- **V. Performance** — PASS. One fewer aggregate query; the recap still recomputes on read deliberately (documented in `SettlementService`).
- **Documentation discipline** — OpenAPI updated in the same commit; CLAUDE.md "033" section amended with a dated note (it states recap = POS + pre-order and that Seller Cost equals the recap), not rewritten silently.

## Project Structure

### Documentation (this feature)

```text
specs/040-recap-pos-transactions-only/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── recap-pos-only.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
app/Services/SettlementService.php                  # POS-only aggregation; remove pre-order aggregation + 033 breakdown
app/Http/Controllers/Api/ReportController.php      # artistSettlements(): drop pos_/preorder_ fields, clamp outstanding;
                                                    # artistSettlementTransactions(): POS only; exportArtistSettlements(): 4 fewer columns
resources/js/views/ReportsView.vue                  # recap columns, cells, footer, totals
resources/js/components/report/ArtistTransactionsModal.vue   # drop the pre-order/sale badge + type column
resources/js/locales/{en,id}.json                   # remove keys that become unused
docs/openapi-pos-mvp.yaml                           # recap response, transactions response, export columns
CLAUDE.md                                           # amend 033 section; active feature block
tests/Feature/{ReportPosPreorderSplitTest,ReportTest,ReportDataModeIsolationTest}.php
qa-tests/component/{ReportsView,ArtistTransactionsModal,DashboardView}.test.js
```

**Structure Decision**: existing single Laravel + Vue web-app layout; changes stay in the files that already own the recap. No new files in `app/` or `resources/js/`.

## Complexity Tracking

None.
