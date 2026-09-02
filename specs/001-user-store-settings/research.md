# Phase 0 Research: Pengaturan Pengguna dan Toko

No items in Technical Context were left as `NEEDS CLARIFICATION` — this is a
brownfield feature inside a codebase whose existing conventions (documented
in `CLAUDE.md` and directly read from source during planning) already
answer most technology questions. This document instead records the
concrete design decisions made while filling the plan, in the same
Decision/Rationale/Alternatives format, so the reasoning survives past this
planning session.

## Decision 1: `roles.menu_keys` as a single JSON column, not a junction table

**Decision**: Store a role's allowed menus as one JSON array column
(`roles.menu_keys`, e.g. `["pos","products","reports"]`) rather than a
separate `role_menu_permissions` junction table.

**Rationale**: This codebase's own Constitution (Principle I) requires
avoiding unneeded complexity. A junction table earns its cost when you need
to efficiently query "which roles can access menu X" at scale, or need
per-row metadata (granted-by, granted-at) on each permission grant. This
feature's own Scale/Scope (dozens of accounts, a handful of custom roles
per single-store install) never needs that query pattern — the only two
real access patterns are "what can this role access" (read one row) and
"does this role include menu X" (an `in_array` check on an already-loaded
array). A junction table here would be exactly the kind of "table +
model + resource for what's fundamentally a list" this codebase already
rejected once — the Vendor/Material/BOM feature deliberately used a real
join table only because `vendor_material_prices` has genuine per-row data
(price, `is_preferred`) that a JSON array can't hold; `menu_keys` has no
such per-row data.

**Alternatives considered**:
- **Junction table** (`role_menu_permissions`: `role_id`, `menu_key`) —
  rejected as the more complex option with no corresponding benefit at this
  scale; reconsider only if a future feature needs per-grant metadata or
  cross-role menu-usage reporting.
- **Bitmask integer column** — rejected: opaque to read in the database
  directly, and menu keys are a small, human-legible set (a dozen or so
  screens) where a bitmask buys nothing a JSON array doesn't already give
  more readably.

## Decision 2: Store profile reuses the existing `settings` key-value table, not a new `StoreProfile` model/table

**Decision**: The new store-profile fields (address, logo path, contact
person, phone, email) are added as new rows in the existing `settings`
table (new keys, e.g. `store_address`, `store_logo_path`,
`store_contact_person`, `store_contact_phone`, `store_contact_email`),
exactly like the existing `store_name`/`store_contact` keys — not a new
dedicated table.

**Rationale**: `Setting` already exists specifically to hold arbitrary
single-instance configuration key/value pairs for this single-tenant,
one-store-per-install product, and `SettingsController::update()` already
supports bulk multi-key updates in one request — precisely what a "store
profile form with several fields saved at once" needs. Introducing a
second, parallel single-row config table would violate Constitution
Principle I (one sanctioned mechanism per concern) for no benefit — a
`StoreProfile` table with exactly one row is strictly more code (model,
migration, resource, dedicated endpoint) than reusing the mechanism this
exact use case was built for.

**Alternatives considered**:
- **New `store_profiles` table** (one row per install) — rejected: worse
  by the DRY/simplicity gate, no relational need this table would serve
  that isolated key-value rows don't already serve equally well.

## Decision 3: Store logo upload gets its own dedicated endpoint, mirroring the existing product/category image pattern

**Decision**: Add `POST /settings/store-logo` (multipart, single `image`
field) rather than trying to make `PUT /settings` (JSON-only today) accept
a file.

**Rationale**: `SettingsController::update()`'s bulk-JSON contract is
already relied upon by the existing Pro/Master license toggle and store
name/contact fields; overloading it to sometimes accept multipart would
complicate that contract for every existing caller. `ProductController` and
`CategoryController` already solved this exact shape of problem (a
JSON-based CRUD endpoint that also needs an image) with a dedicated
`POST .../{id}/image` sibling endpoint reusing `ImageUploadService` — this
feature does the same thing for the store logo, keeping one established
pattern rather than inventing a second way to upload an image in this
codebase.

**Alternatives considered**:
- **Multipart-only `PUT /settings`** — rejected: breaking change to an
  existing, working, JSON-only contract with other real callers (the
  license toggle).

## Decision 4: `users`/`roles` become two new sheets in the existing combined master-data workbook, not a standalone import

**Decision**: Extend `MasterDataSheets`/`MasterDataImportService`/
`MasterDataExportController` — which already handle 8 sheets
(artists→categories→products→stock→vendors→materials→vendor_prices→bom) in
dependency order, all-or-nothing, with `dry_run` support — with two more:
`roles` (processed before `users`, since a user row references a role by
name) and `users`.

**Rationale**: This is the exact established, sanctioned pattern for bulk
import/export of any master-data entity in this codebase, extended twice
already (once for the initial 4 sheets, once for the Vendor/Material/BOM
4 sheets) without needing a new mechanism either time. Building a
standalone user-only import would duplicate the entire validate-then-
apply-atomically machinery (row-level error shape, template generation,
dry-run) that already exists and is already tested.

**Alternatives considered**:
- **Standalone `/imports/users` endpoint** — rejected: pure duplication of
  an existing, working mechanism; also would give the shop owner two
  different places to go for "bulk-load my master data" instead of one.

## Decision 5: Two-step migration for `users.role` (enum → `role_id` FK)

**Decision**: One migration adds `role_id` (nullable at first) and
`photo_path`, backfills `role_id` for every existing user from four
seeded default `Role` rows that reproduce today's enum values exactly,
and only then (same migration, after backfill, or a strictly-later dated
migration) makes `role_id` non-nullable and drops the old `role` enum
column.

**Rationale**: This mirrors a pattern this exact codebase already uses on
purpose — `CLAUDE.md` documents `payments.preorder_id` being created
without an FK constraint in one migration and constrained only in a later
one, specifically because the referenced table didn't exist yet at that
point in migration order. The same caution applies here in reverse: the
`role` enum column is read by every existing authorization check on the
day this migration runs, so backfilling into the new column before
removing the old one (rather than one atomic swap) keeps every step
independently safe to run and roll back.

**Alternatives considered**:
- **One atomic migration** (drop enum, add FK, backfill, all in one
  `up()`) — rejected: harder to reason about safety if the migration fails
  partway (Laravel wraps a single migration's `up()` in a transaction on
  MySQL for DDL that supports it, which mitigates but does not eliminate
  the review-complexity cost of one large migration doing three
  conceptually separate things).
