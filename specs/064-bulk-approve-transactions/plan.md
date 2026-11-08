# Implementation Plan: Bulk Approve Transactions

**Classification**: INTERNAL

**Branch**: `094-bulk-approve-transactions` | **Date**: 2026-11-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/064-bulk-approve-transactions/spec.md`

## Summary

A **Bulk approve** action on the Sales list approves many POS sales at once with ONE stamp, ONE drawn signature or ONE
uploaded image. It is a batch entry point over feature 059 — no new approval rules.

Approach (details and rejected alternatives in [research.md](./research.md)):

- **Two calls, preview then apply.** `POST /orders/approvals/bulk/preview` (read-only, JSON) returns what WOULD be approved
  (counts, sellers, amount, per-order skip reasons) plus a per-seller `items_hash` "plan" — the exact content the user is
  about to approve. `POST /orders/approvals/bulk` (multipart: `method`, `image`, JSON `order_ids`, JSON `plan`) records it.
  The server recomputes eligibility for every id at apply time; the plan hashes are only a freshness reference.
- **`OrderApprovalService` stays the ONLY writer.** `approve()` is split so its record-one-seller body
  (`recordSeller()`) is shared with a new `approveBatch()`. Each ORDER is one transaction that approves all its eligible
  sellers (all-or-nothing per sale, independent across sales; owner/admin on behalf can approve several sellers of one sale).
- **One stored file per batch**, shared by every approval row of that batch (`image_path` repeated). Approvals are
  append-only and no delete path exists, so sharing cannot orphan or break a record; it avoids 200 × 5 MB copies.
- **Traceability without a new table**: nullable `order_approvals.bulk_ref` (UUID, one per batch) + each approval's existing
  `order_approved` activity log carries it + one aggregate `order_bulk_approved` log row (who/method/counts).
- **Frontend**: a "Bulk approve (N)" button next to the existing bulk "Mark verified" using the SAME selection; a
  `BulkApproveModal` (choose → preview/confirm → result) that reuses the method picker extracted from `ApproveSellerModal`.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (API); Vue 3 + Vite + Pinia SPA (no TypeScript)

**Primary Dependencies**: existing only — `OrderApprovalService`, `OrderApprovalSummary`, `ActivityLogger`, `SellerGate`/`SellerScope`, `MenuActions`; frontend `SignaturePad`, `BaseModal`, `useSalesFilters`. **No new package.**

**Storage**: MySQL 8 — one additive nullable column + index on `order_approvals` (`bulk_ref`); signature/upload files on the private `local` disk (`order-approvals/<uuid>.png|jpg`), unchanged

**Testing**: PHPUnit feature tests under `tests/Feature/` against real MySQL (never SQLite; run only with the explicit `-e` overrides inside Docker — see CLAUDE.md); Vitest under `qa-tests/`; mandatory real-browser check (Constitution II)

**Target Platform**: single-machine store install (native or Docker); desktop browser + Android tablet/phone (touch drawing)

**Project Type**: web application (Laravel API + Vue SPA in one repo)

**Performance Goals**: 50 sales stamp-approved in < 30 s end to end (SC-001); 200-sale batch finishes inside one request; preview and apply query counts must NOT grow per order beyond the unavoidable per-order write transactions

**Constraints**: one batch ≤ `config('approvals.bulk_max')` = 200 orders (env `APPROVALS_BULK_MAX`); image ≤ 5 MB, PNG/JPEG sniffed from content (unchanged); controller raises the PHP time limit for the apply request only (pattern of the Sales export); `max_input_vars` safe because `plan`/`order_ids` travel as ONE JSON field each

**Scale/Scope**: a booth event's Sales list (hundreds of rows); ~1 migration, 2 routes, 1 service method pair, 1 request class, 1 modal + 1 extracted component, 1 API-client pair, locale keys (en/id), OpenAPI

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How |
|---|---|---|
| I. Code quality / single write path | PASS | `OrderApprovalService` remains the only `order_approvals` writer; one shared `recordSeller()`; no second eligibility formula (uses `OrderApprovalSummary` + `mayApproveFor`); method picker extracted, not copied |
| II. Testing | PASS (gate at implement) | Feature tests incl. mixed-eligibility, concurrency/duplicate, crafted ids, seller scope, query-count; Vitest for dialog; real-browser check incl. drawing is a tracked task — NOT claimable as done without it |
| III. UX consistency | PASS | Tokens only; `403/409/422` conventions; button hidden (not disabled) for users who can never approve (`can_approve_sellers`, server-computed); UI copy via en/id locale files like the rest of the app |
| IV. Security | PASS | Permission, seller ownership, voided, cycle, freshness all server-side per order; client ids/plan never trusted; ids resolved through scoped models (foreign → skipped, nothing revealed); files private; log in the same transaction as each approval |
| V. Performance | PASS | Preview/apply load `items`+`approvals` for all ids in ONE eager query; dialog + SignaturePad stay lazy chunks; list payload unchanged |
| Docs discipline | PASS | OpenAPI updated in the same commit; PRD/CLAUDE.md dated notes at implement |

**Re-check after design (Phase 1)**: unchanged — no violation, Complexity Tracking empty.

**Deliberate, documented deviation from a convention**: bulk `order_bulk_approved` aggregate log row is written after the per-order transactions (it summarises them); each approval's OWN log is still in its own transaction. See research D6.

## Project Structure

### Documentation (this feature)

```text
specs/064-bulk-approve-transactions/
├── plan.md              # This file
├── research.md          # Phase 0 — decisions D1–D10
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1 — manual + automated verification
├── contracts/
│   └── bulk-approvals.md   # Phase 1 — 2 endpoints + /auth/me addition
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks (NOT created here)
```

### Source Code (repository root)

```text
app/
├── Services/OrderApprovalService.php            # EDIT: recordSeller() extracted; + previewBatch(), approveBatch(), mayApproveAny()
├── Http/Controllers/Api/OrderApprovalController.php   # EDIT: + bulkPreview(), bulkStore()
├── Http/Requests/BulkOrderApprovalRequest.php   # NEW: shape rules shared by preview/apply (ids ≤ cap, method, JSON fields)
├── Http/Controllers/Api/AuthController.php (or /auth/me presenter)  # EDIT: + can_approve_sellers
└── Models/OrderApproval.php                     # EDIT: bulk_ref in $fillable/casts
config/approvals.php                             # NEW: bulk_max
database/migrations/2026_11_26_000001_add_bulk_ref_to_order_approvals.php   # NEW (date AFTER the latest, 2026_11_25)
lang/en/approvals.php, lang/id/approvals.php     # EDIT: skip reasons, aggregate log text
routes/api.php                                   # EDIT: 2 routes beside /orders/verify-payments (before {order} routes)
docs/openapi-pos-mvp.yaml                        # EDIT same commit (contract)

