# Contract: `GET /api/v1/products` (additive change)

**Access (unchanged)**: `viewAny` policy on Product. No new parameters. Default payload stays lean; `?with_variants=1` behaviour unchanged.

## New fields on each `data[]` row — list endpoint only

| Field | Type | Definition |
|-------|------|------------|
| `total_stock` | integer | Σ `current_stock` over the product's active, non-deleted variants; `0` if none. |
| `total_stock_value` | string (money) | Σ `current_stock × sell_price` over the same variants, formatted `number_format((float) $x, 2, '.', '')`; `"0.00"` if none. Potential revenue at current selling prices. |

## Behaviour guarantees

- The two fields are **absent** from `GET /products/{id}`, `POST /products`, `PUT /products/{id}` responses (they are only computed by the list query). Consumers must not read them from those responses.
- DEMO and LIVE data are never summed together; soft-deleted variants are excluded; inactive variants are excluded.
- Cost price is neither used nor derivable from these fields.
- Query count is constant for any page size (aggregates ride the list query).

## Example row (extra fields only)

```json
{ "id": 12, "name": "MCYT", "total_stock": 14, "total_stock_value": "280000.00" }
```

(Variants with stock 4, 10, 0 at 20000 each → 14 units, 280000.00. Values illustrative.)
