**Classification**: INTERNAL

# Implementation Plan: Shipping Notes on Bulk Slips and Total Shipping Fee Statistic

**Branch**: `085-slip-notes-shipping-fee-stat` (spec dir number ≠ branch number) | **Date**: 2026-11-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/068-slip-notes-shipping-fee-stat/spec.md`

## Summary

Two small, independent additions to the Pre-orders screen, **no migration and no new route**:

1. **Slip notes (US1, frontend only).** `buildShippingSlipCellHtml()` in `resources/js/utils/shippingSlipSheet.js` renders the pre-order's `notes` as plain text directly under the "Shipping Info" title when it is non-blank. The bulk-slip payload (`POST /preorders/bulk-invoices` → `invoicePayload()` → `present()`) already carries `notes`, so no backend change is needed. Row heights are already measured from the real markup (067 D3), so a long note grows its row instead of being cut.
2. **Total shipping fee statistic (US2, backend + frontend).** `PreorderController::summary()` returns one new aggregate, `total_shipping_fee`, computed over the SAME filtered query as `grand_total` (non-cancelled, `fulfillment = courier`). `PreordersView.vue` shows it in the existing statistics bar; two locale keys (en, id); OpenAPI updated in the same commit.

The one open spec question (FR-012: which "notes") is resolved by default to the **pre-order's own `notes`** (research D1), flagged for the requester to confirm.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel API), JavaScript (Vue 3 SPA, Vite)

**Primary Dependencies**: existing only; no new package. Slip rendering keeps using the lazily imported `html2canvas` / `jspdf` through `utils/pdfCapture.js`.

**Storage**: MySQL 8, **no schema change** (`preorders.notes` and `preorders.shipping_cost` already exist)

**Testing**: PHPUnit feature tests (`tests/Feature/`, real MySQL, `boothpos_test`); Vitest (`qa-tests/`)

**Target Platform**: shopkeeper's laptop, localhost, browser (Chrome) + print at 100 %

**Project Type**: web application (Laravel API + Vue SPA on one machine)

**Performance Goals**: statistic must not add a noticeable wait to the existing summary refresh; slip rendering time unchanged for a typical batch (≤ 200 slips, one rasterization per page)

**Constraints**: slip markup must escape user text (it is injected as HTML for measuring and rasterizing); cut-sheet grid rules from 067 unchanged; money returned as a 2-decimal string

**Scale/Scope**: 1 util function, 1 controller method, 1 view block, 2 locale keys per language, 1 OpenAPI block, tests

## Constitution Check

*GATE: passes before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|-----------|------------|
| I. Code Quality (single implementation, no speculation) | PASS. The slip keeps ONE cell builder; the stat is added to the one `summary()` using the same `applyFilters()` chain (no second filter chain). No new abstraction. |
| II. Testing (verify, don't assume) | PASS, with an open gate: backend + Vitest tests are planned (tasks phase); the screen change **must** also be exercised in a real browser and the PDF/printed output checked (quickstart). Not yet done. |
| III. UX consistency | PASS. Design tokens only (the stat block copies the existing blocks' classes); labels in both languages (`localeKeys.test.js` guards them); no new control, so no role-hiding issue. |
| IV. Security | PASS. Server computes the sum from stored `shipping_cost`; nothing client-supplied. Free text `notes` is escaped with the existing `escapeHtml` before it reaches the slip HTML (XSS/markup injection into the measuring and capture DOM). No new endpoint; existing `preorders` permissions and seller/mode scopes apply unchanged. |
| V. Performance | PASS. One extra aggregate on an already-filtered query; no N+1; no new frontend dependency; `shippingSlipSheet` stays a dynamic import. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` moves with the endpoint change (contracts/api-deltas.md). Dated CLAUDE.md note added when implemented. |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/068-slip-notes-shipping-fee-stat/
├── plan.md              # This file
├── spec.md
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   └── api-deltas.md    # Phase 1
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/PreorderController.php   # summary(): + total_shipping_fee
resources/js/utils/shippingSlipSheet.js           # buildShippingSlipCellHtml(): + notes block
resources/js/views/PreordersView.vue              # statistics bar: + "Total shipping fee"
resources/js/locales/en.json                      # preorders.summary_shipping_fee
resources/js/locales/id.json                      # preorders.summary_shipping_fee
docs/openapi-pos-mvp.yaml                         # /preorders/summary response

tests/Feature/PreorderListFiltersTest.php         # (or a new PreorderSummaryShippingFeeTest.php) summary cases
qa-tests/unit/shippingSlipSheet.test.js           # notes cases
qa-tests/component/PreordersView.test.js          # statistic is shown
```

**Structure Decision**: existing web-application layout; every change lands in a file that already owns that concern. No new files in `app/` or `resources/js/`.

## Phase notes

- **Phase 0** → `research.md` (D1 notes source, D2 notes placement/format, D3 fee definition, D4 where to compute, D5 UI placement).
- **Phase 1** → `data-model.md` (no stored change; derived figure defined), `contracts/api-deltas.md`, `quickstart.md`.
- **Not in scope** (spec Assumptions): detail-drawer slip, Excel export, reports, dashboard.