resources/js/
├── api/orderApprovals.js                        # EDIT: previewBulkApproval(), bulkApprove()
├── components/sales/ApprovalMethodPicker.vue    # NEW: extracted from ApproveSellerModal (tabs + pad + upload + stamp)
├── components/sales/ApproveSellerModal.vue      # EDIT: uses the picker (behaviour unchanged; its tests must stay green)
├── components/sales/BulkApproveModal.vue        # NEW: choose → confirm(preview) → result
├── views/SalesView.vue                          # EDIT: button + wiring next to bulk verify, refresh after result
├── stores/auth.js                               # EDIT: expose can_approve_sellers
└── locales/en.json, id.json                     # EDIT

tests/Feature/
└── OrderApproval/                               # existing folder + OrderApprovalFixture; NEW Bulk*Test.php files
    ├── BulkPreviewTest / BulkApproveStampTest / BulkApproveImageTest / BulkApproveValidationTest
    ├── BulkEligibilityTest / BulkOwnerOnBehalfTest / BulkFreshnessTest / BulkApproveSecurityTest
    └── BulkLimitTest / BulkTraceabilityTest / BulkQueryCountTest / CanApproveSellersFlagTest
qa-tests/component/
├── BulkApproveModal.test.js, ApprovalMethodPicker.test.js, SalesBulkApprove.test.js   # NEW
└── ApproveSellerModal.test.js                   # existing — must pass UNCHANGED after the picker extraction
```

**Structure Decision**: existing Laravel + Vue web-app layout; logic in `app/Services`, thin controller, one new FormRequest, no new top-level directories.

## Phase summary

- **Phase 0** — [research.md](./research.md): D1 preview+plan, D2 per-order transaction, D3 one shared file, D4 `bulk_ref` instead of a batch table, D5 eligibility, D6 logging, D7 limits/time, D8 permission/visibility of the button, D9 frontend reuse, D10 non-goals.
- **Phase 1** — [data-model.md](./data-model.md), [contracts/bulk-approvals.md](./contracts/bulk-approvals.md), [quickstart.md](./quickstart.md).

## Complexity Tracking

No Constitution violations to justify.
