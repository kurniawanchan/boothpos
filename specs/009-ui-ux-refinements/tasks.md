---

description: "Task list for UI/UX Refinements Batch"

---

# Tasks: UI/UX Refinements Batch

**Input**: Design documents from `/specs/009-ui-ux-refinements/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-deltas.md, quickstart.md

**Tests**: Included — Constitution Principle II requires `tests/Feature/` coverage for every backend change and a real-browser check for every touched screen; these are not optional for this project.

**Organization**: Tasks are grouped by user story (US1–US8, matching spec.md's priorities P1/P1/P2/P2/P2/P3/P3/P3) so each can be delivered and verified independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US8)

## Path Conventions

Existing single Laravel + Vue repo (no `backend/`/`frontend/` split): `app/`, `resources/js/`, `lang/`, `routes/api.php`, `tests/Feature/`, `qa-tests/`, `docs/openapi-pos-mvp.yaml`.

---

## Phase 1: Setup

- [X] T001 Confirm dev environment is ready: `laradock-mysql-1` running, `.env.testing` present and pointed at `boothpos_test`, `npm run dev`/`php artisan serve` both start clean (no code changes — a go/no-go check before touching files)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Relations, policies, and locale keys that US3, US4, and US5 all depend on

**⚠️ CRITICAL**: US4 and US5 cannot start until T002–T005 are done; US1 depends on T006

- [X] T002 [P] Add `orders(): HasMany` and `preorders(): HasMany` relations (via `customer_id`) to `app/Models/Customer.php`, per data-model.md
- [X] T003 [P] Add `preorders(): HasMany` relation (via `event_id`) to `app/Models/Event.php`, alongside its existing `orders()`, per data-model.md
- [X] T004 [P] Add a `delete()` ability to `app/Policies/CustomerPolicy.php` restricted to owner/admin, matching the tier already used for Customer's other mutations
- [X] T005 [P] Add a `delete()` ability to `app/Policies/EventPolicy.php` restricted to owner/admin, matching the tier already used for Event's other mutations
- [X] T006 [P] Add `nav.logout` key (`"Keluar"` / `"Log out"`) to `resources/js/locales/id.json` and `resources/js/locales/en.json`, per research.md R3

**Checkpoint**: Relations, policies, and the logout locale key exist — US1, US4, and US5 implementation can now proceed

---

## Phase 3: User Story 1 - Cleaner, on-brand navigation and login (Priority: P1) 🎯 MVP

**Goal**: Login page drops irrelevant deployment/marketing copy; sidebar menu coloring is verified consistent; navbar shows store name, active event, username, and a localized logout control top-right

**Independent Test**: Load the login page and confirm the removed elements are gone; log in and confirm sidebar coloring and the navbar's store/event/user/logout display, in both languages

### Implementation for User Story 1

- [X] T007 [P] [US1] Remove the "Instalasi lokal · {host}" line, the "· Instalasi lokal" badge, and the three metric badges ("< 30 dtk", "< 15 mnt", "0 transaksi hilang") from `resources/js/views/LoginView.vue`, keeping the left form column's grid layout intact
- [X] T008 [US1] Live-browser screenshot comparison of `resources/js/components/layout/AppSidebar.vue`'s Purchase/Inventaris/Pengaturan default-state color against another top-level item, against the two reference images; patch the specific class only if a genuine difference is confirmed (research.md R1 — the source already applies identical classes, so do not patch speculatively)
- [X] T009 [US1] Move the username block and logout button out of `resources/js/components/layout/AppSidebar.vue`'s footer (current lines ~195–217) into `resources/js/components/layout/AppTopbar.vue`, rendered together in the top-right; keep `auth.logout()` (via `resources/js/stores/auth.js`) as the single logout call site
- [X] T010 [US1] Wire the relocated logout button's label through `t('nav.logout')` in `AppTopbar.vue` instead of the current hardcoded "Keluar" literal
- [X] T011 [US1] Locate the existing "active event" state source (grep `activeEvent`/`currentEvent`/`event_id` across `resources/js/stores/`) and the store-name source (Store Identity data already used by `resources/js/views/SettingsView.vue`); add `storeName` and `activeEvent` props to `AppTopbar.vue` and render them
- [X] T012 [US1] Pass `storeName`/`activeEvent` into `AppTopbar.vue` from its parent shell (`resources/js/components/layout/AppShell.vue` or equivalent) so they render on every authenticated screen
- [X] T013 [P] [US1] Add a Vitest spec in `qa-tests/` asserting the login page has none of the removed copy and that the navbar renders store name, active event, username, and logout in both locales
- [X] T014 [US1] Manual browser verification of login page, sidebar coloring, and navbar per `quickstart.md` steps 1–3, including a language toggle to confirm the logout label localizes

**Checkpoint**: User Story 1 fully functional and independently testable/demoable

---

## Phase 4: User Story 2 - Streamlined Sales page with drill-down popups (Priority: P1)

**Goal**: Sales page shows the transaction list first with no separate product table; transaction-number click opens a products-sold table popup (not a receipt); product-name click inside it opens the existing product-detail popup

**Independent Test**: Open Sales, confirm layout and popup-triggered-by-transaction-number-not-receipt, confirm product-name click opens product detail

### Implementation for User Story 2

- [X] T015 [P] [US2] Create `resources/js/components/sales/TransactionItemsModal.vue`: a `BaseModal`-based popup that fetches one order's detail via the existing `GET /orders/{order}` call (`resources/js/api/orders.js`) and renders a sold-items table (product name, quantity, price, subtotal), per research.md R4
- [X] T016 [US2] In `resources/js/views/SalesView.vue`, reorder the layout so the raw transaction list (currently ~L190-225) renders above/first, and remove the grouped summary `DataTable` (currently ~L155-170) and its `groupBy` control, per FR-003
- [X] T017 [US2] In `resources/js/views/SalesView.vue`, rewire the transaction-number click handler (currently `openReceipt()`, ~L106-109, opening `ReceiptModal`) to instead open `TransactionItemsModal` for that transaction's `id`
- [X] T018 [US2] Wire a product-name click inside `TransactionItemsModal.vue` to open the existing `resources/js/components/.../ProductDetailModal.vue` with the clicked item's `product-id`, reusing `SalesView.vue`'s existing `openRowDetail()` pattern
- [X] T019 [P] [US2] Add `qa-tests/sales-transaction-popup.spec.js`: covers transaction-list-is-primary layout, transaction-number opens products-sold popup (not receipt), and product-name-in-popup opens product detail
- [X] T020 [US2] Manual browser verification of the full Sales page flow per `quickstart.md` step 4

**Checkpoint**: User Stories 1 AND 2 both work independently; MVP scope (both P1 stories) is complete

---

## Phase 5: User Story 3 - Seller terminology rename (Priority: P2)

**Goal**: Every visible "Artist"/"Artists" label reads "Penjual"/"Sellers" respectively; no identifiers change

**Independent Test**: Toggle ID/EN locale and text-scan all screens referencing this concept for leftover "Artist"/"Artists"

### Implementation for User Story 3

- [X] T021 [P] [US3] In `resources/js/locales/id.json`, change the **values** (not keys) of every seller-label entry (`nav.artists`, `master_data.artists_subtitle`, `master_data.artist_updated/created/deactivated`, `reports.artist`, `reports.artist_label`, `reports.artist_profit_note*`, and any others found by grep) from "Artist"/"Artists" wording to "Penjual" wording
- [X] T022 [P] [US3] In `resources/js/locales/en.json`, change the same set of values from "Artist"/"Artists" to "Seller"/"Sellers" wording
- [X] T023 [P] [US3] In `lang/id/master_data.php`, `lang/id/reports.php`, `lang/id/policies.php` (and any other `lang/id/*.php` file a grep for "Artist" turns up), change values to "Penjual" wording
- [X] T024 [P] [US3] In the matching `lang/en/*.php` files, change values to "Seller(s)" wording
- [X] T025 [US3] Grep both locale JSON files, all `lang/*/*.php` files, and rendered `.vue` template text (excluding `menuKey`/route/prop/variable identifiers) to confirm zero remaining "Artist"/"Artists" label occurrences, per SC-004
- [X] T026 [US3] Manual verification: toggle ID/EN and scan Sidebar, Products, POS, Reports, and any other screen referencing sellers, per `quickstart.md` step 5

**Checkpoint**: User Stories 1–3 all independently functional; no identifiers, routes, or API contracts changed

---

## Phase 6: User Story 4 - Delete Events and Customers with no transactions (Priority: P2)

**Goal**: Owner/admin can delete an Event or Customer only when it has zero associated orders/preorders; blocked with a clear conflict otherwise

**Independent Test**: Delete a transaction-free Event/Customer (succeeds); attempt to delete one with a transaction (blocked with reason)

### Tests for User Story 4

- [X] T031 [P] [US4] `tests/Feature/EventDeleteTest.php`: delete succeeds with zero orders/preorders; blocked (409) with an order or preorder present (including a soft-deleted one); 403 for non-owner/admin
- [X] T032 [P] [US4] `tests/Feature/CustomerDeleteTest.php`: same coverage as above for Customer

### Implementation for User Story 4

- [X] T027 [P] [US4] Implement `EventController::destroy()` in `app/Http/Controllers/Api/EventController.php`: authorize via `EventPolicy::delete`, guard on `orders()`/`preorders()` existence (any status, `withTrashed()` where applicable), else soft-delete inside `DB::transaction()` with an `ActivityLogger` snapshot, mirroring `ArtistController::destroy()`
- [X] T028 [P] [US4] Implement `CustomerController::destroy()` in `app/Http/Controllers/Api/CustomerController.php` with the identical pattern (uses T002's new relations)
- [X] T029 [P] [US4] Add `DELETE /events/{event}` route to `routes/api.php`
- [X] T030 [P] [US4] Add `DELETE /customers/{customer}` route to `routes/api.php`
- [X] T033 [P] [US4] Add a `remove(id)` function to `resources/js/api/events.js`
- [X] T034 [P] [US4] Add a `remove(id)` function to `resources/js/api/customers.js`
- [X] T035 [US4] Add a delete action to `resources/js/views/EventsView.vue`, hidden entirely (not disabled) for roles without delete authorization, showing the 409 conflict message on failure
- [X] T036 [US4] Add a delete action to `resources/js/views/CustomersView.vue` with the same visibility/error handling
- [X] T037 [US4] Update `docs/openapi-pos-mvp.yaml` with `DELETE /events/{event}` and `DELETE /customers/{customer}` per `contracts/api-deltas.md`, in the same commit as the route/controller changes
- [X] T038 [US4] Manual browser verification of both delete flows (success + blocked) per `quickstart.md` steps 6–7

**Checkpoint**: User Stories 1–4 all independently functional

---

## Phase 7: User Story 5 - Customer transaction history (Priority: P2)

**Goal**: A customer's page shows every order and preorder they've ever made, type-labeled, opening into existing detail views

**Independent Test**: Open a customer with mixed orders/preorders and confirm all appear, correctly typed, and open into existing detail

### Tests for User Story 5

- [X] T041 [P] [US5] `tests/Feature/CustomerTransactionsTest.php`: mixed order+preorder data returns a merged, type-tagged, date-sorted list; empty state for a customer with none; respects the active DEMO/LIVE data mode

### Implementation for User Story 5

- [X] T039 [US5] Implement `CustomerController::transactions()` in `app/Http/Controllers/Api/CustomerController.php`: load `orders()`/`preorders()` (from T002), merge into `{type, id, number, status, total_amount, date}` rows sorted by date descending, `present()`-style per `contracts/api-deltas.md`
- [X] T040 [US5] Add `GET /customers/{customer}/transactions` route to `routes/api.php`
- [X] T042 [P] [US5] Add a `transactions(id)` function to `resources/js/api/customers.js`
- [X] T043 [US5] Add a "view transactions" action/tab to `resources/js/views/CustomersView.vue` (or a new customer-detail view) listing the merged history, with each row opening the existing order-detail or preorder-detail view depending on `type` — no new detail UI
- [X] T044 [US5] Update `docs/openapi-pos-mvp.yaml` with `GET /customers/{customer}/transactions` per `contracts/api-deltas.md`
- [X] T045 [US5] Manual browser verification per `quickstart.md` step 8

**Checkpoint**: User Stories 1–5 all independently functional

---

## Phase 8: User Story 6 - Dashboard per-customer statistics (Priority: P3)

**Goal**: Dashboard shows a per-customer table and chart (transaction count, total spend) for the active period

**Independent Test**: Load Dashboard with seeded multi-customer data; confirm table and chart agree with underlying transactions

### Tests for User Story 6

- [X] T047 [P] [US6] Extend `tests/Feature/ReportControllerTest.php` with `group_by=customer` coverage: correct aggregation, respects `data_mode` filtering, respects the date-range filter

### Implementation for User Story 6

- [X] T046 [US6] Extend `ReportController::sales()` in `app/Http/Controllers/Api/ReportController.php` to accept `group_by=customer`, aggregating `orders` by `customer_id` (joined to `customers.name`) in a single `GROUP BY` query — no per-customer loop, per research.md R8 / Constitution V
- [X] T048 [US6] Add a per-customer statistics table + chart panel to `resources/js/views/DashboardView.vue`, calling `salesReport({ group_by: 'customer', ... })` and reusing the view's existing Chart.js registration
- [X] T049 [US6] Wire the Dashboard's existing date-range filter (`dateFrom`/`dateTo`) to the new customer panel, matching how the other panels already filter
- [X] T050 [US6] Update `docs/openapi-pos-mvp.yaml`'s `GET /reports/sales` `group_by` enum to include `customer`
- [X] T051 [US6] Manual browser verification per `quickstart.md` step 9, cross-checking table/chart figures against a customer's known transaction total

**Checkpoint**: User Stories 1–6 all independently functional

---

## Phase 9: User Story 7 - Stock-by-artist drilldown in Reports (Priority: P3)

**Goal**: Clicking a seller row in the stock-by-artist report reveals variant count and total stock detail for that seller

**Independent Test**: Click a seller row and confirm variant-level detail matches underlying product/stock data

### Tests for User Story 7

- [X] T053 [P] [US7] Extend `tests/Feature/ReportControllerTest.php` with stock-by-artist drilldown coverage: `artist_id` param returns correct variant-level rows; omitted param leaves existing summary response unchanged; 404 for an `artist_id` outside the active data mode

### Implementation for User Story 7

- [X] T052 [US7] Extend `ReportController::stockByArtist()` in `app/Http/Controllers/Api/ReportController.php` to accept an optional `artist_id`, returning variant-level rows (`variant_id, sku, variant_name, current_stock`) for that one artist by reusing the existing artist→products→variants join, per research.md R9
- [X] T054 [US7] Add an on-demand row-click drilldown (popup or expanded row) to `resources/js/views/ReportsView.vue`'s stock-by-artist table, calling the endpoint with `artist_id` only when clicked (not eagerly)
- [X] T055 [US7] Update `docs/openapi-pos-mvp.yaml` for the `stockByArtist` `artist_id` param and its expanded response shape
- [X] T056 [US7] Manual browser verification per `quickstart.md` step 10

**Checkpoint**: User Stories 1–7 all independently functional

---

## Phase 10: User Story 8 - Remove Settings Data Backup section (Priority: P3)

**Goal**: Settings no longer shows the Data Backup section

**Independent Test**: Open Settings, confirm no Data Backup/"Cadangkan sekarang" section remains

### Implementation for User Story 8

- [X] T057 [P] [US8] Remove the Data Backup block from `resources/js/views/SettingsView.vue` (the contiguous section between the Store Identity form and the system-mode `ConfirmDialog`, per research.md R10)
- [X] T058 [P] [US8] Remove the now-orphaned `settings.data_backup`, `settings.run_from_server_console`, `settings.backup_command_note`, `settings.backup_files_note` keys from `resources/js/locales/id.json` and `en.json`, after grep-confirming no other reference
- [X] T059 [US8] Manual browser verification per `quickstart.md` step 11

**Checkpoint**: All 8 user stories independently functional

---

## Phase 11: Polish & Cross-Cutting Concerns

- [X] T060 [P] Full-app grep sweep confirming zero remaining "Artist"/"Artists" label text (final SC-004 pass, after all screens are touched)
- [X] T061 [P] Run `php artisan test` (full suite) — confirm no regressions across all 214+ existing tests plus this feature's new ones
- [X] T062 [P] Run `npm test` (full Vitest suite) — confirm no regressions
- [X] T063 Run the full `quickstart.md` end-to-end validation in a real browser
- [X] T064 If any non-obvious bug is found and fixed during implementation, record it in code with a `BUG YANG DITEMUKAN & DIPERBAIKI` comment and in README.md's bug list, per repo convention

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS US1 (needs T006), US4 (needs T002–T005), US5 (needs T002)
- **US1, US2 (Phase 3–4, P1)**: Can start after Foundational; independent of each other and of US3–US8
- **US3 (Phase 5, P2)**: Can start after Foundational; independent of all other stories (locale-only)
- **US4 (Phase 6, P2)**: Can start after Foundational (needs T002–T005)
- **US5 (Phase 7, P2)**: Can start after Foundational (needs T002); independent of US4 despite touching the same controllers/views — different methods, no shared task
- **US6, US7, US8 (Phase 8–10, P3)**: Can start after Foundational; each independent of every other story
- **Polish (Phase 11)**: Depends on all desired user stories being complete

### Within Each User Story

- Backend model/policy/relation changes before controller changes
- Controller/route changes before frontend API-client changes
- Frontend API-client changes before view/component wiring
- Feature tests before/alongside implementation (write first per Constitution II)
- Manual browser verification last, per story

### Parallel Opportunities

- All Phase 2 Foundational tasks (T002–T006) can run in parallel — different files
- Once Foundational is done, US1 and US2 (both P1) can proceed in parallel; US3–US8 can each proceed in parallel with everything else since none share a task
- Within US4: T027–T030 (backend) can run in parallel with each other; T031–T032 (tests) can run in parallel with each other and with T033–T034 (frontend API clients)
- Within US6/US7: the `ReportControllerTest.php` extension tasks (T047, T053) touch the same file — do not mark [P] against each other if worked by the same session; safe to parallelize across different sessions/branches

---

## Parallel Example: Foundational Phase

```bash
Task: "Add orders()/preorders() relations to app/Models/Customer.php"
Task: "Add preorders() relation to app/Models/Event.php"
Task: "Add delete() ability to app/Policies/CustomerPolicy.php"
Task: "Add delete() ability to app/Policies/EventPolicy.php"
Task: "Add nav.logout key to resources/js/locales/id.json and en.json"
```

## Parallel Example: User Story 4

```bash
Task: "Implement EventController::destroy() in app/Http/Controllers/Api/EventController.php"
Task: "Implement CustomerController::destroy() in app/Http/Controllers/Api/CustomerController.php"
Task: "tests/Feature/EventDeleteTest.php"
Task: "tests/Feature/CustomerDeleteTest.php"
Task: "Add remove(id) to resources/js/api/events.js"
Task: "Add remove(id) to resources/js/api/customers.js"
```

---

## Implementation Strategy

### MVP First (User Stories 1 + 2, both P1)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (needed for US1's logout key; US4/US5 relations can wait)
3. Complete Phase 3: US1 (login/sidebar/navbar)
4. Complete Phase 4: US2 (Sales page redesign)
5. **STOP and VALIDATE**: both P1 stories independently, per their Independent Test criteria
6. Ship/demo — this alone addresses the most visible, highest-traffic screens (login, every screen's navbar, Sales)

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 → verify → ship (login/navbar/sidebar polish)
3. US2 → verify → ship (Sales redesign)
4. US3 → verify → ship (seller rename, locale-only, lowest risk)
5. US4 → verify → ship (Event/Customer delete)
6. US5 → verify → ship (customer transaction history)
7. US6 → verify → ship (Dashboard customer stats)
8. US7 → verify → ship (stock-by-artist drilldown)
9. US8 → verify → ship (remove Data Backup section)
10. Phase 11 polish as a final cross-cutting pass

### Suggested MVP Scope

**User Stories 1 and 2** (both P1) — login/navbar cleanup and the Sales page redesign are the two changes touching the screens every user sees most often, and neither depends on any other story.

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- T002/T003 (new relations) are the single prerequisite shared by US4 and US5 — do not skip them even though neither story's phase heading mentions "Foundational" by name
- Verify tests fail before implementing (Constitution II / TDD discipline)
- Every backend task pairs with a `tests/Feature/` test in the same story phase; every touched screen gets a manual browser-verification task at the end of its phase — neither is optional for this project
- Commit after each task or logical group
- Stop at any checkpoint to validate a story independently
- Avoid: same-file conflicts flagged as [P], cross-story dependencies that break independence
