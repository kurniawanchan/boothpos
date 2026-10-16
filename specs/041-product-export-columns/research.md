# Research: Product Export Column Update (rewritten 2026-10-16)

## Decision 1 — Where the export layout lives
- **Decision**: `MasterDataSheets::PRODUCTS_EXPORT` + `exportHeadings()` beside `PRODUCTS`.
- **Rationale**: `MasterDataSheets` already owns every sheet's headers; the difference stays visible.
- **Alternatives**: headers inside the service (scatters header knowledge); changing `PRODUCTS` (rejected by FR-011 option A).

## Decision 2 — Money arithmetic for `profit_margin`
- **Decision**: `ReportSplit::cents()` / `ReportSplit::money()`.
- **Rationale**: the one money-to-cents rule used by reports; avoids float drift.
- **Alternatives**: `number_format(sell - cost)` (float drift; second formula).

## Decision 3 — `code` derivation
- **Decision**: `substr($sku, -7)`.
- **Rationale**: literal "last 7 characters"; PHP 8 returns the whole string for short SKUs. Pinned by a test.
- **Alternatives**: regex on SKU shape (breaks on any other SKU form).

## Decision 4 — Filter definition shared by list and export
- **Decision**: a single `Product` query scope `matchingListFilters(array $filters)` used by `ProductController::index()` and by the export.
- **Rationale**: Constitution I (one implementation per concern). Two copies of the search rule would drift: the list already has a subtle rule (search matches the product name OR any variant's SKU/name, so sibling variants come with it) that the export must reproduce exactly.
- **Alternatives considered**:
  - Copy the rules into the export service: rejected — the duplicate is the bug this decision prevents.
  - Filter variants directly (match variant SKU/name per row): rejected — it would export fewer variants than the screen shows for the same product (spec edge case: sibling variants are included).
  - Call the list endpoint internally and reuse its JSON: rejected — paginated and shaped for the screen, not for a flat export.

## Decision 5 — Export query shape
- **Decision**: `ProductVariant::query()->whereHas('product', fn ($q) => $q->matchingListFilters($filters))` with the existing eager loads.
- **Rationale**: one SQL, no per-row lookup (Principle V); empty filters = no restriction = today's behaviour.
- **Alternatives**: pluck product ids first then `whereIn` (two queries, large id lists).

## Decision 6 — Array parameter encoding from the SPA
- **Decision**: pass `artist_id` / `category_id` as arrays in axios `params`; axios encodes them as `artist_id[]=…`, the encoding `listProducts` already uses and the server already reads with `$request->array()`.
- **Rationale**: no new encoding; the export and the list cannot diverge in how they read filters.

## Decision 7 — Total stock source
- **Decision**: `ProductVariant.current_stock` (the variant's own stock on hand).
- **Rationale**: the stock sheet and the Stock screen are per variant (`StockService` maintains `current_stock`); each export row is a variant. Product-level sum rejected: repeats on every variant row. ASSUMPTION flagged in spec for product-owner confirmation.

## Decision 8 — Pagination
- **Decision**: the export ignores pagination; all matching products are exported.
- **Rationale**: FR-014 — the file must reflect the filter, not the page.

## Open items
- Product owner to confirm "total stock available" = variant stock (spec Assumptions).
- Browser check of the Products export with a search and a filter (quickstart §3).
