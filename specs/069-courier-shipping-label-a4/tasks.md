**Classification**: INTERNAL

# Tasks: Courier Shipping Labels on A4 (single and bulk)

**Input**: Design documents from `specs/069-courier-shipping-label-a4/` (plan.md, spec.md, research.md D1–D11,
data-model.md, contracts/shipping-labels.md, quickstart.md)

**Tests**: REQUIRED — Constitution II (backend feature tests on real MySQL, Vitest under `qa-tests/`, real-browser check).
Write each test first and see it fail before the implementation task that follows it.

**Conventions**: code comments, UI copy and commit messages in Indonesian (cite `069` / research D-numbers in comments).
Run backend tests in the container ONLY as
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=…` (never without the
`-e` overrides). Frontend: `npx vitest run --exclude '.claude/**' <files>`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 = single label from the detail drawer (P1), US2 = bulk labels from the list (P2)

---

## Phase 1: Setup

- [X] T001 Confirm the baseline is green before touching shared code: run `npx vitest run --exclude '.claude/**' qa-tests/unit/shippingSlipSheet.test.js qa-tests/unit/pdfCapture.test.js qa-tests/component/PreordersView.test.js` and `php artisan test --filter='MenuActionsRouteCoverageTest|ForbiddenSurfacesTest'` (container form above); record any pre-existing failure (e.g. the flaky `PreordersView.test.js` noted for 068, re-run the file once before blaming a change) in specs/069-courier-shipping-label-a4/quickstart.md under a "Baseline" heading

---

## Phase 2: Foundational (blocks both stories)

**Purpose**: the read-only endpoint (research D1–D4, D10), the shared A4 page rasterizer (D7) and the label renderer (D5,
D6, D8). Both stories call the same endpoint and renderer.

### Backend endpoint

- [X] T002 [P] Write failing feature test tests/Feature/PreorderShippingLabelsTest.php covering, per contracts/shipping-labels.md: (a) a Mail Order pre-order with a shipment returns one `data` row with exactly `preorder_id, preorder_number, courier_name, tracking_number, recipient_name, recipient_phone, address_line` and the response has a top-level `store_identity` (`name, address, contact_phone, contact_person`); (b) skip reasons `no_shipment` (courier, no shipment), `not_mail_order` (pickup — including a pickup pre-order that still has a shipment row), `not_found` (non-existent id AND a DEMO-mode pre-order requested in LIVE mode, both with `preorder_number` null); (c) `count(data) + count(skipped)` equals the number of ids sent; (d) `data` ordered by `preorder_number` ASC regardless of request order; (e) pre-order status is not a filter (a cancelled courier pre-order with a shipment is included); (f) null `tracking_number`/`recipient_phone`/`address_line` come back as null; (g) 422 for empty array, non-integer, duplicate ids, and `config('preorders.bulk_max') + 1` ids (set the config to 3 in the test); (h) 403 `action_not_allowed` for a role whose `menu_actions` gives `preorders` without `export`, 401 unauthenticated; (i) a seller-scoped user (058, `users.artist_id`) gets `not_found` for a pre-order whose items are all another seller's; (j) the JSON contains no customer e-mail/social handle, no prices, no items, no payments (assert those keys/values are absent); (k) query count for 1 vs 20 eligible pre-orders is equal (warm-up call first — settings/licence caches, see 048 note); (l) no rows change in `preorders`, `shipments`, `activity_logs` (count/updated_at unchanged)
- [X] T003 [P] Create app/Http/Requests/PreorderShippingLabelsRequest.php: `authorize()` returns true (route middleware `ability:preorders,export` gates); rules `preorder_ids` required|array|min:1|max:`config('preorders.bulk_max')`, `preorder_ids.*` integer|distinct — deliberately NO `exists:` rule (it bypasses DataModeScope/SellerScope; unknown ids become `not_found`, comment citing research D2); 422 message names the max
- [X] T004 Create app/Support/PreorderShippingLabels.php with `build(array $ids): array` returning `['data' => [...], 'skipped' => [...]]`: one query through the scoped `Preorder` model `whereIn('id', $ids)->with('shipment')` (no `withoutGlobalScopes`), classify each distinct requested id in this order: missing from result → `not_found` (number null); `fulfillment !== 'courier'` → `not_mail_order`; no shipment → `no_shipment`; else a label row with only the seven fields from data-model.md; sort labels by `preorder_number` then id; skipped rows in request order. Docblock in Indonesian explaining D1/D2/D4
- [X] T005 Add `shippingLabels(PreorderShippingLabelsRequest $request): JsonResponse` to app/Http/Controllers/Api/PreorderController.php: delegate to `PreorderShippingLabels::build()` and add `'store_identity' => $this->buildStoreIdentity($this->imageUploadService)` ONCE at the top level (research D3); pick only `name, address, contact_phone, contact_person` (plus nothing else) from the identity
- [X] T006 Register `Route::post('/preorders/shipping-labels', [PreorderController::class, 'shippingLabels'])->middleware('ability:preorders,export');` in routes/api.php next to `/preorders/bulk-invoices` and BEFORE `apiResource('preorders')` (otherwise `{preorder}` captures `shipping-labels`); then run T002 until green and run `--filter='MenuActionsRouteCoverageTest|ForbiddenSurfacesTest'` (both must stay green; the `preorders` segment is already scope-aware)
- [X] T007 [P] Document the endpoint in docs/openapi-pos-mvp.yaml: path `POST /preorders/shipping-labels`, request schema, `ShippingLabel`, `SkippedShippingLabel` (reason enum `not_found|not_mail_order|no_shipment`), `store_identity`, responses 200/401/403/422/423 exactly as contracts/shipping-labels.md
- [X] T008 [P] Add `preorderShippingLabels(preorderIds)` to resources/js/api/preorders.js: `client.post('/preorders/shipping-labels', { preorder_ids: preorderIds }).then((r) => r.data)` (returns `{ data, skipped, store_identity }`)

### Shared A4 rasterizer (extraction from 067)

- [X] T009 [P] Write failing test qa-tests/unit/a4PdfPages.test.js for `renderA4Pages(pageHtmls, { capture })`: one `capture` call per page in order, one jsPDF page per html (first page without `addPage`), the off-screen host is removed from `document.body` after each page even when `capture` throws (error propagates), canvas width/height set to 0 after use, returns a Blob; mock `jspdf` with `vi.mock` (follow the existing mocking in qa-tests/unit/shippingSlipSheet.test.js)
- [X] T010 Create resources/js/utils/a4PdfPages.js exporting `renderA4Pages(pageHtmls, { capture = captureElementCanvas } = {})` by MOVING the page loop (lines 191–211: dynamic `import('jspdf')`, portrait A4 pt, host `position:fixed;left:-9999px`, `capture(host.firstElementChild)`, `addImage(..., 0, 0, PDF_W_PT, PDF_H_PT)`, release node + canvas) out of resources/js/utils/shippingSlipSheet.js; move `PDF_W_PT`/`PDF_H_PT` with it; header comment explains it is shared by 067 slips and 069 labels (research D7)
- [X] T011 Make `renderShippingSlipSheet` in resources/js/utils/shippingSlipSheet.js delegate: build `pages.map((p) => buildSheetPageHtml(p, cellHtmls))` and `return renderA4Pages(pageHtmls, { capture })` — no behaviour change; run qa-tests/unit/shippingSlipSheet.test.js and qa-tests/unit/a4PdfPages.test.js until both green (if a 067 test asserted internals that moved, adapt the assertion, never the behaviour)

### Courier label renderer

- [X] T012 [P] Write failing test qa-tests/unit/courierLabelSheet.test.js: (a) `labelPages(n)` → n=1 → 1 page [0]; n=4 → 1 page [0..3]; n=5 → 2 pages, second [4]; n=0 → []; (b) `buildCourierLabelCellHtml(label, store)` contains courier, tracking, recipient name/phone/address, store name/address/contact phone/contact person and the pre-order number; captions `Courier`, `Tracking No.`, `To`, `From`, `Order`; (c) values with `<b>`, `&`, `"` render escaped (assert the raw `<b>` tag is absent and `&lt;b&gt;` present); (d) null tracking → the tracking box is present but EMPTY (no "-", no "not filled" text), null phone/address → no "null"/"undefined" in the output; (e) no `overflow:hidden`, `line-clamp`, `text-overflow` anywhere in the cell or page markup; (f) `buildLabelPageHtml(pageIndices, cellHtmls)`: page 794×1123, cell 0 width 397 with left+right borders and height 562 with top+bottom, cell 1 width 396 right-only, cell 2 height 561 bottom-only, only existing labels drawn (a page with 1 label has one `[data-label]`); (g) `renderCourierLabels(labels, store, { measure, capture })` with injected `measure` returning [100, 561] throws `LabelTooTallError` with `index === 1` and does not call `capture`; with [100, 560] calls `capture` once per page (5 labels → 2 calls)
- [X] T013 Create resources/js/utils/courierLabelSheet.js (Indonesian header comment citing research D5/D6/D8): constants `PAGE_W=794, PAGE_H=1123, COL_WIDTHS=[397,396], ROW_HEIGHTS=[562,561], CONTENT_W=395, CONTENT_H=560`; `class LabelTooTallError extends Error` (fields `index`, `height`, `capacity`); pure `labelPages(count)`; `buildCourierLabelCellHtml(label, store)` — inline black-on-white styles like 067 (print document; no token classes inside the PDF markup), every value through `escapeHtml` from `./invoiceDocument`, `white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word`, layout top→bottom: courier name (large) + tracking number (large monospace, bordered box that stays empty when null), **To** block dominant (recipient name ≥ 18px bold, phone, address ≥ 14px), **From** block (store name, address, contact phone, contact person, smaller), footer `Order <preorder_number>` monospace; NO fixed height, NO clip; `buildLabelPageHtml(indices, cellHtmls)` with shared-border rules (first column also left, first row also top, as 067 D2), cell boxes `box-sizing:border-box`, fixed widths/heights from the constants; `measureLabelHeights(cellHtmls)` off-screen at `CONTENT_W` (`position:fixed;left:-9999px`, never `display:none`); `async renderCourierLabels(labels, store, { measure = measureLabelHeights, capture } = {})` → measure, throw `LabelTooTallError` for the first height > `CONTENT_H`, then `renderA4Pages(pages.map(...), { capture })`; run T012 until green

