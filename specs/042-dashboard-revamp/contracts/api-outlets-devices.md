**Classification**: INTERNAL

# Contract: Outlets, scope, devices, settings (Stage B unless marked A)

**Gate**: nothing in this file is built before T001 (constitution amendment ratified), except the settings keys marked **A**.

## Settings keys — Stage A

Existing bulk endpoint `PUT /settings` (owner/admin, `settings` menu); `GET /settings` returns them; `GET /settings/features` exposes `store_timezone` and `business_day_cutoff` to every role (the SPA formats "business date" with them).

| Key | Type | Default | Validation |
|---|---|---|---|
| `store_timezone` | IANA name | `Asia/Jakarta` | must be in `DateTimeZone::listIdentifiers()` |
| `business_day_cutoff` | `HH:MM` | `00:00` | `00:00`–`23:59` |
| `cash_difference_tolerance` | decimal ≥ 0 | `5000.00` | money string |
| `device_offline_after_minutes` | integer | `5` | 1–1440 |

After Stage B the first three become per-outlet fields (the settings rows are migrated to the default outlet and then ignored); `device_offline_after_minutes` stays installation-wide.

## Scope

| Route | Purpose | Auth |
|---|---|---|
| `GET /me/scope` | `{outlets:[{id,name,code}], can_all: bool, default_outlet_id}` for the caller | any logged-in user |
| header `X-Outlet-Id: <id>` | selects one outlet; absent = all permitted | validated against the caller's permitted outlets; otherwise `403` |

## Outlets

| Route | Purpose | Auth / rules |
|---|---|---|
| `GET /outlets` | list (id, code, name, timezone, cut-off, offline limit, tolerance, is_active, devices count) | `settings` menu |
| `POST /outlets` · `PUT /outlets/{id}` | create / edit (code immutable after use) | `settings` menu; `activity_logs` row in the same transaction |
| `POST /outlets/{id}/deactivate` · `…/activate` | toggle | `409` if it is the last active outlet |
| `PUT /users/{id}` (existing) | gains `outlet_ids[]` (empty = all outlets) | users menu; `outlet_ids` must exist |

Validation: `timezone` IANA; `offline_cash_limit`, `cash_difference_tolerance` money ≥ 0; `name` ≤ 80; `code` `[A-Z0-9]{2,6}` unique.

## Devices

| Route | Purpose | Auth |
|---|---|---|
| `GET /devices?outlet_id&status` | registry with derived `online` and `offline_minutes` | `settings` menu |
| `POST /devices` | register `{name, outlet_id}` → `{device, activation_code}` (code returned **once**) | `settings` menu |
| `POST /devices/{id}/activation-code` | issue a new code, invalidating the previous unused one | `settings` menu |
| `POST /devices/{id}/retire` | `{reason}` → status `retired`, tokens revoked | `settings` menu |
| `POST /devices/activate` | `{code}` → `{device_token, device:{id,name,outlet_id}}`; code single-use, 15 min, hashed at rest | public, `throttle:10,1`; `422` expired/used/unknown (same message, no oracle) |
| `POST /devices/heartbeat` | `{pending_events:int≥0, offline_cash:"0.00", app_version?}` → `{server_time}` | device token only (`abilities:device:report`) |

- A **user** token calling `/devices/heartbeat` → `403`; a **device** token calling any other route → `403`/`401` (both tested).
- `pending_events` and `offline_cash` are **advisory**: stored on the device row, shown on the Dashboard against the outlet limit, and never read by a sales, payment, stock or cash computation (amendment B; asserted by a test that changes them and checks totals are identical).
- Heartbeats update `last_seen_at = server time` (the device's own clock is never used).
- Retired or unknown device → `401` on heartbeat.

## Errors (unchanged convention)

`422` validation, `409` business rule (last outlet, retired device reuse, outlet in use), `403` role/outlet denial, `401` token.

## OpenAPI

All routes above are added to `docs/openapi-pos-mvp.yaml` in the same commit that adds them.
