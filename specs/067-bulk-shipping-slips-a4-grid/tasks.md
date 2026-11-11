# Tasks: Bulk Shipping Slips on A4 Cut Sheets

**Input**: `specs/067-bulk-shipping-slips-a4-grid/` (plan.md, spec.md, research.md, data-model.md, contracts/ui-contract.md, quickstart.md)

**Scope**: frontend only. No backend, migration, route or OpenAPI task. Tests are included because the constitution (II) requires them for frontend changes.

**Conventions**: comments, locale copy and commit messages in Indonesian (comments explain *why*; cite the research decision, e.g. `D2`). Run only this tree's tests: `npx vitest run --exclude '.claude/**' <file>`.

## Format: `- [ ] T### [P?] [US?] Description with file path`

## Phase 1: Setup

- [X] T001 Baseline: run `npx vitest run --exclude '.claude/**'` and record the pass count in a comment line at the top of this file (so later regressions are attributable)
  <!-- Baseline 2026-11-10: 147 files, 1308 passed, 2 skipped -->

---

## Phase 2: Foundational (blocks both stories)

- [X] T002 [P] Export `escapeHtml` from `resources/js/utils/invoiceDocument.js` (currently module-private) so the new util reuses the one implementation
- [X] T003 [P] Add locale keys `preorders.shipping_sheet_hint` (print at 100 % / actual size, mention outer-edge risk) and `preorders.shipping_sheet_too_tall` (a slip is too tall for one page) to `resources/js/locales/en.json` and `resources/js/locales/id.json` (same section as `bulk_download_failed`)
- [X] T004 Create `resources/js/utils/shippingSlipSheet.js` with the constants (`PAGE_W=794`, `PAGE_H=1123`, `COL_WIDTHS=[265,264,264]`, `COLS=3`) and the pure `layoutSlipRows(heights, { cols, pageHeight })` + `SlipTooTallError` per contracts/ui-contract.md: rows of ≤ 3 slips in order; row outer height supplied by caller; greedy-pack rows into pages with capacity `pageHeight - 1` (top border); a row never spans pages; a row taller than a page throws. Header comment cites research D2–D5.
- [X] T005 [P] Unit tests for `layoutSlipRows` in `qa-tests/unit/shippingSlipSheet.test.js`: 7 slips → rows 3/3/1 on one page; slip indexes across pages = 0..N-1 once each, in order; overflow starts a new page and no row is split; exactly-fitting row stays; row taller than a page throws `SlipTooTallError`; empty input → no pages; page sum never exceeds `PAGE_H`

**Checkpoint**: layout is proven before any DOM/PDF work.

---

## Phase 3: User Story 1 — One A4 sheet, bordered, cut along the lines (P1) 🎯 MVP

**Goal**: bulk "download shipping slips" yields ONE A4 PDF with slips in a 3-column bordered grid, shared 1 px lines, no gaps.

**Independent test**: select 7 pre-orders → one `surat-jalan.pdf`; page 1 shows 3+3+1 slips, 7 boxes, one line between neighbours (quickstart steps 1–3, 6).

### Tests (write first, expect red)

- [X] T006 [P] [US1] In `qa-tests/unit/shippingSlipSheet.test.js` add markup tests for `buildShippingSlipCellHtml(invoice)`: contains number, From and To blocks with the existing labels; every user-typed value is HTML-escaped (`<script>` in a name/address stays inert); no outer border/margin on the cell itself (the page grid draws borders); safe with missing `customer`/`store_identity`
- [X] T007 [P] [US1] In the same file add tests for `buildSheetPageHtml(page, cellHtmls)`: page is exactly 794 × 1123 px, white, zero padding/margin; only real slips produce a cell (partial last row draws no empty boxes — FR-009); each cell `box-sizing:border-box` with right+bottom 1 px solid `#000`; first column also left border, first row of the page also top border; column widths 265/264/264; every cell in a row has the same height
- [X] T008 [P] [US1] Create `qa-tests/component/PreordersBulkShippingSlips.test.js` (follow `PreorderBulkBar.test.js` setup): with `api/preorders.bulkPreorderInvoices`, `utils/pdfCapture` and `jspdf` mocked, selecting 3 rows and clicking the shipping-slips button calls the renderer once, saves ONE file named `surat-jalan.pdf`, does NOT build a ZIP, and shows the print-hint toast; a failing fetch shows `bulk_download_failed`

### Implementation

- [X] T009 [US1] In `resources/js/utils/shippingSlipSheet.js` implement `buildShippingSlipCellHtml(invoice)`: same content as today's slip (title "Shipping Info", number, From | To) reshaped for a cell: inner padding ≈ 6 px INSIDE the border, font sizes legible at 70 mm width, long text wraps (`overflow-wrap:anywhere`), NO fixed height, NO `overflow:hidden`/`line-clamp` (research D3, 049 clipping trap). Uses the exported `escapeHtml`.
- [X] T010 [US1] Implement `buildSheetPageHtml(page, cellHtmls)` per T007 (integer grid; border rules of plan §Design 1; row heights from the layout)
- [X] T011 [US1] Implement `measureSlipHeights(cellHtmls)`: mount all cells off-screen (`position:fixed; left:-9999px`, NOT `display:none`) at the real column width (264 px, border-box), read each `offsetHeight`, add the 1 px bottom border, remove the container; row height = max of its slips (done by the caller via layout input). Must be injectable (`measure` option) because jsdom has no layout.
- [X] T012 [US1] Implement `renderShippingSlipSheet(invoices, { measure, capture })` → `Blob`: build cells → measure → `layoutSlipRows` → for each page mount `buildSheetPageHtml` off-screen, `captureElementCanvas(pageEl)` (from `utils/pdfCapture.js`), `addImage` full-bleed on an `a4`/`pt` jsPDF (`595.28 × 841.89`), `addPage` between pages, remove the page node before the next one (cap memory). `html2canvas`/`jspdf` stay dynamic imports.
- [X] T013 [US1] Rewrite `doBulkShippingSlips()` in `resources/js/views/PreordersView.vue` (~l.948): fetch via `bulkPreorderInvoices`, call `renderShippingSlipSheet`, trigger download of ONE `surat-jalan.pdf`, then `toast.success(t('preorders.shipping_sheet_hint'))`; keep the `bulkDownloading` flag and the `bulk_download_failed` fallback; remove the per-slip ZIP loop and the now-unused `buildShippingSlipHtml` import
- [X] T014 [US1] Remove `buildShippingSlipHtml` from `resources/js/utils/invoiceDocument.js` (only caller was the bulk action; confirm with a search) and update that file's header comment so it no longer claims to build the bulk slip; leave the detail drawer's own template (`shippingSlipEl`, `downloadShippingSlip`) untouched (FR-011)
- [X] T015 [US1] Run T005–T008 + `qa-tests/unit/localeKeys.test.js` → all green; fix until green

**Checkpoint**: MVP — one A4 sheet works for a selection that fits one page.

---

## Phase 4: User Story 2 — More slips continue onto extra pages (P2)

**Goal**: any selection (up to the existing cap of 200) flows onto additional A4 pages, no slip split, no empty boxes, clear error when something cannot fit.

**Independent test**: select 40 → multiple pages, total boxes = 40 (quickstart step 4); very long address → row grows, not clipped (step 5).

- [X] T016 [P] [US2] In `qa-tests/component/PreordersBulkShippingSlips.test.js` add cases: 40 invoices with an injected `measure` → PDF has the page count `layoutSlipRows` predicts (`addPage` called pages−1 times) and total drawn slips = 40; one tall slip makes only ITS row taller (neighbours in the row get the same height); 200 invoices completes and keeps order; a `SlipTooTallError` shows `shipping_sheet_too_tall` and downloads nothing (FR-013)
- [X] T017 [US2] In `renderShippingSlipSheet` / `doBulkShippingSlips()` map `SlipTooTallError` to the localized `preorders.shipping_sheet_too_tall` toast (no partial file); any other error keeps `bulk_download_failed`
- [X] T018 [US2] Make sure pages are processed sequentially and each page node and canvas reference is released before the next (check with the 200-slip test using spies on `removeChild`); adjust T012 if not
- [X] T019 [US2] Run T016 and the whole file set → green

**Checkpoint**: both stories pass automated tests.

---

## Phase 5: Polish & Cross-Cutting

- [X] T020 Run the full suite `npx vitest run --exclude '.claude/**'`; compare with the T001 baseline (no new failures)
  <!-- Result 2026-11-10: 149 files, 1334 passed, 2 skipped (baseline 1308 + 26 new), 0 failures -->
- [X] T021 Production build (`npm run build`) and confirm `jspdf`/`html2canvas` are still separate chunks (Constitution V) — note the result here
  <!-- Result 2026-11-10: build OK; shippingSlipSheet 4.3 kB own chunk, jspdf 390 kB and html2canvas 202 kB remain separate chunks -->
- [ ] T022 Real-browser verification per `quickstart.md` steps 1–6 on DEMO data with the console open (constitution II). If browser tooling is unavailable, leave this OPEN and say so — do not tick it from jsdom results.
- [ ] T023 Print check: print a 2-page sample at 100 % and measure column widths with a ruler (SC-002/SC-003); record whether the printer clipped the outer left/top line (research D6) and, if so, tell the owner a safety margin is a one-constant change
- [X] T024 Add a dated note to `CLAUDE.md` (a short "Bulk shipping slips sheet (feature 067, 2026-11-10)" section: ONE PDF, integer grid 265+264+264, shared borders, row height from content, pure `layoutSlipRows`, too-tall = error not truncation, single-slip unchanged, bulk recipient from `customer` vs single from `shipment` is an inherited difference); no PRD/OpenAPI change needed

---

## Dependencies & Order

- T001 → Foundational (T002–T005) → US1 (T006–T015) → US2 (T016–T019) → Polish (T020–T024).
- Inside Foundational T002, T003, T005 are parallel; T004 precedes T005's run.
- Inside US1: T006–T008 parallel (different concerns/files); T009 → T010 → T011 → T012 → T013 → T014 → T015.
- US2 depends on US1's renderer (extends it); it has no other story dependency.

## Parallel example

```text
T002 escapeHtml export  ∥  T003 locale keys  ∥  T005 layout tests (after T004)
T006 cell tests  ∥  T007 page tests  ∥  T008 view wiring test
```

## Implementation strategy

MVP = Phase 1–3 (single-page sheet, one file, hint toast). Then US2 pagination guarantees. Polish last; T022/T023 need a real browser and printer and may stay open.
