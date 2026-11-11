# UI Contract: bulk shipping slips

No HTTP change (`POST /preorders/bulk-invoices` unchanged; `docs/openapi-pos-mvp.yaml` does not move).

**Trigger**: the existing "download shipping slips" button on the Pre-orders list (`preorders.export` action, hidden otherwise).

**Result**: one file `surat-jalan.pdf`, A4 portrait, N slips in a 3-column bordered grid (see spec FR-001…FR-009).

**Module API** (`resources/js/utils/shippingSlipSheet.js`):
- `buildShippingSlipCellHtml(invoice)` → string (escaped; no outer border — the page grid draws borders).
- `layoutSlipRows(heights, opts?)` → `{ pages: [{ rows: [{ slips: number[], height: number }] }] }`; throws `SlipTooTallError` when one row cannot fit a page.
- `renderShippingSlipSheet(invoices, { measure?, capture? })` → `Blob` (the PDF); `measure`/`capture` are injection points for tests.

**Messages**: success toast carries the print-at-100 % hint (en/id); failure shows `err.message` or `preorders.bulk_download_failed`.
