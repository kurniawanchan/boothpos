# Research: Correct Images on Downloaded Invoices

## Evidence

- **The file**: `/Users/chan/Downloads/invoice-PO-20261001-0004.pdf` (bulk download). The image at the top (logo slot) is the QRIS poster; the same poster is correctly shown again under "Cara pembayaran". Store data is fine: the dev DB has `store_logo_path = store-logo/….png` and the QR channel `payment-channels/….jpg` — two different files.
- **The code** (`resources/js/utils/pdfCapture.js`, `captureElementCanvas`):

  ```js
  const imgEls = Array.from(el.querySelectorAll('img'));          // images INSIDE the document
  const dataUrls = await Promise.all(imgEls.map(img => imageToDataUrl(img.src)…));
  return html2canvas(el, { …, onclone: (clonedDoc) => {
    const clonedImgs = clonedDoc.querySelectorAll('img');          // images of the WHOLE cloned PAGE
    clonedImgs.forEach((img, i) => { if (dataUrls[i]) img.src = dataUrls[i]; });  // matched by POSITION
  }});
  ```

- **html2canvas 1.4.1 source** (`dist/html2canvas.js`): `onclone(documentClone, referenceElement)` — `documentClone` is the clone of the entire document and `referenceElement` is the clone of the captured element, so the page-wide `querySelectorAll('img')` includes every image on the page, in document order.
- **Why bulk is hit**: `doBulkDownload()`, `doBulkShippingSlips()` and the bulk payment-invoice flow append the offscreen container to the END of `document.body`, so every other `<img>` on the page (header avatar, product thumbnails, drawer images) precedes it. With one such image: cloned = `[avatar, logo, qr]`, data = `[logoData, qrData]` → avatar ← logo data, **logo ← QR data**, QR untouched. That is the PDF.
- **Why the existing test missed it**: `qa-tests/unit/pdfCapture.test.js` builds the simulated cloned document from `el.outerHTML` only, so positions always coincide.
- **Why it "comes and goes"**: it depends on how many images the page shows when the user clicks (no avatar and no thumbnails → indices happen to line up).

## Decision 1 — Swap by image identity (own `src`), not by position

**Decision**: Build a map `rawSrcAttribute → dataURL` from the images inside `el` (fetch each distinct URL once), and in `onclone` replace the `src` of any cloned `<img>` whose own raw `src` attribute is in the map. Scope the lookup to the cloned reference element when html2canvas provides it (`onclone(doc, referenceElement)`), falling back to the document.

**Rationale**: Identity is correct by construction — an image can only ever receive *its own* bytes, even if the clone contains extra, missing or reordered images, or two images share a URL. Using the raw `getAttribute('src')` avoids relying on how the cloned document resolves URLs. Fetching each distinct URL once also removes duplicate downloads in the bulk loop (performance, SC-003).

**Failure behaviour (FR-006)**: A fetch that fails leaves no map entry, so that `<img>` keeps its original `src` (html2canvas draws it or leaves it blank) — never another image's data.

## Decision 2 — Fix once in the shared helper, not per screen

All eight call sites (`PreorderInvoiceModal`, `PreorderPaymentReceiptModal`, shipping slip, the two bulk loops in `PreordersView.vue`, `ReceiptModal`, `PurchaseOrderDetailModal`, `InvoiceDetailModal`) call `captureElementCanvas`/`downloadElementAs*`. A single change in `pdfCapture.js` covers FR-007; the single-document modals have the same latent flaw (their `el` is inside a teleported modal with other page images before it) and are fixed by the same change.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| Keep position mapping but query only inside the cloned reference element | Better, but still positional — breaks if html2canvas drops/ignores an image (e.g. `data-html2canvas-ignore`) or the DOM order differs; identity removes the whole class. |
| Tag live images with `data-capture-id` before capture and remove after | Works, but mutates the live (visible) document and needs cleanup in `finally`; the `src` key needs no mutation. |
| Mount the bulk container inside an isolated iframe so the page has no other images | Heavier, changes the rendering context (fonts/CSS), and leaves the single-document path unfixed. |
| Convert images to data URIs when building the HTML (`buildInvoiceHtml`) | Only fixes the bulk invoice builder, not the other documents or the shared helper; adds a fetch step in every builder. |
| Upgrade html2canvas | The behaviour is by design (full-document clone); an upgrade would not change it. |

## Risks / notes

- Images loaded from a different host still get converted to `data:` URIs (taint protection retained); the existing test for that stays.
- `img.getAttribute('src')` can be a relative URL; the fetch uses the resolved `img.src`, the map key stays the raw attribute — both sides of the swap use the same raw attribute because html2canvas clones attributes verbatim.

## Decision 3 — Follow-up (2026-10-04): bulk invoices render the SAME component as the modal

**Request**: "make the multiple download invoice like this one" (`invoice-PO-20261001-0004.pdf`, the rich on-screen layout) "get the logo from store logo". The reference PDF has the modal's layout (store address/contact block, two-column header, "Ways to pay" cards); the bulk download rendered a *different*, simpler layout from `utils/invoiceDocument.js::buildInvoiceHtml()` — a second design that had to be kept in sync by hand.

**Decision**: move the invoice document markup out of `PreorderInvoiceModal.vue` into `components/preorder/PreorderInvoiceDocument.vue` (presentational: `invoice` prop, `enlarge` event). The modal and the bulk download both render it; `PreordersView.vue::mountBulkDocument()` mounts it offscreen per invoice via `render(h(...))` with the app's `appContext` (so vue-i18n works) at 672px (modal width 720px minus `px-6`). The bulk payload (`invoicePayload()`) is the same one the single invoice endpoint returns, so no backend change; the logo comes from `store_identity.logo_url` (the Settings → store logo).

**Scope**: first the *invoice*, then (same day, follow-up request) the *payment invoice* too: `PreorderPaymentReceiptModal`'s body moved to `PreorderPaymentDocument.vue` (optional `paymentId`; without it — the bulk case — it shows the latest payment, as the old builder did, 720px wide because it carries its own padding). `buildInvoiceHtml()` and its unit test were deleted; its rules (Powered-by escaping, logo before QR, no logo → no image) are now covered by `qa-tests/component/PreorderDocuments.test.js` against both components. `utils/invoiceDocument.js` keeps only `buildShippingSlipHtml()`.

**Why not restyle `buildInvoiceHtml`**: it would re-create the modal's Tailwind markup as inline-styled strings — the exact duplication that made the two layouts diverge.
