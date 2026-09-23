---

description: "Task list for Pre-order Form & Workflow Updates"

---

# Tasks: Pre-order Form & Workflow Updates

**Input**: Design documents from `/specs/021-preorder-form-updates/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-updates.md, quickstart.md (all present)

**Tests**: Included as mandatory — Constitution II requires every backend change in this repo to ship with `tests/Feature/` tests run against real MySQL, plus a real-browser check for user-facing screens. Run backend tests via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test` (NOT the bare command — see phpunit.xml/docs/RUNBOOK.md, a real bug found during feature 020 that silently wipes the dev database otherwise).

**Organization**: Tasks are grouped by user story (US1 = Customer picker P1, US2 = Discount P1, US3 = Fulfillment-specific details P2, US4 = Import/export sync P3) per spec.md. FR-006 (quantity direct-entry) has no dedicated story in spec.md — it is folded into US1 as a small additional create-form task, since both are P1 create-form input fixes with no dependency on each other.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- File paths are exact and relative to the repo root

---

## Phase 1: Setup

- [X] T001 [P] Create `app/Support/Couriers.php` — `const OPTIONS = ['JNE', 'J&T', 'SiCepat', 'Pos Indonesia', 'Other']`, `const DEFAULT = 'JNE'` (data-model.md's "New shared definition"; mirrors `MasterDataSheets.php`'s single-source-of-truth role per research.md Decision 5).
- [X] T002 Create migration `database/migrations/<timestamp>_add_discount_pickup_day_courier_name_to_preorders_table.php`: `decimal('discount', 14, 2)->default(0)`, `date('pickup_day')->nullable()`, `string('courier_name', 50)->nullable()` added to `preorders` via `Schema::table()`. Date prefix after the latest existing migration (Constitution/CLAUDE.md convention — check `database/migrations/` for the current latest date before naming this file).

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: No user story task below may start until this phase is complete.

- [X] T003 Update `app/Models/Preorder.php`: add `discount`, `pickup_day`, `courier_name` to `$fillable`, and casts (`'discount' => 'decimal:2'`, `'pickup_day' => 'date'`) alongside the existing casts.
- [X] T004 Update `app/Http/Controllers/Api/PreorderController.php`'s `present()` (the single shared response-shaper used by both `show()` and `invoice()`): add `'discount' => number_format((float) $preorder->discount, 2, '.', '')`, `'pickup_day' => $preorder->pickup_day?->toDateString()`, `'courier_name' => $preorder->courier_name`.

**Checkpoint**: Foundation ready — US1/US2/US3/US4 implementation can now begin.

---

## Phase 3: User Story 1 - Pick a customer faster while creating a pre-order (Priority: P1) 🎯 MVP

**Goal**: Replace the two-step "button opens a pop-up with its own search" customer picker with one inline searchable dropdown on the pre-order create form; add direct numeric quantity entry (FR-006, folded in here).

**Independent Test**: Open "New pre-order," type part of a customer's name/phone directly into the customer field, see a filtered dropdown appear inline (no second pop-up), select one. Separately, confirm an item's quantity can be typed directly, not just incremented/decremented.

### Implementation for User Story 1

- [X] T005 [P] [US1] Create `resources/js/components/preorder/CustomerSearchDropdown.vue` — single-select, remote debounced search (reuse `BaseMultiSelect.vue`'s Teleport/fixed-position-panel/click-outside/scroll-close mechanics per research.md Decision 8, but swap its local `filteredOptions` for a debounced `listCustomers({ search, per_page: 10 })` call). Relocate `CustomerPickerModal.vue`'s "continue as walk-in" and "add new customer" inline mini-form into this component's dropdown panel. **Do not modify or delete `CustomerPickerModal.vue`** — it is also used by `PosView.vue`, out of this feature's scope (research.md Decision 8 correction).
- [X] T006 [US1] In `resources/js/views/PreordersView.vue`'s create form: replace the "Pick a customer…" button + `showCreateCustomerPicker`/`CustomerPickerModal` usage with `<CustomerSearchDropdown v-model="createCustomer" />` inline in the form. Remove the now-unused `showCreateCustomerPicker` ref and the `<CustomerPickerModal ... />` element from the create-form section specifically (its import statement stays only if `PosView.vue`-style reuse isn't happening here — since T005 says don't touch `CustomerPickerModal.vue`, just stop referencing it from this form). (Depends on T005.)
- [X] T007 [US1] In the same create form, add a direct numeric `<input type="number" min="1">` bound to `item.qty` next to each item row's existing +/- buttons (`bumpCreateItem`), so a staff member can type a quantity directly. Keep the existing stepper working alongside it (research.md Decision 7 — frontend-only, no backend change needed since `StorePreorderRequest`'s `items.*.qty` rule already accepts any integer ≥ 1).
- [X] T008 [P] [US1] Add/update `preorders.*` locale keys in `resources/js/locales/en.json` and `id.json` for the new inline customer dropdown's copy (search placeholder, walk-in, add-new-customer — reuse `events_sessions.*` wording already used by `CustomerPickerModal.vue` where it fits, per this codebase's existing key-reuse pattern).
- [X] T009 [US1] Vitest component test (new or extend `qa-tests/component/PreordersView.test.js`): typing in the customer field renders matching results inline with no modal element present; selecting a result sets the create form's customer; typing a quantity directly updates `item.qty`.
- [X] T010 [US1] Manually verify per quickstart.md steps 2–3 in a real browser (Docker dev instance): inline dropdown appears with no second pop-up; walk-in and add-new-customer still reachable; quantity typable directly.

