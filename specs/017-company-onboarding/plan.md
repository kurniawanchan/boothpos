# Implementation Plan: Company Onboarding

**Branch**: `017-company-onboarding` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/017-company-onboarding/spec.md`

## Summary

Adds Company, Package, and Business Type as new administratively-managed entities inside the existing single BoothPOS installation — a sales/onboarding CRM tracker, not a multi-tenant runtime change. An owner/admin onboards a company (business type + package + details + contact + initial owner credentials), the system creates an inactive owner `User` row and emails a single-use, hashed, time-limited 6-digit activation code to the contact; submitting the correct code flips the company to active and the owner user to usable. Package/Business Type follow the existing simple master-data CRUD pattern (`VendorController`-style, no dedicated Service); the Company onboarding/activation flow gets a dedicated `CompanyOnboardingService` because it has genuine business logic (code generation/hashing, email dispatch with audit logging, state transitions, `User` creation). No change to `LicenseGate`/`multi_artist_enabled` — a package's licensing tier is recorded as descriptive data on the Company only, never auto-applied to this single install's own Setting (see research.md R4 for why applying it live would be an actual correctness bug, not just out of scope).

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13), Vue 3 — unchanged, no new stack.

**Primary Dependencies**: Laravel's `Hash` facade (activation-code hashing, same mechanism as password hashing — no new dependency), `Mail` facade (already used by `PreorderNotifier`/`PreorderStatusMail`, same synchronous-send pattern).

**Storage**: MySQL 8, three new tables (`companies`, `packages`, `business_types`) plus one audit-log table (`company_activation_notifications`), all via the existing migration convention.

**Testing**: `tests/Feature/` (`php artisan test` against real MySQL, per Constitution II) covering onboarding, activation (success/wrong-code/expired/already-used), resend invalidation, package deactivate-vs-delete-guard, and role gating (cashier/inventory forbidden).

**Target Platform**: Same single-machine install this app already runs on — no new deployment shape (unrelated to feature 016's Docker work).

**Project Type**: Web application (Laravel API + Vue SPA), extending the existing single-project structure — no new top-level module.

**Performance Goals**: N/A beyond this app's existing conventions (Constitution V) — company/package lists are low-volume, standard paginated list endpoints suffice.

**Constraints**: Activation code brute-force resistance (6 digits = 1,000,000 combinations) requires rate-limiting the activation endpoint, mirroring the existing `throttle:5,1` on `/auth/login` (FR-008's "reject with a specific reason" still applies per-attempt; throttling caps attempt *rate*, not correctness).

**Scale/Scope**: Single installation, low-volume administrative data — not a high-throughput surface.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principle I (Code Quality)** — PASS. `CompanyOnboardingService` holds the genuine business logic (state machine, code hashing, email + audit). `Package`/`BusinessType` CRUD stays controller-level, matching the existing `VendorController` precedent (no service needed for plain CRUD with a delete guard) — not adding a service class there is a deliberate consistency choice, not a shortcut.
- **Principle II (Testing)** — PASS. All new endpoints get `tests/Feature/` coverage before being declared done, run against real MySQL.
- **Principle III (UX Consistency)** — PASS. New UI copy/comments in Indonesian; standard `422`/`409`/`403` convention reused; Company/Package menu items hidden entirely (not disabled) for roles without the new menu key.
- **Principle IV (Security)** — PASS, with one flagged risk this plan explicitly mitigates: a 6-digit code is a real brute-force target if unthrottled — the activation endpoint gets rate-limiting (research.md R5). Activation codes are stored hashed (`Hash::make`), never in plaintext, mirroring password storage. Client-supplied `role_id`/`is_active` are never trusted for the owner-user creation — the service, not the request payload, decides the initial `is_active = false` state.
- **Stack & Environment Constraints** — PASS. No new environment requirement; MySQL 8 unchanged.
- **Documentation & Change Discipline** — `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit as the new routes (tracked as a Polish-phase task, not skipped).

No violations requiring the Complexity Tracking table.

## Project Structure

### Documentation (this feature)

