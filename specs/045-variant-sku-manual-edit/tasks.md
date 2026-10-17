# Tasks: Edit Variant Sequence Number Manually

**Input**: `specs/045-variant-sku-manual-edit/` (plan.md, spec.md, research.md, data-model.md, contracts/variant-sequence.md, quickstart.md)

**Tests**: INCLUDED. Constitution Principle II requires backend changes to ship with MySQL feature tests and frontend changes with Vitest; the drawer needs a browser check.

**Test commands (safe)**: never run tests in the `app` container without the overrides (CLAUDE.md wipe warning):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='VariantSkuTest|ProductSegmentChangeTest|ProductTest|ProductCodeGeneratorTest'`
Frontend: `npm test`.

**Organization**: by user story: US1 (type the number, P1), US2 (number checks, P1), US3 (typed numbers survive a segment change, P2). The flag and the number helpers are Foundational. Migration name `2026_11_17_000001` sorts after the latest existing migration.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1–US3 map to spec.md user stories

---

## Phase 1: Setup

- [X] T001 Baseline: run the safe test command above, the full backend suite, and `npm test` before any change; record counts (`tests/Feature/`, `qa-tests/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the typed-flag column and the number helpers used by every story.

- [X] T002 Create `database/migrations/2026_11_17_000001_add_sku_is_typed_to_product_variants.php`: add `sku_is_typed` boolean NOT NULL default false to `product_variants`; `down()` drops it
- [X] T003 In `app/Models/ProductVariant.php` add `sku_is_typed` to `$fillable` and cast it to `boolean` in `casts()`
- [X] T004 In `app/Services/ProductCodeGenerator.php` add `public function normaliseSequence(string $raw): ?int`: trim; accept digits only (`ctype_digit`); cast to int; return null for 0 or non-digits (Indonesian docblock: leading zeros disappear because the value is an int)
- [X] T005 In `app/Services/ProductCodeGenerator.php` add `public function trailingNumber(string $sku): ?int` returning the digits after the LAST hyphen as int (`SPF-PI-S-7` → 7), null when there is no hyphen or no digits after it
- [X] T006 In `app/Services/ProductCodeGenerator.php` change `nextVariantSku()`: the highest number = max over ALL variants of the product (trashed included) of `sequenceOf($product->code_prefix, $sku) ?? trailingNumber($sku) ?? 0`; next = highest + 1; keep the collision loop and the 14-character guard
- [X] T007 [P] Extend `tests/Unit/ProductCodeGeneratorTest.php`: `normaliseSequence('007')` = 7, `'0'` = null, `'A7'` = null, `' 12 '` = 12, `''` = null; `trailingNumber('SPF-PI-S-7')` = 7, `trailingNumber('RYUKYSAK0001')` = null

**Checkpoint**: flag exists; helpers and numbering rule tested.

---

## Phase 3: User Story 1 — Type the sequence number in the drawer (Priority: P1) 🎯 MVP

**Goal**: the owner types a variant's number; the server builds `<current code>-<number>`, marks it typed, and audits the change.
**Independent Test**: variant `SPF-PI-S-1`, type `7`, save → `SPF-PI-S-7`, `sku_is_typed` true, activity `sku_changed` present.

