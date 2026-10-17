# Tasks: Remove the Length Limit on Product Segment (6 characters, single hyphens allowed)

**Input**: `specs/046-segment-length-unlimited/` (plan.md, spec.md, research.md, data-model.md, contracts/product-segment.md, quickstart.md)

**Prerequisites**: plan.md (required), spec.md (required for user stories)

**Tests**: Included. The constitution (Testing Standards) requires every changed rule to have a test, and quickstart.md lists the suites.

**Organization**: Grouped by user story. Foundational work (rule, constants, migration) blocks every story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 or US2 (from spec.md)

## Test commands

- Backend (inside the `app` container, test overrides required by CLAUDE.md):
  `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>`
- Frontend (host): `npm test`

---

## Phase 1: Setup

- [X] T001 Confirm the test baseline: run `ProductSegmentTest|ProductTest|ProductSegmentChangeTest|VariantSkuTest|MasterDataImportTest|CodeAllocatorTest|LiveToDemoClonerTest` with the test overrides and record the pass count before any change

---

## Phase 2: Foundational (blocks all user stories)

- [X] T002 Update `app/Support/ProductSegment.php`: segment rule becomes letter groups separated by single hyphens, at most 6 characters including hyphens (`PATTERN` = `/^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$/`); `CODE_PATTERN` and `PATTERN_RULE` built from the same segment rule (research Decision 9); add `MAX_LENGTH = 6`, `MAX_CODE_LENGTH = 13`, `MAX_SKU_LENGTH = 20`; update the docblock (spec 042 wording → spec 046)
- [X] T003 Create migration `database/migrations/2026_11_19_000001_widen_product_segment_and_sku_columns.php`: drop `product_variants_active_sku_unique`, drop `active_sku`, `products.product_segment` → `CHAR(6)`, `products.code_prefix` → `CHAR(13)`, `product_variants.sku` → `CHAR(20)`, `order_items.sku_snapshot` and `preorder_items.sku_snapshot` → `CHAR(20)`, re-add `active_sku CHAR(20)` generated stored (same expression, `AFTER sku`), re-add unique index `product_variants_active_sku_unique`; `down()` reverses in reverse order with the old widths; docblock notes the rollback caveat (research Decision 6)
- [X] T004 [P] Create `tests/Unit/ProductSegmentTest.php` cases (extend the existing file): accepts `SHOP`, `SH-K`, `A-B-C`, `ABCDEF`; refuses `ABCDEFG`, `-SH`, `SH-`, `S--K`, `SH K`, `SH1`, `SH_K`; `CODE_PATTERN` accepts `SPF-SH-SH-K` and refuses `SPF-SH-ABCDEFG`
- [X] T005 Run the migration on `boothpos_test` (`migrate:fresh --seed` with test overrides) and check with `information_schema` that the four widths and the `active_sku` generated column are as data-model.md says; then run `migrate:rollback --step=1` and `migrate` again to confirm both directions (quickstart.md)

**Checkpoint**: Rule, constants and columns are in place. US1 and US2 can start.

---

## Phase 3: User Story 1 - Longer segment for a product code (Priority: P1)

**Goal**: A product may use a segment of up to 6 characters, including one with a single hyphen, in create, edit, segment change, import, DEMO clone and export.

**Independent Test**: Create a product with segment `SH-K`, save, and see `SPF-SH-SH-K` and SKU `SPF-SH-SH-K-1`. Import a products sheet with the same segment and it is accepted.

### Tests for User Story 1

- [X] T006 [P] [US1] Add feature tests to `tests/Feature/ProductTest.php` (near the spec 042 segment tests, ~line 114): create with `SHOP` and with `SH-K` → code and first variant SKU use the segment
- [X] T007 [P] [US1] Add a feature test to `tests/Feature/ProductSegmentChangeTest.php`: change to `SH-K` regenerates every active SKU with its number kept (last hyphen group is the number)
- [X] T008 [P] [US1] Add a feature test to `tests/Feature/MasterDataImportTest.php`: a products row with a hyphenated 4–6 character segment is accepted; a 7-character one is refused with the new message
- [X] T009 [P] [US1] Add a test to `tests/Feature/DemoClone/CodeAllocatorTest.php`: `productPrefix('ART-CA-SH-K', …)` returns the segment `SH-K` whole (guards the `explode` limit fix)
- [X] T010 [P] [US1] Add or update tests in `tests/Feature/MasterDataExportTest.php` for the `code` / `item_label` value per the product owner's choice on research Decision 10 (default: text after the category code, e.g. `SH-K-1`); replace the `substr(-7)` expectation

