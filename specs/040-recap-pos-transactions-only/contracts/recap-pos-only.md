# Contract: Seller Recap, POS only (feature 040)

Auth: unchanged — `canAccessMenu('reports')`, otherwise `403`. Money as 2-dp strings.

## GET /api/v1/reports/artist-settlements?event_id=

`200 {event, data[]}` — one row per active seller (plus inactive/deleted sellers that hold a settlement row for the event).

```json
{
  "id": 12, "artist_id": 3, "artist_name": "sapphirefiless",
  "total_sales": "30000.00", "total_units": 1,
  "deduction": "0.00", "payable_amount": "30000.00",
  "paid_amount": "0.00", "outstanding": "30000.00", "status": "unpaid"
}
```

- `total_sales` / `total_units` count **completed, non-voided POS order items only** (event + active data mode). Pre-orders contribute nothing.
- `outstanding = max(0, payable_amount − paid_amount)`; never negative.
- **Removed** (feature 033 fields): `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales`.
- A seller without a row: `id: null`, all money `"0.00"`, `total_units: 0`, `status: "unpaid"` (unchanged).

## GET /api/v1/reports/artist-settlements/{artist}/transactions?event_id=

`200 {event, artist, transactions[]}`; each transaction `{key:"order-<id>", number, created_at, items[], amount_for_artist}`. POS only, only the seller's own items; Σ `amount_for_artist` = that seller's `total_sales` in the list above. **Removed**: `source`, `preorder-*` entries.

## GET /api/v1/reports/artist-settlements/export?event_id=

xlsx, two sheets. "Rekap" headings: `id, artist_id, artist_name, total_sales, total_units, deduction, payable_amount, paid_amount, outstanding, status`. "Detail Transaksi" unchanged.

## POST /api/v1/reports/artist-settlements/{settlement}/payment

Unchanged (`amount >= 0.01`; `403` without the reports menu). Status is derived from `paid` vs the POS-only `payable_amount`.

## Unchanged on purpose

`GET /reports/preorders*`, `GET /reports/profit`, `GET /reports/artist-profit`, `GET /reports/sales`, Pre-orders screen. The Dashboard's per-seller panel reads `total_sales` above and therefore becomes POS-only.

## Follow-up (2026-10-06) — supersedes the fields/endpoints above

- `GET /reports/artist-settlements` rows are ONLY `{artist_id, artist_name, total_sales, total_units}` (no `id`, `deduction`, `payable_amount`, `paid_amount`, `outstanding`, `status`).
- Export "Rekap" sheet headings: `artist_id, artist_name, total_sales, total_units`. "Detail Transaksi" unchanged.
- `POST /reports/artist-settlements/{settlement}/payment` is **removed** (404).