**Checkpoint**: endpoint green, 067 tests still green, label renderer unit-tested.

---

## Phase 3: User Story 1 — Single courier label from the detail drawer (Priority: P1) 🎯 MVP

**Goal**: in the "Courier shipping" section of a pre-order with a saved shipment, "Download courier label (A4)" downloads
one A4 page with the label top-left, from SAVED data; unsaved edits trigger an info toast.

**Independent test**: open a Mail Order pre-order with a full shipment, download, open the PDF — one A4 page, every field
equals the saved record (quickstart steps 1–3).

### Tests for User Story 1

- [X] T014 [US1] Add failing component tests to qa-tests/component/PreordersCourierLabels.test.js (own file, like 067 — implementation note: kept out of the 2,200-line PreordersView.test.js) (mock `api/preorders` `preorderShippingLabels` and `utils/courierLabelSheet` `renderCourierLabels`): (a) with a shipment and `preorders.export` the drawer shows `data-testid="btn-courier-label"` next to the existing slip button; (b) absent without a shipment; (c) absent when `auth.canDo('preorders','export')` is false; (d) clicking calls `preorderShippingLabels([detailId])` and `renderCourierLabels(response.data, response.store_identity)`, downloads `label-kurir-<preorder_number>.pdf`, shows the success toast with the print-at-100 % hint; (e) after editing `shipment.tracking_number` in the drawer without saving, the click still renders the SERVER values and shows the `courier_label_unsaved_ignored` info toast; (f) response with the id in `skipped` (e.g. `no_shipment` race) → error toast, no render; (g) `LabelTooTallError` → `courier_label_too_tall` toast containing the pre-order number, no download

