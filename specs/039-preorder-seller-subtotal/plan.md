# Implementation Plan: Subtotal per Seller in the Pre-order Report

**Branch**: `039-preorder-seller-subtotal` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/039-preorder-seller-subtotal/spec.md`

## Summary

A small backend + frontend change with ONE implementation of the arithmetic:

1. **Backend (single source)** — new `App\Support\PreorderSellerSubtotals::fromRows(array $rows): array` sums, per seller, the four figures of the by-seller rows **in cents** (reusing `ReportSplit::cents()/money()`), returning one subtotal per seller in row order. `ReportController::preordersByArtist()` adds `subtotals` to its response (`{rows, subtotals}`; `rows` unchanged), and `exportPreorderReport()` interleaves the same subtotals into the "Per Seller" sheet. A second `orderBy('preorder_items.artist_id')` guarantees one seller's rows are contiguous even if two sellers share a name.
2. **Frontend** — `ReportsView.vue` keeps the server `subtotals`, picks those of the sellers shown by the existing seller filter, and builds the table rows by inserting each seller's subtotal row after that seller's last data row (`DataTable` rows with a synthetic `_subtotal` row; slots render the label, hide status/completeness/Detail; `rowClass` styles it between a data row and the Grand Total). The Grand Total stays as the client-side sum of the data rows; `sumRows` becomes cent-exact so grand total = Σ subtotals exactly.

No migration, no new endpoint, response change is additive.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (Pinia, vue-i18n en/id, Tailwind v4 tokens)

**Primary Dependencies**: none new (`maatwebsite/excel` already used by the export)

**Storage**: MySQL 8 — **no migration**; subtotals are derived, never stored

**Testing**: PHPUnit on the host (`APP_ENV=testing`, `boothpos_test`; `PreorderReportTest`, new `PreorderSellerSubtotalsTest`); Vitest + Testing Library; real-browser check on an isolated server

**Target Platform**: Local Laravel app + SPA

**Project Type**: Web application

**Performance Goals**: O(rows) in memory on already-aggregated rows (tens of rows); no extra SQL query

**Constraints**: owner/admin only (the report's existing gate, unchanged); DEMO/LIVE unchanged (rows already scoped); money exact to the cent; subtotal = Σ displayed row values (outstanding is the sum of per-row clamped values, NOT value − collected); `docs/openapi-pos-mvp.yaml` updated in the same change; Subtotal label in en + id

**Scale/Scope**: 1 support class, 2 controller touch points, `ReportsView.vue`, locales, docs, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1.*

| Principle | Assessment |
|---|---|
| I. Clean code / single source | One PHP implementation of the sums feeds both the API and the export; the screen only places what the server returns, so screen, export and Grand Total cannot disagree. No second formula in JS. PASS |
| II. Testing | Backend unit/feature tests (cents exactness, multiple sellers, clamped outstanding, empty, order stability, export sheet contents); component test for interleaving, seller filter, grand total invariant; real-browser verification. PASS |
| III. UX consistency | Tokens only; same table pattern as the existing Grand Total row; label translated; no control shown to a role that cannot use the report. PASS |
| IV. Security | No new access path: same `reports` gate; no client-supplied amounts. PASS |
| V. Performance | No extra queries; derived from rows already loaded. PASS |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/039-preorder-seller-subtotal/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/preorder-seller-subtotals.md
├── checklists/requirements.md
└── tasks.md        # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Support/PreorderSellerSubtotals.php        # NEW – cents-exact per-seller sums
└── Http/Controllers/Api/ReportController.php  # preordersByArtist(): + subtotals, stable order; exportPreorderReport(): interleave
resources/js/
├── views/ReportsView.vue                      # interleave subtotal rows, cent-exact sumRows
└── locales/{en,id}.json                       # reports.subtotal
docs/openapi-pos-mvp.yaml                      # response `subtotals`
tests/Feature/PreorderSellerSubtotalsTest.php  # NEW
qa-tests/component/ReportsPreorderSubtotal.test.js  # NEW
```

**Structure Decision**: existing monolith layout; one new support class beside `ReportSplit` (033) which it reuses.
