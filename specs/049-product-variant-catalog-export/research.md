# Research: Product Variant Catalog Export (PDF and Excel)

Grounded in files read on 2026-10-28. Anything not yet run is labelled **unverified**. No NEEDS CLARIFICATION remains; two spec items were reconciled (D9).

## D1 — One data source for both formats

- **Decision**: `App\Services\ProductCatalogBuilder::build(filters)` returns `{store, generated_at, sellers: [{artist_id, name, variants: [...]}]}`. `GET /catalog` returns it as JSON (PDF path); `GET /catalog/export` builds the same structure into the workbook.
- **Rationale**: this repo already settled on "one source for the list AND its export" (`SalesTransactionsService`, `Product::scopeMatchingListFilters`). Two formats from two queries would drift (spec SC-002).
- **Filters**: reuse `Product::scopeMatchingListFilters()` (search, `artist_id[]`, `category_id[]`) so the catalog contains exactly what the Products screen shows (FR-020). Always restricted to active products and active, non-deleted variants (FR-021).
- **Fields per variant**: seller, category, product name, variant name, `code` (SKU), `sell_price` (money string), `image_url` (variant → product fallback via `ProductVariant::image_url`), `barcode`. The query never selects `cost_price`, stock or BOM (FR-007, SC-005).
- **Ordering**: sellers alphabetical; within a seller by category, product, variant name (FR-004/006).
- **Alternative rejected**: reuse `GET /products?with_variants=1` and group in the SPA — would need cost-stripping, would paginate (max 100 per page) and cannot supply barcodes or store identity.

## D2 — Store information: reuse, don't copy

- **Decision**: the builder uses `App\Support\BuildsInvoiceDocument::buildStoreIdentity()` (name follows DEMO/LIVE, logo URL, contact person/phone/email, address) exactly like `SettlementDocument` and `PreorderInvoiceMail` do.
- **Notes**: a second, older copy exists at `app/Http/Controllers/Concerns/BuildsInvoiceDocument.php` and is **not** referenced by any class; do not use it and do not add a third. The trait's logo honours `receipt_show_logo` (the flag documented as "receipt/document only"), which is the right behaviour for a printed document. Missing address/phone/email are simply omitted (spec edge case).
- **Generated-at** is added by the builder (needed to recognise stale printed copies).

## D3 — Barcode type and single encoder

- **Decision**: **Code 128** encoding the variant's SKU exactly. SKUs are like `SPF-AP-MCPS-S-32` (letters, digits, hyphens; up to 20 chars). EAN/UPC are numeric-only and fixed-length; Code 39 has no lowercase and is longer; Code 128 is read by essentially every handheld/counter scanner and supports the full printable ASCII set (spec Assumption).
- **One implementation**: a PHP encoder `App\Support\Code128` returns the **module pattern** (string of 1/0 bars, including start, checksum, stop and a quiet-zone recommendation). The JSON carries the pattern; the SPA draws it as SVG rectangles (no JS barcode library); the Excel export renders the same pattern to PNG with GD. Constitution I forbids two encoders for one concern.
- **Library vs in-house — DECIDED (T001): in-house encoder, library as dev-only oracle.** `picqer/php-barcode-generator` was confirmed on Packagist (v3.3.0, requires PHP ^8.2) but is **LGPL-3.0-or-later**; BoothPOS is sold as a one-time license and installed in customers' stores, so shipping an LGPL library adds redistribution obligations, and we need the raw bar pattern anyway. `App\Support\Code128` is therefore written in-house (~60 lines) and picqer is added as `require-dev` only (the store image installs with `--no-dev`, so it is never shipped); `tests/Unit/Code128Test.php` compares the pattern against picqer's Code 128-B for every printable ASCII character and lengths 1–20.
- **Content validation**: the encoder rejects characters outside printable ASCII; a SKU that cannot be encoded gets no barcode but is still listed with its readable code (never fails the whole export).
- **Scanability rules**: dark ink on white background always (never an accent colour); quiet zone ≥ 10 modules each side; module width ≥ 0.25 mm at A4 100% scale; readable code printed beneath.

## D4 — PDF generation: raster pages through `pdfCapture` (with a measured gate)

