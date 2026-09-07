# Tasks: License & Invoice Management (expanded scope)

**Input**: Design documents from `/specs/019-billing-system/`

**Tests**: Included (Constitution II — normal feature work, automatable).

**Organization**: Grouped by user story (spec.md P1/P1/P2/P2). Continues
numbering from T021 — T001-T020 (below, Phase 0) are the original
small-scope pass, already implemented and passing; this expansion builds on
top of them, not from scratch.

---

## Phase 0: Original small-scope pass (already complete — history only)

- [x] T001-T020 — see `tasks.md`'s git history / the original pass's own
  section for the full list (migration/model/service/policy/controller for
  a minimal `Invoice` tied to `Company`, reusing `Package`/`companies` menu
  key). Kept here only as a numbering anchor — do not re-run.

---

## Phase 1: Foundational (Blocking Prerequisites for the expansion)

**Rename/expand risk (plan.md)**: every task in this phase that renames
`Package`→`License` or `package_id`→`license_id` must be followed by a
repo-wide sweep (`grep -rn 'Package\|package_id'` across `app/`,
`resources/js/`, `tests/`, `docs/openapi-pos-mvp.yaml`) before moving to
Phase 2 — a missed reference fails silently at runtime, not at compile time.

- [x] T021 Create migration `database/migrations/2026_10_19_000001_rename_packages_to_licenses_and_add_pricing.php` — `Schema::rename('packages','licenses')`, add `price` decimal(14,2) and `payment_type` enum(`one_time`,`subscription`) columns (data-model.md)
- [x] T022 Create migration `database/migrations/2026_10_19_000002_rename_company_package_id_to_license_id.php` — `Schema::table('companies', fn ($t) => $t->renameColumn('package_id','license_id'))`, re-adding the FK constraint under its new name if MySQL doesn't carry it automatically — depends on T021
- [x] T023 Rename `app/Models/Package.php` → `app/Models/License.php` (class `License`, add `price`/`payment_type` to `$fillable` and casts) — depends on T021
- [x] T024 [P] Update `app/Models/Company.php` — rename `package_id` fillable entry → `license_id`, rename `package(): BelongsTo` → `license(): BelongsTo` — depends on T022, T023
- [x] T025 [P] Rename `app/Http/Requests/StorePackageRequest.php`/`UpdatePackageRequest.php` → `StoreLicenseRequest.php`/`UpdateLicenseRequest.php`, add `price` (required numeric min:0) and `payment_type` (required in:one_time,subscription) validation rules
- [x] T026 [P] Rename `app/Http/Resources/PackageResource.php` → `LicenseResource.php`, add `price` (formatted string) and `payment_type` fields
- [x] T027 [P] Rename `app/Policies/PackagePolicy.php` → `LicenseCatalogPolicy.php`, gate all actions on `$user->canAccessMenu('licenses')` (research.md R2') instead of `companies`
- [x] T028 Rename `app/Http/Controllers/Api/PackageController.php` → `LicenseCatalogController.php` (class `LicenseCatalogController`, R0 — NOT `LicenseController`, which feature 018 already owns) — depends on T023, T025, T026, T027
- [x] T029 Add `licenses` and `invoices` keys to `App\Support\MenuKeys::ALL` (`app/Support/MenuKeys.php`)
- [x] T030 [P] Create migration `database/migrations/2026_10_19_000010_add_licenses_menu_key_to_default_roles.php`, mirroring `2026_10_16_000005_add_companies_menu_key_to_default_roles.php` exactly (Owner/Admin only) — depends on T029
- [x] T031 [P] Create migration `database/migrations/2026_10_19_000011_add_invoices_menu_key_to_default_roles.php`, same pattern, Owner/Admin only — depends on T029
- [x] T032 [P] Create `lang/id/licenses.php`/`lang/en/licenses.php` (CRUD messages, delete-guard message) — mirrors old `companies.package_*` keys, moved to their own namespace
- [x] T033 Update `routes/api.php` — replace `/packages` routes with `/licenses` routes bound to `LicenseCatalogController` — depends on T028
- [x] T034 Sweep the whole repo for remaining `Package`/`package_id`/`package(` references (`app/`, `resources/js/`, `tests/`, `docs/openapi-pos-mvp.yaml`) and update every one found — depends on T021-T033
- [x] T035 Run `php artisan migrate` + full `php artisan test`, confirm the rename introduced no regressions before continuing to Phase 2

**Checkpoint**: `License` fully replaces `Package` in the codebase, gated on its own menu key — ready for the License and Invoice user stories.

---

## Phase 2: User Story 1 - Manage Licenses with pricing and included features (Priority: P1)

**Independent Test**: `quickstart.md` steps 1-3.

- [x] T036 [P] [US1] Create seeder step (extend `database/seeders/DatabaseSeeder.php` or add a small dedicated seeder) inserting the "Pro" and "Master" `licenses` rows with default `price`/`payment_type`/`description` if not already present (`insertOrIgnore` keyed by `name`) — research.md R9'
- [x] T037 [US1] Run T036's seeder against the dev DB, confirm exactly 2 License rows exist with the new pricing fields populated
- [x] T038 [P] [US1] Create `resources/js/api/licenses.js` (list/create/update/delete, mirrors the old `packages.js` if it existed, or `companies.js`'s shape)
- [x] T039 [US1] Create `resources/js/views/LicensesView.vue` — new top-level screen: list with name/price/payment_type/description columns, create/edit form, delete (409-aware) — depends on T038
- [x] T040 [US1] Add a new top-level `licenses` entry to `NAV_DEFS` in `resources/js/components/layout/AppSidebar.vue` and the corresponding route in the router — depends on T039
- [x] T041 [US1] Rename `tests/Feature/PackageTest.php` → `tests/Feature/LicenseCatalogTest.php`, extend for `price`/`payment_type` validation and the `licenses` menu-key gate (replacing the old `companies`-gate assertions)
- [ ] T042 [US1] Manually verify in a real running browser: `quickstart.md` steps 1-3 — depends on T037, T040

---

## Phase 3: User Story 2 - Manage Invoices as a standalone, full-featured document (Priority: P1)

**Independent Test**: `quickstart.md` steps 4-6, 9-10.

- [x] T043 Create migration `database/migrations/2026_10_19_000003_expand_invoices_table.php` — add `invoice_number` (unique), `license_id` (FK), `subtotal`, `discount`, `grand_total`, `payment_information`; backfill `subtotal`/`grand_total` from the existing `amount` column, `discount = 0`, before dropping `amount` (data-model.md)
- [x] T044 [US2] Update `app/Models/Invoice.php` — new `$fillable`/casts for the expanded fields, add `license(): BelongsTo` — depends on T043, T023
- [x] T045 [US2] Add `generateNumber(): string` to `app/Services/InvoiceService.php` (public, `INV-{YYYYMM}-{seq}`, `withoutGlobalScope(DataModeScope::class)` for cross-mode uniqueness — research.md R3') — depends on T044
- [x] T046 [US2] Add `delete(Invoice $invoice)` to `InvoiceService` — throws `ValidationException` if `status === 'paid'` (FR-011) — depends on T044
- [x] T047 [US2] Add `update(Invoice $invoice, array $data)` to `InvoiceService` — recomputes `grand_total = subtotal - discount` server-side, throws `ValidationException` if `status === 'paid'` — depends on T044
- [x] T048 [P] [US2] Create `app/Http/Requests/StoreInvoiceRequest.php` (rewrite) — `company_id`, `license_id`, `subtotal` (numeric >0), `discount` (numeric >=0, <= subtotal), `due_date`, `payment_information` (nullable), `notes` (nullable)
- [x] T049 [P] [US2] Create `app/Http/Requests/UpdateInvoiceRequest.php` — same shape as Store
- [x] T050 [P] [US2] Update `app/Http/Resources/InvoiceResource.php` — `invoice_number`, nested `license` (id/name/payment_type), `subtotal`, `discount`, `grand_total`, `payment_information`, replacing `amount`
- [x] T051 [US2] Update `app/Policies/InvoicePolicy.php` — gate on `canAccessMenu('invoices')` instead of `companies` (research.md R2')
- [x] T052 [US2] Update `app/Http/Controllers/Api/InvoiceController.php` — `store()` uses `generateNumber()` + computes `grand_total`; add `show()`, `update()`, `destroy()` (mapping `InvoiceService`'s `ValidationException` to 409); add `summary()` (FR-012: unpaid/paid counts+totals, overall count) — depends on T045, T046, T047, T048, T049, T050, T051
- [x] T053 [US2] Register in `routes/api.php`: `GET /invoices/summary` (before the resource routes), `GET/PUT/DELETE /invoices/{invoice}` — depends on T052
- [x] T054 [P] [US2] Update `resources/js/api/invoices.js` — add `getInvoice`, `updateInvoice`, `deleteInvoice`, `getInvoiceSummary`
- [x] T055 [US2] Create `resources/js/views/InvoicesView.vue` — new top-level screen: list (clickable `invoice_number`), statistics panel (US3, wired here since the same screen renders both), create button — depends on T054
- [x] T056 [US2] Create `resources/js/components/invoices/InvoiceFormModal.vue` — full create/edit form: company, license (+ payment_type shown), subtotal, discount, computed grand total, due date, payment information (pre-filled from Settings→Payment, see Phase 5), notes — depends on T055
- [x] T057 [US2] Create `resources/js/components/invoices/InvoiceDetailModal.vue` — full detail view opened by clicking `invoice_number`; download-as-image/download-as-PDF buttons using the exact `html2canvas`/`jsPDF` dynamic-import pattern from `ReceiptModal.vue` (research.md R5') — depends on T055
- [x] T058 [US2] Remove `resources/js/components/companies/CompanyInvoicesModal.vue` and its wiring in `resources/js/views/CompaniesView.vue` (superseded by the standalone Invoices screen) — depends on T055
- [x] T059 [US2] Add a new top-level `invoices` entry to `NAV_DEFS` in `AppSidebar.vue` and the corresponding route — depends on T055
- [x] T060 [US2] Extend `tests/Feature/InvoiceTest.php` — new field shape on create, update (blocked when paid → 409), delete (blocked when paid → 409, allowed when unpaid), `invoice_number` uniqueness across data modes, `summary()` totals — depends on T052
- [ ] T061 [US2] Manually verify in a real running browser: `quickstart.md` steps 4-6, 9 — depends on T057, T059

**Checkpoint**: US1+US2 = the core deliverable — Licenses and Invoices both exist as full standalone screens.

---

## Phase 4: User Story 3 - See billing statistics at a glance (Priority: P2)

**Independent Test**: `quickstart.md` step 7. (Backend `summary()` endpoint already built in T052/T053 — this phase is the frontend wiring + verification only.)

- [x] T062 [US3] Wire `InvoicesView.vue`'s statistics panel to `GET /invoices/summary` (unpaid count/total, paid count/total, overall count) — depends on T055, T053
- [ ] T063 [US3] Manually verify statistics match a manual tally after create/edit/delete/status-change actions — `quickstart.md` step 7

---

## Phase 5: User Story 4 - Configure invoice payment information once, reuse everywhere (Priority: P2)

**Independent Test**: `quickstart.md` step 8.

- [x] T064 [P] [US4] Create migration `database/migrations/2026_10_19_000004_create_invoice_payment_settings_table.php` — single-row table (`bank_name`, `account_number`, `account_holder`, `instructions`), no soft delete (data-model.md R8')
- [x] T065 [P] [US4] Create `app/Models/InvoicePaymentSetting.php` — no `HasDataMode`, no `SoftDeletes` — depends on T064
- [x] T066 [US4] Create `app/Http/Controllers/Api/InvoicePaymentSettingController.php` — `show()` (returns the singleton, creating a blank row on first access via `firstOrCreate(['id' => 1])`), `update()` (gated on `canAccessMenu('settings')`, R7') — depends on T065
- [x] T067 [US4] Register `GET/PUT /settings/payment` in `routes/api.php` — depends on T066
- [ ] T068 [US4] Update `InvoiceController::store()`/`InvoiceFormModal.vue`'s default so a new invoice's `payment_information` defaults to `InvoicePaymentSetting`'s current `instructions`/bank details when the field is left blank (FR-015) — depends on T052, T056, T066
- [x] T069 [US4] Create `resources/js/views/SettingsPaymentView.vue` — form for bank_name/account_number/account_holder/instructions — depends on T067
- [x] T070 [US4] Add a `payment` child under the existing `settings-group` in `AppSidebar.vue`'s `NAV_DEFS` (sibling to `settings`/`users`/`roles`), route `/settings/payment` (research.md R7') — depends on T069
- [x] T071 [US4] Write `tests/Feature/InvoicePaymentSettingTest.php` — get/update, gated on `settings` menu key, singleton behavior (repeated updates never create a second row) — depends on T066
- [ ] T072 [US4] Manually verify: `quickstart.md` step 8 — depends on T068, T070

**Checkpoint**: All four user stories complete — full expanded scope delivered.

---

## Phase 6: Excel export/import (FR-010, supports US2)

- [x] T073 Create `app/Imports/InvoiceImport.php` — `WithHeadingRow`, headings per research.md R6' (`invoice_number`, `company_name`, `license_name`, `subtotal`, `discount`, `grand_total`, `due_date`, `status`, `payment_information`, `notes`)
- [x] T074 Create `app/Services/InvoiceExportImportService.php` — `export(array $filters): array` (mirrors `PreorderExportImportService::export()`), `template(): array`, `import(UploadedFile $file, bool $dryRun, User $importedBy): array` — all-or-nothing validation, blank `invoice_number` → create (reusing `InvoiceService::generateNumber()`), present `invoice_number` → update (blocked if already `paid`) — depends on T073, T045, T047
- [x] T075 Register in `routes/api.php` (static routes BEFORE the `{invoice}` resource routes, per the existing `/preorders/export` ordering convention): `GET /invoices/export`, `GET /invoices/import/template`, `POST /invoices/import` — depends on T074
- [x] T076 [P] Wire export/import buttons into `InvoicesView.vue` (mirrors however `PreordersView.vue` wires its own export/import UI) — depends on T075
- [x] T077 Extend `tests/Feature/InvoiceTest.php` — export produces expected rows, import creates new invoices, import updates by `invoice_number`, import rejects updating a `paid` invoice, import rejects a nonexistent Company/License reference (whole import fails, no partial apply) — depends on T074
- [ ] T078 Manually verify: `quickstart.md` step 10 — depends on T076

---

## Phase 7: Polish

- [x] T079 Update `docs/openapi-pos-mvp.yaml` with every new/changed route from `contracts/api.md` (License catalog rename, expanded Invoice CRUD + summary + export/import, Settings→Payment) — depends on all prior phases
- [x] T080 Run the full `php artisan test` suite, confirm no regressions anywhere (including `LicenseActivationTest` from feature 018, unrelated but checked given the naming proximity, R0) — depends on T079
- [x] T081 Manually verify role gating: `quickstart.md` step 11 (cashier/inventory cannot see or access `/licenses`/`/invoices`)
- [x] T082 Update `spec.md`'s Status line to reflect the expanded scope as delivered

---

## Dependencies

- Phase 1 (rename/foundational) blocks everything else — License must exist before Invoice can reference `license_id`.
- US1 (Licenses) and US2 (Invoices) are both P1 but US2 depends on US1's `licenses` table existing (Phase 1) — they are NOT independent of each other in this expansion, unlike a typical from-scratch feature, because Invoice's `license_id` FK requires License to already exist.
- US3 (statistics) depends on US2's `summary()` endpoint (built as part of Phase 3, wired in Phase 4).
- US4 (Settings→Payment) is independent of US1/US2/US3's core CRUD but its "default payment_information" behavior (T068) depends on US2's `InvoiceController`/`InvoiceFormModal.vue` already existing.
- Phase 6 (export/import) depends on US2's `InvoiceService::generateNumber()`/`update()` (Phase 3).
- Phase 7 (Polish) depends on all prior phases.

## Implementation Strategy

MVP for this expansion = Phase 1 (rename) + Phase 2 (US1: Licenses) + Phase 3 (US2: Invoices). Phases 4 (statistics), 5 (Settings→Payment), and 6 (export/import) can follow incrementally once the core rename + two standalone screens are solid.

---

# Second expansion (2026-09-06): Company edit/delete, Invoice detail enrichment

**Organization**: Continues numbering from T083. No new phases are
foundational/blocking for each other — US5 (Company) and the US2-extension
(Invoice detail) touch disjoint files and can proceed in parallel.

## Phase 8: User Story 5 - Edit and delete a Company record (Priority: P2)

**Independent Test**: `quickstart.md` steps 13-14.

- [x] T083 [P] [US5] Create `app/Http/Requests/UpdateCompanyRequest.php` — same `business_type_id`/`license_id`/`name`/`address`/`contact_name`/`contact_email`/`contact_phone` rules as `StoreCompanyRequest`, WITHOUT `owner_username`/`owner_password` (research.md R11)
- [x] T084 [US5] Add `update(Company $company, array $data)` and `delete(Company $company)` to `app/Services/CompanyOnboardingService.php` — `delete()` throws `ValidationException` (409) if `$company->invoices()->exists()` (research.md R10, NOT a license_id check)
- [x] T085 [US5] Add `update()`/`destroy()` abilities to `app/Policies/CompanyPolicy.php` (both gate on `canAccessMenu('companies')`, unchanged menu key) — depends on T083
- [x] T086 [US5] Add `update()`/`destroy()` actions to `app/Http/Controllers/Api/CompanyController.php`, mapping `CompanyOnboardingService`'s `ValidationException` to 409 — depends on T084, T085
- [x] T087 [US5] Register `PUT /companies/{company}` and `DELETE /companies/{company}` in `routes/api.php` — depends on T086
- [x] T088 [P] [US5] Add `company_delete_has_invoices` message key to `lang/id/companies.php`/`lang/en/companies.php`
- [x] T089 [P] [US5] Add `updateCompany(id, payload)`/`deleteCompany(id)` to `resources/js/api/companies.js`
- [x] T090 [US5] Add Edit/Delete actions per row in `resources/js/views/CompaniesView.vue` (Edit opens the existing onboarding-style form pre-filled minus owner_username/password fields; Delete uses a confirm dialog, 409-aware toast) — depends on T089
- [x] T091 [US5] Extend `tests/Feature/CompanyOnboardingTest.php` (or a new `tests/Feature/CompanyTest.php`) — update succeeds and persists; delete succeeds when zero Invoices; delete refused (409) when at least one Invoice references the Company; role gating unchanged (still `companies` menu key) — depends on T087
- [x] T092 [US5] Manually verify: `quickstart.md` steps 13-14 — depends on T090, T091

---

## Phase 9: User Story 2 (extension) - Business type + Settings→Payment data in Invoice detail/PDF/image (Priority: P1)

**Independent Test**: `quickstart.md` steps 15-16.

- [x] T093 [US2] Update `app/Http/Controllers/Api/InvoiceController.php` — eager-load `company.businessType` alongside the existing `company`/`license` loads in `index()`/`show()`/`store()`/`update()` (research.md R12 — read-live, NOT a snapshot)
- [x] T094 [US2] Update `app/Http/Resources/InvoiceResource.php` — nest `company.business_type` (`{id, name}`) inside the existing `company` object
- [x] T095 [US2] Extend `tests/Feature/InvoiceTest.php` — assert `company.business_type` is present and correct in the `InvoiceResource` response — depends on T093, T094
- [x] T096 [US2] Update `resources/js/components/invoices/InvoiceDetailModal.vue` — add a business-type row in the detail `<dl>` (reads `invoice.company.business_type.name`); ensure the existing `payment_information` block is clearly labeled/structured (no behavior change to the underlying snapshot, just presentation — research.md R12) — depends on T095
- [x] T097 [US2] Manually verify (real browser): business type and payment information both appear in the on-screen detail view AND in the downloaded image/PDF (confirm `html2canvas`'s existing whole-container capture includes the new business-type row automatically — no separate download wiring needed) — `quickstart.md` steps 15-16 — depends on T096

---

## Phase 10: User Story 2 (extension) - Invoice Edit/Delete UI (Priority: P1)

**Independent Test**: `quickstart.md` step 17. Zero backend work — `PUT`/`DELETE /invoices/{invoice}` and `InvoiceFormModal.vue`'s edit mode already exist (research.md R13).

- [x] T098 [US2] Add an Edit button to `resources/js/components/invoices/InvoiceDetailModal.vue`'s footer (visible only when `status !== 'paid'`) that emits an event `InvoicesView.vue` handles by opening the existing `InvoiceFormModal.vue` in edit mode (already supports `:invoice="..."` → `isEdit` — first expansion's T056)
- [x] T099 [US2] Add a Delete button to the same footer (visible only when `status !== 'paid'`), calling the existing `deleteInvoice()` API function with a confirm dialog (mirror `LicensesView.vue`'s 409-aware delete-confirm convention) — depends on T098
- [x] T100 [US2] Extend `qa-tests/component/InvoicesView.test.js` (or add assertions to the existing `InvoiceDetailModal`-adjacent tests) — Edit/Delete buttons render only when unpaid, Edit opens the form pre-filled, Delete calls the API and closes the modal on success — depends on T099
- [x] T101 [US2] Manually verify: `quickstart.md` step 17 (edit/delete work when unpaid, both refused when paid) — depends on T100

---

## Phase 11: Polish (second expansion)

- [x] T102 Update `docs/openapi-pos-mvp.yaml` — add `PUT`/`DELETE /companies/{company}`, update the `Invoice` schema's `company` nested-object shape — depends on Phases 8-10
- [x] T103 Run the full `php artisan test` and `npm test` suites, confirm no regressions — `quickstart.md` step 18 — depends on T102
- [x] T104 Update `spec.md`'s Status line to reflect this second expansion as delivered

## Dependencies (second expansion)

- Phase 8 (Company) and Phases 9-10 (Invoice detail) are independent of each other — disjoint files, can run in parallel.
- Phase 10 depends on nothing from Phase 9 (different parts of the same file, but non-overlapping sections — footer buttons vs. detail `<dl>` — sequence them if assigned to the same agent to avoid a file-edit race).
- Phase 11 (Polish) depends on Phases 8-10 all being complete.

## Implementation Strategy (second expansion)

All three phases (8, 9, 10) can proceed in parallel — none blocks another. Phase 11 (Polish) is the only phase requiring everything else first.