- [X] T008 [US1] Create `app/Services/VariantSkuService.php` with `setSequence(ProductVariant $variant, ?string $raw, ?User $user): void`: inside `DB::transaction` lock the variant row; blank raw → return; `normaliseSequence` null → throw a 422 `ValidationException` on `sku` ("Nomor urut harus angka lebih dari 0"); prefix = `Str::beforeLast($variant->sku, '-')`; candidate = prefix.'-'.n; equal to current sku → return; candidate longer than 14 → 422 on `sku`; uniqueness via `ProductVariant::withTrashed()->withoutGlobalScope(DataModeScope::class)->where('sku', $candidate)->where('id', '!=', $variant->id)->exists()` → 422 on `sku` ("Nomor SKU sudah dipakai"); update `sku` and `sku_is_typed = true`; `ActivityLogger::log(action: 'sku_changed', entityType: 'ProductVariant', ...)` with old and new sku. Indonesian docblock citing spec 045 FR-001..FR-008
- [X] T009 [US1] Add `public function skuForNewVariant(Product $product, ?string $raw): ?string` to `app/Services/VariantSkuService.php`: blank → null (generated); otherwise the same checks as T008 using `product->code_prefix` and returns the candidate
- [X] T010 [US1] In `app/Http/Requests/UpdateVariantRequest.php` add `'sequence' => ['sometimes', 'nullable', 'string', 'max:20']` and `prepareForValidation()` that trims a present value
- [X] T011 [US1] In `app/Http/Requests/StoreVariantRequest.php` add `'sequence' => ['nullable', 'string', 'max:20']` and `prepareForValidation()` that trims a present value
- [X] T012 [US1] In `app/Http/Controllers/Api/ProductController.php` `updateVariant()`: when `$request->filled('sequence')` call `VariantSkuService::setSequence($variant, $request->validated('sequence'), $request->user())` before the other field updates (inside the existing flow; the BOM cost lock stays untouched)
- [X] T013 [US1] In `app/Http/Controllers/Api/ProductController.php` `storeVariant()`: when a sequence is given, use `VariantSkuService::skuForNewVariant($product, $raw)` as the SKU and mark the new variant `sku_is_typed = true`; blank keeps `nextVariantSku()` (generated, flag false)
- [X] T014 [US1] Create `tests/Feature/VariantSkuTest.php`: `PUT /api/v1/variants/{id}` with `sequence` `'7'` on `SPF-PI-S-1` → 200, sku `SPF-PI-S-7`, `sku_is_typed` true; `'007'` → stored `SPF-PI-S-7`; blank or omitted → sku unchanged, flag unchanged; same number → no change and no activity row
- [X] T015 [US1] Tests in `tests/Feature/VariantSkuTest.php`: `POST /api/v1/products/{id}/variants` with `sequence` `'9'` → sku `<code>-9` and flag true; without `sequence` → generated number and flag false
- [X] T016 [US1] Tests in `tests/Feature/VariantSkuTest.php`: an `activity_logs` row with action `sku_changed` exists after a change; a `order_items.sku_snapshot` recorded before the change is unchanged after it
- [X] T017 [P] [US1] In `resources/js/utils/productSegment.js` add `export function splitSku(sku)` returning `{ code, number }` (code = text before the last hyphen; number = text after it; empty strings when the SKU has no hyphen)
- [X] T018 [P] [US1] Create `qa-tests/unit/variantSkuSplit.test.js`: `splitSku('SPF-PI-S-7')` = `{ code: 'SPF-PI-S', number: '7' }`; `splitSku('RYUKYSAK0001')` = `{ code: '', number: '' }`
- [X] T019 [US1] In `resources/js/views/ProductsView.vue` variant card (the SKU chip span near the variant header, currently `<span … bg-sky-bg …>{{ row.sku }}</span>`): replace it with the fixed code text `splitSku(row.sku).code`, a hyphen, and a `BaseInput` (import already present) bound to `row.sequence` (initialised from `splitSku(row.sku).number` for saved variants; empty for new ones), with `:error="row.sequence_error"`
- [X] T020 [US1] In `resources/js/views/ProductsView.vue` `saveProduct()`: for saved variants send `sequence: row.sequence || undefined` in the `updateVariant` payload; for new variants send `sequence` in the `addVariant` payload when filled; a 422 with `errors.sku` on a variant sets that row's `sequence_error` and stops the save, so nothing partial is shown as saved
- [X] T021 [US1] Test in `qa-tests/component/ProductsView.test.js`: a saved variant `ART-KA-S-1` shows `ART-KA-S` as fixed text and `1` in an editable field; saving with `7` sends `sequence: '7'` for that variant; a 422 `errors.sku` shows its message under that variant's number field

**Checkpoint**: US1 testable on its own (T014–T016 and T021).

---

## Phase 4: User Story 2 — The number is checked before saving (Priority: P1)

**Goal**: digits only, at least 1, leading zeros removed, whole SKU at most 14 characters, unique; refusals name the field.
**Independent Test**: `A7` refused; a number that makes the SKU longer than 14 characters refused; `007` stored as `7`.