```text
specs/017-company-onboarding/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api.md            # Phase 1 output — new endpoints
└── tasks.md              # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
database/migrations/
├── 2026_10_16_000001_create_business_types_table.php
├── 2026_10_16_000002_create_packages_table.php
├── 2026_10_16_000003_create_companies_table.php
├── 2026_10_16_000004_create_company_activation_notifications_table.php
└── 2026_10_16_000005_add_companies_menu_key_to_default_roles.php

app/Models/
├── BusinessType.php
├── Package.php
├── Company.php
└── CompanyActivationNotification.php

app/Services/
└── CompanyOnboardingService.php   # onboard(), resendActivationCode(), activate()

app/Mail/
└── CompanyActivationMail.php       # mirrors PreorderStatusMail's pattern

app/Http/Controllers/Api/
├── BusinessTypeController.php      # plain CRUD, VendorController-style
├── PackageController.php           # plain CRUD + delete guard, VendorController-style
└── CompanyController.php           # index/store/show + activate/resend-activation actions

app/Http/Requests/
├── StoreBusinessTypeRequest.php / UpdateBusinessTypeRequest.php
├── StorePackageRequest.php / UpdatePackageRequest.php
├── StoreCompanyRequest.php
└── ActivateCompanyRequest.php

app/Http/Resources/
├── BusinessTypeResource.php
├── PackageResource.php
└── CompanyResource.php

app/Policies/
├── BusinessTypePolicy.php
├── PackagePolicy.php
└── CompanyPolicy.php

lang/id/companies.php, lang/en/companies.php   # new locale files, existing per-feature-file convention

resources/js/views/
├── CompaniesView.vue
├── PackagesView.vue
└── BusinessTypesView.vue           # or folded into a Settings sub-tab if the list stays short

resources/js/components/companies/
├── CompanyOnboardingModal.vue
└── CompanyActivationModal.vue

tests/Feature/
├── CompanyOnboardingTest.php
├── PackageTest.php
└── BusinessTypeTest.php

docs/openapi-pos-mvp.yaml           # MODIFIED — new endpoints (Polish phase)
```

**Structure Decision**: Follows this repo's existing per-entity pattern exactly (Model + Policy + FormRequests + Resource + Controller under the existing single Laravel app, Vue views under `resources/js/views/`) — no new top-level module or separate service boundary, consistent with "internal tracker inside the existing installation," not a new subsystem.

## Complexity Tracking

*No Constitution Check violations — table intentionally omitted.*

## Implementation Notes (post-execution, 2026-09-05)

All 52 tasks in `tasks.md` completed; all three user stories (plus the Business Type foundational CRUD) verified with `php artisan test` (445/445 passing, 18 new tests, zero regressions) and a real browser walkthrough (Playwright) of the full flow: onboard a company → verify the 422 "invalid code" path → activate with the correct code → confirm the owner account can log in — exercised through the actual UI, not just the API.

Two real, previously-undetected bugs were found and fixed only because of that real browser execution (Constitution II):

1. **Two policies (`BusinessTypePolicy`, `PackagePolicy`) were initially copied from `VendorPolicy`'s `viewAny`/`view: return true` pattern**, which would have let any authenticated role (including cashier) list packages/business types. Caught by a test assertion, not the browser — FR-012 explicitly requires "package management" as a whole (not just mutations) to be owner/admin-only, matching `CompanyPolicy`'s already-correct gating. Fixed by gating `viewAny`/`view` on `canAccessMenu('companies')` too.
2. **A missing `nav.companies_subtitle` i18n key** rendered as a raw, untranslated string in the page header — found by actually loading the page in a browser and reading the console warnings, not by code review. While fixing it, also found that the `business-types` route's hyphenated name would have hit the same missing-key problem via `AppShell`'s default `nav.${route.name}_subtitle` fallback (since `t()` keys can't contain the resulting `nav.business-types` path cleanly against an underscore-keyed locale file) — mirrored the existing `purchase-orders` route's fix (explicit `titleKey`/`subtitleKey` in the route's `meta`) rather than relying on the fallback.

All ad hoc verification data created during the browser walkthrough (one company, one package, one business type, and their notification rows) was deleted from the dev database afterward — none of it is seed data and none of it should persist.
