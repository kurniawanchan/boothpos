# Implementation Plan: Restore Sales Receipt Action & Event Info in Receipt Footers

**Branch**: `014-sales-receipt-event-footer` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-sales-receipt-event-footer/spec.md`

## Summary

Two small, independent additive changes: (1) restoring the "View receipt" action on the Sales
list — removed as a *trigger* during `009`'s redesign, but the underlying `ReceiptModal.vue`
component and `GET /orders/{id}/receipt` endpoint were never touched, so this is a pure
frontend rewire with zero backend change; (2) adding event name/location/dates to the footer
of both the POS receipt and the pre-order invoice/receipt, which requires one new
`Preorder::event()` Eloquent relation (mirroring the already-existing `Order::event()`) and
four new response fields on each of the two existing document endpoints. See research.md
R1–R4 for the full reasoning, including why no shared footer component is extracted for what
is currently only two call sites.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Vue 3 (Composition API, `<script setup>`), Vite

**Primary Dependencies**: Existing stack only — Eloquent, `vue-i18n`, `html2canvas`/`jsPDF`
(already used by both receipt/invoice components). No new dependency.

**Storage**: MySQL 8 (existing `orders`/`preorders`/`events` tables) — no migration.

**Testing**: `php artisan test` (`tests/Feature/OrderTest.php`, `tests/Feature/PreorderTest.php`)
against real MySQL; `npm test` (`qa-tests/component/SalesView.test.js` and receipt/invoice
component test files) with mocked APIs; manual browser verification per Constitution II.

**Target Platform**: Same single-machine Laravel+Vue SPA as the rest of this codebase.

**Project Type**: Web application (existing Laravel API + Vue SPA in one repo).

**Performance Goals**: None beyond existing conventions — both endpoints already load a single
record with a handful of relations; adding one more (`event` on the preorder side) and four
scalar fields is not a hot path.

**Constraints**: Must not change the existing "products sold" popup's behavior (FR-003), and
must not change `receipt_footer_text`'s existing optional-store-footer behavior — the new
event-info block is a separate, additional block, not a replacement.

**Scale/Scope**: `OrderController` (response fields only), `PreorderController` (`invoice()`
eager-load + response fields), `Preorder` model (new relation), `SalesView.vue`,
`ReceiptModal.vue`, `PreorderInvoiceModal.vue`, `docs/openapi-pos-mvp.yaml`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability**: `Preorder::event()` reuses the exact same relation
  shape `Order::event()` already established, no duplicated concept; footer formatting logic
  lives once per component rather than being invented twice on the backend (research.md R3);
  no premature shared-component abstraction for a two-call-site footer block (research.md R4).
  PASS.
- **II. Testing Standards**: New backend fields get `tests/Feature/` coverage on both
  `OrderTest`/`PreorderTest`; restored frontend action and new footer rendering get
  `qa-tests/` coverage; both touched screens get real-browser verification per quickstart.md
  before being declared done. PASS.
- **III. User Experience Consistency**: Restored action reuses the exact document/mechanism a
  user already saw at checkout time (no new UI pattern); footer reuses this codebase's
  existing "omit block entirely when data absent" convention (already used for
  `receipt_footer_text`/`customer_name` blocks) rather than inventing new empty-state handling.
  PASS.
- **IV/V (Security, Performance)**: No new authorization surface — both endpoints already
  permit exactly the same viewers as today; no new persisted data, no performance-sensitive
  path. PASS.

No violations — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/014-sales-receipt-event-footer/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api-deltas.md     # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks — not created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/
│   ├── OrderController.php      # receipt() gains 3 new response fields
│   └── PreorderController.php   # invoice() gains 'event' eager-load + 4 response fields
├── Models/
│   └── Preorder.php              # new event(): BelongsTo relation
docs/openapi-pos-mvp.yaml         # documents both response deltas (PRD §9.5)

resources/js/
├── views/SalesView.vue                              # re-adds ReceiptModal + "View receipt" action
├── components/receipt/ReceiptModal.vue               # event-info footer block
└── components/preorder/PreorderInvoiceModal.vue      # same footer block

tests/Feature/OrderTest.php        # new event footer fields on receipt()
tests/Feature/PreorderTest.php     # new event() relation + invoice() fields, incl. no-event case
qa-tests/component/SalesView.test.js               # restored receipt action (+ items action still works)
qa-tests/component/ReceiptModal.test.js            # footer rendering incl. edge cases (if exists, else new)
qa-tests/component/PreorderInvoiceModal.test.js    # footer rendering incl. no-event case
```

**Structure Decision**: Existing single-repo Laravel API + Vue SPA structure — no new
directories. Every change lands inside files that already exist for these two documents and
the Sales screen.

## Complexity Tracking

*No violations — section intentionally left empty.*
