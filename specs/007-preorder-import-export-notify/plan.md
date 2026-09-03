# Implementation Plan: Pre-order Import/Export, Printing, Email Notification & Search

**Branch**: `007-preorder-import-export-notify` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/007-preorder-import-export-notify/spec.md`

## Summary

Four independent additions to the existing Pre-order module: (1) search the pre-order list by customer name, (2) generate a status-appropriate printable invoice/receipt (client-side, mirroring the existing sales-receipt and PO-invoice pattern), (3) export/import pre-order transactions through the existing Excel master-data infrastructure (a new, separate workbook — not folded into the four-sheet master-data file), and (4) email the customer on status change (and on demand), backed by a new `PreorderNotification` audit table so failures are visible rather than silent. All four are additive: no existing pre-order route, model column, or status-transition rule changes shape.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Vue 3 (Composition API, `<script setup>`) — unchanged, same stack as every prior feature in this repo.

**Primary Dependencies**: `maatwebsite/excel` ^4.0 (already a dependency, used for export/import), Laravel's built-in `Illuminate\Mail` (net-new usage in this codebase — no `app/Mail` classes exist yet), `html2canvas` + `jspdf` (already used by `ReceiptModal.vue`/PO invoice, reused for the pre-order invoice/receipt).

**Storage**: MySQL 8 (existing `boothpos`/`boothpos_test` databases). One new table, `preorder_notifications` (audit trail of email attempts).

**Testing**: `php artisan test` (Feature tests, real MySQL, `RefreshDatabase`), Vitest under `qa-tests/`, real-browser verification via chrome-devtools MCP for every user-facing screen touched (Constitution II).

**Target Platform**: Same single-machine, no-cloud-tier deployment as the rest of BoothPOS — email delivery is the one part of this feature that reaches outside that machine, and it degrades gracefully (FR-013) when the shop hasn't configured outgoing mail.

**Project Type**: Web application (Laravel API + Vue SPA), existing structure — no new project/package boundary.

**Performance Goals**: No new list/report endpoint may introduce N+1 queries; export streams from the same eager-loaded query shape the list endpoint already uses.

**Constraints**: Export/import/email-resend restricted to owner/admin only (FR-015) — the existing `preorders` menu key is shared with cashier/inventory for the base CRUD, so these three new endpoints gate on `isOwnerOrAdmin()` inline (the same pattern `ReportController`/`CashierSessionController`/`PaymentProofController` already use), not a new menu key.

**Scale/Scope**: Single-store transaction volumes (tens to low hundreds of pre-orders per event) — no pagination-breaking assumptions.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality**: Print-document data assembly follows the existing `present()`-array-builder pattern (`PreorderController` already partially uses this shape); export/import reuse `GenericArrayExport`/the existing `Maatwebsite\Excel` facade calls rather than inventing a new export mechanism. Email sending is centralized in one new `PreorderNotifier` service (single sanctioned path for "send a pre-order notification"), called from both the status-change flow and the manual resend endpoint — not duplicated logic in two controllers. **PASS**.
- **II. Testing**: Every new endpoint gets a `tests/Feature/*Test.php`; the two screens touched (`PreordersView.vue` search box, a new "Kirim ulang notifikasi" action, print button) get real-browser verification. Email sending is tested against Laravel's `Mail::fake()` — no real SMTP dependency in CI/local test runs. **PASS**.
- **III. UX Consistency**: New UI copy in Indonesian; print output styled with existing design tokens (`app.css` `@theme` vars), matching `ReceiptModal.vue`'s token usage; export/import/resend controls hidden entirely (not disabled) for cashier/inventory roles, consistent with the "hidden means truly unavailable" rule. **PASS**.
- **IV. Security**: Export/import/resend gated server-side via `isOwnerOrAdmin()`, not just hidden client-side. Imported pre-orders always start at `ordered` (FR-010) — never let a spreadsheet forge a paid/handed-over status without the underlying payment/status-transition guards actually running, closing the same "spreadsheet as a free upgrade" risk class the constitution calls out for `ArtistPolicy`/license quota. Notification attempts are logged (audit trail), matching the "sensitive mutation → audit entry in the same transaction" principle — though a notification *send* is not itself a mutation of business data, so it is logged for operational visibility (FR-013/FR-016 "no silent failure"), not as a Principle IV audit requirement. **PASS**.
- **V. Performance**: Export query reuses the same eager-loaded (`customer`, `items`) shape as `index()` — no per-row lazy load. `html2canvas`/`jspdf` are already dynamically imported elsewhere; the pre-order invoice component reuses that same dynamic-import call, not a new synchronous bundle addition. **PASS**.

No violations requiring the Complexity Tracking table.

## Project Structure

### Documentation (this feature)

```text
specs/007-preorder-import-export-notify/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── contracts/
│   └── api-contract.md  # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks — not this command)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/
│   └── PreorderController.php          # + search param, invoice(), export(), resendNotification()
├── Http/Requests/
│   └── ImportPreordersRequest.php      # new
├── Services/
│   ├── PreorderService.php             # unchanged (status-transition already fires the notify hook via a new call site)
│   ├── PreorderExportImportService.php # new — mirrors MasterDataImportService's all-or-nothing convention, scoped to one workbook
│   └── PreorderNotifier.php            # new — single sanctioned path for "send a pre-order status/invoice email"
├── Mail/
│   └── PreorderStatusMail.php          # new Mailable
├── Models/
│   └── PreorderNotification.php        # new — audit row per send attempt
├── Exports/
│   └── PreorderExport.php              # new, thin — reuses GenericArrayExport's shape/pattern
└── Imports/
    └── PreorderImport.php              # new

database/migrations/
└── 2026_10_15_000001_create_preorder_notifications_table.php

resources/js/
├── api/preorders.js                    # + searchable list params, exportPreorders(), importPreorders(), resendNotification()
├── components/preorder/
│   └── PreorderInvoiceModal.vue        # new — mirrors PurchaseOrderDetailModal's client-PDF pattern
└── views/PreordersView.vue             # + search box, print button, export/import/resend controls (owner/admin only)

tests/Feature/
├── PreorderSearchTest.php
├── PreorderInvoiceTest.php
├── PreorderExportImportTest.php
└── PreorderNotificationTest.php

qa-tests/component/
└── PreordersView.test.js               # extended, not replaced
```

**Structure Decision**: Existing single Laravel-API + Vue-SPA structure, extended in place — no new top-level directory. Mirrors the file layout `006-purchase-order-and-ops` already established (a dedicated Service per concern, a Policy/inline-gate decision made explicit, tests co-located with the existing suites).

## Complexity Tracking

*No Constitution Check violations — table intentionally omitted.*
