# Phase 1 Data Model: Pengaturan Pengguna dan Toko

## Entity: Role (`roles` table)

Represents a named, owner-configurable bundle of menu access. Replaces the
current hardcoded 4-value `users.role` enum as the unit of authorization.

| Field | Type | Notes |
|---|---|---|
| `id` | bigint, PK | |
| `name` | string(50), unique | Display name, e.g. "Owner", "Kasir Event A" |
| `menu_keys` | JSON array of strings | The menu keys this role may access (see Menu Key Registry below). Empty array is valid (a role with no menu access) but MUST NOT be the only role capable of managing users/roles (FR-013). |
| `is_system_default` | boolean, default `false` | `true` for the 4 seeded roles that reproduce today's owner/admin/kasir/inventory behavior — informational only, does **not** block editing (an owner may still change a system-default role's `menu_keys`), but the seeder uses it to know which rows to (re-)create idempotently. |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable | Soft delete — consistent with this codebase's existing pattern for master data (Artist, Category, Vendor, Material all soft-delete). |

**Relationships**: `hasMany(User::class)`.

**Validation rules**:
- `name`: required, string, max 50, unique among non-deleted roles.
- `menu_keys`: required array; every value MUST be one of the registered menu keys (reject unknown keys rather than silently storing them — an owner mistyping a key must not create an unenforceable permission).

**State transitions / guards** (enforced in `RolePolicy`/`RoleController`, not just at the database level):
- **Delete** MUST be rejected (409) if any active (`is_active = true`, not soft-deleted) `User` still references this role (FR-014).
- **Delete or update** MUST be rejected (409) if the change would leave zero roles whose `menu_keys` includes the reserved `users` **and** `roles` menu keys — i.e., the store must always retain at least one role capable of managing users and roles (FR-013). This check considers the *other* roles that would remain, not just the one being edited.

## Entity: User (`users` table — modified)

Existing table, modified. Fields already present (`name`, `username`,
`password`, `is_active`, `last_login_at`, timestamps, soft deletes) are
unchanged. Changes:

| Field | Change |
|---|---|
| `role` (enum) | **Removed** (dropped after backfill — see research.md Decision 5). |
| `role_id` | **New**, `bigint unsigned`, FK → `roles.id`, `restrictOnDelete()` (defense-in-depth alongside the application-level guard in FR-014 — mirrors this codebase's existing pattern of pairing an app-level delete guard with a DB-level `restrictOnDelete()`, e.g. `vendor_material_prices.vendor_id`). |
| `photo_path` | **New**, `string`, nullable. Stores the path returned by `ImageUploadService`, exactly like `products.image_path`/`categories.image_path`. |

`last_login_at` already exists on this table (confirmed by reading the
current migration/model during planning) — FR-003 ("terakhir akses") is
satisfied by surfacing this already-tracked column in the new UI and API
response, not by adding new tracking.

**Relationships**: `belongsTo(Role::class)`.

**Validation rules** (`StoreUserRequest`/`UpdateUserRequest`):
- `name`: required, string, max 100 (matches existing column).
- `username`: required, string, max 50, unique among non-deleted users.
- `password`: required on create, sometimes+confirmed on update (only
  changed if provided), following Laravel's standard password rule.
- `role_id`: required, must reference an existing, non-deleted `Role`.
- `photo`: sometimes, image file, reusing `ImageUploadService`'s existing
  size/MIME constraints (same as product/category images).
- `is_active`: boolean.

**State transitions / guards**:
- A user MUST NOT deactivate, delete, or change the `role_id` of the
  account currently authenticated as themselves (FR-006) — checked by
  comparing the target user's `id` to `auth()->id()` in the
  controller/policy, not by trusting a client-supplied "is this me" flag.

## Entity: Menu Key Registry (code constant, not a database table)

A fixed, versioned list of the menu/screen keys that exist in the
application today (e.g. `dashboard`, `pos`, `session`, `events`,
`products`, `artists`, `categories`, `stock`, `vendors`, `materials`,
`customers`, `preorders`, `sales`, `reports`, `users`, `roles`,
`settings`). This is **not** a database table — it is the single source of
truth `Role.menu_keys` validates against and that `RoleController` returns
to the frontend so the role-editing screen can render a checkbox per
known menu without hardcoding the list twice (backend and frontend). Adding
a new screen to the application in the future means adding one entry here,
not a migration.

**Reserved keys**: `users` and `roles` are reserved — see the FR-013 guard
above, which specifically protects access to *these two* keys from ever
reaching zero roles.

## Entity: Store Profile (rows in the existing `settings` table — no new table)

Not a new entity/table — five new keys in the existing generic key-value
`settings` table, alongside the existing `store_name`/`store_contact`
rows (see research.md Decision 2):

| Key | Type | Group |
|---|---|---|
| `store_address` | string | `receipt` (same group as `store_name`) |
| `store_logo_path` | string, nullable | `receipt` |
| `store_contact_person` | string | `receipt` |
| `store_contact_phone` | string | `receipt` |
| `store_contact_email` | string | `receipt` |

**Validation rules** (in the request that calls `PUT /settings`):
- `store_contact_email`: valid email format when present (FR-018).
- `store_contact_phone`: string, reasonable phone-number format check
  (not a strict international-format validator — this is a single-country,
  single-store product; a permissive "digits/spaces/dashes/plus" pattern
  matches this codebase's existing light-touch validation style for
  `store_contact`/`contact_phone` elsewhere).

`store_logo_path` is set only via the dedicated
`POST /settings/store-logo` endpoint (research.md Decision 3), never
through the generic `PUT /settings` JSON body.
