---

description: "Task list for 049-product-variant-catalog-export"
---

# Tasks: Product Variant Catalog Export (PDF and Excel)

**Input**: Design documents from `/specs/049-product-variant-catalog-export/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/catalog-api.md, contracts/pos-scan.md, quickstart.md

**Tests**: INCLUDED. Constitution Principle II requires backend tests in `tests/`, frontend tests in `qa-tests/`, and a real-browser check; the barcode work additionally needs a manual hardware-scanner check that cannot be automated and must be reported honestly as done or not done.

**Organization**: Grouped by user story. Code comments, UI copy and commit messages are Indonesian (project convention); UI strings go through `en.json` AND `id.json` (guarded by `qa-tests/unit/localeKeys.test.js`).

**Test-run safety**: Inside Docker run backend tests only as `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test ...` (the plain command has wiped the dev DB twice). On the host use a throwaway test DB, and run the **full** backend suite as `php -d memory_limit=1G vendor/bin/phpunit` (the default 128 MB limit crashes unrelated export tests).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 PDF · US2 Excel · US3 Barcode scannability · US4 Scope/filters · US5 POS scan-to-add

---

## Phase 1: Setup (resolve the plan's unverified items)

- [X] T001 Decide the Code 128 source: search Packagist for `picqer/php-barcode-generator` (name/version **unverified**) and check it can expose the raw bar/space pattern for Code 128-B; if yes, `composer require` the confirmed version, otherwise note the in-house encoder decision; record the outcome and exact version in `specs/049-product-variant-catalog-export/research.md` (D3)
- [X] T002 [P] PDF spike (throwaway, not committed): render ONE A4 page of 8 sample cards (2 columns × 4 rows, picture placeholder, name, price, a fake 255-module barcode with `shape-rendering: crispEdges`) through `resources/js/utils/pdfCapture.js::captureElementCanvas()` into jsPDF at scale 2 and 3, JPEG quality 0.85/0.92; record seconds per page, KB per page and bar sharpness in `research.md` (D4); decide scale/quality and whether SC-001 (500 variants < 1 minute) must be adjusted — raise any SC change with the product owner instead of silently relaxing it; delete the spike files afterwards
- [X] T003 [P] Verify how the app font (`--font-sans` in `resources/css/app.css`, Plus Jakarta Sans) is delivered offline and that Japanese text falls back to a system font in the PDF render; record in `research.md` (D4) and add any missing font-fallback to the catalog page styles later in T016

**Checkpoint**: barcode source decided, PDF scale/quality chosen, font behaviour known.

---

## Phase 2: Foundational (blocking prerequisites for every story except US5)

**Purpose**: the single barcode encoder, the single catalog data source, its endpoint and the shared client pieces. US1, US2, US3 and US4 all build on these. (US5 is independent and may run in parallel with this phase.)

### Tests first

- [X] T004 [P] Write `tests/Unit/Code128Test.php`: the pattern for sample SKUs (`SPF-AP-MCPS-S-32`, lowercase, digits, a 20-char SKU) starts with the Start-B pattern and ends with the 13-module stop pattern; total modules = 11 × (characters + 2) + 13; a test-side decoder reads the pattern back to the exact input and validates the checksum; non-printable/non-ASCII input returns `null`; empty input returns `null`
- [X] T005 [P] Write `tests/Feature/ProductCatalogTest.php` for `GET /api/v1/catalog`: grouping by seller (alphabetical) and ordering (category → product → variant); a seller with only inactive/deleted products or variants is absent; inactive/deleted variants and products excluded; `search`, `artist_id[]`, `category_id[]` behave like `GET /products`; no `cost_price`, stock, BOM or preorder keys anywhere in the JSON; DEMO variants never appear in LIVE; store identity matches settings and omits unset address/contact; `generated_at` present; 403 for a user without the `products` menu; `422` above 2,000 variants; query count constant for 5 vs 50 variants (warm-up request first)

### Implementation

- [X] T006 Implement `app/Support/Code128.php` (Indonesian "why" comments): `pattern(string $value): ?string` (start B + data + checksum + stop as a string of `1`/`0` modules) and `png(string $pattern, int $moduleWidth, int $height, int $quietZoneModules = 10): string` using GD; reject characters outside printable ASCII; if T001 chose the library, wrap it here so this class stays the only entry point
- [X] T007 Implement `app/Services/ProductCatalogBuilder.php`: `use App\Support\BuildsInvoiceDocument` for `buildStoreIdentity()` (do NOT use the dead copy in `app/Http/Controllers/Concerns/`); query active products/variants with `Product::scopeMatchingListFilters()`, eager-load `artist` (withTrashed), `category`, `variants`; select selling price only; produce `{generated_at, store, totals, sellers[]}` per `data-model.md`; each variant carries `barcode {type, pattern, text}` from `Code128`
- [X] T008 Create `app/Http/Controllers/Api/ProductCatalogController.php` with `index()` (gate `canAccessMenu('products')` → 403, 2,000-variant ceiling → 422 with a translated message) and register `GET /catalog` in `routes/api.php` inside the authenticated group; add backend translation file `lang/en/catalog.php` and `lang/id/catalog.php` for the messages
- [X] T009 [P] Create `resources/js/api/catalog.js` with `getCatalog(params)` (`GET /catalog`, params = `search`, `artist_id`, `category_id`)
- [X] T010 [P] Create `resources/js/components/catalog/BarcodeBars.vue`: props `pattern`, `text`, `width` (px); draws the pattern as SVG rects with `shape-rendering="crispEdges"`, fixed quiet zone of ≥ 10 modules, bars in the ink token on a white background, the readable `text` beneath; renders nothing but the text when `pattern` is null
- [X] T011 [P] Write `qa-tests/component/BarcodeBars.test.js`: bar count/widths follow the pattern, quiet zone present, text shown, null pattern shows only the readable code, fill uses a token class (no hex)

**Checkpoint**: `GET /catalog` returns correct grouped data with barcodes; the bars component renders; encoder proven by round-trip tests.

---

## Phase 3: User Story 1 — Printable PDF catalog grouped by seller (Priority: P1) 🎯 MVP

**Goal**: a **Catalog** button on the Products page opens a dialog and downloads a colourful PDF: cover with store identity and seller index, one section per seller, cards with picture, category, product, variant, code, price and barcode.

**Independent Test**: with products from at least two sellers click Catalog → PDF; confirm the cover store details, one accent-coloured section per seller with the seller name on each of its pages, every active variant exactly once with all fields, no card split across pages.

### Tests for User Story 1

- [X] T012 [P] [US1] Write `qa-tests/unit/catalogPdf.test.js` for the pure pagination function in `resources/js/utils/catalogPdf.js`: cover page first; each page belongs to exactly one seller; seller header repeated on continuation pages; fixed cards-per-page; no variant split; sellers without variants omitted; page count for 0/1/8/9/17 variants; seller accent index cycles
- [X] T013 [P] [US1] Write `qa-tests/component/CatalogPdfPage.test.js`: cover shows logo/name/address/contact/generated-at and omits empty lines with no stray labels; seller page shows seller name, category, product, variant, readable code, price in Rupiah format, picture or same-size placeholder, barcode; no cost/stock text; long names clamp without breaking layout
- [X] T014 [P] [US1] Write `qa-tests/component/CatalogExportModal.test.js` (and extend `qa-tests/component/ProductsView.test.js`): Catalog button visible with the `products` menu and absent without it; dialog loads the catalog, shows seller/variant counts, shows "nothing to export" when empty (no download offered), shows an error with Retry on failure, PDF button calls the generator (mocked) and shows progress

### Implementation for User Story 1

- [X] T015 [US1] Add the catalog UI/PDF keys to BOTH `resources/js/locales/en.json` and `resources/js/locales/id.json` under a new `catalog.*` section (button, dialog title, counts, PDF/Excel labels, "nothing to export", progress, cover heading, "generated on", column/field labels); verify no duplicate keys in the file
- [X] T016 [US1] Create `resources/js/components/catalog/CatalogPdfPage.vue`: one A4 page (cover variant and seller-page variant), Tailwind token classes only, soft accent background + bold price, accent chosen from the existing token pairs (mint, sky, violet, warn, danger) by seller index (add a token pair to `resources/css/app.css` `@theme` only if a sixth accent is needed), 2 columns of fixed-height cards, uses `BarcodeBars` (always dark on white), font fallback for non-Latin names per T003
- [X] T017 [US1] Create `resources/js/utils/catalogPdf.js`: pure `paginateCatalog(catalog)`, and `generateCatalogPdf(catalog, { onProgress })` that dynamically imports `jspdf`, mounts each page offscreen via `createApp`, captures it with `captureElementCanvas()` from `utils/pdfCapture.js` (never index-based image swapping), adds it as a JPEG with the scale/quality from T002, unmounts and cleans up the container (also on error), and saves `katalog-produk-<store>-<yyyy-mm-dd>.pdf`
- [X] T018 [US1] Create `resources/js/components/catalog/CatalogExportModal.vue` (BaseModal): loads `getCatalog(params)` on open, shows counts and the empty state, a PDF button with progress and cancel-safe close, error handling through the shared convention
- [X] T019 [US1] Update `resources/js/views/ProductsView.vue`: add a **Catalog** button next to Export/Import inside the existing `auth.canAccessMenu('products')` block, passing the current search/seller/category filters to the modal
- [X] T020 [US1] Run T012–T014 and make them pass; run `qa-tests/unit/localeKeys.test.js`; generate a PDF from seeded data in the browser and record page count, file size and seconds in `research.md` (D4) for the SC-001 decision

**Checkpoint**: PDF catalog works end to end; independently demonstrable (MVP together with Phase 2).

---

## Phase 4: User Story 2 — Excel catalog grouped by seller (Priority: P1)

**Goal**: the same catalog as an Excel workbook with store information at the top, coloured seller heading rows, searchable text columns, numeric prices, embedded thumbnails and barcodes.

**Independent Test**: export Excel, open it; confirm store block, seller groups, Ctrl+F finds a code, price is numeric, pictures and barcodes are visible.

**Depends on**: Phase 2 (builder, encoder).

### Tests for User Story 2

- [X] T021 [P] [US2] Extend `tests/Feature/ProductCatalogTest.php` for `GET /api/v1/catalog/export`: 200 with the xlsx content type and filename `katalog-produk.xlsx`; read the workbook back (PhpSpreadsheet reader): store block rows, one seller heading row per seller, variant rows grouped under it, text cells for code/names/category, numeric price with a Rupiah number format, drawing count = variants with images + barcodes, no cost/stock/BOM header; 422 when nothing qualifies and above the ceiling; 403 without the `products` menu; headers follow the user's language (en/id)
- [X] T022 [P] [US2] Add a 500-variant memory test (with images present) to `tests/Feature/ProductCatalogTest.php` asserting the export completes and recording peak memory; if it exceeds the default limit apply the mitigations in research D5 (smaller thumbnails first) before raising any limit
- [X] T023 [P] [US2] Write `tests/Unit/CatalogImageThumbnailTest.php` for the thumbnail helper: downsizes a large image to ≤ 160 px longest side as JPEG, returns `null` for a missing or corrupt file, never throws

### Implementation for User Story 2

- [X] T024 [US2] Create `app/Support/CatalogImageThumbnail.php` (GD): read a public-disk image path, downscale to ≈160 px, return JPEG bytes or `null`
- [X] T025 [US2] Create `app/Exports/ProductCatalogExport.php` (Maatwebsite 4 / PhpSpreadsheet, single sheet "Katalog"): store block, per-seller merged heading row with a soft accent fill (ARGB constants mirroring the token palette, commented as the one place hex is unavoidable in a workbook), columns Seller/Category/Product/Variant/Code/Price/Image/Barcode, fixed row height, thumbnail and barcode PNG as drawings (via T024 and `Code128::png`), header labels from `lang/{en,id}/catalog.php`
- [X] T026 [US2] Add `export()` to `app/Http/Controllers/Api/ProductCatalogController.php` (same gate/ceiling, `422` with message when empty) and register `GET /catalog/export` in `routes/api.php`; filename `katalog-produk.xlsx`
- [X] T027 [US2] Add `downloadCatalogExcel(params)` to `resources/js/api/catalog.js` (blob download using the same pattern as `exportMasterData`), add the Excel button and 422 message handling to `CatalogExportModal.vue`, extend `qa-tests/component/CatalogExportModal.test.js`; run T021–T023 and the modal tests until green

**Checkpoint**: Excel catalog works; PDF and Excel come from the same builder output.

---

## Phase 5: User Story 3 — Barcodes the scanner can read (Priority: P1)

**Goal**: every barcode decodes to exactly the variant's code, is printed with the readable code, and survives real printing.

**Independent Test**: round-trip decode of every sample code in tests; manual scan of a printed page.

**Depends on**: Phase 2 and at least one of US1/US2 for the printed output.

- [X] T028 [P] [US3] Extend `tests/Unit/Code128Test.php` with the real SKU shapes produced by `ProductCodeGenerator`/`VariantSkuService` (segment 1–6 letters, hyphenated, unpadded numbers, up to 20 characters) and assert decode == input for each, plus the maximum-length case fits the PDF card width at ≥ 0.25 mm per module (the width calculation lives in `BarcodeBars.vue`/`CatalogPdfPage.vue`)
- [X] T029 [P] [US3] Extend `qa-tests/component/BarcodeBars.test.js`: computed module width never falls below the minimum for a 20-character code in the 2-column card width, quiet zone ≥ 10 modules, bars colour is the dark ink token even inside an accent-coloured section (never an accent colour)
- [X] T030 [US3] Add tests that a variant whose code contains a character Code 128 cannot encode still appears in the catalog JSON, PDF page and workbook with `barcode: null` and its readable code (no failure of the whole export) in `tests/Feature/ProductCatalogTest.php` and `qa-tests/component/CatalogPdfPage.test.js`
- [ ] T031 [US3] MANUAL — hardware scan test (SC-003): print the PDF at 100% on A4, scan at least 10 barcodes with a handheld scanner configured as a keyboard (Enter suffix) and 3 with a phone scanner app, plus scan from the screen; record read rate and any failures in `research.md`; if the read rate is below 95%, adjust module width/scale/quality (T002 findings) and repeat; state clearly in the PR whether this was done

**Checkpoint**: barcode correctness proven by tests and, separately and explicitly, by a real scanner.

---

## Phase 6: User Story 5 — Scan on the POS to add the item (Priority: P1)

**Goal**: Enter in the POS search adds the exact-SKU variant to the cart like a tap.

**Independent Test**: type a SKU and press Enter in `#pos-search` → one unit in the cart, box cleared and focused; unknown code → "not found" toast; partial text + Enter → no auto-add.

