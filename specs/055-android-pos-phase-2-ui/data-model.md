# Data Model: 055

## Category (server, existing, mode-scoped)

| Field | Source | Notes |
|---|---|---|
| id, code, name | `categories` | Existing. `code` unique across modes (feature 003 rules). |
| display_order | `categories` | Existing. Used for ordering in the strip, then name. |
| is_active | `categories` | Only active categories are returned. |
| data_mode | `categories` (`HasDataMode`) | Filtered by the server's active mode. |
| product_count | computed (`withCount` of active products in the mode) | Read-only. Zero is allowed and shown. |

**Response (POS view)**: `{ data: [{ id, code, name, display_order, product_count }] }`. No pagination (categories are few).

## Tablet state (new/changed)

| Name | Where | Holds |
|---|---|---|
| `menu.categories` | `PosStore` / `MenuCatalog` | From `GET /pos/categories`, not derived from products. |
| `menu.products` | `PosStore` / `MenuCatalog` | Unchanged (`GET /products?pos=1`). |
| `TotalsPreference.visible` | shared preferences `counter_totals_visible` | Boolean, default true. Device-local. |
| `TaxDisplay` | `PosStore` from `GET /settings/features` | `label` (`tax_label`), `ratePercent` (`tax_rate`). Display only. |

## Validation and rules

- A category with `product_count = 0` stays in the strip (spec FR-004 says all categories are shown).
- A product whose `category_id` has no entry in the category list is shown under an "Lainnya" group; not dropped (spec edge case: no silent loss).
- Tax values are never negative; if `tax_rate` is missing, the tax line shows the label only, not a guessed rate.
