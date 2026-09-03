# Implementation Plan: UX Enhancements — Product/POS Filters, Menu Styling, Dashboard, User Profile

**Branch**: `005-ux-enhancements-dashboard` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/005-ux-enhancements-dashboard/spec.md`

## Summary

Four independent slices on top of the existing Laravel + Vue SPA: (1) replace
the single-value artist/category chip filters on Products and POS with a
searchable multi-select dropdown (reusing/extending `BaseSelect.vue`,
extending `GET /products`'s `artist_id`/`category_id` filters to accept
arrays); (2) expand `DashboardView.vue` with role-gated shortcut tiles, a
day-filterable sales panel, category/artist/event breakdown charts, and
drill-through links, backed by new read-only dashboard aggregate endpoint(s)
that reuse `ReportController`'s existing mode-scoped query patterns; (3) a
new self-service Profile screen + `PUT /auth/password` and a self-service
photo route, reusing `ImageUploadService` and the mode-agnostic `users`
table; (4) an i18n string fix (`Purchase` → `Pembelian`) and a CSS-token fix
in `AppSidebar.vue` so parent-with-submenu items match standalone items.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), JavaScript/Vue 3 (Composition API, `<script setup>`)

**Primary Dependencies**: Laravel (Sanctum auth, Eloquent), Vue 3, Pinia, vue-i18n, Vite, Tailwind v4 (CSS-first tokens), Chart rendering — no charting library currently in `package.json` (NEEDS CLARIFICATION resolved in research.md)

**Storage**: MySQL 8 (`boothpos` / `boothpos_test`), local disk for uploaded images (`storage/app/public` for existing product/user photos)

**Testing**: `php artisan test` (Feature tests, real MySQL, no SQLite), Vitest (`npm test`) for frontend, manual browser verification per Constitution Principle II

**Target Platform**: Single local machine per store (Laravel API + Vue SPA on one box), reached over `localhost`

**Project Type**: Web application — Laravel API (`app/`, `routes/api.php`) + Vue SPA (`resources/js/`)

**Performance Goals**: Dashboard and filter interactions must feel instant on a single local machine (no network latency to model) — no new N+1 queries, dashboard aggregates eager-load/aggregate in SQL rather than in PHP loops

**Constraints**: No new frontend dependency without dynamic import if screen-scoped (Principle V); all authorization decisions enforced server-side, UI hiding is cosmetic only (Principle IV); all UI copy in Indonesian (Principle III)

**Scale/Scope**: 4 screens touched (Products, POS, Dashboard, new Profile), 1 sidebar component, ~3-5 new/extended backend endpoints, single-store scale (tens of concurrent users at most)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality**: Dashboard aggregates MUST be added as methods on `ReportController` (or a new `DashboardController` delegating to a `DashboardService`) rather than duplicating `SettlementService`/report query logic inline. Password change MUST go through a dedicated `FormRequest` (`UpdatePasswordRequest`) mirroring `UpdateLanguageRequest`'s self-service pattern, not inline validation in the controller. **PASS** — plan follows existing single-sanctioned-path conventions.
- **II. Testing Standards**: New Feature tests required for: password change (success + wrong-current-password + policy failure), self photo upload, dashboard aggregate endpoint(s) (mode-scoping!), multi-value artist/category filters on `GET /products`. New Vitest specs for the multi-select dropdown, ProfileView, DashboardView additions. Every touched screen re-verified in a running browser per Principle II. **PASS**.
- **III. UX Consistency**: Multi-select dropdown MUST use `@theme` tokens (extend `BaseSelect.vue` rather than hand-rolling a new component with hex values). Dashboard shortcuts MUST be omitted (not disabled) for unauthorized roles. All new UI copy in `lang/id` + `lang/en` i18n files (project already runs full i18n post-002-language-toggle). **PASS**.
- **IV. Security**: Password change requires current-password re-verification server-side (never trust a client "verified" flag). Self photo upload MUST scope to `$request->user()->id` only — NOT accept an arbitrary user id from the client, even though `UserPolicy::update` would technically allow an owner/admin to hit the existing `/users/{user}/photo` route for themselves; the profile page calls a *self-only* endpoint so a cashier/inventory role (who fails `UserPolicy::update`) can still change their own photo. Dashboard aggregate endpoint(s) MUST filter by the active DEMO/LIVE mode via the existing `DataModeScope` / explicit mode filter, matching `ReportController`'s existing pattern — **this is the single highest-risk item in this feature** since a new raw aggregate query that forgets mode scoping would leak DEMO data into LIVE reports (see CLAUDE.md's documented raw-query gotcha). **PASS, with explicit test coverage required** (documented in Phase 1).
- **V. Performance**: Dashboard chart data MUST be computed via `GROUP BY` in SQL (mirroring `ReportController::sales()`), not fetched raw and aggregated in PHP/JS. Any charting library added MUST be evaluated for bundle size and dynamically imported into `DashboardView.vue` only. **PASS**.

No violations requiring Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/005-ux-enhancements-dashboard/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   └── api-contract.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php          # + updatePassword()
│   │   ├── UserController.php          # + uploadOwnPhoto() (self-scoped)
│   │   ├── ProductController.php       # index(): accept artist_id[]/category_id[]
│   │   └── DashboardController.php     # NEW — shortcuts meta + analytics aggregates
│   └── Requests/
│       └── UpdatePasswordRequest.php   # NEW
├── Services/
│   └── DashboardService.php            # NEW — mode-scoped aggregate queries
routes/api.php                          # + PUT /auth/password, POST /auth/photo, GET /dashboard/*

resources/js/
├── components/
│   ├── ui/
│   │   ├── BaseSelect.vue              # extend or new BaseMultiSelect.vue (searchable, checkboxes)
│   └── layout/
│       └── AppSidebar.vue              # label fix + color-token fix
├── views/
│   ├── ProductsView.vue                # chip filters → multi-select dropdown
│   ├── PosView.vue                     # chip filters → multi-select dropdown
│   ├── DashboardView.vue               # shortcuts, day filter, charts, links, stats
│   └── ProfileView.vue                 # NEW
├── api/
│   ├── auth.js                         # + updatePassword(), uploadOwnPhoto()
│   ├── products.js                     # filter params → arrays
│   └── dashboard.js                    # NEW
├── router/index.js                     # + /profile route
└── locales/{id,en}.json                # new + corrected strings

tests/Feature/
├── AuthPasswordTest.php                # NEW
├── UserOwnPhotoTest.php                # NEW
├── DashboardTest.php                   # NEW (incl. DEMO/LIVE mode-scoping test)
└── ProductFilterMultiValueTest.php     # NEW

qa-tests/
├── ProfileView.test.js                 # NEW
├── DashboardView.test.js               # extend
├── ProductsView.test.js                # extend for multi-select
├── PosView.test.js                     # extend for multi-select
└── AppSidebar.test.js                  # extend for label/color
```

**Structure Decision**: Existing single web-app layout (Laravel API at repo
root + Vue SPA under `resources/js/`) is reused as-is; no new top-level
project or service is introduced. All four slices are additive changes to
existing controllers/views plus one new controller (`DashboardController`),
one new service (`DashboardService`), one new view (`ProfileView.vue`), and
one new reusable component (multi-select dropdown).

## Complexity Tracking

*No Constitution Check violations — table omitted.*
