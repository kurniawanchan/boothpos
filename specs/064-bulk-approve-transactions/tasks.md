---
description: "Task list for Bulk Approve Transactions"
---

# Tasks: Bulk Approve Transactions

**Classification**: INTERNAL

**Input**: `/specs/064-bulk-approve-transactions/` — plan.md, spec.md, research.md (D1–D10), data-model.md, contracts/bulk-approvals.md, quickstart.md

**Tests**: INCLUDED (Constitution II makes tests mandatory; write them first and watch them fail).

**Organization**: by user story. US1, US2, US3 are all P1: US1 builds the end-to-end path with the stamp, US2 adds the two image methods, US3 completes eligibility/skip rules and the owner/admin case. MVP = Phase 1–3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependency on an unfinished task)
- Backend tests: `tests/Feature/OrderApproval/` (existing folder + `OrderApprovalFixture`); MySQL only. Inside Docker ONLY with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`; never two runs at once. Remember `php artisan migrate` on the dev DB (stale schema answers 503).
- Frontend tests: `qa-tests/component/` or `qa-tests/unit/`; run `npx vitest run --exclude '.claude/**'`. `qa-tests/setup.js` already mocks `api/orderApprovals` — extend that mock for the two new functions.
- Code comments/UI copy/commit messages in Indonesian; locale keys in BOTH `en` and `id` (guard: `qa-tests/unit/localeKeys.test.js`).
- Constitution: `OrderApprovalService` stays the ONLY `order_approvals` writer; no second eligibility formula; hide (never disable) controls a role can't use.

---

## Phase 1: Setup

- [x] T001 Extend `tests/Feature/OrderApproval/OrderApprovalFixture.php` with bulk helpers: `makeSales(int $n, array $sellers)` (N completed POS sales with chosen seller sets), `bulkPlan(array $orderIds, User $as)` (calls preview and returns its `plan`), `fakePng()` (a REAL temp PNG, since `UploadedFile::fake()` reports MIME from the extension and the service sniffs content). Reuse the existing user/seller helpers.
- [x] T002 [P] Create `config/approvals.php` with `bulk_max` = `(int) env('APPROVALS_BULK_MAX', 200)` and a Indonesian docblock citing research D7 (same cap as `verify-payments` / `bulk-receipts`).
- [x] T003 [P] Add server messages to `lang/en/approvals.php` and `lang/id/approvals.php`: skip reason texts (`not_found`, `voided`, `already_approved`, `no_approvable_seller`, `changed`, `failed`), `bulk_limit_exceeded` (with `:max`), `bulk_approved_log` (aggregate row text), `bulk_via_suffix`.
- [x] T004 [P] Add `approvals.bulk_*` UI keys to `resources/js/locales/en.json` and `id.json` (button label with count, dialog title, steps, confirm body with transactions/approvals/sellers/amount, "nothing to approve", result headline, per-reason chips, skipped list title, limit message, "via bulk" history badge, processing text).

---

## Phase 2: Foundational (blocks every story)

**⚠️ No user story starts before this phase is done.**

- [x] T005 Migration `database/migrations/2026_11_26_000001_add_bulk_ref_to_order_approvals.php` per data-model.md: nullable `CHAR(36) bulk_ref` + index `idx_order_approvals_bulk_ref`; `down()` drops index then column. Date prefix must sort AFTER `2026_11_25_000001`; touch no other migration.
- [x] T006 [P] Add `bulk_ref` to `$fillable` in `app/Models/OrderApproval.php`. Then verify research open item 2: read `app/Services/DemoClone/` to see whether unlisted nullable columns are copied generically; if columns are whitelisted add `bulk_ref`, and extend `tests/Feature/DemoClone/` (existing approval-clone test) to assert `bulk_ref` and a SHARED `image_path` survive a clone. Run `CloneRegistryDriftTest` + `OrderApprovalCloneTest`.
- [x] T007 Regression net FIRST: run the whole existing `tests/Feature/OrderApproval` suite and record it green before touching the service.
- [x] T008 Refactor `app/Services/OrderApprovalService.php`: extract the body of the transaction in `approve()` that creates the row + writes the `order_approved` log into `private function recordSeller(Order $locked, array $seller, User $user, ?string $storedPath, ?string $mime, ?string $bulkRef): OrderApproval` (the `UniqueConstraintViolationException` → `already_approved` mapping stays inside it). `approve()` calls it with `bulkRef = null`; behaviour must not change. Add `bulk_ref` to the log's `newValues` only when set. Re-run T007's suite — must stay green.
- [x] T009 Add `public static function mayApproveAny(User $user): bool` (owner/admin, or `artist_id !== null`) to `OrderApprovalService`, next to `mayApproveFor()` (one definition, reused by T012 and the SPA flag).
- [x] T010 Verify research open item 1: read `app/Services/ActivityLogger.php` and its `activity_logs` columns for nullable `entity_id`; record the answer (and the chosen fallback if not nullable) as a one-line comment in the plan's research section.
- [x] T011 Create `app/Http/Requests/BulkOrderApprovalRequest.php`: `authorize()` = authenticated; `prepareForValidation()` decodes the `order_ids` / `plan` JSON strings (invalid JSON → 422, never 500); rules: `order_ids` array 1…`config('approvals.bulk_max')` of distinct integers; `method` `in:draw,upload,stamp` (apply only); `plan` array of `{order_id:int, sellers.*.artist_id:int, sellers.*.items_hash:string}` (apply only). Over-limit message names the limit (`bulk_limit_exceeded`). Preview and apply share the class (preview ignores `method`/`plan`).
- [x] T012 [P] Add `can_approve_sellers` (= `OrderApprovalService::mayApproveAny($user)`) to the `/auth/me` payload in `app/Http/Controllers/Api/AuthController.php` (next to `'permissions' =>` ~line 149); expose it in `resources/js/stores/auth.js`. Test: `tests/Feature/OrderApproval/CanApproveSellersFlagTest.php` (owner true, linked seller true, unlinked cashier false, seller-linked but deactivated seller → still true by rule).
- [x] T013 Routes in `routes/api.php` beside `POST /orders/verify-payments` and BEFORE any `/orders/{order}` route: `POST /orders/approvals/bulk/preview` → `OrderApprovalController@bulkPreview`, `POST /orders/approvals/bulk` → `@bulkStore`, both `->middleware('ability:sales,approve')`. Stub the two controller methods so the routes exist and validate (real logic lands in T022). Confirm `MenuActionsRouteCoverageTest` and `ForbiddenSurfacesTest` (classified scope-aware under `orders`) stay green.
- [x] T014 [P] Add to `resources/js/api/orderApprovals.js`: `previewBulkApproval(orderIds)` (JSON) and `bulkApprove({ orderIds, plan, method, image })` (multipart; `order_ids` and `plan` appended as `JSON.stringify` strings; image optional). Extend the `qa-tests/setup.js` mock of this module with both functions.
- [x] T015 Extract `resources/js/components/sales/ApprovalMethodPicker.vue` from `ApproveSellerModal.vue`: the method tabs, lazy `SignaturePad` (same `defineAsyncComponent` + fallback), file validation (PNG/JPEG, 5 MB, `accept`), stamp preview, `hasInk` tracking, and a `getImage()` exposed via `defineExpose` (draw → `toBlob()` File, upload → file, stamp → null). KEEP the `v-show` (not `v-if`) rule for the canvas across steps. `ApproveSellerModal` now renders the picker; its behaviour/DOM test ids (`method-*`, `approval-file`, `approval-continue`, `approval-confirm`, `approval-error`) must remain. `qa-tests/component/ApproveSellerModal.test.js` must pass UNCHANGED.

**Checkpoint**: column, request, routes, shared service seam, shared picker and API client exist; stories can start.

---

## Phase 3: User Story 1 — Approve many transactions with one stamp (P1) 🎯 MVP

**Goal**: select sales on the Sales list → Bulk approve → Stamp → server-computed confirmation → approvals recorded per eligible seller → result summary and refreshed list.

**Independent test**: as a user linked to Seller A, 10 pending sales containing A's items → select all → stamp → all 10 show A Approved (method stamp, approver, time, shared `bulk_ref`), overall status/progress updated, `approved + skipped = selected`.

### Tests for US1 (write first, must fail)

- [x] T016 [P] [US1] `tests/Feature/OrderApproval/BulkPreviewTest.php`: preview returns `selected`, `eligible_orders`, `approvals`, `amount` (Σ only the approvable sellers' subtotals, 2-dp string), per-seller breakdown, `skipped`, and a `plan` with the CURRENT `items_hash`; `selected = eligible + skipped`; writes nothing (no rows, no files, stale approvals NOT reconciled); no stamp/image fields needed.
- [x] T017 [P] [US1] `tests/Feature/OrderApproval/BulkApproveStampTest.php`: stamp bulk over N sales → one active approval per (order, linked seller), `method=stamp`, `image_path` NULL, one `bulk_ref` shared, `on_behalf=false`, overall status recalculated per sale, one `order_approved` log per approval; response matches contracts (`approved_orders`, `approved_sellers`, `results` in request order); already-approved sale → skipped `already_approved`, existing approval untouched; voided sale → skipped `voided`; repeating the identical request approves nothing new (idempotent).
- [x] T018 [P] [US1] `qa-tests/component/BulkApproveModal.test.js` (stamp path): opens on the choose step; Continue calls `previewBulkApproval`; confirm step shows transactions / approvals / seller names / amount FROM THE PREVIEW; "nothing to approve" state disables Confirm; Cancel/close at every step sends nothing; Confirm is single-flight (double click → one call); result step shows approved/skipped counts.
- [x] T019 [P] [US1] `qa-tests/component/SalesBulkApprove.test.js`: the "Bulk approve (N)" button appears only when `auth.canDo('sales','approve')` AND `can_approve_sellers`; is hidden (not disabled) otherwise; N = selected sale ids; after a result the list reloads and the selection keeps only rows still pending.

### Implementation for US1

- [x] T020 [US1] `OrderApprovalService::previewBatch(array $orderIds, User $user): array` — ONE query loading `Order` (scoped models) `with(['items','approvals'])` for all ids (no per-order lazy loads), then per order the eligibility from research D5 using `OrderApprovalSummary::for()` + `mayApproveFor()` (reasons `not_found`, `voided`, `already_approved`, `no_approvable_seller`); builds `plan` (order → approvable pending sellers → `items_hash`), `approvals`, `amount`, `sellers[]` breakdown. Pure read. Makes T016 pass.
- [x] T021 [US1] `OrderApprovalService::approveBatch(array $orderIds, array $plan, string $method, ?UploadedFile $image, User $user): array`: validate the image ONCE before any order is touched (reuse `storeImage()` rules — stamp path stores nothing here); generate `bulk_ref` (UUID); per order: `reconcile()` (own committed txn, as `approve()` does), then ONE `DB::transaction` → lock order → voided check → `reconcileLocked()` → recompute summary → for each eligible seller call `recordSeller()`; `UniqueConstraintViolationException`/`OrderApprovalException` → that order skipped (`already_approved`), any other `Throwable` → skipped `failed` (log the exception, return a generic reason); result array exactly as the contract (`results` in request order; counts add up). Makes T017 pass for stamp.
- [x] T022 [US1] `OrderApprovalController::bulkPreview()` / `bulkStore()` using `BulkOrderApprovalRequest`; thin: delegate, shape JSON, `set_time_limit(120)` in `bulkStore()` only (pattern of the Sales export); `OrderApprovalException` → `{message, code}` + status; always `200` with per-order reasons once validation passed.
- [x] T023 [US1] `resources/js/components/sales/BulkApproveModal.vue` (lazy-loaded): steps `choose → confirm → result`; uses `ApprovalMethodPicker` (all three methods wired, stamp is what US1 tests); Continue → `previewBulkApproval(orderIds)`; confirm step renders server numbers and the seller list; Confirm → `bulkApprove(...)` with `saving` flag + disabled buttons (single-flight); result step; `BaseModal`, design tokens only, no hex; phone-width layout; keyboard accessible (focus trap via the existing modal base).
- [x] T024 [US1] Wire `resources/js/views/SalesView.vue`: "Bulk approve (N)" button next to the bulk "Mark verified" (hidden unless `auth.canDo('sales','approve') && auth.user.can_approve_sellers`); `defineAsyncComponent` for the modal; pass `chosenOrderIds`; on result → `await load()` and shrink `selected` to rows still pending (same approach as `doBulkVerify`). Makes T018/T019 pass.

**Checkpoint**: US1 works end to end with the stamp — shippable MVP.

---

## Phase 4: User Story 2 — One drawn signature or uploaded image for the whole batch (P1)

**Goal**: the same signature/image is applied to every included approval; invalid input records nothing; one file per batch.

**Independent test**: 5 pending sales, bulk draw once; 5 others, bulk upload a PNG; each approval shows its method and the signature; blank/oversized/GIF → refused with zero rows and zero files.

### Tests for US2 (write first, must fail)

- [x] T025 [P] [US2] `tests/Feature/OrderApproval/BulkApproveImageTest.php`: draw and upload bulk → every approval row has the SAME `image_path` (exactly ONE file written on the `local` disk) and the right `method`; `can_view_image` is still per approval row (owner/admin, approver, linked seller yes; another role no) and `GET …/approvals/{id}/image` serves the shared file to the allowed viewer only; all-skipped batch leaves NO file on disk; a mid-batch per-order failure keeps the file because other rows reference it.
- [x] T026 [P] [US2] `tests/Feature/OrderApproval/BulkApproveValidationTest.php`: missing image for draw/upload, image for stamp, 0-byte, > 5 MB, GIF/PDF renamed `.png` (content sniffed), corrupt JSON in `order_ids`/`plan`, bad `method` → `422` with ZERO approval rows, ZERO files, ZERO activity logs; nothing is processed for any order (FR-007).
- [x] T027 [P] [US2] `qa-tests/component/ApprovalMethodPicker.test.js` + extend `BulkApproveModal.test.js`: blank draw → "signature required" and no preview call; upload wrong type / > 5 MB → message, no call; drawn/uploaded image survives choose → confirm → back (canvas stays mounted via `v-show`; `getImage()` yields a PNG File); stamp sends no image.

### Implementation for US2

- [x] T028 [US2] In `approveBatch()` store the validated image ONCE through the existing `storeImage()` logic and pass the same `$storedPath`/`$mime` to every `recordSeller()`; after the loop delete the file only when `approved_sellers === 0`; wrap so a thrown error before any order is processed deletes the file (pattern of `approve()`). Makes T025/T026 pass.
- [x] T029 [US2] Finish `BulkApproveModal.vue` for draw/upload: send `image` from `picker.getImage()` on Confirm (`toBlob()` null → back to choose with "signature required"), keep draft across steps, map `422 errors.image` to the dialog's inline error. Makes T027 pass.

**Checkpoint**: all three methods work for a batch.

---

## Phase 5: User Story 3 — Only eligible approvals are recorded; the rest are skipped and explained (P1)

**Goal**: mixed selections are safe; every skip has a reason; owner/admin approve every pending seller on behalf, per-order atomic; changed content is never approved.

**Independent test**: select pending + already approved + voided + other-seller-only + not-visible; as Seller A's user only A's pending requirements are recorded and every skipped sale is listed with its reason; counts add up.

### Tests for US3 (write first, must fail)

- [x] T030 [P] [US3] `tests/Feature/OrderApproval/BulkEligibilityTest.php`: mixed batch → exactly the eligible approvals; reasons `not_found` (foreign mode, deleted, seller-scope-invisible id), `voided`, `already_approved`, `no_approvable_seller`; `approved_orders + skipped_total = selected = results.length` over several random mixes; duplicate ids in the request are rejected by validation (distinct) — not double counted.
- [x] T031 [P] [US3] `tests/Feature/OrderApproval/BulkOwnerOnBehalfTest.php`: owner/admin bulk over a 3-seller sale → three approvals, `on_behalf=true`, approver ≠ seller; a failure injected for ONE order (force an exception inside its transaction) rolls back that order's approvals fully (no half-approved sale from this batch) while other orders stay approved and the result says `failed`; seller-linked user on a mixed sale → only own seller approved, sale becomes Partially Approved.
- [x] T032 [P] [US3] `tests/Feature/OrderApproval/BulkFreshnessTest.php`: preview → change one sale's seller lines (add/remove/alter qty via the model, as the fixture allows) → apply → that order `changed`, others approved; a seller that became newly pending after preview and is not in the plan → `changed`; a stale prior approval is reconciled inside the transaction and approvable again; a concurrent approval of one planned seller (simulated by approving between preview and apply) → that order skipped `already_approved`/`changed`, retry approves the remainder.
- [x] T033 [P] [US3] `tests/Feature/OrderApproval/BulkApproveSecurityTest.php`: a role without `sales.approve` → 403 `action_not_allowed`, nothing changes and no state revealed; non-admin user with no linked seller → every order skipped `no_approvable_seller`, nothing recorded; crafted ids of other sellers' sales/other mode → `not_found`, indistinguishable from missing; crafted `plan` hashes (wrong/forged/for another order) can only cause `changed`, never an approval; concurrent double apply (two sequential identical requests + a simulated race on the unique index) → exactly one active row per (order, seller) (SC-005).
- [x] T034 [P] [US3] Extend `qa-tests/component/BulkApproveModal.test.js`: result step lists skipped sales in a scrollable list with reason text (every reason key has an en/id string), grouped counts match the server numbers, "nothing to approve" before confirm (preview `approvals = 0`), error from a failed request leaves the dialog usable with a Retry and never claims success.

### Implementation for US3

- [x] T035 [US3] Complete `previewBatch()`/`approveBatch()` against the full D5 table: owner/admin → every pending seller of each order (per-order atomic, `recordSeller()` loop inside the one transaction); `changed` when a planned hash differs or an approvable pending seller is absent from the plan; `failed` on unexpected exceptions with a generic message (the real exception only in the log); `not_found` for anything the scoped `Order` query did not return. Makes T030–T033 pass.
- [x] T036 [US3] Finish the result step in `BulkApproveModal.vue` (reasons from locale keys, scrollable skipped list, counts that add up, Retry on error). Makes T034 pass.

**Checkpoint**: bulk approval is safe on messy real selections.

---

## Phase 6: User Story 4 — Convenient, bounded selection (P2)

**Goal**: works with row/page/all-filtered selection, honours the per-action limit, reflects the current selection.

**Independent test**: filter Approval = Pending, Select all → dialog counts equal the filtered rows (all pages); a selection over the cap shows the limit and records nothing.

### Tests for US4 (write first, must fail)

- [x] T037 [P] [US4] `tests/Feature/OrderApproval/BulkLimitTest.php`: `approvals.bulk_max + 1` ids → 422 on BOTH preview and apply, message states the limit, nothing written/stored; the config override (`config(['approvals.bulk_max' => 3])`) is honoured (limit is read from config, not a literal).
- [x] T038 [P] [US4] Extend `qa-tests/component/SalesBulkApprove.test.js`: with filters + `Select all` across pages the modal receives ALL filtered `order:` ids (not just the visible page); over the cap the dialog shows the limit and Continue is blocked client-side (server remains the authority); changing the selection while closed and reopening reflects the new ids; a seller-scoped user's "Select all" only yields visible sales.

### Implementation for US4

- [x] T039 [US4] Pass the cap to the SPA from one source (add `approvals.bulk_max` to `GET /settings/features` or reuse the 422 message — prefer the features payload so the dialog can warn before the request; update `tests/Feature/` features test) and use it in `BulkApproveModal.vue`/`SalesView.vue` for the pre-check; keep server validation as the boundary. Makes T037/T038 pass.
- [x] T040 [US4] Ensure the modal re-initialises on every open (selection snapshot taken at open, picker draft cleared, step reset) — no stale ids from a previous open; covered by T038.

**Checkpoint**: practical at event volume.

---

## Phase 7: User Story 5 — Bulk approvals are fully traceable (P2)

**Goal**: bulk records are ordinary approvals plus a traceable batch identity; no edit/delete exists.

**Independent test**: bulk approve 3 sales; as owner/admin each sale's history shows the entry, actor, seller, method, time and "via bulk"; skipped sales have no entries; no edit/delete route.

### Tests for US5 (write first, must fail)

- [x] T041 [P] [US5] `tests/Feature/OrderApproval/BulkTraceabilityTest.php`: every approval of one batch shares `bulk_ref`, a different batch has a different one, single approvals keep `bulk_ref = NULL`; each `order_approved` log carries `bulk_ref`; exactly ONE `order_bulk_approved` log per applied batch with counts/method/ref (and none for a batch that failed validation); `GET /orders/{id}/approvals` → `history[].via_bulk` true/false for owner/admin and the field/`history` absent for others; skipped orders have no approval rows or "approved" logs; no PUT/PATCH/DELETE route exists on `order_approvals` (extend the existing `AuditAndProtectionTest` assertion to the new URIs).
- [x] T042 [P] [US5] Extend `qa-tests/component/OrderApprovalSection.test.js` (or the existing section test file): history entries with `via_bulk` show the "via bulk" badge; entries without it don't.

### Implementation for US5

- [x] T043 [US5] Write the aggregate `order_bulk_approved` log at the end of `approveBatch()` (fallback entity per T010), include `bulk_ref`, method, `selected`, `approved_orders`, `approved_sellers`, `skipped_by_reason`; a failure writing it is logged and does NOT fail the response (research D6).
- [x] T044 [US5] Add `via_bulk` (= `bulk_ref !== null`) to the history entry shape in `OrderApprovalService::present()` (owner/admin history only); show a small badge in `resources/js/components/sales/OrderApprovalSection.vue` using the `approvals.bulk_via` locale key. Makes T041/T042 pass.

**Checkpoint**: audit story complete.

---

## Phase 8: Polish & cross-cutting

- [x] T045 [P] Update `docs/openapi-pos-mvp.yaml` in the SAME change: the two endpoints (request/response schemas, reasons enum, errors), `history[].via_bulk`, `/auth/me` `can_approve_sellers`, and (if T039 used it) the features field.
- [x] T046 [P] Dated notes (no rewritten history): PRD note under the approval section (bulk entry point added 2026-11-08, scope unchanged), and a `CLAUDE.md` section "Bulk approval (feature 064)" with the non-obvious rules (preview+plan freshness, per-order transaction, one shared file, `bulk_ref` not a table, `plan`/`order_ids` as single JSON fields, button gated by `can_approve_sellers`, picker extraction + `v-show` rule). Add the same to `docs/RUNBOOK.md` only if the migrate step needs mentioning.
- [x] T047 (measured 2026-11-08: 200 sales apply 1.58 s, preview 0.06 s, in-process on the test DB; see CLAUDE.md) Measure research open item 3: time a real 200-sale stamp batch and a 50-sale batch on the dev DB (counts only, throwaway data); record the numbers in research.md. If 50 sales take ≥ 30 s (SC-001) or 200 hit the PHP limit, reduce per-order overhead (e.g. skip the pre-transaction `reconcile()` when no active approval exists) and re-test. Do NOT claim SC-001/SC-002 without the measurement.
- [x] T048 [P] Query-count test `tests/Feature/OrderApproval/BulkQueryCountTest.php` (warm-up call first, then compare 5 vs 25 orders): preview issues a constant number of queries; the read part of apply does not grow per order (writes legitimately do); mutation-check by deliberately lazy-loading `items` once.
- [x] T049 [P] Run `npm run build` and confirm `BulkApproveModal` and `SignaturePad` remain separate lazy chunks and `SalesView`'s chunk did not grow beyond the button/wiring (Constitution V — inspect the build output, don't assume).
- [x] T050 Full runs with the exact in-session commands and results reported: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='OrderApproval|MenuActionsRouteCoverage|ForbiddenSurfaces|CloneRegistryDrift|DemoClone'`, then the full backend suite once (report the 9 known `Code128` failures as pre-existing), and `npx vitest run --exclude '.claude/**'`. `php artisan migrate` on the dev DB first.
- [ ] T051 **NOT EXECUTED (2026-11-08: Playwright/DevTools MCP failed to connect; dev DB not migrated).** Real-browser verification per quickstart.md (Constitution II): stamp / draw (mouse + trackpad) / upload batches, cancel at every step, double click Confirm, > 200 selection, nothing-eligible state, owner/admin 3-seller sale with "via bulk" in history, phone width, console clean. **Android touch drawing on a real device/emulator (SC-008).** Playwright/DevTools MCP were unavailable in earlier sessions — if still unavailable, report this task as NOT executed rather than done.
- [x] T052 Final review pass: `git diff` for any second writer of `order_approvals`, any client-trusted value, any hex literal, any English code comment/UI literal outside locale files; confirm `ApproveSellerModal` behaviour unchanged; update the plan/research "open items" with their resolved answers.