- [X] T022 [US2] Tests in `tests/Feature/VariantSkuTest.php`: letters or symbols (`A7`, `7-1`) → 422 `errors.sku` and nothing saved; `0` → 422; a SKU of 15 characters → 422 `errors.sku`; a SKU used by another variant of another product in the other data mode → 422 `errors.sku`; a SKU matching a deleted variant → 422 `errors.sku`
- [X] T023 [US2] Add `export function maxSequenceLength(code)` to `resources/js/utils/productSegment.js`: returns `14 - code.length - 1` (never below 1)
- [X] T024 [P] [US2] Add cases to `qa-tests/unit/variantSkuSplit.test.js`: `maxSequenceLength('SPF-PI-S')` = 9; a very long code returns 1
- [X] T025 [US2] In `resources/js/views/ProductsView.vue` variant number input: `inputmode="numeric"`, `:maxlength="maxSequenceLength(splitSku(row.sku).code)"` (for new variants the code is the product code preview); keep the same `BaseInput` as T019

---

## Phase 5: User Story 3 — Typed numbers survive a segment change (Priority: P2)

**Goal**: a segment change keeps typed SKUs; generated SKUs are regenerated as in feature 044; new numbers still never repeat within a product.
**Independent Test**: `SPF-PI-S-7` (typed) and `SPF-PI-S-2` (generated); change segment to `T` → `SPF-PI-S-7` stays; `SPF-PI-T-2` is generated.

- [X] T026 [US3] In `app/Services/ProductSegmentChangeService.php` `changeSegment()`: variants with `sku_is_typed = true` keep their SKU (not updated); only generated variants get the new code; `assignSkus()` must consider only the generated variants; the audit `sku_count` counts only the regenerated SKUs
- [X] T027 [US3] Tests in `tests/Feature/ProductSegmentChangeTest.php`: a typed SKU stays exactly as it was after a segment change; a generated SKU in the same product is regenerated with its number (`SPF-PI-S-2` → `SPF-PI-T-2`); typed and generated numbers never clash
- [X] T028 [US3] Test in `tests/Feature/ProductTest.php`: after a segment change where a typed `SPF-PI-S-7` was kept, `POST /api/v1/products/{id}/variants` on the product with the new code `SPF-PI-T` returns sku `SPF-PI-T-8` (the number continues past the typed one)

**Checkpoint**: US3 testable on its own.

---

## Phase 5b: User Story 4 — Variants listed in SKU order (Priority: P2, added 2026-10-17)

**Goal**: the product edit drawer lists saved variants in SKU order (code text, then the number as a whole number: `…-2` before `…-10`). Unsaved variants keep the position where they were added until the product is saved.
**Independent Test**: open a product with numbers 1, 2, 9, 10, 11 (any stored order); the cards show `…-1`, `…-2`, `…-9`, `…-10`, `…-11`.

- [X] T036 [US4] In `resources/js/utils/productSegment.js` add `export function compareVariantSku(a, b)` beside `splitSku`: when both SKUs have a number after the last hyphen, compare the code text (`localeCompare` with no locale), and if equal compare the numbers as integers; a SKU without a hyphen sorts after the numbered SKUs of the same code; otherwise compare the full SKU text. Indonesian docblock citing spec 045 FR-012
- [X] T037 [P] [US4] Extend `qa-tests/unit/variantSkuSplit.test.js`: `compareVariantSku` orders `ART-KA-S-2` before `ART-KA-S-10`; orders `ART-KA-S-11` after `ART-KA-S-9`; puts a legacy `RYUKYSAK0001` after numbered `RYUKYSAK-1`; keeps codes apart (`ART-KA-S-1` vs `ART-KA-T-1`)
- [X] T038 [US4] In `resources/js/views/ProductsView.vue` where the drawer loads saved variants (the `variantRows.value = full.variants.length ? full.variants.map(...)` assignment, around line 290): sort the mapped rows with `compareVariantSku` on their `sku`; do not change `emptyVariant()`, the duplicate flow, or the save order
- [X] T039 [US4] Test in `qa-tests/component/ProductsView.test.js`: a product whose variants arrive as `ART-KA-S-10`, `ART-KA-S-2`, `ART-KA-S-1` shows the cards in the order `-1`, `-2`, `-10`; after clicking add-variant (not saved), the new card stays after the existing ones until the product is reopened
- [X] T040 [US4] Manual browser check (read-only, quickstart §7) on `resources/js/views/ProductsView.vue`: open the drawer of an existing product with several numbers; the cards are in SKU order; close without saving; console clean
- [X] T041 [US4] Re-run `npm test` after T036–T039; record the count (`qa-tests/unit/variantSkuSplit.test.js`, `qa-tests/component/ProductsView.test.js`)

