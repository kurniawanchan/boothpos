# API Deltas: Preorder List Filters, Seller Info & Receipt-Style Invoice

All changes are additive to existing endpoints, plus one new endpoint. No breaking changes to
any existing field or response shape any current caller relies on.

## `GET /preorders` (existing — `PreorderController::index()`)

**New query param**: `artist_id` (integer, optional) — filters to preorders having at least
one item belonging to that artist. Combines (AND) with the existing `status`, `event_id`,
`customer_id`, `fulfillment`, and `search` params, per FR-001 and Edge Cases.

**New response field per row**: `sellers: [{ id: number, name: string }]` — the distinct
sellers across that preorder's items (see data-model.md). Existing fields (`id`,
`preorder_number`, `customer_name`, `status`, `fulfillment`, `total_amount`, `paid_amount`,
`outstanding`, `created_at`) are unchanged.

## `GET /preorders/{preorder}` (existing — `PreorderController::show()`) and `POST /preorders` / the invoice endpoint's shared `present()`

**New response field**: `sellers: [{ id, name }]` at the top level (same shape as the list),
and **each entry in `items[]` gains** `artist_id: number|null` and `artist_name: string|null`.
All other existing `present()` fields are unchanged.

## `GET /preorders/{preorder}/invoice` (existing — `PreorderController::invoice()`)

Inherits the `sellers` / `items[].artist_id` / `items[].artist_name` additions above via the
shared `present()` method — no separate change needed. `document_type` and every other
existing field are unchanged.

## `GET /preorders/summary` (NEW)

Owner/admin and cashier/inventory alike — same authorization as `GET /preorders` itself (no
stricter gate; this is a read-only aggregate of data the caller can already see row-by-row).

**Query params**: identical set to `GET /preorders`'s filters — `status`, `event_id`,
`customer_id`, `fulfillment`, `search`, `artist_id` — applied via the same shared
`applyFilters()` helper `index()` uses (see research.md R4), so the two endpoints can never
silently disagree about what "currently filtered" means.

**Response**:

```json
{
  "transaction_count": 12,
  "by_status": [
    { "status": "ordered", "count": 3, "total_amount": "450000.00" },
    { "status": "dp_paid", "count": 4, "total_amount": "1200000.00" },
    { "status": "arrived", "count": 0, "total_amount": "0.00" },
    { "status": "settled", "count": 0, "total_amount": "0.00" },
    { "status": "handed_over", "count": 2, "total_amount": "890000.00" },
    { "status": "cancelled", "count": 3, "total_amount": "150000.00" }
  ],
  "grand_total": "2690000.00",
  "total_outstanding": "1650000.00"
}
```

- Each `by_status` entry carries both `count` (number of preorders in that status within the
  filtered set) and `total_amount` (their summed order value) — "total per status" means the
  transaction quantity per status, not only its money total, per user clarification during
  execution.
- `by_status` always includes all six statuses (even at `"0.00"`), so the frontend never has
  to guess which statuses are missing from a short list — same "always list the full
  dimension, zero-fill the rest" convention `GET /reports/artist-settlements` already
  established for artists with zero sales.
- `total_amount`/`grand_total`/`total_outstanding` follow the existing Money-as-string
  convention (`number_format(...,2,'.','')`) used everywhere else in this API.
- `total_outstanding` is `SUM(total_amount) - SUM(paid_amount)` across the filtered set —
  **not** a sum of each row's already-rounded `outstanding` string, to avoid compounding
  per-row rounding into the aggregate (compute from the raw decimal sums, then format once).

## `docs/openapi-pos-mvp.yaml`

Must be updated in the same commit as the above (PRD §9.5, CLAUDE.md convention): the new
`artist_id` param and `sellers` field on `/preorders` and `/preorders/{preorder}`, the
`items[].artist_id`/`items[].artist_name` additions, and the new `/preorders/summary` path.
