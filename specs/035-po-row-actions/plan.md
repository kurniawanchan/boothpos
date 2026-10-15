# Implementation Plan: Purchase Order Row Actions and Out-of-date Database Errors

**Branch**: `035-po-row-actions` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/035-po-row-actions/spec.md`

## Summary

Two independent slices, one frontend-heavy and one small cross-cutting backend piece:

1. **Row actions (US1–US3)** — the Purchase Orders list gets Detail / Edit / Delete on every row, reusing the existing generic `PreorderRowActions` component (visible-but-disabled-with-reason, overflow into a "More" menu). Edit opens for every status (draft: everything; later: vendor/seller/notes only, lines read-only — the form and API already behave this way, the button was simply draft-only). Delete stays draft-only server-side, now with an explicit "cancel instead" message plus defensive guards (payments, BOM usage) and the UI shows it disabled with that reason. Edits are audited (`purchase_order_updated`).
2. **Out-of-date database (US4)** — root cause of the two screenshots was verified: the dev database has 4 pending migrations (container started before PR #29). The code is fine. Two layers prevent a repeat from looking like a bug:
   - **Reactive**: a global exception renderer turns "unknown column / table not found" database errors on API requests into a **503 `schema_outdated`** with a plain-language message (details stay in the log) instead of raw SQL.
   - **Proactive**: `GET /settings/features` reports `schema_update_required` (owner/admin only) from a single `SchemaStatus` check; the app shell shows a banner telling an administrator how to apply the update.
   - **Failure states**: the PO detail dialog and the BOM selector/modal show the problem + **Retry** instead of a blank dialog / a misleading "no eligible lines".

No schema change and no new endpoint.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (Pinia, vue-i18n en/id, Tailwind v4 tokens)

**Primary Dependencies**: none new (uses Laravel's `Migrator`/`MigrationRepository` already in the framework)

**Storage**: MySQL 8 — **no migration**

**Testing**: PHPUnit on the host (`APP_ENV=testing`, `boothpos_test`); Vitest + Testing Library; real-browser check on an isolated server, plus the real dev stack for the "pending migrations" scenario

**Target Platform**: Local Laravel app + SPA (native or Docker)

**Project Type**: Web application

**Performance Goals**: schema check costs one directory scan of the migrations folder + one `SELECT` on `migrations`, run once per SPA load (features call) and never per request

**Constraints**: raw database text never reaches a user-facing response for schema errors; technical details stay in the log (still reported); only drafts deletable; lines locked after draft; seller locked once a BOM uses the order's lines; permissions unchanged (`purchase_orders` menu, owner/admin/inventory); DEMO/LIVE unchanged

**Scale/Scope**: 1 support class, 1 exception renderer, 1 features field, PO service/controller tweaks, 3 frontend components + banner, locales (en/id), docs, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Single implementation / no duplication | **Pass.** One `SchemaStatus` class answers "is the database behind?" (used by the features endpoint and testable alone); one exception renderer owns the schema-error mapping; the row-action UI reuses `PreorderRowActions` instead of a second menu; PO edits/deletes keep going through `PurchaseOrderService` + `ActivityLogger`. |
| II. Testing | **Pass (planned).** Backend: PO row rules (delete draft-only + guards, edit allowed fields per status, audit rows), schema renderer (503 + friendly text + log still written + non-schema errors unchanged), `SchemaStatus` (pending vs none; owner/admin only). Frontend: row actions per status, disabled-with-reason, detail Retry, selector error state, banner. Real-browser run incl. the pending-migration scenario. |
| III. UX consistency | **Pass.** Same component and wording pattern as the Pre-orders list; tokens only; 409/503 handled in the central client; controls hidden for roles without the menu (whole page), state-based unavailability shown disabled with a reason (same as Pre-orders "Split"); en + id copy. |
| IV. Security | **Pass (improves).** Users no longer receive raw SQL (table/column names); the banner/flag is owner/admin-only; deletes/edits audited in the same transaction; server remains the only authority for what can be edited/deleted. |
| V. Performance | **Pass.** Schema check once per SPA load; no per-request cost, no N+1. |
| Documentation | OpenAPI (503 `schema_outdated`, `schema_update_required`, PO delete/edit notes), RUNBOOK ("after pulling, apply updates; Docker applies on start"), CLAUDE.md. |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/035-po-row-actions/
├── plan.md
├── research.md            # decisions + alternatives
├── data-model.md          # no schema change; action-availability matrix and audit entries
├── contracts/po-rows-and-schema.md
├── quickstart.md
├── checklists/requirements.md
└── tasks.md               # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/Support/SchemaStatus.php                         # NEW — pending-migration check (single source)
bootstrap/app.php                                    # withExceptions: schema-error renderer (503 schema_outdated)
app/Http/Controllers/Api/SettingsController.php      # features(): schema_update_required (owner/admin)
app/Services/PurchaseOrderService.php                # delete() guards; update() audit
app/Http/Controllers/Api/PurchaseOrderController.php # destroy() message; (no new routes)
lang/{en,id}/purchase_orders.php, lang/{en,id}/system.php (NEW)
resources/js/views/PurchaseOrdersView.vue            # row actions via PreorderRowActions; Edit for all statuses
resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue   # error state + Retry
resources/js/components/product/{AddBomItemModal,VariantBomModal}.vue # error state + Retry (no false "no eligible lines")
resources/js/components/layout/{AppShell.vue,SchemaUpdateBanner.vue (NEW)}
resources/js/{api/client.js,utils/errors.js,stores/settings.js}       # schema_outdated handling, flag
resources/js/locales/{en,id}.json
docs/openapi-pos-mvp.yaml, docs/RUNBOOK.md, CLAUDE.md
tests/Feature/{PurchaseOrderRowActionsTest,SchemaOutdatedTest}.php (new); qa-tests/component/{PurchaseOrderRowActions,PurchaseOrderDetailModal,SchemaUpdateBanner}.test.js (new), existing PO/BOM tests extended
```

**Structure Decision**: extend the existing PO module and the central error/feature plumbing; no new top-level concepts.

## Complexity Tracking

No constitution violations to justify.
