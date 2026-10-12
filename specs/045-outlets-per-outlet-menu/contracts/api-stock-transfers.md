**Classification**: INTERNAL

# Contract: Stock per outlet and transfers (Stages 5–6)

## Stock per outlet (menu `stock` / `products`)

| Route | Change |
|---|---|
| `GET /stock` (list) | rows carry `outlets:[{outlet_id,code,on_hand}]` for the visible outlets and `total`; `?outlet_id[]=` narrows; a single effective outlet ⇒ `on_hand` only |
| `GET /stock/movements` | adds `outlet_id`, `outlet_code`; `?outlet_id[]=` filter; references resolve `stock_transfer` |
| `POST /stock/adjustments` | body gains `outlet_id` (required when several visible; default the effective one); reason rules unchanged; writes one ledger row for that outlet |
| `GET /stock/low` | per (item, outlet): `{item, outlet, on_hand, threshold}` |
| materials stock endpoints | same `outlet_id` handling |
| `POST /imports/master-data` `stock` sheet | column `outlet_code` (required when several outlets); absolute balance **per outlet** → adjustment movements for that outlet |

`product_variants.current_stock` in other responses remains the cached total on-hand (excludes in-transit).

## Transfers (permissions `stock.transfer.dispatch|receive|cancel` + outlet assignment)

| Route | Purpose |
|---|---|
| `GET /stock/transfers?status=&outlet_id[]=&from=&to=` | paginated list `{id, number, from_outlet, to_outlet, status, has_discrepancy, line_count, dispatched_at/by, received_at/by}`; limited to transfers where either outlet is permitted |
| `GET /stock/transfers/{id}` | detail with lines (`kind, item, qty_sent, qty_received, discrepancy_reason`), steps (who/when), ledger rows, warnings (`not_carried_at_destination` per line) |
| `POST /stock/transfers` | body `{ from_outlet_id, to_outlet_id, note?, client_ref?, lines:[{kind,item_id,qty}] }` — creates **and dispatches** (source ∈ permitted; both active, different; each qty ≤ source balance for tracked items). `201` with the transfer. Idempotent on `client_ref` (`200` replay) |
| `POST /stock/transfers/{id}/receive` | body `{ lines:[{line_id, qty_received, reason?}] }` (omitted ⇒ receive everything as sent); destination ∈ permitted; short line needs `reason`; more than sent ⇒ 422; second receive ⇒ 409 |
| `POST /stock/transfers/{id}/cancel` | only `in_transit`; source ∈ permitted; returns goods to the source |
| `GET /stock/transfers/discrepancies` | transfers with shortfalls (line, qty, reason, who) |
| `GET /stock/in-transit?outlet_id[]=` | quantities currently in transit per item/material, by from→to |

## Invariants (asserted by tests)

I1 balance = Σ its ledger rows · I2 cached total = Σ outlet balances · I3 Σ balances + in-transit + recorded shortfalls constant over dispatch/receive/cancel; no tracked balance < 0 (two simultaneous dispatches of the last units: one succeeds, one `409 insufficient_stock`).

## Errors

`409 insufficient_stock` (names item, outlet, available) · `409 transfer_not_in_transit` · `409 transfer_already_received` · `422` same-outlet / inactive outlet / qty ≤ 0 / over-receipt · `403` missing permission or outlet · `404` outside scope.

## Audit

Every step writes an `activity_logs` row (`stock_transfer_dispatched|received|cancelled`) inside the same transaction as its stock movements.
