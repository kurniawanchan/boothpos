---

description: "Task list for Customer Data Import & Export"

---

# Tasks: Customer Data Import & Export

**Input**: Design documents from `/specs/020-customer-data-import-export/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/customer-import-export.md, quickstart.md (all present)

**Tests**: Included as mandatory, not optional — Constitution II requires every backend change in this repo to ship with `tests/Feature/` tests run against real MySQL. All tests below run via `docker compose exec app php artisan test --filter=CustomerImportExportTest` (per quickstart.md).

**Organization**: Tasks are grouped by user story (US1 = Export P1, US2 = Bulk Import P2, US3 = Import Preview P3) per spec.md.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to
- File paths are exact and relative to the repo root

## Path Conventions

Existing single-repo web app (Laravel API + Vue SPA) — no new project structure. Paths below match plan.md's Project Structure section exactly.

---

## Phase 1: Setup

**Purpose**: Scaffolding shared files so Phase 3+ story tasks don't collide on the same not-yet-existing file.

- [X] T001 [P] Create `app/Imports/CustomerImport.php` — `ToArray` + `WithHeadingRow`, empty `array()` method, mirrors `app/Imports/PreorderImport.php` exactly.
- [X] T002 [P] Create `app/Services/CustomerExportImportService.php` class shell with method signatures only: `export(): array`, `template(): array`, `import(\Illuminate\Http\UploadedFile $file, bool $dryRun, \App\Models\User $importedBy): array`.
- [X] T003 Register three static routes in `routes/api.php`, immediately above `Route::apiResource('customers', ...)` (line ~87): `Route::get('/customers/export', [CustomerController::class, 'export'])`, `Route::get('/customers/import/template', [CustomerController::class, 'importTemplate'])`, `Route::post('/customers/import', [CustomerController::class, 'import'])` — wire to new stub actions on `CustomerController` that return a placeholder `501` for now.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Logic shared by all three user stories — building it once here means US2 and US3 never duplicate validation/matching logic (Constitution I).

**⚠️ CRITICAL**: No user story task below may be started until this phase is complete.

- [X] T004 Add the `canManageMasterData()` inline authorization gate (`abort_unless($request->user()->canManageMasterData(), 403, ...)`, mirroring `MasterDataImportController`'s style) to all three stub actions on `app/Http/Controllers/Api/CustomerController.php` added in T003.
- [X] T005 Implement a private row-validation method in `app/Services/CustomerExportImportService.php` (e.g. `validateRows(array $rows): array`) applying: `name` required, `email` optional-but-valid-format-if-present, `phone`/`address`/`social_handle`/`notes` optional strings — mirroring the same rules as `StoreCustomerRequest`/`UpdateCustomerRequest` (per data-model.md's field table). Returns a validated-rows-plus-row-errors structure ready for T015 to consume.
- [X] T006 Implement a private email-pre-fetch method in `app/Services/CustomerExportImportService.php` (e.g. `fetchExistingByEmail(array $normalizedEmails): \Illuminate\Support\Collection`) — one batched `Customer::whereIn('email', $normalizedEmails)->get()->keyBy(fn ($c) => strtolower($c->email))` query, scoped to the active data mode via the existing `HasDataMode` global scope (no `withoutGlobalScope` — per research.md's decision). Emails normalized via `strtolower(trim(...))` before both storing the keys and looking values up.

**Checkpoint**: Foundation ready — US1, US2, US3 implementation can now begin.

---

## Phase 3: User Story 1 - Export the customer list to a spreadsheet (Priority: P1) 🎯 MVP

**Goal**: An owner/admin/inventory user can download every customer in the active DEMO/LIVE mode as an `.xlsx` file with all six contact fields.

**Independent Test**: Log in as `owner`, open Customers, click Export, confirm the downloaded file has the right header row and one row per existing customer in the currently active mode — no import functionality needs to exist yet for this to be fully verifiable.

### Implementation for User Story 1

- [X] T007 [P] [US1] Implement `CustomerExportImportService::export(): array` in `app/Services/CustomerExportImportService.php` — query `Customer::all()` (respecting the active-mode global scope automatically), map each row to `['name' => ..., 'phone' => ..., 'address' => ..., 'email' => ..., 'social_handle' => ..., 'notes' => ...]` per data-model.md's Export Row Shape.
- [X] T008 [US1] Replace the `export()` stub in `app/Http/Controllers/Api/CustomerController.php` with the real action: call `CustomerExportImportService::export()`, `return Excel::download(new \App\Exports\GenericArrayExport($rows), 'customers.xlsx')` (depends on T007).
- [X] T009 [P] [US1] Add `exportCustomers()` to `resources/js/api/customers.js` — `client.get('/customers/export', { responseType: 'blob' }).then((r) => r.data)`, mirroring `resources/js/api/preorders.js`'s `exportPreorders()`.
- [X] T010 [US1] Add an "Export" button to `resources/js/views/CustomersView.vue`, rendered only when the logged-in user's role can manage master data (reuse whatever existing frontend role-check the Products/master-data screens already use to gate their own Export/Import buttons) — hidden entirely for cashier, per Constitution III (no visible-but-403 controls).
- [X] T011 [P] [US1] Add `customers.export_button` (and any needed toast copy) to `resources/js/locales/en.json` and `resources/js/locales/id.json`.
- [X] T012 [US1] Feature tests in `tests/Feature/CustomerImportExportTest.php`: `export requires canManageMasterData` (403 for cashier), `export returns one row per customer with all six columns`, `export only includes the currently active data mode`, `export on an empty customer list returns headers only`.
- [X] T013 [US1] Manually verify per quickstart.md steps 1–4 in a real browser (`http://localhost:8000/customers`, Docker dev instance): button visibility per role, downloaded file content.