- **Facts**: there is no server-side PDF anywhere in the repo; CLAUDE.md requires that **all** document downloads go through `utils/pdfCapture.js` (image-by-`src` swap, never by index); `jspdf` and `html2canvas` are already dynamically imported.
- **Decision**: build A4 page components (`CatalogPdfPage.vue`), mount them offscreen one at a time, rasterise with `captureElementCanvas()`, add each to jsPDF as a JPEG. Cover page first (store identity + seller index), then seller pages. Pagination is computed in JS from a **fixed card height** (long names clamp to two lines), so no card can be split and each page belongs to exactly one seller with the seller header repeated on continuation pages (FR-017). Layout: 2 card columns, so a 20-character SKU still gets a barcode wide enough to scan.
- **Why not vector (jsPDF drawing API)**: crisp and tiny, but jsPDF's built-in fonts cannot render Japanese/CJK, which spec FR-023 requires; embedding a CJK font adds megabytes and licensing work. **Why not server PDF (dompdf etc.)**: new dependency, same CJK font problem, and a second document toolchain next to the project's rule.
- **Costs accepted, and a gate**: output is image-based (text not selectable) and larger. **Unverified estimate**: ~0.4–1 MB/page at scale 2 → a 100-variant catalog ≈ 13 pages ≈ 5–12 MB; 500 variants ≈ 63 pages. Rendering time is ≈ 1–2 s/page, so 500 variants may exceed spec SC-001's 1 minute. **First implementation task is a spike** that measures one page's size/time and checks barcode sharpness at scale 2 vs 3; results decide scale and, if needed, the SC-001 ceiling (a reasoned adjustment to e.g. 300 variants will be raised with the product owner, not hidden).
- **Sharp bars**: the SVG bars use `shape-rendering: crispEdges`, integer-ratio sizing and a fixed card width so each module is a whole number of device pixels where possible.
- **Offline**: fonts — the app's `--font-sans` is Plus Jakarta Sans with `system-ui` fallback; how the font file is delivered offline was **not verified** (no CDN link found in `resources/views` or `app.css`). Non-Latin names rely on the OS fallback font in the browser that generates the PDF; verified in the browser check.
- **Colour**: seller accent cycles through the existing token pairs (`mint`, `sky`, `violet`, `warn`, `danger`); backgrounds soft, prices bold, brand colour on the cover. No new hex literals; if a sixth accent is wanted, add a token pair in `@theme` like `sky/violet` were.
- **Alternative noted for later**: print-CSS + browser "Save as PDF" would give vector, searchable output but needs a print dialog and does not fit a one-click download.

## D5 — Excel: server-side with drawings, bounded memory

- **Decision**: `ProductCatalogExport` (Maatwebsite 4 / PhpSpreadsheet): single sheet; rows 1–N store block (name, address, contact, generated-at); then per seller one merged, coloured heading row followed by variant rows. Columns: Seller, Category, Product, Variant, Code, Price (numeric, Rupiah format), Image, Barcode. Code/names/price are real cell values (searchable, FR-019); picture and barcode are drawings anchored in the row.
- **Images**: variant image read from the public disk and downscaled with GD (≈160 px, JPEG) before embedding; missing file → empty cell. Barcode PNG from the shared encoder (D3).
- **Memory risk (verified fact)**: the default 128 MB limit already killed the full test run in `ReportTest` export tests (zipstream). Embedding 500 images could hit the same wall, so the plan includes a 500-variant memory test; mitigations in order: smaller thumbnails, one drawing per row created lazily, document the PHP `memory_limit` need in RUNBOOK. Filename `katalog-produk.xlsx` (Indonesian, like the other exports).
- **Alternative rejected**: client-side xlsx (no library installed; would add a dependency and re-implement formatting).

## D6 — Endpoints and authorization

- **Decision**: `GET /catalog` (JSON) and `GET /catalog/export` (xlsx). Gate: `canAccessMenu('products')` checked in the controller (spec FR-002); 403 otherwise. This is deliberately **not** `canManageMasterData()` (used by `/exports/{entity}`): the catalog holds only selling data and the spec grants it to anyone who can open Products.
- **Empty result**: `GET /catalog` returns an empty `sellers` array; the SPA shows the "nothing to export" message (FR-003). `GET /catalog/export` with nothing to export returns `422` with an explanatory message (no empty workbook).
- **Rate/size**: no pagination (catalog is a whole document); capped at 2,000 variants with a clear `422` beyond that, to protect memory.

