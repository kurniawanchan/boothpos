---

description: "Task list for Duplicate and Split Pre-orders"
---

# Tasks: Duplicate and Split Pre-orders

**Input**: Design documents from `/specs/027-preorder-duplicate-split/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–8), data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: INCLUDED. Constitution II makes tests mandatory for this repo (backend under `tests/Feature/` against real MySQL, frontend under `qa-tests/`, plus a real-browser check). Test tasks come before the implementation they cover.

**Organization**: Grouped by user story (US1 Duplicate one · US2 Duplicate many · US3 Split · US4 Split by seller) so each can be implemented and verified independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US4, only on user-story phase tasks
- Code comments, UI copy and commit messages are in Indonesian (project convention); UI strings go in BOTH `resources/js/locales/{en,id}.json`, backend messages in BOTH `lang/{en,id}/preorders.php`

## Safety reminders (CLAUDE.md)

- Run backend tests on the host, or in Docker ONLY as `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …` — a bare run wipes the dev database.
- Migration date prefix `2026_10_30_000001` is load-bearing; never rename.
- `present()` silently drops relations that were not eager-loaded — every endpoint that returns a pre-order payload must load what `present()` reads.

---

## Phase 1: Setup

**Purpose**: Confirm a safe, green baseline. No new dependencies are introduced by this feature.

- [X] T001 Confirm `.env.testing` exists and points at `boothpos_test`, then run `php artisan test --filter=Preorder` on the host and record that the existing pre-order suites pass before any change (`tests/Feature/Preorder*Test.php`)

---

## Phase 2: Foundational (blocks every user story)

**Purpose**: Origin-tracking schema, model relations, payload fields and shared frontend plumbing that all four stories read.

**⚠️ CRITICAL**: No user-story work can begin until this phase is complete.

- [X] T002 Create migration `database/migrations/2026_10_30_000001_add_source_to_preorders_table.php` adding nullable `source_preorder_id` (self-FK on `preorders.id`, `nullOnDelete`, indexed), `source_type` enum(`duplicate`,`split`) and `source_preorder_number` string(30), with a working `down()` (data-model.md)
- [X] T003 Update `app/Models/Preorder.php`: add the three columns to `$fillable`, add `sourcePreorder(): BelongsTo` and `splitChildren(): HasMany` (`source_type = 'split'`)
- [X] T004 Update `app/Http/Controllers/Api/PreorderController.php`: `present()` returns `source` (`{type, preorder_id, preorder_number}` or `null`) and `split_children` (`[{id, preorder_number}]`, guarded by `relationLoaded`); `show()` eager-loads `splitChildren`. Add an Indonesian comment pointing at the CLAUDE.md "relationLoaded" trap
- [X] T005 [P] Add `duplicatePreorders(preorderIds)` (`POST /preorders/duplicate`) and `splitPreorder(id, payload)` (`POST /preorders/{id}/split`) to `resources/js/api/preorders.js`, following the existing function style
- [X] T006 Add a foundational feature test in `tests/Feature/PreorderSourceFieldsTest.php`: an ordinary pre-order's `GET /preorders/{id}` returns `source: null` and `split_children: []`; a row with `source_*` set returns them correctly; deleting the source nulls `source_preorder_id` but keeps `source_preorder_number`
- [X] T007 Run the migration on the dev DB (`php artisan migrate`) and the test DB, run T006 and the existing `PreorderTest`/`PreorderUpdateTest` to confirm no regression

**Checkpoint**: Schema, model and payload ready — user stories can start.

---

## Phase 3: User Story 1 — Duplicate a single pre-order (Priority: P1) 🎯 MVP

**Goal**: From a row or the detail panel, create a fresh "Ordered" copy of one pre-order, re-priced at today's price, with no payments/shipment, showing where it came from.

**Independent Test**: Duplicate one pre-order (any status) and confirm a new, uniquely numbered "Ordered" order appears with the same customer/event/fulfilment/items, current prices, zero payments, `dispatch_status` pending, a "Duplicated from PO-…" line, and the source unchanged.

### Tests for User Story 1 ⚠️ write first, confirm they fail

- [X] T008 [P] [US1] Create `tests/Feature/PreorderDuplicateTest.php` covering: copied fields (customer, event, fulfilment, pickup day/courier, expected date, shipping cost, discount, notes, items+qty); reset fields (status `ordered`, `paid_amount` 0, no payments/proofs/shipment, `dispatch_status` pending, null `invoice_sent_at`/`shipping_at`/`cancel_reason`); **re-pricing** (change a variant's `sell_price` first → duplicate's subtotal uses the new price, source keeps the old); source completely unchanged; `source` = duplicate + source number; partially-paid, `handed_over` and `cancelled` sources are all duplicable; no `preorder_notifications` row and no mail sent; one `duplicated` activity-log row; unauthenticated → 401; DEMO/LIVE (copy is stamped with the active mode, number unique across modes)
- [X] T009 [P] [US1] Add edge-case tests to `tests/Feature/PreorderDuplicateTest.php`: source's event deleted → copy has `event_id` null and no pickup day; pickup day outside the event range → dropped; soft-deleted variant, inactive variant and inactive product each → `failed` result naming the item and creating nothing; re-priced discount exceeding subtotal+shipping → `failed`

### Implementation for User Story 1

- [X] T010 [US1] Implement `PreorderService::duplicate(Preorder $source, User $user)` in `app/Services/PreorderService.php` per research.md Decisions 1–2 and 7: build the input array, sanitise event/pickup day/courier, pre-check item sellability (deleted/inactive variant or product → `ValidationException` naming the item), call `create()` inside a wrapping `DB::transaction`, stamp `source_*`, write the `duplicated` row via `ActivityLogger` in the same transaction, return the reloaded pre-order. Indonesian comments explaining *why* it wraps `create()` instead of `replicate()`
- [X] T011 [P] [US1] Create `app/Http/Requests/DuplicatePreordersRequest.php`: `preorder_ids` required array 1–100, each integer and `exists:preorders,id`; `authorize()` = authenticated, same as `StorePreorderRequest`
- [X] T012 [US1] Add `PreorderController::duplicate()` in `app/Http/Controllers/Api/PreorderController.php`: iterate the ids, run `duplicate()` per order (independent transactions), catch `ValidationException` and ids invisible in the active mode as `failed`, always respond `200 {data:[{source_id, source_number, status, preorder|error}]}` (contracts/api-deltas.md). Created orders must be reloaded with everything `present()` reads
- [X] T013 [US1] Register `Route::post('/preorders/duplicate', …)` in `routes/api.php` **before** `Route::apiResource('preorders', …)`, next to `bulk-invoices`, with the same explanatory comment style
- [X] T014 [P] [US1] Add backend messages to `lang/en/preorders.php` and `lang/id/preorders.php`: `duplicate_item_unavailable` (with `:item`), `duplicate_not_found`
- [X] T015 [US1] Frontend single duplicate in `resources/js/views/PreordersView.vue`: add a `duplicate` entry to `rowActions(row)` and `onRowAction()`, a Duplicate button in the detail panel, a `doDuplicate(ids)` helper that calls `duplicatePreorders`, shows a success toast with the new number (or the returned error), then calls the existing `load()` so the list and summary cards refresh; show the "Duplicated from PO-…" line (with deep-link via the existing `preorder_id` query) in the detail panel from `detail.source`
- [X] T016 [US1] Add the new UI strings (`preorders.duplicate`, `duplicate_success`, `duplicate_failed`, `duplicated_from`, …) to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T017 [P] [US1] Extend `qa-tests/component/PreordersView.test.js` (APIs are `vi.mock`'d): the row menu lists Duplicate for every status; clicking it calls `duplicatePreorders([id])`, toasts the new number and reloads; a `failed` result shows the error; detail shows the "Duplicated from" link
- [X] T018 [US1] Document in `docs/openapi-pos-mvp.yaml`: `POST /preorders/duplicate` (request, 200 report, 422) and the additive `source` / `split_children` fields on the pre-order schema (same commit as the route — Constitution / PRD §9.5)
- [X] T019 [US1] Run `php artisan test --filter=PreorderDuplicateTest` and `npm test`; fix until green

**Checkpoint**: US1 fully functional and demoable on its own (MVP).

---

## Phase 4: User Story 2 — Duplicate multiple pre-orders (Priority: P2)

**Goal**: Duplicate the checkbox selection in one action with a clear per-order result, never blocking good orders because of a bad one.

**Independent Test**: Tick three pre-orders (one containing an unsellable item) → "Duplicate selected" → two new orders created, one failure listed with its reason, list and summary updated without a manual refresh.

### Tests for User Story 2 ⚠️ write first

- [X] T020 [P] [US2] Add bulk tests to `tests/Feature/PreorderDuplicateTest.php`: 3 ids → 3 independent orders (never merged) each matching its own source; 20 ids → 20 created in one call; one bad order among good ones → others still `created`; an id belonging to the other data mode → `failed` ("not found"), never duplicated; 101 ids → 422; empty array → 422; results preserve request order; `GET /preorders/summary` counts include the new orders immediately (FR-024)
- [X] T021 [P] [US2] Create `qa-tests/component/PreorderDuplicateResultModal.test.js`: lists source → new number pairs and failures with reasons; closes via the button and Escape

### Implementation for User Story 2

- [X] T022 [P] [US2] Create `resources/js/components/preorder/PreorderDuplicateResultModal.vue` (built on `BaseModal`, token classes only, `[data-autofocus]` on the close button): table of source number → new number (clickable to open it) and a separate failure list
- [X] T023 [US2] In `resources/js/views/PreordersView.vue` add a "Duplicate selected" `BaseButton` to the existing bulk bar (shown only when `selectedIds.size > 0`, loading state like `bulkDownloading`), calling `doDuplicate([...selectedIds])`; open the result modal for multi-id runs (single-id keeps the toast from T015), clear the selection and call `load()` afterwards
- [X] T024 [US2] Add bulk strings (`preorders.duplicate_selected`, `duplicate_result_title`, `duplicate_result_created`, `duplicate_result_failed`, summary counts) to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T025 [P] [US2] Extend `qa-tests/component/PreordersView.test.js`: the bulk button is absent with no selection, present with a selection, only ticked visible rows are sent, and the result modal opens with a mixed result
- [X] T026 [US2] Run `php artisan test --filter=PreorderDuplicateTest` and `npm test`; fix until green

**Checkpoint**: US1 + US2 both work independently.

---

## Phase 5: User Story 3 — Split a pre-order (Priority: P2)

**Goal**: Move chosen units of an unpaid, still-open pre-order into a new pre-order, keeping totals reconciled and stock untouched.

**Independent Test**: Split one line off a multi-line "Ordered" pre-order → two orders whose subtotals add up to the original; discount/shipping stay on the original; stock and revenue unchanged; a pre-order with any payment cannot be split.

### Tests for User Story 3 ⚠️ write first

- [X] T027 [P] [US3] Create `tests/Feature/PreorderSplitTest.php` (manual mode): whole-line move keeps the **same `preorder_items.id`**; partial move shrinks the original (`qty`, `line_total = sell_price × qty`) and clones snapshot/cost/seller into the new row; Σ qty per variant and Σ subtotal across both orders equal the original; new order copies customer/event/fulfilment/pickup day/courier/expected date and the source's `status`, gets `shipping_cost` 0, `discount` 0, no notes, `dispatch_status` pending, `paid_amount` 0, `source` = split; original keeps discount/shipping/notes/shipment; `split_children` on the original; the acting user is the new order's `user_id`
- [X] T028 [P] [US3] Add guard tests to `tests/Feature/PreorderSplitTest.php`: `handed_over`/`cancelled` → 409; any payment row (even a small deposit) → 409; discount that would exceed the original's remaining subtotal+shipping → 409 and nothing changed; item id from another order → 422; qty > line qty → 422; nothing moved or everything moved → 422; unauthenticated → 401
- [X] T029 [P] [US3] Add invariant tests to `tests/Feature/PreorderSplitTest.php`: splitting an `arrived` order writes **no** `stock_movements` row and leaves `current_stock` identical; a forced failure mid-split (e.g. mocked late exception) leaves both the original and the table of orders untouched (all-or-nothing); the payment and status checks are re-evaluated after the row lock; no notification/mail; one `split` activity-log row naming the new number(s); DEMO/LIVE isolation; the pre-order and report totals before/after a split are identical (`GET /reports/sales` / `/reports/preorders` recognised revenue — FR-025/SC-006)

### Implementation for User Story 3

- [X] T030 [US3] Implement `PreorderService::split(Preorder $source, array $moves, User $user)` in `app/Services/PreorderService.php` per research.md Decision 4: transaction + `lockForUpdate()` re-read, status/payment guards after the lock (409 `ValidationException`s), move validation (422), re-parent whole lines / shrink-and-clone partial lines, recompute both orders' `subtotal`/`total_amount`, enforce the original's discount cap (409), `ActivityLogger` `split` row, **no `StockService` call** (Indonesian comment stating that invariant). Put the shared body in a private method so US4 reuses it
- [X] T031 [P] [US3] Create `app/Http/Requests/SplitPreorderRequest.php`: `mode` in `items|by_seller`; for `items`, `items` array ≥ 1 with integer `item_id` and integer `qty` ≥ 1; `authorize()` = authenticated
- [X] T032 [US3] Add `PreorderController::split()` in `app/Http/Controllers/Api/PreorderController.php`: map the service's guard `ValidationException`s to 409 and move/shape errors to 422 (CLAUDE.md status-code convention), respond `201 {original, created[]}` with `splitChildren`, `items.variant.product.category`, `payments.proofs`, `shipment`, `customer` loaded so `present()` is complete
- [X] T033 [US3] Register `Route::post('/preorders/{preorder}/split', …)` in `routes/api.php` beside the other `/preorders/{preorder}/…` routes
- [X] T034 [P] [US3] Add split messages to `lang/en/preorders.php` and `lang/id/preorders.php`: `split_not_allowed_status`, `split_not_allowed_has_payment`, `split_item_not_in_order`, `split_qty_exceeds_line`, `split_must_move_and_keep`, `split_discount_exceeds_remaining`
- [X] T035 [US3] Make "has any payment" known to the list row: check what `index()` already returns in `PreorderController` and, if `paid_amount` is not sufficient (e.g. a zero/rejected payment row exists), add a `has_payments` boolean computed without N+1 (single `withExists('payments')` on the list query) and document it in `docs/openapi-pos-mvp.yaml`
- [X] T036 [P] [US3] Create `qa-tests/component/PreorderSplitModal.test.js`: per-line numeric "units to move" inputs bounded to 0..qty, live preview of both subtotals, confirm disabled until ≥1 unit moves and ≥1 stays, backend 409/422 messages shown, submit payload shape `{mode:'items', items:[{item_id, qty}]}`
- [X] T037 [P] [US3] Create `resources/js/components/preorder/PreorderSplitModal.vue` (`BaseModal`, token classes only, direct numeric entry as in the 021 create form, subtotal preview computed from the line `sell_price` for display only — the server recomputes everything)
- [X] T038 [US3] Wire Split in `resources/js/views/PreordersView.vue`: row-action `split` hidden for `handed_over`/`cancelled`, **disabled with an explanatory `title`** when the order has payments (reuse the `payment_invoice` disabled pattern), a Split button in the detail panel, modal open/close, reload list+summary+open detail after success, and the "Split from / Split into" lines from `detail.source` / `detail.split_children`
- [X] T039 [US3] Add split strings (`preorders.split`, `split_title`, `split_units_to_move`, `split_disabled_has_payment`, `split_success`, `split_from`, `split_into`, preview labels) to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T040 [P] [US3] Extend `qa-tests/component/PreordersView.test.js`: Split absent for handed-over/cancelled, disabled-with-title when paid, enabled otherwise; successful split reloads and shows the relation lines
- [X] T041 [US3] Document `POST /preorders/{preorder}/split` (request modes, 201 shape, 409/422 cases) in `docs/openapi-pos-mvp.yaml`
- [X] T042 [US3] Run `php artisan test --filter=PreorderSplitTest` and `npm test`; fix until green

**Checkpoint**: US1–US3 each work independently.

---

## Phase 6: User Story 4 — Split by seller in one step (Priority: P3)

**Goal**: One click turns a multi-seller pre-order into one order per seller.

**Independent Test**: Take an order whose lines belong to two sellers → "Split by seller" → two orders, each with only one seller's lines; a single-seller order refuses with a clear message.

### Tests for User Story 4 ⚠️ write first

- [X] T043 [P] [US4] Add by-seller tests to `tests/Feature/PreorderSplitTest.php`: 2 sellers → original keeps the seller of the lowest-id line and one new order holds the other; 3 sellers → 2 new orders; whole lines only; same status/pickup/courier inheritance and the same guards (status, payment, discount cap) as manual mode; single seller → 422 `split_single_seller`; Σ subtotals reconcile; one activity-log row listing every new number
- [X] T044 [P] [US4] Extend `qa-tests/component/PreorderSplitModal.test.js`: the "Split by seller" button appears only when the order has ≥ 2 distinct sellers and submits `{mode:'by_seller'}`

### Implementation for User Story 4

- [X] T045 [US4] Add the `by_seller` mode to `PreorderService` in `app/Services/PreorderService.php` (research.md Decision 5), reusing the private split body from T030 — group lines by `artist_id`, deterministic seller stays, one new order per other seller; no second copy of the guard logic
- [X] T046 [US4] Teach `PreorderController::split()` / `SplitPreorderRequest` in `app/Http/Controllers/Api/PreorderController.php` and `app/Http/Requests/SplitPreorderRequest.php` to dispatch `mode: by_seller`
- [X] T047 [P] [US4] Add `split_single_seller` to `lang/en/preorders.php` and `lang/id/preorders.php`
- [X] T048 [US4] Add the "Split by seller" button (visible only for ≥ 2 sellers, using `detail.sellers`) to `resources/js/components/preorder/PreorderSplitModal.vue`, and its strings to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [X] T049 [US4] Add the `by_seller` request/response to the split entry in `docs/openapi-pos-mvp.yaml`
- [X] T050 [US4] Run `php artisan test --filter=PreorderSplitTest` and `npm test`; fix until green

**Checkpoint**: All four stories complete.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T051 [P] Add a cross-feature regression test in `tests/Feature/PreorderDuplicateSplitReportsTest.php`: duplicated and split orders appear correctly in `GET /preorders` (filters/search/sort), `GET /preorders/summary`, `GET /preorders/export` and `GET /preorders/{id}/invoice`; revenue/cash recognition is not double counted after a split (FR-024, FR-025)
- [X] T052 [P] Add a dated note to `docs/PRD-POS-Event-Multivendor.md` recording the new post-MVP capability (duplicate/split pre-orders); no cut item is being restored
- [X] T053 Add a short entry to `CLAUDE.md` (Pre-order section): duplicate wraps `create()`, split never touches stock and is blocked by any payment, `has_payments`/`split_children` payload traps, activity-log actions `duplicated`/`split`
- [X] T054 Run the full backend suite safely (host: `php artisan test`; Docker: only with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`) and `npm test`, `npm run build`; record counts and fix any regression
- [X] T055 Execute every step of `specs/027-preorder-duplicate-split/quickstart.md` in a real browser against the real API (Constitution II): duplicate one/many, failure path, split, split by seller, blocked states, EN↔ID strings, browser console free of errors; note the evidence in the final report
- [X] T056 Review the diff once for Constitution compliance (no raw hex, no second stock/log/payment write path, `openapi` moved in the same commit) and for Indonesian comments on every non-obvious decision

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 → Phase 2 → user stories → Polish.** Phase 2 blocks everything.
- **US1** has no dependency on other stories. **US2** builds on US1's endpoint/`doDuplicate()` (its backend is already in T012; US2 adds bulk tests + UI). **US3** is independent of US1/US2 on the backend but shares `PreordersView.vue`, locale files and `docs/openapi-pos-mvp.yaml` with them, so schedule those edits sequentially. **US4** depends on US3 (shared split body and modal).

