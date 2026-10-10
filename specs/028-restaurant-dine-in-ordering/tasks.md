---

description: "Task list for Restaurant Dine-in Ordering Core (Tables, Open Tabs, Modifiers, Kitchen Tickets)"
---

# Tasks: Restaurant Dine-in Ordering Core

**Input**: Design documents from `/specs/028-restaurant-dine-in-ordering/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–14), data-model.md, contracts/api.md, quickstart.md

**Tests**: INCLUDED. Constitution II makes tests mandatory in this repo (backend under `tests/Feature/` against real MySQL, frontend under `qa-tests/`, plus a real-browser check). Within each story, test tasks come first; write them, run them, and **confirm they fail** before implementing.

**Organization**: Grouped by user story (US1 Tables & floor · US2 Tabs core · US3 Modifiers · US4 Kitchen/bar · US5 Move/merge/split · US6 Cancel/void). The plan's "Slice A" (foundation + impact guards) is Phase 2 here because every story depends on it.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US6, only on user-story phase tasks
- Code comments, UI copy and commit messages are in **Indonesian** (project convention; comments explain *why*, using the `BUG YANG DITEMUKAN & DIPERBAIKI` header for anything discovered while running). UI strings go in BOTH `resources/js/locales/en.json` and `id.json`; backend messages in BOTH `lang/en/restaurant.php` and `lang/id/restaurant.php`.
- Every route change updates `docs/openapi-pos-mvp.yaml` **in the same commit** (constitution).

## Safety reminders (CLAUDE.md — this checkout is separate from any other BoothPOS install)

- Backend tests in Docker **only** as `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test …`. A bare run drops the dev database (`warkop_wjm`). Never touch any `boothpos-*` container or the `boothpos`/`boothpos_test` databases.
- Ports for this checkout: app `8010`, Vite `5180`, MySQL host `3308`.
- Migration filename prefixes (`2026_10_31_000001` … `000008`) are FK-order-load-bearing; never rename or reorder.
- Stock changes go through `StockService` only; payments through `PaymentRecorder` only; audit log through `ActivityLogger` **inside the mutating transaction**.
- Any `DB::table('orders'|'order_items')` raw query must filter `data_mode` explicitly (global scope does not apply).
- A `FormRequest` `unique:`/`exists:` rule bypasses Eloquent scopes — scope by `ModeGate::current()` explicitly.
- `cashier_sessions.status` also uses the literal `'open'`; use `Order::STATUS_OPEN` constants in new code.
- Never invent identifiers: reuse names exactly as written in `data-model.md` and `contracts/api.md`.

---

## Phase 1: Setup

**Purpose**: baseline and scaffolding shared by every story

- [X] T001 Run the full existing backend suite with the safe command and record pass count and duration in `specs/028-restaurant-dine-in-ordering/baseline.md` (reference for SC-008: no existing expectation may change)
- [X] T002 [P] Create empty message groups (`tables`, `tabs`, `modifiers`, `kitchen`, `errors`) in `lang/en/restaurant.php` and `lang/id/restaurant.php`
- [X] T003 [P] Add empty top-level namespaces `floor`, `kitchen`, `diningSetup` once each to `resources/js/locales/en.json` and `resources/js/locales/id.json` (never repeat a key within one section — CLAUDE.md JSON-duplicate trap)
- [X] T004 [P] Write `qa-tests/unit/localeParity.test.js`: scan the raw text of both locale files and fail on any duplicated key within one object, and fail if the `en` and `id` key sets differ

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: schema for open tabs, tracked-stock plumbing, menu keys, and the six guards that stop an `open` order being counted as money (research.md Decision 5). **No user story can start until this phase is complete.**

### Tests first (must fail before implementation)

- [X] T005 [P] Write `tests/Feature/TabImpactGuardsTest.php`, one test per research Decision-5 row 1–6, creating open orders directly with `Order::create(['status' => 'open', …])`: (1) shift close ignores an open tab's cash; (2) `POST /orders/{id}/void` on an open order → 409 and no stock returned; (3) `POST /orders` with a `local_ref` belonging to an open order → 409 (the controller maps the service's `ValidationException`) with an error on `local_ref`; (4) `GET /orders` without `status` omits open orders; (5) `GET /orders/{id}/receipt` for an open order → 409; (6) `GET /customers/{id}/transactions` omits open orders
- [X] T006 [P] Write `tests/Feature/TrackedStockTest.php`: `applyMovementIfTracked()` behaves exactly like `applyMovement()` for tracked variants (including the insufficient-stock validation error), returns `null` and writes no movement for `track_stock = false`; retail `OrderService::create()`/`void()` with a non-tracked variant leave stock untouched
- [X] T007 [P] Write `tests/Feature/MenuKeysMigrationTest.php`: `MenuKeys::ALL` contains `floor`, `kitchen`, `dining_setup`; the data migration grants Owner/Admin all three, Cashier `floor`+`kitchen`, Inventory `dining_setup`; it is idempotent; `down()` removes only those keys; `GET /menu-keys` lists them
- [X] T008 [P] Write `tests/Feature/OrderOpenStatusSchemaTest.php`: `status='open'` is accepted; omitted `status` still defaults to `completed`; `service_type` defaults to `retail`; `open_table_key` rejects a second open order on the same table but allows completed/voided/table-less orders to coexist; the migration `down()` refuses while an open order exists

### Implementation

- [X] T009 Create migration `database/migrations/2026_10_31_000001_create_dining_tables_table.php` per data-model.md (`name`, `area` NOT NULL default `''`, `seats`, `display_order`, `is_active`, `data_mode`, UNIQUE `(data_mode, area, name)`, no soft delete)
- [X] T010 [P] Create migration `database/migrations/2026_10_31_000005_add_track_stock_to_product_variants.php` (boolean, default true)
- [X] T011 Create migration `database/migrations/2026_10_31_000006_add_tab_columns_to_orders_items_and_payments.php`: restate the full `orders.status` enum with `open` via `ALTER TABLE … MODIFY` keeping default `completed`; add `service_type`, `table_id` (restrictOnDelete), `tab_label`, `split_from_order_id` (nullOnDelete), generated STORED `open_table_key` + UNIQUE index; add `order_items.modifiers` json, `note`, `sent_at`; (NOT `payments.client_ref` — already created, char(36) UNIQUE, by `2026_10_31_000001_add_ledger_columns_to_payments_table`; adding it here failed with a duplicate column); `down()` throws while any `open` order exists (depends on T009)
- [X] T012 Create migration `database/migrations/2026_10_31_000008_add_restaurant_menu_keys_to_default_roles.php` mirroring `2026_10_19_000011_add_invoices_menu_key_to_default_roles.php`, and add `floor` ("Lantai & Tab"), `kitchen` ("Dapur & Bar"), `dining_setup` ("Pengaturan Restoran") to `app/Support/MenuKeys.php::ALL`
- [X] T013 [P] Create `app/Models/DiningTable.php` (`HasDataMode`, fillable, `orders()`/`openTab()` relations) and `database/factories/DiningTableFactory.php`
- [X] T014 Edit `app/Models/Order.php` (`STATUS_OPEN/COMPLETED/VOIDED` and `SERVICE_*` constants, fillable `service_type`/`table_id`/`tab_label`/`split_from_order_id`, `table()` and `splitFrom()` relations), `app/Models/OrderItem.php` (fillable + `modifiers` array cast + `sent_at` datetime), `app/Models/Payment.php` (`client_ref`), `app/Models/ProductVariant.php` (`track_stock` fillable + boolean cast) and `database/factories/ProductVariantFactory.php` (default `track_stock` true)
- [X] T015 Add `StockService::applyMovementIfTracked()` in `app/Services/StockService.php`: return `null` when the variant is not stock-tracked, otherwise delegate to the existing `applyMovement()` (no second write path)
- [X] T016 Edit `app/Services/OrderService.php`: `void()` rejects `open` with a `ValidationException` (existing `OrderController::void()` turns it into 409) before any stock work; `create()` rejects a `local_ref` that resolves to an `open` order with a `ValidationException` on `local_ref` (the existing controller turns it into 409); both call `applyMovementIfTracked()`; make `generateOrderNumber()` public so `TabService` reuses it
- [X] T017 Edit `app/Http/Controllers/Api/CashierSessionController.php::close()`: replace `status != 'voided'` (~L120) with an explicit `status = 'completed'`, with an Indonesian comment explaining why an open tab must never reach expected cash
- [X] T018 Edit `app/Http/Controllers/Api/OrderController.php`: `index()` defaults to `completed`+`voided` when no `?status=` is passed; `receipt()` returns 409 for an `open` order; add messages to `lang/en/orders_payments.php` and `lang/id/orders_payments.php`
- [X] T019 Edit `app/Http/Controllers/Api/CustomerController.php::transactions()` to exclude `open` orders
- [X] T020 [P] Harden `resources/js/composables/useSalesFilters.js` (`isVoided` and the summary/shift-summary reductions) to count only `status === 'completed'`, and add a case to the existing sales-filters component/unit test under `qa-tests/`
- [X] T021 **Checkpoint**: T005–T008 pass; run the full backend suite and the frontend suite — all existing tests green with no modified expectations (compare with `baseline.md`)

**Checkpoint**: foundation ready — an `open` order can exist and is provably not counted anywhere.

---

## Phase 3: User Story 1 - Set up tables and see the floor at a glance (Priority: P1)

**Goal**: managers define tables; any floor-permission user sees every table, free or occupied, grouped by area.

**Independent Test**: create five tables in two areas, deactivate one; the floor lists four active tables grouped by area, all free; the deactivated one is not offered. Cashier can view the floor but cannot manage tables (quickstart §3).

### Tests (write first, confirm failing)

- [X] T022 [P] [US1] Write `tests/Feature/DiningTablesTest.php`: create/edit/deactivate/delete; duplicate `(area, name)` rejected within the active data mode but allowed across modes; delete refused (409) once any order references the table; deactivating a table that has an open tab → 409; cashier → 403; inventory → allowed; DEMO/LIVE isolation
- [X] T023 [P] [US1] Write `tests/Feature/FloorViewTest.php`: tables grouped by area with `free`/`occupied`; occupied tile carries tab id/number/total/opened_at; takeaway tabs listed separately; inactive tables excluded; the number of SQL queries is constant for 5 vs 50 tables; `?version=` returns `{unchanged:true}` when nothing changed and a new token after any tab change
- [X] T024 [P] [US1] Write `qa-tests/unit/usePolling.test.js` with fake timers: polls at the interval, pauses while `document.hidden`, fetches immediately on becoming visible, recovers after a failed request with a full fetch, never overlaps an in-flight call, stops on unmount, sends the last `version` and skips re-render on `unchanged`
- [X] T025 [P] [US1] Write `qa-tests/component/FloorView.test.js` (API mocked with `vi.mock`): renders areas and tiles, free vs occupied styling, empty state
- [X] T026 [P] [US1] Write `qa-tests/component/TablesAdmin.test.js`: create, duplicate error from the central handler, deactivate, delete-guard message

### Implementation — backend

- [X] T027 [P] [US1] Create `app/Http/Requests/StoreDiningTableRequest.php` and `UpdateDiningTableRequest.php`: `authorize()` = `canAccessMenu('dining_setup')`; the unique rule scoped with `data_mode = ModeGate::current()` explicitly
- [X] T028 [P] [US1] Create `app/Http/Resources/DiningTableResource.php`
- [X] T029 [US1] Create `app/Http/Controllers/Api/DiningTableController.php` (index with `?active=1`, store, update, destroy): delete guard 409 with "deactivate instead", deactivate-with-open-tab 409, `ActivityLogger` entries inside the same transaction for delete/deactivate
- [X] T030 [US1] Create `app/Services/FloorQuery.php`: one aggregate query for open orders + tables grouped by area, takeaway tabs, `unsent_count` from `order_items.sent_at IS NULL`, `ready_count` returning 0 until US4, and a `version` token from `max(updated_at)` + row count of the relevant set
- [X] T031 [US1] Create `app/Http/Controllers/Api/FloorController.php` (`GET /floor`, requires `floor`, honours `?version=`)
- [X] T032 [US1] Register `dining-tables` apiResource and `GET /floor` in `routes/api.php`
- [X] T033 [P] [US1] Add table/floor messages to `lang/en/restaurant.php` and `lang/id/restaurant.php`
- [X] T034 [P] [US1] Document `dining-tables` and `/floor` in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T035 [P] [US1] Create `resources/js/api/diningTables.js` and `resources/js/api/floor.js`
- [X] T036 [US1] Create `resources/js/composables/usePolling.js` (visibility-aware, in-flight guard, jitter, version token, self-healing, unmount cleanup)
- [X] T037 [US1] Create `resources/js/stores/floor.js` (floor snapshot driven by `usePolling`)
- [X] T038 [P] [US1] Create `resources/js/components/restaurant/TableCard.vue` (design tokens only, no hex)
- [X] T039 [US1] Create `resources/js/views/FloorView.vue` (areas grid + takeaway tabs; tab click is wired in US2)
- [X] T040 [P] [US1] Create `resources/js/components/restaurant/TablesAdmin.vue`
- [X] T041 [US1] Create `resources/js/views/DiningSetupView.vue` (tabbed shell; only the Tables tab for now)
- [X] T042 [US1] Register routes `/floor` (`menuKey: 'floor'`) and `/dining-setup` (`menuKey: 'dining_setup'`) in `resources/js/router/index.js` and add the collapsible `restaurant-group` ("Restoran") to `resources/js/components/layout/AppSidebar.vue`, hidden entirely when no child is permitted
- [X] T043 [US1] Fill the `floor.*` and `diningSetup.*` keys in both locale files
- [ ] T044 [US1] **Verify**: US1 tests green; run quickstart §3 in a real browser at `http://localhost:8010` (owner, then cashier) with the console open and zero errors

