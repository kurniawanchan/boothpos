# Implementation Plan: Product Variant Catalog Export (PDF and Excel)

**Branch**: `080-product-variant-catalog-export` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/049-product-variant-catalog-export/spec.md`

## Summary

Add a **Catalog** action to the Products page that downloads the active variants grouped by seller as a colourful **PDF** (cover with store identity, one section per seller, cards with picture, category, product, variant, code, price and a Code 128 barcode) and as an **Excel** workbook (store block, seller group rows, searchable text columns, embedded thumbnails and barcode images). Add **scan-to-add** on the POS search: Enter on an exact code adds that variant to the cart.

Technical approach (details in [research.md](research.md)):

1. **One data source**: `ProductCatalogBuilder` produces the grouped catalog (sellers → variants, store identity, generated-at) for both the JSON endpoint the PDF uses and the Excel export, so the two formats can never disagree.
2. **One barcode implementation, server-side**: a single Code 128 encoder returns the bar pattern; the JSON carries that pattern (the SPA draws it as crisp vector bars), and the Excel export renders the same pattern to PNG with GD. No second encoder in JavaScript.
3. **PDF in the browser through `utils/pdfCapture.js`** (project rule: all document downloads): A4 page components are rasterised page by page into jsPDF. This keeps the Tailwind-token design, browser font fallback for non-Latin names, and the existing image-taint fix. The cost is file size and speed, so a measured spike gates the page layout.
4. **Excel on the server** with the already-installed `maatwebsite/excel`, using worksheet drawings for thumbnails and barcodes (downscaled with GD to keep memory bounded).
5. **Scan-to-add**: a small, additive `exact=1` mode on `GET /variants/lookup` resolves one code deterministically; `PosView` calls it on Enter and reuses the existing add-to-cart path.
6. No migration, no new table, nothing stored.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13.29), JavaScript (Vue 3, Vite, Tailwind v4)

**Primary Dependencies**: existing — `maatwebsite/excel` ^4, `jspdf` ^4.2, `html2canvas` ^1.4, GD and zip PHP extensions (both present on this machine). New (to confirm, **unverified**): a Code 128 encoder for PHP — candidate `picqer/php-barcode-generator`; fallback is a small in-house Code 128-B encoder pinned by known test vectors. No new npm dependency.

**Storage**: MySQL 8, read-only for this feature; public disk images read for the Excel export; nothing persisted

**Testing**: PHPUnit feature + unit tests (`tests/Feature/ProductCatalogTest.php`, encoder unit test, `VariantLookupTest` extension); Vitest (`qa-tests/component`, `qa-tests/unit`); real-browser check; **manual scanner test** with a hardware scanner (cannot be automated)

**Target Platform**: single-machine local install (native or Docker), used offline at venues

**Project Type**: Web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: 100-variant catalog PDF in well under 30 s; Excel for 500 variants within the PHP memory limit; scan-to-cart under 2 s

**Constraints**: no cost/margin/stock in any output; DEMO/LIVE separation; server-side authorization (`products` menu); i18n en + id; no raw hex (barcode bars use the ink token and are always dark-on-white); works offline (no CDN fonts/images fetched at generation time except same-origin app assets)

**Scale/Scope**: hundreds of variants typical; 500 as the stated upper target

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|-----------|------------|
| I. Code quality / single source | PASS. One builder for catalog data, one barcode encoder, store identity via the existing `App\Support\BuildsInvoiceDocument::buildStoreIdentity` (not copied). Controllers stay thin. Scan code resolution has one definition (`exact=1` in the existing lookup). |
| II. Testing | PASS (planned). Backend tests for grouping/exclusions/permission/mode/no-cost; encoder checksum/pattern tests; Excel content test; Vitest for button, modal, PDF page pagination and POS scan; real-browser + hardware-scanner checks recorded honestly. |
| III. UX consistency | PASS. Token classes; button and modal hidden for users without `products` access; errors via the shared 422/409/403 handling; copy in en + id. The PDF palette cycles existing colour-token pairs (mint, sky, violet, warn, danger). |
| IV. Security | PASS. Authorization enforced server-side on both endpoints; the builder selects selling price only (no cost column is ever read); the scan lookup is read-only and prices/totals still come from the server at checkout. |
| V. Performance | PASS with a flagged risk. Eager-loaded relations, one query set per export; `jspdf`/`html2canvas` stay dynamically imported (existing chunks); Excel images are downscaled to bound memory. The 128 MB default PHP memory already crashed unrelated export tests, so an explicit 500-variant memory test is part of the work. |
| Docs discipline | OpenAPI in the same commit; dated PRD note (revives §7.18 catalog, F6.5/F17.5 scanning for external scanners, plus POS scan-to-add); the "Scope discipline" lines in `CLAUDE.md` get a dated note, not a rewrite. |

**Post-design re-check**: no violation. One principle-adjacent trade-off (raster PDF vs vector) is recorded in research D4 with a measurable gate instead of silently accepted.

## Project Structure

### Documentation (this feature)

```text
specs/049-product-variant-catalog-export/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── catalog-api.md
│   └── pos-scan.md
├── checklists/requirements.md
└── tasks.md              # produced later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/ProductCatalogBuilder.php             # NEW: grouped catalog data + store identity (uses BuildsInvoiceDocument)
├── Support/Code128.php                            # NEW: single encoder -> module pattern (+ PNG for Excel)
├── Exports/ProductCatalogExport.php               # NEW: Maatwebsite sheet with drawings
├── Http/Controllers/Api/ProductCatalogController.php   # NEW: index (JSON) + export (xlsx); products-menu gate
└── Http/Controllers/Api/ProductController.php     # lookupVariants(): optional exact=1

routes/api.php                                     # GET /catalog, GET /catalog/export

resources/js/
├── api/catalog.js                                 # NEW
├── utils/catalogPdf.js                            # NEW: pagination + page rendering via pdfCapture
├── components/catalog/CatalogPdfPage.vue          # NEW: one A4 page (cover / seller page)
├── components/catalog/CatalogExportModal.vue      # NEW: format choice, scope summary, progress
├── components/catalog/BarcodeBars.vue             # NEW: draws the server's bar pattern
├── views/ProductsView.vue                         # + Catalog button
├── views/PosView.vue                              # + Enter-to-add handler
└── locales/{en,id}.json

tests/Feature/ProductCatalogTest.php, tests/Unit/Code128Test.php, tests/Feature/ (lookup exact)
qa-tests/component/{CatalogExportModal,CatalogPdfPage,PosScan}.test.js, qa-tests/unit/catalogPdf.test.js

docs/openapi-pos-mvp.yaml, docs/PRD-POS-Event-Multivendor.md (dated note), CLAUDE.md
```

**Structure Decision**: existing single Laravel + Vue repo. New files only where a new concern exists (catalog data, barcode, Excel sheet, PDF pages); everything else extends the file that already owns it.

## Complexity Tracking

No Constitution violations; table intentionally empty.
