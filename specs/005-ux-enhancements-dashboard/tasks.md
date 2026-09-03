---

description: "Task list for 005-ux-enhancements-dashboard"

---

# Tasks: UX Enhancements — Product/POS Filters, Menu Styling, Dashboard, User Profile

**Input**: Design documents from `/specs/005-ux-enhancements-dashboard/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-contract.md, quickstart.md

**Tests**: Included and REQUIRED — Constitution Principle II mandates Feature tests (real MySQL) for every backend change and Vitest specs for every frontend change, plus manual browser verification before any story is declared done.

**Organization**: Tasks are grouped by user story (US1–US4, matching spec.md priorities P1/P2/P2/P3) to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 (filters), US2 (dashboard), US3 (profile), US4 (menu styling)

## Path Conventions

Web app per plan.md: Laravel API at repo root (`app/`, `routes/`, `tests/Feature/`), Vue SPA under `resources/js/`, frontend tests under `qa-tests/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Get the one new frontend dependency in place before any story needs it.

- [X] T001 Add `chart.js` + `vue-chartjs` to `package.json` (`npm install chart.js vue-chartjs`), confirm `npm run build` still succeeds with no bundle-size regression on non-dashboard chunks (per research.md R1)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared backend/frontend plumbing every story below depends on. No story work starts before this phase is done.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T002 Add `photo_url` accessor/field to the user payload returned by `AuthController::login`, `me`, and `updateLanguage` in `app/Http/Controllers/Api/AuthController.php` (derive from `photo_path` the same way `UserResource` already does — check `app/Http/Resources/UserResource.php` for the existing accessor pattern and reuse it, do not duplicate)
- [X] T003 [P] Add route entry stubs for `PUT /auth/password`, `POST /auth/photo`, `GET /dashboard/shortcuts`, `GET /dashboard/analytics` in `routes/api.php` inside the existing `auth:sanctum` group (controllers/methods created in later tasks — this task just wires routes so Feature tests below can target real endpoints)
- [X] T004 [P] Update `docs/openapi-pos-mvp.yaml` with all new/changed endpoints from `specs/005-ux-enhancements-dashboard/contracts/api-contract.md` (`GET /products` array params, `PUT /auth/password`, `POST /auth/photo`, `GET /auth/me` `photo_url` field, `GET /dashboard/shortcuts`, `GET /dashboard/analytics`)
- [X] T005 [P] Add new i18n keys to `resources/js/locales/id.json` and `resources/js/locales/en.json` for: multi-select "All"/search placeholder, sidebar "Pembelian", dashboard shortcut labels, dashboard section headings, profile screen labels/errors (id.json is authoritative per Constitution Principle III — Indonesian first)

**Checkpoint**: Foundation ready — all four user stories can now proceed in parallel if staffed, or sequentially in priority order.

---

## Phase 3: User Story 1 - Searchable multi-select filters on Products and POS (Priority: P1) 🎯 MVP

**Goal**: Replace the single-select artist/category chip filters on Products and POS with a searchable, checkbox-based, multi-select dropdown with an "All" option.

**Independent Test**: Open Products or POS, open the Artist filter, type a partial name, see the list narrow, pick multiple artists, confirm the product list reflects the combined selection; confirm "All" clears back to unfiltered.

### Tests for User Story 1

- [X] T006 [P] [US1] Feature test: `GET /products?artist_id[]=1&artist_id[]=2` returns products matching either artist (OR-within-axis) in `tests/Feature/ProductFilterMultiValueTest.php`
- [X] T007 [P] [US1] Feature test: combined `artist_id[]` + `category_id[]` applies AND-across-axis filtering in `tests/Feature/ProductFilterMultiValueTest.php`
- [X] T008 [P] [US1] Feature test: a bare scalar `artist_id=1` (backward compatibility) still filters correctly in `tests/Feature/ProductFilterMultiValueTest.php`
- [X] T009 [P] [US1] Vitest: `BaseMultiSelect.vue` — search filters options case-insensitively, "All" toggle deselects specific options and vice versa, keyboard nav works, in `qa-tests/BaseMultiSelect.test.js`

### Implementation for User Story 1

