# Implementation Plan: Customer Data Import & Export

**Branch**: `020-customer-data-import-export` | **Date**: 2026-09-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/020-customer-data-import-export/spec.md`

## Summary

Add bulk export (Customer → `.xlsx`) and bulk import (`.xlsx` → Customer, with a
preview/`dry_run` path) as a **standalone** flow on the Customers screen — a
separate template file and separate endpoints, not a fifth+ sheet in the
existing combined master-data workbook (`MasterDataSheets::ORDER`). This
mirrors feature 007's `PreorderExportImportService` almost exactly: one
Excel workbook, one sheet, full validation before a single all-or-nothing
`DB::transaction()`, `dry_run=1` reusing the identical validation path for
preview. The one genuinely new piece of logic (Customer has no precedent
for this) is the email-based upsert: a row whose email matches an existing
customer (case-insensitively, in the currently active DEMO/LIVE mode)
updates that customer instead of creating a duplicate, honoring
"blank cell = leave unchanged" the same way the master-data importer does.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — matches the rest of this repo, no new language/runtime.

**Primary Dependencies**: `maatwebsite/excel` (already used by every other import/export path in this codebase — `PreorderExportImportService`, `MasterDataImportService`), Laravel's own validation/Eloquent, Vue's existing `axios`-based `api/` client layer, Pinia (`useToastStore`), `vue-i18n`.

**Storage**: MySQL 8 (existing `customers` table — no schema change; `data_mode` scoping already in place via `HasDataMode`).

**Testing**: `php artisan test` (Feature test, real MySQL, mirroring `tests/Feature/MasterDataImportTest.php` / the preorder import/export tests) for the backend; `qa-tests/` (Vitest) for any new frontend component logic — per Constitution II, no SQLite, and the screen must also be exercised in a real browser before being called done.

**Target Platform**: Same single-machine, one-store-per-install deployment as the rest of BoothPOS (native or Docker per feature 015/016) — no new deployment concern.

**Project Type**: Web application (Laravel API + Vue SPA in one repo) — existing "web app" layout, no new project/module boundary.

**Performance Goals**: Export of up to 1,000 customers completes in under 5s (SC-001); import of 100 rows completes in under 30s (SC-002) — both well within what the existing `MasterDataImportService`/`PreorderExportImportService` already demonstrate at similar or larger row counts.

**Constraints**: Reuse the existing upload constraints already enforced for `.xlsx` imports in this codebase (file-type validation via Laravel's `mimes:xlsx` rule, a sane max upload size) — no new, feature-specific limit to invent or maintain.

**Scale/Scope**: A single store's customer list — realistically dozens to low thousands of rows, not a multi-tenant/bulk-data-warehouse scale concern.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. All business logic (validation, the email-matching upsert rule, the all-or-nothing transaction) lives in one new `app/Services/CustomerExportImportService.php`, mirroring `PreorderExportImportService`'s shape exactly — one sanctioned write path, no logic duplicated into the controller. No speculative abstraction: this is a single-sheet, single-entity import, so it deliberately does **not** reuse `MasterDataImportService`'s multi-sheet dependency-ordering machinery, which would be over-engineering for one independent entity.
- **II. Testing Standards** — PASS (planned). New Feature tests under `tests/Feature/` run against real MySQL, mirroring the structure of `tests/Feature/MasterDataImportTest.php`'s row-error and dry-run cases. The Customers screen's new export/import UI will be exercised in a real browser (Docker dev instance already running) before being called done, per Constitution II.
- **III. User Experience Consistency** — PASS. New UI reuses existing `BaseModal`/`BaseButton`/toast/i18n conventions already used elsewhere on this same screen and on `PreordersView.vue`'s inline import UI (no new modal *component* invented) as an existing precedent; the import/export controls are only rendered for roles authorized to use them (Constitution IV — no visible-but-403 controls), and all endpoint errors follow the existing 422/409/403 convention.
- **IV. Security** — PASS, with an explicit note. `Customer` is documented (`app/Models/Customer.php`'s own docblock) as holding personal data (phone, email, social_handle) that must never leak into artist-facing exports; this feature's export is strictly an **internal, owner/admin/inventory-only** operation (same tier as the rest of master-data bulk export/import), never artist-facing, so it does not violate that existing note — but it is exactly the kind of bulk-PII operation that note warns about, so gating is enforced server-side (inline check + route middleware), not cosmetically hidden in the UI alone. Money/pricing is not part of this entity, so the "server always recomputes" rule doesn't apply here in the way it does for orders/preorders. The import is a sensitive master-data-adjacent mutation, so it writes an `ActivityLogger` entry inside the same transaction as the customer inserts/updates, matching every other bulk-import path in this codebase.
- **V. Performance & Optimization** — PASS. Export is a single, unpaginated query (bounded by realistic per-store customer counts, same assumption `PreorderExportImportService::export()` already makes); import validates and applies in one pass with no N+1 (email-matching lookups batched via a single `whereIn('email', ...)` pre-fetch rather than one query per row).

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/020-customer-data-import-export/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   └── customer-import-export.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

This is the existing BoothPOS web application (Laravel API + Vue SPA in one
repo, one deployable unit) — no new project or module boundary is
introduced. New/changed files land in the existing structure:

```text
app/
├── Services/
│   └── CustomerExportImportService.php     # NEW — export()/template()/import() (mirrors PreorderExportImportService)
├── Imports/
│   └── CustomerImport.php                  # NEW — ToArray + WithHeadingRow (mirrors PreorderImport)
├── Http/Controllers/Api/
│   └── CustomerController.php              # MODIFIED — add export()/importTemplate()/import() actions
└── Exports/
    └── GenericArrayExport.php              # REUSED as-is (already generic)

routes/
└── api.php                                 # MODIFIED — GET /customers/export, GET /customers/import/template, POST /customers/import (static routes registered before apiResource, mirroring the existing preorders comment about route-order collision with {preorder} binding)

resources/js/
├── api/
│   └── customers.js                        # MODIFIED — exportCustomers(), downloadCustomerImportTemplate(), importCustomers()
├── views/
│   └── CustomersView.vue                   # MODIFIED — export button + inline import modal (BaseModal), mirrors PreordersView.vue's inline import UI
└── locales/
    ├── en.json                             # MODIFIED — new customers.import_*/export_* keys
    └── id.json                             # MODIFIED — same keys, Indonesian copy (source of truth per Constitution III)

tests/Feature/
└── CustomerImportExportTest.php            # NEW — mirrors MasterDataImportTest.php / preorder import-export test structure

docs/
└── openapi-pos-mvp.yaml                    # MODIFIED — new endpoints documented in the same commit (Constitution/Documentation Discipline)
```

**Structure Decision**: Single existing web application (Laravel + Vue in
one repo) — Option 1/2 hybrid already in place for this whole codebase.
This feature adds files following the exact same shape as feature 007
(Preorder import/export), reusing `GenericArrayExport` and the
`Excel::toArray()` + heading-row-import-class pattern rather than
introducing any new export/import machinery.

## Complexity Tracking

*No violations — table intentionally omitted.*
