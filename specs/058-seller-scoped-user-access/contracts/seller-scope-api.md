# Contract: Seller scope on the API

INTERNAL

## Changed payloads
- `GET/POST/PUT /users`, `GET /users/{id}`: add `artist_id` (nullable input) and `artist {id,name,code}` (output). `GET /users?artist_id=` filter.
- `GET /auth/me`, login: add `artist {id,name,code,is_active}|null`, `seller_scoped: bool`.
- User import/export sheet: optional column `artist_code` (blank = unchanged, per existing blank rule).
- `GET /customers`: rows of other sellers absent.

## Owner exemption
`artist_id` with an Owner role → `422`. A user whose role is Owner is never scoped and `/auth/me` returns `seller_scoped:false`.

## Behaviour for a scoped user
| Case | Response |
|---|---|
| Foreign product/variant/order/pre-order/settlement/PO by id | `404` (indistinguishable from missing) |
| List / search / filter options / totals / exports | only own seller; orders only when they hold an own line |
| Create product/PO/settlement | `artist_id` forced; supplied other value → `422` |
| Sell/adjust/copy with a foreign variant id | `404` |
| Refused surfaces (users, roles, settings, backups, activity-logs, master-data import/export, vendors, materials, payment-method report) | `403 {code:'seller_scope_forbidden'}` |
| Delete a seller with linked users | `409` `master_data.artist_delete_has_users` |
| Seller deactivated | `200` empty data + `seller_active:false` on `/auth/me` |
| Existing role/action denial | unchanged (`403 action_not_allowed`) |

OpenAPI (`docs/openapi-pos-mvp.yaml`) changes in the same commit.
