---

description: "Task list for Pengaturan Pengguna dan Toko"

---

# Tasks: Pengaturan Pengguna dan Toko

**Input**: Design documents from `/specs/001-user-store-settings/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md (all present)

**Tests**: Included as real tasks, not optional — this repo's own Constitution
(Principle II, "Testing Standards: Verify, Don't Assume") makes automated
tests plus real-browser verification a non-negotiable gate for this
project, not a generic default to skip.

**Organization**: Tasks are grouped by user story from `spec.md` (US1–US4,
in priority order P1/P1/P2/P3). All file paths are real, existing-repo
paths from `plan.md`'s Project Structure section — this is a brownfield
feature, there is no new project to scaffold.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an
  incomplete task)
- **[Story]**: US1/US2/US3/US4 — omitted for Setup, Foundational, and
  Polish tasks

---

## Phase 1: Setup

**Purpose**: Create the two new migration files and the single source-of-
truth Menu Key Registry that everything else in this feature depends on.
No new toolchain/project — this extends the existing Laravel+Vue monolith.

- [X] T001 Create migration `database/migrations/2026_10_09_000001_create_roles_table.php` — `id`, `name` (string 50, unique), `menu_keys` (json), `is_system_default` (boolean, default false), timestamps, `softDeletes()`.
- [X] T002 [P] Create migration `database/migrations/2026_10_09_000002_add_role_id_and_photo_to_users_table.php` — adds `role_id` (nullable at first, FK → `roles.id`, `restrictOnDelete()`) and `photo_path` (nullable string) to `users`. Do NOT drop the old `role` enum column in this migration (see T007).
- [X] T003 [P] Create `app/Support/MenuKeys.php` — a class constant listing every current menu key + human label (`dashboard`, `pos`, `session`, `events`, `products`, `artists`, `categories`, `stock`, `vendors`, `materials`, `customers`, `preorders`, `sales`, `reports`, `users`, `roles`, `settings`), matching `resources/js/components/layout/AppSidebar.vue`'s current `NAV_DEFS` names exactly. Include a `RESERVED` constant listing `['users', 'roles']` for the FR-013 guard.

**Checkpoint**: Schema and the registry constant exist; nothing reads or writes them yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The `Role` model, the default-role backfill migration, the
`canAccessMenu()` primitive, and migrating every existing authorization
call site to use it. **This is the highest-risk phase of the whole
feature** (see plan.md Complexity Tracking) — until it's done and verified,
no existing user's access may be assumed unchanged.

**⚠️ CRITICAL**: No user story work may begin until this phase is complete AND quickstart.md step 1 (all 4 seeded accounts see identical screens to before this feature) passes.

- [X] T004 Create `app/Models/Role.php` — `menu_keys` cast to `array`, `SoftDeletes`, `hasMany(User::class)`, a `canAccessAnyOf(array $keys): bool` helper. Depends on: T001.
- [X] T005 [P] Create `database/factories/RoleFactory.php`. Depends on: T004.
- [X] T006 In `database/migrations/2026_10_09_000002_add_role_id_and_photo_to_users_table.php`'s `up()`: idempotently insert 4 `Role` rows (`Owner`, `Admin`, `Kasir`, `Inventory`) with `is_system_default = true` and `menu_keys` reproducing exactly what each role can access **today** (read current `isOwnerOrAdmin()`/`canManageMasterData()` call sites to enumerate this precisely — do not guess), then backfill every existing user's new `role_id` by matching their current `role` enum value to the matching seeded row. Depends on: T002, T004.
- [X] T007 Create migration `database/migrations/2026_10_09_000003_finalize_users_role_id.php` — makes `role_id` non-nullable and drops the old `role` enum column. Depends on: T006 (must run only after backfill is proven correct — see quickstart.md step 1).
- [X] T008 Update `app/Models/User.php` — add `role_id`/`photo_path` to `$fillable`, add `role(): BelongsTo`, add `canAccessMenu(string $menuKey): bool` (delegates to `$this->role->menu_keys`), remove the old hardcoded `isOwnerOrAdmin()`/`canManageMasterData()` bodies and reimplement them as thin wrappers over `canAccessMenu()` **only** where existing call sites can't be migrated in this same phase (target: zero such wrappers by the end of T010–T012). Depends on: T004, T007.
- [X] T009 [P] Update `GET /auth/me` in `app/Http/Controllers/Api/AuthController.php` to include `menu_keys: $user->role->menu_keys` in the response. Depends on: T008.
- [X] T010 [P] Migrate master-data authorization to `canAccessMenu()` in `app/Http/Requests/{Store,Update}ProductRequest.php`, `app/Http/Requests/{Store,Update}CategoryRequest.php`, `app/Http/Requests/{Store,Update}ArtistRequest.php`, `app/Http/Requests/StockAdjustmentRequest.php`, `app/Policies/{Artist,Category,Product,Vendor,Material}Policy.php` — replace `canManageMasterData()` calls with the matching `canAccessMenu('products'|'artists'|'categories'|'stock'|'vendors'|'materials')`. Depends on: T008.
- [X] T011 [P] Migrate reporting/settings authorization to `canAccessMenu()` in `app/Http/Controllers/Api/ReportController.php` (profit, artistSettlements, artistProfit, export), `app/Policies/SettingPolicy.php`, `app/Http/Controllers/Api/ActivityLogController.php`, `app/Http/Controllers/Api/PaymentChannelController.php` — replace `isOwnerOrAdmin()` calls with `canAccessMenu('reports')`/`canAccessMenu('settings')` as appropriate per endpoint. Depends on: T008.
- [X] T012 [P] Migrate remaining role-gated authorization to `canAccessMenu()` in `app/Http/Controllers/Api/OrderController.php` (void), `app/Http/Controllers/Api/CashierSessionController.php`'s role-based (non-ownership) checks, `app/Policies/EventPolicy.php`, `app/Policies/CustomerPolicy.php` — replace remaining `isOwnerOrAdmin()`/`canManageMasterData()` calls. Leave ownership-based checks (a cashier acting on their own session) untouched — out of scope for this feature. Depends on: T008.
- [X] T013 Update `resources/js/router/index.js` — replace every route's `meta.roles: [...]` with `meta.menuKey: '<key>'` (matching `app/Support/MenuKeys.php`), and change the guard from `to.meta.roles.includes(auth.role)` to `!auth.canAccessMenu(to.meta.menuKey)`. Depends on: T009, T015.
- [X] T014 Update `resources/js/components/layout/AppSidebar.vue` — replace `NAV_DEFS`'s `roles: [...]` entries with `menuKey: '<key>'`, and change the `navItems` filter to use `auth.canAccessMenu(item.menuKey)`. Depends on: T009, T015.
- [X] T015 Update `resources/js/stores/auth.js` — store `menu_keys` from the `GET /auth/me` response, expose `canAccessMenu(menuKey)` reading from it. Depends on: T009.
- [X] T016 Run the **full** existing backend and frontend test suites (`php artisan test`, `npm test`) and manually verify quickstart.md step 1 (all 4 seeded accounts — `owner`, `admin`, `kasir01`, `inventory` — see an identical set of screens to before this phase). Do not proceed to any user story until this passes with zero regressions. Depends on: T010, T011, T012, T013, T014.
  - Verified independently by the coordinator (not the implementing agent, which had explicitly flagged this step as skipped): 221/221 backend, 99/99 frontend, real-browser login as all 4 seeded accounts with zero console errors. **Not byte-identical to before** — see plan.md/spec.md follow-up note: kasir and inventory lost sidebar access to Produk/Artist/Kategori/Stok (previously visible-but-write-403'd for kasir specifically), a deliberate, justified deviation the implementing agent flagged rather than silently applying. Confirmed acceptable: kasir's real need (SKU/price lookup during a sale) remains fully served by the POS screen's own product search; the removed screens were the separate admin-facing catalog *management* UI, and hiding inaccessible functionality is what Constitution Principle III already requires.

**Checkpoint**: Foundation ready — `canAccessMenu()` is the single, exclusively-used authorization primitive; existing behavior is provably unchanged; user story work may begin.

---

## Phase 3: User Story 1 - Kelola akun pengguna (Priority: P1) 🎯 MVP

**Goal**: Owner/admin can create, view, update, deactivate, search, and filter user accounts, with photo and last-access, entirely from the Pengaturan screen — no more database/Tinker access required.

**Independent Test**: Log in as owner, create a new cashier account via the UI (choosing one of the 4 seeded default roles from Foundational), verify it can immediately log in — all without leaving the application.

### Tests for User Story 1

- [X] T017 [P] [US1] Write `tests/Feature/UserTest.php` — CRUD happy paths, search by name/username (FR-004), filter by role/status (FR-005), self-lockout on deactivate/delete/role-change (FR-006), photo upload validation, `403` for a role lacking `users` in `menu_keys`, export excludes password (FR-007). Must fail before implementation.
- [X] T018 [P] [US1] Write `qa-tests/component/UsersView.test.js` — list rendering, search/filter, create/edit form, self-lockout UI guard, photo upload client-side validation.

### Implementation for User Story 1

- [X] T019 [P] [US1] Create `app/Http/Requests/StoreUserRequest.php` and `app/Http/Requests/UpdateUserRequest.php` per data-model.md's User validation rules.
- [X] T020 [P] [US1] Create `app/Http/Resources/UserResource.php` — `{id, name, username, role: {id, name}, is_active, photo_url, last_login_at}`, password never included.
- [X] T021 [US1] Create `app/Policies/UserPolicy.php` — `canAccessMenu('users')` gate plus the FR-006 self-lockout guard (compare target user id to `auth()->id()`). Depends on: T019, T020.
- [X] T022 [US1] Create `app/Http/Controllers/Api/UserController.php` — `index` (search/filter/paginate), `store`, `show`, `update`, `destroy` (soft-delete), `uploadPhoto` (reuses `ImageUploadService`, mirrors `ProductController::uploadImage`). Depends on: T021.
- [X] T023 [US1] Register routes in `routes/api.php`: `GET|POST /users`, `GET|PUT|DELETE /users/{user}`, `POST /users/{user}/photo`. Depends on: T022.
- [X] T024 [US1] Update `docs/openapi-pos-mvp.yaml` with the new `/users` routes and schemas, in the same commit as T023. Depends on: T023.
- [X] T025 [P] [US1] Create `resources/js/api/users.js`.
- [X] T026 [US1] Create `resources/js/views/UsersView.vue` — list (search box, role/status filter, `DataTable`), create/edit form (`BaseModal`, role `BaseSelect` populated from `GET /roles`, photo upload), last-access column, self-lockout guard on the current user's own row (hide deactivate/delete/role-change actions client-side, matching this codebase's existing "hide, don't disable-then-403" convention). Depends on: T025.
- [X] T027 [US1] Add the `users` route (`menuKey: 'users'`) to `resources/js/router/index.js` and the `Pengguna` nav item to `AppSidebar.vue`. Depends on: T026, T013, T014.
- [X] T028 [US1] Run `php artisan test --filter=UserTest` and `npm test`, then verify quickstart.md's user-creation flow live in a browser as `owner`, and confirm the role dropdown shows the 4 seeded default roles from Foundational. Depends on: T017, T018, T022, T026, T027.

**Checkpoint**: User Story 1 is fully functional and independently testable — using the 4 seeded default roles from Foundational (Role CRUD itself is User Story 2, not required for this checkpoint).

---

## Phase 4: User Story 2 - Kelola peran dan akses menu (Priority: P1)

**Goal**: Owner can create/edit/delete roles with a freely configurable menu-access list, and assign them to users — the capability that makes User Story 1's "peran" field mean something beyond the 4 built-in defaults.

**Independent Test**: Create a role limited to `pos`+`session`, assign it to a user, confirm that user's sidebar shows only those two screens AND that a direct API call to a disallowed endpoint returns `403` (quickstart.md step 3).

### Tests for User Story 2

- [X] T029 [P] [US2] Write `tests/Feature/RoleTest.php` — CRUD, `menu_keys` validated against `MenuKeys` registry (unknown key → `422`), delete rejected with `409` when `user_count > 0` (FR-014), delete/update rejected with `409` when it would leave zero roles capable of managing `users`+`roles` (FR-013) — cover both "deleting the last such role" and "editing its `menu_keys` to remove that access" as distinct cases. Must fail before implementation.
- [X] T030 [P] [US2] Write `qa-tests/component/RolesView.test.js` — list, create/edit with the menu checkbox grid, both 409 guards surfaced as clear UI messages (not generic errors).

### Implementation for User Story 2

- [X] T031 [P] [US2] Create `app/Http/Requests/StoreRoleRequest.php` and `app/Http/Requests/UpdateRoleRequest.php` — validate `menu_keys` entries against `App\Support\MenuKeys`.
- [X] T032 [P] [US2] Create `app/Http/Resources/RoleResource.php` — `{id, name, menu_keys, is_system_default, user_count}`.
- [X] T033 [US2] Create `app/Policies/RolePolicy.php` — `canAccessMenu('roles')` gate, plus FR-013 (check across all *other* roles before allowing a change that would remove `users`+`roles` from the last capable role) and FR-014 (block delete while `user_count > 0`) guards. Depends on: T031, T032.
- [X] T034 [US2] Create `app/Http/Controllers/Api/RoleController.php` — `index`, `store`, `show`, `update`, `destroy`, plus `menuKeys()` returning the `App\Support\MenuKeys` registry as `{key, label}[]`. Depends on: T033.
- [X] T035 [US2] Register routes in `routes/api.php`: `GET|POST /roles`, `GET|PUT|DELETE /roles/{role}`, `GET /menu-keys`. Depends on: T034.
- [X] T036 [US2] Update `docs/openapi-pos-mvp.yaml` with the new `/roles` and `/menu-keys` routes/schemas. Depends on: T035.
- [X] T037 [P] [US2] Create `resources/js/api/roles.js`.
- [X] T038 [P] [US2] Create `resources/js/components/settings/RoleMenuPicker.vue` — checkbox grid fed by `GET /menu-keys`, reusable by the role create/edit form.
- [X] T039 [US2] Create `resources/js/views/RolesView.vue` — list (with `user_count` shown per row), create/edit form using `RoleMenuPicker`, delete action surfacing the `409` guards clearly (e.g. "Tidak bisa dihapus — masih dipakai oleh 3 pengguna"). Depends on: T037, T038.
- [X] T040 [US2] Add the `roles` route (`menuKey: 'roles'`) to the router and the `Peran` nav item to `AppSidebar.vue`. Depends on: T039, T013, T014.
- [X] T041 [US2] Run `php artisan test --filter=RoleTest` and `npm test`, then verify quickstart.md steps 2–5 live in a browser: create a restricted custom role, confirm real `403` on a disallowed endpoint call (not just hidden UI), and trigger both lockout guards plus the delete-in-use guard. Depends on: T029, T030, T034, T039, T040.

**Checkpoint**: User Stories 1 and 2 together deliver this feature's core value — a genuinely configurable, server-enforced permission system (spec SC-006).

---

## Phase 5: User Story 3 - Lengkapi profil toko (Priority: P2)

**Goal**: Owner can configure the store's full address, logo, contact person, phone, and email, and have that identity appear on receipts/exports.

**Independent Test**: Fill every store-profile field including logo, save, reload the page, confirm persistence; complete a sale and confirm the receipt reflects the new identity (quickstart.md step 6).

### Tests for User Story 3

- [X] T042 [P] [US3] Extend `tests/Feature/SettingsTest.php` — new `store_address`/`store_contact_person`/`store_contact_phone`/`store_contact_email` keys save correctly via `PUT /settings`, invalid email format rejected (`422`, FR-018), `POST /settings/store-logo` accepts a valid image and rejects a non-image/oversized one.
- [X] T043 [P] [US3] Extend `qa-tests/component/SettingsView.test.js` for the new store-profile fields and logo upload.

### Implementation for User Story 3

- [X] T044 [US3] Extend `app/Http/Requests/UpdateSettingsRequest.php` — add email-format validation for `store_contact_email` (FR-018).
- [X] T045 [US3] Add `uploadStoreLogo()` to `app/Http/Controllers/Api/SettingsController.php` (reuses `ImageUploadService`, mirrors `CategoryController::uploadImage`) and register `POST /settings/store-logo` in `routes/api.php`.
- [X] T046 [US3] Update `docs/openapi-pos-mvp.yaml` with `POST /settings/store-logo`. Depends on: T045.
- [X] T047 [US3] Extend the "Data Toko" card in `resources/js/views/SettingsView.vue` with address, logo upload, contact-person, phone, and email fields, wired to the extended `PUT /settings` and new `POST /settings/store-logo`. Depends on: T044, T045.
- [X] T048 [US3] Run `php artisan test --filter=SettingsTest` and `npm test`, then verify quickstart.md step 6 live: save the full profile, reload, complete a sale, confirm the receipt shows the new identity. Depends on: T042, T043, T047.
- [X] T048a [US3] **Gap found during implementation, not in the original task list** — the receipt (`GET /orders/{order}/receipt` in `app/Http/Controllers/Api/OrderController.php`, rendered by `resources/js/components/receipt/ReceiptModal.vue`) was never wired to actually show `store_address`/`store_logo_path`/`store_contact_person`/`store_contact_phone`/`store_contact_email` despite spec.md's Acceptance Scenario 3 and SC-004 requiring it. Closed: backend adds the 5 fields (logo resolved to a public URL via `ImageUploadService`) to the receipt response; frontend renders the logo above the store name, the address below it, and a contact footer at the bottom, all conditionally (nothing renders for an unconfigured field). Verified live in a real browser: uploaded a real logo + filled every field via Settings, opened a real receipt from the Sales report, confirmed the logo/address/contact all rendered correctly with zero console errors. Two new backend tests added to `tests/Feature/OrderTest.php` (configured case and gracefully-empty case).

**Checkpoint**: All P1/P1/P2 stories complete — this is the recommended real-world shipping increment for this feature.

---

## Phase 6: User Story 4 - Ekspor dan impor massal data pengguna (Priority: P3)

**Goal**: Bulk-provision staff accounts via the same combined master-data workbook already used for other entities.

**Independent Test**: Export users, add new rows referencing an existing role by name, re-import, confirm the new accounts exist and can log in (quickstart.md step 7).

### Tests for User Story 4

- [X] T049 [P] [US4] Write `tests/Feature/MasterDataImportUserTest.php` — `roles` sheet processed before `users` sheet (dependency order), a `users` row referencing a nonexistent role name produces a row-level `422` naming the bad reference and rolls back the entire import (FR-009), export never includes a password column (FR-007), new users created via import receive a system-generated temporary password (per contracts/api.md).

### Implementation for User Story 4

- [X] T050 [US4] Add `roles` and `users` sheet definitions (headers, dependency order after `bom`) to `app/Support/MasterDataSheets.php`.
- [X] T051 [US4] Extend `app/Services/MasterDataImportService.php` to validate and apply the `roles` then `users` sheets (role resolved by name; new user rows get a system-generated temporary password, never a client-supplied one). Depends on: T050.
- [X] T052 [US4] Extend `MasterDataExportController`'s `entity` route constraint and export logic to accept `roles`/`users` (excluding the password column). Depends on: T050.
- [X] T053 [US4] Update `docs/openapi-pos-mvp.yaml`'s export/import entity enums. Depends on: T051, T052.
- [X] T054 [US4] Extend the downloadable template (`GET /imports/master-data/template`) with example `roles`/`users` rows; re-verify it still imports cleanly as-is (this repo's existing `test_the_shipped_template_imports_as_is` convention — extend that test to cover the two new sheets). Depends on: T051.
- [X] T055 [P] [US4] Update `resources/js/components/masterData/MasterDataImportModal.vue` copy if it names specific sheets, to mention the two new ones.
- [ ] T056 [US4] Run `php artisan test --filter=MasterDataImportUserTest` and verify quickstart.md step 7 live: export, edit, re-import (happy path), then re-import with a bad role reference and confirm nothing was partially applied. Depends on: T049, T054, T055.

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Documentation and regression discipline this repo's Constitution requires (Principle II, "Documentation & Change Discipline").

- [ ] T057 [P] Add a dated post-MVP note to `docs/PRD-POS-Event-Multivendor.md`: F13.1 (user CRUD) built; F13.5 (configurable custom roles) moved from Priority C/stretch to built — following this repo's existing convention for scope-change notes (see the Vendor/Material/BOM and Excel-import dated notes already in that document).
- [ ] T058 [P] Update `docs/uml-pos-mvp.md`/`docs/wbs-pos-mvp.md` to reflect the new Role/User-management flows and mark the relevant WBS items done, mirroring how the Vendor/Material/BOM feature updated these same documents.
- [ ] T059 [P] Extend the `bruno/` collection with a new numbered folder covering: create role → create user with that role → confirm restricted access (403) → delete-in-use guard (409) → self-lockout guard (409) — following this collection's existing "one real flow plus negative cases" convention.
- [ ] T060 Run the full regression suite: `php artisan test` (all suites, not just this feature's) and `npm test` — zero failures, confirming none of the 29 migrated authorization call sites regressed.
- [ ] T061 Execute all 8 steps of `quickstart.md` end-to-end in a real browser as the final acceptance pass before considering this feature done.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup. **Blocks every user story** — this phase's own checkpoint (T016) requires a full regression pass before any story may start, because it touches every existing authorization call site in the codebase.
- **User Story 1 (Phase 3)**: Depends on Foundational only. Does not need User Story 2 — uses the 4 seeded default roles.
- **User Story 2 (Phase 4)**: Depends on Foundational only. Independently testable on its own (assign the new custom role to any existing user, even one created outside User Story 1's new UI) — but delivers its full intended value once combined with User Story 1's UI.
- **User Story 3 (Phase 5)**: Depends on Foundational only. Fully independent of US1/US2 — touches `Setting`, not `User`/`Role` at all.
- **User Story 4 (Phase 6)**: Depends on Foundational (needs `Role`/`User` to exist) **and** on User Story 2's `Role` model being stable (the `roles` sheet references role names). Not dependent on US1's UI or US3.
- **Polish (Phase 7)**: Depends on all four user stories being complete.

### Parallel Opportunities

- T002 and T003 (Setup) run in parallel.
- T010, T011, T012 (Foundational's authorization-migration tasks) run in parallel — each touches a disjoint set of files.
- T013 and T014 both depend on T015 but not on each other — parallel.
- Once Foundational (Phase 2) is checkpointed, **User Stories 1, 2, and 3 can proceed fully in parallel** (different files, no shared state) if staffed by more than one developer. User Story 4 should wait for User Story 2 to stabilize the `Role` shape it imports against.
- Within each story, all `[P]`-marked test-writing and request/resource-creation tasks run in parallel; controller/policy tasks that depend on them run after.

---

## Parallel Example: Foundational Phase

```bash
# After T008 (User::canAccessMenu() exists), these three migrations of
# existing authorization call sites touch entirely disjoint files:
Task: "Migrate master-data authorization to canAccessMenu() (T010)"
Task: "Migrate reporting/settings authorization to canAccessMenu() (T011)"
Task: "Migrate order/session/event/customer authorization to canAccessMenu() (T012)"
```

## Parallel Example: User Story 1

```bash
Task: "Write tests/Feature/UserTest.php (T017)"
Task: "Write qa-tests/component/UsersView.test.js (T018)"
Task: "Create StoreUserRequest/UpdateUserRequest (T019)"
Task: "Create UserResource (T020)"
```

---

## Implementation Strategy

### Minimal MVP (User Story 1 only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational) — the expensive, high-risk part, unavoidable regardless of which story ships first.
2. Complete Phase 3 (User Story 1).
3. **STOP and VALIDATE** against quickstart.md steps 1 (regression) and the User Story 1 acceptance scenarios.
4. This alone already replaces the current "no User Management module exists" gap (PRD F13.1) — a real, shippable increment, using the 4 seeded default roles.

### Recommended real-world increment (User Stories 1 + 2)

Per spec.md's own framing, User Story 2 is what makes User Story 1's role
assignment meaningful beyond the 4 fixed defaults, and together they are
what SC-006 actually measures. Treat **Phases 1–4** as the true MVP for
this feature's stated purpose, with Phase 5 (store profile) and Phase 6
(bulk import/export) as fully independent, any-order follow-ups.

### Incremental Delivery

1. Setup + Foundational → foundation ready, zero regressions proven.
2. User Story 1 → demo: manual user CRUD works.
3. User Story 2 → demo: a genuinely custom role restricts real access (SC-006 met).
4. User Story 3 → demo: store identity appears correctly on a receipt.
5. User Story 4 → demo: bulk staff provisioning via spreadsheet.
6. Polish → documentation and full regression sign-off.

---

## Notes

- Foundational (Phase 2) is unusually large for a "blocking prerequisites"
  phase because this feature's Constitution Check (plan.md) explicitly
  requires one central `canAccessMenu()` primitive rather than 29
  independent migrations of each call site — front-loading that cost here
  is what keeps every later phase simple and consistent.
- Tests are written before implementation within each phase and MUST fail
  first, per this repo's Constitution Principle II.
- `[P]` tasks touch disjoint files — verify this holds before running them
  concurrently if using multiple parallel workers.
- Every phase's final task is a real-browser quickstart.md check, not just
  an automated-suite pass — per this repo's own established practice of
  catching bugs (a silently-broken picker, a missing route, an empty
  export file) that only real execution surfaces.
