**Classification**: INTERNAL

# Contract: API changes (both additive)

Both routes already exist and are gated by the `floor` menu (403 otherwise). No new route, no new permission.

## `GET /api/v1/floor`

Unchanged: query `version` (optional) → `{unchanged: true, version}` when equal; otherwise the full snapshot.

Additions to the snapshot (see data-model.md for the full shape):

| Field | Where | Meaning |
|---|---|---|
| `paid_amount` | every open order (`tab` inside a table, and each `takeaway_tabs[]` row) | money string, `"0.00"` when nothing paid; derived paid total (rejected payments excluded, change subtracted) |
| inactive tables with an open order | `areas[].tables[]` | listed with `is_active: false` and `state: "occupied"`; inactive tables **without** an open order remain hidden |

Guarantees kept: constant query count independent of the number of tables; response contains no clock-dependent value (the `version` hash stays stable when nothing changed); `data_mode` scoping unchanged.

## `GET /api/v1/tabs/{order}`

Addition: `opened_by` — string|null, the opening cashier's name.

Unchanged and relied upon by the detail: `status`, `table {id,name,area}`, `tab_label`, `created_at`, `lines[]` (`name, qty, unit_price, line_total, modifiers, note, sent, prep_status, station`), `subtotal`, `discount_amount`, `total_amount`, `paid_amount`, `balance`, `payments[] {method, amount, verification, paid_at}`.

Status handling the UI depends on: `open` → live; `completed` → returned as-is (UI says "paid"); voided or retail → `404`.

Errors: `403` role without the `floor` menu; `404` as above.

## OpenAPI

`docs/openapi-pos-mvp.yaml` is updated in the same commit (the `/floor` response and the tab schema).
