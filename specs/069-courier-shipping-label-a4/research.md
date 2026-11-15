**Classification**: INTERNAL

# Research: Courier Shipping Labels on A4 (feature 069)

Grounded in the code as of `develop` @ 24be7923 (read 2026-11-15). No open NEEDS CLARIFICATION remain.

## What exists (facts read from the code)

- `resources/js/views/PreordersView.vue` (detail drawer, ~line 2163): once a shipment exists, the courier fields are
  `v-model` bound **directly to the `shipment` ref** (`shipment.courier_name`, `shipment.tracking_number`, …). The
  existing single slip (`shippingSlipEl`, ~line 2191, 420 px wide, From/To only) renders that same ref, so it prints
  **unsaved edits**. There is no separate "last saved" copy.
- `POST /preorders/bulk-invoices` (`PreorderController::bulkInvoices`, `ability:preorders,export`) loads
  `items, payments.*, customer, event` — **not `shipment`** — has **no size cap** (`min:1` only) and returns rows in
  `whereIn(...)->get()` order (id order). `present()` already emits a `shipment` block when the relation is loaded
  (~line 937: courier_name, tracking_number, recipient_*, address_line, status, notes, shipping_cost…).
- `store_identity` comes from `BuildsInvoiceDocument::buildStoreIdentity()` (used inside `present()` too).
- `resources/js/utils/shippingSlipSheet.js` (067/068): A4 794×1123 px, integer column widths, shared 1 px borders,
  measured row heights, one `captureElementCanvas` per page → one jsPDF; `SlipTooTallError` instead of clipping.
- Bulk cap for pre-orders: `config('preorders.bulk_max')` (200), exposed as `preorder_bulk_max`; the list selection
  composable `usePreorderSelection` already enforces it client-side.
- The bulk slip button is gated `auth.canDo('preorders','export')`.

## Decisions

### D1 — One read-only endpoint feeds BOTH single and bulk labels
**Decision**: New `POST /api/v1/preorders/shipping-labels` (`ability:preorders,export`) taking `preorder_ids[]`
(1..`bulk_max`). The drawer's single download calls it with one id.
**Rationale**: (a) The drawer's `shipment` ref holds unsaved edits (fact above); the spec (US1 #3) requires the
**saved** record, and the server is the only place that has it. (b) One data path + one renderer for single and bulk
(Constitution I). (c) A lean payload: number + shipment fields + one `store_identity` — no items, payments, customer
e-mail, prices (spec FR-010).
**Alternatives rejected**: adding `shipment` to `bulk-invoices` — pulls payments/items for nothing, has no cap, and
adding a cap would change an existing endpoint's behaviour; rendering the single label from the drawer's ref — prints
unsaved edits; keeping a client-side "saved copy" — a second source of truth that must be refreshed after five
different shipment actions.

### D2 — Eligibility and skip reasons decided by the server
**Decision**: A pre-order gets a label iff it is visible to the user (scoped model: DEMO/LIVE + seller scope),
`fulfillment = 'courier'` and it has a shipment row. Otherwise it is listed in `skipped[]` with reason
`not_found` (missing / other mode / other seller — indistinguishable on purpose, `preorder_number` null, same as 064/065),
`not_mail_order`, or `no_shipment`. Invariant: `data.length + skipped.length = distinct ids requested`.
Pre-order status is NOT a filter (spec edge case).
**Rationale**: matches the 064/065 "every selected item accounted for" pattern; reasons come from data, not the UI.

### D3 — Store identity once per request
**Decision**: `store_identity` at the top level of the response, built once.
**Rationale**: 054 found per-receipt `Setting::get` cost ~8 queries each; query count must not grow with N
(Constitution V). Test with a query-count assertion.

### D4 — Label order = ascending pre-order number
**Decision**: Server sorts `data` by `preorder_number` ascending (then id). Spec FR-004 amended (dated note).
**Rationale**: the selection can include rows from other pages ("select all N matching"), so the browser does not know
the list order of every id; the selection `Set` is click order. Number order is predictable and matches the paper the
shopkeeper sorts parcels by. Honouring the list sort would need `applySort()` server-side for a print job — rejected as
more than the requirement.

### D5 — 2×2 fixed quarters on an integer grid
**Decision**: A4 794×1123 px; columns 397 + 396 px, rows 562 + 561 px (first column/row also own left/top border, as
067 D2) → every cell has a 395×560 px content box (≈ A6). Cells are filled left→right, top→bottom; only existing labels
are drawn (a 5th label starts page 2 with one cell). Single download = one page, label in the top-left cell.
**Rationale**: product-owner choice (4 per page); fixed quarters keep the cut lines in the same place on every sheet
(A6 parcel-label size). Integer px at raster scale 2 keeps lines crisp (067/049 lesson).

### D6 — Never clip: measure and refuse
**Decision**: Each label's content height is measured from its real markup at 395 px; if > 560 px the whole download
fails with `LabelTooTallError` → localized toast, no file. No `overflow:hidden`, no `line-clamp`.
**Rationale**: spec FR-008; 067 D3/D5 and the 049 html2canvas clip trap.

### D7 — Share the A4 page rasterizer with 067
**Decision**: Extract the page loop of `renderShippingSlipSheet` (host off-screen → `captureElementCanvas` → jsPDF
page → release node/canvas) into `utils/a4PdfPages.js::renderA4Pages(pageHtmls, {capture})`; 067's renderer and the new
`utils/courierLabelSheet.js` both call it. 067's existing tests guard the refactor.
**Rationale**: Constitution I (one implementation for two callers). Cell markup and layout stay separate — the two
documents have different grids and content.

### D8 — Label content and wording
**Decision**: Top: courier name (large) and tracking number (large, monospace; blank ruled box when empty — never a
placeholder text). Middle, dominant: **To** — recipient name, phone, address (largest body text). Below: **From** —
store name, address, contact phone, contact person. Footer: pre-order number. Fixed English captions
(`Courier`, `Tracking No.`, `To`, `From`, `Order`) exactly like the existing 067 slip markup, which is not translated.
All values through `escapeHtml`, `white-space:pre-wrap`, `overflow-wrap:anywhere`. No barcode (spec assumption).
**Rationale**: a courier reads recipient + tracking first; consistency with the existing slip document's captions.

### D9 — Single download from the drawer: dirty warning
**Decision**: The new "Download courier label (A4)" button sits next to "Download shipping slip (PDF)" in the
"Courier shipping" section. Before downloading, the view compares the editable fields of the `shipment` ref with the
fields returned by the endpoint; if they differ, the download still happens (saved data) and an info toast says unsaved
changes were not included.
**Rationale**: spec US1 #3; comparison happens against the fresh server copy, so no stored "saved copy" is needed (D1).

### D10 — Permission and UI visibility
**Decision**: Route `ability:preorders,export` (same as bulk slips and bulk invoices). Both buttons `v-if
auth.canDo('preorders','export')` (hidden, never disabled — Constitution III). The drawer button only renders when a
shipment exists. Route segment `preorders` is already scope-aware for 058; `MenuActionsRouteCoverageTest` and
`ForbiddenSurfacesTest` must stay green.

### D11 — Performance budget
**Decision**: 200 labels = 50 pages, rasterized sequentially, node + canvas released per page (067 pattern). Measure in
a real browser during verification (SC-002: ≤ 60 s); if over budget, lower the raster scale only for this document
after checking line sharpness.
**Risk**: 067 measured slips, not full-page-dense labels; time per page is unmeasured here.