**Independent of** Phases 2–5 (can start immediately).

- [X] T032 [P] [US5] Write backend tests for `GET /api/v1/variants/lookup?exact=1` in `tests/Feature/ProductTest.php` (or a new `VariantLookupExactTest.php`): exact SKU (trimmed, case-insensitive) returns exactly one row with the existing response shape; a partial SKU, a variant name or a product name returns `[]`; inactive variant excluded; empty `q` returns `[]`; without `exact` the response equals today's; the catalog `code` of a variant resolves to that same variant through `exact=1` (FR-013)
- [X] T033 [P] [US5] Write `qa-tests/component/PosScan.test.js` following the existing POS view test setup: Enter on an exact code adds one unit and clears and refocuses the box; same code again → quantity 2; unknown code → warning toast, nothing added; zero stock → out-of-stock toast, nothing added; partial text + Enter → nothing added and normal results shown; empty Enter ignored; scan works with no open session (banner stays, payment still blocked); a pending debounced search does not repopulate the cleared box; typing into other fields is never captured
- [X] T034 [US5] Add the optional `exact` parameter to `lookupVariants()` in `app/Http/Controllers/Api/ProductController.php` (active variants only, `sku` equality on the trimmed query, response shape unchanged, indented comment explaining why exact beats the 40-row LIKE search)
- [X] T035 [US5] Update `resources/js/api/products.js::lookupVariants` to accept an options object (`{ exact: true }`) without changing existing callers
- [X] T036 [US5] Update `resources/js/views/PosView.vue`: `@keydown.enter` on `#pos-search` runs `handleScanEnter()` — trim, bump `searchSeq` to cancel the pending search, call the exact lookup, on one hit `addToCart(buildSearchCards([hit])[0])` (zero stock → toast instead of the silent ignore), clear `search`, keep focus; not found → warning toast; add the toast keys to BOTH locale files under `pos.*`
- [X] T037 [US5] Run T032–T033 and the existing POS tests until green; run `qa-tests/unit/localeKeys.test.js`

