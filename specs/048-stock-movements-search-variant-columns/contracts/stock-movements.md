# Contract: `GET /api/v1/stock/movements` (additive change)

**Access (unchanged)**: menu `stock` OR `products`; otherwise `403`.

## New optional query parameters

| Name | Type | Meaning |
|------|------|---------|
| `search` | string | Partial, case-insensitive match on variant SKU, variant name, or product name. Blank = ignored. `%`/`_` literal. |
| `artist_id[]` | integer[] | Keep movements whose variant's product belongs to any of these sellers. Empty = all. |
| `category_id[]` | integer[] | Same, by category. |

Existing parameters (`variant_id`, `type`, `date_from`, `date_to`, `page`, `per_page`) are unchanged and combine with the above by AND. Paging meta reflects the filtered total.

## New fields on each `data[]` row (all additive)

| Field | Type | Notes |
|-------|------|-------|
| `image_url` | string \| null | Variant picture, else product picture, else `null` (SPA shows a placeholder). |
| `artist_id` | integer \| null | Seller of the variant's product. |
| `artist_name` | string \| null | Present even if the seller was deleted. |
| `category_id` | integer \| null | |
| `category_name` | string \| null | |
| `current_stock` | integer | Live stock of the variant at response time; not the row's `stock_after`. |

Existing fields (`id, variant_id, sku, type, qty_change, stock_before, stock_after, reason, created_at, user_name, variant_name, product_id, product_name, reference`) are unchanged.

## Behaviour guarantees

- Movements of soft-deleted variants/products are still returned and searchable; `product_id` stays `null` for a deleted product (today's rule).
- No cost-related field is ever returned.
- Constant query count regardless of `per_page` (eager-loaded relations).
- Response without any new parameter equals today's response plus the new row fields.

## Example

`GET /api/v1/stock/movements?search=slip&artist_id[]=3&category_id[]=2&type=adjustment`

```json
{
  "data": [{
    "id": 481, "variant_id": 77, "sku": "SPF-KC-MCYT-3", "type": "adjustment",
    "qty_change": -16, "stock_before": 20, "stock_after": 4, "reason": "update real stock",
    "created_at": "2026-10-27T14:21:00+07:00", "user_name": "Chan",
    "variant_name": "Slippery 5cm", "product_id": 12, "product_name": "MCYT",
    "reference": null,
    "image_url": "/storage/variants/abc.jpg", "artist_id": 3, "artist_name": "Seller A",
    "category_id": 2, "category_name": "Keychain", "current_stock": 4
  }],
  "meta": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1 }
}
```

(Values illustrative.)
