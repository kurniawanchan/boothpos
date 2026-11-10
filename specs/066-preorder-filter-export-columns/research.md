**Classification: INTERNAL**

# Research: Pre-order Filters and Export Columns

All findings below were read from the code on 2026-11-10 (facts) — anything not read is labelled ASSUMPTION.

## Facts found

- `PreorderController::applyFilters()` (private, ~L556) handles `status[]`, `dispatch_status[]`, `event_id`, **scalar** `customer_id`,
  `fulfillment[]`, `search` (PO number, customer name, item name/SKU, outstanding digits), `artist_id[]`. It is shared by
  `index()`, `summary()`, `neighbors()`, `matchingIds()` and the bulk preview/apply.
- `PreorderExportImportService::export()` re-implements a **subset** of those filters by hand: no `artist_id`; `search` matches
  only the customer's name; plus `date_from`/`date_to` that the list never had. The controller whitelists the keys passed in.
- `PreorderController::export()` is owner/admin only (inline `isOwnerOrAdmin()`) plus the `preorders.export` action.
- The SPA's export button sends the reactive list `params` (`exportPreorders({ ...params })`), so any new list filter flows to
  the export once the controller whitelists it.
- `preorder_items` has `variant_id`, `artist_id`, `name_snapshot`, `sku_snapshot`; **no `product_id`/`category_id`** → category is
  reached through `variant → product.category_id` (both soft-deletable).
- `sellersFor()` (controller, private) = distinct `items.artist` in first-appearance order, null-safe; the list eager-loads
  `items.artist`.
- Export row key order is the header order (`GenericArrayExport`). `PreorderExportImportTest` pins it (`EXPORT_HEADINGS`,
  `HEADINGS` = export minus export-only keys). The importer reads by column NAME and has no unknown-column check; `status`,
  `total_amount`, `preorder_number`, `sellers` are not import headings (no collision).
- `BaseMultiSelect` filters options **locally** only; `CustomerSearchDropdown` already does remote customer search
  (`listCustomers({search, per_page})`, empty search returns the first page).
- `GET /customers` caps `per_page` at 100 and matches name/phone/social handle (existing behaviour).
- `GET /categories` index is open to the logged-in roles; writes are gated.

## Decisions

### D1 — One shared filter scope (list + export)
- **Decision**: move the chain into `Preorder::scopeMatchingListFilters(Builder $q, array $filters)` with a `LIST_FILTER_KEYS`
  constant; `applyFilters()` becomes a one-liner; the export service calls the same scope. `date_from`/`date_to` stay in the
  export service.
- **Rationale**: Constitution I (one implementation per concern); FR-013/SC-003 are only provable if there is exactly one chain.
  Precedent: `Product`/`StockMovement::scopeMatchingListFilters()`.
- **Alternatives**: (a) copy the new filters into the export service too — rejected, it is the exact divergence that exists
  today; (b) have the service accept a prebuilt `Builder` from the controller — rejected, it breaks the array-based
  `export(array $filters)` callers/tests for no gain.
- **Consequence (flagged to the owner)**: export search broadens to list search; export honours `artist_id`.

### D2 — Customer filter shape
- **Decision**: `customer_id[]` through `Arr::wrap`/`array_filter` (same as `status[]`); scalar still works.
- **Rationale**: spec FR-001 requires several customers; consistent with the other filters; backwards compatible.
- **Alternative**: keep scalar + add `customer_ids[]` — rejected (two names for one concept).

### D3 — Category matching
- **Decision**: `whereHas('items', fn => whereHas('variant', fn => withTrashed()->whereHas('product', fn => withTrashed()->whereIn('category_id', $ids))))`.
- **Rationale**: EXISTS (not JOIN) so a pre-order with several matching items appears once (FR-004) and the paginator/count stay
  right; `withTrashed()` keeps history of deleted variants/products findable (precedent: feature 048). Only the soft-delete
  scope is lifted — `DataModeScope` (and `SellerScope`) still apply to the nested models, so DEMO/LIVE never mix (FR-015).
- **Alternative**: snapshot `category_id` onto `preorder_items` — rejected: migration + backfill for a read-time filter; spec
  assumption says "current category". **ASSUMPTION** (from spec) that current category is what the owner means.

### D4 — Customer options UI
- **Decision**: opt-in `remote` prop on `BaseMultiSelect` (skips local filtering, emits `search`); `CustomerFilterSelect`
  wraps it: loads the first page on mount, debounced `listCustomers({search, per_page: 25})` on typing, and keeps an
  id→name map so the button label of already-selected customers survives a new search.
- **Rationale**: customers can exceed one page, so a one-shot list (as sellers/categories use) would silently omit people.
  Opt-in prop leaves the 8+ existing callers unchanged (same approach as 005/036).
- **Alternative**: reuse `CustomerSearchDropdown` — rejected, it is single-select and returns a full customer object.
- **PII**: the dropdown shows name only. Same-name customers are indistinguishable in the label — accepted limitation;
  adding phone/email to a filter label would spread personal data to a screen that doesn't need it.

### D5 — Export columns
- **Decision**: add `preorder_number` (first), `sellers` (after `customer_name`), `status` (before `dispatch_status`),
  `total_amount` (after `discount`). Values: PO number as stored; sellers = distinct names, first-appearance order, joined
  ` | `; status = raw value; total = `number_format((float) total_amount, 2, '.', '')`.
- **Rationale**: PO number first so a row is identifiable at a glance; ` | ` because seller names may contain commas (same
  reason as `variant_names`, feature 050); raw status matches `dispatch_status`; money format matches every other money column.
  Importer reads by name → position is free (precedent 060: `current_stock` moved from B to C).
- **Cost**: `PreorderExportImportTest` layout pins change in the same commit (deliberate, not drift).
- **Alternative**: append after `shipping_at` — rejected: PO number at the far right defeats the purpose.
- **Risk**: a user may assume an exported `status` is re-imported. It is not (imports always start `ordered`). The OpenAPI
  text and the template (which omits the column) state it; no in-file marker is added.

### D6 — Seller-name derivation
- **Decision**: move `sellersFor()`'s logic to `Preorder::sellers(): array` (list item `{id,name}`); the controller delegates;
  the export maps names.
- **Rationale**: one definition of "the sellers of a pre-order" (spec FR-009 = what the list column shows).
- **Outcome of the code check (T002)**: `Artist` uses `SoftDeletes`, and the list eager-loads `items.artist` WITHOUT
  `withTrashed()`, so a soft-deleted seller is absent from the list's Seller column. **The export loads `items.artist` exactly
  as the list does (no `withTrashed`)** so the `sellers` cell always equals the list column (US3 scenario 2 / SC-004).
  A test pins this for a pre-order whose seller was soft-deleted.

### D7 — Bulk/neighbors/summary parity
- **Decision**: no code beyond D1 — they already call `applyFilters()`. Add tests asserting that `matching-ids`, `neighbors`
  and `summary` honour `category_id[]`/`customer_id[]` (FR-006), and a frontend test that changing either filter clears the
  bulk selection exactly like the existing filters do (`usePreorderSelection` behaviour — verify, don't assume).

### D8 — OpenAPI / docs
- `GET /preorders`, `/preorders/summary`, `/preorders/export`, `/preorders/matching-ids`, `/preorders/{id}/neighbors`:
  `customer_id` → array, new `category_id` array, `artist_id` documented as array for export; export description lists the new
  columns and the search/seller alignment. Same commit as the code (Constitution).

## Open items (none block planning)

- Browser verification of the two dropdowns and the export file (tooling availability unknown; keep the task open).
