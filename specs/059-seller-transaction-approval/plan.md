# Implementation Plan: Transaction Approval & Seller Audit

**Classification**: INTERNAL

**Branch**: `095-seller-transaction-approval` (from `develop`; spec dir number ≠ branch number) | **Date**: 2026-11-05 | **Spec**: [spec.md](./spec.md)

## Summary

Each POS sale gets **one approval requirement per unique seller** among its items. A seller (or an owner/admin on their behalf) approves by drawn signature, uploaded PNG/JPG, or an APPROVED stamp. The overall status (Pending / Partially / Fully Approved + "X/N") is **derived, never stored**. Approvals are append-only rows in a new `order_approvals` table; one active row per (sale, seller) is guaranteed by the database (`active_slot` unique, the pattern of `seller_settlements`).

Approach, in one line each (reasoning in [research.md](./research.md)):

- **One calculator** `App\Support\OrderApprovalSummary` (pure, like `PaymentSummary`): requirements from current lines, per-seller state, overall state, and the **content hash** of a seller's lines.
- **One writer** `App\Services\OrderApprovalService` (row lock on the order, one transaction, one `ActivityLogger` row, file cleanup on rollback) — mirrors `OrderReferenceService` / `SellerSettlementService::acknowledge()`.
- **Invalidation is derived**: an approval is valid only while its stored content hash equals the seller's current lines. No hook is needed in a future line-edit path for correctness; `reconcile()` persists + logs the invalidation at the next write.
- **List**: the Sales rows already feed a client-side filter; each row gets a small `approval` summary (counts + per-seller facts, never an image), so the icon, popover and filter need no extra request.
- **Detail**: new Approval section in `TransactionItemsModal` backed by `GET /orders/{id}/approvals`; drawing reuses `settlement/SignaturePad.vue`.
- **Permission**: new named action `sales.approve` (registry + route middleware + coverage test) AND an eligibility rule (seller's linked user, or owner/admin).

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API), Vue 3 + Pinia + Vite (SPA), Tailwind v4 tokens

**Primary Dependencies**: existing only — no new package. Drawing: in-repo `SignaturePad.vue` (pointer events). No new frontend dependency.

**Storage**: MySQL 8 — one new table `order_approvals`; images on the private `local` disk under `order-approvals/` (UUID names). Nothing added to `orders`/`order_items`.

**Testing**: PHPUnit feature tests in `tests/Feature/OrderApproval/` against MySQL (`boothpos_test`; inside Docker only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`); Vitest under `qa-tests/` (component + `useSalesFilters` unit); real-browser check (Constitution II) — browser tooling was unavailable in earlier sessions, so this is tracked as an explicit open task.

**Target Platform**: local single-machine install (native or Docker); desktop browser + Android touch.

**Project Type**: web application (Laravel API + Vue SPA, one repo).

**Performance Goals**: Sales list stays one request; approval data adds **0 extra queries per row** (one eager load per page) — asserted by a query-count test (Constitution V).

**Constraints**: client never trusted (only an `items_hash` the server itself produced is echoed back, and it can only make the request fail, never succeed); GET never writes; images never in list payloads; DEMO/LIVE clone must still work (no ids inside the hash).

**Scale/Scope**: a booth event ≈ hundreds of sales, ≤ ~10 sellers per sale. Backend ≈ 1 migration, 1 model, 1 support class, 1 service, 1 controller (+2 requests), 3 routes; SPA ≈ 5 components + 1 composable change.

## Constitution Check

*GATE: passes before research and again after design.*

| Principle | How this plan complies |
|---|---|
| I. Single write path, logic in services | `OrderApprovalService` is the only writer of `order_approvals`; calculations only in `OrderApprovalSummary`; controller validates/delegates. Reuses `ActivityLogger`, `SignaturePad.vue`, the private-file pattern; no second hash/status formula in JS (the SPA only displays server fields). |
| II. Verify, don't assume | TDD feature tests incl. concurrency/idempotency, permission matrix, mutation-style checks on the hash rule; Vitest; real-browser task T-final (drawing on desktop + Android) stays open until actually run. |
| III. UX consistency | Token classes only, no hex (stamp artwork uses theme tokens); 422/409/403 convention (409 `already_approved`, `approval_stale`, `order_voided`; 403 not eligible); controls the role cannot use are **hidden** (`auth.canDo('sales','approve')` + server-computed per-seller `can_approve`); id/en locale keys + `localeKeys` guard. |
| IV. Security | Every rule enforced server-side per request (permission, eligibility, seller ownership, not voided, not already approved, fresh hash); private disk, content-sniffed type, size cap, authorizing image endpoint, `no-store`; audit row in the same transaction; append-only (no update/delete route); not-widening w.r.t. 057/058. |
| V. Performance | Eager-load approvals once for the Sales page; image endpoint is per-file; SignaturePad already in a lazy settlement chunk — the approval dialog is dynamically imported so the Sales bundle does not grow (checked in build output). |
| Docs discipline | OpenAPI updated in the same commit as routes; dated PRD/CLAUDE.md notes; `CloneRegistry` entry + drift test; `MenuActions` registry + coverage test. |

No violations → Complexity Tracking empty.

## Project Structure

### Documentation (this feature)

```text
specs/059-seller-transaction-approval/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── approvals-api.md
├── checklists/requirements.md
└── tasks.md            # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_11_25_000001_create_order_approvals_table.php

app/
├── Models/OrderApproval.php                       # append-only; no HasDataMode (rides orders)
├── Models/Order.php                               # + approvals() relation
├── Support/OrderApprovalSummary.php               # requirements, hash, per-seller + overall status (pure)
├── Support/MenuActions.php                        # sales named += 'approve'
├── Services/OrderApprovalService.php              # ONLY writer: approve(), reconcile(), history()
├── Services/SalesTransactionsService.php          # row gets `approval` summary (eager-loaded once)
├── Services/DemoClone/CloneRegistry.php           # + order_approvals (via orders.order_id, files)
├── Http/Controllers/Api/OrderApprovalController.php   # index, store, image
├── Http/Requests/StoreOrderApprovalRequest.php
└── Http/Resources/…  (none: hand-built presenter inside the service, like OrderReferenceService::present())
routes/api.php                                     # 3 routes, `ability:sales,approve` on POST
lang/{id,en}/approvals.php  (+ permissions action label)

resources/js/
├── api/orderApprovals.js
├── components/sales/
│   ├── OrderApprovalSection.vue                   # detail section (seller rows, status, history)
│   ├── ApproveSellerModal.vue                     # method tabs + confirm (dynamic import)
│   ├── ApprovalStatusIcon.vue                     # small list icon
│   ├── ApprovalPopover.vue                        # click popover (teleported, Esc/focus)
│   └── ApprovalStamp.vue                          # APPROVED stamp artwork (tokens)
├── components/settlement/SignaturePad.vue         # reused as is (strings via prop if needed)
├── composables/useSalesFilters.js                 # + filters.approval (`appr` URL key)
├── views/SalesView.vue                            # + filter control, icon in TRX cell
├── components/sales/TransactionItemsModal.vue     # + Approval section
└── locales/{en,id}.json                           # approvals.*

tests/Feature/OrderApproval/                        # calculator, service, permissions, concurrency, list, clone
qa-tests/…                                          # component + useSalesFilters tests
docs/openapi-pos-mvp.yaml                           # same commit as routes
```

**Structure Decision**: web application inside the existing Laravel + Vue tree; every new file sits next to its closest sibling feature (`OrderReferenceService`, `SellerSettlementService`, `components/sales/*`).

## Complexity Tracking

None.
