---

description: "Task list for Product Images in POS Search Results"
---

# Tasks: Product Images in POS Search Results

**Input**: Design documents from `/specs/030-fix-pos-search-product-image/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–4), data-model.md (no data changes), contracts/variants-lookup.md, quickstart.md

**Tests**: INCLUDED. Constitution II requires tests; today nothing covers the POS search card's shape or the photo rule on `GET /variants/lookup`, which is why the dropped field went unnoticed. A real-browser before/after check is mandatory.

**Organization**: The whole fix is one builder in `resources/js/utils/posProductCards.js` plus its call site, so it sits in the Foundational phase; the story phases add story-specific tests and verification — US1 Search results show the photo (P1) · US2 The photo follows the item into the cart and stays consistent (P1).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US2, only on user-story phase tasks
- Code comments and commit messages in Indonesian (project convention); no new UI strings are needed

## Safety reminders

- Real-browser checks use the dev app on :8000 **read-only**: search and the client-side cart only — never complete a checkout, never edit data. (If a write is ever needed, use an isolated server + `boothpos_test` instead.)
- Backend tests run on the HOST with `.env.testing` (`boothpos_test`); inside the `app` container only with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …`. Never `migrate:fresh`/`db:wipe`/`db:seed` against the dev DB.
- Check free Docker disk space before long runs (a full disk has frozen MySQL before).

---

## Phase 1: Setup

- [X] T001 Record the baseline: run `npx vitest run qa-tests/unit/posProductCards.test.js qa-tests/component/PosView.test.js qa-tests/component/PosCartPanel.test.js` and `php artisan test --filter=test_variant_lookup` on the host; all green before any change (note the counts)

---

## Phase 2: Foundational — reproduce, then fix the search card (blocks both stories)

**Purpose**: Prove the symptom in a real browser, pin it with failing tests, then make the one change both stories depend on.

