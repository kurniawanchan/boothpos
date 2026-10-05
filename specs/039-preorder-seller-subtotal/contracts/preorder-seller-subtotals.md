# Contract: Pre-order report — seller subtotals

Delta against `docs/openapi-pos-mvp.yaml` (updated in the same commit).

## GET /api/v1/reports/preorders?breakdown=artist — response gains `subtotals`

Auth: unchanged (owner/admin reports gate).

```json
{
  "rows": [ { "artist_id": 3, "artist_name": "sapphirefiless", "status": "ordered", "payment_completeness": "unpaid", "preorder_count": 13, "total_order_value": "930000.00", "total_collected": "0.00", "total_outstanding": "930000.00" } ],
  "subtotals": [ { "artist_id": 3, "artist_name": "sapphirefiless", "preorder_count": 28, "total_order_value": "1920000.00", "total_collected": "1239000.00", "total_outstanding": "935000.00" } ]
}
```

- `rows` is unchanged (existing clients keep working); `rows` are now guaranteed contiguous per seller.
- `subtotals[i]` = Σ over the rows with the same `artist_id`; money cents-exact; outstanding is the sum of the rows' (clamped) outstanding values.
- The default (non-`breakdown`) summary response is unchanged.

## GET /api/v1/reports/preorder/export — "Per Seller" sheet (existing route, `report=preorder`)

After the last row of each seller, one subtotal row:

| artist_id | artist_name | status | payment_completeness | preorder_count | total_order_value | total_collected | total_outstanding |
|---|---|---|---|---|---|---|---|
| *(empty)* | `Subtotal — <seller>` | *(empty)* | *(empty)* | Σ | Σ | Σ | Σ |

The Summary sheet, sheet names, file name and column order are unchanged; no grand-total row is added.
