# Implementation Plan: Pre-order Invoice Layout Refinements & Shipping Slip

**Branch**: `024-invoice-layout-shipping-slip` | **Date**: 2026-09-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/024-invoice-layout-shipping-slip/spec.md`

## Summary

Five refinements to the pre-order invoice (and, where it shares the same
shell, the payment invoice): (1) the header becomes two columns — event
name/location/available-on (centered) on the left, pre-order
number/status/"To:" recipient block on the right; (2) the document's
own title reads as a pre-order invoice and the creation date is shown;
(3) payment options split into two columns — QR-based channels (bigger
QR) and bank-transfer channels; (4) a new shipping-slip section appears
for Mail Order pre-orders only, showing event/order-number/from/to/item
type; (5) the existing footer message keeps working unchanged. All five
are achieved almost entirely in the frontend components — the only
backend change is adding `created_at` to `PreorderController::present()`
(confirmed missing by reading the method directly), since every other
field the request needs (customer contact fields, store identity,
fulfillment, items) is already returned by the existing invoice payload.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), Vue 3 (Composition API) — no new language/runtime.

**Primary Dependencies**: None new. Same client-side-only document rendering (`html2canvas`+`jsPDF`) as every prior document feature.

**Storage**: MySQL 8. No schema change — `Preorder.created_at` already exists (a standard Eloquent timestamp column), it is simply not yet included in `present()`'s response. No new tables, no new columns.

**Testing**: `php artisan test` (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`) and `qa-tests/` (Vitest), plus a real-browser check per Constitution II for the redesigned header, payment columns, and shipping slip.

**Target Platform**: Same single-machine BoothPOS deployment — no deployment change.

**Project Type**: Existing web application (Laravel API + Vue SPA).

**Performance Goals**: N/A — no new query, no new data volume.

**Constraints**: No server-side PDF/image rendering is introduced (Constitution I/existing precedent — the shipping slip is a section of the same client-rendered document, not a second document or endpoint). The shipping slip must not depend on a `Shipment` record existing (spec.md Assumptions) — it reads from data the invoice payload already has (pre-order, event, customer).

**Scale/Scope**: Same single-store pre-order volume as today.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. `created_at` is added to the one existing `present()` method every preorder response already flows through — not a second response-shaping path. The shipping slip reuses the store-identity and customer data already assembled by `BuildsInvoiceDocument`/`present()` — no new data-assembly path is introduced for it.
- **II. Testing Standards** — PASS (planned). New Feature test confirming `created_at` appears in the invoice payload; new component tests for the two-column header, the QR/bank payment split, and the shipping slip's fulfillment-based visibility. Real-browser check for the full redesigned layout.
- **III. User Experience Consistency** — PASS. All new layout uses existing `@theme` token classes (no raw hex), and the same client-rendered-document convention (no new backend template, no new download endpoint) already established across every document in this product.
- **IV. Security** — PASS. No new authorization surface — the shipping slip and two-column header expose only data the invoice already returns to the same audience (whoever can already open this invoice).
- **V. Performance & Optimization** — PASS. No new query — `created_at` is already loaded on every `Preorder` model instance (a standard Eloquent attribute), and the shipping slip's "item type" list is derived client-side from `invoice.items`, already present in the payload.

No violations — **Complexity Tracking is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/024-invoice-layout-shipping-slip/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── invoice-created-at.md
└── tasks.md   # /speckit-tasks — not created by /speckit-plan
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/
└── PreorderController.php                 # MODIFIED — present() gains created_at

resources/js/
├── components/preorder/
│   ├── PreorderInvoiceModal.vue            # MODIFIED — two-column header, two-column payment terms, shipping slip section, title, creation date
│   └── PreorderPaymentReceiptModal.vue     # MODIFIED — same changes, mirrored (shares the invoice shell per feature 022/023 precedent)
└── locales/{id,en}.json                     # MODIFIED — new labels (To:, From:, Shipping slip, Item type, Created, column headers already partly present)

docs/openapi-pos-mvp.yaml                    # MODIFIED — Preorder/invoice response schema gains created_at

tests/Feature/
└── PreorderInvoiceCreatedAtTest.php         # NEW
```

**Structure Decision**: Existing single web application. No new backend classes, no new routes, no new database migration — this is the smallest-footprint feature in this session's history, entirely a frontend layout change plus one missing field.

## Complexity Tracking

*No violations — table intentionally omitted.*