**Checkpoint**: US1 is demonstrable on its own.

---

## Phase 4: User Story 2 - Open a tab, add items over several rounds, settle at the end (Priority: P1) 🎯 MVP

**Goal**: a waiter/cashier opens a tab on a table (or takeaway), adds items across rounds, and the cashier takes payment (partial, split, or full) producing an ordinary sale and receipt.

**Independent Test**: with an open shift, open a tab, add two items, later a third, pay with a cash+transfer split → one completed sale with three items, table free, stock moved correctly, receipt matches (quickstart §4). **Together with Phases 1–3 this is the MVP.**

### Tests (write first, confirm failing)

- [X] T045 [P] [US2] Write `tests/Feature/TabLifecycleTest.php`: open on a free table; second open on the same table → 409 with `existing_tab_id`; takeaway tab with `tab_label`; inactive table refused; shift not open → 422; `local_ref` makes a retried open return the first tab; table/customer from the other data mode → 404/422; close-empty (204) and refused when it has lines; handover (owner/admin only, target shift must be open); shift close → 409 listing open tabs and succeeding once they are settled or voided; two parallel opens of one table yield exactly one 201 (DB-level `open_table_key`)
- [X] T046 [P] [US2] Write `tests/Feature/TabLinesTest.php`: add/qty-change/remove on unsent lines; prices computed server-side (a client-sent price is ignored); identical unsent lines merge; sent lines are not editable (409); inactive variant 422; insufficient stock → 409 naming the available quantity (as the retail sale); `track_stock=false` never blocks; two interleaved adds to one tab both persist
- [X] T047 [P] [US2] Write `tests/Feature/TabStockTest.php` asserting every row of the data-model stock table that applies in this story: `sale` on add (movement `reference_type='order_item'`, `reference_id` = the item id), `return` on reduce/remove of an unsent line, no movement on settle, each unit deducted/restored at most once across retries
- [X] T048 [P] [US2] Write `tests/Feature/TabSettlementTest.php`: partial payment keeps the tab open and shows `balance`; full payment completes it, frees the table, computes change; the cash-overpay/change rule and non-cash proof rule are those of `PaymentRecorder`; repeating a `client_ref` records nothing twice; second settle of a completed tab → 409; removing a line that would drop the total below `paid_amount` → 409; completed receipt shows table/`tab_label`
- [X] T049 [P] [US2] Write `tests/Feature/TabReportsExclusionTest.php`: an unpaid open tab with items is absent from `GET /reports/sales`, profit, artist-profit, seller settlement and its export, the Sales page payload and its export, dashboard totals, customer history and session summary; after payment it appears exactly once
- [X] T050 [P] [US2] Write `tests/Feature/TabDataModeTest.php`: tabs and tables created in DEMO never appear in LIVE and vice versa; a DEMO `table_id` supplied in LIVE → 404/422
- [X] T051 [P] [US2] Write `tests/Feature/TabPermissionsTest.php` for the floor endpoints × default roles (owner, admin, cashier, inventory, a role with no `floor`), and assert the tab payload carries no cost fields
- [X] T052 [P] [US2] Write `qa-tests/component/TabView.test.js` (mocked API): add line, edit qty, remove unsent, totals from server, pay flow sends a `client_ref`
- [X] T053 [P] [US2] Write `qa-tests/component/SessionOpenTabs.test.js`: the close-shift 409 with `open_tabs` renders the list with links

