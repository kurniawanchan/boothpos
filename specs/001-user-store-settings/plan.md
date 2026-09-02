# Implementation Plan: Pengaturan Pengguna dan Toko

**Branch**: `001-user-store-settings` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-user-store-settings/spec.md`

## Summary

Add three new capabilities to the existing BoothPOS Pengaturan screen: (1) full
user-account management (CRUD, photo, last-access, search/filter), (2) a
fully configurable role/menu-permission system replacing the current 4
hardcoded roles (owner/admin/cashier/inventory), and (3) an expanded store
profile (address, logo, contact person, phone, email) — plus bulk
export/import for user accounts via the existing combined master-data Excel
workbook. Technical approach: reuse every existing single-sanctioned
mechanism this codebase already has (`Setting` key-value store for store
profile fields, `ImageUploadService` for photo/logo uploads,
`MasterDataImportService`/`MasterDataSheets` for bulk import/export,
`ActivityLogger` for the audit trail) rather than building parallel
one-off systems, and introduce exactly one new authorization primitive
(`User::canAccessMenu(string $menuKey): bool`) that all enforcement points —
existing and new — delegate to, so the permission model has one source of
truth instead of scattering `menu_keys` lookups across 29 call sites.

## Technical Context

**Language/Version**: PHP 8.3/8.4 (Laravel 13) for the backend; JavaScript
(Vue 3, `<script setup>`) for the frontend — matching the existing
codebase exactly, no new language/runtime introduced.

**Primary Dependencies**: Laravel 13 + Sanctum (existing auth), `maatwebsite/excel`
(existing — extends the already-present combined import/export workbook),
existing in-house `ImageUploadService` (already used for product/category/
payment-channel images), existing `ActivityLogger`. Frontend: Vue Router,
Pinia, existing `BaseSelect`/`DataTable`/`BaseModal` UI kit, Tailwind v4
CSS-first tokens — no new frontend dependency required.

**Storage**: MySQL 8 (required — per Constitution "Stack & Environment
Constraints"; this feature adds new tables/columns via standard Laravel
migrations, no new storage technology).

**Testing**: PHPUnit against real MySQL (`tests/Feature/`, `php artisan test`);
Vitest + Testing Library (`qa-tests/`, `npm test`); real-browser
verification for the new/changed Settings screens before considering any
part of this feature done (Constitution Principle II).

**Target Platform**: Same single local machine per store as the rest of
the application — no new deployment target.

**Project Type**: Web application (existing single-repo Laravel API + Vue
SPA monolith) — this feature extends the existing structure, it does not
introduce a new project.

**Performance Goals**: User list search/filter responds in under 10 seconds
for 50+ accounts (spec SC-002) — trivially met by the existing paginated
list + `usePaginatedList` pattern already used elsewhere. Menu-permission
checks MUST NOT add a per-check database query — the authenticated user's
resolved menu-access set is loaded once per request (already-loaded
relation) and once per frontend session (returned from `GET /auth/me`),
not re-queried per menu/per route.

**Constraints**: MySQL required, no SQLite (Constitution). All
authorization decisions enforced server-side; the frontend router/nav
gating is cosmetic only, exactly like every other role gate already in
this codebase (Constitution Principle IV). The migration away from the
current hardcoded 4-role enum MUST NOT change any existing user's
effective access on the day this ships — the 4 current roles become
seeded, editable Role rows whose `menu_keys` reproduce today's behavior
exactly (spec Assumptions).

**Scale/Scope**: Single-store install; realistically dozens of user
accounts and a handful of custom roles per install — not a multi-tenant
or high-concurrency concern. Menu-level access only (spec Assumptions
explicitly exclude per-action/per-CRUD-verb permissions from this
feature's scope).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Code Quality & Maintainability (DRY/SOLID) | **PASS, with a required design constraint.** Replacing the fixed-role model touches every existing authorization call site. To avoid exactly the "duplicated logic per call site" failure this principle warns against, this plan introduces **one** new authorization primitive (`User::canAccessMenu()`) backed by **one** new data shape (`roles.menu_keys` JSON), and requires every enforcement point — the 17 `FormRequest`s, 6 inline controller checks, 6 Policies, and the frontend router guard/nav filter — to delegate to it rather than each learning to query permissions independently. This is a gate, not a suggestion: a design that has two or more independent ways to answer "can this user access X" fails this check. |
| II. Testing Standards | **PASS, gate carried into Phase 1/tasks.** Requires `tests/Feature/` coverage against real MySQL for: a custom role with a restricted `menu_keys` set actually receiving 403 on a disallowed endpoint (not just the UI hiding a link), the two lockout guards (FR-006, FR-013/FR-014), and the extended bulk-import validation. Requires real-browser verification of the new Pengguna/Peran screens and the extended Toko profile before this feature is considered done. |
| III. UX Consistency | **PASS.** New screens reuse existing tokens, `DataTable`/`BaseSelect`/`BaseModal`, the established hide-not-disable role-gating pattern (now driven by `canAccessMenu()` instead of a hardcoded role array), and Indonesian copy throughout. |
| IV. Security | **PASS, this is the highest-risk area of this feature — see Complexity Tracking.** Client-side menu hiding remains cosmetic only; every backend enforcement point MUST check `canAccessMenu()` server-side. The migration is designed so no endpoint becomes *more* permissive during the transition (seeded default roles reproduce current behavior exactly, verified by test before any custom role is ever created). Self-lockout is guarded at both the account level (FR-006) and the role level (FR-013/FR-014) — a stricter, additional guard beyond what any existing feature in this codebase requires, because this is the first feature able to remove someone's own access to the system that manages access. |
| V. Performance & Optimization | **PASS.** Permission set is resolved once per request/session (see Technical Context), not per-check. User list reuses the existing eager-loading + pagination pattern already proven for Artists/Vendors/Materials — no new N+1 risk introduced. |
| Stack & Environment Constraints | **PASS.** No new storage technology, no SQLite anywhere, single-local-machine deployment unchanged. |
| Documentation & Change Discipline | **PASS, gate carried into Phase 1/tasks.** `docs/openapi-pos-mvp.yaml` MUST gain the new routes in the same commit as the routes themselves. This is a genuinely new capability (not a PRD-cut item being resurrected) — PRD F13.1 (user CRUD) was never built and F13.5 (configurable custom roles) was marked Priority C/stretch; this feature fully delivers both. A dated note MUST be added to the PRD recording that F13.5 moved from "stretch, not built" to "built," following this repo's own established convention for scope changes. |

**Result**: No unjustified violations. One deliberate, spec-driven complexity increase (see Complexity Tracking) — not a shortcut, a direct consequence of the user's explicit choice of the most flexible of three offered options during `/speckit-specify`.

## Project Structure

### Documentation (this feature)

```text
specs/001-user-store-settings/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api.md            # Phase 1 output — new/changed REST endpoints
└── tasks.md              # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

