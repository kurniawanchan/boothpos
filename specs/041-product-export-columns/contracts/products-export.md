# Contract: `GET /exports/products` (products sheet) — rewritten 2026-10-16

**Endpoint**: `GET /api/v1/exports/products` (existing route)
**Auth**: `canManageMasterData()` (owner/admin/inventory) — unchanged
**Query parameters** (new, products only): `search` (string), `artist_id[]` (int, repeatable), `category_id[]` (int, repeatable). Absent = no restriction. Other entities ignore them.
**Response**: `.xlsx`, filename `data-produk.xlsx`, one sheet named `products`

## Header order (exact)

```
sku, code, artist_code, artist_name, category_code, category_name,
product_name, variant_name, item_label, description, total_stock_available,
cost_price, sell_price, profit_margin, low_stock_alert
```

## Guarantees
- One row per variant of every product matching the filters (no page limit).
- No filter → every variant.
- `code` = last 7 chars of `sku` (or whole SKU).
- `item_label` = `{code} - {category_name} {variant_name}`.
- `total_stock_available` = variant `current_stock`.
- `profit_margin` = `sell_price − cost_price`, 2 dp, may be negative.
- Removed headers never appear: product_segment, is_preorder, preorder_eta, product_is_active, variant_is_active, initial_stock, image_filename, variant_image_filename.
- Same products as `GET /products` for the same `search`/`artist_id[]`/`category_id[]` (shared scope).

## Not guaranteed
- Re-import through `POST /imports/master-data` (option A trade-off). Import and template are unchanged (17 product columns) — see `docs/openapi-pos-mvp.yaml`.