**Checkpoint**: scanning works on the POS independent of the catalog files.

---

## Phase 7: User Story 4 — Choose what goes into the catalog (Priority: P2)

**Goal**: the export honours the Products page's current filters and tells the user the scope; an empty scope offers no download.

**Independent Test**: filter to one seller → only that seller exported; clear filters → all; filter to nothing → clear message and no file.

**Depends on**: US1 (modal and button).

- [X] T038 [P] [US4] Extend `qa-tests/component/CatalogExportModal.test.js` and `qa-tests/component/ProductsView.test.js`: the modal sends the page's current `search`, `artist_id[]`, `category_id[]` to both catalog calls; reopening after a filter change uses the new filters (no stale scope); empty scope shows the message and disables the PDF/Excel buttons; extend `tests/Feature/ProductCatalogTest.php` so the export endpoint honours the same filters as the JSON endpoint
- [X] T039 [US4] Update `CatalogExportModal.vue` and `ProductsView.vue`: show the active scope in the dialog ("N sellers · M variants", plus the selected seller/category names when filtered), read filters at the moment the dialog opens, disable downloads when the scope is empty

**Checkpoint**: filter-scoped catalogs work; all five stories are independently functional.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T040 [P] Update `docs/openapi-pos-mvp.yaml` IN THE SAME COMMIT as the code: `GET /catalog`, `GET /catalog/export`, the `exact` parameter of `GET /variants/lookup`, and the `Catalog`/`CatalogVariant`/`Barcode` schemas; validate the YAML still parses (earlier features broke quoting here)
- [X] T041 [P] Add the dated notes: `docs/PRD-POS-Event-Multivendor.md` (catalog §7.18 revived by seller/Excel/PDF, barcode generation, external-scanner support from F6.5/F17.5, POS scan-to-add; camera scanning, per-event filter F18.3 and layout variants F18.5 not revived), a dated section in `CLAUDE.md` for the rules learned (single encoder, store identity via `App\Support\BuildsInvoiceDocument`, scan = exact lookup, raster PDF trade-offs and the spike results) and a dated pointer next to the existing "Scope discipline" cut line; add a `docs/RUNBOOK.md` note on scanner setup (keyboard mode with Enter suffix) and the PHP `memory_limit` needed for large Excel catalogs
- [X] T042 Run the full suites: `php -d memory_limit=1G vendor/bin/phpunit`, `npm test`, `npm run build`; report real pass/fail counts, including any pre-existing failures
- [X] T043 Real-browser verification following `quickstart.md` steps 1–11 with the console open (Constitution II), including Japanese seller/product names, DEMO vs LIVE, en ⇄ id, a role without the `products` menu, and PDF/Excel at ~100 and ~500 variants; record results honestly (including that the hardware-scanner step T031 was or was not performed) — **Done in headless Chrome against an isolated app on the throwaway test DB (see research.md): login, Products dialog, PDF + Excel download, POS scan flows, PDF/Excel at 62/100/500 variants. NOT covered in a browser: DEMO↔LIVE switch and en⇄id switch (covered by API/component tests only), a role without the products menu (API 403 + component tests only), opening the workbook in Excel itself (read back with PhpSpreadsheet), and T031 hardware scanning.**
- [X] T044 [P] Scan the touched Vue files for raw hex literals and non-token colours, confirm the Catalog button/dialog are absent for roles without access, confirm no new duplicate locale keys, and confirm no cost, stock or margin field appears in any JSON/PDF/Excel output