**Checkpoint**: Export is fully functional and shippable as a standalone MVP increment.

---

## Phase 4: User Story 2 - Import new customers in bulk from a spreadsheet (Priority: P2)

**Goal**: An owner/admin/inventory user can upload an `.xlsx` file and have every valid row become a new customer, with an email match updating an existing customer instead of duplicating it.

**Independent Test**: Prepare a spreadsheet with several rows (some new, one matching an existing customer's email), import it for real, confirm the right customers are created/updated and that any invalid row blocks the entire import with a clear error — independent of US1 having been used first.

### Implementation for User Story 2

- [X] T014 [US2] Implement `CustomerExportImportService::template(): array` in `app/Services/CustomerExportImportService.php` — return the heading-only row (`name, phone, address, email, social_handle, notes`), same column order as `export()`.
- [X] T015 [US2] Implement `CustomerExportImportService::import()` full logic in `app/Services/CustomerExportImportService.php`: `Excel::toArray(new \App\Imports\CustomerImport, $file)[0]`, run T005's row validation, run T006's email pre-fetch, branch each row into create vs. update (update = blank cell leaves existing field unchanged, per FR-013 / data-model.md), wrap all writes in one `DB::transaction()`, write an `ActivityLogger` entry inside that same transaction (research.md decision), return `{applied, dry_run, created_count, updated_count, row_errors}` per data-model.md's Import Result shape. If any `row_errors` exist, no writes happen at all (all-or-nothing, matching `MasterDataImportService`'s convention). (Depends on T005, T006, T014.)
- [X] T016 [US2] Replace the `importTemplate()` stub in `app/Http/Controllers/Api/CustomerController.php`: call `CustomerExportImportService::template()`, `return Excel::download(new \App\Exports\GenericArrayExport($rows), 'template-customers.xlsx')` (depends on T014).
- [X] T017 [US2] Replace the `import()` stub in `app/Http/Controllers/Api/CustomerController.php` for the real-apply path: validate the request (`Rule::file()->max(10240)->rules(['mimes:xlsx'])`, matching `ImportMasterDataRequest`'s convention), call the service with `dry_run` read from the request (default `false`), return `201` with counts on success or `409` with `row_errors` on validation failure, per `contracts/customer-import-export.md` (depends on T015).
- [X] T018 [P] [US2] Add `downloadCustomerImportTemplate()` and `importCustomers(file, dryRun)` to `resources/js/api/customers.js`, mirroring `resources/js/api/preorders.js`'s equivalents.
- [X] T019 [US2] Add an import modal to `resources/js/views/CustomersView.vue` (reuse `BaseModal`, mirroring `PreordersView.vue`'s inline import UI): file picker, submit triggers a real import (no preview step yet — that's US3), display success counts or the row-error list via the existing toast/error conventions.
- [X] T020 [P] [US2] Add `customers.import_*` locale keys (button label, modal title, file picker label, row-error list heading, success message) to `resources/js/locales/en.json` and `resources/js/locales/id.json`.
- [X] T021 [US2] Feature tests in `tests/Feature/CustomerImportExportTest.php`: `import creates new customers`, `import updates an existing customer matched by email case-insensitively`, `a blank cell on an update row leaves the existing value unchanged`, `a row without an email always creates a new customer even if the name matches an existing one`, `a missing name is a row-level error and nothing is saved`, `a non-spreadsheet file is rejected`, `import only matches customers within the currently active data mode`, `import requires canManageMasterData`, `a successful import writes an activity log entry inside the same transaction`.
- [X] T022 [US2] Manually verify per quickstart.md steps 6, 8, 9, 11 in a real browser: import with new + updating rows, confirm counts and that untouched fields on an updated customer survive; re-import the same file and confirm no duplicate is created for the row with an email.

**Checkpoint**: Export (US1) and real bulk import (US2) both fully functional; US1 unaffected by US2's changes.

---

## Phase 5: User Story 3 - Preview an import before committing it (Priority: P3)

**Goal**: A user can see exactly what an import would do — counts and row errors — before any customer record is actually created or updated.

**Independent Test**: Run the import endpoint with `dry_run=1` against a file with both valid and invalid rows (directly via the API, or through the UI once built) and confirm the reported counts/errors match reality while zero customers are written; then confirm committing the same file for real matches the preview exactly.

### Implementation for User Story 3

- [X] T023 [US3] Confirm/extend `CustomerController::import()` in `app/Http/Controllers/Api/CustomerController.php` correctly threads the request's `dry_run` boolean through to `CustomerExportImportService::import()`'s `$dryRun` parameter (built in T015/T017) and returns `200` with counts and zero writes when `dry_run=true`, per `contracts/customer-import-export.md`.
- [X] T024 [US3] Add a "Preview" step to the import modal in `resources/js/views/CustomersView.vue`: submitting the file first calls import with `dry_run=1`, shows created/updated counts and any row errors, and only enables a "Confirm import" action once a clean (zero-row-error) preview has been shown (depends on T019).
- [X] T025 [P] [US3] Add `customers.import_preview_*` locale keys (preview heading, counts summary, confirm button label) to `resources/js/locales/en.json` and `resources/js/locales/id.json`.
- [X] T026 [US3] Feature tests in `tests/Feature/CustomerImportExportTest.php`: `dry_run reports counts and errors without creating or updating any customer`, `confirming the same file after a clean dry run produces exactly the counts the preview reported`.
- [X] T027 [US3] Manually verify per quickstart.md step 7 in a real browser: preview a file, confirm nothing is created yet, then confirm the real import afterward matches the preview exactly.

**Checkpoint**: All three user stories independently functional and verified.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Repo-wide discipline that applies across all three stories.

- [X] T028 [P] Update `docs/openapi-pos-mvp.yaml` with the three new endpoints (`GET /customers/export`, `GET /customers/import/template`, `POST /customers/import`), matching `contracts/customer-import-export.md` exactly — same commit as the route/response changes above (Constitution's Documentation & Change Discipline).
- [X] T029 [P] Run the full suites and confirm zero regressions: `docker compose exec app php artisan test` (backend) and `npm test` (frontend, `qa-tests/`).
- [X] T030 Run `quickstart.md` end-to-end once every task above is complete, including the role-visibility check (step 3) and the activity-log check (step 12).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup (T001–T003) — BLOCKS all three user stories.
- **User Story 1 (Phase 3)**: Depends only on Foundational. Fully independent of US2/US3.
- **User Story 2 (Phase 4)**: Depends only on Foundational (specifically T005/T006). Does not depend on US1's frontend work, though it does add to the same `CustomersView.vue`/`customers.js`/locale files US1 touched (sequential edits to those files, not a hard logical dependency).
- **User Story 3 (Phase 5)**: Depends on US2's T015/T017/T019 existing (the `dry_run` plumbing and the import modal it extends) — this is the one story that is not fully independent of another, matching spec.md's own framing ("a safety feature layered on top of Story 2's core import").
- **Polish (Phase 6)**: Depends on all three user stories being complete.

### Parallel Opportunities

- T001 and T002 (Phase 1) — different files.
- T005 and T006 (Phase 2) — different methods, but same file (`CustomerExportImportService.php`); safe to parallelize only if done as separate, non-overlapping method additions.
- T007, T009, T011 (US1) — different files, can run in parallel once Foundational is done.
- T018, T020 (US2) — different files, parallel with each other; not parallel with T015/T017 (same-file sequential dependency within the story).
- T025 (US3) — parallel with T023/T024 (different files).
- T028, T029 (Polish) — different concerns, parallel.

---

## Parallel Example: User Story 1

```bash
# Once Phase 2 (Foundational) is complete, launch these together:
Task: "Implement CustomerExportImportService::export() in app/Services/CustomerExportImportService.php"
Task: "Add exportCustomers() to resources/js/api/customers.js"
Task: "Add customers.export_button locale keys to en.json and id.json"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) and Phase 2 (Foundational).
2. Complete Phase 3 (US1 — Export).
3. **STOP and VALIDATE**: run T012's tests and T013's manual browser check.
4. Export alone is already a shippable, demoable increment.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US1 (Export) → test independently → demoable MVP.
3. US2 (Bulk Import) → test independently → demoable.
4. US3 (Preview) → test independently (layered on US2) → demoable.
5. Polish (docs, full suite, quickstart) → done.

---

## Notes

- [P] tasks touch different files with no unfinished dependency between them.
- Every task lists an exact file path — no task should require guessing where the code goes.
- Constitution II mandates real-MySQL Feature tests and a real-browser check for any user-facing screen change — both are included per story above, not deferred to Polish.
- Avoid: adding a fourth story, reusing `MasterDataImportService`'s multi-sheet machinery, or a per-row (non-batched) email lookup — all explicitly rejected in research.md.
