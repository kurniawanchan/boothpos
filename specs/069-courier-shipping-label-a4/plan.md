**Classification**: INTERNAL

# Implementation Plan: Courier Shipping Labels on A4 (single and bulk)

**Branch**: `085-courier-shipping-label-a4` (spec dir number ≠ branch number) | **Date**: 2026-11-15 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/069-courier-shipping-label-a4/spec.md`

## Summary

Add a **courier label** (courier, tracking number, recipient name/phone/address, sender, pre-order number), built from
the **saved shipment record**, downloadable as an A4 PDF: one label from the detail drawer's "Courier shipping" section,
or many from the Pre-orders list selection, 4 per A4 page (2×2, ≈ A6, shared cut lines). Existing shipping slips are
untouched (new, additional document).

Approach: one read-only endpoint `POST /preorders/shipping-labels` (cap = `preorders.bulk_max`, `ability:preorders,export`)
returns eligible labels (Mail Order + shipment, scoped model, number order), `skipped[]` with reasons, and
`store_identity` once. Single and bulk both call it — it is the only source of SAVED data, because the drawer's
`shipment` ref is edited in place (research D1). The browser renders labels with a new `utils/courierLabelSheet.js`
(fixed integer 2×2 grid, measured content, refuse instead of clip) and the A4 page rasterizer extracted from 067 into
`utils/a4PdfPages.js` (shared). No migration, nothing stored, no data changes.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), JavaScript (Vue 3.5, Vite)

**Primary Dependencies**: existing only — `html2canvas` 1.4 + `jspdf` 4.2 via `utils/pdfCapture.js` (lazy chunks)

**Storage**: MySQL 8, read-only for this feature (`preorders`, `shipments`, `settings`); no migration

**Testing**: PHPUnit feature tests (`tests/Feature/`, real MySQL `boothpos_test`), Vitest (`qa-tests/`), real-browser check

**Target Platform**: local install (native or Docker), Chrome-class browser on the store laptop

**Project Type**: web application (Laravel API + Vue SPA, same origin)

**Performance Goals**: 200 labels (50 pages) in one PDF ≤ 60 s (SC-002); endpoint query count constant in N

**Constraints**: no clipping of label text; integer px grid for crisp cut lines; one `store_identity` per request;
PDF libs stay dynamically imported

**Scale/Scope**: ≤ 200 pre-orders per request; 1 endpoint, 2 buttons, 2 new JS modules, 1 support class

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Check | Status |
|---|---|---|
| I. Single implementation | One endpoint + one renderer for single and bulk; A4 page loop extracted and shared with 067 (D7); eligibility logic in one support class, controller thin | PASS |
| II. Testing | Feature test (shape, skips, order, cap, ability, DEMO/LIVE, seller scope, query count, no PII beyond recipient); Vitest for layout/escape/too-tall and view buttons; real-browser + print check in quickstart | PASS (browser step is a task, must be done) |
| III. UX consistency | Buttons hidden without `preorders.export`; 422/403 via central handler; Indonesian copy + EN locale; token classes for buttons. PDF markup uses inline black/white like 067 (print document, not UI) | PASS |
| IV. Security | Read-only; server decides eligibility; scoped model (mode + seller) — no `exists:` rule (bypasses scopes); payload limited to recipient fields; all user text escaped | PASS |
| V. Performance | Eager-load `shipment`; store identity once; query-count test; per-page release of canvas; lazy chunks verified with `npm run build` | PASS |
| Docs discipline | OpenAPI updated in the same commit; CLAUDE.md feature note | PASS |

No violations → Complexity Tracking empty.

**Post-design re-check (after Phase 1)**: unchanged — PASS. One deliberate spec amendment (label order = pre-order
number, research D4) is recorded as a dated note in spec FR-004.

## Project Structure

### Documentation (this feature)

```text
specs/069-courier-shipping-label-a4/
├── plan.md
├── research.md          # D1–D11
├── data-model.md
├── quickstart.md
├── contracts/shipping-labels.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/PreorderController.php      # + shippingLabels() (thin)
├── Http/Requests/PreorderShippingLabelsRequest.php  # NEW: ids 1..bulk_max, integer, distinct
└── Support/PreorderShippingLabels.php               # NEW: scoped load, eligibility, skip reasons, ordering
routes/api.php                                       # + POST /preorders/shipping-labels (ability:preorders,export), before apiResource
docs/openapi-pos-mvp.yaml                            # + path + schemas
tests/Feature/PreorderShippingLabelsTest.php         # NEW

resources/js/
├── api/preorders.js                                 # + preorderShippingLabels(ids)
├── utils/a4PdfPages.js                              # NEW: renderA4Pages(pageHtmls, {capture}) — extracted from 067
├── utils/shippingSlipSheet.js                       # delegates its page loop to a4PdfPages (no behaviour change)
├── utils/courierLabelSheet.js                       # NEW: cell markup, layout, measure, LabelTooTallError, renderCourierLabels()
├── views/PreordersView.vue                          # + bulk button, + drawer button, download flows, dirty check
└── locales/{id,en}.json                             # + preorders.courier_label_* keys
qa-tests/unit/courierLabelSheet.test.js              # NEW
qa-tests/unit/shippingSlipSheet.test.js              # stays green after the extraction
qa-tests/component/PreordersView.test.js             # + button visibility and flows
```

**Structure Decision**: existing Laravel + Vue single-repo layout; no new directories.

## Implementation notes (for /speckit-tasks)

1. Backend first: request + support class + route + test + OpenAPI. Route must be registered before
   `apiResource('preorders')` (else `{preorder}` swallows `shipping-labels`), and carry `ability:preorders,export`
   so `MenuActionsRouteCoverageTest` stays green.
2. Extract `renderA4Pages` with 067's tests green BEFORE adding the label renderer.
3. `courierLabelSheet.js`: pure `labelPages(count)`; `buildCourierLabelCellHtml(label, store)` escaped; `measure`
   and `capture` injectable (jsdom has no layout/canvas); throw `LabelTooTallError` with the index.
4. View: dynamic `import('../utils/courierLabelSheet')`; skipped summary toast; empty result → no file + message;
   single flow compares the drawer's editable fields to the returned label for the dirty toast.
5. Locale keys in both files (`localeKeys.test.js` guards literals).
6. Real-browser verification + print check per quickstart; record the 200-label timing.

## Complexity Tracking

None.
