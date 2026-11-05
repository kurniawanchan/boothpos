**Classification: INTERNAL**

# Contracts

## 1. `PATCH /api/v1/preorders/{id}/status` (behaviour change, shape unchanged)

Request and response shapes are unchanged. Auth, `ability:preorders,status` gate and 409 conflict mapping are unchanged.

| Request `status` | Before | After |
|---|---|---|
| `arrived` | 200; stock `+qty`; response status `arrived` | 200; **no stock change**; response status is `arrived`, or `settled` when the pre-order is already fully paid |
| `settled` | unchanged | unchanged |
| `handed_over` | 200 and `-qty`, or 409 when not fully paid, or 409 when stock would go below zero | unchanged |

Notes for API consumers:
- After `arrived`, read `status` from the response; it may be `settled`.
- A move to `arrived` no longer fails because of stock.
- `409` on `handed_over` for insufficient stock keeps the existing message naming the SKU and available stock.

## 2. `PUT /api/v1/preorders/{id}` (behaviour change, shape unchanged)

Editing items of a pre-order in `arrived` or `settled` no longer writes a stock movement and no longer changes stock. All other validation is unchanged.

## 3. Artisan: `app:settle-paid-arrived-preorders`

```text
php artisan app:settle-paid-arrived-preorders                    # dry-run (default)
php artisan app:settle-paid-arrived-preorders --apply --by=owner # apply
```

| Option | Meaning |
|---|---|
| (none) | Lists the pre-orders that would be settled: mode, preorder number, total. Writes nothing. Exit 0. |
| `--apply` | Settles them. Requires `--by`. |
| `--by=<username>` | Existing active user with owner/admin rights; recorded as the actor. Missing or not allowed: error, exit non-zero, nothing written. |

Behaviour:
- Handles DEMO and LIVE.
- One transaction per pre-order: status `arrived → settled` plus one `preorder_status_corrected` activity-log row. A failure on one pre-order is reported and does not stop the rest.
- No stock, payment, total or notification effect.
- Idempotent: a second run reports 0 pre-orders.
- Output contains pre-order numbers and counts only.

Docker: `docker compose exec app php artisan app:settle-paid-arrived-preorders --apply --by=owner`.

## 4. Frontend text

`preorders.arrived_marked` (EN and ID) no longer claims stock increased.
