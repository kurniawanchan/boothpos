**Classification: INTERNAL**

# Implementation Plan: POS Layout Refresh, Sales Page Upgrades, Sales Reports and Cashier Session List

**Branch**: `085-pos-layout-sales-reports` | **Date**: 2026-11-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/054-pos-layout-sales-reports/spec.md`

## Summary

Twelve user stories in four areas, one existing architecture:

1. **POS screen (US1–US4), frontend only.** The variant picker stays open and shows per-variant cart counts with a bottom Close button; Save-as-draft / Draft Transactions move into the cart panel header; the seller and category dropdowns become horizontally scrollable pill rows (seller pills beside a half-width search box, category pills below it).
2. **Sales page (US5–US9).** Root causes found by reading the code, not guessed: the KPI cards read server totals that only know the *event* filter (so they disagree with every client-side filter), and the "All events" row in the dropdown is a non-clickable `<div>`. Fix: KPI cards derive from the same filtered rows as the summary line; add an explicit selectable "All events" option; add client-side pagination over the already-loaded list. New: payment-method correction (`PaymentService::changeMethod`), a per-sale reference (note + private image), a reference link in the table, one-line number/total cells, and "Download all receipts" (one receipt PDF per sale, zipped, same pattern as pre-order bulk invoices).
3. **Reports (US10–US11).** Two new tabs in the existing owner/admin Reports page, backed by two new `ReportController` endpoints plus drill-down transaction lists: sales by product (best-seller mark, name search) and sales by payment method.
4. **Cashier sessions (US12).** A paginated session history list with a detail view (open/close figures and the session's transactions), via new read-only endpoints on `CashierSessionController`.

No existing business rule is changed except one deliberate, logged exception: a payment's *method and channel* become correctable (amount/date stay immutable).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing), Vue 3 SPA (Vite, Pinia, vue-i18n, Tailwind v4)

**Primary Dependencies**: existing only: `html2canvas`, `jspdf`, `jszip` (already in `package.json`, already dynamically imported for pre-order bulk invoices). No new dependency.

**Storage**: MySQL 8 (required). One migration: nullable reference columns on `orders` (see data-model.md). Reference image on the private `local` disk (same disk and serving pattern as payment proofs).

**Testing**: PHPUnit feature tests under `tests/Feature/` against real MySQL (`boothpos_test`; inside Docker only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`); Vitest under `qa-tests/`; real-browser verification of every changed screen (Constitution II).

**Target Platform**: Store laptop/tablet, browser at `localhost`; POS layout must work at tablet width.

**Project Type**: Web application (Laravel API + Vue SPA on one machine)

**Performance Goals**: Sales page browsing of 1,000 transactions per page-change < 2 s (SC-006); receipt download of 200 sales < 2 min (SC-009); reports aggregate in SQL, no per-row queries.

**Constraints**: DEMO/LIVE isolation (raw `DB::table` queries need explicit `data_mode` filter); money returned as 2-decimal strings; OpenAPI moves in the same commit as route changes; UI copy in both `en.json` and `id.json` (static `localeKeys` test); never trust client money.

**Scale/Scope**: A booth event's list: hundreds to low thousands of sales; tens of sessions per event.

## Constitution Check

*GATE: passes before Phase 0; re-checked after Phase 1 design below.*

| Principle | Assessment |
|---|---|
| I. Single write paths, logic in Services | Method change goes through a new `PaymentService::changeMethod()` (the payment write path; `PaymentRecorder` stays the only *creator*). Reference writes go through a new `OrderReferenceService`, audit via `ActivityLogger`. Reports/sessions logic in services, not controllers. **Pass.** |
| II. Verify, don't assume | Backend feature tests on MySQL for every new endpoint and rule; Vitest for pills/picker/pagination; mandatory real-browser pass (quickstart.md) because mock tests cannot see layout or the scan race. **Pass (planned).** |
| III. UX consistency | Token classes only (no hex); errors via the 422/409/403 convention and the central handler; controls a role cannot use are hidden, not disabled (change-method, reference edit); copy via i18n keys in both locales. The "Indonesian only" clause is superseded in practice by feature 002's documented language toggle. **Pass.** |
| IV. Security | Authorization server-side in service and request class; reference image private disk + authorizing endpoint, random names, MIME sniffed server-side; payment-method change cannot touch amounts; audit rows inside the same transaction; bulk receipts return only what the existing receipt shows. **Pass.** |
| V. Performance | Reports are single aggregate queries with explicit `data_mode`; session list paginated server-side; Sales list uses `withExists`-style indicator (no N+1); JSZip/PDF libs stay dynamic imports. **Pass.** |

