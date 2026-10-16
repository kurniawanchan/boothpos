# Tasks: Product Segment Length (1–3) and SKU Format

**Input**: `specs/042-product-segment-1-3/` (plan.md, spec.md, research.md, data-model.md, contracts/product-segment.md, quickstart.md)

**Tests**: INCLUDED. Constitution Principle II requires backend changes to ship with tests (MySQL) and the screen change needs a Vitest unit plus a browser check.

**Test commands (safe)**: never run tests in the `app` container without the overrides (CLAUDE.md wipe warning):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='ProductTest|MasterDataImportTest|CodeAllocatorTest|LiveToDemoClonerTest'`
Frontend: `npm test`.

**Organization**: by user story, P1 first (US1, US2), then P2 (US3, US4). Shared rule `App\Support\ProductSegment` is Foundational because every story and the demo clone depend on it.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US4 map to spec.md user stories

---

## Phase 1: Setup

- [X] T001 Baseline: run the safe test command above before any change; record pass counts and any pre-existing failures (`tests/Feature/ProductTest.php`, `tests/Feature/MasterDataImportTest.php`, `tests/Feature/DemoClone/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: one definition of the segment rule (plan Design §1). MUST complete before any user story.

- [X] T002 Create `App\Support\ProductSegment` in `app/Support/ProductSegment.php` with: `PATTERN = '/^[A-Z]{1,3}$/'`, `CODE_PATTERN = '/^[A-Z]{3}-[A-Z]{2}-[A-Z]{1,3}$/'`, `PATTERN_RULE` (the regex string for the FormRequest), and `normalise(?string $value): ?string` (trim + uppercase; blank → null). Indonesian docblock stating why it is the single source (five copies had drifted)
- [X] T003 [P] Create `tests/Unit/ProductSegmentTest.php`: accepts `S`, `SH`, `SPF`; rejects `''`-after-normalise, `ABCD`, `S1`, `S-`; `normalise(' spf ')` = `SPF`; `CODE_PATTERN` accepts `SPF-SH-S` and rejects `SPF-SH-` and `SPF-SH-ABCD`

**Checkpoint**: rule defined and unit-tested.

---

## Phase 3: User Story 1 — Enter a short segment (Priority: P1)

**Goal**: a 1–3 letter segment is accepted, stored as typed (uppercase), not padded; the preview shows the same code.
**Independent Test**: segment `S`, artist SPF, category SH → saved as `S`, preview `SPF-SH-S-001`.

- [X] T004 [US1] In `app/Http/Requests/StoreProductRequest.php`: `product_segment` rule → `['nullable', 'string', 'regex:' . ProductSegment::PATTERN_RULE]`; add `prepareForValidation()` that normalises a non-blank `product_segment` with `ProductSegment::normalise` so the regex sees the stored form; keep `alpha` out (regex covers letters)
- [X] T005 [US1] In `app/Http/Controllers/Api/ProductController.php` `store()`: store `ProductSegment::normalise($data['product_segment'])` when typed; no padding; derived path unchanged (`deriveSegmentFromName`)
- [X] T006 [US1] Tests in `tests/Feature/ProductTest.php`: segment `S` saved as `S`; lowercase `spf` stored as `SPF`; `ABCD` → 422 with error on `product_segment`; `S1` → 422; nothing saved on 422
- [X] T007 [US1] Create `resources/js/utils/productSegment.js` exporting `previewSegment(typed, name)`: typed value trimmed, uppercased, sliced to 3, **not padded**; blank → derived 3 letters padded with `X` (same as `deriveSegment` in ProductsView today)
- [X] T008 [US1] In `resources/js/views/ProductsView.vue`: replace `effectiveSegment` (line ~207) with `previewSegment(productForm.product_segment, productForm.name)`; `codePreview` keeps `…-001`; remove the local `deriveSegment` if it is now unused (line ~203)
- [X] T009 [P] [US1] Vitest `qa-tests/unit/productSegment.test.js`: `previewSegment('S','Conan')` = `S`; `previewSegment('','Conan')` = `CON`; `previewSegment('','Ai')` = `AIX`; `previewSegment('spf','x')` = `SPF`; `previewSegment('ABCD','x')` = `ABC`