### Implementation — backend

- [X] T054 [US2] Create `app/Services/TabService.php`: `open()` (maps the unique-violation on `open_table_key` to 409; takes number from `OrderService::generateOrderNumber()`), `addLine()`, `updateLine()`, `removeUnsentLine()`, `closeEmpty()`, `handover()`, `recalculate()`; every mutation in a transaction that first `lockForUpdate()`s the order row; stock only via `applyMovementIfTracked()` with `reference_id` = item id; logs through `ActivityLogger` where sensitive
- [X] T055 [US2] Create `app/Services/TabSettlementService.php`: record payments via the unchanged `PaymentRecorder` with the call's `client_ref`, accumulate `paid_amount`, complete the tab when covered (change ≤ cash received), idempotency by `client_ref`, 409 when not `open`
- [X] T056 [P] [US2] Create `app/Http/Requests/OpenTabRequest.php`, `AddTabLineRequest.php`, `UpdateTabLineRequest.php`, `TabPaymentRequest.php`, `HandoverTabRequest.php` (`authorize()` = `floor`; handover also `isOwnerOrAdmin()`)
- [X] T057 [P] [US2] Create `app/Http/Resources/TabResource.php` (shape in contracts/api.md, cashier-safe) and extend `app/Http/Resources/OrderResource.php` with `service_type`, `table`, `tab_label`, `split_from_order_number` (all `whenLoaded`/null-safe)
- [X] T058 [US2] Create `app/Http/Controllers/Api/TabController.php` (index/show/store/destroy/handover), `TabLineController.php` (store/update/destroy — unsent only in this story) and `TabPaymentController.php` (store)
- [X] T059 [US2] Edit `CashierSessionController::close()` to return 409 with `{open_tabs:[{id, order_number, table}]}` while the shift holds an open tab (placed after the already-closed check, before the update) and add the lang key in `lang/{en,id}/events_sessions.php`
- [X] T060 [US2] Extend `OrderController::receipt()` / its resource so a completed tab sale includes `service_type`, table name, `tab_label` and `split_from_order_number`
- [X] T061 [US2] Accept `track_stock` in the variant create/update requests and expose it in `ProductVariantResource` and in `GET /products?with_variants=1` (opt-in shape unchanged otherwise)
- [X] T062 [US2] Register the `tabs`, `tabs/{id}/items…`, `tabs/{id}/payments`, `tabs/{id}/handover` routes in `routes/api.php` (grouped under the existing auth + licence middleware)
- [X] T063 [P] [US2] Add tab messages to `lang/en/restaurant.php` and `lang/id/restaurant.php`
- [X] T064 [P] [US2] Document the tab routes and the changed `sessions/{id}/close`, `orders` and receipt responses in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T065 [P] [US2] Create `resources/js/api/tabs.js`
- [X] T066 [US2] Extend `resources/js/stores/floor.js` with the current-tab state (server-owned lines; no local price maths)
- [X] T067 [P] [US2] Create `resources/js/components/restaurant/TabLinesList.vue`
- [X] T068 [US2] Create `resources/js/views/TabView.vue`: reuse `utils/posProductCards.js` and `ProductVariantPickerModal.vue` for browsing, totals from the server, remove unsent lines, pay through the existing `components/payment/RecordPaymentModal.vue` (mode `record`) with a generated `client_ref` (`utils/uuid.js`), then `ReceiptModal.vue`
- [X] T069 [US2] Wire `FloorView.vue`: free tile → "Buka tab" (optional customer), "Takeaway" button (label), occupied tile → `/floor/tabs/:id`
- [X] T070 [US2] Edit `resources/js/views/SessionView.vue` to show the open-tabs list from the 409 with links to each tab
- [X] T071 [US2] Add the "not stock-tracked" switch to the variant form in `resources/js/views/ProductsView.vue`
- [X] T072 [US2] Register `/floor/tabs/:id` (`menuKey: 'floor'`) in `resources/js/router/index.js`
- [X] T073 [US2] Add the `floor.tab.*` keys to both locale files
- [ ] T074 [US2] **Verify**: US2 tests green; run `specs/028-restaurant-dine-in-ordering/quickstart.md` §4 in a real browser and the parallel-open/parallel-settle `curl` checks of quickstart §9; console clean