**Post-design re-check**: no violations; Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/054-pos-layout-sales-reports/
├── plan.md              # This file
├── research.md          # Phase 0 decisions
├── data-model.md        # Phase 1: columns, entities, state rules
├── quickstart.md        # Phase 1: how to run and verify each story
├── contracts/
│   └── api-changes.md   # Phase 1: new/changed endpoints
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks, not created here)
```

### Source Code (repository root)

```text
# Backend
database/migrations/2026_11_03_000001_add_reference_to_orders_table.php   # new (US8)
app/Services/PaymentService.php                  # + changeMethod() (US7)
app/Services/OrderReferenceService.php           # new (US8)
app/Services/SalesTransactionsService.php        # + has_reference, items_gross/items_net per row (US5/US8)
app/Services/SalesByProductReport.php            # new (US10)
app/Services/SalesByPaymentMethodReport.php      # new (US11)
app/Services/CashierSessionQuery.php             # new (US12)
app/Http/Controllers/Api/OrderController.php     # + changePaymentMethod, reference CRUD, reference file, bulkReceipts
app/Http/Controllers/Api/ReportController.php   # + salesByProduct(+transactions), salesByPaymentMethod(+transactions)
app/Http/Controllers/Api/CashierSessionController.php  # + index, show, transactions
app/Http/Requests/ChangePaymentMethodRequest.php, UpsertOrderReferenceRequest.php  # new
app/Http/Resources/OrderResource.php             # + reference block (relationLoaded-safe)
app/Services/DemoClone/CloneRegistry.php         # orders: add reference_image_path to 'files'
routes/api.php                                   # new routes (static before {order})
lang/en|id/*.php                                 # new server messages
docs/openapi-pos-mvp.yaml                        # same commit as routes

# Frontend
resources/js/views/PosView.vue                   # layout, pills, picker wiring
resources/js/components/pos/PosCartPanel.vue     # draft actions in header
resources/js/components/pos/ProductVariantPickerModal.vue  # stays open, counts, Close button
resources/js/components/pos/PillFilterRow.vue    # new: scrollable single/multi pill row (US3/US4)
resources/js/views/SalesView.vue                 # All events, KPIs from summary, pagination, link, one-line cells, bulk download
resources/js/composables/useSalesFilters.js      # + KPI totals, page state in query
resources/js/components/ui/TablePagination.vue   # existing, reused as is (research R6)
resources/js/components/sales/TransactionItemsModal.vue    # change-method + reference editor
resources/js/components/sales/OrderReferenceModal.vue      # new: view/edit reference
resources/js/components/sales/ChangePaymentMethodModal.vue # new
resources/js/components/receipt/ReceiptDocument.vue        # extracted from ReceiptModal (shared with bulk)
resources/js/views/ReportsView.vue               # + two tabs
resources/js/components/report/SalesByProductTab.vue, SalesByPaymentMethodTab.vue, ReportTransactionsModal.vue  # new
resources/js/views/SessionsHistoryView.vue       # new (US12), route 'sessions-history'
resources/js/api/*.js, resources/js/locales/en.json + id.json, router/index.js, sidebar entry

# Tests
tests/Feature/: PaymentMethodChangeTest, OrderReferenceTest, SalesTransactionsReferenceTest,
                SalesByProductReportTest, SalesByPaymentMethodReportTest, CashierSessionListTest, BulkReceiptsTest
qa-tests/: PosVariantPicker, PosFilterPills, PosCartHeader, SalesKpiAndPagination, SalesAllEvents,
           SalesReferenceAndMethod, ReportsNewTabs, SessionsHistory, localeKeys (existing guard)
```

**Structure Decision**: Existing single Laravel + Vue layout; reuse before adding. New files only where there is no existing home (two report services, reference service, session query, three small components). `ReceiptDocument.vue` is *extracted* from `ReceiptModal.vue` so the modal and the bulk download share one layout (the project rule already applied to pre-order invoices).

## Delivery order (each story independently shippable)

1. P1 first: US1, US2, US5 (picker, draft actions, KPI/all-events/pagination). 
2. Layout P2: US3, US4, US6 (pills, one-line cells).
3. Payments P2: US7 then US8 (US11 depends on US7 only for "corrected method shows once").
4. Reports and sessions P2: US10, US11, US12.
5. P3: US9 (needs the `ReceiptDocument` extraction).

## Complexity Tracking

No constitution violations to justify.

## Known risks (carried into tasks)

- **Payment-method change touches shift cash and order change.** Rules fixed in research.md R3; the cash/change edge is the riskiest part and gets the most tests.
- **`BaseSelect` placeholder row** is shared by 8+ callers; the fix is at the Sales caller (explicit empty option), not in the component.
- **Pagination reverses a documented decision** ("no pagination" in CLAUDE.md, Sales section): CLAUDE.md must be updated with a dated note in the same change.
- **Bulk receipt rendering** is client-side rasterisation; the 200-sale cap and per-receipt failure reporting are the guardrails.
