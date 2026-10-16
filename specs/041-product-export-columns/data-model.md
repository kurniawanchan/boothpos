# Data Model: Product Export Row (rewritten 2026-10-16)

No schema change. Export row shape and the filter set.

## Products export row (one per `product_variants` row of a matching product)

Ordering: `products.name`, then `product_variants.sku` (unchanged).

| Field | Type | Source / rule |
|---|---|---|
| sku | string | `product_variants.sku` |
| code | string | last 7 chars of `sku` (whole if shorter) |
| artist_code | string | `artists.code` |
| artist_name | string | `artists.name` |
| category_code | string | `categories.code` |
| category_name | string | `categories.name` |
| product_name | string | `products.name` |
| variant_name | string | `product_variants.variant_name` |
| item_label | string | `"{code} - {category_name} {variant_name}"` |
| description | string\|null | `products.description` |
| total_stock_available | integer | `product_variants.current_stock` (0 written as `0`) |
| cost_price | money string (2 dp) | `product_variants.cost_price` |
| sell_price | money string (2 dp) | `product_variants.sell_price` |
| profit_margin | money string (2 dp, may be negative) | `sell_price − cost_price` via `ReportSplit` |
| low_stock_alert | int | `product_variants.low_stock_alert` |

## Export filter set (products only)

| Parameter | Type | Rule (same as Products list) |
|---|---|---|
| search | string, optional | product name LIKE OR any variant sku/variant_name LIKE |
| artist_id[] | int array, optional | `products.artist_id` IN (non-empty values); ignored if empty |
| category_id[] | int array, optional | `products.category_id` IN (non-empty values); ignored if empty |

Rows = all variants of products matching the filter set. No pagination.

## Invariants
- `profit_margin` = `sell_price − cost_price`.
- `item_label` starts with `code`.
- `total_stock_available` = `current_stock` of the same variant.
- Export is read-only: no row written, no stock movement, no audit entry.
- The list endpoint returns the same products for the same filters (shared scope).

## Removed from this export only
`product_segment`, `is_preorder`, `preorder_eta`, `product_is_active`, `variant_is_active`, `initial_stock`, `image_filename`, `variant_image_filename`.
