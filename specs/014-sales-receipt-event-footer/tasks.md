---

description: "Task list for Restore Sales Receipt Action & Event Info in Receipt Footers"

---

# Tasks: Restore Sales Receipt Action & Event Info in Receipt Footers

**Input**: Design documents from `/specs/014-sales-receipt-event-footer/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: Included — Constitution Principle II requires `tests/Feature/` coverage for every backend change and a real-browser check for every touched screen; not optional for this project.

**Organization**: Tasks are grouped by user story (US1 P1, US2 P2, matching spec.md's priorities).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1, US2)

## Path Conventions

Existing single Laravel + Vue repo, branched from `main`: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`, `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Confirm dev environment is ready: `laradock-mysql-1` running, `.env.testing` present, `npm run dev`/`php artisan serve` both start clean — no code changes

---

## Phase 2: Foundational

**Note**: No schema changes, and US1/US2 touch entirely disjoint files (US1: `SalesView.vue` only; US2: `Preorder.php`, both controllers, `ReceiptModal.vue`, `PreorderInvoiceModal.vue`) — there is no shared blocking work. Proceed directly to User Story phases; US1 and US2 may be worked in either order or in parallel.

---

## Phase 3: User Story 1 - View a completed sale's printed receipt again from the Sales list (Priority: P1) 🎯 MVP

**Goal**: Restore the ability to view/download a sale's actual receipt from the Sales list, without disturbing the existing "products sold" popup.

**Independent Test**: Open `/sales`, trigger "View receipt" on a row, confirm the same receipt document opens with print/download working, and "View items" on the same row still opens the products-sold popup unchanged (quickstart.md US1).

- [X] T002 [US1] In `resources/js/views/SalesView.vue`, re-import `ReceiptModal.vue` (already exists, unchanged, at `resources/js/components/receipt/ReceiptModal.vue`), add `showReceipt`/`receiptOrderId` refs, and an `openReceipt(row)` function setting `receiptOrderId.value = row.id; showReceipt.value = true` (mirrors the existing `openItems(row)` pattern in this same file)
- [X] T003 [US1] In the same file's `#cell-actions` template (where the existing "View items" button already lives, calling `openItems(row)`), add a second button calling `openReceipt(row)`, and mount `<ReceiptModal :open="showReceipt" :order-id="receiptOrderId" @close="showReceipt = false" />` alongside the existing `TransactionItemsModal` instance — do not remove or alter the existing "View items" button/modal
- [X] T004 [P] [US1] Frontend test in `qa-tests/component/SalesView.test.js`: clicking "View receipt" calls the already-existing `getReceipt(id)` API function with the row's `id` and opens the receipt modal; clicking "View items" on the same row still opens the products-sold popup exactly as before (regression check for FR-003's "additive, not a replacement" requirement)
- [X] T005 [US1] Manual browser verification (quickstart.md US1): "View receipt" opens the correct transaction's receipt, download works, "View items" popup still works unchanged

**Checkpoint**: User Story 1 fully functional and independently testable/demoable — this is the feature's MVP.

---

## Phase 4: User Story 2 - Event name, location, and dates appear in the receipt/invoice footer (Priority: P2)

**Goal**: Both the POS receipt and the pre-order invoice/receipt show the associated event's name, location, and dates in their footer, gracefully handling a missing event or partial event data.

**Independent Test**: Open a receipt and an invoice for event-tied transactions and confirm the footer shows name/location/dates; confirm a preorder with no event shows no footer block at all (quickstart.md US2).