---

## Dependencies & execution order

- **Phase 1** → **Phase 2** (blocks all). Inside Phase 2: T005 → T006; T007 → T008 → (T009, T020…); T011 before T013; T015 before T023/T029; T014 before T023.
- **US1 (Phase 3)** needs all of Phase 2. **US2** needs US1's `approveBatch()` and modal (T021, T023). **US3** needs US1's service + modal. **US4** needs US1's modal/wiring. **US5** needs US1's `approveBatch()` (aggregate log) and `present()`.
- US2, US3, US4, US5 are independent of each other once US1 is in; they touch overlapping files (`OrderApprovalService.php`, `BulkApproveModal.vue`), so implement them sequentially or rebase carefully rather than in parallel branches.
- **Polish** last; T047 (timing) can run as soon as US1–US3 exist and informs any optimisation before T050.

## Parallel opportunities

- Phase 1: T002, T003, T004 together (T001 touches only tests).
- Phase 2: T006, T012, T014 in parallel after T005/T008 as noted; T015 (frontend) is independent of the backend tasks.
- Per story, all `[P]` test tasks together (different files). Frontend tests (T018/T019, T027, T034, T038, T042) are independent of backend tests.
- Polish: T045, T046, T048, T049 together.

## Implementation strategy

1. **MVP = Phase 1–3 (T001–T024)**: stamp-based bulk approval with preview, confirmation, result, button. Validate with T016–T019 and a stamp-only browser check before moving on.
2. Add **US2** (images) → **US3** (safety rules, owner/admin) next — all three are P1 and the feature should not ship without US3, because partial coverage of skip rules is the main risk.
3. **US4**, **US5** are P2 and can ship in the same release or follow it.
4. T051 (real browser incl. Android drawing) is the release gate for the touch path; do not mark the feature done without it or an explicit "not executed" report.

## Task count

52 tasks — Setup 4, Foundational 11, US1 9, US2 5, US3 7, US4 4, US5 4, Polish 8.