### Implementation for User Story 1

- [X] T011 [US1] `app/Http/Requests/StoreProductRequest.php` line ~31: message `'Segmen produk harus 1 sampai 3 huruf (A–Z).'` → "1 sampai 6 karakter (huruf A–Z, boleh tanda hubung tunggal di antara huruf)."; the rule itself keeps `PATTERN_RULE`
- [X] T012 [US1] `app/Http/Requests/UpdateProductRequest.php` line ~30: same message change
- [X] T013 [US1] `app/Services/ProductCodeGenerator.php`: docblock (lines ~27–54) and the `InvalidArgumentException` message (line ~54) → 6 characters with hyphens; `deriveSegmentFromName` unchanged (still 3 letters)
- [X] T014 [US1] `app/Services/MasterDataImportService.php` lines ~725–729: segment check uses `ProductSegment::PATTERN`; message → "harus 1 sampai 6 karakter …"; comment at ~725 updated
- [X] T015 [US1] `app/Services/DemoClone/CodeAllocator.php`: line ~56 `explode('-', $livePrefix)` → `explode('-', $livePrefix, 3)` so the segment keeps its hyphen; line ~50 comment and line ~54 message `(segmen 1-3 huruf)` → 6 characters
- [X] T016 [US1] `app/Services/MasterDataExportService.php`: replace `skuTail()` (lines ~261–263, `substr($sku, -7)`) with the chosen rule (default: text after the category code, research Decision 10); update the docblock at ~255–260 and the callers at lines ~226 and ~237 (`item_label`). Depends on the owner's choice; do not start until confirmed.
- [X] T017 [US1] `resources/js/utils/productSegment.js` `previewSegment`: keep letters and single hyphens, uppercase, cut to 6 characters (line ~18–19); `deriveSegment` unchanged; update the header comment (lines ~7–9)
- [X] T018 [P] [US1] `qa-tests/unit/productSegment.test.js`: previewSegment expectations (6 characters, hyphen kept, other symbols dropped)
- [X] T019 [US1] `resources/js/views/ProductsView.vue` line ~724: segment `BaseInput` `maxlength="3"` → `maxlength="6"`
- [X] T020 [P] [US1] `resources/js/locales/en.json` line ~355 `"segment_3": "Segment (1–3)"` → `"Segment (1–6)"`; `resources/js/locales/id.json` line ~355 `"Segmen (1–3)"` → `"Segmen (1–6)"`
- [ ] T021 [P] [US1] `qa-tests/component/ProductsView.test.js`: the segment input has `maxlength` 6 and the code preview shows a hyphenated segment

**Checkpoint**: US1 works end to end (create, edit, change, import, clone, export, form).

---

## Phase 4: User Story 2 - Clear refusal when the code is too long (Priority: P2)

**Goal**: Code longer than 13 or SKU longer than 20 is refused with a message on the field; nothing is saved.

**Independent Test**: Enter a segment that makes a regenerated SKU 21 characters and save; the message appears on the segment field and nothing changes.

### Tests for User Story 2

- [X] T022 [P] [US2] `tests/Feature/VariantSkuTest.php`: replace the 14-character test (the "longer than fourteen characters" case) with 20 accepted and 21 refused; the message names 20 characters
- [X] T023 [P] [US2] `tests/Feature/ProductSegmentChangeTest.php`: a segment change where a regenerated SKU would exceed 20 → 422 on `product_segment`, and no SKU, code or segment changes (check the database after the refusal)
- [X] T024 [P] [US2] `tests/Feature/ProductTest.php`: a 7-character segment and a hyphen at the start are refused with the message on `product_segment`
- [X] T025 [P] [US2] `qa-tests/unit/variantSkuSplit.test.js`: `maxSequenceLength` expectations use 20 (not 14)

