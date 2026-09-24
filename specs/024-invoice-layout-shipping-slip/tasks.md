---

description: "Task list for Pre-order Invoice Layout Refinements & Shipping Slip"

---

# Tasks: Pre-order Invoice Layout Refinements & Shipping Slip

**Input**: Design documents from `/specs/024-invoice-layout-shipping-slip/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/invoice-created-at.md, quickstart.md

**Tests**: Included — Constitution II requires Feature tests for every service/endpoint change in this codebase, and plan.md's Constitution Check commits to them explicitly.

**Organization**: Tasks are grouped by user story (US1–US5, matching spec.md's priorities P1/P2/P1/P2/P3) so each is independently completable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to
- Backend tests run via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Test>` (per CLAUDE.md's documented environment bug)

## Path Conventions

Existing single web app: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`, `docs/` at repo root (see plan.md's Project Structure).

---

## Phase 1: Setup

**Purpose**: None needed — no schema change, no new dependency. This phase is intentionally empty; work starts directly at Foundational.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The one shared backend field every user story's frontend work displays (US1's creation date). Everything else needed by every story is already returned by the existing invoice payload (confirmed in research.md), so this is the only blocking prerequisite.

- [X] T001 Add `created_at` to `PreorderController::present()` in `app/Http/Controllers/Api/PreorderController.php` (research.md Decision 1) — `$preorder->created_at?->toIso8601String()`.
- [X] T002 [P] Write `tests/Feature/PreorderInvoiceCreatedAtTest.php`: `GET /preorders/{id}`, `GET /preorders/{id}/invoice`, and `POST /preorders/bulk-invoices` all include a non-null `created_at` matching the pre-order's actual creation time.
- [X] T003 [P] Update `docs/openapi-pos-mvp.yaml`'s Preorder schema with `created_at`.

**Checkpoint**: `created_at` is available on every invoice payload — ready for US1's frontend display.

---

## Phase 3: User Story 1 - A clearer, better-organized invoice header (Priority: P1) 🎯 MVP

**Goal**: The invoice header renders as two columns — event info (centered) on the left, order/recipient info on the right — with the pre-order's creation date visible.

**Independent Test**: Open any pre-order invoice and confirm the two-column layout: left = event name/location/available-on (centered, each omitted individually if unset); right = pre-order number/status/"To:" block (customer name/email/phone/social handle/address, each omitted individually if unset); creation date visible near the order identity.

### Implementation for User Story 1

- [X] T004 [US1] In `resources/js/components/preorder/PreorderInvoiceModal.vue`, restructure the header into a `grid grid-cols-2 gap-4` layout: left column = event name (new) + the existing standout location/available-on block (rows now individually `text-center`, not `justify-between`); right column = pre-order number + status pill (existing) + a new "To:" block reading `invoice.customer.{name,email,phone,social_handle,address}`, each line `v-if`-guarded (research.md Decisions 2–3). Store-identity block stays full-width above both columns.
- [X] T005 [US1] Add the pre-order's creation date display (`formatDate(invoice.created_at)` or `formatDateTime`) near the order-identity content in the right column of `PreorderInvoiceModal.vue`.
- [X] T006 [US1] Apply the same two-column header + creation-date changes to `resources/js/components/preorder/PreorderPaymentReceiptModal.vue` (shares the invoice shell per feature 022/023 precedent).
- [X] T007 [P] [US1] Add new locale keys (`preorders.to_label`, `preorders.created_at_label`, `preorders.event_name_label` if not already covered by `events_sessions.col_event_name`) to `resources/js/locales/{id,en}.json`.
- [X] T008 [P] [US1] Add/update Vitest cases in `qa-tests/component/PreorderInvoiceModal.test.js`: two-column header renders event name/location/available-on in the left column and pre-order number/status/"To:" fields in the right column; individual "To:" fields are omitted when absent from the customer payload; creation date renders.

**Checkpoint**: User Story 1 fully functional and independently testable.

---

## Phase 4: User Story 2 - The invoice document is clearly labeled and dated (Priority: P2)

**Goal**: The invoice window's title identifies it as a pre-order invoice.

**Independent Test**: Open a pre-order invoice and confirm the modal's title bar reads as a pre-order invoice, not just the bare order number.

### Implementation for User Story 2

- [X] T009 [US2] Add a new locale key `preorders.document_title_invoice` ("Pre-order Invoice" / "Invoice Pre-order") to `resources/js/locales/{id,en}.json` (research.md Decision 6 — deliberately NOT reusing `document_invoice_title`, which stays driving the smaller in-body badge).
- [X] T010 [US2] In `resources/js/components/preorder/PreorderInvoiceModal.vue`, change the `BaseModal`'s `:title` binding from `invoice?.preorder_number` to a combination of the new title key and the pre-order number (e.g. `` `${t('preorders.document_title_invoice')} — ${invoice?.preorder_number}` ``).
- [X] T011 [P] [US2] Add/update a Vitest case in `qa-tests/component/PreorderInvoiceModal.test.js` asserting the modal's title includes "Pre-order Invoice" text, not just the bare number.

**Checkpoint**: User Stories 1–2 both independently functional.

---

## Phase 5: User Story 3 - Payment options are easier to scan, with a bigger QR (Priority: P1)

**Goal**: Payment channels split into a QR column and a bank-transfer column; QR grows again.

**Independent Test**: Open an invoice for a store with both channel types configured — confirm two visually separate columns; confirm the QR is larger than feature 023's size; confirm a store with only one type shows only that column.

### Implementation for User Story 3

- [X] T012 [US3] In `resources/js/components/preorder/PreorderInvoiceModal.vue`, add computed `qrChannels`/`bankChannels` (filtering `invoice.payment_channels` by `type`, research.md Decision 4) and restructure the payment-terms section into a `grid grid-cols-2 gap-3` (each column individually `v-if`-guarded on its array being non-empty).
- [X] T013 [US3] Enlarge the QR thumbnail in the QR column from `h-24 w-24` to `h-32 w-32`.
- [X] T014 [US3] Apply the same two-column payment-terms + QR-size change to `resources/js/components/preorder/PreorderPaymentReceiptModal.vue`.
- [X] T015 [P] [US3] Add/update Vitest cases in `qa-tests/component/PreorderInvoiceModal.test.js`: QR-type and bank-transfer-type channels render in separate columns; a payload with only one type shows only that column; the QR image's size class reflects the new dimensions.

**Checkpoint**: User Stories 1–3 all independently functional.

---

## Phase 6: User Story 4 - A separate shipping slip for Mail Order pre-orders (Priority: P2)

**Goal**: A shipping-slip section appears for Mail Order pre-orders only, showing event/order-number/from/to/item-type, without depending on a `Shipment` record.

**Independent Test**: Open a Mail Order pre-order's invoice — confirm the shipping slip appears with all five pieces of information; open a Self Pickup pre-order's invoice — confirm it's absent; confirm it works even with no `Shipment` record created.

### Implementation for User Story 4

- [X] T016 [US4] In `resources/js/components/preorder/PreorderInvoiceModal.vue`, add a computed `showShippingSlip = invoice.fulfillment === 'courier'` and a computed `itemTypes` (de-duplicated `invoice.items[].name_snapshot`, research.md Decision 5).
- [X] T017 [US4] Add the shipping-slip section template (visible only when `showShippingSlip`) showing: event name (`invoice.event_name`), pre-order number, "From" (reusing `invoice.store_identity`), "To" (reusing `invoice.customer`, same fields as US1's header block), and the `itemTypes` list.
- [X] T018 [US4] Apply the same shipping-slip section to `resources/js/components/preorder/PreorderPaymentReceiptModal.vue`.
- [X] T019 [P] [US4] Add new locale keys (`preorders.shipping_slip_title`, `preorders.from_label`, `preorders.item_type_label`) to `resources/js/locales/{id,en}.json`.
- [X] T020 [P] [US4] Add Vitest cases in `qa-tests/component/PreorderInvoiceModal.test.js`: shipping slip renders for `fulfillment: 'courier'` with all five pieces of information; is absent for `fulfillment: 'pickup'`; renders correctly with no `invoice.shipment` present in the payload (proving no dependency on it).

**Checkpoint**: User Stories 1–4 all independently functional.

---

## Phase 7: User Story 5 - The store's closing message still appears (Priority: P3)

**Goal**: Confirm the existing footer message is unaffected by this feature's layout changes.

**Independent Test**: Confirm a store with a configured footer message still sees it, unchanged, after all layout changes.

### Implementation for User Story 5

- [X] T021 [US5] Manually verify (no code change expected) that the existing `<p v-if="invoice.footer_text">` block in `PreorderInvoiceModal.vue` still renders in its existing position after the header/payment-column/shipping-slip restructuring in US1/US3/US4 — reposition only if a layout conflict is found while doing those tasks.

**Checkpoint**: All five user stories independently functional.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Final consistency pass across the whole feature.

- [X] T022 [P] Run the full backend suite: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`.
- [X] T023 [P] Run the full frontend suite: `npm test -- --run`.
- [X] T024 Execute every scenario in `specs/024-invoice-layout-shipping-slip/quickstart.md` against the dev stack via real-browser verification (Constitution II), including the payment-invoice parity check.
- [X] T025 Verify `docs/openapi-pos-mvp.yaml` is fully in sync with the `created_at` addition (PRD §9.5 / CLAUDE.md API conventions).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Empty — no dependencies.
- **Foundational (Phase 2)**: Depends on Setup. Blocks US1 (needs `created_at` on the payload) — does not block US2/US3/US4/US5, which use only already-existing fields.
- **US1 (Phase 3)**: Depends on Foundational.
- **US2 (Phase 4)**: Independent of Foundational and US1 — pure locale-key + title-binding change.
- **US3 (Phase 5)**: Independent of Foundational/US1/US2 — pure payment-channel filtering, already-existing data.
- **US4 (Phase 6)**: Independent of Foundational/US1/US2/US3 — pure fulfillment-gated section, already-existing data.
- **US5 (Phase 7)**: A verification-only checkpoint depending on US1/US3/US4 having been implemented (to confirm no layout conflict), not new functionality.
- **Polish (Phase 8)**: Depends on all desired stories being complete.

**Note on file overlap**: US1/US2/US3/US4 all touch the same two files (`PreorderInvoiceModal.vue`, `PreorderPaymentReceiptModal.vue`), so while they are logically independent, implementing them in true parallel by different contributors would require careful merge coordination — in practice, within one contributor's session, they are naturally sequenced one after another against the same file.

### Suggested MVP Scope

User Story 1 (Phase 3, after Foundational) delivers the single biggest
readability improvement on its own — the two-column header restructuring
is what the rest of the request's line items build on top of visually.

### Parallel Opportunities

- T002/T003 (Foundational) run in parallel with T001 is sequential (T001 must land first since T002 tests it).
- T007/T008 (US1 locale keys + tests) can run in parallel with each other once T004–T006 land.
- US2, US3, and US4's locale-key/test tasks (T011, T015, T019/T020) are each independent of the others' implementation tasks and can be parallelized across contributors if working on different stories simultaneously.

---

## Parallel Example: Foundational

```bash
# T001 first (blocking):
Task: "Add created_at to PreorderController::present()"

# Then in parallel:
Task: "Write PreorderInvoiceCreatedAtTest.php"
Task: "Update docs/openapi-pos-mvp.yaml with created_at"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Complete Phase 2: Foundational.
2. Complete Phase 3: User Story 1.
3. **STOP and VALIDATE**: run quickstart.md's US1 section against the dev stack.
4. Deploy/demo if ready.

### Incremental Delivery

1. Foundational → US1 (MVP) → US2 → US3 → US4 → US5 (verification) → Polish.
2. US2/US3/US4 have no hard dependency ordering among themselves and could be delivered in any order after US1, though implementing them sequentially against the same two files (as noted above) is the practical path for a single contributor.