**Checkpoint**: US1 testable on its own (backend tests T006 + preview T009).

---

## Phase 4: User Story 2 — The SKU follows the segment exactly (Priority: P1)

**Goal**: SKU = `code_prefix` + `-` + 3-digit sequence, with the segment as typed.
**Independent Test**: segment `S` → first variant `SPF-SH-S-001`, second `SPF-SH-S-002`; segment `SPF` → `SPF-SH-SPF-001`.

- [X] T010 [US2] In `app/Services/ProductCodeGenerator.php` `buildCodePrefix()`: replace `strlen($prefix) !== 10` with `! preg_match(ProductSegment::CODE_PATTERN, $prefix)` (same `InvalidArgumentException`); keep the cross-mode collision check unchanged; `deriveSegmentFromName` unchanged
- [X] T011 [US2] Tests in `tests/Feature/ProductTest.php`: create with segment `S` → SKUs `SPF-SH-S-001` and (second variant) `SPF-SH-S-002`; segment `SPF` → `SPF-SH-SPF-001`; a stored `code_prefix` of `SPF-SH-S` reads back unchanged (CHAR round trip)
- [X] T012 [US2] In `app/Services/DemoClone/CodeAllocator.php` `productPrefix()`: replace the regex `/^([A-Za-z]{3})-([A-Za-z]{2})-([A-Za-z]{3})$/` with the same shape using `ProductSegment::PATTERN` for the segment (1–3 letters), keeping case-insensitive input (uppercase the value before matching against `CODE_PATTERN`)
- [X] T013 [US2] Tests in `tests/Feature/DemoClone/CodeAllocatorTest.php`: `productPrefix('SPF-SH-S', …)` succeeds and returns the demo-coded prefix; `productPrefix('SPF-SH-ABCD', …)` still throws `CloneException`
- [X] T014 [US2] Test in `tests/Feature/DemoClone/LiveToDemoClonerTest.php`: a LIVE product with segment `S` clones without `CloneException`; its demo SKU keeps the segment (`…-S-001`)
- [X] T015 [US2] Test in `tests/Feature/ProductTest.php`: a segment that would duplicate an existing `code_prefix` (`SPF-SH-S` already used, including a DEMO product) is refused 422 on `product_segment`; a different short segment (`SX`) is accepted

**Checkpoint**: US2 testable on its own.

---

## Phase 5: User Story 3 — Blank segment still derives from the name (Priority: P2)

**Goal**: blank segment derives 3 letters, padded with `X`, unchanged.
**Independent Test**: name "Conan", blank → `CON` (`SPF-SH-CON-001`); name "Ai", blank → `AIX`.

- [X] T016 [US3] Test in `tests/Feature/ProductTest.php`: blank segment, name "Conan" → `CON`; name "Ai" → `AIX`; existing `test_segment_is_derived_from_name_when_not_provided` still passes (update only if its expectation changed)
- [X] T017 [P] [US3] Covered by T009 (`previewSegment` blank cases) — confirm passing in `qa-tests/unit/productSegment.test.js`; no new file

---

## Phase 6: User Story 4 — Excel import uses the same rule (Priority: P2)

**Goal**: import accepts 1–3 letters; rejects 4+ or non-letters with a row error; blank derives.
**Independent Test**: import row segment `S` → created `SPF-SH-S-001`; row `ABCD` → row error, whole import rolled back.

- [X] T018 [US4] In `app/Services/MasterDataImportService.php` (~lines 722–731): normalise the typed segment with `ProductSegment::normalise`, validate with `ProductSegment::PATTERN`; error text `"Segmen produk '{$segment}' harus 1–3 huruf."`; blank still derives from the name; the deferred-collision branch is unchanged
- [X] T019 [US4] Tests in `tests/Feature/MasterDataImportTest.php`: row with segment `S` is imported and gets `SPF-SH-S-001`; row with `ABCD` reports a `product_segment` row error and writes nothing (rollback); the shipped template still imports as-is (`test_the_shipped_template_imports_as_is`)

**Checkpoint**: US4 testable on its own.

---

