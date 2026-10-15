# Implementation Plan: Product Images in POS Search Results

**Branch**: `030-fix-pos-search-product-image` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/030-fix-pos-search-product-image/spec.md`

## Summary

On the POS screen, search results show the grey placeholder icon instead of the product photo, and an item added from a search result shows no photo in the cart. Root cause (confirmed in code): the backend is already correct — `GET /variants/lookup` returns `image_url` for every hit (the variant's own photo, else its parent product's, via the `ProductVariant::image_url` accessor) — but `PosView.vue`'s `searchCards` mapping copies only a hand-picked subset of fields and **drops `image_url`**. The card template reads `card.image_url` (so it falls to the placeholder), and because `addToCart(card)` pushes that same card into the cart store, the cart line has no image either.

Fix: build the search cards in `utils/posProductCards.js` (next to `buildProductCards`/`toCartItem`, where the browse card and cart item shapes already live) through one tested function that passes `image_url` through, and use it from `PosView.vue`. Also ignore responses of superseded searches (US2 scenario 4). Document the two fields the endpoint already returns in OpenAPI. No backend behaviour change, no schema/permission change.

## Technical Context

**Language/Version**: JavaScript (Vue 3 SPA, Vite); PHP 8.3 / Laravel only for one new test and the OpenAPI doc

**Primary Dependencies**: none added

**Storage**: N/A

**Testing**: Vitest (`qa-tests/unit/posProductCards.test.js`, `qa-tests/component/PosView.test.js`), PHPUnit (`tests/Feature/ProductTest.php`, host with `.env.testing`), real-browser check on the dev app (read-only search; the cart is client-side state)

**Target Platform**: Browser SPA served by the local Laravel app

**Project Type**: Web application (frontend fix + doc/test on backend)

**Performance Goals**: No extra request and no new payload (the field is already in the response); the stale-response guard adds no latency (SC-004)

**Constraints**: Search results (which items, order, names, prices, stock) must not change (FR-006); the card must keep falling back to the placeholder when there is no photo or the file fails to load (FR-005)

**Scale/Scope**: One new util function (~15 lines), one call-site change in `PosView.vue`, a request-sequence guard (~4 lines), tests, one OpenAPI schema edit

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / single implementation | **Pass.** The browse card (`buildProductCards`) and cart item (`toCartItem`) shapes already live in `utils/posProductCards.js`; the search card moves there too instead of staying an inline `map` in the view that silently falls out of sync (the bug). One photo rule everywhere: variant → product → placeholder, already implemented once in `ProductVariant::image_url` and not duplicated. |
| II. Testing | **Pass (planned).** Unit tests for the new builder (photo passed through, null when absent, nothing else changes), a `PosView` component test (search card shows the `<img>`, cart line shows it after adding, stale response ignored), and a backend test pinning `GET /variants/lookup`'s `image_url` fallback rule (currently untested). A real-browser before/after check per Constitution II. |
| III. UX consistency | **Pass.** No new copy/strings; same card markup, same placeholder fallback as the browse grid. |
| IV. Security | **Pass.** No new data exposed: `image_url` is already returned by the endpoint to every role that can search, and is the same public-disk URL the browse grid shows; no cost/margin fields involved. |
| V. Performance | **Pass.** No additional request or payload. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` `VariantLookup` schema is missing `image_url`/`category_name` that the endpoint already returns — corrected in the same commit (PRD §9.5). |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/030-fix-pos-search-product-image/
├── plan.md
├── research.md          # root-cause evidence, decisions, alternatives
├── data-model.md        # (no data changes — stated explicitly)
├── quickstart.md        # automated + real-browser verification
├── contracts/
│   └── variants-lookup.md   # response shape the POS relies on (documented, unchanged behaviour)
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
resources/js/utils/posProductCards.js        # + buildSearchCards(): passes image_url through
resources/js/views/PosView.vue               # searchCards uses it; ignore superseded search responses
qa-tests/unit/posProductCards.test.js        # builder tests
qa-tests/component/PosView.test.js           # search shows photo, cart keeps it, stale response ignored
tests/Feature/ProductTest.php                # lookup image_url: variant's own, product fallback, null
docs/openapi-pos-mvp.yaml                    # VariantLookup: image_url, category_name
CLAUDE.md                                    # plan pointer + one-line rule
```

**Structure Decision**: Frontend-only behaviour change inside the existing POS util/view; backend untouched except a pinning test.

## Complexity Tracking

No constitution violations to justify.
