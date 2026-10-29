# Contract: Catalog endpoints (new)

**Access (both)**: authenticated user with the `products` menu (`canAccessMenu('products')`); otherwise `403`. Enforced server-side; the SPA only hides the button.

## `GET /api/v1/catalog`

Returns the grouped catalog used to render the PDF.

| Query | Type | Meaning |
|-------|------|---------|
| `search` | string | Same as `GET /products` (product name, or SKU/variant name of a variant). |
| `artist_id[]` | integer[] | Seller filter; empty = all. |
| `category_id[]` | integer[] | Category filter; empty = all. |

**200** — structure in `data-model.md` ("Generated structure"). Example (abridged):

```json
{
  "generated_at": "2026-10-28T11:20:00+07:00",
  "store": { "name": "Demo Sakana Fridge", "logo_url": "/storage/store/logo.png", "address": "Jl. Contoh 1", "contact_person": null, "contact_phone": null, "contact_email": null },
  "totals": { "sellers": 1, "variants": 1 },
  "sellers": [{
    "artist_id": 3, "name": "Seller A",
    "variants": [{
      "variant_id": 77, "category_name": "Keychain", "product_name": "MCYT", "variant_name": "Slippery 5cm",
      "code": "SPF-KC-MCYT-3", "sell_price": "20000.00", "image_url": null,
      "barcode": { "type": "code128", "pattern": "11010010000...", "text": "SPF-KC-MCYT-3" }
    }]
  }]
}
```

**422** — more than 2,000 variants match (`message` asks the user to narrow the filters). **403** — no `products` menu.

Guarantees: only active products/variants; no `cost_price`, stock, BOM or preorder fields; DEMO/LIVE separation; sellers alphabetical, variants by category → product → variant name; the same filter semantics as the Products list.

## `GET /api/v1/catalog/export`

Same query parameters. Returns the workbook `katalog-produk.xlsx` (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`).

- Sheet **Katalog**: store block at the top (name, address, contact, generated-at), then for each seller a coloured heading row and its variant rows.
- Columns: Seller, Category, Product, Variant, Code, Price, Image, Barcode. Code/names/category are text, Price is numeric with a Rupiah number format, Image and Barcode are embedded pictures (thumbnail ≈160 px; barcode PNG rendered from the same pattern as the JSON).
- **422** when nothing qualifies (no empty workbook) or when the 2,000-variant ceiling is exceeded; **403** without the `products` menu.
- Column header language follows the requesting user's language.

## Compatibility

Both endpoints are new; no existing response changes.
