---

description: "Task list for feature 039 — subtotal per seller in the Pre-order report"
---

# Tasks: Subtotal per Seller in the Pre-order Report

**Input**: Design documents from `/specs/039-preorder-seller-subtotal/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-seller-subtotals.md, quickstart.md

**Tests**: INCLUDED (Constitution II). Existing report tests are extended, never loosened.

**Organization**: By user story. **Foundational (Phase 2) = the single PHP implementation of the sums** (`PreorderSellerSubtotals`), because both the screen (US1, via the API) and the export (US2) consume it. US2 depends only on Phase 2, so it can run in parallel with US1.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1, US2

## Conventions to honour

- Comments, `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` (the `localeKeys` test must stay green).
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`); never inside the `app` container without the `-e` overrides; never touch the dev DB `boothpos`.
- The subtotal is **the sum of the displayed row values** (cents-exact via `ReportSplit::cents()/money()`); outstanding is the SUM of the rows' clamped outstanding values, never `value − collected`.
- `docs/openapi-pos-mvp.yaml` moves in the same commit as the response change; tokens only in CSS classes (no hex).

---

## Phase 1: Setup

- [X] T001 Baseline on `039-preorder-seller-subtotal`: run `APP_ENV=testing php artisan test --filter='PreorderReport|ReportTest'` and `npx vitest run qa-tests/component/ReportsView.test.js`; note the pass counts (expect all green).

---

## Phase 2: Foundational — one implementation of the sums

- [X] T002 [P] Create `tests/Unit/PreorderSellerSubtotalsTest.php` (plain PHPUnit `TestCase`, no DB) for `App\Support\PreorderSellerSubtotals::fromRows(array $rows): array`: the three rows of one seller sum to exactly the expected figures; two sellers → two subtotals in first-seen order (not alphabetical re-sorting); a single-row seller still gets a subtotal; `total_outstanding` is the SUM of row outstanding values — assert with a row whose collected exceeds order value (`845000.00` value, `1099000.00` collected, `0.00` outstanding) that the subtotal outstanding is `0.00` for that row's contribution, NOT `value − collected`; cents-exactness with awkward values (`0.10` + `0.20` → `0.30`, `1234.005` style inputs not expected — rows are 2-dp strings); counts are integers; empty input → `[]`; rows of a different seller in between (non-contiguous input) are still grouped by `artist_id`; result keys are exactly `artist_id, artist_name, preorder_count, total_order_value, total_collected, total_outstanding` with money as 2-dp strings. Expect FAIL before T003.
- [X] T003 Create `app/Support/PreorderSellerSubtotals.php` (Indonesian docblock: why derived/never stored, why cents via `ReportSplit`, why outstanding is a sum of clamped row values): `public static function fromRows(array $rows): array` grouping by `artist_id` in first-seen order, summing `preorder_count` (int) and the three money fields in integer cents (`ReportSplit::cents()`), formatting with `ReportSplit::money()`. Run T002.

**Checkpoint**: the arithmetic exists once and is tested.

---

## Phase 3: User Story 1 — See each seller's total at a glance on the By Seller report (Priority: P1) 🎯 MVP

**Goal**: a "Subtotal — <seller>" row after each seller's rows on screen; Grand Total unchanged and equal to Σ subtotals.

**Independent Test**: By Seller view with several sellers/rows — each seller ends with a Subtotal equal to the sum of its rows; Σ subtotals = Grand Total; seller filter and event change behave.

### Tests for US1 (write first, expect failures)

