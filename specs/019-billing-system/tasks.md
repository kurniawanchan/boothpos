# Tasks: Billing Records for Company Onboarding

**Input**: Design documents from `/specs/019-billing-system/`

**Tests**: Included (Constitution II — normal feature work, automatable).

**Organization**: Grouped by user story (spec.md P1/P1/P2).

## Phase 1: Foundational (Blocking Prerequisites)

- [ ] T001 Create migration `database/migrations/2026_10_18_000001_create_invoices_table.php` per data-model.md (`company_id` FK restrictOnDelete, `amount`, `due_date`, `status` enum default `unpaid`, `paid_at`, `notes`, `data_mode`, soft delete)
- [ ] T002 [P] Create `app/Models/Invoice.php` (`HasDataMode`, `SoftDeletes`, `company()` BelongsTo, casts for `due_date`/`paid_at`)
- [ ] T003 [P] Create `app/Policies/InvoicePolicy.php` gating all actions on `$user->canAccessMenu('companies')` (research.md R2)
- [ ] T004 Create `app/Services/InvoiceService.php` with `markPaid(Invoice $invoice)` and `cancel(Invoice $invoice)` — both throw `ValidationException` if `$invoice->status !== 'unpaid'` (research.md R1) — depends on T002
- [ ] T005 [P] Add `invoice_created`/`invoice_marked_paid`/`invoice_cancelled`/`invoice_invalid_transition` keys to `lang/id/companies.php`/`lang/en/companies.php`

**Checkpoint**: Model + guarded service ready — user stories can build on this.

---

## Phase 2: User Story 1 - Record an invoice for a company's package (Priority: P1) 🎯 MVP

**Independent Test**: `quickstart.md` step 1.

- [ ] T006 [P] [US1] Create `app/Http/Requests/StoreInvoiceRequest.php` (`amount` required numeric >0, `due_date` required date, `notes` nullable)
- [ ] T007 [P] [US1] Create `app/Http/Resources/InvoiceResource.php`
- [ ] T008 [US1] Create `app/Http/Controllers/Api/InvoiceController.php` with `index()` (company-scoped + overall, `?status=` filter, FR-006) and `store()` — depends on T003, T006-T007
- [ ] T009 [US1] Register `GET/POST /companies/{company}/invoices` and `GET /invoices` in `routes/api.php`
- [ ] T010 [US1] Write `tests/Feature/InvoiceTest.php` — create succeeds (`201`, status `unpaid`); cashier/inventory get `403`
- [ ] T011 [US1] Run T010's tests, confirm pass; run full `php artisan test`

---

## Phase 3: User Story 2 - Mark an invoice paid (Priority: P1)

**Independent Test**: `quickstart.md` steps 2-4.

- [ ] T012 [US2] Add `markPaid()`/`cancel()` actions to `InvoiceController`, mapping `InvoiceService`'s `ValidationException` to `409` (research.md R1) — depends on T004
- [ ] T013 [US2] Register `POST /invoices/{invoice}/mark-paid` and `POST /invoices/{invoice}/cancel` in `routes/api.php`
- [ ] T014 [US2] Extend `tests/Feature/InvoiceTest.php` — mark-paid succeeds (`paid_at` set); re-marking an already-paid/cancelled invoice → `409`; cancel succeeds on a fresh unpaid invoice
- [ ] T015 [US2] Run tests, confirm pass; run full `php artisan test`

**Checkpoint**: US1+US2 together = MVP — invoices can be recorded and resolved.

---

## Phase 4: User Story 3 - View a company's billing history (Priority: P2)

**Independent Test**: `quickstart.md` step 5.

- [ ] T016 [P] [US3] Create `resources/js/api/invoices.js` (`listCompanyInvoices`, `createInvoice`, `markPaid`, `cancelInvoice`)
- [ ] T017 [US3] Create `resources/js/components/companies/CompanyInvoicesPanel.vue` (list + create form + mark-paid/cancel buttons), embedded in the company detail view
- [ ] T018 [US3] Manually verify in a real running browser + API (Constitution II)

---

## Phase 5: Polish

- [ ] T019 Update `docs/openapi-pos-mvp.yaml` with the 5 new endpoints
- [ ] T020 Run full `php artisan test` suite, confirm no regressions; update `spec.md`'s Status line

---

## Dependencies

- Foundational blocks all stories.
- US2 depends on Foundational only (not on US1's controller code, but shares `InvoiceController` — sequence T008 before T012 to avoid file conflicts).
- US3 (frontend) depends on US1+US2's endpoints existing.
- Polish depends on all stories.

## Implementation Strategy

MVP = Foundational + US1 + US2. US3 (frontend) and Polish follow once the API is solid.
