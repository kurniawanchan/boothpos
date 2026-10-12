**Classification**: INTERNAL

# Implementation Plan: Hide Events and Pre-orders

**Branch**: `046-hide-events-preorders` | **Date**: 2026-10-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `specs/046-hide-events-preorders/spec.md`

## Summary

Remove two product concepts from every user-facing surface and every business rule: the **Event** (today required by shifts, orders, settlements and most reports) and the **Pre-order** (a whole module with its own payments, stock steps, documents, mail, import/export and report tab). Existing event and pre-order rows stay in the database untouched (decided: Q1 = A, Q2 = A); nothing converts, deletes or reverses them.

Approach: **delete the code, keep the data.** (1) Make the three NOT NULL `event_id` columns nullable and stop writing events, so shifts/sales/tabs/receipts work with zero events. (2) Re-base every report, dashboard panel, sales filter, export and customer view on a business-date range (default: current business day) and on sales only — the recap is computed live and no longer written to the `outlet_settlements` snapshot; cost & profit loses the per-event cost. (3) Delete the Pre-orders module (≈25 routes, services, mail, imports/exports, documents, screens, report tab, stock-arrival/hand-over steps). (4) Delete the Events module, drop both menu keys from the registry and from saved roles, and redirect the old addresses. (5) Sweep tests, locales, OpenAPI, seeders and docs, rehearse the upgrade on a populated copy. Delivered in 5 stages; each stage leaves both suites green.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (API), Vue 3 + Vite + Pinia + Tailwind v4 (SPA); Indonesian code comments and UI copy (id/en locales)
**Primary Dependencies**: existing only (maatwebsite/excel, vue-i18n, Vitest); nothing added, several removed (pre-order mail/imports, `jszip`, whose only caller is the pre-order bulk-invoice helper — research D11)
**Storage**: MySQL 8 (required). One migration: `event_id` → nullable on `cashier_sessions`, `orders`, `outlet_settlements`; one data migration stripping `events`/`preorders` from `roles.menu_keys`. No table is dropped, no row is deleted.
**Testing**: PHPUnit on real MySQL (`warkop_wjm_test`, container run with the explicit `-e` overrides), Vitest in `qa-tests/`, real-browser verification (currently blocked by a locked browser profile — see research D15)
**Target Platform**: single machine or one shared server (constitution 2.0.0); unchanged
**Project Type**: web application (Laravel API + Vue SPA served together)
**Performance Goals**: no regression; report/dashboard queries lose joins, so equal or faster (SC-005: any former per-event figure in ≤ 2 actions)
**Constraints**: money, stock and payment totals that remain visible must be identical before/after (SC-003); stock/payment ledger invariants I1/I2 untouched; old addresses/saved role menus/saved filters must never error (FR-015)
**Scale/Scope**: ≈ 59 backend files and 62 frontend files mention pre-orders, ≈ 23 backend and 20 frontend files mention events; ≈ 53 backend and 37 frontend test files touch pre-orders, ≈ 56 and 144 mention events (most only as fixture data)

## Constitution Check

*GATE: passes before Phase 0; re-checked after Phase 1 design — passes.*

| Principle | Assessment |
|---|---|
| I. Code quality / single implementation | Removing two modules reduces duplication; shared pieces (payment ledger, documents, `SalesFigures`) keep exactly one implementation — the pre-order branches inside them are deleted, not forked. |
| II. Testing | Tests of removed modules are deleted; new tests prove the event-free and pre-order-free flows, redirects, tolerated stale role keys and the upgrade totals. Browser verification is required by the constitution and is **blocked by the locked browser profile** (carried over from 045) — recorded as an open item, not skipped silently. |
| III. UX consistency | Hidden entirely (no disabled leftovers); locale keys removed with their screens; both languages. |
| IV. Security | Removal is enforced server-side (routes deleted → 404; menu keys removed from the registry so no role can hold them); hiding is not the control. Stale keys are ignored by `Role::allows`, never granting access. |
| V. Performance | Fewer joins and no per-event aggregation; no new N+1. |
| Documentation & change discipline | Pre-orders and events were *added* capabilities: their removal is recorded as explicit dated notes in `CLAUDE.md` and the PRD (no history rewritten); `docs/openapi-pos-mvp.yaml` updated in the same commit as each route/response change. |

