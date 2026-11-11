# Research: Bulk Shipping Slips on A4 Cut Sheets

Spec: [spec.md](spec.md) · Findings come from reading `PreordersView.vue::doBulkShippingSlips`, `utils/invoiceDocument.js`, `utils/pdfCapture.js`.

## What exists today (fact)

- `doBulkShippingSlips()` (`PreordersView.vue` ~l.948): `bulkPreorderInvoices(ids,'invoice')` → for EACH invoice mounts `buildShippingSlipHtml(invoice)` (420 px wide, 24 px padding, From | To), rasterizes it, makes ONE jsPDF sized to the slip, zips all into `surat-jalan.zip`. No tests reference it (grep over `qa-tests/` found none).
- The slip has NO images (store logo was removed in 024), so image tainting is not a concern, but we still go through `captureElementCanvas` (project rule: all document downloads use `pdfCapture.js`).
- Bulk slip recipient comes from `invoice.customer`; the single-slip template in the detail drawer reads the `shipment` record. This difference is OLD and out of scope (spec FR-008: content unchanged). Flagged for the owner only.

## Decisions

**D1 — One PDF, rasterized per PAGE, not per slip.** Decision: build each A4 page as one DOM node and rasterize it once. Rationale: matches `pdfCapture` rule; N slips → P pages rasterizations (faster than today's N); lines are drawn by the browser's CSS borders, so alignment is by construction. Rejected: jsPDF vector text/lines (drawing rules + text wrapping by hand, font/Unicode risk, a second renderer to maintain); one canvas per slip placed by coordinates (N rasterizations, sub-pixel placement risk, double lines at joins).

**D2 — Integer-pixel grid, shared borders.** A4 at 96 dpi is 794 × 1123 px; 794/3 is not an integer. Use 265 + 264 + 264 = 793 px (the 1 px slack is on the right edge, invisible). Each slip owns only its right + bottom border (first column also left, first row of a page also top), so a join is ONE 1 px line, not two borders or a margin hack. At raster scale 2 every border is exactly 2 device px → crisp (the catalog work, feature 049, showed non-integer module widths blur in html2canvas).

**D3 — Row height from content, not fixed.** Decision: row height = tallest slip in the row, measured off-screen with the real markup and real column width. Rationale: a shipping address must never be clipped (a fixed height + `overflow:hidden` or line-clamp would silently drop text — and 049 found `line-clamp`/`truncate` glyph-clipping traps in html2canvas anyway); straight horizontal cut lines are preserved because every cell in a row has the row height (FR-006). Rejected: global fixed height from the tallest slip overall (one long address wastes paper for all); truncating text (loses data).

**D4 — Pagination is a pure function.** `layoutSlipRows(heights)` takes measured outer heights and returns `pages: [[{row heights, slip indexes}]]`. Pure so it is unit-testable in jsdom (which has no layout). The measurer is injected into the renderer for the same reason.

**D5 — Row taller than a page.** Practically impossible (a page is ≈ 297 mm) but must not truncate: throw a typed error, show the toast (FR-013). No automatic font shrinking.

**D6 — Page margins 0, print hint instead.** The user asked for no margin/padding. Most printers have a 3–5 mm unprintable edge; the outer left/top lines could be clipped on some printers. We do not add a margin (it would shift the grid and contradict the request); we show a hint to print at 100 % and note the edge risk in quickstart. If the owner later wants a safety margin, it is one constant.

**D7 — File name / ZIP.** Output becomes `surat-jalan.pdf` (single file). The ZIP path for slips is removed; `jszip` remains used by invoice bulk download, so no dependency change.

## Open items (not blockers)

- Real-browser print/ruler check (SC-002/SC-003) is manual — see quickstart.md.
- Hardware printer edge clipping is outside software control (D6).
