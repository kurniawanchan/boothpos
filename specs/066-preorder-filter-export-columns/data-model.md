**Classification: INTERNAL**

# Data Model: Pre-order Filters and Export Columns

**No schema change. No migration.** Everything below is query shape and response/export shape.

## Entities touched (read-only)

| Entity | Used for | Relevant columns (existing) |
|---|---|---|
| `preorders` | the filtered/exported row | `id`, `preorder_number`, `customer_id`, `status`, `total_amount`, `data_mode` |
| `preorder_items` | bridge to seller and (via variant) category | `preorder_id`, `variant_id`, `artist_id` |
| `product_variants` | bridge to product (soft-deletable) | `id`, `product_id` |
| `products` | owns the category (soft-deletable) | `id`, `category_id` |
| `categories` | filter choices | `id`, `name` |
| `customers` | filter choices | `id`, `name` |
| `artists` | seller name for the export | `id`, `name` |

All indexed through existing FKs; no new index needed at this scale.

## Filter semantics (single scope)

`Preorder::scopeMatchingListFilters(Builder $q, array $filters)` — keys in `Preorder::LIST_FILTER_KEYS`:

| Key | Type | Predicate |
|---|---|---|
| `status` | string[] | `status IN (…)` (unchanged) |
| `dispatch_status` | string[] | `dispatch_status IN (…)` (unchanged) |
| `event_id` | int | `event_id = ?` (unchanged) |
| `customer_id` | int \| int[] | **`customer_id IN (…)`** (was `= ?`; scalar still accepted) |
| `fulfillment` | string[] | `fulfillment IN (…)` (unchanged) |
| `search` | string | PO number OR customer name OR item name/SKU OR outstanding digits (unchanged) |
| `artist_id` | int[] | EXISTS item with `artist_id IN (…)` (unchanged) |
| **`category_id`** | int[] | **EXISTS item → variant (withTrashed) → product (withTrashed) with `category_id IN (…)`** |

Combination: AND across keys, OR within a key. Empty strings/empty arrays are dropped (not "match nothing").
`date_from`/`date_to` are NOT part of the scope (export-only, `whereDate(created_at)`).

Invariants: a pre-order appears at most once whatever the number of matching items; the pre-order is returned whole (its
items are never narrowed); DEMO/LIVE and seller scope still come from the models' global scopes.

## Export row (one row per pre-order)

Header order = key order of the first row (`GenericArrayExport`). **Bold = new, export-only.**

| # | Column | Source | Format |
|---|---|---|---|
| 1 | **`preorder_number`** | `preorders.preorder_number` | string |
| 2 | `customer_name` | customer name | string |
| 3 | **`sellers`** | distinct `items.artist.name`, first-appearance order | names joined with ` \| `; empty string when none |
| 4 | `event_name` | event name | string |
| 5 | `fulfillment` | — | `pickup` / `mail order` |
| 6 | `pickup_day` | — | `Day N` |
| 7 | `products` | `sku_snapshot` per item | comma-separated |
| 8 | `variant_names` | live variant name per item | ` \| `-joined (feature 050) |
| 9 | `quantities` | `qty` per item | comma-separated |
| 10 | `unit_prices` | `sell_price` per item | comma-separated, 2 decimals |
| 11 | `shipping_cost` | — | 2 decimals |
| 12 | `courier_name` | — | string |
| 13 | `expected_date` | — | `Y-m-d` |
| 14 | `discount` | — | 2 decimals |
| 15 | **`total_amount`** | `preorders.total_amount` (items − discount + shipping) | `number_format(…, 2, '.', '')` |
| 16 | `notes` | — | string |
| 17 | **`status`** | `preorders.status` | raw value (`ordered`, `arrived`, `settled`, `handed_over`, `cancelled`) |
| 18 | `dispatch_status` | — | raw value |
| 19–20 | `invoice_sent_at`, `shipping_at` | — | ISO 8601 + offset |
| 21–22 | `created_at`, `updated_at` | — | ISO 8601 + offset (still last) |

Import contract: the importer reads by column NAME. Import headings (`HEADINGS`) are unchanged and `template()` is unchanged;
`variant_names`, `preorder_number`, `sellers`, `total_amount`, `status`, `created_at`, `updated_at` are all ignored on import
(`EXPORT_ONLY`/ignored-by-name). An exported file therefore re-imports with no error and no side effect from these columns.

Privacy: no phone/email/address/social handle added (FR-014).

## API response shapes

No response body changes: list rows already carry `preorder_number`, `customer_name`, `sellers`, `status`, `total_amount`.
Only request parameters change (see contracts/preorder-filters-export.md).