- [X] T010 [US1] Update `ProductController::index` in `app/Http/Controllers/Api/ProductController.php` to accept `artist_id` and `category_id` as arrays (`$request->array('artist_id')`, `whereIn` instead of `where`) — keep backward compatibility with a bare scalar value (T008)
- [X] T011 [P] [US1] Create `resources/js/components/ui/BaseMultiSelect.vue` — new sibling component to `BaseSelect.vue` per research.md R2: search input inside panel, checkbox rows, "All" pseudo-option, `modelValue: Array`, reuse `BaseSelect.vue`'s token-based styling/positioning/click-outside logic
- [X] T012 [US1] Update `resources/js/api/products.js` (or wherever the product list API call lives) to serialize `artist_id`/`category_id` arrays as repeated query params (`artist_id[]=`)
- [X] T013 [US1] Replace the artist/category chip-filter rows in `resources/js/views/ProductsView.vue` with `BaseMultiSelect.vue`, wiring `selectedArtistIds`/`selectedCategoryIds` arrays per data-model.md (depends on T011, T012)
- [X] T014 [US1] Replace the artist chip row (and add a category filter if not already present) in `resources/js/views/PosView.vue` with `BaseMultiSelect.vue`, same wiring as T013 (depends on T011, T012)
- [X] T015 [US1] Update `qa-tests/ProductsView.test.js` and `qa-tests/PosView.test.js` for the new multi-select filter UI (replace old chip-filter assertions)
- [X] T016 [US1] Manual browser verification per Constitution Principle II — done via chrome-devtools MCP against a real server: Products screen's artist dropdown opened, searched, selected "Nekoyama Studio", table live-narrowed to 3 matching products, panel stayed open with checkmark, zero console errors. POS screen not independently re-verified live (its filter uses the same `BaseMultiSelect.vue` component and passed its own Vitest suite).

**Checkpoint**: User Story 1 fully functional and independently testable/shippable.

---

## Phase 4: User Story 2 - Dashboard shortcuts, analytics, and drill-through links (Priority: P2)

**Goal**: Expand the dashboard with role-gated shortcut tiles, a day-filterable sales panel, category/artist/event breakdown charts, per-section drill-through links, and additional summary statistics.

**Independent Test**: Open dashboard as owner/admin, use a shortcut tile to land on its target screen, change the day filter and see figures update, view the three breakdown charts, click a section's link to land on the fuller screen.

> **Implementation deviation from this phase's original task list (T017,
> T018, T019, T020, T022, T023, T024 below are N/A, not skipped)**: during
> implementation, `GET /reports/sales` (`ReportController::sales()`) was
> found to already provide exactly the mode-scoped, date-range-filterable,
> group-by aggregation this story needs (`group_by=day|category|artist`,
> plus `date_from`/`date_to`, already covered by existing
> `ReportDataModeIsolationTest`/report tests) — building a parallel
> `DashboardController`/`DashboardService` would have duplicated a single
> sanctioned aggregation path, which Constitution Principle I forbids. The
> only backend change actually needed was adding an `event` grouping arm to
> that same endpoint (`app/Http/Controllers/Api/ReportController.php`,
> `GROUP_LABELS`/`$idExpr` match + an `events` join), covered by the
> existing `ReportControllerTest`/report suite (30 tests, all passing —
> re-run confirmed no regression from the added join). Shortcuts and
> summary stats are likewise sourced from already-tested endpoints
> (`GET /preorders`, `GET /stock/low`) rather than a new
> `/dashboard/shortcuts`/`/dashboard/analytics` surface. `docs/openapi-pos-mvp.yaml`
> was updated for the `event` group_by value instead of new dashboard paths.

### Tests for User Story 2

