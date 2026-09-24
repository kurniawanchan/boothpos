---

description: "Task list for Pre-order Invoice & CRUD Overhaul"

---

# Tasks: Pre-order Invoice & CRUD Overhaul

**Input**: Design documents from `/specs/022-preorder-invoice-crud-overhaul/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/preorder-crud-invoice.md, quickstart.md

**Tests**: Included — Constitution II requires Feature tests for every service/endpoint change in this codebase, and plan.md's Constitution Check commits to them explicitly.

**Organization**: Tasks are grouped by user story (US1–US7, matching spec.md's priorities P1/P1/P1/P2/P2/P2/P3) so each is independently completable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to
- All backend tests run via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Test>` (per CLAUDE.md's documented environment bug)

## Path Conventions

Existing single web app: `app/`, `resources/js/`, `tests/Feature/`, `qa-tests/`, `docs/` at repo root (see plan.md's Project Structure).

---

## Phase 1: Setup

**Purpose**: Dependencies and schema groundwork with no story-specific logic yet.

- [x] T001 Add `jszip` to `package.json` (`npm install jszip`) — needed by US5's bulk download.
- [x] T002 Create migration `database/migrations/2026_09_23_000001_drop_city_and_postal_code_from_shipments_table.php` dropping `city`/`postal_code` from `shipments` (data-model.md's Shipment section).
- [x] T003 Run `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan migrate` and `docker compose exec app php artisan migrate` (dev DB) to apply T002.

**Checkpoint**: Dependencies installed, schema updated — no behavior changed yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared backend building blocks that more than one user story depends on. No user-facing behavior yet.

**⚠️ CRITICAL**: US3 and US4 both depend on the store-identity/payment-channel assembly below; US1's stock-delta logic is reused by nothing else but is foundational to that story's own two acceptance branches.

- [x] T004 [P] Add `App\Http\Controllers\Concerns\BuildsInvoiceDocument` trait (or a plain private method reused via composition — match existing controller conventions) in `app/Http/Controllers/Api/PreorderController.php` that assembles `store_identity` (name, mode-aware demo/live, logo_url respecting `receipt_show_logo`, contact_person, contact_phone, contact_email, address), `payment_channels` (active channels, ordered by `display_order`, **unmasked** `account_number` — research.md Decision 2), and `footer_text` — copied from `OrderController::receipt()`'s existing assembly, not reimplemented (research.md Decision 1).
- [x] T005 [P] Write `tests/Feature/PreorderInvoiceStoreIdentityTest.php` covering: all fields present when configured; `store_identity`/`payment_channels`/`footer_text` omitted (not empty-string) when unset; logo omitted when `receipt_show_logo` is false; payment channel `account_number` is the full unmasked value (contrast with `PaymentChannelController::index()`'s masking for a non-owner role, proving this is a deliberate exception).

**Checkpoint**: Shared invoice-assembly helper exists and is tested in isolation before any document endpoint uses it.

---

## Phase 3: User Story 1 - Correct a mistake on a pre-order after it's created (Priority: P1) 🎯 MVP

**Goal**: Staff can edit a pre-order's items/details (status-gated) and delete it (status- and payment-gated), with stock always left correct.

**Independent Test**: Create a pre-order, edit its items pre-"arrived" (no stock effect), advance to "arrived" and edit again (stock corrected by delta, not doubled), confirm edit refused at "Handed over"/"Cancelled", delete an "Ordered" order (succeeds), attempt delete at any later status or with a payment (refused, points to Cancel).

### Tests for User Story 1

- [x] T006 [P] [US1] Write `tests/Feature/PreorderUpdateTest.php`: edit items at `ordered`/`dp_paid` → no stock movement recorded; edit items at `arrived`/`settled` → stock corrected by exact delta per variant (not reversed-and-reapplied — assert `stock_movements` row count for unchanged variants stays zero); edit refused (409) at `handed_over`/`cancelled` with clear message; discount/pickup_day/courier cross-field validation (from feature 021) still enforced on edit.
- [x] T007 [P] [US1] Write `tests/Feature/PreorderDeleteTest.php`: delete succeeds at `ordered` with no payment, removes `preorder_items` and the `preorders` row; delete refused (409) at every other status; delete refused (409) when any payment exists even if status is somehow `ordered`.

### Implementation for User Story 1

- [x] T008 [US1] Add `PreorderService::update(Preorder $preorder, array $data): Preorder` in `app/Services/PreorderService.php` — status guard (refuse `handed_over`/`cancelled`), recompute subtotal/total/discount, replace `preorder_items` (delete removed lines, update changed quantities, insert new lines with fresh snapshot pricing), and for `arrived`/`settled` apply one `StockService::applyMovement()` call per variant whose quantity changed (delta = new − old, type `purchase`) — per research.md Decision 3, never a blanket reverse+reapply.
- [x] T009 [US1] Add `PreorderService::delete(Preorder $preorder): void` in `app/Services/PreorderService.php` — refuse (409-mapped exception) unless `status === 'ordered'` and `payments()->doesntExist()`; otherwise delete items then the preorder in one transaction.
- [x] T010 [US1] Create `app/Http/Requests/UpdatePreorderRequest.php` mirroring `StorePreorderRequest`'s item/discount/pickup_day/courier validation rules, all fields optional except `items`.
- [x] T011 [US1] Add `update()`/`destroy()` actions to `app/Http/Controllers/Api/PreorderController.php`, calling `PreorderService::update()`/`delete()`, mapping their guard exceptions to `409` with the messages from `lang/id/preorders.php`/`lang/en/preorders.php` (add keys: `edit_not_allowed_status`, `delete_not_allowed_status`, `delete_not_allowed_has_payment`).
- [x] T012 [US1] Add `PATCH /preorders/{preorder}` and `DELETE /preorders/{preorder}` routes in `routes/api.php`, same authorization gate as the existing `preorders` route group.
- [x] T013 [P] [US1] Add `updatePreorder(id, payload)` / `deletePreorder(id)` to `resources/js/api/preorders.js`.
- [x] T014 [US1] Wire an "Edit" action in `resources/js/views/PreordersView.vue`'s list/detail actions that opens the existing create-form UI pre-filled and in edit mode (submits via `updatePreorder` instead of create), disabled/hidden when status is `handed_over`/`cancelled`.
- [x] T015 [US1] Wire a "Delete" action in `resources/js/views/PreordersView.vue` (confirmation dialog), enabled only when status is `ordered`, calling `deletePreorder` and removing the row from the list on success; surface the 409 message via the existing central error handler when attempted elsewhere (defensive — the button itself should already be hidden/disabled).
- [x] T016 [US1] Update `docs/openapi-pos-mvp.yaml` with the new `PATCH`/`DELETE /preorders/{id}` operations (per PRD §9.5 / CLAUDE.md API conventions).

**Checkpoint**: User Story 1 fully functional and independently testable — edit/delete work end-to-end with correct stock behavior.

---

## Phase 4: User Story 2 - Faster, more accurate shipment details (Priority: P1)

**Goal**: "Create shipment data" auto-fills from the customer; city/postal_code no longer exist.

**Independent Test**: Open "Create shipment data" on a pre-order with a known customer — name/phone/address pre-filled, editable, no city/postal fields present.

### Tests for User Story 2

- [x] T017 [P] [US2] Write `tests/Feature/ShipmentAddressTest.php`: `POST/PUT` shipment endpoints no longer accept/store `city`/`postal_code` (columns gone — assert via schema or a 422/ignored-field check per existing FormRequest validation rules); `address_line` remains required.

### Implementation for User Story 2

- [x] T018 [US2] Remove `city`/`postal_code` validation rules from the shipment `FormRequest`(s) used by `app/Http/Controllers/Api/ShipmentController.php` (`store()`/`update()`).
- [x] T019 [P] [US2] In `resources/js/views/PreordersView.vue` (or the shipment-form component it renders), pre-populate `recipient_name`/`recipient_phone`/`address_line` from the pre-order's linked `customer` (`name`, `phone`, `address`) when "Create shipment data" opens, remaining editable.
- [x] T020 [US2] Remove the City/Postal Code form fields from that same shipment form component; remove any locale keys now unused for those fields in `resources/js/locales/{id,en}.json` (only if not referenced elsewhere).
- [x] T021 [US2] Update `docs/openapi-pos-mvp.yaml`'s shipment request schema to drop `city`/`postal_code`.

**Checkpoint**: User Stories 1 AND 2 both work independently.

---

## Phase 5: User Story 3 - A professional-looking pre-order invoice (Priority: P1)

**Goal**: "Receipt" → "Invoice" everywhere; invoice shows store identity, payment channels, footer; QR is bigger and click-to-popup.

**Independent Test**: Open a pre-order's document — labeled "Invoice", shows store logo/name/contact/address, payment options, footer message; click the QR during payment collection and confirm a full-size popup.

### Tests for User Story 3

- [x] T022 [P] [US3] Write `tests/Feature/PreorderInvoiceEndpointTest.php`: `GET /preorders/{id}/invoice` response includes `store_identity`/`payment_channels`/`footer_text` (reusing T004/T005's helper — this test exercises it through the actual invoice endpoint, not in isolation).

### Implementation for User Story 3

- [x] T023 [US3] Extend `PreorderController::invoice()` (or its `present()`-style array builder) to include the T004 helper's `store_identity`/`payment_channels`/`footer_text` fields.
- [x] T024 [US3] Relabel every "Receipt"/"Print" string referring to this document to "Invoice" in `resources/js/components/preorder/PreorderInvoiceModal.vue` and any parent screens/buttons that open it, plus `resources/js/locales/{id,en}.json` (rename the relevant keys, keep Indonesian-first copy per CLAUDE.md Conventions).
- [x] T025 [US3] Redesign `PreorderInvoiceModal.vue`'s template: store header block (logo/name/contact/address, mirroring `ReceiptModal.vue`'s header markup), payment-channels section listing each channel's name/account number/QR, footer text block — all `v-if`-guarded to omit gracefully when a field is absent (matching `ReceiptModal.vue`'s existing degrade-gracefully pattern).
- [x] T026 [P] [US3] Enlarge the QR image and add click-to-popup in `resources/js/components/payment/ChannelPicker.vue` (shared by POS checkout and pre-order settlement — research.md Decision 8): grow the `<img>` size, add a click handler opening a simple full-size lightbox (new small component or `BaseModal` with the image at natural size).
- [x] T027 [US3] Update `docs/openapi-pos-mvp.yaml`'s invoice response schema with the new fields; update any "receipt" wording in the pre-order invoice endpoint's description to "invoice".

**Checkpoint**: User Stories 1–3 all independently functional; the invoice document is fully redesigned and relabeled.

---

## Phase 6: User Story 4 - A matching invoice for each payment received (Priority: P2)

**Goal**: "Payment receipt" → "Payment invoice", restyled to match Story 3's shell, with per-payment vs. order-total figures clearly distinguished.

**Independent Test**: Record a payment, open the resulting document — labeled "Payment invoice", same visual shell as the main invoice, clearly shows this payment's amount vs. the order's total/remaining balance.

**Depends on**: User Story 3 (reuses its document shell/store-identity block, per spec.md's own stated dependency).

### Tests for User Story 4

- [x] T028 [P] [US4] Write `tests/Feature/PreorderPaymentInvoiceTest.php`: the existing payment-receipt endpoint's response also includes `store_identity`/`payment_channels`/`footer_text`, plus the already-existing payment-specific fields (amount, method, paid_at, running paid/outstanding).

### Implementation for User Story 4

- [x] T029 [US4] Extend the payment-receipt endpoint/`present()` builder in `app/Http/Controllers/Api/PreorderController.php` with the same T004 helper fields, alongside its existing payment-event fields.
- [x] T030 [US4] Relabel "Payment receipt"/"Print" references to "Payment invoice" in `resources/js/components/preorder/PreorderPaymentReceiptModal.vue` and `resources/js/locales/{id,en}.json`.
- [x] T031 [US4] Restyle `PreorderPaymentReceiptModal.vue` to reuse `PreorderInvoiceModal.vue`'s new header/payment-channels/footer markup (T025), keeping its existing "this payment vs. order total/remaining" distinction visually prominent (e.g. a highlighted "Paid this time" line separate from the order's running totals).
- [x] T032 [US4] Update `docs/openapi-pos-mvp.yaml`'s payment-receipt response schema and rename its description to "payment invoice".

**Checkpoint**: User Stories 1–4 all independently functional.

---

## Phase 7: User Story 5 - Handle many invoices at once (Priority: P2)

**Goal**: Bulk-select pre-orders in the list, download all their invoices as one zip, or bulk-email them.

**Independent Test**: Select multiple pre-orders, trigger bulk download — confirm a zip with one PDF per order; trigger bulk email — confirm a per-order sent/skipped report.

**Depends on**: User Story 3 (invoice document) and User Story 4 (payment invoice document) already existing in their new form.

### Tests for User Story 5

- [x] T033 [P] [US5] Write `tests/Feature/PreorderBulkInvoiceTest.php`: `POST /preorders/bulk-invoices` returns the full invoice payload array for each requested id (both `document: invoice` and `document: payment_invoice`); `POST /preorders/bulk-email` sends one email per pre-order with an email on file, reports `skipped_no_email` for one without, reports `skipped_not_configured` when mail isn't configured (mirroring `PreorderNotifier`'s existing convention), and records rows in `preorder_notifications`.

### Implementation for User Story 5

- [x] T034 [US5] Add `PreorderController::bulkInvoices()` action returning an array of invoice payloads (reusing T004's helper + existing single-invoice assembly) for the requested `preorder_ids`/`document` type.
- [x] T035 [US5] Create `app/Mail/PreorderInvoiceMail.php` — rich HTML body (no PDF attachment, research.md Decision 5) built from the same invoice payload data.
- [x] T036 [US5] Add `PreorderController::bulkEmailInvoices()` action sending `PreorderInvoiceMail` per selected pre-order, reusing `PreorderNotifier`'s record-every-attempt pattern (new `preorder_notifications` rows distinguishing this send type), returning a per-order sent/skipped report.
- [x] T037 [US5] Add `POST /preorders/bulk-invoices` and `POST /preorders/bulk-email` routes in `routes/api.php`.
- [x] T038 [P] [US5] Add `bulkDownloadInvoices(ids, document)` / `bulkEmailInvoices(ids, document)` to `resources/js/api/preorders.js`.
- [x] T039 [US5] Add row checkboxes + a bulk-actions toolbar ("Download invoices" / "Email invoices", with a document-type toggle for invoice vs. payment invoice) to `resources/js/views/PreordersView.vue`'s list, enabled only when ≥1 row selected.
- [x] T040 [US5] Implement client-side bulk download: for each selected order, sequentially render `PreorderInvoiceModal.vue`'s (or `PreorderPaymentReceiptModal.vue`'s) offscreen DOM into a PDF via the existing `html2canvas`+`jsPDF` pipeline, add each PDF to a `JSZip` archive, and trigger one `.zip` download at the end.
- [x] T041 [US5] Wire the "Email invoices" bulk action to call `bulkEmailInvoices` and display the per-order sent/skipped summary (toast list or small modal).
- [x] T042 [US5] Update `docs/openapi-pos-mvp.yaml` with the two new bulk endpoints.

**Checkpoint**: User Stories 1–5 all independently functional.

---

## Phase 8: User Story 6 - Find a customer faster while creating a pre-order (Priority: P2)

**Goal**: Customer picker shows a scrollable default list on open, narrowing as the user types.

**Independent Test**: Open the customer picker with nothing typed — a scrollable list of existing customers is already visible; typing narrows it as today.

### Implementation for User Story 6

- [x] T043 [US6] In `resources/js/components/preorder/CustomerSearchDropdown.vue`, trigger `listCustomers({ search: '', per_page: 10 })` on dropdown `open()` (before any typing), reusing the existing scrollable panel (`max-h-[360px] overflow-y-auto`, already present from feature 021) to display the results.
- [x] T044 [P] [US6] Add/adjust a Vitest case in `qa-tests/component/PreordersView.test.js` asserting the dropdown calls `listCustomers` with an empty search on open, not only after typing.

**Checkpoint**: User Stories 1–6 all independently functional. No backend change needed for this story (data-model.md Decision 6 — `GET /customers` already supports an empty `search`).

---

## Phase 9: User Story 7 - Bulk import/export matches how the business talks about orders (Priority: P3)

**Goal**: One-row-per-order import/export layout (event name, pickup/mail order, "Day N" pickup day, comma-separated products/quantities/unit_prices, shipping cost, courier, ETA, discount, notes), replacing the old row-per-item format entirely.

**Independent Test**: Download the new template, fill one multi-item order on a single row, import it, confirm one correctly-formed pre-order results; export existing pre-orders and confirm the identical column layout (round-trips through import).

**Depends on**: User Story 3's "Mail Order"/"pickup" wording (already established in feature 021) — no new dependency introduced here.

### Tests for User Story 7

- [x] T045 [P] [US7] Write `tests/Feature/PreorderImportExportOverhaulTest.php`: template has the new column set (event_name, fulfillment, pickup_day, products, quantities, unit_prices, shipping_cost, courier_name, expected_date, discount, notes, customer_name — no customer_phone/customer_email); a valid multi-item row imports as one pre-order with items matched positionally; a products/quantities count mismatch is rejected as a row-level error (FR-018); `pickup_day` as "Day 2" resolves to the matched event's real second date; export produces the same column layout and re-imports unmodified (round-trip, SC-005).

### Implementation for User Story 7

- [x] T046 [US7] Update `MasterDataSheets`-style header constant (or the equivalent private `HEADINGS` array) in `app/Services/PreorderExportImportService.php` to the new one-row-per-order column set (data-model.md's "Import/Export row shape"), removing the old row-per-item grouping logic entirely (research.md Decision 7, resolved Question 3 = full replace).
- [x] T047 [US7] Rewrite the read/validate path in `PreorderExportImportService` to: match `event_name` against `Event` (data-mode scoped, ambiguous match = row error); parse `fulfillment` as "pickup"/"mail order"; parse `pickup_day` as "Day N" resolved against the matched event's date range; split `products`/`quantities`/`unit_prices` by comma and match positionally (count mismatch = row error per FR-018); validate `courier_name`/`shipping_cost` only for mail-order rows, same cross-field rules as feature 021's `resolvePickupDayAndCourier()`.
- [x] T048 [US7] Rewrite the apply path in `PreorderExportImportService` to create one `Preorder` + its `PreorderItem`s per valid row from the new shape, keeping the all-or-nothing transaction convention.
- [x] T049 [US7] Rewrite the export path in `PreorderExportImportService` to produce the identical column layout (round-trip guarantee, SC-005).
- [x] T050 [US7] Update `lang/id/preorders.php`/`lang/en/preorders.php` with new/changed import error message keys for the new column shape (event name not found/ambiguous, product/quantity count mismatch, etc.).
- [x] T051 [US7] Update `docs/openapi-pos-mvp.yaml`'s import/export template and endpoint docs to the new column layout.

**Checkpoint**: All seven user stories independently functional.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: Final consistency pass across the whole feature.

- [x] T052 [P] Run the full backend suite: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`.
- [x] T053 [P] Run the full frontend suite: `npm test`.
- [x] T054 Execute every scenario in `specs/022-preorder-invoice-crud-overhaul/quickstart.md` against the dev stack via real-browser verification (Constitution II) — reseed dev data afterward if anything was created/deleted during testing.
- [x] T055 Verify `docs/openapi-pos-mvp.yaml` is fully in sync with every route/response change made across all phases above (PRD §9.5 / CLAUDE.md API conventions).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup. Blocks US3 and US4 specifically (both consume T004's helper); does not block US1, US2, US6, US7, which have no dependency on it.
- **US1 (Phase 3)**: Depends only on Setup — independent of Foundational.
- **US2 (Phase 4)**: Depends on Setup's T002 migration — independent of Foundational and US1.
- **US3 (Phase 5)**: Depends on Foundational (T004/T005).
- **US4 (Phase 6)**: Depends on Foundational (T004) AND US3 (reuses its document shell/wording — spec.md's own stated dependency).
- **US5 (Phase 7)**: Depends on US3 and US4 (bulk-generates their documents) and Setup's T001 (`jszip`).
- **US6 (Phase 8)**: Independent of everything else — can run any time after Setup.
- **US7 (Phase 9)**: Independent of Foundational/US1–US6 at the code level, though it reuses feature 021 terminology already in place.
- **Polish (Phase 10)**: Depends on all desired stories being complete.

### Suggested MVP Scope

User Story 1 alone (Phase 3) is the MVP — it closes the single most-requested gap (no way to fix or remove a mistaken pre-order) and needs nothing from any other story.

### Parallel Opportunities

- T001–T003 (Setup) can run together.
- T006/T007 (US1 tests) in parallel; T013 (frontend api) in parallel with backend T008–T012.
- US1 and US2 can be built in parallel by different people (no shared files).
- US3 must finish before US4 starts; US3+US4 must finish before US5 starts.
- US6 and US7 can be built any time in parallel with the rest, by a third developer.

---

## Parallel Example: User Story 1

```bash
# Tests first:
Task: "Write tests/Feature/PreorderUpdateTest.php"
Task: "Write tests/Feature/PreorderDeleteTest.php"

# Then backend service work (sequential — same file, PreorderService.php):
Task: "Add PreorderService::update()"
Task: "Add PreorderService::delete()"

# Frontend api client can proceed in parallel with backend controller wiring:
Task: "Add updatePreorder()/deletePreorder() to resources/js/api/preorders.js"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup.
2. Complete Phase 3: User Story 1 (Foundational is not required for US1).
3. **STOP and VALIDATE**: run T006/T007, verify edit/delete manually against quickstart.md's US1 section.
4. Deploy/demo if ready.

### Incremental Delivery

1. Setup → US1 (MVP) → US2 → Foundational → US3 → US4 → US5 → US6 → US7 → Polish.
2. Each story adds value without breaking previous ones; US4/US5's dependency on US3 is the only hard ordering constraint besides Foundational→US3/US4.
