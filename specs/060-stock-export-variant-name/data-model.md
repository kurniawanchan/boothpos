**Classification: INTERNAL**

# Data Model: Stock Export Row

No tables, columns or migrations change. This describes the shape of the exported `stock` sheet only.

## Stock export row (one per product variant)

| Column | Source | Notes |
|---|---|---|
| `sku` | `product_variants.sku` | Unchanged. Upsert key on import. |
| `variant_name` | `product_variants.variant_name` | **New.** Export-only, read-only. Live value. Empty cell when the name is blank. Ignored on import. |
| `current_stock` | `product_variants.current_stock` | Unchanged. Absolute value on import. |
| `reason` | none (empty) | Unchanged. Optional note used on import. |

## Rules

- Row set and order: all variants visible under the active DEMO/LIVE mode, ordered by `sku`, exactly as before.
- Import contract (`MasterDataSheets::headings('stock')`): still `sku, current_stock, reason`. Unchanged.
- Export contract (`MasterDataSheets::STOCK_EXPORT`, new): `sku, variant_name, current_stock, reason`.
- Text cells that start with `=` are stored as text (existing protection in `SheetArrayExport`).

## State transitions

None.