---

## Dependencies & Execution Order

### Phase dependencies

- Phase 1 (T001–T003) first; T001 gates T006, T002 gates T016/T017, T003 gates T016.
- Phase 2 gates US1, US2, US3, US4. **US5 (Phase 6) is independent** and can run in parallel with Phases 1–5.
- US1 (Phase 3) before US4 (Phase 7): both edit `CatalogExportModal.vue` and `ProductsView.vue`.
- US2 (Phase 4) needs Phase 2 only; it extends the modal from US1 in T027, so schedule T027 after T018.
- US3 (Phase 5) verification tasks need Phase 2; T031 needs a generated PDF (US1).
- Polish (Phase 8) after the stories being shipped are done.

### Within each story

Tests first and failing → backend (builder/controller/export) → i18n keys → frontend → run tests.

### Parallel opportunities

- T002 and T003 alongside T001.
- T004 and T005 (different files) together; T009, T010, T011 together.
- T012–T014 together; T021–T023 together.
- US5 (T032–T037) entirely parallel to Phases 2–5.
- T040, T041, T044 in parallel.

### Parallel example

```text
Developer A: T001 → T004 → T006 → T007 → T008 → T012–T020 (US1) → T038–T039 (US4)
Developer B: T002/T003 (spike, fonts) → T009–T011 → T021–T027 (US2) → T028–T031 (US3)
Developer C: T032 → T037 (US5, independent)
Then: Polish T040–T044
```

---

## Implementation Strategy

### MVP first

1. Phase 1 (barcode source, PDF spike) → Phase 2 (encoder, builder, endpoint, bars component).
2. US1 (PDF) + the barcode checks of US3 → demo a printed catalog that scans.
3. Add US5 (scan-to-add) so scanning pays off at the counter.
4. Add US2 (Excel), then US4 (filters).
5. Polish: OpenAPI, notes, full suites, browser and hardware verification.

### Notes

- No migration and nothing is stored; every output is generated on demand.
- Never read `cost_price`, `current_stock` or BOM data in the catalog builder (FR-007, SC-005).
- One barcode encoder only (`App\Support\Code128`); the SPA draws the server's pattern.
- The raster-PDF trade-offs (size, speed, non-selectable text) are accepted only if the T002 spike supports them; otherwise raise it with the product owner before building on it.
- The OpenAPI change ships in the same commit as the response changes.
