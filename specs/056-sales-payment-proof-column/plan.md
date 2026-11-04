# Implementation Plan: Sales Payment Proof Visibility & List Column Refinements

**Classification**: INTERNAL

**Branch**: `092-sales-payment-proof-column` | **Date**: 2026-11-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/056-sales-payment-proof-column/spec.md`

## Summary

> **Addendum (2026-11-05)** — stories US7–US9 added (toggle to show/hide all references + proofs, full Excel export with embedded images, seller-scoped statistics). Their design is in research.md D9–D12; the summary below covers US1–US6 only.

Six small changes to the Sales page, one tiny backend addition. (1) The sale detail shows a thumbnail of each non-cash payment's current proof (click → the existing full-size viewer). (2) The list gets a "Payment Proof" link under the transaction number, driven by ONE new server-computed row field `payment_proof_count` (viewable current proofs, permission decided by the existing `Payment::proofViewableBy()`); the link opens a lightweight dialog that loads the sale with the existing `GET /orders/{id}` and reuses the same thumbnail component — no new endpoint, no image loaded by the list. (3) `sales_reference.link` is relabelled "Sale Reference". (4) A positive "Verified" pill in the Payment column, derived client-side from fields the rows already carry. (5) Total column moves before Margin. (6) A Sale Reference filter (all / has / none) over the existing `has_reference` row field, wired into `useSalesFilters` and the URL like every other filter. Only the list payload and OpenAPI change on the server; no migration.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API); Vue 3 + Pinia + Vite (SPA)

**Primary Dependencies**: existing only — no new package. Reuses `ImageLightbox` (gallery mode, 055), `getPaymentProofBlobUrl`, `PaymentHistoryList`, `StatusPill`, `BaseSelect`.

**Storage**: MySQL 8, unchanged. No migration. Proof files stay on the private `local` disk, served only through `GET /payment-proofs/{id}/file`.

**Testing**: PHPUnit feature tests on real MySQL (`tests/Feature/`); Vitest in `qa-tests/`; real-browser check (constitution II).

**Target Platform**: localhost per-store install (browser SPA + local API)

**Project Type**: web application (Laravel API + Vue SPA, one machine)

**Performance Goals**: list load within 10% of today (spec SC-007); zero proof images fetched by the list (SC-003).

**Constraints**: permission decided server-side only; list payload stays lean; `payments.proofs` eager-loaded once (no N+1); UI copy in en + id.

**Scale/Scope**: a booth event's Sales list (hundreds of rows); ≤ a handful of payments per sale.

## Constitution Check

*GATE: passed before research; re-checked after design — still passed.*

| Principle | Assessment |
|---|---|
| I Code quality / single source | Proof permission stays `Payment::proofViewableBy()` (one definition); the thumbnail is one component used by detail and list dialog; no second endpoint or formula. PASS |
| II Testing | Backend tests for the new field (permissions, superseded, cash, voided, no N+1); Vitest for link, thumbnail, pill, column order, filter, URL; real-browser check listed in quickstart. PASS (browser check to be performed at implement) |
| III UX consistency | Token classes only, no hex; links follow the existing "Sale Reference" link style; users without permission see nothing (no disabled hint). PASS |
| IV Security | Visibility decided server-side per request user; images remain behind the authorizing endpoint, fetched as blobs with the token; no new access path. PASS |
| V Performance | One extra eager load (`payments.proofs`) on the list; no per-row query; images only on demand; no new dependency. PASS |
| Docs discipline | `docs/openapi-pos-mvp.yaml` updated in the same commit as the row-field addition. PASS (task) |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/056-sales-payment-proof-column/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/sales-list-row.md
└── tasks.md            # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/Services/SalesTransactionsService.php        # + payments.proofs eager load, + payment_proof_count
docs/openapi-pos-mvp.yaml                         # + payment_proof_count on the Sales row schema

resources/js/
├── components/payment/PaymentHistoryList.vue     # + opt-in proof thumbnail (prop), reuses 'view-proof'
├── components/payment/PaymentProofThumbnail.vue  # NEW: blob-loaded thumbnail, placeholder on failure
├── components/sales/PaymentProofsModal.vue       # NEW: list dialog (getOrder → thumbnails → gallery)
├── components/sales/TransactionItemsModal.vue    # passes the new prop
├── composables/useSalesFilters.js                # + filters.reference, URL key `ref`
├── views/SalesView.vue                           # link, Verified pill, column order, filter control
└── locales/{en,id}.json                          # sales_reference.link, payment_proof.*, filter labels

tests/Feature/SalesPaymentProofListTest.php       # NEW
qa-tests/                                         # new + updated Vitest files (see tasks)
```

**Structure Decision**: existing Laravel + Vue layout; one new service-level field, three new/changed Vue components, no new route.

## Addendum design (US7–US9)

- **US9 (seller statistics):** frontend logic already exists (`part()` in `useSalesFilters.js`, `seller_items` per row). First task reproduces the reported behaviour (research D9); the export reuses the same per-seller figures server-side.
- **US8 (export):** `POST /reports/sales/transactions/export` keeps its key-based contract (+ optional `sellers[]`); rows come from one shared builder, images are embedded as drawings (pattern of `ProductCatalogExport`), proof images only for what the exporter may view, hard limit with a clear 422. No new dependency.
- **US7 (toggle):** frontend only. New `RowAttachments.vue` reusing `ReferenceThumbnails`, `PaymentProofThumbnail`, `ImageLightbox`; per-row lazy loading with a concurrency cap; toggle remembered in `localStorage`. No list-payload image data.
- No migration. Files: `app/Exports/SalesTransactionsExport.php` (new), `ReportController::exportSalesTransactions`, `SalesTransactionsService` (shared rows), `resources/js/components/sales/RowAttachments.vue` (new), `SalesView.vue`, `useSalesFilters.js`, `api/reports.js`, `docs/openapi-pos-mvp.yaml`, locales en/id.
- **Constitution re-check:** server-side permission for proofs kept (export reuses `Payment::proofViewableBy()`); money as numbers; OpenAPI in same commit; en+id copy; tests first; no PII added; private files read through the authorising code path, never via public URLs.

## Phases

- **Phase 0** → [research.md](research.md): decisions D1–D8, no open clarifications.
- **Phase 0 addendum** → research.md D9–D12.
- **Phase 1** → [data-model.md](data-model.md), [contracts/sales-list-row.md](contracts/sales-list-row.md), [quickstart.md](quickstart.md).
