# Tasks: Ganti Bahasa Antarmuka (Indonesia/English)

**Input**: Design documents from `/specs/002-language-toggle/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Included — this codebase's Constitution Principle II makes
backend tests (`tests/Feature/`, real MySQL) and frontend tests
(`qa-tests/`) mandatory for any change, plus a real-browser pass.

**Organization**: Tasks are grouped by user story per spec.md (US1 = P1,
US2 = P2). Full-app translation coverage (FR-008) is the bulk of US1's
task list — it is not a separate story because it has no independent
value on its own (a toggle that only translates one screen is not
useful), it is what makes US1's "seluruh aplikasi" acceptance criteria
true.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2)
- File paths are exact — see plan.md's Project Structure for the full map

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Scaffold the i18n infrastructure that doesn't exist yet in
this codebase at all (see research.md) — no behavior yet, just the
skeleton both sides will be populated into.

- [X] T001 Create `lang/id/` and `lang/en/` directories, each with empty
      skeleton files (`return [];`) named per backend domain:
      `auth.php`, `validation.php`, `policies.php`, `users.php`,
      `roles.php`, `master_data.php`, `vendors_materials.php`,
      `events_sessions.php`, `orders_payments.php`, `preorders.php`,
      `reports.php`, `settings.php`, `activity_log.php`,
      `master_data_import.php`.
- [X] T002 [P] `npm install vue-i18n@9` (Vue 3 / Composition API build) —
      add to `package.json`/`package-lock.json`.
- [X] T003 [P] Create `resources/js/locales/id.json` and
      `resources/js/locales/en.json` skeletons with matching top-level
      namespace keys: `common`, `nav`, `dashboard`, `pos`, `master_data`,
      `vendors_materials`, `events_sessions`, `preorders`, `reports`,
      `settings`, `users`, `roles`, `activity_log`, `errors`.
- [X] T004 Create `resources/js/i18n.js` — `createI18n()` instance,
      `legacy: false` (Composition API mode), `locale: 'en'`,
      `fallbackLocale: 'id'` (source-of-truth language, so a not-yet-
      translated English key falls back to the original Indonesian
      string instead of rendering blank), `messages` from the two JSON
      files created in T003.
- [X] T005 Register the i18n plugin in `resources/js/main.js`
      (`app.use(i18n)`), after the existing Pinia/router setup.

**Checkpoint**: i18n plumbing exists on both sides but nothing uses it
yet — no behavior change, app still runs identically.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The actual language-preference mechanism (storage, endpoint,
locale resolution) that every user story and every translated screen
depends on.

**⚠️ CRITICAL**: No translation work (US1) can be meaningfully tested end
to end until this phase is complete.

- [X] T006 Migration `database/migrations/2026_10_10_000001_add_language_to_users_table.php`
      — `$table->enum('language', ['id', 'en'])->default('en')->after('photo_path');`
      per data-model.md (single additive migration, no two-stage pattern
      needed here).
- [X] T007 `app/Models/User.php` — add `language` to `$fillable`; document
      in a short comment why no `$casts` entry is needed (a plain string
      enum value round-trips fine without a cast, unlike JSON columns
      like `menu_keys`).
- [X] T008 `app/Http/Requests/UpdateLanguageRequest.php` (new) — validates
      `language` as `['required', 'string', Rule::in(['id', 'en'])]`;
      `authorize()` returns `true` unconditionally (self-service, no
      policy gate — see research.md Decision 4). Add a comment citing
      why this deliberately does NOT reuse `UserPolicy`.
- [X] T009 `app/Http/Controllers/Api/AuthController.php` — add
      `updateLanguage(UpdateLanguageRequest $request)`: updates
      `$request->user()->update(['language' => $request->validated('language')])`,
      returns the updated user in the same shape as `me()`.
- [X] T010 `routes/api.php` — add `Route::put('/auth/language', [AuthController::class, 'updateLanguage'])`
      inside the `auth:sanctum` group.
- [X] T011 `app/Http/Controllers/Api/AuthController.php` — add `language`
      to the user payload returned by both `login()` and `me()` (either
      inline array or via `UserResource` if one already covers this
      shape — check for reuse before duplicating the array literal, per
      Constitution Principle I).
- [X] T012 `app/Http/Middleware/SetLocaleFromUser.php` (new) — calls
      `App::setLocale($request->user()->language)`; add a comment noting
      this is the SINGLE place backend locale is decided (Principle I).
- [X] T013 `routes/api.php` — register `SetLocaleFromUser` on the
      `auth:sanctum` route group (after the auth middleware, so
      `$request->user()` is resolved); explicitly verify `POST /auth/login`
      is OUTSIDE this group (it already is, per existing route structure)
      so FR-001 holds without extra code.
- [X] T014 `config/app.php` — set `'locale' => env('APP_LOCALE', 'id')`
      and `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'id')` (was
      `'en'`/`'en'`, the unmodified Laravel default that never matched
      this product's actual all-Indonesian history — see research.md
      Decision 2).
- [X] T015 [P] `tests/Feature/LanguagePreferenceTest.php` (new) — covers:
      `PUT /auth/language` success (`id`→`en` and back), `422` on an
      invalid value (e.g. `"fr"`), a user with NO `users`-menu access
      (e.g. a Kasir-role account) can still successfully call this
      endpoint (proves the self-service exemption from T008), the
      updated value is reflected in a subsequent `GET /auth/me` without
      re-login, and two different users' `language` values don't affect
      each other.
- [X] T016 [P] `resources/js/stores/auth.js` — read `language` from the
      login/`/auth/me` response, set `i18n.global.locale.value` to it on
      login and app boot; add a `setLanguage(lang)` action that calls
      `PUT /auth/language` and updates both the store's `user.language`
      and `i18n.global.locale.value` reactively on success.
- [X] T017 [P] `resources/js/components/layout/LanguageSwitcher.vue`
      (new) — a small two-option control (e.g. `BaseSelect` or a toggle)
      bound to `authStore.user.language`, calling `authStore.setLanguage()`
      on change; uses `BaseSelect`/existing UI primitives, no new
      one-off styling (Constitution Principle III — design tokens only).
- [X] T018 `resources/js/components/layout/AppTopbar.vue` — mount
      `LanguageSwitcher.vue` so it's reachable from every authenticated
      screen (FR-003/FR-004), not buried in one settings page.

**Checkpoint**: The toggle mechanism works end to end for whatever
strings are already wired to `t()`/`__()` — at this point NOTHING is
translated yet (US1's bulk of work is next), but the plumbing is provably
correct via T015/T016 tests.

---

## Phase 3: User Story 1 - Preferensi bahasa tersimpan per pengguna (Priority: P1) 🎯 MVP

**Goal**: Every screen after login respects the logged-in user's stored
language preference; the login screen and receipts are explicitly
excluded (FR-001, FR-009); switching is instant with no data loss in
open forms (FR-011).

**Independent Test**: Login as user A, switch to Bahasa Indonesia,
logout; login as user B (never touched language) on the same
device/browser — confirm B still sees English, unaffected by A. Re-login
as A — confirm the app opens in Bahasa Indonesia without re-selecting.

### Backend string migration for User Story 1

> Each task below converts existing literal-Indonesian strings in the
> named file(s) into `__('domain.key')` calls, and populates the matching
> key in BOTH `lang/id/<domain>.php` (with the exact original Indonesian
> text) and `lang/en/<domain>.php` (with its English translation).

- [X] T019 [P] [US1] `app/Http/Controllers/Api/AuthController.php` login
      failure/inactive-account messages → `lang/{id,en}/auth.php`.
- [X] T020 [P] [US1] `app/Policies/UserPolicy.php` and
      `app/Policies/RolePolicy.php` — self-lockout (409) and
      last-capable-role (409) messages → `lang/{id,en}/policies.php`.
- [X] T021 [P] [US1] `app/Http/Requests/StoreUserRequest.php`,
      `UpdateUserRequest.php`, `StoreRoleRequest.php`,
      `UpdateRoleRequest.php` — custom `messages()` strings →
      `lang/{id,en}/users.php` + `lang/{id,en}/roles.php`.
- [X] T022 [P] [US1] `app/Http/Controllers/Api/ArtistController.php`,
      `CategoryController.php`, `ProductController.php`,
      `StockController.php` and their `FormRequest`/delete-guard
      messages → `lang/{id,en}/master_data.php`.
- [X] T023 [P] [US1] `app/Http/Controllers/Api/VendorController.php`,
      `MaterialController.php` and vendor-price/BOM guard messages →
      `lang/{id,en}/vendors_materials.php`.
- [X] T024 [P] [US1] `app/Http/Controllers/Api/CustomerController.php`,
      `EventController.php`, `CashierSessionController.php` →
      `lang/{id,en}/events_sessions.php`.
- [X] T025 [P] [US1] `app/Http/Controllers/Api/OrderController.php`
      (EXCLUDING `receipt()` — see T031), `PaymentChannelController.php`,
      `PaymentProofController.php` → `lang/{id,en}/orders_payments.php`.
- [X] T026 [P] [US1] `app/Http/Controllers/Api/PreorderController.php`,
      `ShipmentController.php` → `lang/{id,en}/preorders.php`.
- [X] T027 [P] [US1] `app/Http/Controllers/Api/ReportController.php` →
      `lang/{id,en}/reports.php`.
- [X] T028 [P] [US1] `app/Http/Controllers/Api/SettingsController.php`,
      `app/Policies/SettingPolicy.php` → `lang/{id,en}/settings.php`.
- [X] T029 [P] [US1] `app/Http/Controllers/Api/ActivityLogController.php`
      → `lang/{id,en}/activity_log.php`.
- [ ] T030 [P] [US1] `app/Services/MasterDataImportService.php`,
      `MasterDataExportController.php`, `MasterDataImportController.php`
      — per-row import error messages → `lang/{id,en}/master_data_import.php`.
- [X] T031 [US1] `app/Http/Controllers/Api/OrderController.php::receipt()`
      — add an explicit `BUG YANG DITEMUKAN & DIPERBAIKI`-style comment
      (per Constitution "Documentation & Change Discipline") stating
      these labels are deliberately NOT converted to `__()` — struk
      selalu Bahasa Indonesia terlepas dari locale aktif (FR-009,
      research.md Decision 5). Depends on T025 completing first to avoid
      a merge conflict on the same file.

### Frontend string migration for User Story 1

- [X] T032 [P] [US1] `resources/js/components/layout/AppShell.vue`,
      `AppSidebar.vue`, `AppTopbar.vue` — nav labels/group titles →
      `t('nav.*')`, populate `locales/{id,en}.json` under `nav`.
- [X] T033 [P] [US1] `resources/js/views/DashboardView.vue` → `t('dashboard.*')`.
- [X] T034 [P] [US1] `resources/js/views/PosView.vue`,
      `components/pos/PosCartPanel.vue`,
      `components/pos/ProductVariantPickerModal.vue`,
      `components/payment/{ChannelPicker,MethodTiles,PaymentPanel,PosPaymentModal,ProofCapture}.vue`
      → `t('pos.*')`.
- [X] T035 [P] [US1] `resources/js/views/{ArtistsView,CategoriesView,ProductsView,StockView}.vue`,
      `components/product/{ProductDetailModal,VariantBomModal}.vue`,
      `components/masterData/{MasterDataImportModal,MaterialVendorPricesModal}.vue`
      → `t('master_data.*')`.
- [X] T036 [P] [US1] `resources/js/views/{VendorsView,MaterialsView}.vue`
      → `t('vendors_materials.*')`.
- [ ] T037 [P] [US1] `resources/js/views/{CustomersView,EventsView,SessionView}.vue`,
      `components/forms/CustomerPickerModal.vue`,
      `components/preorder/PreorderStatusStepper.vue`
      → `t('events_sessions.*')`.
- [ ] T038 [P] [US1] `resources/js/views/PreordersView.vue`,
      `components/payment/RecordPaymentModal.vue` → `t('preorders.*')`.
- [ ] T039 [P] [US1] `resources/js/views/{SalesView,ReportsView}.vue`,
      `components/report/ArtistTransactionsModal.vue` → `t('reports.*')`.
- [ ] T040 [P] [US1] `resources/js/views/{SettingsView,UsersView,RolesView}.vue`,
      `components/settings/RoleMenuPicker.vue` →
      `t('settings.*')`/`t('users.*')`/`t('roles.*')`.
- [X] T041 [P] [US1] `resources/js/components/ui/{BaseButton,BaseDrawer,BaseInput,BaseModal,BaseSelect,BaseTextarea,ConfirmDialog,DataTable,EmptyState,StatusPill,TablePagination,ToastStack}.vue`
      — generic shared strings ("Simpan", "Batal", "Tidak ada data",
      pagination labels, confirm-dialog defaults) → `t('common.*')`. This
      is the highest-leverage file group — every other screen composes
      these, so getting `common.*` right first unblocks T032–T040
      visually even before each screen's OWN strings are converted.
- [X] T042 [US1] `resources/js/components/receipt/ReceiptModal.vue` — add
      a code comment (matching T031's backend comment) stating these
      labels stay hardcoded Indonesian on purpose; do NOT wrap them in
      `t()`.
- [X] T043 [US1] `resources/js/views/LoginView.vue` — verify (add a short
      comment recording the verification) that this file has NO `t()`/
      `useI18n()` usage and no `LanguageSwitcher` — confirms FR-001 by
      construction, not just by omission.

### Tests & verification for User Story 1

- [X] T044 [US1] `qa-tests/component/LanguageSwitcher.test.js` (new) —
      switching the control re-renders visible text in the mounted
      component tree immediately, calls `authStore.setLanguage()`
      (mocked API), and reflects the persisted value on next mount.
- [ ] T045 [US1] Extend an existing form test (e.g.
      `qa-tests/component/ProductImageUpload.test.js` or `UsersView.test.js`)
      with a case: fill part of a create form, flip the locale mid-edit,
      assert the field's value is unchanged (FR-011).
- [ ] T046 [US1] Real-browser pass per `quickstart.md` steps 1, 3, 4, 5,
      7, 8 (login screen unaffected; toggle works and applies instantly
      across at least 3 different screens; per-account isolation on a
      shared device; localized 422/409 messages in both locales; data
      preserved on mid-edit switch).

**Checkpoint**: User Story 1 is fully functional and independently
testable — the entire post-login application respects each user's
stored language preference, and the login screen/receipt exclusions are
verified, not just assumed.

---

## Phase 4: User Story 2 - Pengguna baru default berbahasa English (Priority: P2)

**Goal**: Any account without a previously-stored preference — brand new
or pre-existing before this feature shipped — sees English by default.

**Independent Test**: Create a new user via Kelola Pengguna without
touching any language setting; log in as that user; confirm English
throughout.

- [X] T047 [P] [US2] `tests/Feature/LanguagePreferenceTest.php` — add a
      case asserting `UserController::store()` creates a user with
      `language === 'en'` when the field isn't part of the create
      payload at all (proving the DB column default does the work,
      matching FR-006/FR-007 — no extra application code needed beyond
      T006's migration default).
- [X] T048 [P] [US2] `tests/Feature/MasterDataImportUserTest.php` —
      assert a user row created via the `users` sheet import also
      defaults to `language: 'en'` (the sheet does NOT need a `language`
      column at all — confirm `MasterDataSheets`/`MasterDataImportService`
      require no change here, and record that confirmation as a comment
      if no code change was needed).
- [ ] T049 [US2] Real-browser pass per `quickstart.md` step 2 — a freshly
      created user and at least one pre-existing seeded account (e.g.
      `kasir02`, which was never touched by this feature's migration
      backfill logic since none is needed) both show English on first
      login after this feature ships.

**Checkpoint**: User Stories 1 AND 2 both work independently — the
toggle mechanism (US1) and its default behavior (US2) are both proven.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, governance follow-up, and final regression —
mirrors the Phase 7 convention established in `001-user-store-settings`.

- [ ] T050 [P] Update `docs/openapi-pos-mvp.yaml` — add
      `PUT /auth/language`, add `language` to the `User`/`AuthenticatedUser`
      schema and the `/auth/login` + `/auth/me` response bodies.
- [ ] T051 [P] Add a dated post-MVP note to
      `docs/PRD-POS-Event-Multivendor.md` recording this new capability —
      it does not correspond to any existing F-number (the PRD never
      mentions multi-language support), so follow the same
      "genuinely new capability, not a resurrection of a cut scope item"
      framing used by the Vendor/Material/BOM note (§10.2/§7.13 area).
- [ ] T052 [P] Extend the `bruno/` collection with a request (or new
      numbered folder, e.g. `bruno/12-Language/`) exercising
      `PUT /auth/language`: success, `422` on an invalid value, and a
      Kasir-role account succeeding despite lacking `users` menu access —
      following this collection's "one real flow plus negative cases"
      convention.
- [ ] T053 Flag the Principle III constitution conflict explicitly to
      the developer/product owner (per plan.md's Complexity Tracking) —
      recommend running `/speckit-constitution` to amend Principle III
      (MINOR bump) before this feature merges to `main`. This task does
      NOT perform the amendment itself — that is a deliberate, separate
      governance action.
- [ ] T054 Full regression: `php artisan test` (zero failures — pay
      particular attention to any EXISTING test asserting a literal
      Indonesian error-message string via `assertJsonPath('message', '...')`,
      since T019–T030 change those strings to come from `lang/id/*.php`
      — the JSON shape is unchanged but any test asserting the literal
      string needs to still pass because `lang/id/*.php` holds the exact
      original string) and `npm test` (zero failures/regressions from
      the T032–T041 conversions).
- [ ] T055 Execute `quickstart.md` step 6 (receipt stays Bahasa Indonesia
      even when the cashier's UI is set to English) explicitly, then run
      through all 8 quickstart steps once more end-to-end as the final
      consolidated acceptance pass.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately.
- **Foundational (Phase 2)**: Depends on Setup (needs `i18n.js`/locale
  JSON skeletons and `lang/` directories to exist) — BLOCKS both user
  stories.
- **User Story 1 (Phase 3)**: Depends on Foundational. T031 depends on
  T025 (same file). T042/T043 have no code dependency but should land
  after their respective backend/frontend conversion tasks land, so the
  "why this is excluded" comment reads correctly against the surrounding
  already-converted code.
- **User Story 2 (Phase 4)**: Depends on Foundational only (T006's
  migration default) — genuinely independent of US1's translation work,
  can run in parallel with Phase 3.
- **Polish (Phase 5)**: Depends on both user stories being complete.

### Parallel Opportunities

- T002 and T003 (Setup) can run in parallel with T001.
- Nearly all of T019–T041 (backend and frontend string migration) are
  `[P]` — each touches a disjoint set of files and can be distributed
  across contributors or run as concurrent agents; only T031/T042/T043
  have soft ordering dependencies noted above.
- Phase 4 (US2) can be executed in full parallel with Phase 3 (US1) once
  Phase 2 (Foundational) is done — they touch different concerns (default
  value vs. translation coverage).
- T050–T052 (Polish docs) are `[P]` — independent files.

---

## Parallel Example: User Story 1 backend string migration

```bash
# Launch several independent domain-conversion tasks together:
Task: "Convert app/Policies/UserPolicy.php and RolePolicy.php messages to __() — T020"
Task: "Convert Artist/Category/Product/Stock controllers and requests — T022"
Task: "Convert Vendor/Material controllers and guard messages — T023"
Task: "Convert ReportController.php — T027"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational (CRITICAL — the toggle mechanism
   itself; without it, no translated string is reachable by a real
   user).
3. Complete Phase 3: User Story 1 (this IS the full-scope feature per
   FR-008 — there is no smaller "MVP slice" of translation coverage that
   satisfies the spec, since the spec's clarification explicitly rejected
   a phased-by-screen approach in favor of full coverage).
4. **STOP and VALIDATE**: run `quickstart.md` steps 1, 3, 4, 5, 7, 8.
5. Layer in User Story 2 (small, mostly test-only, can be done any time
   after Foundational).

### Incremental Delivery

1. Setup + Foundational → toggle mechanism provably correct, zero visible
   translation yet.
2. User Story 1 → the actual product-facing feature — deploy/demo only
   after this, since a partially-translated app (some screens converted,
   others not) is worse UX than the current all-Indonesian baseline, not
   an acceptable intermediate release state per FR-008's "seluruh
   aplikasi" requirement.
3. User Story 2 → default-behavior tests, can land alongside or slightly
   after US1 without blocking it.
4. Polish → documentation, Bruno collection, the Principle III governance
   flag, and final regression.

### Parallel Team Strategy

With multiple developers/agents:

1. One agent completes Setup + Foundational (Phase 1–2) — this is a hard
   serialization point, everything else depends on it.
2. Once Foundational lands, fan out T019–T041 across as many
   agents/contributors as available — each task is a disjoint file set,
   genuinely parallelizable (see workflow-authoring's `pipeline()`
   pattern if orchestrated via subagents).
3. One agent handles US2 (T047–T049) in parallel with the T019–T046 fan-out.
4. Converge for Phase 5 Polish once both stories are checkpointed.

---

## Notes

- [P] tasks = different files, no dependencies.
- [Story] label maps task to specific user story for traceability.
- The bulk of this task list (T019–T043, 25 of 55 tasks) is mechanical
  string-migration work with a repeatable pattern — this is a direct
  consequence of FR-008's full-scope requirement discovered to be large
  in research.md, not scope creep introduced during task generation.
- Commit after each domain-conversion task or logical group, not one
  giant commit — mirrors this codebase's existing commit granularity.
- Verify `php artisan test`/`npm test` stay green after EACH domain
  conversion, not only at the end — a broken `lang/en/*.php` key
  reference fails loudly as a missing-translation warning, easy to miss
  if 25 files are converted before the first test run.
