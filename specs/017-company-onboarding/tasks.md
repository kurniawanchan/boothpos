# Tasks: Company Onboarding

**Input**: Design documents from `/specs/017-company-onboarding/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Included — Constitution Principle II ("Testing Standards") requires backend changes be accompanied by `tests/Feature/` tests run against real MySQL; this is a normal feature addition (unlike feature 016's deployment-infra work, which had no automated-test equivalent), so test tasks are in scope throughout.

**Organization**: Tasks are grouped by user story (spec.md P1/P1/P2). Business Type management has no dedicated user story in spec.md (only Company onboarding, activation, and package management were story-ized) — its CRUD is treated as Foundational infrastructure US1 depends on, per the tasks-template's "entity serving multiple/no specific story → Setup/Foundational" rule.

## Phase 1: Setup

- [x] T001 Create the 5 new migration file stubs (empty `up()`/`down()`) at the exact paths/timestamps in plan.md's Project Structure — `database/migrations/2026_10_16_000001_create_business_types_table.php` through `..._000005_add_companies_menu_key_to_default_roles.php` — to lock in migration ordering before parallel model/migration work begins
- [x] T002 [P] Create `lang/id/companies.php` and `lang/en/companies.php` with an empty `return [];` shape, ready for keys to be added by later tasks (existing per-feature lang-file convention, e.g. `vendors_materials.php`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Migrations, models, and Business Type CRUD every user story depends on.

**⚠️ CRITICAL**: No user story can be implemented until this phase is complete.

- [x] T003 Implement migration `database/migrations/2026_10_16_000001_create_business_types_table.php` per data-model.md (`name`, `code` unique, `is_active`, soft delete) — NOT `HasDataMode`-scoped (research.md R6)
- [x] T004 Implement migration `database/migrations/2026_10_16_000002_create_packages_table.php` per data-model.md (`name`, `description`, `license_tier` enum `pro`/`master`, `is_active`, soft delete) — NOT `HasDataMode`-scoped
- [x] T005 Implement migration `database/migrations/2026_10_16_000003_create_companies_table.php` per data-model.md (FKs to `business_types`/`packages`/`users`, `status` enum, `activation_code_hash`, `activation_code_expires_at`, `activated_at`, `data_mode` column, soft delete)
- [x] T006 Implement migration `database/migrations/2026_10_16_000004_create_company_activation_notifications_table.php` per data-model.md (mirrors `preorder_notifications` shape exactly — `trigger`, `recipient_email`, `status`, `error_message`, `sent_at`, no `updated_at`)
- [x] T007 Implement migration `database/migrations/2026_10_16_000005_add_companies_menu_key_to_default_roles.php` — append `'companies'` to the seeded `Owner`/`Admin` default roles' `menu_keys`, mirroring `2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.php` exactly (research.md R1)
- [x] T008 [P] Create `app/Models/BusinessType.php` (fillable: `name`, `code`, `is_active`; `code` uppercased via mutator like `Vendor::code()`; `companies()` HasMany for the delete-guard check)
- [x] T009 [P] Create `app/Models/Package.php` (fillable: `name`, `description`, `license_tier`, `is_active`; `companies()` HasMany for the delete-guard check)
- [x] T010 Create `app/Models/Company.php` (uses `HasDataMode`, `SoftDeletes`; `status`/`activated_at`/`activation_code_expires_at` casts; `businessType()`, `package()`, `owner()` [BelongsTo `User`, FK `owner_user_id`], `activationNotifications()` [HasMany] relations) — depends on T003-T006
- [x] T011 [P] Create `app/Models/CompanyActivationNotification.php` mirroring `app/Models/PreorderNotification.php`'s exact shape (fillable, `sent_at` cast, `company()` BelongsTo)
- [x] T012 [P] Create `app/Policies/BusinessTypePolicy.php` gating `create`/`update`/`delete` on `$user->canAccessMenu('companies')` (research.md R1), `viewAny`/`view` open — mirrors `VendorPolicy`
- [x] T013 [P] Create `app/Http/Requests/StoreBusinessTypeRequest.php` and `UpdateBusinessTypeRequest.php` (validates `name`, unique `code`, `is_active`) — mirrors `StoreVendorRequest`
- [x] T014 [P] Create `app/Http/Resources/BusinessTypeResource.php`
- [x] T015 Create `app/Http/Controllers/Api/BusinessTypeController.php` (`index`/`store`/`show`/`update`/`destroy` with a 409 delete guard when `companies()->exists()`) — mirrors `VendorController` exactly — depends on T008, T012-T014
- [x] T016 Register Business Type routes in `routes/api.php` under the existing `auth:sanctum` group
- [x] T017 Add `business_type_delete_has_companies` (and any other needed) keys to `lang/id/companies.php`/`lang/en/companies.php` (T002)
- [x] T018 Write `tests/Feature/BusinessTypeTest.php` — CRUD, 409 delete guard when referenced, 403 for cashier/inventory roles

**Checkpoint**: Migrations run clean, Business Type management works end-to-end — ready for Company/Package work.

---

## Phase 3: User Story 1 - Onboard a new company (Priority: P1) 🎯 MVP

**Goal**: An owner/admin can submit business type + package + company details + contact + owner credentials and get a pending company with an inactive owner user and a dispatched (or audit-logged-as-skipped) activation code.

**Independent Test**: `quickstart.md` Section 1.

### Tests for User Story 1

- [x] T019 [P] [US1] Write `tests/Feature/CompanyOnboardingTest.php` covering: successful onboarding creates company (`pending_activation`) + inactive owner user + a `company_activation_notifications` row; duplicate owner username rejected with no records created; unconfigured mail (`MAIL_MAILER=log`) still creates records with `status: skipped_not_configured` — write these to FAIL first (Constitution II, TDD-style for this new service)

### Implementation for User Story 1

- [x] T020 [US1] Create `app/Mail/CompanyActivationMail.php` mirroring `app/Mail/PreorderStatusMail.php`'s structure (accepts the company + plaintext code, renders a simple activation-code email)
- [x] T021 [US1] Create `app/Services/CompanyOnboardingService.php` with `onboard(array $data): Company` — resolves the owner User's `role_id` against the seeded system-default `Owner` role (research.md R7, never client-supplied), creates the `User` with `is_active: false`, creates the `Company` with `status: pending_activation`, generates a 6-digit code via `random_int(100000, 999999)`, stores `Hash::make()` of it plus a 24h `activation_code_expires_at`, and sends+logs via `Mail`/`CompanyActivationNotification` AFTER the DB transaction commits (mirrors `PreorderNotifier`'s commit-then-notify ordering, research.md R3) — never let a mail failure roll back the company/user creation
- [x] T022 [P] [US1] Create `app/Http/Requests/StoreCompanyRequest.php` (business_type_id/package_id must reference an *active* record — data-model.md's validation rules; owner_username unique among non-deleted users; owner_password min 8 — mirrors `StoreUserRequest`'s rules)
- [x] T023 [P] [US1] Create `app/Policies/CompanyPolicy.php` gating all actions on `$user->canAccessMenu('companies')`
- [x] T024 [P] [US1] Create `app/Http/Resources/CompanyResource.php` (includes business type/package/owner-username/status/activated_at, money/dates formatted per this codebase's existing conventions)
- [x] T025 [US1] Create `app/Http/Controllers/Api/CompanyController.php` with `index`/`store`/`show` (store delegates to `CompanyOnboardingService::onboard()`) — depends on T020-T024
- [x] T026 [US1] Register `GET/POST /companies`, `GET /companies/{id}` routes in `routes/api.php`
- [x] T027 [US1] Add onboarding-related keys (validation messages, etc.) to `lang/id/companies.php`/`lang/en/companies.php`
- [x] T028 [US1] Run T019's tests, confirm they pass; run full `php artisan test` to confirm no regressions
- [x] T029 [P] [US1] Create `resources/js/views/CompaniesView.vue` (list, per this codebase's `DataTable`/pagination conventions) and `resources/js/components/companies/CompanyOnboardingModal.vue` (the onboarding form); add the `companies` menu entry to the sidebar, hidden entirely for roles without `canAccessMenu('companies')` (Constitution III)
- [x] T030 [US1] Manually verify `quickstart.md` Section 1 in a real running browser + API (Constitution II — UI changes must be exercised for real, not just unit-tested)

**Checkpoint**: A company can be onboarded end-to-end (pending state) — MVP's first half complete.

---

## Phase 4: User Story 2 - Activate the company via emailed code (Priority: P1)

**Goal**: Submitting the correct, unexpired, unused code activates the company and its owner login; wrong/expired/reused codes are rejected with a distinguishable reason; resend invalidates the previous code.

**Independent Test**: `quickstart.md` Sections 2 and 3.

### Tests for User Story 2

- [x] T031 [P] [US2] Write `tests/Feature/CompanyActivationTest.php` covering: correct code activates company + owner user (owner can subsequently log in); wrong code rejected, state unchanged; expired code rejected; already-active company's activation rejected; resend invalidates the prior code; resend on an already-active company rejected (409) — write to FAIL first

### Implementation for User Story 2

- [x] T032 [US2] Add `activate(Company $company, string $code): Company` and `resendActivationCode(Company $company): Company` to `app/Services/CompanyOnboardingService.php` — `activate()` uses `Hash::check()`, checks `activation_code_expires_at`/`status` before flipping `company.status` to `active`, `activated_at` to now, and the owner `User.is_active` to `true`, all inside one DB transaction; `resendActivationCode()` regenerates code+expiry (overwriting the previous hash — research.md R2) then sends+logs a `trigger: resend` notification the same way `onboard()` does
- [x] T033 [P] [US2] Create `app/Http/Requests/ActivateCompanyRequest.php` (validates `code` is exactly 6 digits)
- [x] T034 [US2] Add `activate`/`resendActivation` actions to `app/Http/Controllers/Api/CompanyController.php`, mapping `CompanyOnboardingService`'s specific rejection reasons (wrong/expired/already-active) to distinguishable `422`/`409` responses per contracts/api.md
- [x] T035 [US2] Register `POST /companies/{id}/activate` (with `throttle:10,1`, research.md R5) and `POST /companies/{id}/resend-activation` routes in `routes/api.php`
- [x] T036 [US2] Add activation-related keys (`activation_code_invalid`, `activation_code_expired`, `activation_already_active`) to `lang/id/companies.php`/`lang/en/companies.php`
- [x] T037 [US2] Run T031's tests, confirm they pass; run full `php artisan test` to confirm no regressions
- [x] T038 [P] [US2] Create `resources/js/components/companies/CompanyActivationModal.vue` (code entry + resend action) wired into `CompaniesView.vue`
- [x] T039 [US2] Manually verify `quickstart.md` Sections 2 and 3 in a real running browser + API

**Checkpoint**: Onboarding + activation both work end-to-end — MVP complete.

---

## Phase 5: User Story 3 - Manage available packages (Priority: P2)

**Goal**: An owner/admin can create/edit/deactivate packages; a deactivated package stops being newly selectable but stays intact on existing companies; a referenced package can't be deleted outright.

**Independent Test**: `quickstart.md` Section 4.

### Tests for User Story 3

- [x] T040 [P] [US3] Write `tests/Feature/PackageTest.php` covering: CRUD; deactivated package excluded from `?is_active=1` list but still shown correctly on an existing company; 409 delete guard when referenced by a company; 403 for cashier/inventory — write to FAIL first

### Implementation for User Story 3

- [x] T041 [P] [US3] Create `app/Http/Requests/StorePackageRequest.php`/`UpdatePackageRequest.php` (name, description, `license_tier` in `pro`/`master`, `is_active`)
- [x] T042 [P] [US3] Create `app/Http/Resources/PackageResource.php`
- [x] T043 [US3] Create `app/Http/Controllers/Api/PackageController.php` (`index`/`store`/`show`/`update`/`destroy` with a 409 delete guard when `companies()->exists()`) — mirrors `VendorController`/`BusinessTypeController` — depends on T009, T041-T042
- [x] T044 [US3] Register Package routes in `routes/api.php`
- [x] T045 [US3] Add `package_delete_has_companies` key to `lang/id/companies.php`/`lang/en/companies.php`
- [x] T046 [US3] Run T040's tests, confirm they pass; run full `php artisan test` to confirm no regressions
- [x] T047 [P] [US3] Create `resources/js/views/PackagesView.vue` (list + create/edit/deactivate), added under the same `companies` menu key
- [x] T048 [US3] Manually verify `quickstart.md` Section 4 in a real running browser + API

**Checkpoint**: All three user stories independently verified.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T049 Update `docs/openapi-pos-mvp.yaml` with all new endpoints from `contracts/api.md`, in the same spirit as every prior feature (Constitution's Documentation & Change Discipline rule — MUST move in the same commit as the route changes)
- [x] T050 [P] Write a role-gating test sweep (can be folded into T018/T028/T040's existing 403 assertions, or a small dedicated test) confirming cashier/inventory get 403 on every new `/companies`, `/packages`, `/business-types` endpoint, and that the sidebar hides these entirely for those roles (`quickstart.md` Section 5)
- [x] T051 Run the full `php artisan test` suite once more after all phases, confirming no regressions across the whole app
- [x] T052 Full end-to-end run-through of all five `quickstart.md` sections in one sitting; update `spec.md`'s Status line to reflect completion

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (migrations/models/Business Type CRUD every story needs).
- **User Story 1 (Phase 3)**: Depends on Foundational. Delivers the MVP's first half (pending companies).
- **User Story 2 (Phase 4)**: Depends on Foundational AND User Story 1 (activation acts on a company US1 creates) — not independent of US1 despite both being P1, since activation has nothing to act on without onboarding existing first.
- **User Story 3 (Phase 5)**: Depends on Foundational only — independently testable in parallel with US1/US2 once Foundational is done, since package management doesn't require a company to exist first (only the reverse).
- **Polish (Phase 6)**: Depends on all three user stories being complete.

### Parallel Opportunities

- T008/T009/T011 (models) can run in parallel — different files, no interdependency.
- T012/T013/T014 (Business Type Policy/Requests/Resource) can run in parallel.
- Within US1: T022/T023/T024 (Request/Policy/Resource) can run in parallel once T010 (Company model) exists; T029 (frontend) can proceed in parallel with T025-T028 (backend) once T024's Resource shape is settled.
- User Story 3 (Phase 5) can be staffed entirely in parallel with User Stories 1-2 (Phases 3-4) once Phase 2 (Foundational) is done, since it has no dependency on either.

---

## Implementation Strategy

### MVP First (User Stories 1 + 2 only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational).
2. Complete Phase 3 (US1) — companies can be onboarded (pending state).
3. Complete Phase 4 (US2) — companies can be activated. **This is the MVP**: onboarding without activation delivers no real login access, so US1+US2 together are the smallest useful increment, not US1 alone.
4. **STOP and VALIDATE** via `quickstart.md` Sections 1-3.

### Incremental Delivery

1. Setup + Foundational → Business Type management ready, migrations run clean.
2. US1 + US2 together → MVP: a company can be onboarded and activated.
3. US3 → package lifecycle management, independently addable at any point after Foundational.
4. Polish → OpenAPI spec updated, full regression pass, full quickstart sign-off.
