# Contract: `GET /api/v1/variants/lookup` (behaviour unchanged, documented)

Used by the POS search box (and the pre-order "Add item" picker). Query: `q` (optional since 024; matches SKU, variant name or product name), `limit` (default 20, max 50). Active variants only. Any authenticated role.

Each item of `data[]`:

| Field | Type | Notes |
|---|---|---|
| `variant_id` | integer | |
| `sku` | string | |
| `label` | string | `"<product name> — <variant name>"` |
| `artist_name` | string | |
| `category_name` | string \| null | already returned; **now documented** |
| `sell_price` | string | money, 2 decimals |
| `current_stock` | integer | |
| `is_preorder` | boolean | |
| `image_url` | string \| null | already returned; **now documented**. Variant's own photo, else the parent product's, else `null` (the `ProductVariant::image_url` accessor). |

**What the POS relies on (and the new tests pin)**: `image_url` follows the variant → product → null rule, so a search card and the browse grid show the same photo for the same item.