## Phase 7: Copy, docs and locale

- [X] T020 [P] Update `resources/js/locales/en.json` (`segment_3` → `"Segment (1–3)"`, `product_label` → `"product · 1–3"`) and `resources/js/locales/id.json` (`segment_3` → `"Segmen (1–3)"`, matching `product_label`); keep `qa-tests/unit/localeKeys.test.js` passing
- [X] T021 [P] Update `docs/openapi-pos-mvp.yaml`: `product_segment` schema on the product create request (`type: string`, `minLength: 1`, `maxLength: 3`, `pattern: '^[A-Za-z]{1,3}$'`, blank derives from name); update the line ~904 description to say the segment is 1–3 letters
- [X] T022 [P] Update `resources/js/views/ProductsView.vue` line ~675: label is already `master_data.segment_3`; keep `maxlength="3"`; confirm no `X` placeholder hint contradicts the new rule

---

## Phase 8: Polish & cross-cutting concerns

- [X] T023 Run the backend suites with the safe command (`tests/Feature/ProductTest.php`, `tests/Feature/MasterDataImportTest.php`, `tests/Feature/DemoClone/`); record pass counts
- [X] T024 Run `npm test`; all Vitest files pass, including `qa-tests/unit/productSegment.test.js` and `qa-tests/unit/localeKeys.test.js`
- [X] T025 Run `vendor/bin/pint` on `app/Support/ProductSegment.php`, `app/Http/Requests/StoreProductRequest.php`, `app/Http/Controllers/Api/ProductController.php`, `app/Services/ProductCodeGenerator.php`, `app/Services/DemoClone/CodeAllocator.php`, `app/Services/MasterDataImportService.php`, one file at a time (`docker compose exec -T app vendor/bin/pint --test <file>`). Known pre-existing issues: `ProductController.php` (method `searchVariants`, not touched here) and `MasterDataExportService.php` (`ProductVariantBomLine` FQN). Do not reformat unrelated code; revert any hunk outside this feature
- [X] T026 Browser check (manual) on `resources/js/views/ProductsView.vue`: Products → New; segment `S` previews `SPF-SH-S-001`; save; list and SKU show `SPF-SH-S-001`; segment `SPFX` shows the field error; console has no errors (quickstart §2)
- [X] T027 Manual: demo clone simulation (`php artisan app:clone-live-to-demo`, `app/Console/Commands/CloneLiveToDemo.php`; default simulate, nothing written) completes with a short-segment LIVE product (quickstart §4)
- [X] T028 Confirm `CLAUDE.md` SPECKIT block points to `specs/042-product-segment-1-3/plan.md` (already done; verify only)

---

## Dependencies & Execution Order

- **Setup (T001)** → **Foundational (T002–T003)** blocks all stories.
- **US1 (T004–T009)**: T004 and T005 after T002; T007 before T008; T009 after T007; T006 after T004/T005.
- **US2 (T010–T015)**: after T002; T010, T012 are independent of US1 (different files) — can run in parallel with US1 backend.
- **US3 (T016–T017)**: after US1 and US2 (it tests the same generator/preview).
- **US4 (T018–T019)**: after T002 (independent of US1–US3 otherwise).
- **Copy/docs (T020–T022)**: `[P]`, any time after US1 shape is settled.
- **Polish (T023–T028)**: last.

### Parallel opportunities
- T003 (unit), T009 (Vitest), T010/T012 (generator and clone), T018 (import), T020/T021 (locale and OpenAPI) — all different files.

---

## Implementation Strategy

### MVP (this feature)
1. T001 → T002 → T004–T009 (US1): a short segment saves and previews correctly.
2. US2 (T010–T015): SKUs and the demo clone — required before release, because the clone is the highest-impact call site.
3. Then US3, US4, copy, polish.

### Totals
- 28 tasks, all open. Per story: Foundational 2, US1 6, US2 6, US3 2, US4 2, copy/docs 3, polish 6.

---

## Notes
- Every task names an exact file path.
- Existing products' codes and SKUs must not change (spec FR-009); no migration (plan Summary).
- Commit only when asked. The branch is stacked on the unpushed `049-product-export-columns` commit.
