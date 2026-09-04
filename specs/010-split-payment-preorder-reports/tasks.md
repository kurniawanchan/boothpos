---

description: "Task list for Split Payment Visibility, Preorder Receipt & Reporting"

---

# Tasks: Split Payment Visibility, Preorder Receipt & Reporting

**Input**: Design documents from `/specs/010-split-payment-preorder-reports/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: Included — Constitution Principle II requires `tests/Feature/` coverage for every backend change and a real-browser check for every touched screen; not optional for this project.

**Organization**: Tasks are grouped by user story (US1–US6, matching spec.md's priorities P1/P1/P2/P2/P2/P3) so each can be delivered and verified independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US6)

## Path Conventions

Existing single Laravel + Vue repo: `app/`, `resources/js/`, `routes/api.php`, `tests/Feature/`, `qa-tests/`, `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Confirm dev environment is ready: `laradock-mysql-1` running, `.env.testing` present, `npm run dev`/`php artisan serve` both start clean — no code changes

---

## Phase 2: Foundational

**Note**: Per data-model.md, this feature introduces no schema changes and no new shared relations — `Payment`, `Preorder`, and `PreorderItem` already have every field needed. There is no blocking shared-infrastructure work; each user story below is independently buildable once Setup is done. The only real cross-story constraint is a **file-level** one (US1 and US2 both edit `PaymentPanel.vue`) — see Dependencies section, not a Foundational phase.

**Checkpoint**: Proceed directly to User Story phases.

---

## Phase 3: User Story 1 - Cashier can clearly split a payment across methods at POS checkout (Priority: P1) 🎯 MVP

**Goal**: The POS checkout payment screen visibly shows, before any entry is added, that more than one payment method can be used, and clearly reflects the running split state as entries are added/removed

**Independent Test**: At POS checkout with a non-round total, open the payment screen, confirm a visible split affordance exists before adding anything, add two entries in different methods covering the total, complete the sale, and confirm both entries appear on the receipt

### Implementation for User Story 1

