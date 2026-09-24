---

description: "Task list for Event Availability & Invoice Redesign"

---

# Tasks: Event Availability & Invoice Redesign

**Input**: Design documents from `/specs/023-event-availability-invoice-redesign/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/event-availability-invoice.md, quickstart.md

**Tests**: Included — Constitution II requires Feature tests for every service/endpoint change in this codebase, and plan.md's Constitution Check commits to them explicitly.

**Organization**: Tasks are grouped by user story (US1–US4, matching spec.md's priorities P1/P1/P2/P1) so each is independently completable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to
- All backend tests run via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Test>` (per CLAUDE.md's documented environment bug)

## Path Conventions

Existing single web app: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`, `docs/` at repo root (see plan.md's Project Structure).

---

## Phase 1: Setup

**Purpose**: Schema groundwork with no story-specific behavior yet.

- [x] T001 Create migration `database/migrations/<new>_add_available_on_to_events_table.php` adding nullable `available_on ENUM('day_1','day_2')` to `events` (data-model.md).
- [x] T002 Run `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan migrate` and `docker compose exec app php artisan migrate` (dev DB) to apply T001.

**Checkpoint**: Schema ready — no behavior changed yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The one shared building block every user story either directly needs (US1) or transitively depends on for its data to exist (US2's standout block needs `available_on` to resolve).

**⚠️ CRITICAL**: US2/US3/US4 do not literally require US1's UI to be built first (they can be implemented and tested against directly-seeded `available_on` data), but US1 is still P1 and the natural first story to ship since it's the only way to actually set the value through the product.

- [x] T003 [P] Add `available_on` to `App\Models\Event::$fillable`, cast, and `availableOnDate(): ?Carbon` helper (research.md Decision 1).
- [x] T004 [P] Add `available_on` validation (`nullable|in:day_1,day_2`, rejected when `start_date === end_date`) to `app/Http/Requests/StoreEventRequest.php` and `app/Http/Requests/UpdateEventRequest.php` (data-model.md).
- [x] T005 [P] Write `tests/Feature/EventAvailabilityTest.php`: `available_on` accepted for a multi-day event; rejected (422) when the event is single-day; `Event::availableOnDate()` resolves `day_1`/`day_2` to `start_date`/`end_date` correctly; `null` when unset.

**Checkpoint**: `available_on` exists as a validated, resolvable model attribute — ready for the controller-level clearing logic (US1) and for every document to read (US2).

---

## Phase 3: User Story 1 - Mark which day an event's booth is available (Priority: P1) 🎯 MVP

**Goal**: Owner/admin can set/clear an event's "available on" day through the Events screen, and it's auto-cleared if the event collapses to one day.

**Independent Test**: Create/edit a multi-day event, set "Day 2", save, reopen to confirm it persisted; then edit dates so the event becomes one day and confirm the value was cleared.

### Tests for User Story 1

- [x] T006 [P] [US1] Extend `tests/Feature/EventAvailabilityTest.php` (or add to it): `PATCH /events/{event}` clears a previously-set `available_on` in the same request that collapses `start_date`/`end_date` to the same day; a multi-day-to-multi-day date edit leaves `available_on` untouched.

### Implementation for User Story 1

- [x] T007 [US1] In `app/Http/Controllers/Api/EventController.php::update()`, inside the existing `DB::transaction()` (which already clears stale pre-order `pickup_day` rows), add: if the updated event is now single-day and `available_on` is set, clear it to `null` (research.md Decision 2).
- [x] T008 [US1] Add an "Available on" `BaseSelect` to `resources/js/views/EventsView.vue`'s event form, options labeled with the actual resolved dates (e.g. "Day 1 (1 Nov 2026)" / "Day 2 (2 Nov 2026)"), shown only when `form.start_date !== form.end_date`, cleared client-side when the two dates become equal.
- [x] T009 [P] [US1] Add `available_on`-related locale keys (`events_sessions.available_on_label`, `events_sessions.available_on_day_1`, `events_sessions.available_on_day_2`) to `resources/js/locales/{id,en}.json`.
- [x] T010 [US1] Update `docs/openapi-pos-mvp.yaml`'s Event schema and `POST /events`/`PATCH /events/{id}` request/response with `available_on`.

**Checkpoint**: User Story 1 fully functional and independently testable — the field can be set, persisted, and auto-cleared, even though no document shows it yet.

---

## Phase 4: User Story 2 - Customers see availability and location prominently (Priority: P1)

**Goal**: The resolved available-on date and the event location render as one standout block on the pre-order invoice, payment invoice, and sales receipt — replacing the old tiny footer line.

**Independent Test**: Generate each of the three documents for an event with both an available day and a location set, and confirm both facts appear in a visually prominent block (not the old small muted footer text); confirm the block is fully absent when the data isn't set.

### Tests for User Story 2

- [x] T011 [P] [US2] Write `tests/Feature/InvoiceAvailabilityAndLogoTest.php` (start this file here, extended further in US4): `GET /preorders/{id}/invoice` and `POST /preorders/bulk-invoices` include `event_available_on_date`, correctly resolved via `Event::availableOnDate()`, `null` when unset or no event linked; `GET /orders/{id}/receipt` includes the same field.

### Implementation for User Story 2

- [x] T012 [P] [US2] Add `event_available_on_date` to `PreorderController::invoicePayload()`'s response (`app/Http/Controllers/Api/PreorderController.php`).
- [x] T013 [P] [US2] Add `event_available_on_date` to `OrderController::receipt()`'s response (`app/Http/Controllers/Api/OrderController.php`).
- [x] T014 [US2] In `resources/js/components/preorder/PreorderInvoiceModal.vue`, remove the existing small `"Location: X" / "Dates: Y"` footer block and replace it with one standout block (positioned after the store-identity header, before order/customer identity) showing the resolved available-on date and the event location using higher-contrast token classes (research.md Decision 3); omitted entirely when both are unset.
- [x] T015 [US2] Confirm `resources/js/components/preorder/PreorderPaymentReceiptModal.vue` inherits the same standout block (it reuses the same invoice payload via `getPreorderInvoice()` — add the block there too, matching T014's markup/logic).
- [x] T016 [US2] Apply the same standout block treatment (replacing the existing small footer line) to `resources/js/components/receipt/ReceiptModal.vue`, using its already-returned `event_location` plus the new `event_available_on_date`.
- [x] T017 [P] [US2] Add locale keys for the standout block's labels (e.g. `preorders.available_on_label`, reusing existing `events_sessions.location`) to `resources/js/locales/{id,en}.json`.
- [x] T018 [US2] Update `docs/openapi-pos-mvp.yaml`'s invoice and receipt response schemas with `event_available_on_date`.

**Checkpoint**: User Stories 1–2 both independently functional — every customer-facing document now shows availability/location prominently.

---

## Phase 5: User Story 3 - A clearer, wider invoice with a bigger QR and shipping cost (Priority: P2)

**Goal**: The pre-order invoice (and payment invoice) reads as header → item table → footer, is wider, has a bigger QR, and shows shipping cost when present.

**Independent Test**: Open a Mail Order pre-order's invoice with shipping cost set — confirm the wider table-based layout, the shipping-cost line, and the larger QR; confirm the shipping-cost line is absent when zero.

### Implementation for User Story 3

- [x] T019 [US3] Restructure `resources/js/components/preorder/PreorderInvoiceModal.vue`'s item list from a stacked `flex` list into an HTML `<table>` (Product/Qty/Unit price/Line total columns), inside a clear header/table/footer document structure (research.md Decision 5).
- [x] T020 [US3] Change `PreorderInvoiceModal.vue`'s `BaseModal` `max-width-class` from `max-w-[480px]` to `max-w-[720px]`.
- [x] T021 [US3] Add a "Shipping cost" line to `PreorderInvoiceModal.vue`'s totals section, `v-if="parseMoney(invoice.shipping_cost) > 0"`, mirroring the existing discount-line pattern (research.md Decision 6 — no backend change, `shipping_cost` is already returned).
- [x] T022 [US3] Enlarge the per-channel QR thumbnail in `PreorderInvoiceModal.vue`'s payment-terms block from `h-14 w-14` to `h-24 w-24` (research.md Decision 7), keeping the existing click-to-enlarge `ImageLightbox.vue` behavior unchanged.
- [x] T023 [US3] Confirm `PreorderPaymentReceiptModal.vue` (which shares the invoice shell) picks up the same table/width/QR-size/shipping-cost changes — adjust its markup to match if it has any layout that diverged from `PreorderInvoiceModal.vue`'s.
- [x] T024 [P] [US3] Add/adjust a Vitest case in `qa-tests/component/PreorderInvoiceModal.test.js` asserting: a table element is present for items; a shipping-cost line renders when `shipping_cost` > 0 and is absent at 0; a QR thumbnail's size class reflects the new dimensions.
- [x] T025 [US3] Update `docs/openapi-pos-mvp.yaml`'s invoice response description noting `shipping_cost` is now rendered (no schema change — field already documented).

**Checkpoint**: User Stories 1–3 all independently functional.

---

## Phase 6: User Story 4 - Store logo actually appears everywhere (Priority: P1)

**Goal**: Uploaded store logo appears immediately in Settings → General, after reload, and on both invoice documents and the sales receipt.

**Independent Test**: Upload a logo in Settings → General, confirm it appears immediately and after a full page reload; confirm it appears on a freshly generated pre-order invoice and sales receipt.

### Tests for User Story 4

- [x] T026 [P] [US4] Extend `tests/Feature/InvoiceAvailabilityAndLogoTest.php`: `GET /settings` response includes a `store_logo_url` sibling field alongside `data`, correctly resolved from `store_logo_path` via `ImageUploadService::url()`, `null` when unset; `POST /settings/store-logo`'s response includes the same field for the just-uploaded file.

### Implementation for User Story 4

- [x] T027 [US4] Add `store_logo_url` to `SettingsController::index()`'s response (`app/Http/Controllers/Api/SettingsController.php`), computed via the already-injected `ImageUploadService`.
- [x] T028 [US4] Add `store_logo_url` to `SettingsController::uploadStoreLogo()`'s response, computed from the newly-stored path (avoiding a re-read).
- [x] T029 [US4] In `resources/js/views/SettingsView.vue`, delete the hand-rolled `storeLogoUrl` computed (`/storage/${storeLogoPath.value}`); replace `storeLogoUrl` with a plain `ref` populated from `listSettings()`'s `store_logo_url` on load and from `uploadStoreLogo()`'s `store_logo_url` on `saveLogo()` (research.md Decision 8).
- [x] T030 [US4] Update `docs/openapi-pos-mvp.yaml`'s `GET /settings`/`POST /settings/store-logo` response schemas with `store_logo_url`.

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final consistency pass across the whole feature.

- [x] T031 [P] Run the full backend suite: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`.
- [x] T032 [P] Run the full frontend suite: `npm test -- --run`.
- [x] T033 Execute every scenario in `specs/023-event-availability-invoice-redesign/quickstart.md` against the dev stack via real-browser verification (Constitution II), including re-confirming the store-logo bug's leading symptom is gone.
- [x] T034 Verify `docs/openapi-pos-mvp.yaml` is fully in sync with every route/response change made across all phases above (PRD §9.5 / CLAUDE.md API conventions).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup. Blocks US1 (needs `available_on` validated/resolvable) and, transitively, US2 (needs real data to display).
- **US1 (Phase 3)**: Depends on Foundational.
- **US2 (Phase 4)**: Depends on Foundational (reads `Event::availableOnDate()`) — does not depend on US1's UI, only on `available_on` being a real, settable column (can be tested by seeding the value directly).
- **US3 (Phase 5)**: Independent of US1/US2 at the code level (pure layout/QR/shipping-cost work on already-returned data) — can be built in parallel with them by a different person, though it touches the same file as US2's T014, so within one contributor's work it's sequenced after US2 to avoid rebasing the same component twice.
- **US4 (Phase 6)**: Fully independent of US1/US2/US3 — different files entirely (Settings, not preorders/events).
- **Polish (Phase 7)**: Depends on all desired stories being complete.

### Suggested MVP Scope

User Story 1 alone (Phase 3, after Foundational) is the smallest shippable increment — the data model and editing UI exist, even though no document shows it yet. For actual customer-facing value, ship through **User Story 2** (Phase 4) as the true MVP — that's the point where the feature's core ask ("make available-on and location standout") is delivered end-to-end.

### Parallel Opportunities

- T001–T002 (Setup) run sequentially (migration then apply); T003–T005 (Foundational) run in parallel.
- US4 (Phase 6) can be built entirely in parallel with US1/US2/US3 by a second contributor — zero file overlap.
- Within US2, T012/T013 (two different controllers) run in parallel; T017 (locales) runs in parallel with either.

---

## Parallel Example: Foundational + User Story 4

```bash
# Foundational, in parallel:
Task: "Add available_on to Event model/cast/helper"
Task: "Add available_on validation to StoreEventRequest/UpdateEventRequest"
Task: "Write EventAvailabilityTest.php"

# User Story 4, fully independent, any time after Setup:
Task: "Add store_logo_url to SettingsController::index()"
Task: "Add store_logo_url to SettingsController::uploadStoreLogo()"
Task: "Fix SettingsView.vue's storeLogoUrl to use the backend-provided URL"
```

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational.
3. Complete Phase 3: User Story 1.
4. Complete Phase 4: User Story 2.
5. **STOP and VALIDATE**: run quickstart.md's US1/US2 sections against the dev stack.
6. Deploy/demo if ready — this is the feature's actual core value.

### Incremental Delivery

1. Setup + Foundational → US1 → US2 (MVP) → US3 → US4 → Polish.
2. US4 can be pulled forward or built in parallel at any point — it has no dependency on the rest of this feature.