## D7 — POS scan-to-add

- **Facts**: `PosView` search is a debounced `GET /variants/lookup?q=` (LIKE on SKU, variant name, product name, 40 max); there is no Enter handling; a search card **is** the cart item shape (`buildSearchCards`), and tapping calls `addToCart(card)` which silently ignores `current_stock <= 0`.
- **Decision**: add optional `exact=1` to `lookupVariants()`: returns only the active variant whose SKU equals `q` (trimmed, case-insensitive per the DB collation). On `keydown.enter` in `#pos-search`, `PosView` calls it directly (bypassing the debounce), then: one hit → `addToCart(buildSearchCards([hit])[0])`, clear the box and keep focus; no hit → toast "code not found"; zero stock → toast (instead of today's silent ignore, for scans only). Non-exact text + Enter does nothing special (the normal search results already show).
- **Why server-side exact**: the LIKE search caps at 40 and sorts by SKU, so a client-side "find the exact match in the results" could miss it for short prefixes; one deterministic lookup is simpler and testable.
- **Races**: Enter increments `searchSeq` and clears `search`, cancelling any in-flight debounced search (existing 030 guard); a double scan adds two units visibly in the cart (spec scenario 6).
- **Session**: adding to the cart does not require an open session today (payment does); scan keeps tap parity (see D9).
- **Out of scope**: camera scanning, scanner-vs-human typing-speed detection, beep/feedback sounds.

## D8 — PRD and scope notes

- PRD §7.18 (F18.1–F18.5) and F6.5/F17.5 exist as requirements, while CLAUDE.md "Scope discipline" lists the printed/PDF catalog and barcode scanning as cut. This feature revives: catalog (PDF + Excel, by seller), barcode generation, and scanning by external keyboard-style scanners incl. POS scan-to-add. Required: a dated note in the PRD and a dated note beside the CLAUDE.md scope-cut line. Not revived: per-event filter (F18.3), layout variants (F18.5), camera scanning.

## D9 — Spec reconciliations made during planning

1. Spec scenario 7 said a scan with no open session adds nothing, while FR-014 says "same rules as tapping". Tapping adds to the cart without a session and only blocks payment. **Resolved in favour of tap parity**; the scenario text in `spec.md` was corrected accordingly.
2. SC-001 (500 variants in under 1 minute) may be unreachable with raster PDF pages; decided by the D4 spike and raised explicitly if it needs adjusting.

## Implementation findings (2026-10-28) — measured, not estimated

**T003 — fonts (settled).** No web font is delivered anywhere: no `@font-face`, no font package, no `<link>` in `resources/views/app.blade.php`. `--font-sans` names Plus Jakarta Sans but the app renders in `system-ui`. So the PDF is offline-safe, and Japanese text falls back to the OS CJK font of the browser that generates it (verified visually).

**T002/T020 — PDF spike (real headless Chrome 156, `utils/catalogPdf.js` + the real `CatalogPdfPage`, scale 2, JPEG 0.9).** Measured through a throwaway Vite harness driven over the DevTools protocol:

| Variants | Pages | Generation | PDF size |
|---|---|---|---|
| 62 | 12 | 1.8–3.1 s (~150–250 ms/page) | 2.9–3.0 MB |
| 100 | 15 | 2.3 s | 4.4 MB |
| 501 | 67 | 9.8–10.6 s | 21.2–21.6 MB |

The earlier estimate (1–2 s/page, ~1 MB/page) was pessimistic: **SC-001 (500 variants in under a minute) holds with a wide margin**. The remaining cost is file size (~320 KB/page); `PDF_SCALE`/`JPEG_QUALITY` stay at 2 / 0.9 and quality can be lowered if size matters.

**Defects only a real browser could show (all fixed, with tests where jsdom can express them):**
1. **Clipped text** — html2canvas cuts text at the element's content box: `truncate` on inline spans and `line-clamp-*` removed the lower half of glyphs, and the clip rectangle sits a few px above the text, so a box exactly one line tall loses its bottom. Fix: block elements with explicit px line-heights and a clip box 6 px taller than the text, pulled back with `-mb-[6px]` (documented in the `CatalogPdfPage.vue` header).
2. **Barcode sizing** — a fixed 312 px width gave 2.27 device px per module for the 20-character code; edges blurred and a pixel-level scanline decode of that code failed (60 of 61). Fix: each barcode's width is chosen so every module is a whole number of device pixels (`integerModulePx`, scale shared through `PDF_RASTER_SCALE`). Afterwards **all 61 (62-variant file), 500 of 500 (501-variant file) and 27 of 27 (the real app's downloaded PDF) barcodes decode from the PDF's own pixels**, on every one of their scanlines.
3. **POS scan race** — see D7 below; mock-based tests could not see it.