### Implementation for User Story 2

- [X] T026 [US2] `app/Services/ProductCodeGenerator.php` lines ~150–153 in `nextVariantSku()`: `strlen($candidate) > 14` → `ProductSegment::MAX_SKU_LENGTH`; the comment and RuntimeException text (14 → 20). Runs after T013 (same file).
- [X] T027 [US2] `app/Services/VariantSkuService.php`: line ~19 docblock (14 → 20); lines ~114–116 and ~224–226: `strlen > 14` → `ProductSegment::MAX_SKU_LENGTH`, messages "melebihi 14 karakter" → "melebihi 20 karakter"
- [X] T028 [US2] `app/Services/ProductSegmentChangeService.php`: after `assignSkus()` and before the `update` of the product, compute the longest new SKU; if any is longer than `ProductSegment::MAX_SKU_LENGTH`, throw `ValidationException::withMessages(['product_segment' => ['Segmen ini membuat SKU lebih dari 20 karakter. Gunakan segmen yang lebih pendek.']])` so the transaction rolls back (contracts/product-segment.md)
- [X] T029 [US2] `resources/js/utils/productSegment.js` `maxSequenceLength`: `14 - code.length - 1` → `20 - code.length - 1` (line ~52–55) and its comment; depends on T017 (same file)

**Checkpoint**: Refusals show on the right field and nothing is written.

---

## Final Phase: Polish & Cross-Cutting

- [X] T030 Update `docs/openapi-pos-mvp.yaml` per contracts/product-segment.md: line ~905 (segment 1–3 → 6 characters with hyphens), line ~5997 `sku` minLength/maxLength 14 → 20, line ~6052 `code_prefix` maxLength 10 → 13 (minLength 8 → 8 stays), line ~6053 `product_segment` pattern and maxLength 3 → 6. Same commit as the route/response changes (PRD §9.5).
- [X] T031 Run `ProductSegmentTest`, `ProductTest`, `ProductSegmentChangeTest`, `VariantSkuTest`, `VariantRemovalTest`, `InactiveVariantNumberTest`, `MasterDataImportTest`, `MasterDataExportTest`, `CodeAllocatorTest`, `LiveToDemoClonerTest`, `CloneRegistryDriftTest` with the test overrides; all must pass
- [X] T032 Run the full backend suite with the test overrides and `npm test`; record the counts (previous: 1268 backend, 786 frontend)
- [X] T033 Run Pint on the changed PHP files only (`ProductSegment.php`, the two form requests, the four services, the new migration and test files). Note pre-existing failures already on HEAD (ProductController.php, ProductVariant.php, routes/api.php, PreorderExportImportService.php, ProductTest.php) and do not reformat those.
- [ ] T034 Manual check per quickstart.md in DEMO mode (browser): segment `SH-K` saved; 7-character segment refused on the field; a segment change that would exceed 20 refused. Needs the user's go-ahead for a browser session.

---

## Dependencies & Execution Order

- **Phase 2 blocks everything**: T002 before T003–T005; T002 and T003 before any US task.
- **US1 and US2** both touch `ProductCodeGenerator.php` (T013 then T026), `productSegment.js` (T017 then T029) and `ProductTest.php` (T006 and T024 are both additions — merge carefully). US2 can start once T002 and T003 are done, but its file edits must follow the US1 edits in those same files.
- **T016 waits on the product owner's export choice** (research Decision 10). Tasks T010 and T016 depend on it; the rest do not.
- **Polish** (T030–T034) runs after both stories.

### Parallel examples

- After T005: T006, T007, T008, T009, T010 (all different test files or distinct tests), and T018, T020, T021 in parallel.
- US2 tests T022, T023, T024, T025 in parallel.

## Implementation Strategy

- **MVP**: Phase 1 + Phase 2 + US1. A hyphenated segment works end to end; the long-code refusals (US2) come next.
- **Incremental**: then US2, then Polish.
- **Stop-and-ask point**: before T016 and T010 (export column rule).
