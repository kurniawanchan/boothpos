# API Contract (Phase 1)

All new/changed endpoints follow the existing conventions documented in
CLAUDE.md: money as `number_format((float)$x, 2, '.', '')` strings, `422`
for validation, `409` for business-rule conflicts, `403` for role/ownership
denial, pagination envelope `{"data": [...], "meta": {...}}` where a list is
returned. `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit as
these routes ship (Constitution "Documentation & Change Discipline").

## Changed: `GET /products` (and any shared POS product-listing call)

- `artist_id` — now accepts an array: `artist_id[]=1&artist_id[]=2`. A bare
  `artist_id=1` still works (Laravel normalizes to `['1']`). Omitted/empty
  = no filter on this axis (unchanged "All" behavior).
- `category_id` — same array treatment as `artist_id`.
- Response shape unchanged.

## New: `PUT /auth/password`

Auth required (`auth:sanctum`), no Policy — self-service, any role.

Request:
```json
{ "current_password": "string", "password": "string", "password_confirmation": "string" }
```

Responses:
- `200` — `{ "message": "..." }` (password changed, current session token
  remains valid — no `logout` side effect).
- `422` — `current_password` incorrect, or `password` fails policy /
  confirmation mismatch (validation-shaped error, per existing convention —
  NOT `401`, since the caller is already authenticated).

## New: `POST /auth/photo`

Auth required, no Policy — operates only on `$request->user()`, no `{user}`
route parameter.

Request: multipart, `image` file field (`mimes:jpeg,png`, max
`ImageUploadService::MAX_KILOBYTES`).

Responses:
- `200` — updated self user resource (same shape as `AuthController::me`,
  plus a `photo_url`).
- `422` — invalid file type/size.

## Changed: `GET /auth/me` (and login response, and `PUT /auth/language` response)

- Add `photo_url` (nullable string) to the returned user shape, so the app
  header/topbar avatar and the new Profile screen share one source.

## New: `GET /dashboard/shortcuts`

Auth required. Returns the shortcut list filtered to the caller's
`menu_keys` (server is the source of truth for what's shown — UI hiding is
cosmetic only per Constitution Principle IV, so this endpoint, not just
frontend logic, omits unauthorized shortcuts).

Response:
```json
{ "data": [ { "key": "new_sale", "route": "pos", "menu_key": "pos" }, ... ] }
```

## New: `GET /dashboard/analytics`

Auth required. Query params: `date_from`, `date_to` (default: current
active event's date range, else last 30 days), `event_id` (optional,
default: current/most recent active event).

Response: the `sales_by_day` / `sales_by_category` / `sales_by_artist` /
`sales_by_event` / `stats` shape from data-model.md, all money as strings,
all scoped to the active DEMO/LIVE mode.

- `200` — analytics payload (empty arrays / zero stats if no data — the
  frontend renders the empty-state per spec edge case, not the backend).