This is a brownfield feature inside the existing BoothPOS monolith — no new
top-level project is created. Real paths below are the existing structure
this feature extends (unused generic template options removed).

```text
app/
├── Models/
│   ├── User.php                      # MODIFY: role_id FK (was enum), photo_path, canAccessMenu()
│   ├── Role.php                      # NEW: name, menu_keys (JSON), is_system_default
│   └── Setting.php                   # unchanged — reused as-is for store profile keys
├── Http/
│   ├── Controllers/Api/
│   │   ├── UserController.php        # NEW: index/store/update/destroy + search/filter + photo upload
│   │   ├── RoleController.php        # NEW: index/store/update/destroy + list of available menu keys
│   │   └── SettingsController.php    # MODIFY: add uploadStoreLogo() (mirrors existing product/category image pattern)
│   ├── Requests/
│   │   ├── StoreUserRequest.php      # NEW
│   │   ├── UpdateUserRequest.php     # NEW
│   │   ├── StoreRoleRequest.php      # NEW
│   │   └── UpdateRoleRequest.php     # NEW
│   └── Resources/
│       ├── UserResource.php          # NEW
│       └── RoleResource.php          # NEW
├── Policies/
│   ├── UserPolicy.php                # NEW — delegates to canAccessMenu('users') + self-lockout guards
│   └── RolePolicy.php                # NEW — delegates to canAccessMenu('roles') + FR-013/FR-014 guards
├── Services/
│   ├── ImageUploadService.php        # unchanged — reused for user photo + store logo
│   ├── MasterDataImportService.php   # MODIFY: add 'users' sheet (dependency-ordered after roles)
│   └── ActivityLogger.php            # unchanged — reused for user/role sensitive actions
└── Support/
    └── MasterDataSheets.php          # MODIFY: add 'roles' + 'users' sheet definitions

database/
├── migrations/
│   ├── 2026_10_09_000001_create_roles_table.php           # NEW
│   ├── 2026_10_09_000002_add_role_id_and_photo_to_users.php # NEW — adds role_id FK + photo_path,
│   │                                                          # migrates existing enum values into
│   │                                                          # seeded Role rows, THEN drops old enum column
│   └── 2026_10_09_000003_add_profile_fields_to_settings.php # NEW (if needed — likely just seeded rows,
│                                                                # not a schema change, since settings is
│                                                                # already a generic key-value table)
└── factories/
    └── RoleFactory.php                # NEW

tests/Feature/
├── UserTest.php                       # NEW — CRUD, search/filter, self-lockout, photo upload
├── RoleTest.php                       # NEW — CRUD, menu_keys enforcement, delete/lockout guards
└── MasterDataImportUserTest.php       # NEW — users sheet in the combined import

resources/js/
├── views/
│   ├── UsersView.vue                  # NEW — list + CRUD form, mirrors VendorsView.vue's shape
│   ├── RolesView.vue                  # NEW — list + CRUD form with a menu-checkbox matrix
│   └── SettingsView.vue               # MODIFY — extend "Data Toko" card with address/logo/contact fields
├── components/
│   └── settings/
│       └── RoleMenuPicker.vue         # NEW — reusable checkbox grid of available menu keys
├── api/
│   ├── users.js                       # NEW
│   └── roles.js                       # NEW
├── stores/
│   └── auth.js                        # MODIFY — store resolved menu-access set from GET /auth/me,
│                                        # expose canAccessMenu(menuKey)
├── router/
│   └── index.js                       # MODIFY — every route's `roles: [...]` meta becomes `menuKey: '<key>'`,
│                                        # guard checks auth.canAccessMenu(to.meta.menuKey) instead of
│                                        # to.meta.roles.includes(auth.role)
└── components/layout/
    └── AppSidebar.vue                 # MODIFY — NAV_DEFS `roles` filter becomes `menuKey` + canAccessMenu()

qa-tests/component/
├── UsersView.test.js                  # NEW
├── RolesView.test.js                  # NEW
└── SettingsView.test.js               # MODIFY — extend for new store-profile fields

docs/
├── openapi-pos-mvp.yaml               # MODIFY — new routes, same commit as the routes (Constitution gate)
└── PRD-POS-Event-Multivendor.md       # MODIFY — dated note: F13.1 built, F13.5 moved from stretch to built
```

