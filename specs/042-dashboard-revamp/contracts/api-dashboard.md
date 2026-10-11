**Classification**: INTERNAL

# Contract: Dashboard API

All routes are under `/api/v1`, inside the existing `auth:sanctum` group, and require the **`dashboard`** menu (`canAccessMenu('dashboard')` → else 403). Money is a string `"0.00"`; counts are integers; timestamps are ISO 8601 with offset; business dates are `YYYY-MM-DD`. Every response is filtered by the active DEMO/LIVE mode; from Stage B also by the caller's permitted outlets.

Common query parameters: `period` = `today` (default) | `7d` | `30d`; `outlet_id` (Stage B; omitted = all the caller may see; an outlet the caller may not use → `403`).

## GET /dashboard/summary

```json
{
  "computed_at": "2026-10-11T12:30:04+07:00",
  "business_date": "2026-10-11",
  "period": "today",
  "range": {"from": "2026-10-11", "to": "2026-10-11"},
  "comparison": {"basis": "same_time_yesterday", "from": "2026-10-10", "to": "2026-10-10", "up_to": "12:30"},
  "scope": {"all_outlets": true, "outlet_ids": []},
  "kpis": {
    "net_sales":  {"value": "18420500.00", "comparison": "17247000.00", "change_pct": 6.8,  "series": [{"t": "00:00", "v": "0.00"}], "comparison_series": [{"t": "00:00", "v": "0.00"}]},
    "orders":     {"value": 486, "comparison": 466, "change_pct": 4.3, "series": [], "comparison_series": []},
    "avg_order_value": {"value": "37902.00", "comparison": "37020.00", "change_pct": 2.4, "series": [], "comparison_series": []},
    "low_stock":  {"value": 7, "comparison": 4, "change_abs": 3, "scope": "organisation", "series": [], "comparison_series": []}
  },
  "devices": {"behind_count": 0, "items": []}
}
```

- `comparison.basis`: `same_time_yesterday` for `today`; `previous_period` for `7d`/`30d` (`up_to` omitted).
- Series points: `t` is `HH:00` for `today`, a date for `7d`/`30d`. `series` stops at the current hour; `comparison_series` covers the whole comparison range.
- `change_pct` is `null` when the comparison is `0`/absent (client shows "no comparison"); never `NaN`. `low_stock` uses `change_abs`; a rise is bad.
- `kpis.orders.value` counts **all statuses** (research D4); `avg_order_value` = net ÷ completed orders (`null` value when there are none).
- `devices` is present from Stage B only; Stage A omits the key. `items[]` = `{device_id, name, outlet_id, outlet_name, offline_minutes, pending_events, offline_cash}` (advisory figures).
- A figure the caller may not see is omitted, never zero.
- Cached ≤ 30 s per `[mode, outlet set, period]`; `computed_at` is when it was computed.
- Errors: `403` no menu / forbidden outlet; `422` bad `period`.

## GET /dashboard/attention

```json
{
  "computed_at": "2026-10-11T12:30:04+07:00",
  "business_date": "2026-10-11",
  "count": 3,
  "scope": {"all_outlets": true, "outlet_ids": []},
  "items": [
    {"kind": "payments_pending", "severity": "warn", "count": 4, "amount": "75900.00",
     "meta": {"by_method": {"qr_ewallet": 3, "bank_transfer": 1}},
     "link": {"route": "sales", "query": {"pstate": "pending"}}},
    {"kind": "void_after_fire", "severity": "warn", "count": 9, "amount": "214000.00", "amount_unknown_count": 2,
     "meta": {"share_of_gross_pct": 1.1}, "link": {"route": "activity-log", "query": {"action": "item_cancelled", "date": "2026-10-11"}}},
    {"kind": "cash_difference", "severity": "critical", "count": 1, "amount": "-23500.00",
     "meta": {"tolerance": "5000.00", "session_id": 17}, "link": {"route": "session", "query": {"id": 17}}},
    {"kind": "low_stock", "severity": "warn", "count": 7, "amount": null, "meta": {}, "link": {"route": "stock", "query": {"low": 1}}}
  ]
}
```

- Always returns **every kind**; an item with nothing to do has `severity: "ok"` and `count: 0` (UI shows its all-clear line). `count` at the top = number of items whose severity is not `ok`.
- `kind` ∈ `payments_pending`, `void_after_fire`, `cash_difference`, `low_stock`, and (Stage B) `device_offline` with `meta: {device, outlet, offline_minutes, pending_events, offline_cash, limit, exposure_pct}`; `severity` is `critical` when `exposure_pct > 100`.
- `link` is `null` when the caller lacks the destination menu. The destination page must list exactly the records counted (SC-003); a test per kind compares the item count to the destination's row count.
- Definitions: research D6. Items describe the **current business date**, not the period.

## GET /dashboard/orders

Query: `tab` = `all|open|partial|paid|cancelled` (default `all`), `q` (matches order number, table name, item name; min 1 char), `type[]` = `retail|dine_in|takeaway`, `method[]` = `cash|bank_transfer|qr_ewallet`, `pstat[]` = `unpaid|pending|partial|paid`, `from`/`to` (business dates; default: the period's range), `outlet_id`, `page`, `per_page` (default 25, max 100), `sort` (`-created_at` default).

```json
{
  "data": [{
    "id": 812, "order_number": "A000141", "type": "dine_in", "table": "T02", "items_summary": "Kopi susu ×2, Roti bakar",
    "status": "completed", "tab": "paid", "payment_state": "paid", "methods": ["qr_ewallet"],
    "total_amount": "75900.00", "paid_amount": "75900.00", "business_date": "2026-10-11",
    "at": "2026-10-11T12:21:09+07:00", "outlet_id": null
  }],
  "meta": {"current_page": 1, "per_page": 25, "total": 486, "last_page": 20},
  "counts": {"all": 486, "open": 12, "partial": 9, "paid": 458, "cancelled": 7},
  "hidden_tabs": ["bill", "closed"]
}
```

- `counts` are computed with the **same filters except `tab`** (so each tab count equals the rows that tab shows with the other filters unchanged); with no other filter they satisfy SC-005.
- `payment_state` derives from `PaymentSummary` (set-based fragment, parity-tested); `paid_amount` = total paid (tendered minus change), not `orders.paid_amount`.
- `hidden_tabs` lists design tabs that have no data in this product, so the UI never shows a permanently-zero tab.
- No customer contact fields. Row detail opens the existing `GET /orders/{id}`.
- Errors: `403`; `422` invalid tab/filter values.

## GET /dashboard/orders/export

Same query as above (ignores `page`/`per_page`), responds `text/csv; charset=utf-8` with `Content-Disposition: attachment`. Columns = the table's columns plus `items_summary`; no contact data. Row set equals the screen's filtered set (test: compare with `/dashboard/orders` over the full range). `403` as above.

## GET /dashboard/export

Query: `period`, `outlet_id`. `text/csv`: header lines (`computed_at`, `period`, `range`, `comparison basis`), the four KPIs (value, comparison, change), the trend points of each, then the attention items. Numbers match `/dashboard/summary` and `/dashboard/attention` computed in the same request.

## Conventions

- Controllers validate and delegate to `DashboardService`, `AttentionService`, `DashboardOrdersQuery` (Constitution I); no business logic in `DashboardController`.
- `docs/openapi-pos-mvp.yaml` gains these five paths in the same commit as the routes.
- Rate: the three read endpoints are polled every 30 s per open Dashboard; no throttle change needed at the expected scale (ASSUMPTION).