**Checkpoint**: US4 testable on its own.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T029 [P] Update `docs/openapi-pos-mvp.yaml`: `PUT /variants/{variant}` and `POST /products/{product}/variants` gain `sequence` (string, digits, nullable); document the 422 on `sku` for non-numeric, zero, too long, or duplicate; state that a typed number is kept on a segment change (contracts/variant-sequence.md)
- [X] T030 Run the safe backend command (`VariantSkuTest`, `ProductSegmentChangeTest`, `ProductTest`, `ProductCodeGeneratorTest`); record counts
- [X] T031 Run the full backend suite with the safe overrides; record the count
- [X] T032 Run `npm test`; record the count (includes `variantSkuSplit`, `ProductsView`)
- [X] T033 Run `vendor/bin/pint --test` one file at a time on the new and changed files: `app/Services/VariantSkuService.php`, `app/Services/ProductCodeGenerator.php`, `app/Services/ProductSegmentChangeService.php`, `app/Http/Requests/UpdateVariantRequest.php`, `app/Http/Requests/StoreVariantRequest.php`, `app/Models/ProductVariant.php`, `database/migrations/2026_11_17_000001_add_sku_is_typed_to_product_variants.php`, `tests/Feature/VariantSkuTest.php`. Fix only hunks in this feature; `ProductController.php` already fails Pint on HEAD, so do not reformat it
- [ ] T034 Manual browser check (quickstart §3) on `resources/js/views/ProductsView.vue` with a DEMO product: the code is fixed text, the number is editable; `7` saves; `A7`, `0` and a duplicate are refused under the field; the layout fits the card header; console clean. Needs writes, so run only with the owner's go-ahead
- [X] T035 [P] Verify `CLAUDE.md` SPECKIT block points to `specs/045-variant-sku-manual-edit/plan.md` (done in the plan step; verify only)

---

## Dependencies & Execution Order

- **Setup (T001)** → **Foundational (T002–T007)** blocks all stories. T006 changes numbering used by US1 creation, so it lands before T013 is tested.
- **US1 (T008–T021)**: T008 → T009 → T012/T013; T010/T011 before T012/T013; T014–T016 after T008–T013; T017 and T018 `[P]`; T019 → T020 → T021; T019 needs T017.
- **US2 (T022–T025)**: after US1 service and requests; T023 before T025; T024 `[P]`.
- **US3 (T026–T028)**: after Foundational; T026 before T027 and T028.
- **Polish (T029–T035)**: last; T029 `[P]`.

### Parallel opportunities
- T007 (unit), T017/T018 (utils and their test), T024 (unit test), T029 (OpenAPI) — different files.

---

## Implementation Strategy

### MVP (this feature)
1. T001 → T002–T007 → US1 backend (T008–T016) → US1 drawer (T017–T021): the owner can type a number and save.
2. US2 (T022–T025): the checks and field limits.
3. US3 (T026–T028): typed numbers survive a segment change.

### Totals
- 35 tasks, all open. Per story: Setup 1, Foundational 6, US1 14, US2 4, US3 3, Polish 7.

---

## Notes
- Every task names an exact file path.
- The branch is `053-variant-sku-manual-edit`, based on `develop` (PR #45 merged).
- Commit only when asked.

### Totals (updated 2026-10-17, User Story 4)
- Added US4: T036–T041 (6 tasks), all open. The backend is unchanged by this story (display rule in the SPA only).
