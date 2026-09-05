# Implementation Plan: Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

**Branch**: `012-seller-preorder-report-detail-export` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-seller-preorder-report-detail-export/spec.md`

## Summary

Four additive changes on top of `010-split-payment-preorder-reports` (this
branch is cut from `010`, not `main`). The pre-plan code survey found one
piece is bigger than it looks: `ReportController::preorders()`'s current
query aggregates at the `preorders` header level with no join to
`preorder_items` at all, so adding a per-seller breakdown (User Story 2)
isn't an extra `GROUP BY` column — it requires joining `preorder_items`
and re-deriving `total_order_value`/`total_collected`/`total_outstanding`
per artist using the same cash-collected-proration rule `010` already
established for `sales()`/`profit()`/`SettlementService` (research.md R1
there), applied one level deeper. The survey also found a real,
pre-existing gap while investigating User Story 1's target method:
`artistSettlementTransactions()` has no explicit `data_mode` filter at
all — flagged and fixed here as part of the same method touch, per this
codebase's own convention that hand-rolled joined queries must filter
`data_mode` explicitly since Laravel's Eloquent global scope doesn't
propagate through manual joins.

User Story 3's drilldown and User Story 4's exports both have direct,
low-risk precedents already in this exact controller/view to mirror:
`StockByArtistDetailModal.vue`'s on-demand (not eager) detail-fetch
pattern for the drilldown, and `exportArtistSettlements()`'s
`MultiSheetArrayExport`/`SheetArrayExport` pattern for the Pre-order
export (since it must carry both the existing summary rows and the new
per-seller breakdown as two sheets) — while Purchases and Stock-by-Seller
exports are trivial single-sheet `GenericArrayExport` additions, since
their row shapes are already flat.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Vue 3 (Vite) — unchanged.

**Primary Dependencies**: Backend: existing `ReportController`, `App\Exports\{GenericArrayExport,MultiSheetArrayExport,SheetArrayExport}`. Frontend: existing `ArtistTransactionsModal.vue`, `StockByArtistDetailModal.vue` (structural precedent for the new Pre-order drilldown modal), `ReportsView.vue`'s existing `doExport()`/`exportReport()` wiring (already generic, no change needed there).

**Storage**: MySQL 8 (existing `preorder_items`, `preorders`, `payments`, `order_items`, `orders` tables) — no schema changes; `preorder_items.artist_id` (confirmed FK, `2026_10_05_000001_create_preorders_tables.php:38`) is the join column the new per-seller breakdown needs.

**Testing**: `php artisan test` (`tests/Feature/`) for every backend query/export change; Vitest (`qa-tests/`) for `ArtistTransactionsModal.vue`, the new Pre-order drilldown modal, and `ReportsView.vue`'s new export buttons/columns; manual browser verification per Constitution Principle II for every touched report tab.

**Target Platform**: Same as existing app.

**Project Type**: Web application (Laravel API + Vue SPA in one repo) — no restructuring.

**Performance Goals**: No new goals beyond Constitution Principle V — the per-seller Pre-order breakdown must stay a single grouped SQL query (join `preorder_items`, aggregate, apply the proration in SQL or one pass over grouped results), not a per-preorder PHP loop; the merged order+preorder transaction-detail query for a seller must remain two grouped queries merged once in PHP (mirroring how `010`'s `sales()`/`profit()` already merge two aggregate sources), not N+1.

**Constraints**: Reuse the `010`-established revenue-recognition rule (cash actually collected via `payments`, never `preorders.paid_amount`, prorated by item value share, cancelled preorders contribute zero) for every new preorder-derived figure in this feature — no second, divergent calculation is introduced. The new preorder drilldown must open the *existing* preorder detail view (via the already-working `route.query.preorder_id` deep-link in `PreordersView.vue`), not a new one. Every new/changed query touching `preorder_items`, `orders`, or other `data_mode`-bearing tables via manual joins must explicitly filter `data_mode = ModeGate::current()`, since Eloquent's global scope does not propagate through joins in this codebase's established pattern.

**Scale/Scope**: 2 backend methods extended (`artistSettlementTransactions()`, `preorders()`), 1 backend method extended for export whitelist + 3 new export code paths, 2 frontend components extended (`ArtistTransactionsModal.vue`, `ReportsView.vue`) + 1 new frontend component (Pre-order drilldown modal), 3 new `<BaseButton>` export call sites.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. Preorder revenue recognition logic is not duplicated a third time — the plan reuses the exact proration rule and, where feasible, factors the shared "recognized revenue per preorder" computation so `sales()`, `profit()`, `SettlementService`, and this feature's two new/changed methods (`artistSettlementTransactions()`, `preorders()`) don't each reimplement it independently. Export additions reuse existing `GenericArrayExport`/`MultiSheetArrayExport`/`SheetArrayExport` classes, no new export mechanism introduced.
- **II. Testing Standards** — PASS, enforced by plan: every backend query/export change gets a `tests/Feature/` test against MySQL; every touched screen (Seller Recap detail, Pre-order report + drilldown, three new export buttons) gets a real-browser check before being marked done.
- **III. User Experience Consistency** — PASS. New UI reuses existing `@theme` tokens and existing component patterns (`StockByArtistDetailModal.vue`'s structure, `ArtistTransactionsModal.vue`'s card layout, the existing export-button pattern) rather than inventing new ones. UI copy Indonesian-first via existing i18n keys, new keys added to both locale files matching existing naming.
- **IV. Security** — PASS. No client-supplied money values are trusted — every new figure (seller transaction-detail amounts, per-seller preorder breakdown, drilldown totals) is server-computed from `payments`/`preorder_items`, never from client input. New exports/detail views are gated by the same existing role-based access (`canAccessMenu('reports')`) already enforced on every other report in this controller — no new exposure.
- **V. Performance & Optimization** — PASS with an explicit note: the per-seller Pre-order breakdown (User Story 2) and the merged seller transaction-detail (User Story 1) must each be single grouped-query (or two-queries-merged-once-in-PHP) implementations, never a loop over preorders/artists — called out explicitly in Phase 1 design and re-verified in code review, per this codebase's established convention for this exact class of report.

No violations requiring Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/012-seller-preorder-report-detail-export/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/             # Phase 1 output (API contract deltas)
└── tasks.md               # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/
│   └── ReportController.php
│       # + artistSettlementTransactions(): merge preorder-sourced transactions
│       #   into the existing OrderItem-based result; add missing data_mode filter
│       # + preorders(): add optional artist-dimension query path (join preorder_items,
│       #   proportional allocation) for the per-seller breakdown and the
│       #   individual-preorder drilldown
│       # + export(): whitelist 'purchases', 'stock-by-artist', 'preorder';
│       #   add exportPreorderReport() private method (MultiSheetArrayExport:
│       #   "Ringkasan" + "Per Seller" sheets)

resources/js/
├── components/report/
│   ├── ArtistTransactionsModal.vue     # + render preorder-sourced entries (type badge)
│   ├── StockByArtistDetailModal.vue    # unchanged — structural reference only
│   └── PreorderReportDetailModal.vue   # NEW — on-demand drilldown, mirrors StockByArtistDetailModal.vue
├── views/
│   └── ReportsView.vue
│       # + seller column/breakdown on Pre-order tab, row-click → PreorderReportDetailModal
│       # + 3 new doExport() BaseButton call sites (Purchases, Stock by Seller, Pre-order)
├── api/
│   └── reports.js        # preorderReport() extended to accept artist_id/drilldown params (exportReport() unchanged — already generic)
├── locales/{id,en}.json  # new keys: seller-breakdown labels, drilldown labels, export button labels for the 3 new tabs

tests/Feature/
├── ReportTest.php                     # extended: artistSettlementTransactions includes preorder entries + data_mode isolation; preorders() per-seller breakdown correctness (concrete proration example); export whitelist covers the 3 new report types
└── (no new test files expected — extends existing report test coverage)

qa-tests/
├── component/ArtistTransactionsModal.test.js   # extended: renders preorder-sourced entries distinctly
├── component/PreorderReportDetailModal.test.js  # NEW
└── component/ReportsView.test.js                # extended: 3 new export buttons present per tab (if this test file exists — confirm during implementation)
```

**Structure Decision**: Existing single Laravel+Vue repo, no new top-level directories — matches the established pattern from `010`/`011`.

## Complexity Tracking

*No Constitution Check violations — table not needed.*