The software decode (`.spike/decode.php`, a scanline Code 128 reader over the PDF's embedded JPEGs at 192 dpi) is a **stand-in** for a scanner; it does **not** replace the hardware test (T031, still open).

**T022 — Excel memory (real GD + PhpSpreadsheet, 500 variants, 1200×900 noisy photos, default PHP limit):** peak 85 MB, 2.3 MB workbook, so the 512 MB bump in the controller is a safety margin, not a necessity. The test photos are 10 distinct images reused across 500 variants, so file size is optimistic; memory is bounded either way because pictures are streamed from temp files. A bug found by reading the workbook back: Maatwebsite silently drops an empty-array row, shifting every later row by one and making the seller-heading merge overwrite the first variant row; the spacer row is now `['']`.

**Real-app browser verification (isolated app on the throwaway `boothpos_test` database, DEMO mode, real API, headless Chrome):** login → Products shows the Catalog button → dialog "3 sellers · 27 variants" → PDF (7 pages, 1.2 MB) and Excel (35 rows, 27 barcode drawings, store block, merged seller headings, numeric prices, no cost/stock/margin) both download; POS: a scan adds one unit with the box cleared and focused, a repeat makes quantity 2, two back-to-back scans of another code both land, an unknown code gives the "Code … was not found." toast, zero stock gives the out-of-stock toast, partial text + Enter keeps the text and adds nothing. Console: only html2canvas debug lines, one expected 404 (`/sessions/current` = no open session) and one "Error loading image" for a catalog picture whose file does not exist (below).

**Known limitation, not fixed here:** a variant whose `image_path` points to a file that no longer exists is answered by Laravel's SPA catch-all as `200 text/html`; `pdfCapture` converts that into a data URL, html2canvas logs "Error loading image", and the card shows an empty picture area instead of the "No image" placeholder. It affects every document that embeds product images, not only the catalog.

**D7 — POS scan race (found in the real browser).** The first handler tied its result to the existing `searchSeq` stale-guard. In a real browser the 300 ms debounced search fires *after* Enter (network latency exceeds the debounce), bumps `searchSeq`, and the scan was dropped silently; two fast scans in a row cancelled each other the same way. Final design: Enter calls `runSearch.cancel()` (new `useDebouncedFn().cancel`), clears the box *before* the request so the next scan can never be glued to the previous code, and always applies an exact hit; a separate `scanSeq` only decides whether partial-text results may be restored. Regression tests use a lookup slower than the debounce and fail when the old behaviour is reintroduced (checked by mutation: 5 of 14 fail).

## Open items carried into tasks — status

1. Confirm the Packagist name/version for the Code 128 library, or write the in-house encoder (D3).
2. Run the PDF spike (page size, seconds per page, bar sharpness) before building the full renderer (D4).
3. Verify how the app font loads offline and that CJK names render in the browser that generates the PDF (D4).
4. Measure Excel memory at 500 variants (D5).
5. Hardware-scanner test on a printed page and on screen (SC-003) — manual, recorded honestly.

1. ~~Packagist name/version / in-house encoder~~ — done (in-house, picqer dev-only).
2. ~~PDF spike~~ — done, numbers above.
3. ~~Offline font / CJK~~ — done: system font, CJK renders.
4. ~~Excel memory at 500~~ — done: 85 MB peak.
5. **Hardware-scanner test (SC-003) — NOT done.** Only the software decode above has been run.