**Checkpoint**: MVP complete — a restaurant can take dine-in orders and get paid.

---

## Phase 5: User Story 3 - Modifiers and notes (Priority: P2)

**Goal**: modifier groups with option prices and rules; staff choose options and a note when adding an item; snapshot on the line; effective price included.

**Independent Test**: a required single-choice `Size` group with a +5,000 option attached to one item; add it choosing the paid option and a note — line price, tab total and receipt all include +5,000 and show choice and note; rename the option later and the old tab/receipt are unchanged (quickstart §5).

### Tests (write first, confirm failing)

- [X] T075 [P] [US3] Write `tests/Feature/ModifierGroupsTest.php`: CRUD with options (replace-by-id semantics: omitted options are *deactivated*, never deleted); rule validation (min>max, single≠max 1, required⇒min≥1); attachments to products and categories (scoped ids); delete guard once any order line used the group; `dining_setup` required; DEMO/LIVE isolation
- [X] T076 [P] [US3] Write `tests/Feature/ModifierResolverTest.php` (service-level matrix): required / optional / single / multiple / min / max; option from a group not effective for the product → rejected; inactive option/group rejected; effective groups = product ∪ category without duplicates; negative unit price rejected; negative-delta option allowed when the total stays ≥ 0
- [X] T077 [P] [US3] Write `tests/Feature/TabModifiersTest.php`: line price = base + Σ deltas × qty computed server-side; snapshot unchanged after renaming/repricing/deactivating an option or group (SC-006); identical modifier sets merge, different sets do not; note stored and returned; receipt and tab payload show options and note; `?with_modifiers=1` is opt-in on `GET /products`
- [X] T078 [P] [US3] Write `qa-tests/component/ModifierPickerModal.test.js` (required group blocks confirm; max reached disables further options; running price shown)
- [X] T079 [P] [US3] Write `qa-tests/component/ModifierGroupsAdmin.test.js`

