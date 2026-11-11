# Quickstart / verification: bulk shipping slips sheet

Automated: `npx vitest run --exclude '.claude/**' qa-tests/unit/shippingSlipSheet.test.js qa-tests/component/PreordersBulkShippingSlips.test.js`, then the whole `npm test`.

Manual (real browser, DEMO data, console open):
1. Pre-orders → tick 7 Mail Order pre-orders → download shipping slips → one `surat-jalan.pdf`, page 1 = 3+3+1 slips, only 7 boxes drawn.
2. Zoom 400 %: a join between two slips is ONE black line; no gap, no double line.
3. Print at 100 % ("actual size"); measure with a ruler: column widths ≈ 70 mm, equal; cut along lines → one whole slip per piece.
4. Select 40 → multiple pages, none split, total boxes = 40.
5. Give one pre-order a very long address → its row grows, text is not cut, neighbours in that row get the same height.
6. Select 1 → 1 page, slip top-left. Console: no errors.
