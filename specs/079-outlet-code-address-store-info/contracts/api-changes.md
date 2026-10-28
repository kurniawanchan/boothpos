# API Contract Changes: 079

**Classification**: INTERNAL

Delta only; the full contract moves into `docs/openapi-pos-mvp.yaml` in the same commit as each route/response change. Status codes follow the project convention (422 validation, 409 business conflict, 403 role/outlet denial). Roles: owner/admin unless stated.

## Outlets

**`POST /outlets`**, **`PUT /outlets/{id}`** — request adds/changes:

| Field | Rule |
|---|---|
| `code` | create: required; update: now accepted (was `prohibited`). `^[A-Za-z0-9]{3,5}$`, case-insensitive unique among live outlets. 422 message states the rule. |
| `address` | optional, string ≤500, nullable |

Response (`OutletResource`) adds `address`. `GET /outlets`, `GET /outlets/{id}` likewise.

## Receipt — `GET /orders/{order}/receipt`

Same keys, new source (the outlet of the order):

| Key | Now |
|---|---|
| `store_name` | outlet name (or shared name when the outlet has no details at all) |
| `store_address` | outlet address |
| `store_contact_phone`, `store_contact` | outlet phone |
| `store_contact_email` | outlet email |
| `store_notes` | **new**, outlet notes, `null` when empty |
| `store_contact_person`, `receipt_footer_text`, `store_logo_url`, `app_name` | unchanged (shared) |

Empty outlet details are `null`; clients omit the line.

## Features — `GET /settings/features`

Adds `store: {name, address, phone, email, notes}` computed for the request's narrowed outlet (single permitted outlet or `X-Outlet-Id`); All outlets → shared values; empty outlet field → shared value. `store_name` is kept and equals `store.name`.

## Token-less tablet read (new)

`GET /pos-setup/outlets/{outlet}/branding` → `{system_mode, store_name, store_logo_url}` where `store_name` is the outlet's name. Throttled like the sibling pos-setup routes. Unknown/inactive outlet → 404. The existing `GET /pos-setup/branding` is unchanged.

## Terminals — `/devices`

| Route | Change |
|---|---|
| `GET /devices` | Adds `outlet_id[]`, `status` (`active\|revoked\|all`, default `all`), `q`, `page`, `per_page`. With `page`/`per_page` returns `{data:[…], meta:{current_page, per_page, total, last_page}}`; without, the current `{data:[…]}` shape. Row adds `outlet:{id,code,name}`, `paired_at`, `revoked_at`. `outlet_id[]` only narrows within the user's outlets (values outside them never widen; none allowed → empty list, as for dining tables); the page requests it without `X-Outlet-Id`. |
| `POST /devices/pairings` | **Unchanged contract**: the outlet comes from `X-Outlet-Id` (the Terminals page sets it from its outlet picker); a header outlet the user may not use, or an inactive one, is 403 `outlet_forbidden`. 422 `name` when taken in that outlet. 201 with the row (now with `outlet`). |
| `PATCH /devices/{device}` | Accepts `name` and/or `outlet_id` (at least one). Move: 409 `open_shift` when the terminal has an open shift; 422 `name` when taken in the target; revoked terminal → 409 (as rename today). 200 with the row. |
| `DELETE /devices/{device}` | **Unchanged** — deactivates (sets `revoked_at`), idempotent, 204. |
| `POST /devices/{device}/reactivate` | **New.** Clears `revoked_at`. 422 `name` when an active terminal of the outlet has that name. A terminal of an inactive outlet is hidden (404). 200 with the row. |
| `DELETE /devices/{device}/permanent` | **New.** Hard delete. 409 when an order or a shift references it (the FKs are SET NULL, so the guard lives in `DeviceService::deleteIfUnused`). 204 otherwise. |
| `POST /devices/heartbeat`, `GET /devices/server-address` | Unchanged. |

All new routes: 403 for non-owner/admin; a terminal outside the user's outlets → 404 (existing scoped route-model binding).

## Activity log

New actions: `device_moved`, `device_reactivated`, `device_deleted`; an outlet update is logged as `updated` (entity Outlet) with old/new values, including a code change. Written inside the mutating transaction.