### Implementation — backend

- [X] T080 [US3] Create migration `database/migrations/2026_10_31_000004_create_modifier_groups_options_and_pivots.php` (`modifier_groups`, `modifier_options` with signed `price_delta`, pivots `modifier_group_product`, `modifier_group_category`)
- [X] T081 [P] [US3] Create `app/Models/ModifierGroup.php`, `app/Models/ModifierOption.php` (both `HasDataMode`) and `database/factories/ModifierGroupFactory.php`, `ModifierOptionFactory.php`; add `modifierGroups()` relations on `Product` and `Category`
- [X] T082 [US3] Create `app/Services/ModifierResolver.php`: resolve effective groups, validate the selection, return the snapshot `[{group, option, price_delta}]` and the delta sum
- [X] T083 [P] [US3] Create `app/Http/Requests/StoreModifierGroupRequest.php`, `UpdateModifierGroupRequest.php`, `AttachModifierGroupRequest.php` (`dining_setup`)
- [X] T084 [P] [US3] Create `app/Http/Resources/ModifierGroupResource.php`
- [X] T085 [US3] Create `app/Http/Controllers/Api/ModifierGroupController.php` (CRUD, `PUT /modifier-groups/{id}/attachments`, delete guard 409; `ActivityLogger` in the same transaction)
- [X] T086 [US3] Edit `ProductController` and `ProductResource` so `GET /products?with_variants=1&with_modifiers=1` includes active effective groups/options (opt-in; default response stays lean)
- [X] T087 [US3] Edit `TabService::addLine()`/`updateLine()` and `AddTabLineRequest`/`UpdateTabLineRequest` to accept `modifier_option_ids` and `note`, call `ModifierResolver`, store the snapshot, write the effective price into `order_items.sell_price`, and merge only identical unsent lines
- [X] T088 [US3] Edit `app/Http/Resources/TabResource.php`, `app/Http/Resources/OrderResource.php` and the receipt payload in `app/Http/Controllers/Api/OrderController.php` to return each line's `modifiers` and `note`
- [X] T089 [US3] Register the `modifier-groups` routes in `routes/api.php`
- [X] T090 [P] [US3] Add modifier messages to `lang/en/restaurant.php` and `lang/id/restaurant.php`
- [X] T091 [P] [US3] Document modifier routes and the changed product/tab/receipt shapes in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T092 [P] [US3] Create `resources/js/api/modifierGroups.js`
- [X] T093 [US3] Create `resources/js/components/restaurant/ModifierPickerModal.vue` (rules enforced client-side as UX only; the server is authoritative)
- [X] T094 [US3] Create `resources/js/components/restaurant/ModifierGroupsAdmin.vue` and add it as a tab in `resources/js/views/DiningSetupView.vue`
- [X] T095 [US3] Integrate the picker into `TabView.vue` and show options/note in `TabLinesList.vue` and `components/receipt/ReceiptModal.vue`
- [X] T096 [US3] Add the `modifiers.*` keys to both locale files
- [ ] T097 [US3] **Verify**: US3 tests green; run `specs/028-restaurant-dine-in-ordering/quickstart.md` §5 in a real browser, both languages, console clean

**Checkpoint**: US1–US3 each work independently.

---

## Phase 6: User Story 4 - Send items to the kitchen or bar and track progress (Priority: P2)

**Goal**: unsent lines are sent as per-station tickets; stations and waiters track `new → preparing → ready → served`; screens update within 5 seconds; tickets are printable in the browser.

**Independent Test**: a tab with a food item and a drink → one Kitchen ticket and one Bar ticket; advance each to served; the floor "items ready" flag appears and clears at the right moments (quickstart §6).

### Tests (write first, confirm failing)

- [X] T098 [P] [US4] Write `tests/Feature/StationsTest.php`: CRUD, unique per mode, delete guard (referenced by category/product/ticket → 409), `dining_setup` required, DEMO/LIVE isolation, `station_id` accepted on category/product update
- [X] T099 [P] [US4] Write `tests/Feature/KitchenTicketsTest.php`: send splits unsent lines into one ticket per station; precedence product → category → none; no-station lines go to a station-less ticket as `ready`; send stamps `sent_at` and locks the lines (edit → 409); empty send → 409; send is atomic (a failure leaves nothing half-sent); forward-only transitions (skipping → 409); `new→preparing→ready` need `kitchen`, `ready→served` need `floor` or `kitchen`; who/when recorded per transition; `cancelled` is not settable through the status endpoint
- [X] T100 [P] [US4] Write `tests/Feature/KitchenFeedTest.php`: station feed lists unfinished items plus items finished/cancelled in the last 30 minutes, oldest first, only that station's tickets; `?version=` short-circuit; `waiting_seconds`; ticket show endpoint for printing
- [X] T101 [US4] Extend `tests/Feature/TabPermissionsTest.php` with the kitchen endpoints × default roles (a `kitchen`-only role can use the station screen but cannot settle a tab)
- [X] T102 [P] [US4] Write `qa-tests/component/KitchenView.test.js` (station switch, status buttons only for allowed transitions, cancelled items clearly marked)
- [X] T103 [P] [US4] Write `qa-tests/component/StationsAdmin.test.js`