- [X] T004 [P] [US1] Extend `tests/Feature/PreorderReportTest.php` with `GET /api/v1/reports/preorders?breakdown=artist`: with ≥ 3 sellers and several statuses/payment states (reuse the file's `makePreorder()` helper, include a seller with a single row and a fully paid pre-order whose payments exceed its total if the fixtures allow, otherwise assert via two partially paid rows): the response has `rows` (existing shape, unchanged) AND `subtotals` (one per seller, same order sellers first appear in `rows`); each subtotal equals the manual sum of that seller's rows (`assertSame` on 2-dp strings and ints); Σ subtotals = Σ rows for each of the three money fields and the count; `rows` are contiguous per seller (no seller id re-appears after another seller's row), including when two different artists share the same name; `event_id` filtering and DEMO/LIVE isolation apply to `subtotals` exactly as to `rows`; no rows → `subtotals: []`; the default (non-`breakdown`) response is unchanged (no `subtotals` key).
- [X] T005 [P] [US1] Create `qa-tests/component/ReportsPreorderSubtotal.test.js` (mock the reports API like `ReportsView.test.js`; open the Pre-order tab → "By Seller" with `rows` for sellers A (3 rows), B (1 row), C (2 rows) and matching `subtotals`): after each seller's LAST data row a subtotal row appears with the label `Subtotal — <name>` and the four server figures, in that order (verify DOM order); a single-row seller also has one; the subtotal row has empty status and payment-completeness cells and NO "Detail" button, while data rows keep Detail; the subtotal row's classes differ from a data row and from the Grand Total (e.g. subtotal `font-semibold border-t`, Grand Total `font-bold border-t-2`); the Grand Total row is still last and each figure equals the sum of the subtotals shown (exactly, via the formatted strings); choosing seller B in "All sellers" shows only B's rows + B's subtotal + the Grand Total (equal to the subtotal); switching to the Summary view shows no subtotal rows; with no rows no subtotal and no Grand Total are rendered; clicking Detail on a data row still opens the detail for THAT row (a subtotal row never opens it); the label is translated (Indonesian default in tests: "Subtotal — …" is identical in both languages, so assert the `reports.subtotal` key exists in both locale files).

### Implementation for US1

- [X] T006 [US1] `app/Http/Controllers/Api/ReportController.php::preordersByArtist()`: add `->orderBy('preorder_items.artist_id')` right after the `artists.name` ordering (contiguity); keep the `rows` mapping unchanged; return `['rows' => $rows, 'subtotals' => PreorderSellerSubtotals::fromRows($rows->all())]` (import the class); update the method docblock (Indonesian, cite 039 and why subtotals are server-side). Run T004.
- [X] T007 [P] [US1] Locale key `reports.subtotal` ("Subtotal" / "Subtotal") in `resources/js/locales/en.json` and `id.json` (check the name is free in the `reports` section); run `qa-tests/unit/localeKeys.test.js`.
- [X] T008 [US1] `resources/js/views/ReportsView.vue`: (a) store `subtotals` from `loadPreorderByArtist()` (`preorderBySubtotals` ref; default `[]`); (b) make `sumRows()` cent-exact (accumulate `Math.round(parseMoney(v) * 100)` and divide by 100 at the end; integer count keys unchanged) with an Indonesian comment (grand total must equal Σ subtotals exactly); (c) new computed `preorderByArtistDisplayRows`: from `filteredPreorderByArtist` (existing seller filter) walk the rows and, after the last row of each `artist_id`, insert `{ id: 'subtotal__<artist_id>', _subtotal: true, artist_id, artist_name, preorder_count, total_order_value, total_collected, total_outstanding }` taken from `preorderBySubtotals` (skip if missing); (d) pass `preorderByArtistDisplayRows` to the by-seller `DataTable`; add a `rowClass` for it (`_subtotal` → `bg-surface-subtle font-semibold border-t border-line-2`); (e) cell slots: `#cell-artist_name` renders `t('reports.subtotal') + ' — ' + row.artist_name` in bold for a subtotal row and the plain name otherwise; `#cell-status` / `#cell-payment_completeness` render nothing for a subtotal row; `#cell-actions` renders no Detail for a subtotal row; (f) leave the Grand Total footer reading `filteredPreorderByArtist` (data rows only) and `openPreorderDetail` unchanged. Run T005 and `qa-tests/component/ReportsView.test.js`.
- [X] T009 [US1] Run `APP_ENV=testing php artisan test --filter='PreorderReport|ReportTest|PreorderSellerSubtotals'` and `npx vitest run`; all green.

**Checkpoint**: US1 demonstrable and shippable on its own.

---

## Phase 4: User Story 2 — The Excel export shows the same subtotals (Priority: P2)

**Goal**: the "Per Seller" sheet carries the same subtotal rows; the Summary sheet and file are otherwise unchanged.

**Independent Test**: export with several sellers; the Per Seller sheet has a labelled Subtotal row after each seller's rows, matching the screen to the cent.

### Tests for US2 (write first, expect failures)

- [X] T010 [P] [US2] Extend `tests/Feature/PreorderReportTest.php` (or create `tests/Feature/PreorderReportExportTest.php` if the file gets long) for the existing pre-order report export route `GET /api/v1/reports/preorder/export` (`ReportController::export` → `exportPreorderReport`): download the `.xlsx` (`->assertOk()`; read it with `PhpOffice\PhpSpreadsheet\IOFactory::load` on a temp file written from `$response->streamedContent()` or `->getFile()` as the existing import tests do) and assert: sheet names are still `Ringkasan` and `Per Seller` in that order; the `Ringkasan` sheet is byte-for-byte the same rows as before (compare against the summary JSON); the `Per Seller` sheet has the original header row (same column order) and, after each seller's last data row, exactly one row whose `artist_name` cell is `Subtotal — <seller>`, `artist_id`/`status`/`payment_completeness` cells empty, and the four figures equal to the JSON `subtotals` for that seller; seller rows are contiguous; with an `event_id` filter the sheet contains exactly the filtered sellers and subtotals; no Grand Total row is added; an event with no pre-orders produces a sheet with only the header (no subtotal rows).

### Implementation for US2

- [X] T011 [US2] `app/Http/Controllers/Api/ReportController.php::exportPreorderReport()`: after `$breakdownRows` is read, compute `PreorderSellerSubtotals::fromRows($breakdownRows)` and build the sheet rows by inserting, after the last row of each `artist_id`, `['artist_id' => null, 'artist_name' => 'Subtotal — '.$name, 'status' => '', 'payment_completeness' => '', 'preorder_count' => …, 'total_order_value' => …, 'total_collected' => …, 'total_outstanding' => …]` (the same figures as the JSON subtotals — reuse the helper's output, do not recompute); pass those rows to the `Per Seller` `SheetArrayExport`; leave `Ringkasan`, headings, sheet names and file name untouched; Indonesian comment (screen/export parity decision of 2026-10-05). Run T010.
- [X] T012 [US2] Run `APP_ENV=testing php artisan test --filter='PreorderReport|ReportTest|PreorderSellerSubtotals'`; all green.

**Checkpoint**: both stories delivered.

---

## Phase 5: Polish & Cross-cutting

- [X] T013 [P] `docs/openapi-pos-mvp.yaml`: document `subtotals` on `GET /reports/preorders?breakdown=artist` (item schema per `contracts/preorder-seller-subtotals.md`), the contiguity guarantee on `rows`, and a note on the export's Per Seller sheet subtotal rows; validate the YAML parses.
- [X] T014 [P] `CLAUDE.md`: add a short section "Pre-order report subtotals (feature 039)" next to the 033 reports section — `PreorderSellerSubtotals` is the single implementation (cents via `ReportSplit`), outstanding = Σ of clamped row values, export parity decision, client `sumRows` cent-exact, subtotal rows are synthetic display rows (never passed to Detail). Keep the Active-feature block already written.
- [X] T015 [P] `README.md` "bugs found during execution": add an entry only if a real defect is found while implementing/verifying (candidates: same-name sellers interleaving rows — fixed by the extra sort key; collected > order value on a Paid row — record as observed, not fixed); otherwise skip.
- [X] T016 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; both green; report any pre-existing failure separately.
- [X] T017 Real-browser verification per `quickstart.md` on an ISOLATED server (`APP_ENV=testing php artisan serve --port=8091`, test DB, `license:dev-activate` on the TEST DB only, `npm run build` first): seed (scratch script via tinker, using `PreorderService`/factories) an event with ≥ 4 sellers and pre-orders across statuses and payment states, a seller with one row, a seller sharing a name with another, and a paid pre-order whose collected exceeds its total if reproducible; then check quickstart items 1–5 (subtotal rows and styling; hand-addition = subtotal; Σ subtotals = Grand Total for all four figures incl. the clamped row; single-seller filter; event change; empty event; export Per Seller sheet vs screen via reading the downloaded xlsx; EN ↔ ID; console clean). Screenshots (no customer data) to `specs/039-preorder-seller-subtotal/evidence/`.
- [X] T018 Remove scratch files (`.playwright-mcp`, scratchpad seed scripts, downloaded xlsx), mark every completed task `[X]` here (`python3 <scratchpad>/mark.py T001 …` follows `.specify/feature.json`), and prepare the commits (Indonesian: docs commit for the spec set, then the feature commit) — commit only on the user's request; push/PR/merge only when explicitly asked, using the personal GitHub account (`gh` active account `kurniawanchan`; push with `git -c credential.helper= -c 'credential.helper=!gh auth git-credential' push`).

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 → T003) → US1 and US2. **US2 needs only Phase 2** and edits `ReportController` in a different method than US1 (T006 vs T011) but the SAME file, so run T006 before T011 (or merge carefully); the test tasks (T004, T005, T010) are in different files and can be written in parallel.
- Within US1: T004 ∥ T005 ∥ T007 (tests/locale first), then T006, then T008 (T008 needs T007 and benefits from T006 for end-to-end).
- Polish after both stories; T017 needs the built SPA.

### Parallel opportunities

- T002 ∥ T004 ∥ T005 ∥ T007 ∥ T010 (different files); T013 ∥ T014 ∥ T015.

## Implementation Strategy

- **MVP = Phases 1–3 (US1)**: subtotals on screen, with the shared arithmetic in place; US2 (export parity) follows immediately since it reuses the same class.
- Stop at each checkpoint and run the phase's tests plus the T001 baseline; never loosen an assertion — change an existing report assertion only where it pins the exact row list of the By Seller table or sheet.
