# Contract: Android setup & PIN sign-in endpoints

**Classification**: INTERNAL — to be mirrored in `docs/openapi-pos-mvp.yaml` in the same commit as the routes.

All routes below need **no bearer token**. They carry `throttle` and the existing `Accept-Language: id` behaviour. Responses follow the project conventions (422 shape, 409 business conflict, 429 throttle/lock). Outlet is a **path parameter**, validated as an active outlet in the current data mode (Constitution IV exception, plan Complexity Tracking).

## GET /pos-setup/outlets
`200 {"data":[{"id":1,"name":"WJM 3"}]}` — active outlets, ordered by name. Name and id only. Empty list is valid.

## GET /pos-setup/outlets/{outlet}/terminals
`200 {"data":[{"id":7,"name":"Kasir Depan"}]}` — active (non-revoked) terminals of that outlet, ordered by name. `404` for an unknown/inactive outlet. No `last_seen_at`, `app_version` or other device metadata.

## POST /pos-setup/outlets/{outlet}/terminals
Body: `{"name":"Kasir Depan"}`.
- `201 {"id":7,"name":"Kasir Depan","created":true}` — registered under `{outlet}`.
- `200 {"id":7,"name":"Kasir Depan","created":false}` — an active terminal with that name (case/space-insensitive) already exists; it is returned, nothing is created.
- `422 {"errors":{"name":[...]}}` — blank, or longer than the column length.
- `404` unknown/inactive outlet. `429` throttled.
Side effects: `ActivityLogger` entry `device_registered` in the same transaction when created.

## GET /staff/roster?outlet_id={id}  *(existing; token no longer required)*
`200 {"data":[{"id","name","role","has_pin","locked"}]}` — now **excludes owner/admin**. Same shape as today. Sending an old bearer token is harmless.

## POST /auth/pin-sessions  *(existing; token no longer required)*
Body adds `device_id`: `{"outlet_id":1,"user_id":5,"pin":"123456","device_id":7}`.
- `200` unchanged shape (`token`, `expires_at`, `user`).
- `401 invalid_pin` (with `attempts_remaining`), `401` generic for unknown/inactive/non-roster/owner/admin user.
- `429 pin_locked` with `locked_until` after 5 wrong PINs (lock now 5 minutes).
- `422 device_id` — terminal missing, revoked, or not in `outlet_id`.
Side effects: `pin_login` log entry (user, outlet, terminal).

## Unchanged but relied on
- `POST /devices/heartbeat` → `422 device_id` when the terminal is revoked (client maps this to the revoked state, FR-018).
- `GET /server-address` (065) — already unauthenticated.
- `POST /devices/pairings`, `PATCH/DELETE /devices/{id}` — web owner/admin only, untouched.

## Not exposed
Usernames, emails, phone numbers, PIN hashes, device metadata, any business data.
