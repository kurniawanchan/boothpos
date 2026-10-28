# Data Model: Stock Page Search, Variant Details, and Product Stock Totals

**No schema change, no migration.** Everything below is read-side shape. Existing tables: `stock_movements`, `product_variants`, `products`, `artists`, `categories`.

## Entities and the fields this feature reads

| Entity | Fields read | Notes |
|--------|-------------|-------|
| `StockMovement` | id, variant_id, type, qty_change, stock_before, stock_after, reason, created_at, user | Append-only ledger; unchanged. `HasDataMode` scope applies. |
| `ProductVariant` | sku, variant_name, image_path, current_stock, sell_price, is_active, deleted_at | `image_url` accessor = own image, else product image, else null. Soft-deletable. |
| `Product` | name, image_path, artist_id, category_id, deleted_at | Soft-deletable. |
| `Artist` (seller) | id, name | Soft-deletable; a deleted seller still shows its name on history rows. |
| `Category` | id, name | Soft-deletable; same rule. |

## Derived values

| Value | Definition | Where computed |
|-------|------------|----------------|
| Movement row `current_stock` | the variant's live `current_stock` when the response is built (can differ from that row's `stock_after`) | `StockController::movements()` |
| Product `total_stock` | Σ `current_stock` over the product's **active, non-deleted** variants; `0` when none | `ProductController::index()` aggregate |
| Product `total_stock_value` | Σ (`current_stock` × `sell_price`) over the same variants; money string `"0.00"` when none | `ProductController::index()` aggregate |

Totals are never stored. Negative stock (if present) is summed as-is.

## Validation rules

- `search`: string, trimmed; empty after trim = no filter; `%` and `_` treated literally.
- `artist_id[]`, `category_id[]`: arrays of integers; non-numeric entries ignored; unknown ids give an empty result, never an error.
- Combined with existing `type`, `date_from`, `date_to`, `variant_id` by AND; pagination `per_page` capped at 100 as today.

## State transitions

None. This feature performs no writes.

## Invariants to preserve

- DEMO/LIVE rows never mix in the list or totals (relation aggregates and Eloquent `whereHas` keep the global scopes; no raw `DB::table`).
- Soft-deleted variants/products with history remain visible on the ledger and reachable through History.
- Cost price is never selected into any new field.
