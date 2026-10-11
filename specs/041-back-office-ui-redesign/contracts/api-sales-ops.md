**Classification**: INTERNAL

# API contract — sales, cash, orders monitor, POS operations (Phase 5, 8)

Legend: **R** reason code required · **A** approval path.

## Sales records (Penjualan)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /sales-records?outlet_id=&method=&type=&q=&from=&to=` | immutable sales records (receipt no., time, outlet, cashier, type, items, gross, discount, tax, total, method) | `sal.order.view` | cost/margin only for callers who may see profit (existing rule) |
| `GET /sales-records/{id}` | full calculation (gross, manual discount, tax base, tax, rounding, total), configuration/tax version used, payments | `sal.order.view` | no write endpoint exists for closed records; any attempt returns `409 IMMUTABLE` |
| `POST /sales-records/{id}/reprint` | register a reprint and return the copy payload ("SALINAN") | `pos.reprint` | counts against the per-receipt limit; over limit → `403 REPRINT_LIMIT` unless approved (**A**) |
| `GET /sales/summary?business_date=&outlet_id=` | four page figures: net sales, returns & refunds, complimentary (notional), shift cash variance | `sal.order.view` | equals sums of the lists |
| `POST /sales/export-daily` | daily sales export | `sal.order.export` | goes through the export-history path (REP-057 in the design) |

## Returns and refunds

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /sales-returns` | create from an order/items (`items`, `reason_code_id`, `stock_decision`) | `sal.return.create` | **R**; never edits the original order; refund held until approved |
| `POST /sales-returns/{id}/approve` · `/reject` | decide | `sal.return.approve` | **A**; on approve the refund goes to the payment module; failure becomes status `failed` (visible, retryable) |
| `POST /sales-returns/{id}/retry-refund` | retry failed refund | `sal.return.approve` | idempotent by `client_ref` |
| `GET /sales-returns` | list | `sal.return.view` | |

## Complimentary and staff meal

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /complimentary` | record (`kind`, items, `employee_id` for staff meal, `reason_code_id`) | `sal.complimentary.create` | **R**; notional value computed server-side; staff meal over the per-employee daily limit → needs approval (**A**) |
| `GET /complimentary` | list, separate from revenue | `sal.complimentary.view` | excluded from all revenue totals |

## Shifts and cash

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /sessions?outlet_id=&status=` (existing, extended) | shifts with float, sales by method, expected cash, counted, variance | `pos.shift.view` | open shifts past schedule flagged |
| `POST /sessions/{id}/count` | blind cash count by denomination | session owner or `pos.shift.close` | expected cash hidden until submitted |
| `POST /sessions/{id}/close` (existing) | close | owner or `pos.shift.close` | `409` while open tabs exist (existing); variance beyond tolerance → pending approval |
| `POST /sessions/{id}/variance/approve` | accept variance | `pos.shift.variance.approve` | **A**, **R** (e.g. wrong change, cash-out not recorded, counterfeit, unknown → investigation) |
| `POST /sessions/{id}/force-close` | close a forgotten shift | `pos.shift.force_close` | **R**; audited |
| `POST /sessions/{id}/cash-movements` | cash in/out | `pos.cash.move` | **R**; above threshold → **A** |
| `GET /sessions/{id}/report?kind=x\|z` | X computed; Z stored at close | `pos.shift.view` | Z immutable |

## Orders and tables monitor (Pesanan)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /orders-monitor?status=&outlet_id=&q=` | open/pre-bill/partial/paid/closed/cancelled orders with counts per tab | `sal.order.view` | read-only; no create/edit endpoint here (orders change only through POS/tab endpoints) |
| `GET /orders-monitor/summary` | open orders, occupied tables (x/y, %), tables over N minutes, open value | `sal.order.view` | N from business rules (default 45) |
| `GET /floor` (existing) | table board; adds `seated_minutes`, `over_threshold`, `bill_state` | `floor.view` | polling-friendly (version token, existing) |
| `POST /orders/{id}/verify-qris` · `/cancel-unpaid` | back-office actions allowed by the design | `sal.qris.verify`, `sal.order.cancel_unpaid` | **R** for cancel |

## End of day

See `api-foundation.md` (`/day-closes`). Checklist items: open orders = 0, pending QRIS = 0, events synced, shifts closed, no unresolved variance. `GET /day-closes/{id}` returns each check with pass/fail, count and detail.

## Devices, activation, printers, shift slots (Phase 8)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /devices` · `GET /devices/{id}` | fleet with model, applied release, last sync, pending events | `dvc.device.view` | |
| `POST /device-activation-codes` | create 8-character code (15 min, single use) | `dvc.device.manage` | code returned once, stored hashed |
| `POST /devices/activate` | device redeems code (unauthenticated except the code) | public + throttle | `422` expired/used; returns a device token with device ability only |
| `POST /devices/{id}/revoke` · `/resend-release` · `/test-print` | actions | `dvc.device.manage` | revoke **R** |
| `GET/POST/PUT /printers` | role, model, paper width, connection, station | `dvc.printer.manage` | |
| `GET/POST/PUT /shift-slots` | slot name, start, end, day pattern, staff | `cfg.shift.manage` | |

## Sync (server contract for POS clients)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /sync/events` | batch of device events with per-device `seq` | device token | idempotent by (`device_id`,`seq`); each event accepted, rejected with code, or quarantined |
| `GET /sync/exceptions?status=&code=` | exceptions awaiting a decision | `pos.sync.view` | codes: NUMBER_CONFLICT, TABLE_CONFLICT, CALC_VARIANCE, REVOKED_USER_OFFLINE, BUSINESS_DAY_CLOSED, NOT_FOUND |
| `POST /sync/exceptions/{id}/resolve` | allowed actions per code (merge orders, accept as valid, flag, reject) | `pos.sync.resolve` | **R**; the response shows the effect; nothing merges silently |
| `GET /config/current` | active release for a device | device token | includes minimum app version policy |

## Configuration releases

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /config-releases` · `GET …/{id}` | list, detail with diff and per-device applied status | `pro.release.view` | |
| `POST /config-releases` | create draft from pending drafts | `pro.release.publish` | lists the changes included |
| `POST /config-releases/{id}/publish` | publish now or `scheduled_at` | `pro.release.publish` | applies drafts atomically; requires approved price drafts |
| `POST /config-releases/{id}/rollback` | publish an earlier snapshot as a new release | `pro.release.publish` | **R**; history never rewritten |

## Limits, presets, denominations, receipt, app policy

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET/PUT /approval-limits` | see foundation | `cfg.limits.manage` | |
| `GET/POST/PUT /discount-presets` | code, value, minimum role, reason code | `cfg.discount.manage` | cap checked server-side at sale time |
| `GET/PUT /cash-denominations` | notes and coins | `cfg.cash.manage` | drives float rows and blind count |
| `GET/PUT /receipt-templates?outlet_id=` | header, footer, copies, toggles | `cfg.receipt.manage` | |
| `GET/PUT /app-version-policy` | minimum/latest, channel, grace, force update | `cfg.app.manage` | |
| `GET/POST/PUT /dining-tables` (existing) | adds bulk create with numbered names, capacity, self-order code | `dining_setup` | |
| `PUT /menu-layout` | category order and visibility on POS | `pro.layout.manage` | |
