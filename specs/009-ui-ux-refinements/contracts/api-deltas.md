# API Contract Deltas: UI/UX Refinements Batch

All deltas below MUST be reflected in `docs/openapi-pos-mvp.yaml` in the same
commit (Documentation & Change Discipline, CLAUDE.md/Constitution). Status
codes follow the existing convention: `422` validation, `409` business-rule
conflict, `403` role/ownership denial.

## DELETE /events/{event}

- **Auth**: `EventPolicy::delete` — owner/admin only (matches Event's existing management tier).
- **Guard**: `409` with a translated conflict message if the event has any `orders` or `preorders` (any status, including soft-deleted).
- **Success**: `204`, event soft-deleted, `ActivityLogger` entry written in the same transaction.
- **Errors**: `403` (role), `404` (not found / wrong data_mode), `409` (has transactions).

## DELETE /customers/{customer}

- **Auth**: `CustomerPolicy::delete` — owner/admin only (matches Customer's existing management tier).
- **Guard**: `409` with a translated conflict message if the customer has any `orders` or `preorders` (any status, including soft-deleted).
- **Success**: `204`, customer soft-deleted, `ActivityLogger` entry written in the same transaction.
- **Errors**: `403`, `404`, `409`.

## GET /customers/{customer}/transactions

- **Auth**: same read tier as existing `GET /customers` (owner/admin/cashier/inventory — matches current customer-read access; no widening).
- **Response**: `{"data": [{ "type": "order"|"preorder", "id": int, "number": string, "status": string, "total_amount": string, "date": string(ISO8601) }, ...]}`, sorted by `date` descending, merged across both types.
- **Errors**: `403`, `404` (customer not found / wrong data_mode).

## GET /reports/sales?group_by=customer

- **Auth**: unchanged (`canAccessMenu('reports')`, same as every other `group_by` value on this endpoint).
- **New enum value**: `group_by=customer`, alongside existing `product|category|artist|day|event`.
- **Response shape**: same envelope as the existing `artist` grouping branch, with rows shaped `{ customer_id, customer_name, transaction_count, total_amount }`.
- **Errors**: unchanged (`422` for an unrecognized `group_by` value, existing behavior).

## GET /reports/stock-by-artist (existing) — new drilldown

- **New param**: optional `artist_id` (int). When present, response returns variant-level rows for that one artist: `{ "artist_id", "artist_name", "variants": [{ "variant_id", "sku", "variant_name", "current_stock" }], "variant_count", "total_stock" }`. When absent, response is unchanged (existing per-artist summary array).
- **Auth**: unchanged (`canAccessMenu('reports')`).
- **Errors**: `404` if `artist_id` doesn't resolve within the active data mode; unchanged otherwise.

## No changes

- `POST/PUT /events`, `POST/PUT /customers` — unchanged.
- `GET /orders/{order}` — reused as-is by the Sales page's new `TransactionItemsModal` (existing eager-loaded item detail, no shape change).
- Artist-related endpoints (`/artists*`) — unchanged; the rename is display-label-only (see research.md R5) and touches no route, field, or response shape.
