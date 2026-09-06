# Implementation Plan: Billing Records for Company Onboarding

**Branch**: `019-billing-system` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/019-billing-system/spec.md`

## Summary

Adds `Invoice` as a new entity tied to `Company` (feature 017) — a manually-entered billing record (amount, due date, status) with no payment-gateway integration, matching the existing "internal CRM tracker" scope. CRUD/status-transition follows this codebase's established simple-entity pattern (`VendorController`-style for creation, `PurchaseOrderService`-style guarded status transitions for paid/cancelled) rather than inventing a new pattern. Gated on the existing `companies` menu key — no new permission surface.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13), Vue 3 — unchanged.

**Primary Dependencies**: None new.

**Storage**: MySQL 8, one new table (`invoices`), `HasDataMode`-scoped (business/transactional data tied to a `HasDataMode`-scoped `Company`, per `CLAUDE.md`'s existing rule).

**Testing**: `tests/Feature/InvoiceTest.php` — create, mark paid, mark cancelled, invalid transitions rejected, role gating (cashier/inventory 403), filter by status.

**Target Platform**: Unchanged — same single-machine installation.

**Project Type**: Web application, extending the existing structure.

**Performance Goals**: N/A — low-volume administrative data, same as `Company`/`Package`.

**Constraints**: An invoice's amount is a fixed snapshot at creation (FR-007) — never recomputed from the company's current package.

**Scale/Scope**: Single installation, one invoice list per company — not a ledger/accounting system.

## Constitution Check

- **Principle I** — PASS. Status transitions (`markPaid()`/`cancel()`) live in a small `InvoiceService`, mirroring `PurchaseOrderService`'s transition-guard pattern; plain CRUD (create/list) stays controller-level like `VendorController`, since it has no real business logic beyond validation.
- **Principle II** — PASS. `tests/Feature/InvoiceTest.php` covers every transition and gating rule before this is declared done.
- **Principle III** — PASS. Reuses `companies` menu key (no new permission surface), Indonesian UI copy, standard 422/409/403 convention (invalid transition → 409, matching `PurchaseOrderService`'s own status-transition convention).
- **Principle IV** — PASS. Amount/status transitions are server-validated only; an invoice's amount is never client-recomputed from live package data.
- **Documentation & Change Discipline** — `docs/openapi-pos-mvp.yaml` updated in the same commit as the new routes.

No violations requiring Complexity Tracking.

## Project Structure

```text
specs/019-billing-system/
├── plan.md, research.md, data-model.md, quickstart.md, contracts/api.md, tasks.md

database/migrations/2026_10_18_000001_create_invoices_table.php
app/Models/Invoice.php
app/Services/InvoiceService.php        # markPaid(), cancel() — guarded transitions
app/Http/Controllers/Api/InvoiceController.php
app/Http/Requests/StoreInvoiceRequest.php
app/Http/Resources/InvoiceResource.php
app/Policies/InvoicePolicy.php          # gates on canAccessMenu('companies')
lang/id/companies.php, lang/en/companies.php  # MODIFIED — new invoice_* keys, same file as 017
resources/js/api/invoices.js
resources/js/components/companies/CompanyInvoicesPanel.vue  # embedded in CompanyDetail, per US3
docs/openapi-pos-mvp.yaml               # MODIFIED
tests/Feature/InvoiceTest.php
```

**Structure Decision**: Follows 017's exact per-entity pattern (Model + Policy + FormRequest + Resource + Controller), reusing its `companies` menu key and lang file — this is additive record-keeping on an existing entity, not a new subsystem.

## Complexity Tracking

*No violations — table omitted.*