**Checkpoint**: Customer picking and quantity entry are fully functional and independently testable/shippable.

---

## Phase 4: User Story 2 - Apply a discount to a pre-order (Priority: P1)

**Goal**: A fixed Rupiah discount reduces a pre-order's total, is validated server-side, and is visible on the pre-order's detail/invoice.

**Independent Test**: Create a pre-order with known item totals, enter a discount, confirm the displayed total and (after saving) the invoice both reflect items minus discount; confirm a discount larger than the subtotal is rejected.

### Implementation for User Story 2

- [X] T011 [US2] Add `'discount' => ['sometimes', 'numeric', 'min:0']` to `app/Http/Requests/StorePreorderRequest.php`'s `rules()`.
- [X] T012 [US2] In `app/Services/PreorderService.php::create()`: read `$discount = (float) ($data['discount'] ?? 0)`, throw `ValidationException::withMessages(['discount' => __('preorders.discount_exceeds_total')])` when `$discount > $subtotal + $shippingCost`, and change `total_amount` to `$subtotal + $shippingCost - $discount`. Persist `discount` on `Preorder::create()`. Add the matching `preorders.discount_exceeds_total` key to `lang/id/preorders.php` and `lang/en/preorders.php`.
- [X] T013 [US2] In `resources/js/views/PreordersView.vue`'s create form: add a discount `BaseInput` (numeric), update `createTotal` computed to subtract it, include `discount` in `submitCreate()`'s payload, and surface `createErrors.discount` the same way other field errors already render.
- [X] T014 [US2] Show the discount line (and its effect on the total) in `resources/js/components/preorder/PreorderInvoiceModal.vue` and in `PreordersView.vue`'s detail drawer, only when `discount > 0` (zero-discount preorders — including every pre-existing one — render exactly as they do today, per Edge Cases).
- [X] T015 [P] [US2] Add discount-related locale keys (label, invoice line, error message) to `en.json`/`id.json`.
- [X] T016 [US2] Feature tests in `tests/Feature/PreorderFormUpdatesTest.php`: `creating a preorder with a discount reduces total_amount accordingly`, `a discount larger than subtotal plus shipping is rejected with a 422`, `omitting discount behaves exactly as before (zero discount, unaffected total)`.
- [X] T017 [US2] Manually verify per quickstart.md steps 4 and 8 in a real browser.

**Checkpoint**: Discount is fully functional; US1 unaffected.

---

## Phase 5: User Story 3 - Fulfillment-specific details: pickup day and mail courier (Priority: P2)

**Goal**: "Courier" reads "Mail Order" everywhere; "Self Pickup" offers a pickup day derived from a linked event's real dates; "Mail Order" offers a courier dropdown defaulted to JNE; both are validated server-side and shown on the invoice.

**Independent Test**: Link a pre-order to a 2-day event, choose "Self Pickup," confirm two real-dated day choices appear and one is required; separately choose "Mail Order," confirm a courier dropdown appears pre-selected to JNE.

### Implementation for User Story 3

