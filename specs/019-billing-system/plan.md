# Implementation Plan: License & Invoice Management

**Branch**: `019-billing-system` | **Date**: 2026-09-06 (re-planned — second scope expansion) | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/019-billing-system/spec.md` (expanded 2026-09-06, twice)

**Supersedes**: the first expansion's plan (License rename, standalone Invoice, statistics, Settings→Payment) — fully implemented and verified (482/482 backend, 214/216 frontend tests). This plan describes only the DIFF for the second expansion: Company edit/delete, Settings→Payment structured data + Company business type surfaced in the invoice detail view/PDF/image, and closing the Invoice-edit/delete UI gap (backend already supports it since the first expansion; only the detail-view Edit/Delete buttons are missing).

## Summary

Adds Company edit (`PUT /companies/{company}`) and delete (`DELETE /companies/{company}`, blocked 409 if any Invoice references it — Company always has exactly one License, so License linkage is NOT a valid delete-guard here, unlike the License/Invoice entities themselves). Expands `InvoiceResource` to eager-load `company.businessType` so the detail view/PDF/image can show it. Adds Edit/Delete buttons to `InvoiceDetailModal.vue` (wiring already-existing backend endpoints and an already-existing `InvoiceFormModal.vue` edit mode — this is a UI-only gap, not new backend work). Adds the Settings→Payment singleton's structured fields to the invoice detail view and PDF/image capture.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13), Vue 3 — unchanged.

**Primary Dependencies**: None new.

**Storage**: MySQL 8. No new tables — `companies`, `invoices`, `invoice_payment_settings` all already exist from the prior expansion. No migration needed for Company edit/delete (no schema change, just new controller actions on existing columns).

**Testing**: New `tests/Feature/CompanyTest.php` coverage (or extend `CompanyOnboardingTest.php`) for update/delete + the invoice-reference delete-guard; extend `tests/Feature/InvoiceTest.php`'s `InvoiceResource` assertions for the new `company.business_type` nesting; a `qa-tests/component/InvoiceDetailModal.test.js` (or extend `InvoicesView.test.js`) for the new Edit/Delete buttons.

**Target Platform**: Unchanged.

**Project Type**: Web application, extending the existing structure.

**Performance Goals**: N/A — same low-volume administrative/transactional scope.

**Constraints**: Company delete-guard checks Invoice references only (not License — every Company always has exactly one License by design, so that would make every Company permanently undeletable). Invoice payment-information snapshot never changes retroactively if Settings→Payment is edited after the invoice was created (already true since the first expansion — this plan only makes that snapshot's structured fields visible, it doesn't change the snapshot behavior itself).

**Scale/Scope**: Single installation, same administrative-CRUD scope as the rest of this feature.

## Constitution Check

- **Principle I** — PASS. Company update/delete stay controller-level + a small `CompanyOnboardingService::update()`/`delete()` pair (mirroring how `InvoiceService` already holds the delete-guard pattern for Invoice) — no new complex business logic, just CRUD plus one relationship check.
- **Principle II** — PASS. New/extended test coverage for every new capability before declaring this done (Company update/delete + guard, InvoiceResource's new nested field, the Invoice detail-view Edit/Delete UI).
- **Principle III** — PASS. No new menu key — Company edit/delete stay gated on the existing `companies` menu key (unchanged from feature 017); Invoice edit/delete stay gated on the existing `invoices` menu key (unchanged from the first expansion). Indonesian UI copy. Standard 409 for the Company delete-guard, matching the existing License/Invoice delete-guard convention exactly.
- **Principle IV** — PASS. Company edit fields are server-validated (`UpdateCompanyRequest`, new — deliberately NOT reusing `StoreCompanyRequest`, since owner_username/password are creation-only concerns that must never be re-validated/re-required on an edit).
- **Documentation & Change Discipline** — `docs/openapi-pos-mvp.yaml` updated in the same commit as the new Company routes and the `InvoiceResource` shape change.

No violations requiring Complexity Tracking.

## Project Structure

```text
specs/019-billing-system/
├── plan.md, research.md, data-model.md, quickstart.md, contracts/api.md, tasks.md

# Company edit/delete (new)
app/Http/Requests/UpdateCompanyRequest.php   # new — deliberately NOT StoreCompanyRequest (no owner_username/password fields)
app/Services/CompanyOnboardingService.php    # MODIFIED — + update(), delete() (409 guard: any Invoice referencing this Company)
app/Http/Controllers/Api/CompanyController.php  # MODIFIED — + update(), destroy()
app/Policies/CompanyPolicy.php               # MODIFIED — + update(), delete() abilities (both gate on canAccessMenu('companies'), unchanged menu key)
routes/api.php                               # MODIFIED — PUT/DELETE /companies/{company}
lang/id/companies.php, lang/en/companies.php # MODIFIED — + company_delete_has_invoices message
resources/js/api/companies.js                # MODIFIED — + updateCompany(), deleteCompany()
resources/js/views/CompaniesView.vue         # MODIFIED — Edit/Delete actions per row
tests/Feature/CompanyOnboardingTest.php      # MODIFIED — update/delete + guard coverage

# Invoice detail: business type + Settings→Payment structured fields + Edit/Delete UI
app/Http/Resources/InvoiceResource.php       # MODIFIED — nest company.business_type (eager-load businessType on the company relation)
app/Http/Controllers/Api/InvoiceController.php  # MODIFIED — eager-load company.businessType alongside the existing company/license loads
resources/js/api/invoices.js                 # MODIFIED — none needed (updateInvoice/deleteInvoice already exist from the first expansion)
resources/js/components/invoices/InvoiceDetailModal.vue  # MODIFIED — + Edit button (opens InvoiceFormModal in edit mode, already supports it), + Delete button (calls existing deleteInvoice, 409-aware), + business_type row, + structured payment-information block (bank_name/account_number/account_holder/instructions fetched via GET /settings/payment at creation time — actually already baked into the invoice's own payment_information snapshot; this plan surfaces THAT snapshot more visibly/structurally rather than re-fetching live settings)
tests/Feature/InvoiceTest.php                # MODIFIED — assert company.business_type present in InvoiceResource
qa-tests/component/InvoicesView.test.js       # MODIFIED — assert Edit/Delete buttons present and wired

docs/openapi-pos-mvp.yaml                    # MODIFIED
```

**Design note on "Settings→Payment structured fields in invoice detail"**: an invoice's `payment_information` field is ALREADY a fixed snapshot recorded at creation (defaulting from `InvoicePaymentSetting::instructions` if left blank, per the first expansion's FR-015/T068). This plan does NOT change that snapshot mechanism — it only makes sure `InvoiceDetailModal.vue`'s display of that snapshot is presented as clearly-labeled structured fields (or at minimum, unambiguously rendered) rather than an easily-overlooked plain paragraph, and confirms `html2canvas`'s PDF/image capture includes that same block (it already captures the whole `detailEl` container, so no separate wiring is needed there — verify only, in quickstart.md).