- [X] T002 [US1] In `resources/js/components/payment/PaymentPanel.vue`, make the split-payment UI always visible in `mode="checkout"` regardless of `entries.length` (not gated behind `isSplitting`) — persistent entries list/hint, and a context-aware submit button label ("Add & continue" when the current entry won't cover the remaining balance vs. "Complete payment" when it will), per research.md R3
- [X] T003 [US1] Add/update locale keys in `resources/js/locales/id.json` and `en.json` for the new always-visible split hint copy and the two submit-button label variants
- [X] T004 [P] [US1] Extend `qa-tests/component/PaymentPanel.test.js`: split affordance is visible before any entry exists (checkout mode), submit button label changes correctly between "add & continue" and "complete"
- [X] T005 [US1] Manual browser verification of the full POS split-payment flow per `quickstart.md` step 1, including checking the resulting receipt itemizes both entries (receipt itself is unchanged — `ReceiptModal.vue` already does this per research.md R5 context, this step confirms it end-to-end with the new UI)

**Checkpoint**: User Story 1 fully functional and independently testable/demoable

---

## Phase 4: User Story 2 - Owner/admin can clearly split a preorder payment across methods (Priority: P1)

**Goal**: The preorder payment-recording screen offers the same visible split experience as POS checkout, and split entries are reliably persisted as separate payments against the preorder

**Independent Test**: Open a preorder's payment-recording flow, add a partial entry, confirm it's actually persisted (visible in payment history) before adding a second entry that completes the balance, confirm both appear as separate payment records

### Implementation for User Story 2

- [X] T006 [US2] In `resources/js/components/payment/PaymentPanel.vue`, change the `mode === 'record'` branch of `submit()` to accumulate into `entries[]` exactly like `mode="checkout"` already does (instead of always emitting a single payload), reusing the same always-visible split UI from T002 — depends on T002 being complete first (same file, sequential edit), per research.md R2
- [X] T007 [US2] Update `resources/js/components/payment/RecordPaymentModal.vue` (and/or its caller in `resources/js/views/PreordersView.vue`) to submit the accumulated `entries[]` as **sequential, awaited** calls to the existing `POST /preorders/{id}/payments` endpoint — one call per entry, no new backend endpoint, per research.md R2
- [X] T008 [US2] Add per-entry submission state (`pending`|`submitting`|`submitted`|`failed`) to the split-entry list in the Preorder payment flow so a mid-split network/validation failure doesn't lose already-submitted entries, and the user can retry only the failed entry, per data-model.md's frontend state model
- [X] T009 [P] [US2] Extend `tests/Feature/PreorderPaymentTest.php` (or the existing preorder-payment test file — confirm exact name during implementation): sequential split payments each produce their own `Payment` row, `preorders.paid_amount` increments correctly across the sequence, and existing state-transition guards (e.g. can't hand over before fully paid) still apply correctly mid-split
- [X] T010 [P] [US2] Extend `qa-tests/component/PaymentPanel.test.js` for `mode="record"` entry accumulation, and add/extend a `RecordPaymentModal`-focused spec covering sequential submission and single-entry retry-on-failure
- [X] T011 [US2] Manual browser verification of Preorder split payment and the failure/retry path per `quickstart.md` steps 2–3

**Checkpoint**: User Stories 1 AND 2 both work independently; MVP scope (both P1 stories) is complete

---

## Phase 5: User Story 3 - Any user can tell which table row their cursor is over (Priority: P2)

**Goal**: Every data table in the app highlights the hovered row

**Independent Test**: Visit each of the 5 table locations, hover rows, confirm highlight appears/disappears correctly and doesn't obscure other row indicators

### Implementation for User Story 3

- [X] T012 [P] [US3] Add a hover class (existing `@theme` token, e.g. matching the pattern already used for other hover states in this codebase) to `resources/js/components/ui/DataTable.vue`'s data-row `<tr>` template — covers every screen already using this shared component
- [X] T013 [P] [US3] Add the same hover class to `resources/js/components/product/VariantBomModal.vue`'s table row markup
- [X] T014 [P] [US3] Add the same hover class to `resources/js/components/report/ArtistTransactionsModal.vue`'s table row markup
- [X] T015 [P] [US3] Add the same hover class to `resources/js/components/masterData/MasterDataImportModal.vue`'s table row markup
- [X] T016 [P] [US3] Add the same hover class to `resources/js/components/product/ProductDetailModal.vue`'s table row markup
- [X] T017 [P] [US3] Extend `qa-tests/component/DataTable.test.js`: hover class is present on rows, and applying it alongside an existing selected/status-color row class doesn't remove or override that class (FR-007)
- [X] T018 [US3] Manual browser verification of row hover across all 5 locations per `quickstart.md` step 4

**Checkpoint**: User Stories 1–3 all independently functional

---

## Phase 6: User Story 4 - Preorder payment receipt looks and prints like the POS receipt, clearly marked as a preorder (Priority: P2)

**Goal**: A printable, POS-receipt-styled payment receipt exists per preorder payment event, clearly marked as a preorder with its status

**Independent Test**: Record a payment against a preorder, open its payment receipt, confirm POS-receipt-style layout, clear "Pre-order" + status marking, and correct identification of which payment event it documents

### Implementation for User Story 4

- [X] T019 [US4] Create `resources/js/components/preorder/PreorderPaymentReceiptModal.vue`: sources data from the existing `GET /preorders/{id}` response (already includes the `payments` relation, per research.md R5 — no backend change needed), mirrors `ReceiptModal.vue`'s visual layout and its `payment_summary`-style itemization pattern, prominently labeled "Pre-order" with the preorder's current status, and identifies which specific payment event (down payment vs. settlement, with date) the receipt documents when more than one exists
- [X] T020 [US4] Add a "print payment receipt" action per payment-history entry in `resources/js/views/PreordersView.vue`, opening `PreorderPaymentReceiptModal.vue` scoped to that specific payment event
- [X] T021 [US4] Add new locale keys to `resources/js/locales/id.json`/`en.json` for the preorder receipt's labels ("Pre-order" marking, payment-event labels, status display)
- [X] T022 [P] [US4] Add `qa-tests/component/PreorderPaymentReceiptModal.test.js`: renders POS-receipt-like structure, shows "Pre-order" + status prominently, correctly identifies the specific payment event for a preorder with multiple payments
- [X] T023 [US4] Manual browser verification of the preorder payment receipt, including the multi-payment-event distinction, per `quickstart.md` step 5

**Checkpoint**: User Stories 1–4 all independently functional

---

## Phase 7: User Story 5 - Preorder transactions are counted in every existing report (Priority: P2)

**Goal**: Sales, profit, and artist-settlement reports include preorder-sourced revenue, recognized only from cash actually collected and prorated correctly across items/artists

**Independent Test**: Create an event with a regular sale and a partially-paid preorder, run sales/profit/artist-settlement reports, confirm totals equal sale amount + preorder's collected (not full order) amount

### Implementation for User Story 5

- [X] T024 [US5] Extend `ReportController::sales()` in `app/Http/Controllers/Api/ReportController.php` to merge preorder-sourced recognized revenue into the existing rows/totals — one additional grouped aggregate query (or `UNION ALL`) over `preorder_items`/`preorders`/`payments`, using the proration rule from research.md R1 (`item.line_total / preorder.subtotal * amount_collected`, `amount_collected` summed live from non-rejected `payments`, `cancelled` preorders excluded entirely) — NOT a per-preorder PHP loop (Constitution V)
- [X] T025 [US5] Extend `ReportController::profit()` the same way, applying the same R1 proration to `PreorderItem.cost_price` alongside `line_total`, per research.md R7
- [X] T026 [US5] Extend `SettlementService::recalculateForEvent()` in `app/Services/SettlementService.php` to add a second, parallel aggregation over `preorder_items` (same R1 proration, `cancelled` excluded) into the same per-artist `total_sales`/`total_units` figures already computed from `order_items`
- [X] T027 [P] [US5] Extend `tests/Feature/ReportTest.php`: `sales()`/`profit()` totals include a partially-paid preorder's collected-only amount (not full order value); a `cancelled` preorder contributes zero; a multi-artist preorder's partial payment is prorated correctly across artists
- [X] T028 [P] [US5] Extend the existing artist-settlement test file (confirm exact filename during implementation, e.g. `tests/Feature/ArtistSettlementTest.php` or wherever `SettlementService::recalculateForEvent()` is currently tested): settlement recalculation includes preorder-sourced revenue with the same proration/cancellation rules
- [X] T029 [US5] Update `docs/openapi-pos-mvp.yaml`'s description for `GET /reports/sales`, `GET /reports/profit`, `GET /reports/artist-settlements` noting the merged preorder revenue (no schema/field change, per contracts/api-deltas.md)
- [X] T030 [US5] Manual browser verification of merged report totals (to the rupiah) and cancelled-preorder exclusion per `quickstart.md` steps 6–7

**Checkpoint**: User Stories 1–5 all independently functional

---

## Phase 8: User Story 6 - A dedicated preorder report exists (Priority: P3)

**Goal**: A new report summarizes preorders by status and payment completeness, with event filtering

**Independent Test**: With preorders in multiple statuses/payment states, open the new report and confirm correct counts/totals per status × payment-completeness, and correct event filtering

### Implementation for User Story 6

- [X] T031 [US6] Implement a new preorder-report method (`ReportController::preorders()`, or a dedicated sibling controller if that better matches this codebase's existing controller granularity — decide during implementation per research.md R6) in `app/Http/Controllers/Api/ReportController.php`: base query over `preorder_items`/`preorders` filtered by `data_mode`/optional `event_id`, grouped by `status` × a computed `payment_completeness` bucket (`unpaid`/`partial`/`paid`, derived from live-summed `payments` per data-model.md), returning `preorder_count`, `total_order_value`, `total_collected`, `total_outstanding` per group
- [X] T032 [US6] Add `GET /reports/preorders` route to `routes/api.php`, gated `canAccessMenu('reports')` matching every other report route
- [X] T033 [P] [US6] Create `tests/Feature/PreorderReportTest.php`: correct grouping/totals across statuses and payment-completeness buckets, `event_id` filter narrows correctly, DEMO/LIVE data-mode isolation holds
- [X] T034 [US6] Add a `preorderReport()` function to `resources/js/api/reports.js` calling `GET /reports/preorders`
- [X] T035 [US6] Add a new "Preorder" tab to `resources/js/views/ReportsView.vue` rendering the status × payment-completeness breakdown (counts, collected, outstanding), with the event filter wired the same way other tabs already filter
- [X] T036 [US6] Add new locale keys to `resources/js/locales/id.json`/`en.json` for the preorder report tab and its labels
- [X] T037 [US6] Update `docs/openapi-pos-mvp.yaml` with `GET /reports/preorders` per `contracts/api-deltas.md`
- [X] T038 [US6] Manual browser verification of the dedicated preorder report per `quickstart.md` step 8

**Checkpoint**: All 6 user stories independently functional

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T039 [P] Run `php artisan test` (full suite) — confirm no regressions
- [X] T040 [P] Run `npm test` (full Vitest suite) — confirm no regressions
- [X] T041 Run the full `quickstart.md` end-to-end validation in a real browser
- [X] T042 If any non-obvious bug is found and fixed during implementation, record it in code with a `BUG YANG DITEMUKAN & DIPERBAIKI` comment and in README.md's bug list, per repo convention

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: No blocking tasks — see note above
- **US1 (Phase 3, P1)**: Can start after Setup
- **US2 (Phase 4, P1)**: Can start after Setup, but **T006 must not start until T002 is complete** — both edit `resources/js/components/payment/PaymentPanel.vue`, and US2's change builds directly on US1's always-visible-UI change rather than reintroducing the old gated behavior
- **US3 (Phase 5, P2)**: Fully independent — different files from every other story
- **US4 (Phase 6, P2)**: Fully independent — new component + `PreordersView.vue`, no overlap with US1/US2's `PaymentPanel.vue`/`RecordPaymentModal.vue` changes
- **US5 (Phase 7, P2)**: Fully independent — backend-only (`ReportController.php`, `SettlementService.php`), no frontend overlap with any other story
- **US6 (Phase 8, P3)**: Fully independent — new files; may reuse patterns established by US5's report work but has no hard dependency on it
- **Polish (Phase 9)**: Depends on all desired user stories being complete

### Within Each User Story

- Backend query/service changes before their tests (or written alongside, TDD-style, per Constitution II)
- Frontend component changes before their qa-tests
- Manual browser verification last in each phase

### Parallel Opportunities

- US3, US4, US5, and US6 can all proceed in parallel with each other and with US1/US2, since none share a file
- Within US3: T012–T017 are all `[P]` — five different files plus one test file
- Within US5: T024–T026 touch two backend files (`ReportController.php`, `SettlementService.php`) — T024/T025 (same file, sequential) then T026 (different file, parallelizable with the tests once T024/T025 land); T027/T028 (tests) are `[P]` with each other
- Within US6: T033 (test) is `[P]` with T031/T032 only if worked by a different session (same-file caution doesn't apply — new file)
- US1's T002 must complete before US2's T006 (same-file dependency, noted above) — this is the only true cross-story ordering constraint in this feature

---

## Parallel Example: User Story 3 (fully parallel, 5 files)

```bash
Task: "Add hover class to resources/js/components/ui/DataTable.vue"
Task: "Add hover class to resources/js/components/product/VariantBomModal.vue"
Task: "Add hover class to resources/js/components/report/ArtistTransactionsModal.vue"
Task: "Add hover class to resources/js/components/masterData/MasterDataImportModal.vue"
Task: "Add hover class to resources/js/components/product/ProductDetailModal.vue"
```

## Parallel Example: User Story 5

```bash
Task: "Extend tests/Feature/ReportTest.php for merged preorder revenue"
Task: "Extend the artist-settlement test file for merged preorder revenue"
# (run after T024-T026 land, since tests assert against the new behavior)
```

---

## Implementation Strategy

### MVP First (User Stories 1 + 2, both P1)

1. Complete Phase 1: Setup
2. Complete Phase 3: US1 (POS split-payment visibility) — note T002 must land before starting US2
3. Complete Phase 4: US2 (Preorder split payment, actually working end-to-end)
4. **STOP and VALIDATE**: both P1 stories independently, per their Independent Test criteria
5. Ship/demo — closes the most operationally urgent gap (cashiers/owners unable to discover or reliably use split payment)

### Incremental Delivery

1. Setup → US1 → verify → ship
2. US2 → verify → ship (both P1s together form the MVP)
3. US3 (row hover) → verify → ship — lowest-risk, fully independent
4. US4 (preorder receipt) → verify → ship
5. US5 (reports include preorder revenue) → verify → ship — the correctness fix
6. US6 (dedicated preorder report) → verify → ship
7. Phase 9 polish as a final cross-cutting pass

### Suggested MVP Scope

**User Stories 1 and 2** (both P1) — these close the actual functional gap (Preorder split payment doesn't work at all today) and the visibility gap (POS split payment works but isn't discoverable), which together are the most operationally pressing part of this feature per the spec's own priority ordering.

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- The only hard cross-story file dependency in this feature is T002 → T006 (both edit `PaymentPanel.vue`) — every other story is fully file-independent
- Revenue-recognition proration (research.md R1) is the one piece of business logic in this feature that needs careful test coverage (T027/T028) — verify the math against a hand-calculated example during review, not just that a test passes
- Verify tests fail before implementing (Constitution II / TDD discipline)
- Commit after each task or logical group
- Stop at any checkpoint to validate a story independently
- Avoid: same-file conflicts flagged as [P], cross-story dependencies that break independence