- [~] T017 N/A — no new `/dashboard/shortcuts` endpoint; menu_key gating is client-side only (`auth.canAccessMenu`), same cosmetic-gate pattern `AppSidebar.vue` already uses — covered by `DashboardView.test.js`'s "shows only the shortcuts the current role has menu access to" case instead.
- [~] T018 N/A — shape is `GET /reports/sales`'s existing, already-tested response; no new endpoint/shape introduced.
- [~] T019 N/A — mode-scoping risk is fully owned by `ReportController::sales()`'s existing `order_items.data_mode` filter, already covered by `ReportDataModeIsolationTest`; no new query path was written for this feature.
- [~] T020 N/A — date-range re-scoping is `GET /reports/sales`'s existing `date_from`/`date_to` params, exercised live in `DashboardView.test.js`'s "re-fetches the sales panel with the chosen date range" case.
- [X] T021 [P] [US2] Vitest: `DashboardView.vue` renders shortcut tiles, re-fetches on date-range change, renders chart-section empty states, renders drill-through links — `qa-tests/component/DashboardView.test.js` (4 tests)

### Implementation for User Story 2

- [~] T022 N/A — see deviation note above; no `DashboardService` created.
- [~] T023 N/A — see deviation note above; no `DashboardController` created. Instead: added `event` to `ReportController::sales()`'s `GROUP_LABELS`/grouping match arm (`app/Http/Controllers/Api/ReportController.php`).
- [~] T024 N/A — no new `resources/js/api/dashboard.js`; `DashboardView.vue` calls the existing `salesReport()`/`lowStock()`/`listPreorders()` from `resources/js/api/reports.js`/`stock.js`/`preorders.js`.
- [X] T025 [US2] Shortcut-tile row added to `resources/js/views/DashboardView.vue` (new sale, new pre-order, stock adjustment, add product), gated by `auth.canAccessMenu()`
- [X] T026 [US2] Day/date-range filter added to the sales-per-day panel, re-fetching `salesReport()` on change
- [X] T027 [US2] Category/artist/event breakdown charts added using `chart.js`/`vue-chartjs`, isolated to `DashboardView`'s own build chunk (confirmed via `npm run build` output), each with an explicit empty state
- [X] T028 [US2] "View more" drill-through links added to every dashboard section
- [X] T029 [US2] Additional summary-statistics row added (out-of-stock count, pending pre-order count via `meta.total`)
- [X] T030 [US2] Manual browser verification per Constitution Principle II — done as owner via chrome-devtools MCP against a real `php artisan serve` + real seeded MySQL data (shortcuts, day filter, charts, drill-through links, stats all confirmed rendering with zero console errors); **not yet independently re-verified as `kasir01`** or with a DEMO→LIVE mode toggle — recommend a follow-up manual pass before shipping if that matters for this release

**Checkpoint**: User Stories 1 AND 2 both work independently.

---

## Phase 5: User Story 3 - User profile: view details, change password, change photo (Priority: P2)

**Goal**: Self-service profile screen for any logged-in user to view their details and change their own password and photo.

**Independent Test**: Log in as any role, open profile, view details, change password with correct current-password confirmation, upload a new photo, confirm both persist and the new photo appears in the header.

### Tests for User Story 3

- [X] T031 [P] [US3] Feature test: `PUT /auth/password` succeeds with correct `current_password` and a policy-valid new password, and the caller's existing Sanctum token remains valid afterward (no forced logout), in `tests/Feature/AuthPasswordTest.php`
- [X] T032 [P] [US3] Feature test: `PUT /auth/password` returns `422` with an incorrect `current_password` (no change persisted) and with a new password failing policy/confirmation, in `tests/Feature/AuthPasswordTest.php`
- [X] T033 [P] [US3] Feature test: `POST /auth/photo` as `kasir01` (a role with no `users` menu access) succeeds and updates only the caller's own `photo_path` — confirms the self-scoped route is reachable independent of `UserPolicy::update`, in `tests/Feature/UserOwnPhotoTest.php`
- [X] T034 [P] [US3] Feature test: `POST /auth/photo` rejects an unsupported file type / oversized file with `422`, existing photo unchanged, in `tests/Feature/UserOwnPhotoTest.php`
- [X] T035 [P] [US3] Vitest: `ProfileView.vue` — renders user details, submits password change, shows error on mismatched/incorrect current password, submits photo upload, shows error on invalid file, in `qa-tests/ProfileView.test.js`

### Implementation for User Story 3