### Implementation — backend

- [X] T104 [US4] Create migration `database/migrations/2026_10_31_000002_create_stations_table.php`
- [X] T105 [US4] Create migration `database/migrations/2026_10_31_000003_add_station_id_to_categories_and_products.php` (nullable FK, `restrictOnDelete`)
- [X] T106 [US4] Create migration `database/migrations/2026_10_31_000007_create_kitchen_tickets_and_items_tables.php` (per data-model.md; `order_item_id` `nullOnDelete`; no history table)
- [X] T107 [P] [US4] Create `app/Models/Station.php`, `KitchenTicket.php`, `KitchenTicketItem.php` (all `HasDataMode`) and their factories under `database/factories/`
- [X] T108 [US4] Edit `app/Models/Category.php`, `Product.php` and their store/update requests and resources to accept and return `station_id`
- [X] T109 [US4] Create `app/Http/Controllers/Api/StationController.php`, `StoreStationRequest.php`, `UpdateStationRequest.php`, `StationResource.php` (delete guard 409)
- [X] T110 [US4] Create `app/Services/KitchenService.php`: `send()` (per-station tickets, no-station → `ready`, sets `order_items.sent_at`, one transaction), `transition()` (forward-only, who/when, permission split), station feed with version token and 30-minute window, ticket show
- [X] T111 [P] [US4] Create `app/Http/Requests/TicketItemStatusRequest.php` and `SendTabRequest.php`
- [X] T112 [US4] Create `app/Http/Controllers/Api/KitchenController.php` (station feed, `GET /tickets/{id}`, `PATCH /ticket-items/{id}/status`) and `app/Http/Resources/TicketResource.php`
- [X] T113 [US4] Add `POST /tabs/{id}/send` to `TabController` and the per-line `prep_status`/`station` to `TabResource`; sent lines reject edits in `TabService`
- [X] T114 [US4] Extend `app/Services/FloorQuery.php` with the real `ready_count` (grouped subquery over `kitchen_ticket_items`) while keeping the constant-query-count test green
- [X] T115 [US4] Register stations, tickets, ticket-items and send routes in `routes/api.php`
- [X] T116 [P] [US4] Add kitchen messages to `lang/en/restaurant.php` and `lang/id/restaurant.php`
- [X] T117 [P] [US4] Document station, kitchen and send routes in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T118 [P] [US4] Create `resources/js/api/stations.js` and `resources/js/api/kitchen.js`
- [X] T119 [US4] Create `resources/js/stores/kitchen.js` (station tickets via `usePolling` with the version token)
- [X] T120 [P] [US4] Create `resources/js/components/restaurant/TicketCard.vue` and `StationBoard.vue`
- [X] T121 [US4] Create `resources/js/components/restaurant/PrintableTicket.vue` and add an `@media print` block to `resources/css/app.css` using design tokens only (no new dependency; `window.print()`)
- [X] T122 [US4] Create `resources/js/views/KitchenView.vue` (station tabs, board, print button, recently-finished section)
- [X] T123 [US4] Create `resources/js/components/restaurant/StationsAdmin.vue` and add it as a tab in `DiningSetupView.vue`
- [X] T124 [US4] Add a "Station" dropdown to the category and product forms in `resources/js/views/CategoriesView.vue` and `resources/js/views/ProductsView.vue`
- [X] T125 [US4] Add the "Kirim" (send) button, per-line preparation status pill and the "served" action to `TabView.vue`
- [X] T126 [US4] Show the "items ready" flag on `TableCard.vue` and add the `/kitchen` route (`menuKey: 'kitchen'`) and sidebar child
- [X] T127 [US4] Add the `kitchen.*` keys to both locale files
- [ ] T128 [US4] **Verify**: US4 tests green; run `specs/028-restaurant-dine-in-ordering/quickstart.md` §6 in two real browsers, stopwatch the send→appears delay ten times (≥ 9 within 5 s, SC-003), hidden-tab and offline recovery, console clean

**Checkpoint**: US1–US4 work independently.

---

## Phase 7: User Story 5 - Move, merge and split tabs (Priority: P3)

**Goal**: staff move a tab to another free table, merge two tabs, or split a bill by items/units and pay part.

**Independent Test**: a tab with four items — pay two via split; a completed sale with those two exists, two remain open on the table, and the totals add up to the original to the cent (quickstart §7).

Depends on US2 and US4 (tickets must follow a merge).

### Tests (write first, confirm failing)

- [X] T129 [P] [US5] Write `tests/Feature/TabSplitMergeMoveTest.php`: move to a free table (old frees, new occupied, tickets unchanged) and to an occupied/inactive table → 409; merge moves lines + tickets + ticket items, source becomes `voided` ("digabung"), table frees, no stock movement, different shifts → 409; split of whole lines re-parents the same `order_items` rows, split of a partial unit shrinks and clones, new order has its own number and `split_from_order_id`, Σ(new)+Σ(remaining)=original to the cent, no stock movement, splitting a tab with any payment → 409, partial-unit on a sent-but-not-served line → 409, splitting all lines completes the sale and frees the table, repeated `client_ref` does not split twice
- [X] T130 [P] [US5] Write `qa-tests/component/SplitBillModal.test.js` (selection of lines and units, payment total equals the selection)

### Implementation — backend