### Within each story

Tests (fail first) → service → request/controller → route → lang → frontend → docs → green run.

### Same-file serialisation (cannot be [P] together)

`app/Services/PreorderService.php` (T010, T030, T045) · `app/Http/Controllers/Api/PreorderController.php` (T004, T012, T032, T046, T035) · `routes/api.php` (T013, T033) · `resources/js/views/PreordersView.vue` (T015, T023, T038) · `resources/js/locales/{en,id}.json` (T016, T024, T039, T048) · `lang/{en,id}/preorders.php` (T014, T034, T047) · `docs/openapi-pos-mvp.yaml` (T018, T035, T041, T049) · `tests/Feature/PreorderDuplicateTest.php` (T008, T009, T020) · `tests/Feature/PreorderSplitTest.php` (T027–T029, T043) · `qa-tests/component/PreordersView.test.js` (T017, T025, T040).

---

## Parallel Examples

**Foundational**: after T002–T004 land, T005 (frontend API) and T006 (test) run in parallel.

**User Story 1 tests/impl**:

```text
T008 PreorderDuplicateTest.php (core)   ┐ parallel — different concerns,
T011 DuplicatePreordersRequest.php       │ but T009 edits the same test file as T008 → run after it
T014 lang en/id messages                 │
T017 PreordersView.test.js               ┘
```

**User Story 3**: T027/T028/T029 are written in one pass (same file) while T031 (request class), T034 (lang), T036 (Vitest) and T037 (modal component) proceed in parallel; T030 → T032 → T033 are sequential.

---

## Implementation Strategy

### MVP first (User Story 1)

1. Phase 1 → Phase 2 → Phase 3, then **stop and validate**: duplicate a real pre-order in the browser. This alone removes the most common re-keying work and can ship by itself.

### Incremental delivery

1. + US2 (bulk) — small, mostly frontend, ships next.
2. + US3 (split) — the larger, riskier slice; keep it behind the full guard test suite (T027–T029) before wiring the UI.
3. + US4 (by seller) — convenience on top of US3.
4. Polish (T051–T056) before any merge.

### Notes

- Commit per story (or per logical group) with Indonesian messages; do not push without the developer's explicit instruction.
- Each checkpoint is a safe stopping point: the app keeps working and every earlier story stays demoable.
