---

description: "Task list for Preorder List Filters, Seller Info & Receipt-Style Invoice"

---

# Tasks: Preorder List Filters, Seller Info & Receipt-Style Invoice

**Input**: Design documents from `/specs/013-preorder-list-filters-receipt/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: Included — Constitution Principle II requires `tests/Feature/` coverage for every backend change and a real-browser check for every touched screen; not optional for this project.

**Organization**: Tasks are grouped by user story, in priority order (US1 P1, US3 P1, US2 P2, US5 P2, US4 P3 — spec.md's own numbering interleaves priorities, this reorders strictly by priority per the Phase Structure rule).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1, US2, US3, US4, US5)

## Path Conventions

Existing single Laravel + Vue repo, branched from `012-seller-preorder-report-detail-export`: `app/`, `resources/js/`, `routes/api.php`, `tests/Feature/`, `qa-tests/`, `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Confirm dev environment is ready: `laradock-mysql-1` running, `.env.testing` present, `npm run dev`/`php artisan serve` both start clean — no code changes

---

## Phase 2: Foundational

**Blocking prerequisite for both P1 stories (US1 and US3)** — surfacing `preorder_items.artist_id` (research.md R1) is needed by US1's filter/column AND US3's per-item seller line on the invoice. Must complete before Phase 3/4.

- [X] T002 Add `artist(): BelongsTo` relation (→ `Artist::class` via existing `artist_id` column) to `app/Models/PreorderItem.php` — no migration, the column already exists
- [X] T003 In `app/Http/Controllers/Api/PreorderController.php`, extract the existing `status`/`event_id`/`customer_id`/`fulfillment`/`search` `when(...)` chain out of `index()` into a private `applyFilters(Builder $query, Request $request): Builder` helper; `index()` calls it unchanged in behavior (research.md R4 — this is what lets `summary()` in Phase 6 reuse the exact same filter logic without duplicating it)
- [X] T004 In `app/Http/Controllers/Api/PreorderController.php`, add a private `sellersFor(Preorder $preorder): array` helper that returns the distinct `{id, name}` set across `$preorder->items` (each via its new `artist` relation, first-appearance order, skipping any item whose artist no longer resolves); wire it into `present()` as a top-level `sellers` field and into each `items[]` entry as `artist_id`/`artist_name` (data-model.md)

**Checkpoint**: `PreorderItem::artist()` resolves correctly, `applyFilters()` produces identical results to the old inline chain, `present()`'s `sellers`/`items[].artist_*` fields are populated — verify via `php artisan tinker` on one seeded multi-seller preorder before starting Phase 3.

---

## Phase 3: User Story 1 - Filter and identify preorders by seller (Priority: P1) 🎯 MVP

**Goal**: A seller filter narrows the Pre-orders list; every row and the detail view show which seller(s) are involved.

**Independent Test**: Open the Pre-orders list, pick a seller from the new filter, confirm only preorders with at least one matching item show, with a visible seller column/detail per quickstart.md US1.

