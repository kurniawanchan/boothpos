# Contract: GET /api/v1/pos/categories (055)

**Constitution**: the operation goes into `docs/openapi-pos-mvp.yaml` in the same commit as the route.

## Request

`GET /api/v1/pos/categories`

- **Auth**: signed-in user with the same gate as `GET /products?pos=1` (`ProductPolicy::viewAny`). Any role that sells on the counter may call it. Other roles → `403`.
- **Mode**: none sent. The server applies the active system mode (DEMO or LIVE) through `HasDataMode`.

## Response 200

```json
{
  "data": [
    { "id": 1, "code": "KP", "name": "Kopi", "display_order": 1, "product_count": 8 },
    { "id": 2, "code": "NK", "name": "Non-kopi", "display_order": 2, "product_count": 7 }
  ]
}
```

- Only `is_active` categories of the current mode.
- Ordered by `display_order`, then `name`.
- `product_count` counts active products of the same mode in that category; `0` is valid and the category is still returned.

## Errors

- `401` no session; `403` role without POS access.

## Not in this contract

- No write operations.
- No pagination (categories are few; a limit would hide categories, which the spec forbids).
