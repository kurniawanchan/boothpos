# Implementation Plan: Purchase Orders, Store Customization, Activity Log Screen, New Reports, POS Drafts, Per-Artist Opening Cash, Split Payment

**Branch**: `006-purchase-order-and-ops` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/006-purchase-order-and-ops/spec.md`

## Summary

Ten independent slices across six product areas. The two biggest surprises
from research, which reshape scope versus a naive reading of the spec:
(1) **split payment and payment notes are already ~80% built on the
backend** — `POST /orders` already accepts and validates a `payments[]`
array with cash-overpay guards, and `Payment.notes` is a real fillable
column already used by preorder payments; the POS checkout path
(`PosView.vue` → `PaymentPanel.vue`) is hardcoded to send exactly one
payment entry and never collects a note, so this is overwhelmingly a
frontend task, not a new data model; (2) **materials have no stock concept
today** — `StockService::applyMovement()` is scoped to `ProductVariant`
only, and `Material` has no `current_stock` column — so "receiving a
purchase order increases material stock" (spec FR-003) requires a genuinely
new, parallel stock-tracking mechanism for materials, mirroring the
existing `stock_movements` append-only pattern rather than reusing it
directly (a `ProductVariant` and a `Material` are different entities).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), JavaScript/Vue 3 (Composition API, `<script setup>`)

**Primary Dependencies**: Laravel (Sanctum, Eloquent), Vue 3, Pinia, vue-i18n, Vite, Tailwind v4 (CSS-first `@theme` tokens), `jspdf`/`html2canvas` (already a dependency, used by `ReceiptModal.vue`), `chart.js`/`vue-chartjs` (added in 005, available for any new report charts)

**Storage**: MySQL 8, local disk for uploaded images (existing `ImageUploadService`)

**Testing**: `php artisan test` (Feature tests, real MySQL), Vitest (`npm test`), manual browser verification per Constitution Principle II

**Target Platform**: Single local machine per store, `localhost`

**Project Type**: Web application — Laravel API (`app/`, `routes/api.php`) + Vue SPA (`resources/js/`)

**Performance Goals**: No new N+1 queries; Activity Log and the two new reports must page/filter server-side, not load-all-then-filter client-side, given they can grow large over a store's lifetime.

**Constraints**: No raw hex literals outside `resources/css/app.css` (Principle III) — the theme color picker is the one deliberate, narrow exception, and must write through the same `@theme` CSS-custom-property mechanism, never a parallel styling system; every new stock-affecting write path goes through one sanctioned service method (Principle I); every sensitive mutation (PO status change, PO delete, theme change) writes an `ActivityLogger` entry inside the same transaction (Principle IV).

**Scale/Scope**: 10 user stories, ~6 new DB tables/columns, ~15 new/changed backend endpoints, ~8 new frontend screens/components, single-store scale.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality**: Purchase order business logic (status transitions, stock/material-stock effects, payment recording) MUST live in a new `PurchaseOrderService`, mirroring `PreorderService`'s existing transition-guard pattern (`ValidationException` → `409`) — not inline controller logic. Material stock changes MUST go through one new sanctioned method (`MaterialStockService::applyMovement()` or equivalent), the material-scoped mirror of `StockService::applyMovement()`, never a direct `Material::increment()`. Split payment reuses `PaymentRecorder` unchanged — it is already a loop-friendly single-payment recorder; `OrderService::create()` already loops it. **PASS**.
- **II. Testing Standards**: Every new status transition, every new stock-affecting path (material receiving), and the split-payment/notes/draft/opening-cash-per-artist paths need Feature tests against real MySQL; every new screen needs a Vitest spec and a real-browser check. Given the scale of this feature, Phase 2 tasks MUST enumerate tests per user story rather than batching them, so each of the 10 stories remains independently verifiable. **PASS**.
- **III. UX Consistency**: Theme color picker is the sole point allowed to write a raw color value, and it must do so by setting the same CSS custom properties `@theme` already declares (`--color-brand`, `--color-brand-hover`, `--color-brand-active`) at the document-root level, not by introducing inline styles or a second theming system — see research.md R1. All new screens follow existing role-hiding (never disabled), Indonesian-first i18n, and the existing DataTable/BaseDrawer/BaseModal component vocabulary. **PASS**.
- **IV. Security**: Purchase Order, Activity Log, and both new reports are gated by `canManageMasterData()`/`canAccessMenu('reports')` respectively, matching Vendor/Material and the existing report tier. PO status transitions, deletes, and theme changes MUST write `ActivityLogger` entries inside the same transaction as the mutation. Money on PO line items and payment entries is always server-computed/validated, client values never trusted, matching every existing transactional flow. Per-artist opening cash amounts must sum-check server-side, not trust a client-supplied total. **PASS**.
- **V. Performance**: Activity Log and the purchases/stock-per-artist reports MUST paginate and filter server-side (Constitution explicitly flags large-history screens). PO invoice printing reuses the existing client-side `html2canvas`+`jsPDF` pattern (no new backend PDF dependency, no bundle growth beyond what 005 already introduced). **PASS**.

No violations requiring Complexity Tracking — the one deliberate exception (theme color as a runtime-set CSS custom property) is documented above and in research.md R1, not a silent violation.

## Project Structure

### Documentation (this feature)

```text
specs/006-purchase-order-and-ops/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api-contract.md
└── tasks.md              # Phase 2 output (/speckit-tasks — NOT this command)
```

### Source Code (repository root)

```text
database/migrations/
├── 2026_xx_xx_create_purchase_orders_tables.php   # purchase_orders, purchase_order_items
├── 2026_xx_xx_add_stock_to_materials_table.php     # materials.current_stock
├── 2026_xx_xx_create_material_stock_movements.php  # mirrors stock_movements for materials
├── 2026_xx_xx_add_draft_to_orders_status.php       # orders.status enum + draft-cart storage
├── 2026_xx_xx_create_pos_drafts_table.php          # saved cart snapshots
├── 2026_xx_xx_create_session_opening_cash_entries.php  # per-artist opening cash
└── 2026_xx_xx_add_notes_to_orders_payments.php      # payments.*.notes already exists; only orders-path validation changes, no migration needed there

