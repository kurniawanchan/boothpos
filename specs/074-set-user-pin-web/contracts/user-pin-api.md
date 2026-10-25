# Contract: Set User PIN

**Classification**: INTERNAL — to be mirrored in `docs/openapi-pos-mvp.yaml` in the same commit as the routes.

All routes need an authenticated **owner/admin** (menu `settings`); anyone else gets 403. Errors follow the project convention (422 validation, 403 role, 429 throttle). Both routes are `throttle:30,1`.

## PUT /api/v1/users/{user}/pin
Body: `{"pin":"482916","source":"typed"}` — `pin` required, exactly 6 digits; `source` optional, `typed` (default) or `generated`.
- `200` → the updated user (same shape as `GET /users/{id}`) with `has_pin: true`, `pin_locked: false`. The PIN is **not** in the response.
- `422` `{"message","code":"pin_too_weak"}` for weak PINs; `422` field errors for not-6-digits / bad `source`.
- `403` caller is not owner/admin. `404` unknown user.
Effects (one transaction): replaces the PIN, clears failed attempts and lock, deletes the user's PIN sessions, writes `pin_set` or `pin_replaced`.

## POST /api/v1/pin-suggestions
No body. `200` → `{"pin":"739204"}` — a random, valid (non-weak) 6-digit PIN. Not stored, not tied to a user, not logged. `403` for non-owner/admin.

## GET /api/v1/users (additions)
- Each user: `has_pin` (bool), `pin_locked` (bool).
- Query `has_pin=1` → only users with a PIN; `has_pin=0` → only users without. Omitted → all.

## Unchanged
`POST /auth/pin-changes` (own PIN), `POST /users/{user}/pin-reset` (clear only), all Android PIN sign-in routes.

## Never exposed
The PIN after the dialog closes, the hash, the failed-attempt counter.