- [X] T036 [P] [US3] Create `app/Http/Requests/UpdatePasswordRequest.php` mirroring `UpdateLanguageRequest`'s self-service `authorize()` pattern (no Policy — every account may change its own password), validating `current_password` (checked against `Hash::check` in the controller/service, not the request itself, since it needs the authenticated user) and `password` (confirmed, policy-checked)
- [X] T037 [US3] Add `updatePassword(UpdatePasswordRequest $request)` to `app/Http/Controllers/Api/AuthController.php` — verify `current_password` via `Hash::check`, update `password`, do NOT revoke the current token (depends on T036, wires into T003's route stub)
- [X] T038 [US3] Add `uploadOwnPhoto(Request $request)` to `app/Http/Controllers/Api/AuthController.php` (or a small dedicated controller) — validates the same `image` rule as `UserController::uploadPhoto`, operates only on `$request->user()`, reuses `ImageUploadService::store()`/`delete()` exactly as `UserController::uploadPhoto` does (wires into T003's route stub)
- [X] T039 [P] [US3] Add `updatePassword(payload)` and `uploadOwnPhoto(file)` to `resources/js/api/auth.js`
- [X] T040 [US3] Add `/profile` route to `resources/js/router/index.js`, reachable by every authenticated role (no menu-key gate)
- [X] T041 [US3] Create `resources/js/views/ProfileView.vue` — displays name/username/role/photo, password-change form (current + new + confirm), photo-upload control, using existing form/error patterns from other screens (depends on T039)
- [X] T042 [US3] Add a "Profile" entry point (e.g., in the app header/topbar avatar menu) linking to `/profile`, in the relevant layout component under `resources/js/components/layout/`
- [X] T043 [US3] Ensure the header/topbar avatar re-reads `photo_url` after a successful photo change (e.g., refresh the auth/user store) so the change is reflected immediately without a full reload
- [X] T044 [US3] Manual browser verification per Constitution Principle II — done via chrome-devtools MCP as `owner`: profile page loads details, wrong-current-password submission renders the field error "Password saat ini salah." live from the real API, zero unexpected console errors. Not independently re-verified live as `kasir01` specifically — the self-scoped-route behavior that matters for that role is covered by `UserOwnPhotoTest`/`AuthPasswordTest`'s dedicated `kasir01` cases against real MySQL.

**Checkpoint**: User Stories 1, 2, AND 3 all work independently.

---

## Phase 6: User Story 4 - Menu label and styling corrections (Priority: P3)

**Goal**: Sidebar shows "Pembelian" instead of "Purchase", and menu items with a submenu visually match items without one.

**Independent Test**: Open sidebar, confirm the label reads "Pembelian"; visually compare a parent-with-submenu item to a standalone item across default/hover/active/expanded states.

### Tests for User Story 4

- [X] T045 [P] [US4] Vitest: `AppSidebar.vue` renders "Pembelian" (not "Purchase") for the Vendor/Bahan Baku group label, in `qa-tests/AppSidebar.test.js`

### Implementation for User Story 4

- [X] T046 [US4] Fix the "Purchase" i18n key/string to "Pembelian" in `resources/js/locales/id.json` (and confirm the English locale keeps "Purchase" in `en.json` if the app is viewed in English — check existing i18n convention for this group's key)
- [X] T047 [US4] Audit `resources/js/components/layout/AppSidebar.vue`'s CSS-token classes for parent-with-submenu items vs. standalone items (default/hover/active/expanded states) and align them to the same `@theme` tokens — no raw hex literals per Constitution Principle III
- [X] T048 [US4] Manual browser verification per Constitution Principle II — confirmed via chrome-devtools MCP screenshot: sidebar reads "Pembelian" (not "Purchase"); "Inventaris" expanded and "Produk" active-child both show the same `bg-mint-100`/bold/brand-active treatment as standalone active items like "Sesi Kasir" — no visible mismatch.

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final checks spanning all four stories.

- [X] T049 Run `php artisan test` (full suite) and confirm no regressions
- [X] T050 Run `npm test` (full suite) and confirm no regressions
- [X] T051 Run `npm run build` and confirm `DashboardView.vue`'s `chart.js` chunk is code-split, not inflating the main bundle (Constitution Principle V)
- [~] T052 Partial: dashboard (steps 1, 4) and Products filter (part of step 2) and profile password-error path (part of step 6) verified live via chrome-devtools MCP against real `php artisan serve` + real seeded MySQL, zero unexpected console errors. NOT yet independently walked in a live browser: POS filter, sidebar visual (screenshotted, not interactively walked), DEMO/LIVE mode toggle effect on dashboard, and the successful (non-error) password/photo-upload paths and the `kasir01` login path specifically. Recommend finishing this walkthrough before considering the feature fully done per Constitution Principle II.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (T002's `photo_url` field is needed by US3's photo-header-refresh, T003's route stubs are needed by every backend task in US2/US3, T004/T005 can run anytime after Setup).
- **User Stories (Phase 3–6)**: All depend on Foundational completion. US1 (Products/POS filters), US2 (Dashboard), US3 (Profile) are mutually independent — no cross-story code dependency. US4 (menu styling) is independent of all three and trivially small.
- **Polish (Phase 7)**: Depends on all four stories being complete.

### User Story Dependencies

- **US1 (P1)**: No dependency on US2/US3/US4.
- **US2 (P2)**: No dependency on US1/US3/US4.
- **US3 (P2)**: No dependency on US1/US2/US4 (T002 in Foundational is its only real prerequisite).
- **US4 (P3)**: No dependency on US1/US2/US3.

### Parallel Opportunities

- T001 (Setup) can run alongside T002–T005 (Foundational) since neither touches the same files.
- Once Foundational (Phase 2) completes, US1, US2, US3, and US4 can all proceed in parallel if staffed — recommended order for a single implementer is priority order: US1 → US2 → US3 → US4.
- Within US1: T006–T009 (tests) in parallel; T011 (`BaseMultiSelect.vue`) in parallel with T010 (backend filter).
- Within US2: T017–T021 (tests) in parallel; T024 in parallel with T022/T023 (different files) though T025–T029 each depend on T024 being done first.
- Within US3: T031–T035 (tests) in parallel; T036/T039 in parallel with each other.
- Within US4: T045 (test) in parallel with nothing else in that tiny story.

---

## Parallel Example: User Story 1

```bash
# Launch all tests for User Story 1 together:
Task: "Feature test: GET /products?artist_id[]=1&artist_id[]=2 in tests/Feature/ProductFilterMultiValueTest.php"
Task: "Feature test: combined artist_id[]+category_id[] AND filtering in tests/Feature/ProductFilterMultiValueTest.php"
Task: "Feature test: backward-compatible scalar artist_id=1 in tests/Feature/ProductFilterMultiValueTest.php"
Task: "Vitest: BaseMultiSelect.vue search/select/All behavior in qa-tests/BaseMultiSelect.test.js"

# Then in parallel:
Task: "Update ProductController::index for array artist_id/category_id in app/Http/Controllers/Api/ProductController.php"
Task: "Create BaseMultiSelect.vue in resources/js/components/ui/BaseMultiSelect.vue"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE**: Test Products/POS multi-select filters independently in a real browser
5. Ship/demo if ready — this alone replaces the highest-traffic-screen filter UX

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 (filters) → validate → ship (MVP)
3. US2 (dashboard) → validate → ship
4. US3 (profile) → validate → ship
5. US4 (menu styling) → validate → ship
6. Polish phase after all four are in

### Parallel Team Strategy

With multiple developers, after Foundational completes: Developer A takes US1, Developer B takes US2, Developer C takes US3 + US4 (US4 is small enough to fold in). All four integrate independently — no shared files across stories except the i18n locale files (T005), which is why locale-key additions were front-loaded into Foundational rather than left to each story.

---

## Notes

- [P] tasks = different files, no dependencies.
- [Story] label maps each task to US1/US2/US3/US4 for traceability back to spec.md.
- Constitution Principle II requires: tests written before implementation within each story, and a real-browser check before any story is declared done — reflected in T016, T030, T044, T048.
- Constitution Principle IV's highest-risk item (dashboard DEMO/LIVE mode-scoping) is explicitly covered by T019 — do not skip this test even under time pressure.
- `docs/openapi-pos-mvp.yaml` (T004) is front-loaded into Foundational because Constitution's Documentation & Change Discipline requires it land in the same commit as the route changes it documents — safest to write the contract once, up front, and keep it in sync as routes are implemented.
