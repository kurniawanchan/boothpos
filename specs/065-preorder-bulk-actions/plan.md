# Implementation Plan: Pre-order Bulk Actions

**Classification**: INTERNAL

**Branch**: `095-preorder-bulk-actions` | **Date**: 2026-11-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/065-preorder-bulk-actions/spec.md`

## Summary

Bulk selection and five bulk actions on the Pre-orders list: **update status**, **mark invoice sent**, **mark shipping**
(the last two = the existing dispatch marker), **add payment**, **create shipments**. No schema change.

Approach (reasoning and rejected alternatives in [research.md](./research.md)):

- **Reuse, don't re-implement.** Every action loops the SAME service method the individual action uses —
  `PreorderService::transitionStatus()`, `PaymentService::addPayment()` — and two controller-embedded pieces are
  **extracted into services first** so both paths share them: `PreorderService::setDispatchStatus()` (from
  `PreorderController::updateDispatchStatus`) and a new `ShipmentService::createFor()` (from `ShipmentController::store`).
  The individual endpoints keep their exact behaviour and tests.
- **One orchestrator, `PreorderBulkService`**: limit check, ONE eager-loaded query through the scoped `Preorder` model,
  per-pre-order outer transaction (row lock → service call → audit row), result shaping
  (`succeeded|skipped|failed`, reason, message), aggregate log. It contains no business rule of its own.
- **Preview, then apply** for every action (`POST /preorders/bulk/{action}/preview|apply`, 8 routes registered from one
  table so each carries the SAME `ability` middleware as its individual action). Preview is read-only and returns the
  confirmation numbers and, for payment/shipment, the per-pre-order rows to edit. Apply re-judges every pre-order on
  its current state — there is no client-trusted plan.
- **Idempotency**: payments derive a per-pre-order `client_ref` (UUIDv5 of the dialog's `batch_ref` + pre-order id) so a
  retry replays instead of duplicating; shipments, markers and status are idempotent by their own rules.
- **Selection**: `GET /preorders/matching-ids` reuses `PreorderController::applyFilters()` (the one filter source) so
  "select all N matching" spans pages; the header checkbox needs a small `header-<key>` slot added to `DataTable`.
- **Frontend**: selection logic in a composable, one `PreorderBulkModal` shell (configure → review → processing → result)
  with four small forms and a shared result view; retry = re-select the non-successes.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API); Vue 3 + Vite + Pinia SPA

**Primary Dependencies**: existing only — `PreorderService`, `PaymentService`, `ActivityLogger`, `SellerGate`/scopes, `MenuActions`, `PreorderNotifier`; `ramsey/uuid` (already in `composer.lock`) for UUIDv5. **No new package.**

**Storage**: MySQL 8 — **no migration** (audit rows go to the existing `activity_logs`; payments/shipments/markers use existing tables)

**Testing**: PHPUnit feature tests in `tests/Feature/PreorderBulk/` against real MySQL (inside Docker ONLY with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`, one run at a time, `-e CACHE_STORE=array` to avoid the shared file-cache race); Vitest in `qa-tests/`; mandatory real-browser check (Constitution II)

**Target Platform**: single-machine store install; desktop browser + tablet

**Project Type**: web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: SC-001 50 status changes < 1 min, SC-002 50 payments < 2 min, SC-003 30 shipments < 3 min end to end; preview of 200 pre-orders with a constant number of queries (no per-row query)

**Constraints**: one action ≤ `config('preorders.bulk_max')` = 200 (env `PREORDER_BULK_MAX`); each pre-order is its own transaction; status notifications are sent AFTER each commit and never fail the change; all request bodies are JSON (no file upload, so PHP `max_input_vars` does not apply); the apply request raises the PHP time limit for itself only

**Scale/Scope**: a booth event's pre-order list (hundreds); 0 migrations, 10 routes, 2 extracted + 1 new service, 1 orchestrator, 1 request class, 1 modal shell + 4 forms + 1 result view + 1 composable, locale keys en/id, OpenAPI

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How |
|---|---|---|
| I. Single write path / DRY | PASS | Bulk loops the existing writers (`transitionStatus`, `addPayment`, extracted `setDispatchStatus`/`ShipmentService::createFor`); no rule is copied. Extraction (not duplication) is the explicit price of reuse; individual endpoints and their tests must stay green unchanged |
| II. Testing | PASS (gate at implement) | Regression net run BEFORE extraction; parity tests prove bulk result == individual result per action; mixed-eligibility, idempotency, concurrency, scope, permission, query-count tests; real-browser check is a tracked task — not claimable as done without it |
| III. UX consistency | PASS | Tokens only; hidden-not-disabled per permission; `409/422/403` conventions; en/id locale files |
| IV. Security | PASS | Per-action `ability` middleware on every route + per-pre-order scoped lookup (foreign/other mode → skipped `not_found`, nothing revealed); client amounts are only inputs to the existing server checks (never exceed remaining); audit row in the same transaction as each change |
| V. Performance | PASS | One eager-loaded query for all ids; preview computes from loaded relations; modals lazy-loaded; no N+1 (query-count test) |
| Docs discipline | PASS | OpenAPI in the same commit; PRD + `CLAUDE.md` dated notes at implement |

