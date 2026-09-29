# Implementation Plan: Pre-order Invoice/Shipping Progress, Print Menu & List Refinements

**Branch**: `025-preorder-dispatch-status-list-refinements` (not yet created) | **Date**: 2026-09-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/025-preorder-dispatch-status-list-refinements/spec.md`

## Summary

Adds a manual, three-value marker (`pending` / `invoice_sent` / `shipping`) to pre-orders, kept **separate** from the main status state machine, with server-derived timestamps for the two active values. The marker is set from a new card in the detail drawer, filtered in the list (and honoured by summary/export), shown with its dates inside the existing status cell, and carried through Excel export/import. `shipping` is Mail Order only, enforced in the API and on the edit path. Alongside it: a "Print" dropdown in the detail (invoice / payment invoice), a fix for the list row's non-working "Payment invoice" link, left-aligned customer names, an "Actions" column with an overflow "More" menu past three actions, and sortable created/updated columns plus detail display. Two latent bugs surfaced and were fixed in passing: the export threw on array-shaped filters, and a duplicate locale key silently overrode existing text.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — no new language/runtime.

**Primary Dependencies**: None new (PhpSpreadsheet's `Shared\Date` is already a transitive dependency of `maatwebsite/excel`).

**Storage**: MySQL 8. Two additive migrations on `preorders`: `dispatch_status` (enum, default `pending`) and `invoice_sent_at` / `shipping_at` (nullable timestamps). Both reversible.

**Testing**: `php artisan test` via the safe container command (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`) and Vitest in `qa-tests/`. A real-browser check per Constitution II is listed in quickstart.md and not yet run.

**Target Platform**: Same single-machine BoothPOS deployment.

**Project Type**: Existing web application (Laravel API + Vue SPA).

**Performance Goals**: N/A — no new query per row; the filter is one `whereIn` on an existing table (no new index), the same shape as the status filter.

**Constraints**: The marker must never touch stock/payments/status (FR-002). Dates are server-derived (FR-006). Import rows that break a rule reject the whole file (matches the importer's all-or-nothing rule).

**Scale/Scope**: Same single-store pre-order volume.

## Constitution Check

- **I. Code Quality & Maintainability** — PASS. One shared `Preorder::DISPATCH_STATUSES` constant feeds request validation and import validation; the timestamp rule lives in one place (`updateDispatchStatus`) and the edit path adjusts only the one impossible case. The row-actions and print menus are small presentational components, not logic in a 1,500-line view. The response goes through the existing `present()`, with `show()`'s eager-loads so no relation silently disappears.
- **II. Testing Standards** — PASS (browser check pending). New Feature tests (`PreorderDispatchStatusTest`, extended `PreorderExportImportTest`) and Vitest cases; existing tests updated where the actions moved into a menu.
- **III. User Experience Consistency** — PASS. Token classes only, no raw hex; new strings exist in both locales; the menu positioning reuses the Teleport/fixed technique of `BaseMultiSelect.vue`.
- **IV. Security** — PASS. No new authorization surface (same audience as `updateStatus`); dates are never accepted from the client; import date parsing is normalised to the app timezone, and export/import remain owner/admin only.
- **V. Performance & Optimization** — PASS. No N+1: the new fields are columns on the loaded model.

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/025-preorder-dispatch-status-list-refinements/
├── plan.md
├── spec.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── dispatch-status.md
│   └── export-import.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
database/migrations/
├── 2026_10_28_000001_add_dispatch_status_to_preorders_table.php        # NEW
└── 2026_10_29_000001_add_dispatch_timestamps_to_preorders_table.php    # NEW

app/
├── Models/Preorder.php                          # MODIFIED — fillable, casts, DISPATCH_STATUSES, $attributes default
├── Http/Controllers/Api/PreorderController.php  # MODIFIED — updateDispatchStatus(), filter, present()/index() fields, sort, export filter list
├── Services/PreorderService.php                 # MODIFIED — edit to pickup downgrades shipping
└── Services/PreorderExportImportService.php     # MODIFIED — columns, array filters, import rules, date parsing
routes/api.php                                   # MODIFIED — PATCH /preorders/{preorder}/dispatch-status
lang/{en,id}/preorders.php                       # MODIFIED — 6 new messages

resources/js/
├── api/preorders.js                             # MODIFIED — updatePreorderDispatchStatus()
├── views/PreordersView.vue                      # MODIFIED — filter, columns, cells, detail card, handlers
├── components/preorder/PreorderPrintMenu.vue    # NEW
├── components/preorder/PreorderRowActions.vue   # NEW
└── locales/{en,id}.json                         # MODIFIED

docs/openapi-pos-mvp.yaml                        # MODIFIED — new path, filter, export/import notes

tests/Feature/PreorderDispatchStatusTest.php     # NEW
tests/Feature/PreorderExportImportTest.php       # MODIFIED
qa-tests/component/PreordersView.test.js         # MODIFIED
```

**Structure Decision**: Existing single web application. One new backend endpoint, two additive migrations, two small frontend components; no new service class — the rule is small enough to live in the controller action plus one line in `PreorderService::update()`.

## Complexity Tracking

*No violations — table intentionally omitted.*