- [X] T006 [US2] Add `event(): BelongsTo` relation to `app/Models/Preorder.php` (mirrors the already-existing `Order::event()` in `app/Models/Order.php`) — no migration, `preorders.event_id` already exists and is already nullable
- [X] T007 [US2] In `app/Http/Controllers/Api/PreorderController.php`, add `'event'` to `invoice()`'s existing `->load(['items', 'payments', 'customer'])` call, and add `event_name`/`event_location`/`event_start_date`/`event_end_date` to its JSON response, each `null` when the preorder has no `event_id` (research.md R2)
- [X] T008 [US2] In `app/Http/Controllers/Api/OrderController.php`'s `receipt()` action, add `event_location`/`event_start_date`/`event_end_date` to its response alongside the already-existing `event_name` (the `event` relation is already eager-loaded there — no `->load()` change needed)
- [X] T009 [US2] Backend test in `tests/Feature/OrderTest.php`: `GET /orders/{id}/receipt` includes `event_location`/`event_start_date`/`event_end_date` matching the seeded event's own values
- [X] T010 [US2] Backend test in `tests/Feature/PreorderTest.php`: `GET /preorders/{id}/invoice` includes all four event fields matching the seeded event when the preorder has one, AND all four are `null` when the preorder's `event_id` is null
- [X] T011 [US2] Add an event-info footer block to `resources/js/components/receipt/ReceiptModal.vue` — location plus a computed date display (both dates present and different → range; both present and identical → single date per FR-007; only one present → that one alone; neither present and no location → omit the whole block per FR-006), reusing `formatDate()` from `resources/js/utils/date.js` (research.md R3)
- [X] T012 [US2] Add the same event-info footer block (same conditional logic as T011) to `resources/js/components/preorder/PreorderInvoiceModal.vue`, additionally omitting the entire block when `event_name` is null (preorder has no event at all, per FR-005)
- [X] T013 [P] [US2] Add any new i18n keys the footer block needs (e.g. a "location" label) to `resources/js/locales/id.json`/`en.json` — reuse an existing key if this codebase already has an equivalent label elsewhere, don't duplicate
- [X] T014 [US2] Frontend test for `ReceiptModal.vue`'s footer (existing or new test file under `qa-tests/component/`): renders location + date range; single-day event renders one date, not a range; block is present when location/dates exist
- [X] T015 [US2] Frontend test for `PreorderInvoiceModal.vue`'s footer: renders event info when present; entirely omits the block (no dangling labels) when the preorder has no event
- [X] T016 [US2] Manual browser verification (quickstart.md US2): both documents show event info correctly, a no-event preorder shows no block, a partial-data event (one date, or no location) renders gracefully, downloaded files match

**Checkpoint**: User Story 2 fully functional and independently testable/demoable.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T017 [P] Update `docs/openapi-pos-mvp.yaml` per `contracts/api-deltas.md`: the four new fields on both `GET /orders/{order}/receipt` and `GET /preorders/{preorder}/invoice` response schemas
- [X] T018 Full regression: `php artisan test` and `npm test` both fully green; fix any pre-existing or newly-surfaced failures found along the way (Constitution II)
- [X] T019 If any real bug was found and fixed during execution (per this repo's established convention), document it in `README.md`'s dated bug-log style

---

## Dependencies & Execution Order

- **Phase 2 (Foundational)** has no actual blocking work — noted only to confirm US1 and US2 are file-disjoint and independent.
- **Phase 3 (US1)** and **Phase 4 (US2)** have zero file overlap (`SalesView.vue` vs. `Preorder.php`/both controllers/`ReceiptModal.vue`/`PreorderInvoiceModal.vue`) and no data dependency on each other — they may be implemented in either order, or in parallel by two different agents.
- **Phase 5 (Polish)** runs last, after both user stories are complete.

## Parallel Execution Examples

```text
# US1 and US2 can run fully in parallel from the start (zero file overlap):
T002, T003, T004, T005 (US1)  ‖  T006, T007, T008, T009, T010, T011, T012, T013, T014, T015, T016 (US2)

# Within US2, backend (T006-T010) and the locale task (T013) don't block each other:
T006 → T007, T008 (sequential, same-ish concern) ‖ T013 [P] (locale file, independent)
```

## Implementation Strategy

**MVP first**: Phase 3 (US1) alone restores the removed capability and is fully shippable on its own — the suggested MVP scope.

**Incremental delivery**: Phase 4 (US2) is a self-contained enhancement to both receipt documents and can follow immediately after, or be delivered in the same pass since it touches entirely different files.
