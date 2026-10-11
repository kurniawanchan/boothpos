**Classification**: INTERNAL

# Contract: Products API

All routes under `/api/v1`, inside the `auth:sanctum` group. Read routes need the `products` menu (the existing `ProductPolicy`: view/viewAny open to any logged-in user stays as is for the POS); writes need `products` menu plus the permission named per route. Money is a string `"0.00"`. DEMO/LIVE applies everywhere. Stage B adds the outlet dimension (marked **B**).

## GET /products  (extended)

Query: `tab` = `menu|material|semi_finished|packaging|retail|modifier` (default `menu`; the non-menu tabs return the materials / modifier-groups lists in their own envelope shapes via their existing routes — this endpoint serves `menu` and `retail`), `q` (name, short name, any variant SKU, code prefix), `category_id[]`, `status[]` = `draft|active|discontinued`, `quality` = `no_station|no_category|no_price|no_cost`, `sort` = `name|-name|sku|-sku|price|-price|status|-status`, `page`, `per_page` (default 15, max 100), `with_variants`, `with_modifiers` (existing), new `with_availability` (default 1).

```json
{
  "data": [{
    "id": 12, "code": "KOP-001", "name": "Kopi Tubruk", "short_name": "Kopi Tubruk",
    "image_url": null, "category": {"id": 3, "name": "Kopi"}, "station": {"id": 1, "name": "Bar", "source": "category"},
    "status": "active", "is_retail": false,
    "base_price": "8000.00", "price_range": {"min": "8000.00", "max": "8000.00"},
    "variants_summary": "Gula", "variant_count": 1, "modifier_summary": ["Gula"],
    "availability": [{"outlet_id": null, "outlet_code": null, "state": "available|sold_out", "reason": null}],
    "food_cost_pct": 38.7, "quality": []
  }],
  "meta": {"current_page": 1, "per_page": 15, "total": 24, "last_page": 2},
  "counts": {"menu": 24, "material": 18, "semi_finished": 6, "packaging": 7, "retail": 4, "modifier": 7}
}
```

- `base_price`/`price_range` from active variants; `variants_summary` is the variant names joined by " / " or "Tanpa varian"; `modifier_summary` the first names of linked groups.
- `availability` has one entry for the install in Stage A (`outlet_id: null`); **B** one entry per outlet in scope.
- `quality` ⊂ `no_station`, `no_category`, `no_price`, `no_cost`.
- `counts` are over the same data mode and are independent of the filters (tab counts); `meta.total` follows the filters.
- Errors: `422` invalid filter value.

## GET /products/summary

```json
{
  "computed_at": "...", "release_enabled": false,
  "active": {"count": 22, "total": 24, "draft": 1, "discontinued": 1},
  "sold_out_today": {"count": 6, "examples": [{"product": "Es Jeruk", "outlet": null}]},
  "unreleased": {"count": 3, "summary": ["Harga Kopi Susu Gula Aren", "Item baru Bubur Kacang Hijau"], "active_version": 42, "active_since": "2026-10-01"},
  "quality": {"count": 2, "examples": ["Tahu Crispy", "Kentang Goreng"], "kind": "no_station"}
}
```
`unreleased` is `null` when `menu_release_enabled` is off (the UI hides the figure and the banner). Counts equal the list for the same filters-free view (SC-001).

## GET /products/{id}  (extended)

Adds: `short_name`, `tax_category`, `prep_minutes`, `status`, `is_retail`, `station` (+source), `release` (`{version, outlets_active}` or null), `variants[]` (`id, name, sku, base_price, outlet_prices (B), is_active, cost_price, bom_complete, recipe_driven`), `modifier_links[]` (`group_id, name, is_required, min_select, max_select, display_order, options[]`), `availability[]` (with `reason`, `marked_by`, `marked_at`, `expires_at`, `daily_quota` (B), `channels` (B)), `recipe` (`{version, yield, portion_cost, food_cost_pct, gross_margin, lines[]}` for the first variant of the active recipe, or `null`; the full history via `/recipes`), `can` (`{edit, mark_86, discontinue, bulk_price, edit_recipe}` computed per caller). Sections the caller may not see are omitted.

## PUT /products/{id}  (extended)

Accepts the existing fields plus `short_name` (≤ 24), `tax_category`, `prep_minutes` (0–600), `is_retail`. Descriptive fields apply at once (not release-gated). `422` validation.

## POST /products/{id}/sold-out   ·   DELETE /products/{id}/sold-out

Body: `{reason_code_id (required, category sold_out), note?}` (**B**: `outlet_ids[]`; empty = all outlets in scope). Permission `menu.item.86`. Creates an availability mark expiring at the end of the current business day; `409` if the product is discontinued or already 86. `DELETE` clears the active mark. Both write the activity log in the same transaction. Existing orders are untouched.

## POST /products/{id}/discontinue   ·   POST /products/{id}/reactivate

Body: `{reason_code_id (required for discontinue), note?}`. Permission `menu.item.manage`. With releases off: effective at once (`is_active = 0`, `discontinued_at` set, active 86 cleared). With releases on: creates a pending `menu_change` (`item_discontinue` / `item_reactivate`) and returns it; effective with the next release. Logged.

## GET /products/{id}/price-history

```json
{"data": [{"at": "2026-10-01T09:00:00+07:00", "variant": "Reguler", "old_price": "7000.00", "new_price": "8000.00", "source": "release", "by": "Laila", "approved_by": "Hendra", "release_version": 42, "note": null}]}
```
Newest first; the last entry is the creation entry (`source: created`). Paginated (`page`, `per_page`).

## PUT /products/{id}/variants   (drawer, batch)

Body: `{variants: [{id?, name, sku?, base_price, is_active, outlet_prices?: {<outlet_id>: price|null} (B)}], effective_from?}`. Validates unique SKU (generated when empty, existing rule), name and price required, no deletion of a variant with history (deactivate instead). Prices go through `PriceService`: immediate mode applies; release mode creates pending changes (over-threshold ones `awaiting_approval`). Response lists `applied[]` and `pending[]`. All-or-nothing.

## PUT /products/{id}/modifier-links

Body: `{links: [{group_id, is_required, min_select, max_select, display_order}]}`; replaces the item's links in one transaction; `422` for `min < 0`, `max < min`, `max < 1` (null = unlimited), unknown group; release mode → a pending `modifier_link` change. Group contents are never edited here.

## Conventions

- Controllers validate and delegate to `ProductCatalogQuery`, `AvailabilityService`, `PriceService`, `ReleaseService`, `RecipeService` (Constitution I); no logic in controllers.
- `docs/openapi-pos-mvp.yaml` gains/extends these paths in the same commit as the routes.
- Errors keep the convention: `422` validation, `409` business rule, `403` role/outlet, `401` token.
