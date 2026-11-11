# Implementation Plan: Bulk Shipping Slips on A4 Cut Sheets

**Branch**: `097-bulk-shipping-slips-a4-grid` (spec dir number ≠ branch number) | **Date**: 2026-11-10 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/067-bulk-shipping-slips-a4-grid/spec.md`

## Summary

Bulk "download shipping slips" on the Pre-orders list stops zipping one PDF per slip and instead produces ONE A4 PDF: slips in a 3-column grid, each with a 1 px solid black border, touching each other (one shared line between neighbours), rows of equal height, no slip split across pages. **Frontend only — no migration, no route, no API change.** The slip data still comes from the existing `POST /preorders/bulk-invoices`. A new util, `utils/shippingSlipSheet.js`, owns three things: the cell markup (replacing the old fixed-width `buildShippingSlipHtml`), a PURE layout function (rows → pages), and the renderer (measure → lay out → rasterize each page through `utils/pdfCapture.js` → one jsPDF document). `PreordersView.doBulkShippingSlips()` shrinks to "fetch, call renderer, save".

## Technical Context

**Language/Version**: JavaScript (Vue 3 SPA, Vite); no PHP change

**Primary Dependencies**: existing `html2canvas` (via `utils/pdfCapture.js`) and `jspdf`, both already dynamically imported; `jszip` is no longer used by THIS action (still used by other bulk downloads)

**Storage**: N/A — nothing stored

**Testing**: Vitest in `qa-tests/` (pure layout + markup + view wiring with `pdfCapture`/`jspdf` mocked; jsdom has no canvas and no layout, so measurement is injectable); real-browser check for the visual result (Constitution II) — Playwright/Chrome DevTools MCP were unavailable in the last sessions, so this stays a manual step in quickstart.md

**Target Platform**: the store laptop's browser (Chrome-class), printing to A4 at 100 %

**Project Type**: web application (single repo: Laravel API + Vue SPA) — this feature touches the SPA only

**Performance Goals**: 50 slips < 30 s (SC-005); expect ≈ 3 pages ≈ a few seconds, since a page is ONE rasterization, not one per slip (the old flow did N)

**Constraints**: integer-pixel borders so lines stay crisp at 2× raster; no clipped text (a shipping address must never be cut); selection cap 200 unchanged

**Scale/Scope**: ≤ 200 slips ≈ ≤ 10–25 pages

## Constitution Check

*GATE: passes before research and after design.*

| Principle | Verdict |
|-----------|---------|
| I. Clean code / one implementation | PASS — ONE slip-cell builder replaces `buildShippingSlipHtml` (its only caller was the bulk action); layout is one pure function; no copy of invoice layout. The detail drawer's single-slip template is untouched (FR-011). |
| II. Testing | PASS with open item — Vitest for layout/markup/wiring; browser verification of print geometry is a listed manual task (cannot be proven in jsdom). |
| III. UX consistency | PASS — slip text is the existing labels; new hint copy goes through `locales/en.json` + `id.json` (guarded by `localeKeys.test.js`); button stays gated by `auth.canDo('preorders','export')`. Tokens: the slip is a PRINT document rendered to raster, like every other document in `pdfCapture`, so its black `#000` border/ink follows the documents' existing inline-style convention (`invoiceDocument.js`), not the screen token rule. |
| IV. Security | PASS — no new data exposed: the same payload the old action fetched; permission unchanged (server-side `ability:preorders,export` already on the route); HTML built with the existing `escapeHtml`. |
| V. Performance | PASS — `html2canvas`/`jspdf` stay dynamic imports; N rasterizations → P (pages). |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/067-bulk-shipping-slips-a4-grid/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/ui-contract.md
└── tasks.md             # /speckit-tasks — not created here
```

### Source Code (repository root)

```text
resources/js/
├── utils/shippingSlipSheet.js      # NEW: cell html, layoutSlipRows(), renderShippingSlipSheet()
├── utils/invoiceDocument.js        # buildShippingSlipHtml removed (moved/reshaped into the new util); escapeHtml kept/exported
├── views/PreordersView.vue         # doBulkShippingSlips() → uses the util, saves ONE pdf, shows print hint
└── locales/{en,id}.json            # + preorders.shipping_sheet_hint, shipping_sheet_filename (if needed)

qa-tests/unit/shippingSlipSheet.test.js     # NEW: layout + markup
qa-tests/component/PreordersBulkShippingSlips.test.js  # NEW: wiring (mocked capture/jspdf)
```

**Structure Decision**: SPA-only; one new util next to `pdfCapture.js` / `invoiceDocument.js`, mirroring how feature 049's catalog page layout lives in utils + a renderer. No backend, no OpenAPI, no PRD change (PRD scope notes concern cuts; this is a layout change to an existing export).

## Design (details in research.md)

1. **Geometry (CSS px at 96 dpi; A4 = 794 × 1123).** Page element is exactly 794 × 1123, white, zero padding. Three integer columns: 265 + 264 + 264 = 793 px (col 0 carries the page's left border). Every slip box is `box-sizing: border-box` with `border-right` + `border-bottom` 1 px solid `#000`; first column adds `border-left`, first row of a page adds `border-top`. So two neighbours share exactly one 1 px line; no margin, no gap. Inner text padding (≈ 6 px) is INSIDE the border (spec FR-005 allows it).
2. **Row height = tallest slip in that row** (so horizontal cut lines are straight and no text is clipped), measured in a hidden off-screen container using the real cell markup at the real column width. Never a fixed height with `overflow: hidden`, because an address may not be cut.
3. **Pagination = pure function** `layoutSlipRows(heights, { cols: 3, pageHeight: 1123 })`: group into rows of 3, greedy-pack rows into pages (capacity = 1123 − 1 top border), no row split. A row taller than a page → typed error → toast (FR-013), not silent truncation.
4. **Render**: one `captureElementCanvas(pageEl)` per page (scale 2) → `jsPDF({unit:'pt', format:'a4'})`, `addImage` full-bleed (595.28 × 841.89 pt), `addPage` for the rest, `save('surat-jalan.pdf')`. Pages are rasterized sequentially and disposed as we go to cap memory.
5. **Partial last row/page**: only slips that exist get borders (FR-009), by construction (cells are only created for real slips).
6. **Hint (FR-010)**: after a successful download a toast tells the user to print at actual size (100 %).
