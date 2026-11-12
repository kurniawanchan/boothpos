**Classification**: INTERNAL

# Contract deltas: 068-slip-notes-shipping-fee-stat

Only one HTTP response changes. `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit (constitution: Documentation & Change Discipline).

## `GET /preorders/summary` — one additive field

Request: unchanged (same filter parameters as `GET /preorders`; they already include `status`, `event_id`, `customer_id[]`, `category_id[]`, `fulfillment`, `dispatch_status[]`, `shipping_flag[]`, `search`, `artist_id`).

Response `200` (new field marked):

```json
{
  "transaction_count": 3,
  "by_status": [ { "status": "ordered", "count": 3, "total_amount": "150000.00" } ],
  "grand_total": "150000.00",
  "total_outstanding": "100000.00",
  "total_shipping_fee": "50000.00"
}
```

- `total_shipping_fee`: `Money` (2-decimal string). Sum of `shipping_cost` of the filtered, **non-cancelled**, **`fulfillment = courier`** pre-orders. `"0.00"` when none match. Same filtered set as `grand_total`; Self Pickup never contributes.
- Backward compatible: existing keys and semantics unchanged. The SPA treats a missing key as `0`.
- Status codes unchanged (200 / 401 / 403 by existing middleware).

OpenAPI edit: add under the 200 response properties, next to `total_outstanding`:

```yaml
total_shipping_fee: { $ref: '#/components/schemas/Money', description: "068 — jumlah ongkir (shipping_cost) pre-order Mail Order (fulfillment=courier) yang cocok dengan filter, tanpa yang 'cancelled' (sama seperti grand_total). Ambil sendiri tidak pernah ikut. '0.00' bila tidak ada yang cocok." }
```

## `POST /preorders/bulk-invoices` — NO change

The slip already receives `notes` (string or null) from `invoicePayload()`/`present()`. Documented here only so tasks do not add a field that exists: the contract for this endpoint is untouched.

## Frontend contract (internal)

`buildShippingSlipCellHtml(invoice)`: reads `invoice.notes` (string | null | undefined). Output contains the notes block only when it is non-blank; the text is passed through `escapeHtml()`; the block uses `white-space: pre-wrap`. All other output is byte-identical to today for an invoice without notes (pinned by a test).
