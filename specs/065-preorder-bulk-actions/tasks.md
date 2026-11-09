---
description: "Task list for Pre-order Bulk Actions"
---

# Tasks: Pre-order Bulk Actions

**Classification**: INTERNAL

**Input**: `/specs/065-preorder-bulk-actions/` — plan.md, spec.md, research.md (D1–D13), data-model.md, contracts/bulk-preorders.md, quickstart.md

**Tests**: INCLUDED (Constitution II makes tests mandatory; write each story's tests first and watch them fail).

**Organization**: by user story. US1–US4 are P1, US5–US7 are P2. Phase 2 builds the shared path (extractions, orchestrator, request, routes, modal shell, result view) so every story is a thin slice on top. **MVP = Phase 1 + 2 + US1 + US2 + US4** (select, bulk status, honest results).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependency on an unfinished task)
- Backend tests: `tests/Feature/PreorderBulk/` (new folder + `PreorderBulkFixture` trait); MySQL only. Inside Docker ONLY with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test -e CACHE_STORE=array`; never two runs at once. Run `php artisan migrate` on the dev DB first (stale schema answers 503).
- Frontend tests: `qa-tests/component/` or `qa-tests/unit/`; run `npx vitest run --exclude '.claude/**'`. `qa-tests/setup.js` mocks API modules by default — extend the mock for the new `api/preorders.js` functions.
- Code comments, UI copy, commit messages in Indonesian; locale keys in BOTH `en` and `id` (guard: `qa-tests/unit/localeKeys.test.js`).
- Constitution: bulk NEVER copies a rule — it loops the writer the individual action uses; no second write path; hide (never disable) controls a role cannot use; OpenAPI in the same commit; 9 known `Code128` backend failures are pre-existing (report, don't hide).


> **Amendment 2026-11-09:** Phases 5 (US3 payment) and 8 (US6 shipments) were implemented and then REMOVED at the product owner's request: their routes, `PreorderBulkService` branches, forms (`BulkPaymentForm`, `BulkShipmentForm`, `bulkRules.js`), tests and docs are gone. `ShipmentService` (T007) stays as a refactor used by the individual endpoint. The ticked state below is the history of what was built.

---

## Phase 1: Setup

- [X] T001 Regression net BEFORE touching anything: run `--filter='Preorder|Shipment|PaymentLedger|MenuActionsRouteCoverage|ForbiddenSurfaces'` in Docker with the safe overrides and record the baseline (pass/fail counts) in the task notes.
  - _Note:_ Baseline: 362 passed (3538 assertions), 29.9 s.
- [X] T002 [P] Create `config/preorders.php` with `bulk_max` = `(int) env('PREORDER_BULK_MAX', 200)` and an Indonesian docblock citing research D10 (same cap as 064 / `bulk-receipts`).
- [X] T003 [P] Add server messages to `lang/en/preorders.php` and `lang/id/preorders.php`: one text per reason code (`not_found`, `invalid_transition`, `not_fully_paid`, `insufficient_stock`, `cancelled`, `not_mail_order`, `already_done`, `shipment_exists`, `missing_recipient`, `fully_paid`, `invalid_amount`, `exceeds_balance`, `already_recorded`, `rejected`, `failed`), `bulk_limit_exceeded` (with `:max`), and the four `preorder_bulk_*` audit descriptions plus the aggregate one.
- [X] T004 [P] Add `preorders.bulk_*` UI keys to `resources/js/locales/en.json` and `id.json`: action labels, select-all/“select all N matching”/clear/selected-count, dialog titles and steps, confirmation bodies, “nothing eligible”, result headline and per-reason chips, retry, processing text, unknown-outcome message, limit message.
- [X] T005 Create `tests/Feature/PreorderBulk/PreorderBulkFixture.php` (trait): `makePreorders(int $n, array $overrides)` in chosen statuses/fulfillment/payment states (through the real services, not raw inserts), `userWith(array $abilities)` (role with only the named `preorders` actions), `sellerScopedUser()`, a `bulkRequest()` helper that posts JSON to the 8 routes, and `shipmentPayload()`. Never name a helper `put`/`seed`/`get`/`call`.

---

## Phase 2: Foundational (blocks every user story)

**Extraction first — individual endpoints must behave exactly as before (research D1).**

- [X] T006 Extract the dispatch-marker rule from `PreorderController::updateDispatchStatus` into `PreorderService::setDispatchStatus(Preorder, string): Preorder` and `dispatchBlocker(Preorder, string): ?array` in `app/Services/PreorderService.php`; it throws the same `ValidationException(...)->status(409)`. The controller delegates. Do not change dates/rules.
- [X] T007 [P] Create `app/Services/ShipmentService.php` with `createFor(Preorder, array $data): Shipment` (courier-only 409, shipment-exists 409, required fields and max lengths exactly as `ShipmentController::store` today) and `recipientDefaults(Preorder): array`; make `ShipmentController::store` delegate with identical status codes. Verify the defaults' source against what the shipment form pre-fills (research open item 2).
- [X] T008 Add `PreorderService::statusBlocker(Preorder, string $target): ?array` (`[code, message]`, null = allowed) and make `transitionStatus()` use it for its two pre-checks (`canTransitionTo`, outstanding for `handed_over`); behaviour and messages unchanged. Stock for `handed_over` stays checked only inside `applyMovement`.
- [X] T009 Rerun the T001 regression command; every previously green test must still be green. If anything changed, fix the extraction (not the test) before continuing.
  - _Note:_ Rerun after extraction: 362 passed — identical.
- [X] T010 Create `app/Http/Requests/PreorderBulkRequest.php`: shape rules per `{action}` and per phase (preview/apply) from contracts §2–3, `order_ids` integers/unique/non-empty/≤ `config('preorders.bulk_max')` (message names the limit), `cancel_reason` required when target is `cancelled`, `channel_id` required for non-cash, `rows` ⊆ `order_ids` with no duplicate ids, `batch_ref` UUID for payment.
- [X] T011 Create `app/Services/PreorderBulkService.php` (orchestrator ONLY, no business rule): one eager-loaded query through the scoped `Preorder` model (`items`, `payments`, `customer`, `shipment`) for all ids; per-pre-order `DB::transaction` with `lockForUpdate()` → service call → audit row; `classify()` maps a service `ValidationException` to a reason code in ONE place; result shaping `succeeded|skipped|failed` in request order with `succeeded + skipped + failed = selected`; unexpected exception → `failed` with a generic message (detail only in the log); notifications after each commit; aggregate `preorder_bulk_operation` row after the loop (its failure never fails the response); raises the PHP time limit for apply only. Provide `preview()` / `apply()` entry points that each action fills in later.
- [X] T012 Add `bulkPreview()` and `bulkApply()` thin methods and `matchingIds()` (reusing the private `applyFilters()`; plucks ids only; returns `{ids,total,capped}` capped at `bulk_max`) to `app/Http/Controllers/Api/PreorderController.php`.
- [X] T013 Register in `routes/api.php`, BEFORE `apiResource('preorders')`: `GET /preorders/matching-ids` and the 8 `POST /preorders/bulk/{status|dispatch|payment|shipment}/{preview|apply}` routes from ONE loop over the table `status→status`, `dispatch→dispatch`, `payment→payment`, `shipment→edit` (each with `ability:preorders,<ability>`; pass one middleware array, `apiResource(...)->middleware()` overwrites). Classify the new routes for seller scope (scope-aware, not forbidden) so `ForbiddenSurfacesTest` and `MenuActionsRouteCoverageTest` stay green.
- [X] T014 [P] Expose `preorder_bulk_max` in `GET /settings/features` (all roles, convenience only) in the existing settings controller, following 064's `bulk_approve_max` pattern.
- [X] T015 [P] Add the optional `header-<key>` slot to `resources/js/components/ui/DataTable.vue` (default renders the label, so no existing table changes) and a `qa-tests` case proving the default is unchanged.
- [X] T016 [P] Add `matchingPreorderIds()`, `previewBulk()`, `applyBulk()` to `resources/js/api/preorders.js` (signatures per contracts §5) and extend the default mock in `qa-tests/setup.js`.
  - _Note:_ Client functions added; the default mock in `qa-tests/setup.js` was NOT extended (it only mocks `orderApprovals`; each preorder test file mocks `api/preorders` itself).
- [X] T017 Create `resources/js/components/preorder/bulk/PreorderBulkModal.vue` (lazy shell: configure → preview → review → processing → result; single-flight apply; one `batch_ref` per open) and `BulkResultView.vue` (counts that add up, reason per non-success, pre-order number opens the detail, “Retry these” emits the non-success ids). Forms plug in via a slot/registry; no form yet.

**Checkpoint**: extractions green, routes registered and gated, shell renders, nothing user-visible yet.

---

## Phase 3: User Story 1 — Select many pre-orders (P1) 🎯

**Goal**: header select-all with partial state, “select all N matching” across pages, count, Clear, and a permission-aware action bar.

**Independent test**: 60 pre-orders over 3 pages → select page → select all matching → count correct → clear.

### Tests (write first)

- [X] T018 [P] [US1] `tests/Feature/PreorderBulk/MatchingIdsTest.php`: same filters as the list return the same set; ids capped at `bulk_max` with `capped=true` and the true `total`; other mode / foreign seller invisible; page/sort params ignored; a role without the `preorders` menu is refused.
  - _Note:_ Implemented in `BulkGuardsTest::test_matching_ids_follow_the_list_filters…`.
- [X] T019 [P] [US1] `qa-tests/unit/usePreorderSelection.test.js`: page toggle, indeterminate, select-all-matching (and capped message), clear, reset on filter/search/sort change, count always equals the set size.
- [X] T020 [P] [US1] `qa-tests/component/PreorderBulkBar.test.js`: bar absent with 0 selected; each action button hidden (not disabled) without its `auth.canDo('preorders', …)`; Clear works.

### Implementation

- [X] T021 [US1] Create `resources/js/composables/usePreorderSelection.js` (page/all-matching/clear/partial state; resets with filters; truthful count; limit from `preorder_bulk_max`).
- [X] T022 [US1] Edit `resources/js/views/PreordersView.vue`: move the existing `selectedIds` Set into the composable (the existing download/email/duplicate actions keep using it unchanged), header checkbox via the new `header-<key>` slot, “Select all N matching” banner, selected count, Clear button, and the five-button bulk bar gated per ability.

**Checkpoint**: selection is fully usable with the existing bulk actions; the five buttons exist but open the empty shell.

---

## Phase 4: User Story 2 — Bulk update status (P1)

**Goal**: one target status for the selection; eligible move exactly as individually, the rest skipped with reasons.

**Independent test**: 10 mixed-status pre-orders → “Goods arrived” → only Deposit-paid moved, others skipped with reasons.

### Tests (write first)

- [X] T023 [P] [US2] `tests/Feature/PreorderBulk/BulkStatusTest.php`: mixed batch moves only valid transitions, `succeeded + skipped + failed = selected`; **parity** — each result equals the individual `updateStatus` outcome (status, stock movements, auto-settle, notifications); `handed_over` skips `not_fully_paid` and `insufficient_stock` while the rest proceed; `cancelled` needs `cancel_reason` once and stores it on all (422 without); an invalid transition is never forced; notification failure never fails the change.
- [X] T024 [P] [US2] `tests/Feature/PreorderBulk/BulkStatusPreviewTest.php`: `status_options` computed without `params`, counts per target match the apply; preview writes nothing (rows, logs, stock, mail).
  - _Note:_ Implemented inside `BulkStatusTest` (preview case).
- [X] T025 [P] [US2] `qa-tests/component/BulkStatusForm.test.js`: only reachable targets are offered with their counts; cancel requires a reason; confirm disabled when nothing eligible.

### Implementation

- [X] T026 [US2] Implement the `status` branch of `PreorderBulkService::preview()/apply()`: options from `statusBlocker`, per-row `transitionStatus` on the locked model, `preorder_bulk_status` audit row (old/new status, cancel reason, `bulk_ref`), notification after commit.
- [X] T027 [P] [US2] Create `resources/js/components/preorder/bulk/BulkStatusForm.vue` and wire it into the modal registry and the “Update status” button.

**Checkpoint**: US2 usable end to end.

---

## Phase 5: User Story 3 — Bulk add payment (P1)

**Goal**: shared method/channel/reference/notes, per-row editable amount, each payment on its own pre-order, no duplicates.

**Independent test**: 5 pre-orders with different balances, one edited partial amount → correct paid/outstanding/status; resubmitting records nothing twice.

### Tests (write first)

- [X] T028 [P] [US3] `tests/Feature/PreorderBulk/BulkPaymentTest.php`: per-row amounts recorded against the right pre-order; amount > outstanding → `exceeds_balance`, ≤ 0 → `invalid_amount`, already paid → `fully_paid`, cancelled → `cancelled` (never overpaid); non-cash without channel → 422 and ZERO rows; becoming fully paid follows the individual auto-status rules (**parity** with `storePayment`); payment carries the shared reference/notes and `paid_at` = processing time.
- [X] T029 [P] [US3] `tests/Feature/PreorderBulk/BulkPaymentIdempotencyTest.php`: same `batch_ref` twice → second run all `already_recorded`, still one payment per pre-order; two concurrent applies → exactly one payment each; a new `batch_ref` is a new batch.
  - _Note:_ Implemented inside `BulkPaymentTest` (same batch twice; new batch). Two truly concurrent applies are covered by the per-pre-order row lock + the UNIQUE `client_ref`, not by a parallel-process test.
- [X] T030 [P] [US3] `qa-tests/component/BulkPaymentForm.test.js`: amounts default to outstanding and are editable; blockers flagged in the review table; channel required for non-cash; total shown matches the sum of eligible rows.

### Implementation

- [X] T031 [US3] Implement the `payment` branch: preview rows from `PaymentSummary` (total/paid/outstanding/default amount/blocker, `total_amount`); apply derives `client_ref = UUIDv5(namespace, "{batch_ref}:{preorder_id}")` and calls `PaymentService::addPayment()` on the locked model; `$replayed` → skipped `already_recorded`; `preorder_bulk_payment` audit row linking the payment id and `bulk_ref`. Resolve the payment `purpose` mapping against `PaymentPanel` and record it in research.md (open item 1). No payment date, no proof.
- [X] T032 [P] [US3] Create `resources/js/components/preorder/bulk/BulkPaymentForm.vue` (shared fields + editable per-row review table, reuses the existing channel picker) and wire the “Add payment” button.

**Checkpoint**: US3 usable end to end.

---

## Phase 6: User Story 4 — Clear results and retry (P1)

**Goal**: a truthful progress → result experience with one-click retry and a safe recovery from an unknown outcome.

**Independent test**: valid + already-processed + invalid mix → counts add up, every non-success has a reason, retry selects exactly the non-successes, list refreshed.

### Tests (write first)

- [X] T033 [P] [US4] `tests/Feature/PreorderBulk/BulkResultShapeTest.php`: for every action `selected = succeeded + skipped + failed = results.length`, request order preserved, every non-success has `reason` + `message`, an exception inside one pre-order yields `failed` with a generic message (no SQL/paths) and leaves that pre-order untouched while the others succeed (inject the fault through a service double).
  - _Note:_ Counts-add-up asserted in every action test; the exception-isolation case is `BulkGuardsTest::test_an_unexpected_failure…`.
- [X] T034 [P] [US4] `qa-tests/component/BulkResultView.test.js` and `PreorderBulkModal.test.js`: progress shown and the confirm button single-flight; result counts add up; “Retry these” replaces the selection with the non-success ids and reopens the same action; payment retry keeps `batch_ref`, a fresh open makes a new one; pre-order number opens the detail; a network drop shows the “outcome unknown, safe to re-run” message.

### Implementation

- [X] T035 [US4] Finish `PreorderBulkModal.vue` + `BulkResultView.vue`: processing indicator, double-submit guard, unknown-outcome state on a dropped request, retry wiring, and after-close refresh (`load()` + summary cards) keeping only the non-successes selected in `PreordersView.vue`.

**Checkpoint**: the P1 set (US1–US4) is complete — **MVP is shippable here**.

---

## Phase 7: User Story 5 — Invoice / shipping progress (P2)

**Goal**: set the dispatch marker on many pre-orders with the same rules and dates as individually.

**Independent test**: 8 pre-orders (pickup, Mail Order, one cancelled) → “Mark shipping” → only eligible Mail Order change.

### Tests (write first)

- [X] T036 [P] [US5] `tests/Feature/PreorderBulk/BulkDispatchTest.php`: `invoice_sent` skips cancelled (`cancelled`); `shipping` skips Self Pickup (`not_mail_order`); already at target → `already_done` with dates unchanged; dates follow the individual rules (**parity** with `PreorderDispatchStatusTest`); stock, payments, status and notifications untouched.
- [X] T037 [P] [US5] `qa-tests/component/BulkMarkerForm.test.js`: the three markers offered, counts shown, nothing-eligible blocks confirm.

### Implementation

- [X] T038 [US5] Implement the `dispatch` branch via `dispatchBlocker()` / `setDispatchStatus()` and the `preorder_bulk_dispatch` audit row (old/new marker and dates, `bulk_ref`).
- [X] T039 [P] [US5] Create `BulkMarkerForm.vue` and wire the “Update invoice progress” / “Update shipping progress” buttons (one action, `dispatch_status` preselected per button).

---

## Phase 8: User Story 6 — Create shipments (P2)

**Goal**: one shipment per eligible Mail Order pre-order with its own recipient and tracking number.

**Independent test**: 6 Mail Order pre-orders (one has a shipment, one lacks an address, one is Self Pickup) → three created, three skipped with reasons.

### Tests (write first)

- [X] T040 [P] [US6] `tests/Feature/PreorderBulk/BulkShipmentTest.php`: each shipment has its own recipient/tracking and is linked only to its pre-order; existing shipment → `shipment_exists` and never overwritten; Self Pickup → `not_mail_order`; cancelled → `cancelled`; empty required recipient field → `missing_recipient`; repeating the request yields at most one shipment per pre-order; **parity** with `ShipmentController::store`; created shipments editable individually afterwards.
- [X] T041 [P] [US6] `qa-tests/component/BulkShipmentForm.test.js`: rows seeded from the preview defaults, missing fields flagged, tracking editable, shared courier required.

### Implementation

- [X] T042 [US6] Implement the `shipment` branch: preview rows from `ShipmentService::recipientDefaults()` with blockers/`fields`; apply merges row overrides with shared params and calls `ShipmentService::createFor()`; `preorder_bulk_shipment` audit row (shipment id, courier, tracking, `bulk_ref`).
- [X] T043 [P] [US6] Create `BulkShipmentForm.vue` (shared courier/cost/notes via the existing courier dropdown + editable per-row table) and wire the “Create shipments” button.

---

## Phase 9: User Story 7 — Traceability (P2)

**Goal**: every changed pre-order traceable to a person, time, action and bulk operation; skipped/failed have no false entries.

**Independent test**: run each action over 3 pre-orders → entries exist for changed ones only, with old/new values and the shared `bulk_ref`.

### Tests (write first)

- [X] T044 [US7] `tests/Feature/PreorderBulk/BulkAuditTest.php`: per action, one `preorder_bulk_*` row per succeeded pre-order inside its transaction with user, old/new values and `bulk_ref`; skipped and failed pre-orders have none; a rollback inside one pre-order leaves no row for it; exactly one `preorder_bulk_operation` row with counts and `skipped_by_reason`; a failing aggregate write never fails the response; payment rows reference the service's own `payment_recorded` row.
  - _Note:_ Audit assertions live in each action's test class (`BulkStatusTest`, `BulkPaymentTest`, `BulkDispatchShipmentTest`) rather than a separate file. A forced rollback leaves no audit row is asserted in the isolation test.

### Implementation

- [X] T045 [US7] Verify the exact `entity_type` strings used for pre-orders by existing `ActivityLogger` calls (research open item 4) and align every bulk row; confirm the rows are findable in the owner/admin Activity Log screen (filter by action) and note the result in research.md.
  - _Note:_ Entity type is `Preorder` (same as `PaymentService`). The Activity Log API filters generically by `entity_type`/`action`, so the rows are findable; the screen itself was not opened in a browser.

---

## Phase 10: Cross-cutting tests & docs

- [X] T046 [P] `tests/Feature/PreorderBulk/BulkAuthorizationTest.php`: per route, a role without the individual ability gets 403 `action_not_allowed` (including crafted requests), nothing changes; seller-scoped user acts only on visible pre-orders and foreign ids come back `not_found`; demo vs live never mix; nothing else is touched by any action.
  - _Note:_ In `BulkGuardsTest` (403 on all 8 routes for a role with no matching ability, single-ability role, other mode/seller = not_found).
- [X] T047 [P] `tests/Feature/PreorderBulk/BulkLimitAndValidationTest.php`: `bulk_max + 1` ids → 422 naming the limit and nothing written; empty selection, non-integer ids, `rows` outside `order_ids` and duplicate row ids → 422; preview of every action writes nothing.
  - _Note:_ In `BulkGuardsTest` (limit, empty, non-integer, duplicate ids) and `BulkPaymentTest` (rows outside selection / duplicate rows).
- [X] T048 [P] `tests/Feature/PreorderBulk/BulkQueryCountTest.php`: preview and the READ part of apply do not grow per pre-order (warm-up call first; mutation-check by lazy-loading a relation).
  - _Note:_ In `BulkGuardsTest` (preview of all four actions: same query count for 3 and 9 pre-orders). The per-pre-order locked re-read in APPLY is deliberate and grows with N.
- [X] T049 Update `docs/openapi-pos-mvp.yaml` in the same change: the 9 new paths, request/response schemas, the reason-code enum, `preorder_bulk_max` in features.
- [X] T050 [P] Add dated notes: `docs/PRD-POS-Event-Multivendor.md` (pre-order bulk actions, no payment date/proof in bulk) and a CLAUDE.md section "Pre-order bulk actions (feature 065)" with the rules a future change must not break (reuse by extraction, orchestrator only, per-pre-order transaction, idempotent `client_ref`, known audit gap for individual actions).
- [X] T051 Measure with the same method as 064 (in-process timing on the test DB; log mailer, then a slow-mail simulation for status changes): status of 50, payments of 50, shipments of 30, preview of 200. Record numbers in CLAUDE.md; do NOT claim SC-001…003 without them. If notifications dominate, decide per research D7 and document.
  - _Note:_ status50 0.37 s, payment50 0.58 s, shipment30 0.08 s, preview200 0.02–0.03 s (log mailer, in-process). Slow-SMTP simulation NOT done.
- [X] T052 Run the full quickstart automated commands (backend filter + vitest) with the exact commands in-session; report the 9 `Code128` failures as pre-existing; run `npm run build` and confirm `PreorderBulkModal` stays a separate lazy chunk (Constitution V).
  - _Note:_ backend full run 1946 passed / 9 failed (the known Code128 reference-library failures); Vitest 145 files, 1298 passed, 2 skipped; `npm run build` keeps `PreorderBulkModal` a separate 22 kB lazy chunk.
- [ ] T053 Real-browser check per quickstart (Constitution II): selection across pages, all five dialogs, retry, double-click confirm, phone width, console clean. If browser tooling is unavailable, report it explicitly as NOT executed — do not mark done.

---

## Dependencies & order

- Phase 1 → Phase 2 (T006–T009 strictly in order: extract, then rerun regression) → everything else.
- Within Phase 2: T010–T013 are backend and share files with T011/T012 (sequential); T014–T16 are independent [P]; T017 needs T015/T016.
- US1 needs only Phase 2. US2, US3, US5, US6 each need Phase 2 and are independent of each other (they touch different branches of `PreorderBulkService` — merge carefully, do not run two such tasks at once). US4's UI finishing (T035) needs at least one form (US2). US7 verifies rows written by US2/US3/US5/US6, so run it after them.
- Tests within a story precede their implementation.

## Parallel examples

- After Phase 2: US1 frontend (T021–T022) in parallel with US2 backend (T023–T026).
- Within US3: T028, T029, T030 together; T032 (form) alongside T031 once the preview row shape is fixed.
- Phase 10: T046, T047, T048, T050 together.

## Implementation strategy

1. **MVP**: Phase 1 → 2 → US1 → US2 → US4. Stop and verify (backend filter + vitest; browser if available).
2. Add US3 (payment, the highest-risk money path) next, then US5 and US6.
3. US7 and Phase 10 last; T053 is a gate, not optional polish.

## Format check

All 53 tasks use `- [ ] T### [P?] [US?] description with path`; story labels appear only in Phases 3–9.
