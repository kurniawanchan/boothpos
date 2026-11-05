# Implementation Plan: Per-Menu Action Permissions for Roles

**Branch**: `093-menu-action-permissions` (spec dir `057-…`, from `develop`) | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/057-menu-action-permissions/spec.md`

## Summary

Today a role is only a list of menus (`roles.menu_keys`); whoever can open a menu can do everything on it. This feature adds, per menu, a set of permitted **actions** (View, Create, Edit, Delete + named actions such as Void, Verify, Export). Design in one paragraph: a single registry (`App\Support\MenuActions`) lists every menu's actions; a nullable `roles.menu_actions` JSON stores the grants (**NULL = legacy = full access**, so no data migration and no behaviour change); ONE primitive `User::canDo(menu, action)` answers everything; a route middleware `ability:menu[,action]` is attached to every mutating route and a **route-coverage test fails the build when a mutating route has none**; `GET /auth/me` returns the resolved `permissions` map; the SPA hides what is not allowed through one composable + directive; the role editor gains an actions grid; role import/export carries a new optional column. The action gate is strictly **additional** to existing rules (owner/admin-only, recorder-only, cost/margin visibility), so it can only narrow.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing), Vue 3 SPA (existing)

**Primary Dependencies**: none new

**Storage**: MySQL 8, ONE additive nullable JSON column on `roles` (reversible migration)

**Testing**: PHPUnit (container, `-e` overrides only), Vitest + Testing Library in `qa-tests/`

**Target Platform**: localhost store install (native or Docker)

**Project Type**: web application (Laravel API + SPA)

**Performance Goals**: no extra query per request (role already loaded once per request); `/auth/me` payload grows by one small map

**Constraints**: server-side enforcement is the boundary (Constitution IV); existing behaviour must be byte-identical for every existing role until edited; bilingual UI; OpenAPI in the same commit; no raw hex

**Scale/Scope**: 22 menus, ~110 mutating routes, ~25 views with action buttons, 1 role editor, 1 role import/export sheet

## Constitution Check

- **I. Clean code / DRY** — one registry, one primitive (`canDo`), one middleware, one frontend composable; `canAccessMenu` stays the View primitive. PASS.
- **II. Testing** — route-coverage test, per-action allow/deny matrix, back-compat (NULL = full), no-widening, role-editor and per-screen component tests, real-browser check. PASS (T-tasks first).
- **III. UX consistency** — id + en copy for every action label; hidden (not disabled) controls; existing editor patterns reused. PASS.
- **IV. Security** — enforced server-side per request; hiding is cosmetic; 403 with machine-readable code; role edits audit-logged in the same transaction; lock-out protection extended. PASS.
- **V. Performance** — no N+1, no new query per request. PASS.
- Post-design re-check: PASS (see research D1–D10).

## Project Structure

### Documentation (this feature)

```text
specs/057-menu-action-permissions/
├── plan.md, research.md, data-model.md, quickstart.md
├── contracts/permissions-api.md
└── tasks.md            # /speckit-tasks
```

### Source Code (repository root)

```text
app/Support/MenuActions.php                       # NEW registry: menu → actions (+ route→action coverage helpers)
app/Http/Middleware/EnsureMenuAction.php          # NEW alias `ability`
app/Models/Role.php, app/Models/User.php          # canDo()/allows(), cast menu_actions
app/Http/Controllers/Api/RoleController.php, Store/UpdateRoleRequest, RoleResource, AuthController (permissions in /auth/me)
app/Policies/RolePolicy.php                       # lock-out guard extended to full actions on users+roles
app/Support/MasterDataSheets.php + role import/export service  # optional `menu_actions` column
database/migrations/*_add_menu_actions_to_roles_table.php
routes/api.php                                    # `->middleware('ability:…')` on every mutating route
resources/js/stores/auth.js (permissions, canDo), composables/useAbility.js, directives/can.js
resources/js/components/settings/RoleMenuPicker.vue (→ actions grid), views/RolesView.vue, ~25 views (hide controls)
resources/js/locales/{en,id}.json, lang/{en,id}/permissions.php
docs/openapi-pos-mvp.yaml
tests/Feature/MenuActionsRouteCoverageTest.php, RoleActionsMatrixTest.php, …; qa-tests/component/*
```

## Phases

- **Phase 0** → [research.md](research.md): decisions D1–D10, no open clarifications.
- **Phase 1** → [data-model.md](data-model.md), [contracts/permissions-api.md](contracts/permissions-api.md), [quickstart.md](quickstart.md).