- [X] T018 [US3] Add an event dropdown to `resources/js/views/PreordersView.vue`'s create form (`BaseSelect`, options from `listEvents()` in `resources/js/api/events.js`, already used elsewhere in this codebase) — bound to a new `createEventId` ref, optional like `event_id` already is server-side. **This dropdown does not exist today** (research.md Decision 9) and is required for the pickup-day picker below to have anything to derive dates from.
- [X] T019 [US3] In the same form: when `createFulfillment === 'pickup'` AND `createEventId` is set, compute and render one selectable day per calendar day in the selected event's `start_date`–`end_date` range (label: "Day N (<real date>)"); bind the choice to a new `createPickupDay` ref (a date string). When there's no event selected, show no pickup-day control at all (FR-008a) and do not require one to submit.
- [X] T020 [US3] Add `'pickup_day' => ['nullable', 'date']` to `StorePreorderRequest::rules()` (cross-field/range checks happen in the service, per Constitution I's "business logic in Services" — FormRequest only validates shape).
- [X] T021 [US3] In `PreorderService::create()`: when `fulfillment === 'pickup'` and `pickup_day` is present, require `event_id` to also be present and load that `Event`; reject (`ValidationException`, field `pickup_day`) if the event isn't linked, or if the date falls outside `[event.start_date, event.end_date]`. Reject (field `pickup_day`) if `pickup_day` is present while `fulfillment === 'courier'` (FR-014's create-time counterpart). Persist `pickup_day` on `Preorder::create()`.
- [X] T022 [US3] Rename every `preorders.fulfillment_courier`-driven label from "Courier" to "Mail Order" in `en.json`/`id.json` (the enum value `courier` itself is untouched — grep the codebase for any other hardcoded "Courier" UI string beyond `FULFILLMENT_LABEL` and fix those too, per FR-007's "everywhere it appears").
- [X] T023 [US3] Add a courier `BaseSelect` (options from `App\Support\Couriers` — expose via a small shared frontend constant or a lightweight endpoint reuse; keep in sync with the backend list per research.md Decision 5) to the create form, shown only when `createFulfillment === 'courier'`, defaulted to `'JNE'`, bound to a new `createCourierName` ref.
- [X] T024 [US3] Add `'courier_name' => ['nullable', 'string', Rule::in(\App\Support\Couriers::OPTIONS)]` to `StorePreorderRequest::rules()`. In `PreorderService::create()`: default `courier_name` to `Couriers::DEFAULT` when `fulfillment === 'courier'` and none was sent; reject (field `courier_name`) if present while `fulfillment === 'pickup'` (FR-014's create-time counterpart). Persist on `Preorder::create()`.
- [X] T025 [US3] In `resources/js/views/PreordersView.vue`'s existing shipment-creation section: change `shipmentForm.courier_name`'s `BaseInput` to a `BaseSelect` using the same courier list as T023, and pre-fill it from `detail.courier_name` (the parent preorder's default) when the shipment form opens. **`ShipmentController::store()`'s own backend validation is unchanged** (still `required|string|max:50`, per research.md Decision 1 — this is a frontend-only upgrade of an already-working, separately-validated field).
- [X] T026 [US3] Implement FR-009a: when a linked `Event`'s `start_date`/`end_date` change such that an existing preorder's `pickup_day` falls outside the new range, clear that preorder's `pickup_day`. Add this as a small step in `EventController::update()` (or `EventService`, whichever already owns event-update writes — check before adding a new write path per Constitution I) inside the same transaction as the event update: `Preorder::where('event_id', $event->id)->whereNotNull('pickup_day')->get()` filtered to out-of-range rows, updated to `pickup_day = null`.
- [X] T027 [US3] Show the pickup day (its real date) and/or courier on `PreorderInvoiceModal.vue` and the preorder detail drawer in `PreordersView.vue`, only when set (FR-009).
- [X] T028 [P] [US3] Add "Mail Order"/pickup-day/courier locale keys to `en.json`/`id.json`.
- [X] T029 [US3] Feature tests in `tests/Feature/PreorderFormUpdatesTest.php`: `pickup day is accepted when it falls within the linked event's date range`, `pickup day is rejected when outside the event's date range`, `pickup day is rejected when no event is linked`, `pickup day is cleared when the linked event's dates change to exclude it`, `courier defaults to JNE when omitted for mail-order fulfillment`, `an unknown courier value is rejected`, `pickup_day on a mail-order-fulfillment row is rejected`, `courier_name on a self-pickup-fulfillment row is rejected`.
- [X] T030 [US3] Manually verify per quickstart.md steps 5–9 in a real browser: 2-day event offers two dated choices, 1-day event offers one, no-event offers none; "Mail Order" label appears everywhere; courier dropdown defaults to JNE and also appears (as a dropdown) in the separate shipment-creation step, pre-filled.

**Checkpoint**: All fulfillment-specific details fully functional; US1/US2 unaffected.

---

## Phase 6: User Story 4 - Bulk import/export reflects the new fields (Priority: P3)

**Goal**: The existing pre-order import/export file gains discount/pickup_day/courier_name columns, with the same validation rules the form enforces, including rejecting a fulfillment-mismatched value as a row error.

**Independent Test**: Export pre-orders with these fields set, confirm the columns are present and correct; import a file with these columns (including one deliberately-mismatched row) and confirm correct rows apply and the mismatched row is rejected with the whole import declining to save anything.

### Implementation for User Story 4

- [X] T031 [US4] Add `discount`, `pickup_day`, `courier_name` to `app/Services/PreorderExportImportService.php`'s `HEADINGS` constant, `template()`, and `export()`'s per-row mapping (per data-model.md's Import/Export Row Shape table — order-level values, populated on every row for export; meaningful only on a group's first row for import, mirroring how `customer_name`/`event_id`/`fulfillment` already work in `groupRows()`).
- [X] T032 [US4] In `PreorderExportImportService::import()`'s per-row validation: validate `discount` (numeric ≥ 0, rejects if it would make the total negative — same rule as T012); validate `pickup_day` (only when `fulfillment=pickup`, resolves against the row's `event_id`'s date range, same as T021); validate `courier_name` (only when `fulfillment=courier`, must be one of `Couriers::OPTIONS`, defaults to `Couriers::DEFAULT` if blank, same as T024). Add the FR-014 cross-field check: `pickup_day` present with `fulfillment=courier`, or `courier_name` present with `fulfillment=pickup`, is a row-level error appended to `row_errors` (all-or-nothing, matching the existing convention — nothing saves if any row fails).
- [X] T033 [US4] Update `docs/openapi-pos-mvp.yaml` per `contracts/preorder-updates.md`: `POST /preorders`, `GET /preorders/{id}`, `GET /preorders/{id}/invoice`, `GET /preorders/export`, `GET /preorders/import/template`, `POST /preorders/import` all gain the three new fields/columns in their documented schemas.
- [X] T034 [US4] Feature tests in `tests/Feature/PreorderFormUpdatesTest.php`: `export includes discount, pickup_day, and courier_name columns`, `import applies discount/pickup_day/courier_name exactly as the form would`, `import rejects a row with pickup_day set on a mail-order fulfillment row`, `import rejects a row with courier_name set on a self-pickup fulfillment row`, `re-exporting immediately after a successful import round-trips the same values`.
- [X] T035 [US4] Manually verify per quickstart.md steps 10–12 in a real browser.

**Checkpoint**: All four user stories independently functional and verified.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T036 [P] Run the full suites and confirm zero regressions: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test` (backend) and `npm test` (frontend, `qa-tests/`).
- [X] T037 Run `quickstart.md` end-to-end once every task above is complete.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup (T002's migration must exist for T003's casts to make sense) — BLOCKS all user stories.
- **User Story 1 (Phase 3)**: Depends only on Foundational. Fully independent of US2/US3/US4.
- **User Story 2 (Phase 4)**: Depends only on Foundational. Independent of US1 (touches different parts of the same `PreordersView.vue`/locale files — sequential file edits, not a logical dependency).
- **User Story 3 (Phase 5)**: Depends only on Foundational and on T001 (`Couriers`). Independent of US1/US2 logically, though it adds more fields to the same create form (sequential file edits).
- **User Story 4 (Phase 6)**: Depends on US2 and US3 existing first (T031/T032 need `discount`/`pickup_day`/`courier_name` to already be real, validated fields — spec.md itself frames US4 as depending on US2/US3).
- **Polish (Phase 7)**: Depends on all four user stories being complete.

### Parallel Opportunities

- T001 and T002 (Phase 1) — different files.
- T005, T008 (US1) — different files, parallel once Foundational is done.
- T015 (US2) — parallel with T011–T014 (different file).
- T028 (US3) — parallel with T018–T027 (different file).
- T036 (Polish) — parallel with T037 only in the sense both can be prepared together, though T037 should run after T036 passes.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) and Phase 2 (Foundational).
2. Complete Phase 3 (US1 — customer dropdown + qty direct-entry).
3. **STOP and VALIDATE**: T009's Vitest run and T010's manual browser check.
4. This alone is already a real, shippable UX improvement to the highest-frequency interaction in the pre-order workflow.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US1 (customer picker + qty) → test independently → demoable.
3. US2 (discount) → test independently → demoable.
4. US3 (pickup day + mail order + courier) → test independently (builds on Foundational, not on US1/US2's code) → demoable.
5. US4 (import/export) → test independently (needs US2+US3's fields to exist) → demoable.
6. Polish (full suite, quickstart) → done.

---

## Notes

- [P] tasks touch different files with no unfinished dependency between them.
- Every task lists an exact file path or, where a decision is needed at implementation time (T026), names the specific investigation required rather than guessing a file.
- Constitution II mandates real-MySQL Feature tests (with the corrected `-e` env override — see Tests note above) and a real-browser check for any user-facing screen change — both included per story, not deferred to Polish.
- Avoid: touching `shipments`' required-field schema (research.md Decision 1), renaming the `fulfillment` enum value (Decision 4), duplicating the courier list per screen (Decision 5), or deleting/modifying `CustomerPickerModal.vue` (still needed by `PosView.vue`, out of scope).
