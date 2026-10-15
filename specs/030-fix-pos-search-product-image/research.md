# Research: Product Images in POS Search Results

## Evidence

- **Screenshot**: search "slippery" with the seller filter set — all four result cards show the placeholder box icon; the products do have photos in the normal grid (spec Assumption 1).
- **Backend is fine**: `ProductController::lookupVariants()` returns `'image_url' => $v->image_url` for every hit — the `ProductVariant::image_url` accessor (variant's own image, else the parent product's). It also returns `category_name`.
- **Frontend drops it** (`resources/js/views/PosView.vue`):

  ```js
  const searchCards = computed(() =>
    (searchResults.value ?? []).map((v) => ({
      variant_id: v.variant_id, sku: v.sku, name: v.label, artist_name: v.artist_name,
      sell_price: v.sell_price, current_stock: v.current_stock,
      category_code: null, category_name: null,        // image_url never copied
    }))
  );
  ```

  The search card template reads `card.image_url` → `undefined` → placeholder icon. The inline comment ("returns no category info … fall back to a generic thumbnail") is stale: the endpoint has returned both fields since feature 024.
- **Cart inherits the gap**: a search card is added with `addToCart(card)` → `posCart.add(card)` spreads the card into the cart item, so the cart line (`PosCartPanel` shows `item.image_url`) has no photo either. The browse path is fine because `toCartItem()` sets `image_url: variant.image_url ?? card.image_url ?? null`.
- **Same-shape producers live in one util**: `utils/posProductCards.js` already owns `buildProductCards` (browse card) and `toCartItem` (cart item); only the search card is built inline in the view.

## Decision 1 — Build the search card in `utils/posProductCards.js` and pass `image_url` through

**Decision**: add `buildSearchCards(results)` next to its two siblings; it maps lookup hits to the card shape and carries `image_url: v.image_url ?? null`. `PosView.vue` calls it. The cart item then gets the photo with no cart change.

**Rationale**: Fixes both symptoms (card + cart) at the one place the shape is wrong, makes the shape unit-testable without mounting the screen (the util's stated purpose), and prevents the next field added to the endpoint from being dropped by an ad-hoc `map`.

## Decision 2 — Show the category label on search cards too (REVERSED on request, 2026-10-04)

Originally left off (FR-006: only the photo changes). The requester then asked to "add category name": `buildSearchCards()` now also passes `category_name` through (the endpoint already returns it and the card template already renders it when present — the same label the browse cards show). Spec gains FR-009. `category_code` stays `null` (it only substitutes for a missing photo on browse cards).

## Decision 3 — Ignore responses of superseded searches

`runSearch` is debounced but not request-ordered: two overlapping requests can resolve out of order, leaving results (and their photos) of an older term on screen. Spec US2-4 requires the final results to be the ones shown. A small sequence counter in `runSearch` discards any response that is not from the latest request (and does not clear the loading flag for a superseded one). No new dependency.

## Decision 4 — Backend: no behaviour change; pin it with a test and fix the OpenAPI schema

`VariantLookup` in `docs/openapi-pos-mvp.yaml` lacks `image_url` and `category_name` (both returned today). Add them (PRD §9.5: docs move with the contract). Add a `ProductTest` case pinning the photo rule on `/variants/lookup` (variant's own → product's → null), which today is untested and is exactly what the POS now depends on.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| Add `image_url: v.image_url` inline in `PosView.vue`'s `map` | Fixes the symptom but keeps an untestable inline shape that already drifted once; the util is where sibling shapes live. |
| Re-fetch products for the hits (`GET /products?with_variants=1`) to get images | Extra request per keystroke, slower search (SC-004), and the data is already in the lookup response. |
| Change the backend to return something else | The backend is correct; the bug is in how the view maps it. |
| Also show category on search cards | Initially out of scope; added at the requester's request (Decision 2, FR-009). |

## Risks / notes

- The search ignores the seller/category dropdowns today (the endpoint takes none). The screenshot's four results happen to belong to the selected seller. This feature does not change what is found (FR-006); US2's "with filters applied" is about photos on whatever results appear, which this fix covers.
- A failing photo URL: the card's `<img>` has no `onerror` fallback in the browse grid either; behaviour stays identical to the browse grid (FR-005 parity), not worse.