**Re-check after design (Phase 1)**: unchanged — no violation, Complexity Tracking empty.

**Deliberate deviations (documented, not violations)**: (1) the aggregate `preorder_bulk_operation` log is written after the
per-pre-order transactions (summary of committed work; research D6); (2) the individual status/marker/shipment actions
write NO audit row today, so "same audit entry as the individual action" (spec FR-036) is satisfied by the bulk path
writing its own row — the individual actions are intentionally left unlogged (out of scope, research D6).

## Project Structure

### Documentation (this feature)

```text
specs/065-preorder-bulk-actions/
├── plan.md  research.md  data-model.md  quickstart.md
├── contracts/bulk-preorders.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks (NOT created here)
```

### Source Code (repository root)

```text
app/
├── Services/PreorderBulkService.php             # NEW orchestrator (preview*/apply*, result shaping, audit, limit)
├── Services/ShipmentService.php                 # NEW: createFor() extracted from ShipmentController::store (+ recipientDefaults())
├── Services/PreorderService.php                 # EDIT: + setDispatchStatus() extracted from the controller; + statusBlocker() shared by transitionStatus() and preview
├── Http/Controllers/Api/PreorderController.php  # EDIT: updateDispatchStatus() delegates; + matchingIds(), bulkPreview(), bulkApply()
├── Http/Controllers/Api/ShipmentController.php  # EDIT: store() delegates to ShipmentService (behaviour unchanged)
├── Http/Requests/PreorderBulkRequest.php        # NEW: shape rules per action, limit from config
config/preorders.php                              # NEW: bulk_max
lang/en/preorders.php, lang/id/preorders.php      # EDIT: reason texts, audit descriptions
routes/api.php                                    # EDIT: GET matching-ids + 8 bulk routes (from one table) before apiResource('preorders')
docs/openapi-pos-mvp.yaml                         # EDIT same commit

resources/js/
├── api/preorders.js                              # EDIT: matchingPreorderIds(), previewBulk(), applyBulk()
├── composables/usePreorderSelection.js           # NEW: page/all-matching/clear/partial state, resets with filters
├── components/ui/DataTable.vue                   # EDIT: + optional `header-<key>` slot (default unchanged)
├── components/preorder/bulk/PreorderBulkModal.vue        # NEW shell: configure → review → processing → result
├── components/preorder/bulk/BulkStatusForm.vue, BulkMarkerForm.vue, BulkPaymentForm.vue, BulkShipmentForm.vue   # NEW
├── components/preorder/bulk/BulkResultView.vue           # NEW (counts, reasons, retry, open pre-order)
├── views/PreordersView.vue                       # EDIT: header checkbox, "select all N matching", Clear, 5 action buttons, wiring, refresh
└── locales/en.json, id.json                      # EDIT

tests/Feature/PreorderBulk/                        # NEW folder: fixture trait + one class per area
qa-tests/component/                                # NEW: PreorderBulkModal / forms / selection tests
```

**Structure Decision**: existing Laravel + Vue layout; business logic in `app/Services`, thin controller methods, one new FormRequest, UI under `components/preorder/bulk/`.

## Phase summary

- **Phase 0** — [research.md](./research.md): D1 reuse by extraction, D2 preview/apply shape and route table, D3 per-pre-order transaction + lock, D4 result vocabulary, D5 payment idempotency and amounts, D6 audit (what exists, what bulk adds), D7 status specifics (notify, handed_over), D8 shipments, D9 selection across pages, D10 limits/time, D11 permissions/visibility, D12 frontend structure, D13 non-goals.
- **Phase 1** — [data-model.md](./data-model.md), [contracts/bulk-preorders.md](./contracts/bulk-preorders.md), [quickstart.md](./quickstart.md).

## Complexity Tracking

No Constitution violations to justify.