- [X] T131 [US5] Add `move()` and `merge()` to `app/Services/TabService.php` (same-shift rule, re-point `kitchen_tickets.order_id` on merge only, never on split)
- [X] T132 [US5] Add `split()` to `app/Services/TabSettlementService.php` (new completed order via `generateOrderNumber()`, re-parent/shrink+clone, recompute both totals, record the split sale's payments, complete or void-as-fully-paid the remainder, `client_ref` idempotency, **no `StockService` call**)
- [X] T133 [P] [US5] Create `app/Http/Requests/MoveTabRequest.php`, `MergeTabRequest.php`, `SplitTabRequest.php`
- [X] T134 [US5] Add `move`, `merge` to `TabController` and `split` to `TabPaymentController`; register the routes in `routes/api.php`
- [X] T135 [P] [US5] Add messages to `lang/{en,id}/restaurant.php` and document the routes in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T136 [P] [US5] Extend `resources/js/api/tabs.js` with `moveTab`, `mergeTab`, `splitTab`
- [X] T137 [P] [US5] Create `resources/js/components/restaurant/MoveTabModal.vue` and `MergeTabModal.vue`
- [X] T138 [US5] Create `resources/js/components/restaurant/SplitBillModal.vue` (select lines/units, then payment through `RecordPaymentModal`)
- [X] T139 [US5] Add the move/merge/split actions to `TabView.vue` and an action menu on occupied tiles in `FloorView.vue`
- [X] T140 [US5] Add the move/merge/split keys to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [ ] T141 [US5] **Verify**: US5 tests green; run the move/merge/split rows of `specs/028-restaurant-dine-in-ordering/quickstart.md` §7 in a real browser, console clean

---

## Phase 8: User Story 6 - Remove items and void tabs safely (Priority: P3)

**Goal**: remove items before preparation freely; after preparation starts only an owner/admin with a reason (logged, stock not restored); void a whole tab safely.

**Independent Test**: remove an unsent tracked item → stock restored; cancel a `preparing` item as owner with a reason → stock **not** restored and an activity-log entry names the user and reason; void an unpaid tab → only unsent/`new` lines return stock (quickstart §7).

Depends on US2 and US4.

### Tests (write first, confirm failing)

- [X] T142 [P] [US6] Write `tests/Feature/TabVoidAndCancelTest.php`: unsent removal restores stock; sent+`new` cancel by a floor user needs a reason, restores stock, ticket item shows `cancelled`; `preparing`/`ready`/`served` cancel by cashier → 403, by owner/admin with a reason → allowed, **no** stock restore, activity-log entry (user, reason) written in the same transaction (rollback test: failure leaves no log row); a line in a completed order cannot be cancelled through the tab flow; void-tab restores only unsent/`new` lines, cancels unfinished ticket items, frees the table, needs owner/admin and a reason; void with any payment → 409; void of a completed tab-sale follows the unchanged existing rule
- [X] T143 [P] [US6] Write `qa-tests/component/CancelItemDialog.test.js` (reason required; the control is hidden for roles that may not cancel after cooking begins)

### Implementation — backend

- [X] T144 [US6] Extend `app/Services/TabService.php::removeLine()` to cover sent lines: ticket item `new` → any `floor` user + reason + stock `return`; `preparing`+ → `isOwnerOrAdmin()` + reason, no stock movement; delete the `order_items` row, mark the ticket item `cancelled` with who/when/reason, `ActivityLogger` entry inside the transaction; refuse lines of completed orders and removals that drop the total below `paid_amount`
- [X] T145 [US6] Add `voidTab()` to `app/Services/TabService.php` (owner/admin, reason, no payments, stock rules from data-model.md, cancel unfinished ticket items, status `voided`)
- [X] T146 [P] [US6] Create `app/Http/Requests/CancelTabLineRequest.php` and `VoidTabRequest.php`
- [X] T147 [US6] Wire `DELETE /tabs/{id}/items/{item}` (with `reason`) and `POST /tabs/{id}/void` in `TabLineController`/`TabController` and `routes/api.php`
- [X] T148 [P] [US6] Add messages to `lang/{en,id}/restaurant.php` and document the routes in `docs/openapi-pos-mvp.yaml`

### Implementation — frontend

- [X] T149 [US6] Create `resources/js/components/restaurant/CancelItemDialog.vue` (required reason; hidden — not disabled — when the user may not cancel at that state, using `canAccessMenu('settings')` for post-preparation)
- [X] T150 [US6] Add the void-tab action (with reason dialog) and the per-line cancel to `TabView.vue`
- [X] T151 [US6] Add the cancel/void keys to `resources/js/locales/en.json` and `resources/js/locales/id.json`
- [ ] T152 [US6] **Verify**: US6 tests green; run the cancel/void rows of `specs/028-restaurant-dine-in-ordering/quickstart.md` §7 in a real browser, console clean

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: prove the cross-cutting guarantees, close the documentation obligations, and run the final acceptance pass

- [X] T153 [P] Complete the role × endpoint matrix in `tests/Feature/TabPermissionsTest.php` for every new route (owner, admin, cashier, inventory, a custom `kitchen`-only role, a role with none) and assert unauthorized controls are absent from the UI payloads
- [X] T154 [P] Add `tests/Feature/TabPerformanceTest.php`: floor query count is constant; a 100-line tab settles in < 1 s; the tab payload eager-loads lines and payments once (no N+1)
- [X] T155 Re-run `tests/Feature/TabImpactGuardsTest.php` and `TabReportsExclusionTest.php` after all stories are in and extend them with kitchen/split/merge states (an open tab in any prep state is still excluded from every figure)
- [X] T156 Verify research risk 1: load-test 5 polling browser tabs while triggering a heavy report export; decide on `PHP_CLI_SERVER_WORKERS` in `docker/php/entrypoint.sh` and `docker/store/entrypoint.sh`; record the outcome (including any multi-worker misbehaviour) in `specs/028-restaurant-dine-in-ordering/research.md`
- [X] T157 Verify research risk 2: run the new migrations (`database/migrations/2026_10_31_000001`–`000008`) against a populated copy in `warkop_wjm_test`, confirm existing orders keep `status='completed'`, `service_type='retail'`, `open_table_key IS NULL`, and that rollback is refused while an open order exists
- [X] T158 [P] Add a dated note (restaurant-variant scope, not a rewrite of MVP cuts) to `docs/PRD-POS-Event-Multivendor.md`
- [X] T159 [P] Update `docs/RUNBOOK.md` (default long-lived event + single "Restoran" artist setup, polling load note, `PHP_CLI_SERVER_WORKERS` outcome)
- [X] T160 [P] Update `CLAUDE.md`: the `HasDataMode` model count 20 → 26 and the new models, the three menu keys, and the 028 summary (keep the plan pointer between the SPECKIT markers)
- [X] T161 Final `docs/openapi-pos-mvp.yaml` consistency pass: every route in `routes/api.php` added by this feature is present with its error codes
- [X] T162 Run the full backend suite with the safe command: all green, no modified expectations in existing tests (compare with `baseline.md`)
- [X] T163 Run `docker compose exec node npm test` and `npm run build` (output in `public/build`); confirm the new views build as separate lazy chunks and no new dependency was added
- [ ] T164 Execute quickstart §8 in a real browser at `http://localhost:8010` for every role (owner, cashier, inventory, a kitchen-only role): both languages, console clean, controls hidden rather than disabled
- [ ] T165 Re-run `specs/028-restaurant-dine-in-ordering/quickstart.md` §9 (parallel open, parallel settle, parallel add) and the 5-second station timing check once more on the finished build
- [X] T166 Confirm separation (record the result in `specs/028-restaurant-dine-in-ordering/baseline.md`): `docker ps` shows the `boothpos-*` containers unchanged and nothing in this change references their ports, volumes or databases
- [X] T167 Record out-of-scope follow-ups in `specs/028-restaurant-dine-in-ordering/research.md` (restaurant demo seeder, tax/service charge, recipe-based stock, waste category, equal-share split, per-unit ticket items)

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 Setup** → no dependencies.
- **Phase 2 Foundational** → after Phase 1; **blocks every story**. Tests T005–T008 first; migrations T009→T011 in order (T011 needs T009); T015 needs T014; T016–T019 need T014/T015.
- **US1 (Phase 3)** → after Phase 2. **US2 (Phase 4)** → after Phase 2 and US1 (needs tables, floor store, `usePolling`).
- **US3 (Phase 5)** → after US2 (modifiers attach to tab lines).
- **US4 (Phase 6)** → after US2. Independent of US3 (a venue can run kitchen tickets without modifiers).
- **US5 (Phase 7)** → after US2 **and US4** (merge re-points tickets).
- **US6 (Phase 8)** → after US2 **and US4** (sent-line cancellation needs ticket items).
- **Phase 9 Polish** → after the stories you intend to ship.

```text
Setup ─► Foundational ─► US1 ─► US2 ─┬─► US3
                                      ├─► US4 ─┬─► US5
                                      │        └─► US6
                                      └──────────────────► Polish
```

### Within each story

Tests (confirm failing) → migrations → models/factories → services → requests/resources → controllers → routes → lang + OpenAPI → frontend api → store → components → view → router/sidebar → locale → verify task.

### Parallel opportunities

- Phase 1: T002, T003, T004 together.
- Phase 2: the four test files T005–T008 together; T010, T013, T020 are independent of the migration chain.
- Within a story, every `[P]` test file is a different file and can be written at once; `[P]` request/resource/lang/OpenAPI/api-module tasks touch distinct files.
- After US2: **US3 and US4 can be built in parallel by two developers** (they touch different files; the only shared touch points are `TabView.vue`, `TabService.php`, `TabResource.php` — coordinate those three).

### Parallel example (US2 tests)

```text
T045 TabLifecycleTest      T046 TabLinesTest          T047 TabStockTest
T048 TabSettlementTest     T049 TabReportsExclusionTest   T050 TabDataModeTest
T051 TabPermissionsTest    T052 TabView.test.js       T053 SessionOpenTabs.test.js
```

## Implementation Strategy

### MVP first (US1 + US2)

1. Phase 1 → Phase 2 (prove the six guards) → US1 → US2.
2. **Stop and validate** with quickstart §3–§4 and §9; a restaurant can already take orders and get paid. Demo/ship this slice.

### Incremental delivery

3. US4 (kitchen) next — highest operational value after payment — or US3 (modifiers) if the menu is customisation-heavy; both can run in parallel.
4. US5 (move/merge/split), then US6 (cancel/void).
5. Phase 9 before declaring the feature done.

### Notes

- Commit after each task or logical group; commit messages in Indonesian. The port/database separation changes already on this branch should be committed **separately** from feature code.
- A task is not done until its test passes **and**, for user-facing work, it has been seen working in a real browser with a clean console (Constitution II).
- Do not mark the feature complete on the basis of the diff alone; use evidence from the current session.

## Implementation notes — deviations from this plan (2026-10-04)

- `ReasonDialog.vue` (generic) replaces the planned `CancelItemDialog`; it also serves tab void and sent-line cancel.
- The FloorView tile action menu (move/merge/split from the tile) was **not** built; those actions live on `TabView`.
- The setup namespace is `dining_setup` (snake_case) in the locale files, not `diningSetup`.
- Extra pieces not in the original task list: `HandlesTabErrors` (controller trait), `InputRejectedException` (→422), `TabConflictException` (→409 + extras), `ModifierGroupService`, `OpenApiCoverageTest` (route coverage + unquoted-brace guard), `TabPerformanceTest`.
- Migrations 000006/000007/000008 refuse `down()` while any `open` order exists (found via the T157 populated-data check).
- **Not done (browser-dependent, left open on purpose)**: T044, T074, T097, T128, T141, T152, T164, T165 — they need a logged-in real browser. An attempt to hand the browser an API token was denied by the permission policy and was not worked around.

