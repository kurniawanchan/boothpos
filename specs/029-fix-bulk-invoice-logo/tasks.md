---

description: "Task list for Correct Images on Downloaded Invoices"
---

# Tasks: Correct Images on Downloaded Invoices

**Input**: Design documents from `/specs/029-fix-bulk-invoice-logo/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–2), data-model.md (no data changes), quickstart.md

**Tests**: INCLUDED. Constitution II requires tests; the original test hid the bug (its simulated cloned page held only the target element), so the regression tests here deliberately use a page with decoy images. A real-browser check is mandatory.

**Organization**: One shared change in `resources/js/utils/pdfCapture.js` serves every story, so it sits in the Foundational phase; the story phases add the story-specific tests and verification — US1 Bulk invoices show the logo (P1) · US2 Independent of the page (P1) · US3 Every downloadable document (P2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US3, only on user-story phase tasks
- Code comments and commit messages in Indonesian (project convention); no new UI strings are needed

## Safety reminders

- Browser verification uses an ISOLATED server + `boothpos_test`, never the dev database (`php artisan serve --port=<other>` with `.env.testing` sourced; never run `migrate:fresh`/`db:wipe`/`db:seed` against the dev DB).
- The Docker VM disk has filled before and frozen MySQL; check free space before long runs.
- `npm run build` writes `public/build/` (gitignored); rebuild before any browser check of the fix.

---

## Phase 1: Setup

**Purpose**: Confirm a safe, green baseline. No dependency changes in this feature.

- [X] T001 Check Docker disk space (`docker compose exec mysql df -h /var/lib/mysql` must show free space), then run `npx vitest run` and record the passing counts so any later failure is attributable (`qa-tests/`)

---

## Phase 2: Foundational — the shared fix (blocks every user story)

**Purpose**: All three stories are served by ONE change in the shared capture helper, so it is done first: reproduce the defect, write the tests that fail on the old positional code, then fix it.

**⚠️ CRITICAL**: The user-story phases below only verify and guard the fix for their slice; they cannot start before this phase is done.

- [X] T002 Reproduce the defect on the CURRENT build (before any change) in a real browser against an ISOLATED server + `boothpos_test` (seed with `db:seed` + `license:dev-activate`; upload a store logo, create a QR payment channel with an image and 2–3 pre-orders; serve on another port): open Pre-orders with at least one other `<img>` on the page (inject a decoy `<img>` into the header if needed), bulk-download invoices, wrap `HTMLCanvasElement.prototype.toDataURL` to keep each rendered canvas, show them in an overlay and screenshot — confirm the QR image sits in the logo slot, as in `invoice-PO-20261001-0004.pdf`. Save the screenshot under `.playwright-mcp/` as the before-evidence
- [X] T003 [P] Rewrite the capture tests in `qa-tests/unit/pdfCapture.test.js` so they FAIL on the old code: make the fetch mock return different bytes per URL, and simulate `onclone(clonedDoc, clonedReferenceElement)` with a cloned page that has decoy `<img>` elements BEFORE, BETWEEN and AFTER the document's own images. Assert: the logo `<img>` receives the logo's data URL, the QR `<img>` the QR's, decoys are untouched, the live element's `src` values are untouched; a failed fetch leaves that image's original `src` (others still swapped, capture still resolves); the same URL used twice is fetched once and both elements get the same data; a document with no images still resolves; when a reference element is passed only images inside it are touched. Keep the existing PDF/PNG helper cases
- [X] T004 Fix `captureElementCanvas()` in `resources/js/utils/pdfCapture.js`: collect the document's images, fetch each DISTINCT `src` once into a `Map<rawSrcAttribute, dataUrl>` (failed fetches get no entry), and in `onclone(clonedDoc, clonedRoot)` iterate `(clonedRoot ?? clonedDoc).querySelectorAll('img')` and replace an image's `src` only when its own raw `src` attribute is in the map — never by index. Replace the file's comment block with an Indonesian `BUG YANG DITEMUKAN & DIPERBAIKI` note explaining the page-wide-clone / positional-match root cause and why identity keying is correct (research.md Decisions 1–2); keep the existing cross-origin/taint explanation
- [X] T005 Run `npx vitest run qa-tests/unit/pdfCapture.test.js` and then the full `npx vitest run`; fix until green

---

## Phase 3: User Story 1 — Bulk-downloaded invoices show the store logo (Priority: P1) 🎯 MVP

**Goal**: Every PDF in a multi-selection invoice download carries the store logo at the top and the QR code only in the payment section.

**Independent Test**: With a logo and a QR channel configured, bulk-download 2+ invoices and confirm every PDF's top image is the logo.

### Tests for User Story 1 ⚠️ write first

- [X] T006 [P] [US1] Extend `qa-tests/unit/invoiceDocument.test.js`: `buildInvoiceHtml()` places the store-logo `<img>` in the header block BEFORE any QR image, QR images appear only inside the payment-methods section next to their provider names, and with no `logo_url` the header contains no `<img>` (and with no QR image the payment section contains none) — the structure the capture step relies on, for both `invoice` and `payment_invoice` documents and for `buildShippingSlipHtml()`
- [X] T007 [P] [US1] Add a bulk-download flow test to `qa-tests/component/PreordersView.test.js` (mock `bulkPreorderInvoices`, `jszip`, `jspdf` and `utils/pdfCapture`; render the view with a decoy `<img>` on the page): selecting two rows and clicking the bulk download calls `captureElementCanvas` once per invoice, sequentially, with a container whose HTML holds THAT invoice's logo and QR (inspect `container.innerHTML` at call time), removes each container afterwards, and adds one PDF per invoice to the zip

### Implementation / verification for User Story 1

- [X] T008 [US1] Rebuild (`npm run build`) and repeat the real-browser bulk-invoice download from the Foundational reproduction step: every rendered invoice canvas now shows the store logo at the top and the QR only under "Cara pembayaran"; also with NO logo configured (slot empty, no QR in it) and with a single selected pre-order; save the after-screenshots under `.playwright-mcp/`
- [X] T009 [US1] Run `npx vitest run qa-tests/unit/invoiceDocument.test.js qa-tests/component/PreordersView.test.js`; fix until green

---

## Phase 4: User Story 2 — The result does not depend on what else is on the screen (Priority: P1)

**Goal**: The images in a downloaded document are identical whether the page shows 0, 1 or many other pictures, and no extra downloads are introduced.

**Independent Test**: Capture the same invoice with 0, 1 and 20 decoy images on the page and compare — identical.

### Tests for User Story 2 ⚠️ write first

- [X] T010 [P] [US2] Add to `qa-tests/unit/pdfCapture.test.js`: a parameterised case that runs the capture + `onclone` simulation with 0, 1 and 20 decoy images before the document and asserts the logo/QR `src` results are identical in all three; a case with decoys that share a URL with the document's own images (they may receive that same URL's data, but never another URL's); a case asserting `fetch` is called exactly once per DISTINCT image URL (SC-003: no extra fetches)

### Implementation / verification for User Story 2

- [X] T011 [US2] In the real browser, bulk-download the same invoices with 0, 1 and ~20 decoy `<img>` elements injected into the page (header, list area and an open detail drawer) and compare the rendered canvases (e.g. a data-URL hash per canvas): all identical and correct; time a 10-invoice bulk download before/after if the isolated data allows (no more than +10%, SC-003)
- [X] T012 [US2] Run `npx vitest run qa-tests/unit/pdfCapture.test.js`; fix until green

---

## Phase 5: User Story 3 — The same guarantee for every downloadable document (Priority: P2)

**Goal**: Pre-order invoice, payment invoice, shipping slip, POS receipt, purchase order and billing invoice (single and bulk) all place the right image in the right slot.

**Independent Test**: Download each document type from a screen that also contains other pictures and confirm only its own images appear.

### Verification for User Story 3

- [X] T013 [P] [US3] Confirm every call site still hands the helper its own document element and needs no change: `PreorderInvoiceModal.vue`, `PreorderPaymentReceiptModal.vue`, `ReceiptModal.vue`, `PurchaseOrderDetailModal.vue`, `InvoiceDetailModal.vue` (via `downloadElementAsPdf/Png`) and the two bulk loops + shipping slip in `PreordersView.vue` (via `captureElementCanvas`); run `npx vitest run qa-tests/component/PreorderInvoiceModal.test.js qa-tests/component/PreorderPaymentReceiptModal.test.js qa-tests/component/ReceiptModal.test.js qa-tests/component/InvoicesView.test.js` and fix any regression (no source change is expected here)
- [X] T014 [US3] In the real browser, with a decoy `<img>` on the page, download and inspect: a single pre-order invoice (modal), a single payment invoice, the bulk payment-invoice download, the bulk shipping-slip download, and the POS sales receipt — each shows only its own images; spot-check the billing invoice and purchase-order downloads (they share the same helper and the unit tests cover it); record the results

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T015 [P] Add one bullet to the Frontend section of `CLAUDE.md` stating the rule: document downloads go through `utils/pdfCapture.js`; images are swapped into html2canvas's clone by their own `src`, NEVER by index, because `onclone`'s first argument is a clone of the whole page (keep the SPECKIT plan pointer as is)
- [X] T016 Run the full frontend suite `npx vitest run` and `npm run build`; record counts and fix any regression (no backend change, so the backend suite is not required, but run `php artisan test --filter=Preorder` once on the host as a sanity check)
- [X] T017 Final diff review against the constitution: the fix lives only in the shared helper (no per-screen workaround), no new network surface, taint protection retained, Indonesian `BUG YANG DITEMUKAN & DIPERBAIKI` comment present, tests include the decoy-image regression
- [ ] T018 Ask the reporter to re-download the bulk invoices from the real page (the original `invoice-PO-20261001-0004.pdf` scenario) and confirm the logo slot now shows the store logo; note their confirmation in the final report


## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories → Polish.** Phase 2 contains the whole fix; nothing after it needs production-code changes unless a story's verification finds a gap.
- Inside Phase 2: reproduce (T002) → failing tests (T003) → fix (T004) → green run (T005).
- **US1** and **US2** are both P1 and independent of each other after Phase 2; **US3** verifies the remaining document types and can run alongside them.
- Same-file serialisation: `qa-tests/unit/pdfCapture.test.js` (T003, US2 test), `qa-tests/component/PreordersView.test.js` (US1 test), `resources/js/utils/pdfCapture.js` (T004 only).

## Parallel examples

- After T005: the US1 builder test (`invoiceDocument.test.js`), the US1 `PreordersView` flow test and the US2 `pdfCapture` cases touch different files and can be written together.
- US3's component-test run and its browser spot checks are independent of US1/US2 verification.

## Implementation strategy

1. **MVP**: Phases 1–3 — reproduce, fix the helper, prove the bulk invoice shows the logo. Stop and validate with the reporter's scenario.
2. + **US2** (independence from page content, no extra fetches), then **US3** (the other documents) — mostly verification, since the fix is shared.
3. Polish, then the reporter re-downloads the real invoices to confirm.

### Notes

- Commit as one Indonesian-message commit (plus the spec docs commit); do not push without explicit instruction.
- If the browser check finds an image problem that is NOT the positional swap (e.g. a different layer), stop and add a task rather than widening this fix silently.
