# Research: Stock Page Search, Variant Details, and Product Stock Totals

All items are resolved; nothing is left as NEEDS CLARIFICATION. Statements are grounded in files read on 2026-10-28; anything not yet run is labelled **unverified**.

## D1 — Where search/filter lives: the existing movements endpoint, server-side

- **Decision**: Extend `GET /stock/movements` with `search`, `artist_id[]`, `category_id[]`. Implement the matching once as `StockMovement::scopeMatchingListFilters()` (same pattern as `Product::scopeMatchingListFilters()`, used by both the Products list and its export).
- **Rationale**: FR-004 requires filtering across the whole dataset, not the loaded page (the endpoint paginates, default 25, max 100). The same endpoint feeds `VariantHistoryModal`, so every new parameter must be optional and additive.
- **Alternatives**: client-side filtering of the loaded page (rejected: wrong results and counts under pagination); a new endpoint (rejected: two sources for one ledger — 036 deliberately made `/stock/movements` the single ledger source).

## D2 — What "search" matches

- **Decision**: partial, case-insensitive match on variant SKU, variant name, or parent product name. LIKE wildcards (`%`, `_`) in user input are escaped; blank/whitespace-only input is ignored.
- **Rationale**: spec FR-001 and the edge case about special characters. MySQL's default collation is case-insensitive. The existing `Product` scope does not escape wildcards; the new scope does, because the spec lists special characters as an edge case.
- **Soft-deleted rows**: the existing endpoint eager-loads `variant`/`variant.product` with `withTrashed()` so history of deleted items stays reachable. The `whereHas` conditions must also use `withTrashed()`, otherwise searching would silently drop movements of deleted variants/products (**to be pinned by a test**).
- **Not matched**: movement reason / reference number (not requested; avoid scope creep).

## D3 — Seller and category filters

- **Decision**: array-capable `artist_id[]` / `category_id[]` filtering through `variant.product` (`whereIn`), reusing the Products-screen convention (`BaseMultiSelect`, empty = all). Options come from the existing `listArtists` / `listCategories` calls the Products screen already makes.
- **Rationale**: consistent UX (Constitution III) and no new endpoints. A stale id simply yields an empty list (edge case in spec).
- **Existing filters** (`type`, `date_from`, `date_to`, `variant_id`) stay and combine with AND.

## D4 — New fields on each movement row

- **Decision**: add `image_url`, `artist_id`, `artist_name`, `category_id`, `category_name`, `current_stock`. All are additive.
  - `image_url` = `ProductVariant::image_url` accessor (variant image, else product image, else null) — the accessor already exists and is the documented single definition of the fallback; the frontend shows a placeholder for null (spec assumption).
  - `current_stock` = the variant's live `current_stock` at response time (NOT the movement's `stock_after`); spec FR-003 says so explicitly.
  - Seller/category come from `variant.product.artist` / `.category`, loaded `withTrashed()` so a deleted seller still shows its name.
- **N+1 guard (Constitution V)**: eager-load `variant.product.artist` and `variant.product.category`; the accessor reads `product` which is already loaded. **A feature test asserts a constant query count** across page sizes.
- **Privacy**: no cost price, no BOM data. Seller/category/picture are already visible to the `products`-menu users who can reach this endpoint.

## D5 — The History action

- **Decision**: a button in a new last column of each movement row that opens the existing `VariantHistoryModal` for that row's `variant_id`, `sku` and `variant_name`. State is local to `StockView` (same pattern as the existing SKU → `ProductDetailModal` flow) so the list, filters and scroll position are untouched when the dialog closes.
- **Rationale**: spec FR-007. The spec notes this is somewhat redundant on a ledger row (consequence of Q1 = B); it stays because the user asked for it and the dialog adds per-variant filtering.
- **Visibility**: `/stock` is already a menu-gated screen, so the button needs no extra gating (hidden-not-disabled rule is satisfied because the whole screen is hidden from roles without access). **Unverified**: the router meta for `/stock` was not opened; confirm in tasks (T-routing check) that the screen requires the `stock` menu.

## D6 — Dialog title

- **Decision**: build the label in `VariantHistoryModal` as `[variantName, variantSku].filter(Boolean).join(' · ')` and change the `master_data.history_title` copy from `… — {sku}` to `… — {label}` in `en.json` and `id.json`. Both call sites (`ProductDetailModal`, new `StockView`) already/will pass both props.
- **Rationale**: FR-008/FR-009 — one component, one rule; no second dialog. Missing name degrades to SKU only, missing SKU to name only (today's fallback was `variantSku || variantName`).
- **Risk**: any existing test asserting the old title string must be updated (`grep` of `qa-tests` for `history_title`/`Transaction history` returned nothing, so none expected — **unverified for text assertions on rendered output; run the three affected Vitest files**).

## D7 — Product totals: where and how

- **Decision**: compute in `ProductController::index()` with relation aggregates over `variants` restricted to `is_active = true`:
  - `total_stock` = Σ `current_stock`
  - `total_stock_value` = Σ `current_stock × sell_price`, serialised with the project's money rule (`number_format((float) $x, 2, '.', '')`, always a string), `"0.00"` when there are no active variants.
- **Why relation aggregates, not a raw subquery or `DB::table`**: `ProductVariant` uses `HasDataMode` and `SoftDeletes`. CLAUDE.md warns that hand-rolled `DB::table()` queries bypass the DEMO/LIVE global scope. An Eloquent relation aggregate (`withSum`) applies the global scopes of the related model, so DEMO and LIVE never mix (spec edge case) and soft-deleted variants are excluded.
- **Expression column**: `withSum` on a computed expression (`current_stock * sell_price`) relies on Laravel accepting an `Expression` as the aggregate column. This is believed supported in current Laravel but is **unverified in this repo's version**; the first task is a failing test, and if the expression form is unsupported the fallback is a correlated subquery built from `ProductVariant::query()` (still scope-aware) and added with `selectSub`.
- **Where emitted**: only the list endpoint. `ProductResource` emits the two fields only when the attributes are present on the model (the `relationLoaded()` trap documented in CLAUDE.md, applied deliberately: show/store/update responses stay unchanged and do not silently show stale or zero totals).
- **Alternatives**: sum in the SPA from `variants` (rejected: the list does not always load variants and server is the source of truth for money — Constitution IV); a stored/cached column (rejected: drift risk, needs a write path, violates single-write-path discipline).
- **Pre-order products**: their variants hold 0 stock until arrival, so totals are 0 — consistent with the stock invariants in CLAUDE.md; no special case.

## D8 — Products screen presentation

- **Decision**: two new columns after Category: `Total stock` (integer) and `Stock value` (formatted with the existing `formatIDR`). Values come straight from the API; no client arithmetic. Not sortable in this iteration (the list is server-paginated; sorting by a derived value would need server support — out of scope).

## D9 — Documentation and guards

- OpenAPI updated in the same commit (constitution): `GET /stock/movements` params + row fields, `GET /products` row fields.
- `qa-tests/unit/localeKeys.test.js` already fails on a literal `t('a.b')` missing from either locale, so new keys must land in both files.
- CLAUDE.md gets a short dated section (new fields, the `withSum` scope reason, totals list-only rule).

## Open items carried into tasks (none blocking)

1. Confirm `/stock` route meta requires the `stock` menu (D5).
2. Confirm `withSum` with an expression works on the installed Laravel (D7); fall back as written if not.
3. Confirm Indonesian wording for the new labels with the product owner during review (copy only).