### Implementation for User Story 1

- [X] T015 [P] [US1] Add locale keys to resources/js/locales/id.json and resources/js/locales/en.json under `preorders`: `download_courier_label` ("Unduh label kurir (A4)" / "Download courier label (A4)"), `courier_label_downloaded` (includes the print-at-100 % / actual size hint), `courier_label_unsaved_ignored` ("Perubahan pengiriman yang belum disimpan tidak ikut dicetak." / EN equivalent), `courier_label_too_tall` (`{number}` placeholder), `courier_label_failed`, `courier_label_not_available` (single pre-order has no saved courier shipment); check no key is duplicated within the section (the 025 `created_at_label` trap)
- [X] T016 [US1] In resources/js/views/PreordersView.vue add a shared helper `downloadCourierLabels(ids, fileName)` that calls `preorderShippingLabels(ids)`, lazily `import('../utils/courierLabelSheet')`, calls `renderCourierLabels(data, store_identity)`, triggers the download via an object URL (same pattern as `doBulkShippingSlips`, revoke after click) and returns `{ data, skipped }`; maps `LabelTooTallError` to `courier_label_too_tall` with `data[err.index].preorder_number`; other errors to `err.message || courier_label_failed`
- [X] T017 [US1] In resources/js/views/PreordersView.vue add `downloadCourierLabel()` for the drawer: call the helper with `[detail.id]` and `label-kurir-${preorder_number}.pdf`; if `data` is empty → `courier_label_not_available` toast (no file); otherwise compare the editable fields (`courier_name, tracking_number, recipient_name, recipient_phone, address_line`, null ≡ '') of the `shipment` ref with `data[0]` and show `courier_label_unsaved_ignored` (info) when any differ, then the success toast; comment citing research D1/D9 (the drawer edits the ref in place)
- [X] T018 [US1] Add the button in the "Courier shipping" action row of resources/js/views/PreordersView.vue right after "Download shipping slip (PDF)": `BaseButton variant="secondary" size="sm" data-testid="btn-courier-label" v-if="auth.canDo('preorders','export')"`, `:loading` bound to its own ref, Phosphor duotone icon (`ph-tag` or `ph-printer`), label `t('preorders.download_courier_label')`; run T014 until green

