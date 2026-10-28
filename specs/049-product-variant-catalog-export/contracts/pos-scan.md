# Contract: POS scan-to-add

## `GET /api/v1/variants/lookup` (additive)

New optional query parameter:

| Name | Type | Meaning |
|------|------|---------|
| `exact` | boolean | `1`/`true`: ignore the LIKE search and return only the **active** variant whose SKU equals `q` (leading/trailing spaces trimmed; letter case ignored by the database collation). At most one row. Empty `q` returns `[]`. |

Response shape is identical to today's (`variant_id, sku, label, artist_name, category_name, sell_price, current_stock, is_preorder, image_url`). Without `exact`, behaviour is unchanged. Access is unchanged (any authenticated user who can use the POS).

## POS search box behaviour (UI contract)

| Input on Enter | Result |
|----------------|--------|
| Text equal to exactly one active variant's SKU, stock > 0 | One unit added to the cart (same cart line as tapping the search card); box cleared and still focused. |
| Same code, variant already in cart | Its quantity increases by 1. |
| Same code, `current_stock <= 0` | Nothing added; warning toast ("out of stock"). |
| Text matching no SKU | Nothing added; warning toast ("code not found"). |
| Partial text / names | No automatic add; the normal search results are shown. |
| Empty text | Ignored. |

- No change to prices, totals, discounts or stock logic; `POST /orders` still recomputes everything server-side.
- No open cashier session: the item is still added (tap parity); the existing "open a session" banner and the payment block stay.
- Typing into other fields (customer, payment, discount) is never captured by the scan handler (the handler is bound to the search input only).
- A pending debounced search is cancelled when Enter is handled (existing `searchSeq` guard).
