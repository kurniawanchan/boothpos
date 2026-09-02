# Phase 1 API Contracts: Pengaturan Pengguna dan Toko

All routes are under `/api/v1`, require `auth:sanctum`, and follow this
codebase's existing conventions exactly: `{data, meta}` pagination
envelope for lists, money-free here (no monetary fields in this feature),
`422` for validation, `409` for business-rule conflicts (the lockout/
in-use guards below), `403` for role/menu denial. These MUST be added to
`docs/openapi-pos-mvp.yaml` in the same commit that adds the routes
(Constitution "Documentation & Change Discipline").

## Users

```
GET    /api/v1/users                  ?search=&role_id=&is_active=&per_page=
POST   /api/v1/users
GET    /api/v1/users/{user}
PUT    /api/v1/users/{user}
DELETE /api/v1/users/{user}
POST   /api/v1/users/{user}/photo     multipart, field: image
```

- `GET /users` — gated on `canAccessMenu('users')`. `search` matches
  `name` or `username` (FR-004). `role_id`/`is_active` filter (FR-005).
  Response rows: `{id, name, username, role: {id, name}, is_active,
  photo_url, last_login_at}` — `photo_url` built via `ImageUploadService`
  the same way `qr_image_url`/`image_url` already are elsewhere; password
  never included (FR-007's export has the identical exclusion).
- `POST /users` — `StoreUserRequest`. `403` if the acting user's role
  lacks `users` in its `menu_keys`.
- `PUT /users/{user}` — `UpdateUserRequest`. `409` if the target is the
  acting user themselves and the request would deactivate them or change
  their `role_id` (FR-006) — checked in `UserPolicy::update`, not left to
  the request body to self-report.
- `DELETE /users/{user}` — soft-delete (deactivate). Same `409` self-
  lockout guard as above.
- `POST /users/{user}/photo` — mirrors `POST /products/{product}/image`
  exactly (same MIME/size validation via `ImageUploadService`).

## Roles

```
GET    /api/v1/roles
POST   /api/v1/roles
GET    /api/v1/roles/{role}
PUT    /api/v1/roles/{role}
DELETE /api/v1/roles/{role}
GET    /api/v1/menu-keys
```

- `GET /roles` — gated on `canAccessMenu('roles')`. Returns
  `{id, name, menu_keys, is_system_default, user_count}` —
  `user_count` (count of active users on this role) is precomputed so the
  frontend can show "3 pengguna memakai peran ini" without a second
  request, and so the delete-guard's rejection (see below) can quote the
  same number back consistently.
- `POST /roles` / `PUT /roles/{role}` — `StoreRoleRequest`/
  `UpdateRoleRequest`. Every `menu_keys` entry validated against the Menu
  Key Registry (`data-model.md`) — unknown keys rejected with `422`, not
  silently dropped or stored. `PUT` additionally runs the FR-013 "at
  least one role can still manage users+roles" check across all *other*
  roles before allowing a `menu_keys` change that would remove `users` or
  `roles` from this one.
- `DELETE /roles/{role}` — `409` if `user_count > 0` (FR-014), message
  states the count. `409` if this is the last role with `users`+`roles`
  access (FR-013).
- `GET /menu-keys` — returns the Menu Key Registry as
  `{key, label}[]` (e.g. `{"key": "vendors", "label": "Vendor"}`) so the
  role-editing screen's checkbox list is generated from one server-side
  source, never hardcoded a second time in the frontend.

## Settings (existing endpoints extended)

```
PUT    /api/v1/settings                          (existing — no shape change, new keys just flow through)
POST   /api/v1/settings/store-logo               multipart, field: image   (NEW)
```

- `PUT /settings` already accepts the bulk `{"settings":[{key, value}]}`
  shape (see `research.md` Decision 2) — the five new store-profile keys
  (`store_address`, `store_contact_person`, `store_contact_phone`,
  `store_contact_email`) are validated the same way existing keys are
  (email format on `store_contact_email` per FR-018, added to
  `UpdateSettingsRequest`).
- `POST /settings/store-logo` — new, mirrors
  `POST /categories/{category}/image` exactly; sets the
  `store_logo_path` setting row via `ImageUploadService`, logged through
  `ActivityLogger` the same way every `PUT /settings` change already is.

## Master-data import/export (existing mechanism extended)

No new routes — `GET /exports/{entity}`'s `entity` route constraint gains
`roles|users`, and `GET /imports/master-data/template` /
`POST /imports/master-data` automatically pick up the two new sheets once
`MasterDataSheets` is extended (research.md Decision 4). The `users` sheet
never includes a password column (FR-007's export exclusion applies
symmetrically to import — a new user row via import gets a system-
generated temporary password the owner must reset on first CRUD edit,
consistent with FR-007 never exposing a password anywhere in bulk data).

## Auth (existing endpoint, response extended)

```
GET    /api/v1/auth/me     (existing — response gains one field)
```

- `GET /auth/me`'s existing response gains `menu_keys: string[]` — the
  authenticated user's role's resolved menu-access set, computed once
  per request server-side. This is what `resources/js/stores/auth.js`
  stores and what `canAccessMenu(menuKey)` reads client-side (cosmetic UI
  gating only — see Technical Context's Performance Goals for why this is
  resolved once, not per-check).