- [X] T002 In the real browser on the dev app (http://localhost:8000, login `owner`), open POS, pick a product that shows a photo in the normal grid, search its name: confirm the result card shows the placeholder icon (the reported defect) and that adding it to the cart gives a cart line without a photo; save the screenshot as `specs/030-fix-pos-search-product-image/evidence/before-search-card.png` (no checkout, no data change)
- [X] T003 [P] Add failing tests to `qa-tests/unit/posProductCards.test.js` for a new `buildSearchCards(results)`: (a) a hit with `image_url` yields a card with that `image_url`; (b) a hit with `image_url: null`/missing yields `image_url: null`; (c) every other field is mapped exactly as today (`variant_id`, `sku`, `name` ← `label`, `artist_name`, `sell_price`, `current_stock`, `category_code: null`, `category_name: null`) and order is preserved; (d) `null`/empty input yields `[]`
- [X] T004 In `resources/js/utils/posProductCards.js` add and export `buildSearchCards(results)` (Indonesian comment: why it lives beside `buildProductCards`/`toCartItem`, that `image_url` comes from `GET /variants/lookup` already resolved variant → product, and that category stays null on purpose per FR-006); in `resources/js/views/PosView.vue` replace the inline `searchCards` map with it and correct the stale comment in `runSearch` ("returns no category info … generic thumbnail")
- [X] T005 Run `npx vitest run qa-tests/unit/posProductCards.test.js`; fix until green (the T003 tests must have failed before T004)

**Checkpoint**: the search card now carries the photo; both stories can be verified.

---

## Phase 3: User Story 1 — Search results show the product photo (Priority: P1)

**Goal**: A search result card shows the same photo the item has in the normal grid (variant's own, else the product's, else the placeholder), whether found by name or by SKU.

**Independent Test**: Search a product that shows a photo in the normal grid by name, then by SKU fragment; the result card shows that photo; a product with no photo shows the placeholder.

### Tests for User Story 1 ⚠️ write first

- [X] T006 [P] [US1] Add to `tests/Feature/ProductTest.php` tests pinning `GET /api/v1/variants/lookup`'s `image_url` (currently untested; no behaviour change expected): variant with its own `image_path` → that URL; variant without one but product with one → the product's URL; neither → `null`; also asserted for a lookup by SKU fragment and by product name; role `cashier` (any role that can search sees it)
- [X] T007 [P] [US1] Add to `qa-tests/component/PosView.test.js` (mock `lookupVariants`): typing a term renders the result card with an `<img>` whose `src` is the hit's `image_url`; a hit with `image_url: null` renders the placeholder icon (`ph-package`) and no `<img>`; a SKU-style term behaves the same; item name/price/stock text unchanged

### Implementation / verification for User Story 1

- [X] T008 [US1] Rebuild (`npm run build`) and repeat the T002 real-browser search: the result card now shows the same photo as the normal grid, by name and by SKU fragment; a photo-less product still shows the placeholder; save `specs/030-fix-pos-search-product-image/evidence/after-search-card.png`; console clean
- [X] T009 [US1] Run `npx vitest run qa-tests/component/PosView.test.js` and `php artisan test --filter=test_variant_lookup`; fix until green

**Checkpoint**: US1 delivered — the reported symptom is fixed.

---

## Phase 4: User Story 2 — The photo follows the item into the cart and stays consistent (Priority: P1)

**Goal**: The same item shows the same photo on the search card, in the cart after adding it from a search, and in the normal grid after clearing the search; with seller/category filters applied; and a late response from an older search never replaces the final results.

**Independent Test**: With a seller and a category selected, search an item, add it to the cart from the result, compare photo on the card, in the cart line and in the grid after clearing the search; type quickly and confirm the final results' photos.

### Tests for User Story 2 ⚠️ write first

- [X] T010 [US2] Add to `qa-tests/component/PosView.test.js`: after clicking a search result with an `image_url`, the cart panel line shows that same `<img>`; clearing the search box returns the browse grid with its own photos unchanged; with the seller/category dropdowns changed while results are shown, the cards keep their photos
- [X] T011 [US2] Add to `qa-tests/component/PosView.test.js`: two overlapping searches where the OLDER request resolves AFTER the newer one — the screen keeps the newer results (and their photos) and the loading state ends correctly (use deferred promises on the `lookupVariants` mock and fake timers for the debounce)

### Implementation / verification for User Story 2

- [X] T012 [US2] In `resources/js/views/PosView.vue::runSearch` add a request counter so a response from a superseded search is ignored (no assignment to `searchResults`, and the loading flag is cleared only by the latest request); keep the existing 300 ms debounce and the cleared-term path (`searchResults = null`) working
- [X] T013 [US2] In the real browser (dev app, read-only): with a seller and a category selected, search an item with a photo, add it to the cart from the result → the cart line shows the photo; clear the search → the grid shows the same photo; type a term quickly and keep typing → the final results' photos show; save `specs/030-fix-pos-search-product-image/evidence/after-cart.png`; do NOT check out (empty the cart afterwards)
- [X] T014 [US2] Run `npx vitest run qa-tests/component/PosView.test.js qa-tests/component/PosCartPanel.test.js qa-tests/unit/posCart.test.js`; fix until green

**Checkpoint**: US2 delivered.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T015 [P] Update `docs/openapi-pos-mvp.yaml` `VariantLookup` schema: add `category_name` (string, nullable) and `image_url` (string, nullable, "variant's own photo, else the product's") — fields the endpoint already returns (PRD §9.5: docs move with the contract; mirror `specs/030-fix-pos-search-product-image/contracts/variants-lookup.md`)
- [X] T016 [P] Add one bullet to the Frontend section of `CLAUDE.md`: POS card and cart-item shapes (`buildProductCards`, `buildSearchCards`, `toCartItem`) live in `utils/posProductCards.js` — don't build a card with an inline `map` in the view, or a new endpoint field silently drops out (feature 030); keep the SPECKIT plan pointer as is
- [X] T017 Run the full frontend suite `npx vitest run` and `npm run build`; record counts and fix any regression; run `php artisan test --filter=ProductTest` once on the host as a sanity check (no backend behaviour change)
- [X] T018 Final diff review against the constitution: the fix is one util function + its call site (no inline workaround), no new request or payload, no new strings, Indonesian `BUG YANG DITEMUKAN & DIPERBAIKI` comment present, tests include the photo-dropped regression and the stale-response case; evidence screenshots contain no customer data
- [ ] T019 Ask the reporter to check the real POS search once (search a product that has a photo, add it to the cart) and confirm the photo shows on the card and in the cart; note their confirmation in the final report

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories → Polish.** Phase 2 contains the whole fix to the card shape; nothing after it needs production-code changes except the small stale-response guard (T012, US2).
- Inside Phase 2: reproduce (T002) and failing tests (T003) → implement (T004) → green run (T005).
- **US1** and **US2** are both P1; US1 needs only Phase 2, US2 needs Phase 2 and adds T012.
- Same-file serialisation: `qa-tests/component/PosView.test.js` (T007, T010, T011 — add them one after another), `resources/js/views/PosView.vue` (T004 then T012).

## Parallel examples

- After T005: T006 (backend test), T007 (card test) and T015/T016 (docs) touch different files and can run together.
- T010 and T011 are in the same test file as T007 — write them sequentially, but both before T012.

## Implementation strategy

1. **MVP**: Phases 1–3 — reproduce, fix the card shape, prove the search card shows the photo. Stop and validate with the reporter's scenario.
2. + **US2** (cart consistency, stale-response guard).
3. Polish, then the reporter confirms in the real POS.

### Notes

- Commit as one Indonesian-message commit for the code (plus the spec/plan docs commit); do not push or open a PR without explicit instruction.
- If the browser check shows the photo is still missing for an item that has one in the normal grid, the cause is NOT the dropped field (e.g. an image URL host problem) — stop and add a task rather than widening this fix silently.
