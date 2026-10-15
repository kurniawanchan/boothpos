# Implementation Plan: Duplicate and Split Pre-orders

**Branch**: `027-preorder-duplicate-split` | **Date**: 2026-10-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/027-preorder-duplicate-split/spec.md`

## Summary

Add two actions to the Pre-orders screen: **Duplicate** (one or many selected orders → one fresh "Ordered" order each, re-priced at today's catalog price, no payments/shipment) and **Split** (move chosen units of a not-yet-closed, unpaid pre-order into a new pre-order, or one-click "split by seller"). Both are server-side operations in `PreorderService`; the duplicate is built *on top of* the existing `create()` so pricing, validation, numbering and DEMO/LIVE stamping keep a single implementation, and the split is one transaction that re-parents or shrinks item rows without touching stock. Origin is recorded on the new order (`source_*` columns) and shown in the detail panel. Two new endpoints (`POST /preorders/duplicate`, `POST /preorders/{id}/split`), no change to existing routes' shapes beyond additive `source`/`split_children` fields on the pre-order payload. See [research.md](./research.md) for Decisions 1–8.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel (existing), Vue 3 + Pinia + Vite (existing)

**Primary Dependencies**: None new. Reuses `PreorderService`, `ActivityLogger`, `BaseModal`, `PreorderRowActions`, `vue-i18n`

**Storage**: MySQL 8 — one additive migration on `preorders` (three nullable origin columns). No new table

**Testing**: PHPUnit feature tests in `tests/Feature/` against real MySQL (`boothpos_test`); Vitest component tests in `qa-tests/`; real-browser check of the Pre-orders screen (Constitution II)

**Target Platform**: Single-store local install (native or Docker), browser SPA over `localhost`

**Project Type**: Web application (Laravel API + Vue SPA, same repo)

**Performance Goals**: Duplicate of 20 selected orders in < 30 s (SC-002) — sequential per-order transactions, items eager-loaded once; split of one order well under 1 s

**Constraints**: All money computed server-side; per-order failures must not abort a bulk duplicate; split is all-or-nothing and must not change stock; Indonesian code comments/commit messages; `docs/openapi-pos-mvp.yaml` updated in the same commit as the routes

**Scale/Scope**: Booth-sized data (tens to low hundreds of pre-orders per event); bulk duplicate capped at 100 ids per request

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 design — still passes.*

| Principle | Assessment |
|---|---|
| I. Clean code / DRY / single write path | **Pass.** `duplicate()` calls `create()` (pricing, discount cap, pickup-day/courier rules, numbering, mode stamping stay in one place). Origin logging goes through `ActivityLogger` only. Stock is never written by either action, so `StockService::applyMovement()` stays the only stock path. Logic lives in `PreorderService`, controller only validates/delegates/shapes. |
| II. Testing | **Pass (planned).** Feature tests for both services/endpoints incl. money reconciliation, stock invariance, mode isolation, authz; Vitest for the modal + row actions; browser verification listed in quickstart.md. |
| III. UX consistency | **Pass, one nuance.** Tokens/components reused, no raw hex; 409/422/403 convention followed. The "hide, don't disable" rule is about *role* gating — there is no role gating here (same access as create/edit). The *state*-based disabled split action with an explanatory `title` (spec FR-012a, user-approved) follows the existing precedent of "Payment invoice" disabled until a payment exists (`rowActions()`); Split is *hidden* for handed-over/cancelled, matching how Edit/Delete are hidden when always-rejected. UI copy in both locale files; comments/commits in Indonesian. |
| IV. Security | **Pass.** Server re-prices (duplicate) and re-derives every amount (split); no client-supplied money. Authorization enforced server-side (same gate as `store`/`update`: authenticated; verified against all three mechanisms — no policy/inline gate exists for pre-order CRUD). Item/payment snapshots preserved: split keeps `sell_price`/`cost_price`/`artist_id`; duplicate takes fresh snapshots by design (spec Q1). Audit rows written inside the same transaction as the mutation. |
| V. Performance | **Pass.** Source orders loaded with `items` in one query; no per-row lazy loads; new relation `splitChildren` eager-loaded only by `show()`/action responses, never the list. |
| Stack constraints | MySQL 8 only; migration prefix `2026_10_30_000001` sorts after the last existing one (`2026_10_29_000001`). No push/remote action involved. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` updated with the two routes and the new payload fields in the same commit; PRD gets a dated note (new capability, not a cut item). |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/027-preorder-duplicate-split/
├── plan.md              # This file
├── research.md          # Phase 0 — Decisions 1–8
├── data-model.md        # Phase 1 — columns, state rules, split algorithm
├── quickstart.md        # Phase 1 — manual + browser verification script
├── contracts/
│   └── api-deltas.md    # Phase 1 — the two endpoints + additive payload fields
├── checklists/requirements.md
└── tasks.md             # Phase 2 — created later by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/PreorderController.php   # + duplicate(), split(); present() gains source/split_children
├── Http/Requests/DuplicatePreordersRequest.php   # NEW — ids shape
├── Http/Requests/SplitPreorderRequest.php        # NEW — mode + moves shape
├── Models/Preorder.php                           # + fillable source_*, sourcePreorder()/splitChildren()
└── Services/PreorderService.php                  # + duplicate(), split(), splitBySeller()

database/migrations/
└── 2026_10_30_000001_add_source_to_preorders_table.php   # NEW

lang/{en,id}/preorders.php                        # + error messages
routes/api.php                                    # + 2 routes (static one BEFORE apiResource)
docs/openapi-pos-mvp.yaml                         # + routes, payload fields

resources/js/
├── api/preorders.js                              # + duplicatePreorders(), splitPreorder()
├── components/preorder/PreorderSplitModal.vue    # NEW
├── components/preorder/PreorderDuplicateResultModal.vue  # NEW (bulk summary)
├── views/PreordersView.vue                       # row actions, bulk bar button, detail buttons, "source" line
└── locales/{en,id}.json                          # + preorders.* keys

tests/Feature/
├── PreorderDuplicateTest.php                     # NEW
└── PreorderSplitTest.php                         # NEW
qa-tests/component/
├── PreorderSplitModal.test.js                    # NEW
└── PreordersView.test.js                         # + duplicate/split wiring cases
```

**Structure Decision**: Existing single Laravel + Vue web app; new behaviour lives in `PreorderService` (business rules), two thin controller actions, and two new modal components beside the existing `components/preorder/*`. No new top-level directories.

## Complexity Tracking

No constitution violations to justify.
