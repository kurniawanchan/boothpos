# Implementation Plan: Split Payment Visibility, Preorder Receipt & Reporting

**Branch**: `010-split-payment-preorder-reports` | **Date**: 2026-09-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/010-split-payment-preorder-reports/spec.md`

## Summary

Six independently-shippable changes on top of the existing Laravel + Vue
BoothPOS app. The pre-plan code survey found the split-payment *data model*
(one `Payment` row per entry, `payments[]` already accepted by `POST
/orders`) already exists for Orders, but the split-payment *experience* has
two real, distinct gaps rather than one cosmetic one: (1) `PaymentPanel.vue`
gives zero visible affordance that splitting is even possible before a user
stumbles into it, at POS checkout; and (2) for Preorders, `PaymentPanel`'s
`mode="record"` branch never accumulates entries at all — it emits a single
payment every submit, and the backend `POST /preorders/{id}/payments`
endpoint only ever accepts one payment object per call. So Preorder split
payment is not just invisible, it doesn't work end-to-end yet. This plan
closes both gaps by (a) making `PaymentPanel`'s split UI explicit and always
visible regardless of mode, and (b) having the Preorder payment flow
accumulate entries client-side and submit them as sequential calls to the
*existing, unmodified* `POST /preorders/{id}/payments` endpoint — no new
backend endpoint, reusing `PreorderService::recordPayment()`'s already-
correct one-call-per-Payment-row semantics.

Row hover (`DataTable.vue` + four other raw-`<table>` components) is a
pure CSS addition. The preorder payment receipt is a new component
(`PreorderPaymentReceiptModal.vue`) built off `GET /preorders/{id}`'s
already-loaded `payments` relation — not a reuse of the POS `ReceiptModal`
(structurally incompatible: order-specific fields like `cashier_name`,
`change_amount`) and not the existing `PreorderInvoiceModal` (that's an
order-confirmation document, not an itemized payment receipt).

Merging Preorder revenue into `sales()`/`profit()`/`artistSettlements()`/
`SettlementService::recalculateForEvent()` is the trickiest piece: `Preorder`
and `PreorderItem` mirror `Order`/`OrderItem`'s shape closely enough to
UNION in structurally, but a preorder's `line_total` is the item's *order*
value, not what's actually been *paid* — merging it in unprorated would
violate spec FR-012 ("a partially-paid preorder must not inflate revenue by
its full order value"). The design recognizes only the cash actually
collected (summed from `payments`, never from the potentially-stale
`preorders.paid_amount` cache), prorated across a preorder's items/artists
by each item's share of the preorder subtotal — documented in research.md
R1 as the one non-obvious design decision in this plan. The new dedicated
Preorder report is a fully new `ReportController::preorders()` (or sibling
controller) endpoint — no existing aggregate to build on.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Vue 3 (Vite) — unchanged, matches existing repo.

**Primary Dependencies**: Backend: existing `PreorderService`, `PaymentRecorder`, `SettlementService`, `ReportController`. Frontend: existing `PaymentPanel.vue`, `DataTable.vue`, the html2canvas/jsPDF print pattern already used by `ReceiptModal.vue`/`PreorderInvoiceModal.vue` for the new receipt component.

**Storage**: MySQL 8 (existing `payments`, `preorders`, `preorder_items`, `orders`, `order_items`, `artist_settlements` tables) — no new tables; report queries add a parallel Preorder-sourced aggregation, no schema change.

**Testing**: `php artisan test` (`tests/Feature/`) for every backend report/query change and the (unchanged-but-newly-multi-call) preorder payment flow; Vitest (`qa-tests/`) for `PaymentPanel`, `DataTable` hover, and the new receipt component; manual browser verification per Constitution Principle II for every touched screen (POS checkout, Preorder payment recording, every table screen, Preorder detail, all report tabs).

**Target Platform**: Same as existing app — single-machine local install.

**Project Type**: Web application (Laravel API + Vue SPA in one repo) — no restructuring.

**Performance Goals**: No new goals beyond Constitution Principle V — the preorder-revenue merge into `sales()`/`profit()`/`SettlementService::recalculateForEvent()` must stay as grouped SQL aggregation (UNION ALL or two aggregate queries merged once in PHP), not a per-preorder loop; sequential Preorder payment-entry submission (2–3 calls typical for a split) is an accepted, bounded exception to "no client-side loops calling the API," since it reuses existing single-payment semantics rather than requiring a new batched endpoint.

**Constraints**: No new backend endpoint for Preorder payments — split entries submit as sequential calls to the existing `POST /preorders/{id}/payments`. Revenue recognized from a preorder into any report is bounded by cash actually received (`payments`, not `preorders.paid_amount`), never by order/line value. A cancelled preorder contributes zero revenue regardless of prior payments recorded (spec Edge Cases). Row hover must not visually conflict with existing selection/status-color row indicators (spec FR-007). DEMO/LIVE mode boundary and existing role-based report access apply unchanged to every touched report.

**Scale/Scope**: ~10 files touched for split-payment visibility (POS + Preorder), 5 files touched for row hover, 2 new components + 1 backend field addition for the preorder receipt, 4 report methods + `SettlementService` extended for preorder revenue merge, 1 new report endpoint + 1 new frontend report tab for the dedicated Preorder report.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. No new payment write path is introduced — Preorder split payments still go through the single sanctioned `PaymentRecorder::record()` call per entry, just issued sequentially from the frontend instead of once; this avoids a second, parallel "batch payment" code path competing with the existing one. Revenue-merge logic for reports lives in the same `SettlementService`/`ReportController` files already responsible for that concern, not a new parallel reporting service.
- **II. Testing Standards** — PASS, enforced by plan: every backend query change gets a `tests/Feature/` test against MySQL; every touched screen gets a real-browser check before being marked done.
- **III. User Experience Consistency** — PASS. Row hover and the split-payment UI use only existing `@theme` tokens (no raw hex). The preorder payment receipt visually mirrors the POS receipt's existing layout conventions rather than inventing a new document style. UI copy is Indonesian-first via existing i18n keys, new keys added to both locale files.
- **IV. Security** — PASS. No client-supplied money values are trusted — revenue-recognized amounts for reports are always server-computed from `payments` rows, never from client input or even from the `preorders.paid_amount` cache (explicitly avoided per research.md R1, since that cache is not re-derived from `payments` at read time and could drift). Preorder payment receipt exposure follows the same role/ownership boundary already enforced on `GET /preorders/{id}`.
- **V. Performance & Optimization** — PASS with an explicit note: the preorder-revenue merge into `sales()`, `profit()`, `artistSettlements()`/`SettlementService::recalculateForEvent()` must be one additional grouped aggregate query (or a `UNION ALL` folded into the existing query), not a preorder-by-preorder loop — called out explicitly in Phase 1 design and re-verified in code review.

No violations requiring Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/010-split-payment-preorder-reports/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/             # Phase 1 output (API contract deltas)
└── tasks.md               # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/
│   ├── ReportController.php         # + preorder revenue merged into sales()/profit()/artistSettlements(); + new preorders() report endpoint
│   └── PreorderController.php       # unchanged endpoints; show()/present() already exposes payments (verified in survey)
├── Services/
│   └── SettlementService.php        # recalculateForEvent() extended to also aggregate preorder_items (prorated by paid amount)

resources/js/
├── components/
│   ├── payment/
│   │   ├── PaymentPanel.vue          # + explicit split-visible UI (both modes); mode="record" now accumulates entries like mode="checkout"
│   │   └── RecordPaymentModal.vue    # + submits entries[] as sequential POST /preorders/{id}/payments calls
│   ├── preorder/
│   │   └── PreorderPaymentReceiptModal.vue   # NEW — POS-receipt-styled, preorder-marked, per-payment-event receipt
│   ├── product/ProductDetailModal.vue        # + row hover (raw <table>, not DataTable)
│   ├── product/VariantBomModal.vue           # + row hover
│   ├── report/ArtistTransactionsModal.vue    # + row hover
│   └── masterData/MasterDataImportModal.vue  # + row hover
├── components/ui/
│   └── DataTable.vue                 # + row hover class (covers every screen using DataTable)
├── views/
│   ├── PreordersView.vue             # + "print payment receipt" action per payment event
│   └── ReportsView.vue               # + new "Preorder" report tab
├── api/
│   └── reports.js                    # + preorderReport() function
├── locales/{id,en}.json              # new keys: split-payment hint copy, preorder receipt labels, preorder report labels

tests/Feature/
├── ReportTest.php                    # extended: preorder revenue included in sales()/profit(), cancelled preorder excluded, partial-payment proration
├── ArtistSettlementTest.php          # extended: settlement recalculation includes preorder-sourced revenue (file name TBC — confirm exact existing test file during implementation)
├── PreorderPaymentTest.php           # extended or new: sequential split payments against a preorder produce correct paid_amount/payments rows
└── PreorderReportTest.php            # NEW — dedicated report endpoint

qa-tests/
├── component/PaymentPanel.test.js    # extended: split UI visible in both modes, entries accumulate in record mode
├── component/DataTable.test.js       # extended: hover class present
└── component/PreorderPaymentReceiptModal.test.js  # NEW
```

**Structure Decision**: Existing single Laravel+Vue repo, no new top-level directories — matches the established pattern from features 006/007/009.

## Complexity Tracking

*No Constitution Check violations — table not needed.*