**Structure Decision**: Extend the existing single-repo Laravel+Vue monolith
in place — no new project, no new top-level directory. Every new backend
file follows the exact naming/placement convention already established by
the Vendor/Material/BOM feature (the most recently added comparable module
in this codebase); every new frontend file follows the exact convention
established by `VendorsView.vue`/`MaterialsView.vue`.

## Complexity Tracking

> Filled because Constitution Check flagged one deliberate, spec-driven
> complexity increase under Principle IV (Security) — not a violation, but
> significant enough to require explicit justification per Governance's
> review expectation.

| Complexity | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Replacing the fixed 4-role enum with a dynamic, owner-configurable `Role`/`menu_keys` model, and migrating all existing authorization call sites (17 `FormRequest`s, 6 inline controller checks, 6 Policies, the frontend router guard, `AppSidebar` nav filter) to delegate to one new `canAccessMenu()` primitive | Directly required by spec FR-010/FR-011/FR-012 and SC-006 — the user explicitly chose this option (Option C of three offered) knowing it was described as "a substantial architectural change, not a small settings-screen addition" before confirming. A custom role with restricted menu access must genuinely restrict access everywhere, not just in a few new screens, or SC-006 ("pengguna itu hanya bisa mengakses menu yang diizinkan") is not actually met. | **Rejected: Option A** (assign one of the 4 fixed roles only) — does not satisfy FR-010/FR-011 at all, the user explicitly declined this narrower option. **Rejected: Option B** (individual per-user overrides on top of fixed roles) — narrower blast radius, but still requires the same central `canAccessMenu()` primitive and still touches most of the same call sites, while delivering less of what SC-006 asks for (a genuinely new, ownable role, not an exception list). Given the migration cost is similar either way, Option C's full data-driven model was not meaningfully more expensive than B once the central primitive exists, and it is what was explicitly approved. |
| Two-step user-table migration (add `role_id` alongside the existing `role` enum column, backfill from seeded default roles, then drop the enum in a follow-up migration rather than one atomic schema change) | The existing `role` enum column is read by 29+ call sites on the day this migration runs; changing it atomically in one migration risks a window where code deployed slightly out of sync with the migration reads a column that no longer exists. Splitting it into add-and-backfill / then-drop (as two ordered, dated migrations, consistent with this repo's existing convention of never reordering migration date prefixes) keeps each step independently safe and testable. | A single atomic migration was considered simpler, but this codebase's own `CLAUDE.md` explicitly documents a prior instance of exactly this kind of FK-sequencing hazard (`payments.preorder_id` created without a constraint, then constrained later in a follow-up migration, specifically to avoid an ordering hazard) — the two-step pattern already has precedent here and is the established safe default, not a novel complication. |