- [X] T005 [US1] In `app/Http/Controllers/Api/PreorderController.php`, add an `artist_id` clause to `applyFilters()` using `whereHas('items', fn ($q) => $q->where('artist_id', $artistId))` (research.md R2 — NOT a join, so a preorder with several matching items is never duplicated); eager-load `items.artist` in `index()`'s query and wire each row's `sellers` via `sellersFor()`
- [X] T006 [US1] Backend test in `tests/Feature/PreorderTest.php`: `GET /preorders?artist_id=X` returns only preorders with a matching item, combines (AND) with existing `status`/`fulfillment`/`search` filters, and a preorder with 3 items from the same filtered seller appears exactly once (not 3 times)
- [X] T007 [US1] Backend test in `tests/Feature/PreorderTest.php`: list rows (`index()`) and the detail response (`show()`) both include `sellers: [{id, name}]`, and detail `items[]` entries include `artist_id`/`artist_name`; a preorder spanning 2 sellers shows both, not just the first
- [X] T008 [US1] Add a seller filter (`BaseSelect`, "All sellers" + `listArtists({per_page: 100, is_active: true})`, single-select — same pattern as the Reports screen's seller filter from `012`) to `resources/js/views/PreordersView.vue`, wired via the existing `setFilter({ artist_id: v || undefined })` mechanism already used for `status`/`fulfillment`
- [X] T009 [US1] Add a "Seller" column to the Pre-orders `DataTable` in `resources/js/views/PreordersView.vue` rendering `row.sellers.map(s => s.name).join(', ')` (or an em-dash placeholder if empty)
- [X] T010 [US1] In `resources/js/views/PreordersView.vue`'s detail drawer, show each line item's seller (`line.artist_name`) alongside its existing `name_snapshot`/`sku_snapshot`
- [X] T011 [P] [US1] Add `preorders.col_seller` (and any other new US1 label) to `resources/js/locales/id.json` and `resources/js/locales/en.json`
- [X] T012 [US1] Frontend test in `qa-tests/component/PreordersView.test.js`: selecting a seller from the filter calls `listPreorders` with `artist_id`, and a mocked multi-seller row renders all seller names in the seller column
- [X] T013 [US1] Manual browser verification (quickstart.md US1): seller filter narrows the list, seller column and detail both show every involved seller, filter combines with existing filters, resetting to "All sellers" restores the full list — check in both DEMO and LIVE mode

**Checkpoint**: User Story 1 fully functional and independently testable/demoable.

---

## Phase 4: User Story 3 - Receipt-styled preorder invoice with clear preorder marking (Priority: P1)

**Goal**: The preorder invoice document uses the POS receipt's visual conventions while unmistakably showing "Pre-order" and the preorder's live current status.

**Independent Test**: Open a preorder's invoice at any status, confirm receipt-style layout + Pre-order marking + current status per quickstart.md US3.

- [X] T014 [US3] Restyle `resources/js/components/preorder/PreorderInvoiceModal.vue`: add a centered header block reusing `PreorderPaymentReceiptModal.vue`'s existing `preorder_marking_label` badge + `StatusPill` (same `STATUS_LABEL_KEY`/`STATUS_VARIANT` maps) showing the preorder's live granular `status`, keep the existing `document_type`-driven heading/footer logic (invoice/receipt/cancelled) unchanged, restructure the item list to use the same dashed-line-separator and prominent-total typography conventions `ReceiptModal.vue`/`PreorderPaymentReceiptModal.vue` already use
- [X] T015 [US3] In `PreorderInvoiceModal.vue`'s item rows, display each item's seller (`item.artist_name`, from Phase 2's T004) mirroring `ReceiptModal.vue`'s existing `item.artist_name` per-line display
- [X] T016 [P] [US3] Add any new locale keys the restyled invoice needs (e.g. a status-line label, if `document_*`/`preorder_marking_label` don't already cover it) to `resources/js/locales/id.json`/`en.json`
- [X] T017 [US3] Frontend test covering the restyled `PreorderInvoiceModal.vue`: renders the Pre-order marking badge + a `StatusPill` matching `invoice.status` (not just `document_type`), and renders `item.artist_name` per line
- [X] T018 [US3] Manual browser verification (quickstart.md US3): open the invoice at several different preorder statuses, confirm the status shown always matches the preorder's CURRENT status (advance a preorder's status, reopen, confirm it updated), download the PDF and confirm the downloaded file matches the on-screen layout

**Checkpoint**: User Stories 1 AND 3 (both P1) independently functional — this is the feature's MVP.

---

## Phase 5: User Story 2 - Open preorder detail by clicking the transaction number (Priority: P2)

**Goal**: Clicking a preorder number opens the same detail view as the existing "Detail" button.

**Independent Test**: Click a preorder number in the list, confirm the detail view opens, per quickstart.md US2.

- [X] T019 [US2] In `resources/js/views/PreordersView.vue`'s `#cell-preorder_number` template, wrap the existing `<span>` in a clickable element (`<button>`) calling the already-existing `openDetail(row)` — no new function, no new state (research.md R7)
- [X] T020 [P] [US2] Frontend test in `qa-tests/component/PreordersView.test.js`: clicking the preorder number opens the same detail view/drawer as clicking the existing "Detail" action
- [X] T021 [US2] Manual browser verification (quickstart.md US2)

**Checkpoint**: User Story 2 independently functional.

---

## Phase 6: User Story 5 - Summary statistics for the preorder list (Priority: P2)

**Goal**: The Pre-orders screen shows transaction count, per-status totals, grand total, and total outstanding, recomputed as filters change.

**Independent Test**: Load the list with known preorders, confirm the summary figures match a manual sum, and that they update when filters (including the new seller filter) are applied, per quickstart.md US5.

- [X] T022 [US5] Add a `summary(Request $request): JsonResponse` action to `app/Http/Controllers/Api/PreorderController.php` reusing Phase 2's `applyFilters()`: `transaction_count` (COUNT), `by_status` (SUM `total_amount` GROUP BY `status`, zero-filled for all 6 statuses per contracts/api-deltas.md), `grand_total` (SUM `total_amount`), `total_outstanding` (SUM `total_amount` − SUM `paid_amount`, computed from raw decimal sums then formatted once — not a sum of pre-rounded per-row `outstanding` strings, per research.md R3)
- [X] T023 [US5] Add `GET /preorders/summary` route in `routes/api.php`, same authorization as the existing `GET /preorders` route (placed BEFORE the `GET /preorders/{preorder}` route so `"summary"` doesn't get swallowed as a route-model-binding id)
- [X] T024 [US5] Backend test in `tests/Feature/PreorderTest.php`: `summary()`'s figures match a manual sum of seeded preorders, respects every `applyFilters()` predicate (including the new `artist_id`), always lists all 6 statuses even at "0.00", and stays isolated per DEMO/LIVE `data_mode`
- [X] T025 [P] [US5] Add `getPreorderSummary(params = {})` to `resources/js/api/preorders.js` (`GET /preorders/summary`)
- [X] T026 [US5] Add a summary display (transaction count, per-status totals, grand total, total outstanding) to `resources/js/views/PreordersView.vue`, refetched via `getPreorderSummary()` whenever the same filters driving the list (`status`, `fulfillment`, `search`, and the new `artist_id`) change
- [X] T027 [P] [US5] Add new i18n keys for the summary labels to `resources/js/locales/id.json`/`en.json`
- [X] T028 [US5] Frontend test in `qa-tests/component/PreordersView.test.js`: summary figures render from `getPreorderSummary()`'s response and refetch when the seller/status/fulfillment filters change
- [X] T029 [US5] Manual browser verification (quickstart.md US5): summary figures match a manual check, recompute when filters are applied, cancelled preorders still contribute to their own status bucket

**Checkpoint**: User Story 5 independently functional.

---

## Phase 7: User Story 4 - Rename "Print" to "Receipt" everywhere it appears (Priority: P3)

**Goal**: Every "Print"-wording label on the Pre-orders screen reads "Receipt" wording instead, in both languages.

**Independent Test**: Switch languages, confirm no "Print"/"Cetak" wording remains on this screen and behavior is unchanged, per quickstart.md US4.

- [X] T030 [P] [US4] Rename `preorders.print_action` ("Cetak"→"Struk" / "Print"→"Receipt") and `preorders.print_payment_receipt` ("Cetak struk pembayaran"→"Struk pembayaran" / "Print payment receipt"→"Receipt") in `resources/js/locales/id.json` and `resources/js/locales/en.json` (research.md R6 — these are the ONLY two "print"-wording keys under the `preorders` namespace; `purchase_orders.print_invoice` is a different screen, out of scope)
- [X] T031 [US4] Manual verification: switch between Indonesian and English on the Pre-orders screen, confirm no leftover "Print"/"Cetak" wording anywhere on it, and confirm both renamed actions still behave exactly as before

**Checkpoint**: All 5 user stories independently functional — feature complete.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T032 [P] Update `docs/openapi-pos-mvp.yaml` per `contracts/api-deltas.md` in full: `artist_id` query param and `sellers`/`items[].artist_id`/`items[].artist_name` response fields on `GET /preorders` and `GET /preorders/{preorder}`, and the new `GET /preorders/summary` path
- [X] T033 Full regression: `php artisan test` and `npm test` both fully green; fix any pre-existing or newly-surfaced failures found along the way (Constitution II — do not defer a found regression past this feature)
- [X] T034 If any real bug was found and fixed during execution (per this repo's established convention), document it in `README.md`'s dated bug-log style

---

## Dependencies & Execution Order

- **Phase 2 (Foundational)** blocks Phase 3 (US1) and Phase 4 (US3) — both need `PreorderItem::artist()` and the `sellers`/`items[].artist_*` fields.
- **Phase 3 (US1)** and **Phase 4 (US3)** are independent of each other (different files: US1 touches the list/filter/column; US3 touches only `PreorderInvoiceModal.vue`) but both depend on Phase 2 — may be worked in either order or in parallel by different agents once Phase 2 is done.
- **Phase 5 (US2)**, **Phase 6 (US5)**, and **Phase 7 (US4)** have no dependency on Phase 2, 3, or 4 at all — they touch `PreordersView.vue`'s number cell, a brand-new `summary()` endpoint, and locale files respectively. They CAN be started immediately, in parallel with Phase 2/3/4, by different agents — sequenced after in this document purely to follow priority order, not because of a real blocking dependency. The only real file-overlap risk is that US1 (T008–T010), US2 (T019), and US5 (T026) all edit `resources/js/views/PreordersView.vue` — those three tasks must not run concurrently against the same file version.
- **Phase 8 (Polish)** runs last, after every user story is complete.

## Parallel Execution Examples

```text
# After Phase 2 completes, US1 and US3 backend/test work can run in parallel (different files):
T006, T007 (PreorderTest.php, US1)  ‖  T017 (invoice modal test, US3)

# Locale-only and API-client-only tasks never conflict with view-file edits — always parallelizable:
T011 [P]  ‖  T016 [P]  ‖  T020 [P]  ‖  T025 [P]  ‖  T027 [P]  ‖  T030 [P]  ‖  T032 [P]
```

## Implementation Strategy

**MVP first**: Phase 2 (Foundational) → Phase 3 (US1) → Phase 4 (US3) delivers both P1 stories — seller visibility and the receipt-style invoice — as a demoable increment before touching the P2/P3 stories.

**Incremental delivery after MVP**: Phase 5 (US2, click-to-detail) and Phase 7 (US4, rename) are the cheapest remaining stories and can be slotted in whenever convenient; Phase 6 (US5, summary statistics) is the largest remaining chunk of new backend surface (a new endpoint) and is best done as its own increment.