**Checkpoint**: US1 complete and testable alone (MVP).

---

## Phase 4: User Story 2 — Bulk courier labels from the list selection (Priority: P2)

**Goal**: "Download courier labels (A4 PDF)" in the bulk bar produces ONE PDF, 4 labels per page, pre-order number order,
and reports skipped pre-orders by number.

**Independent test**: select a mix of Mail Order with/without shipment and Self Pickup; one PDF with exactly the eligible
labels; toast lists the skipped numbers (quickstart step 4).

### Tests for User Story 2

- [X] T019 [US2] Add failing component tests to qa-tests/component/PreordersCourierLabels.test.js: (a) with a selection and `preorders.export` the bulk bar shows `data-testid="bulk-btn-courier-labels"` beside `bulk-btn-shipping-slips`; absent without `export`; (b) click sends ALL selected ids (`[...selectedIds]`) to `preorderShippingLabels`, renders `response.data`, downloads `label-kurir.pdf`; (c) with `skipped` containing numbered entries and one `not_found`, the toast lists the numbered ones and counts the `not_found` one without inventing a number; (d) empty `data` → `courier_labels_none_eligible` toast, `renderCourierLabels` not called, no download; (e) the existing shipping-slips button and flow are unchanged (still calls `bulkPreorderInvoices` + `renderShippingSlipSheet`); (f) 422 from the API (over the cap) shows the server message through the central error path

### Implementation for User Story 2

