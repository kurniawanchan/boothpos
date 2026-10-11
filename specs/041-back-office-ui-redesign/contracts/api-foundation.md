**Classification**: INTERNAL

# API contract — foundation (Phase 2a, 6, 9)

Legend: **R** reason code required · **A** approval path · perm = permission key.

## Outlets and scope

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /outlets` | list outlets the caller may access | any signed-in | includes `code, name, timezone, opens_at, closes_at, business_day_cutoff, service_modes, tables_count, is_active` |
| `POST /outlets` · `PUT /outlets/{id}` | create/update | `iam.outlet.manage` | `code` unique; immutable after first document; `422` shape, `409` if deactivating the last active outlet or an outlet with open orders/shifts |
| `GET /me/scope` | caller's permitted outlets + effective permissions | signed-in | drives the outlet selector and hidden-not-disabled controls |
| `PUT /users/{id}/outlets` | set a user's outlet scope (empty = all) | `iam.user.manage` | audited; cannot remove the last owner's access |

## Permissions, roles, separation of duties

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /permissions` | catalogue grouped by area | `iam.role.manage` | source of the matrix UI |
| `GET /roles` · `POST /roles` · `PUT /roles/{id}` | list/create/update role incl. `permissions[]` | `iam.role.manage` | built-in read-only/non-transacting roles refuse write permissions (`409`); `users`/`roles` reserved rule kept |
| `GET /sod-rules` | list active separation-of-duties rules | `iam.role.manage` | read-only listing |

## Reasons, approvals, limits

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /reason-codes?category=` | list (active by default) | signed-in | used by every R action's dialog |
| `POST/PUT/DELETE /reason-codes` | manage | `cfg.reason.manage` | delete only if unused, else deactivate (`409`) |
| `GET /approvals?status=&kind=&mine=` | approvals waiting for me / created by me | per kind | feeds bell and "needs action" |
| `POST /approvals/{id}/approve` · `/reject` | decide | per kind | **A**; `409 SELF_APPROVAL`; `403` over the role's limit; reject needs **R** |
| `GET/PUT /approval-limits` | limits per role and kind | `cfg.limits.manage` | offline limit > online limit is lowered and the response says so |

## Financial configuration

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /tax-rules` · `POST /tax-rules` | list / publish a new version with `effective_from` | `cfg.tax.manage` | no update-in-place; overlapping dates `409`; previous sales keep their snapshot |
| `GET/POST/PUT /payment-methods` | methods with scope, verification flow, clearing account | `cfg.payment.manage` | cash cannot be deleted; deactivate only |
| `GET /business-rules` · `PUT /business-rules` | POS rules (table/guest required, lock after pre-bill, reprint limit, idle lock, variance tolerance, cash-out threshold, offline limits, counter-to-kitchen) | `cfg.rules.manage` | validated ranges; audited with before/after |

## Business date and day close

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /outlets/{id}/business-date` | current business date and cut-off state | signed-in | server-computed from timezone + cut-off |
| `GET /day-closes?outlet_id=&status=` | list days with blockers | `pos.eod.view` | |
| `POST /day-closes/{id}/close` | close the day | `pos.eod.close` | `409` with `{blockers:[{code,count,detail}]}` while any check fails |
| `POST /day-closes/{id}/reopen` | reopen | `pos.eod.reopen` | **R** (`reopen` category); regenerates the report on next close |

## Search, attention, dashboard (Phase 6)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /search?q=&types=` | federated search: orders (number/table), members, items, tables | per type | limited to caller's outlets and permissions; min 2 chars; max 8 per type; indexed LIKE |
| `GET /attention` | derived items needing action `[ {kind, count, value?, outlet_id?, link} ]` | signed-in | computed on read, cached ~30 s per user+outlet set; feeds bell, rail dots, dashboard list |
| `GET /dashboard/summary?period=today\|7d\|30d` | headline figures with change vs previous period/day (same time of day), trend series | `dash.view` | built from `business_date`; figures equal the sum of the underlying list for the same filter |
| `GET /dashboard/export?period=` | export of the dashboard figures | `rpt.export` | file via the export-history path |

## Settings sections and imports (Phase 9)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /settings/sections` | grouped section list with issue counts (Organisasi, Keuangan, Perangkat, Operasional) | `cfg.view` | counts computed server-side (e.g. missing required setting) |
| `POST /imports/validate` | upload + validate (menu & prices, users, ingredients, tables, modifiers, suppliers) | `cfg.import` | returns per-row/column errors; nothing applied; `mode` = add_update \| replace_all |
| `POST /imports/{id}/apply` | apply a validated import | `cfg.import` | only if validation passed; `replace_all` needs **R** |
| `GET /imports` | import history (file, user, time, status, error summary) | `cfg.import` | |

## Cross-cutting changes to existing endpoints

- `POST /orders`, `/tabs`, `/tabs/{id}/payments`: server stamps `outlet_id`, `business_date`, tax snapshot; `409 BUSINESS_DAY_CLOSED` if the date is closed; unchanged request shape for existing clients.
- `GET /reports/*`, `GET /sessions/*`, `GET /floor`: respect outlet scope and filter by `business_date`; `X-Outlet-Id` honoured.
- `GET /activity-logs`: gains `outlet_id`, `ip`, and filters `outlet_id`, `actor_id`, `action`, `from`, `to`.