app/
├── Models/
│   ├── PurchaseOrder.php, PurchaseOrderItem.php     # NEW
│   ├── MaterialStockMovement.php                    # NEW
│   ├── PosDraft.php                                 # NEW
│   └── SessionOpeningCashEntry.php                   # NEW
├── Services/
│   ├── PurchaseOrderService.php                      # NEW — status transitions, material stock, ActivityLogger
│   ├── MaterialStockService.php                      # NEW — sanctioned material stock write path
│   └── PosDraftService.php                           # NEW — thin, mostly CRUD + ownership checks
├── Http/Controllers/Api/
│   ├── PurchaseOrderController.php                    # NEW
│   ├── PosDraftController.php                         # NEW
│   ├── ActivityLogController.php                      # unchanged backend; frontend screen is new
│   └── ReportController.php                           # + purchases(), stockByArtist()
│   └── CashierSessionController.php                   # store()/summary() extended for per-artist entries
│   └── OrderController.php                            # StoreOrderRequest gains payments.*.notes
│   └── PreorderController.php                         # storePayment() already has notes; no change needed
│   └── SettingsController.php                          # theme_color, receipt_footer_text settings (existing bulk update route)

resources/js/
├── views/
│   ├── PurchaseOrdersView.vue                         # NEW — replaces/extends the Purchase (Pembelian) group's landing
│   ├── ActivityLogView.vue                             # NEW
│   ├── PurchasesReportView.vue or a new tab on ReportsView.vue
│   └── StockByArtistReportView.vue or a new tab on ReportsView.vue
├── components/
│   ├── purchaseOrders/PurchaseOrderDetailModal.vue, PurchaseOrderInvoice.vue  # NEW
│   ├── pos/PosDraftsPanel.vue                          # NEW
│   ├── payment/PaymentPanel.vue                        # extended: multiple entries, running balance, notes field
│   ├── settings/ThemeColorPicker.vue                    # NEW
│   └── layout/AppSidebar.vue                            # "Pembelian" group gains a Purchase Orders entry; new "Activity Log" entry
├── api/
│   ├── purchaseOrders.js, posDrafts.js, activityLog.js  # NEW
│   └── reports.js, settings.js, sessions.js, orders.js, preorders.js  # extended

tests/Feature/
├── PurchaseOrderTest.php, PurchaseOrderStockReceivingTest.php  # NEW
├── SplitPaymentTest.php, PaymentNotesTest.php                  # NEW
├── PosDraftTest.php                                             # NEW
├── SessionOpeningCashPerArtistTest.php                          # NEW
├── ActivityLogReportTest.php (extends existing coverage if any) 
├── PurchasesReportTest.php, StockByArtistReportTest.php         # NEW
└── ThemeAndReceiptSettingsTest.php                              # NEW

qa-tests/component/
└── one spec per new/changed component listed above
```

**Structure Decision**: Existing single web-app layout reused as-is. Ten
independent additive slices — no existing screen is removed, only extended
(Payment, Cashier Session, Settings, Vendor/Material's surrounding
navigation) or newly added (Purchase Orders, Activity Log, two reports, POS
drafts).

## Complexity Tracking

*No unjustified Constitution Check violations — table omitted.*