No violations; Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/046-hide-events-preorders/
├── plan.md              # this file
├── research.md          # decisions D1–D16
├── data-model.md        # schema changes and what stays
├── quickstart.md        # verification scenarios
├── contracts/
│   └── removed-and-changed.md   # removed routes, changed payloads, redirects
├── checklists/requirements.md
└── tasks.md             # created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/   # delete EventController, PreorderController, ShipmentController, preorder parts of Customer/Order/Product/PaymentProof/Report/CashierSession/PosDraft controllers
├── Http/Requests/          # delete Event*/Preorder*/Duplicate/Split requests
├── Http/Resources/         # OrderResource, ProductResource lose event/pre-order fields
├── Models/                 # Event, Preorder, PreorderItem, PreorderNotification, Shipment kept ONLY if a migration/history reader needs them (research D2) — otherwise deleted; Order/CashierSession/OutletSettlement lose the event relation
├── Services/               # delete PreorderService, PreorderNotifier, PreorderExportImportService; simplify PaymentService/PaymentRecorder/PaymentSummary, SalesFigures, SettlementService, OrderService, TabService, TabSettlementService, StockMovementReferences, MasterDataImport/Export
├── Mail/                   # delete PreorderInvoiceMail, PreorderStatusMail
├── Support/                # MenuKeys (drop 2 keys), delete PreorderDocumentType/PreorderSellerSubtotals/Couriers (if unused), BuildsInvoiceDocument pre-order parts
├── Imports/                # delete PreorderImport
routes/api.php              # delete ~30 event/pre-order routes + report/export keys
database/
├── migrations/             # 2026_12_10_… nullable event_id; 2026_12_10_… strip role menu keys
└── seeders/                # DatabaseSeeder + SakanaFridgeDemoSeeder without events/pre-orders
resources/js/
├── views/                  # delete EventsView, PreordersView, views/preorders/*; edit PosView, SessionView, SalesView, ReportsView, TabView, CustomersView, ProductsView/forms
├── components/             # delete components/preorder/*, report/PreorderReportDetailModal, dashboard PerEventSection (replaced by period figures); edit receipt/payment/customer/sales components
├── api/                    # delete events.js, preorders.js, shipments.js; edit reports.js, payments.js, products.js, invoices.js
├── router/ + utils/navigation.js + AppShell/RailNav   # remove entries, add redirects
└── locales/                # remove dead keys through the merge/remove tool
qa-tests/ and tests/Feature # delete module tests; update characterisation suites; add event-free / pre-order-free / redirect / upgrade tests
docs/                       # openapi-pos-mvp.yaml, RUNBOOK.md, PRD note; CLAUDE.md dated note
```

**Structure Decision**: the existing Laravel API + Vue SPA layout; this feature only deletes and simplifies inside it, plus two migrations.

## Stages

| # | Stage | Result (each ends with both suites green) |
|---|---|---|
| 1 | **Schema + event-free trading** | nullable `event_id`; shift open without event; orders/tabs/drafts/receipts without event; POS/Session/Tab screens without event |
| 2 | **Reports, dashboard, sales, customers, exports by date and sales-only** | date range everywhere (default current business day), recap live, profit without event cost, no pre-order blending, customer history sales-only, product pre-order flags gone |
| 3 | **Delete the Pre-orders module** | routes/controllers/services/mail/imports/exports/documents/screens/tests removed; payment ledger orders-only; stock-movement labels neutral for legacy rows |
| 4 | **Delete the Events module + menus** | Events screen/API removed; both keys out of the registry and out of saved roles; redirects for old addresses |
| 5 | **Sweep + upgrade proof** | locales, OpenAPI, seeders, docs/dated notes; rehearsal on a populated copy (totals equal); browser pass when the profile is available |
