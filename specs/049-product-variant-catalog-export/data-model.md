# Data Model: Product Variant Catalog Export (PDF and Excel)

**No schema change, no migration, nothing stored.** The catalog is generated on demand from existing tables: `products`, `product_variants`, `artists` (sellers), `categories`, `settings` (store identity).

## Source entities and the fields read

| Entity | Fields read | Rule |
|--------|-------------|------|
| `Product` | id, name, image_path, artist_id, category_id, is_active, deleted_at | Only active, non-deleted. DEMO/LIVE scope applies automatically. |
| `ProductVariant` | id, sku, variant_name, sell_price, image_path, is_active, deleted_at | Only active, non-deleted. **`cost_price`, `current_stock`, BOM data are never read.** |
| `Artist` | id, name | Grouping key; soft-deleted seller with active products still shows its name (as on the Stock page). |
| `Category` | id, name | Shown per variant row/card. |
| Settings | store name (DEMO/LIVE aware), logo, address, contact person/phone/email | Via `BuildsInvoiceDocument::buildStoreIdentity()`. |

## Generated structure (response of `GET /catalog`)

```text
Catalog
├── generated_at        ISO-8601 timestamp
├── store               { name, logo_url|null, address|null, contact_person|null, contact_phone|null, contact_email|null }
├── totals              { sellers: int, variants: int }
└── sellers[]           ordered by seller name (case-insensitive)
    ├── artist_id, name
    └── variants[]      ordered by category name, product name, variant name
        ├── variant_id
        ├── category_name | null
        ├── product_name
        ├── variant_name
        ├── code                    the SKU, exactly as POS resolves it
        ├── sell_price              money string "20000.00"
        ├── image_url | null        variant image, else product image, else null
        └── barcode                 { type: "code128", pattern: "1101001000011...", text: "<code>" } | null
```

## Barcode value object

| Field | Meaning |
|-------|---------|
| `type` | Always `code128` in this iteration. |
| `pattern` | String of `1` (bar) and `0` (space) modules: start code, encoded data, checksum, stop. The renderer adds the quiet zone. |
| `text` | The encoded value, printed beneath for manual entry. |

`barcode` is `null` only when the code contains a character Code 128 cannot encode (printable ASCII only); the variant is still listed with its readable code.

## Validation rules

- Filters: `search` (string), `artist_id[]`, `category_id[]` — same semantics as the Products list; non-numeric ids ignored.
- Result must contain ≥ 1 variant for export; otherwise `GET /catalog/export` answers `422` and `GET /catalog` returns `sellers: []`.
- Hard ceiling of 2,000 variants per catalog (`422` beyond it).
- Prices are always the selling price formatted `number_format((float) $x, 2, '.', '')`; money display (Rupiah) is a presentation concern.

## State transitions

None. Read-only; the only mutation in the feature is the existing POS cart (scan adds the same line a tap adds).

## Invariants

- Each active variant of an included product appears **exactly once**, under its seller.
- The PDF and Excel come from the same builder output, so their content cannot diverge.
- No cost, margin, stock level or preorder status in any output.
- DEMO and LIVE rows never mix.