- [X] T020 [P] [US2] Add locale keys to resources/js/locales/id.json and en.json under `preorders`: `bulk_download_courier_labels` ("Unduh label kurir (A4 PDF)" / "Download courier labels (A4 PDF)"), `courier_labels_downloaded` (`{count}` + print-at-100 % hint), `courier_labels_skipped` (`{count}`, `{numbers}`), `courier_labels_skipped_hidden` (`{count}` not accessible), `courier_labels_none_eligible`
- [X] T021 [US2] In resources/js/views/PreordersView.vue add `doBulkCourierLabels()`: guard empty selection; own `bulkCourierDownloading` ref; call `downloadCourierLabels([...selectedIds.value], 'label-kurir.pdf')`; on empty `data` show `courier_labels_none_eligible` and stop (the helper must not render/download when `data` is empty — adjust T016's helper to return early before rendering); after download show `courier_labels_downloaded` and, when `skipped` is non-empty, a warning toast built from `courier_labels_skipped` (numbers joined with ", ", cap the listed numbers at 20 then "…") plus `courier_labels_skipped_hidden` for `not_found`
- [X] T022 [US2] Add the bulk button in the bulk action bar of resources/js/views/PreordersView.vue directly after the shipping-slips button (~line 1575): `v-if="auth.canDo('preorders', 'export')"`, `variant="secondary" size="sm" data-testid="bulk-btn-courier-labels" :loading="bulkCourierDownloading"`, icon `ph-duotone ph-tag`, label `t('preorders.bulk_download_courier_labels')`; run T019 until green

**Checkpoint**: US1 and US2 both work independently.

---

## Phase 5: Polish & Cross-Cutting

- [X] T023 [P] Run the i18n guard `npx vitest run --exclude '.claude/**' qa-tests/unit/localeKeys.test.js` and the full frontend suite `npx vitest run --exclude '.claude/**'`; fix regressions
- [X] T024 [P] Run the full backend suite in the container with the `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` overrides (or on the host `php -d memory_limit=2G vendor/bin/phpunit --no-progress`); fix regressions (the 9 Code128 reference-library failures are pre-existing, `picqer` is require-dev)
- [X] T025 Run `npm run build` and confirm in the build output that `courierLabelSheet`, `a4PdfPages`, `jspdf` and `html2canvas` are in lazily loaded chunks, not the entry chunk (Constitution V); note the chunk names in specs/069-courier-shipping-label-a4/quickstart.md
- [ ] T026 (PARTIAL — see quickstart.md "Real-data and render checks") Real-browser verification per specs/069-courier-shipping-label-a4/quickstart.md steps 1–9 against the dev stack (rebuild `public/build` or use `npm run dev`); check the console for errors; record the 200-label timing (SC-002 ≤ 60 s) and the result of each step in quickstart.md. If the timing exceeds 60 s, follow research D11 (lower raster scale for this document only, re-check line sharpness) and re-measure
- [ ] T027 (NOT DONE — no printer in this session) Print one sheet at 100 % / actual size and measure the cut lines (each cell ≈ 105 × 148.5 mm); record the result in quickstart.md (mark explicitly as NOT done if no printer is available — never imply it was checked)
- [X] T028 [P] Add a "Courier labels on A4 (feature 069, 2026-11-15)" section to CLAUDE.md (before "## Conventions") with the non-obvious rules: new document beside the 067 sheet; ONE endpoint for single and bulk because the drawer edits `shipment` in place; no `exists:` rule (scopes); eligibility and skip reasons; number order (spec amendment); store identity once; fixed 2×2 integer grid and refuse-not-clip; `renderA4Pages` shared with 067; what was and was not verified in a browser/on paper
- [X] T029 Self-review the diff against plan.md's Constitution Check table and research D1–D11 (single write path untouched, no new permission, hidden-not-disabled buttons, Indonesian comments/copy, OpenAPI in the same commit) before reporting done

---

## Dependencies & Execution Order

- **Phase 1 (T001)** → **Phase 2** → **US1 (Phase 3)** → **US2 (Phase 4)** → **Phase 5**.
- Inside Phase 2: T002/T003 → T004 → T005 → T006 (green); T007, T008 independent. T009 → T010 → T011. T012 → T013
  (T013 depends on T010 for `renderA4Pages`).
- US1 needs T006, T008, T013. US2 needs the same plus T016 (shared helper from US1); US2's own UI is independent of the
  drawer button (T018).
- T014/T019 both edit qa-tests/component/PreordersView.test.js and T016–T018/T021–T022 all edit PreordersView.vue →
  sequential, not [P].

### Parallel opportunities

- T002, T003, T007, T008, T009, T012 (different files) can start together after T001.
- T015 alongside T014; T020 alongside T019.
- T023, T024, T028 in parallel at the end.

## Parallel Example: Phase 2 kick-off

```text
T002 tests/Feature/PreorderShippingLabelsTest.php
T003 app/Http/Requests/PreorderShippingLabelsRequest.php
T007 docs/openapi-pos-mvp.yaml
T008 resources/js/api/preorders.js
T009 qa-tests/unit/a4PdfPages.test.js
T012 qa-tests/unit/courierLabelSheet.test.js
```

## Implementation Strategy

1. **MVP** = Phases 1–3 (endpoint, renderer, drawer button). Stop, run quickstart steps 1–3 in the browser, demo.
2. **Increment** = Phase 4 (bulk button + skipped summary). Run quickstart steps 4–7.
3. **Finish** = Phase 5 (full suites, build chunks, browser + print, CLAUDE.md note). Commit with OpenAPI in the same
   commit as the route (Constitution docs discipline); push / PR only when the user asks.

## Notes

- Never run `php artisan test` in the `app` container without the two `-e` overrides — it has wiped the dev DB twice.
- Do not change the existing single slip (`shippingSlipEl`) or the 067 sheet's output (spec FR-007).
- Downloads must not write anything (FR-012): T002 (l) guards it.
