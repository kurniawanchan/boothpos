# Contract changes: terminal name

Only differences from the current API are listed. Money/status conventions unchanged. `docs/openapi-pos-mvp.yaml` is updated in the same change.

## POST /devices/pairings (owner/admin)
- Body: `{ "name": string (required, trimmed, ≤80) }`. `code` is no longer accepted (ignored if sent).
- 201: `{ id, name, outlet_id, app_version, last_seen_at, revoked }` — **no `code`**.
- 422 `errors.name`: blank, or another active terminal of this outlet has that name (case/space-insensitive).

## PATCH /devices/{device} (owner/admin) — NEW
- Body: `{ "name": string }`. Outlet-scoped (other outlet → 404). Revoked → 409. Duplicate/blank → 422 `errors.name`.
- 200: the device (same shape as above). Audit `device_renamed` in the same transaction.

## GET /devices (owner/admin)
- Rows as above (no `code`), ordered by name.

## POST /devices/heartbeat
- Response adds `name` (the terminal's current name) so already-paired tablets learn it.

## Tab / floor / order payloads
- `TabResource.device`: `{ id, name }` (was `{ id, code }`).
- Floor snapshot per tab: `device_name` (was `device_code`).
- `GET /orders/{id}/receipt`: adds `terminal_name` (string|null); `receipt_no` is now `"000124"` for new orders (legacy `"T01-000124"` unchanged).
- Order payloads (`OrderResource`) and `GET /reports/sales` transaction rows: `terminal_id`, `terminal_name` (null = no terminal). `OrderResource` carries them only when `device` is eager-loaded (relationLoaded trap) — every endpoint returning orders must load it.
- Shift/session payload and `GET /sessions/{id}/summary`: `terminal_name`.
- Sales export: a "Terminal" column, same rows as the screen.

## Compatibility
Android app and server are released together; the removed `device.code` / `device_code` keys are not preserved.
