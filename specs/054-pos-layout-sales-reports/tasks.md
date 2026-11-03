**Classification: INTERNAL**

---

description: "Task list for 054 POS layout, Sales page, reports, cashier sessions"
---

# Tasks: POS Layout Refresh, Sales Page Upgrades, Sales Reports and Cashier Session List

**Input**: Design documents from `/specs/054-pos-layout-sales-reports/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R15), data-model.md, contracts/api-changes.md, quickstart.md

**Test locations (actual)**: component tests live in `qa-tests/component/`, composable/util tests in `qa-tests/unit/` (the paths in the task text were guesses; US1 → `qa-tests/component/ProductVariantPickerModal.test.js` + `PosVariantPicker.test.js`, US2 → `PosCartPanel.test.js` + `PosView.test.js`, US5 → `unit/SalesKpiAndPagination.test.js`, `component/SalesAllEvents.test.js`, `component/SalesPagination.test.js`).

**Tests**: INCLUDED. The constitution (Principle II) requires tests for every change plus a real-browser check for every changed screen, so each story has test tasks written first (they must fail before the implementation).

**Organization**: grouped by user story so each can be built, tested and shipped alone. Priorities from spec.md: P1 = US1, US2, US5; P2 = US3, US4, US6, US7, US8, US10, US11, US12; P3 = US9.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US12 (user-story phases only)
- Backend tests: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>` (NEVER without the `-e` overrides). Frontend: `npx vitest run --exclude '.claude/**'`.
- UI copy goes in BOTH `resources/js/locales/en.json` and `id.json`; `qa-tests/unit/localeKeys.test.js` fails on a missing literal key.
- Every raw `DB::table(...)` query touching a `HasDataMode` table filters `data_mode = ModeGate::current()` explicitly.

---

## Phase 1: Setup

- [X] T001 Confirm `.env.testing` exists and points at `boothpos_test`; run the existing backend suite filter `SalesTransactions|SalesPageData|PaymentLedger|ReportDataModeIsolation|CloneRegistryDrift` once and record the green baseline in `specs/054-pos-layout-sales-reports/quickstart.md` notes (no code change)
- [X] T002 [P] Run `npx vitest run --exclude '.claude/**'` once and record the baseline failures (if any) so later failures can be attributed correctly

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: the two helpers several stories would otherwise duplicate.

- [ ] T003 Locate the existing `%`/`_`-escaping helper used by `StockMovement::scopeMatchingListFilters()`; if it is not reusable, extract it to `app/Support/LikeEscape.php` (single implementation) and switch the existing caller to it; add a unit test in `tests/Unit/LikeEscapeTest.php` (used by US10) *(deferred: only US10 needs it)*
- [ ] T004 [P] Add the `api` client functions skeleton (empty exports with JSDoc) for the new endpoints in `resources/js/api/orders.js`, `resources/js/api/reports.js`, `resources/js/api/sessions.js` so story tasks only fill bodies (functions listed in contracts/api-changes.md §9) *(deferred: fill each function in its own story instead of stubbing)*

**Checkpoint**: stories can start.

---

## Phase 3: User Story 1 — Pick several variants in one go (P1) 🎯 MVP

**Goal**: the variant picker stays open after each choice, shows per-variant cart counts, and closes via a bottom Close button.
**Independent test**: open a 3+-variant product, add two variants, picker stays open with counts, bottom Close keeps the cart.

### Tests (write first, must fail)

- [X] T005 [P] [US1] Create `qa-tests/unit/PosVariantPicker.test.js`: tapping a variant emits `select` and the picker does NOT emit `close`; per-variant "in cart" count renders from `cartQtyByVariant`; out-of-stock variant is not selectable; the footer Close button emits `close`; the footer stays rendered with 8 variants
- [X] T006 [P] [US1] Extend `qa-tests/unit/PosScan.test.js` (or the existing PosView test) with: choosing a variant leaves `showVariantPicker` true and the cart gains the line; Close keeps the cart; single-variant product still adds directly

### Implementation

- [X] T007 [US1] In `resources/js/components/pos/ProductVariantPickerModal.vue` add prop `cartQtyByVariant` (object), render a "×N in cart" badge per variant, add a sticky footer with a `BaseButton` "Close" (`data-testid="picker-close"`) emitting `close`; keep image lightbox behaviour
- [X] T008 [US1] In `resources/js/views/PosView.vue` change `handleVariantPick` to only call `addToCart` (no closing, no clearing `variantPickerCard`); add computed `cartQtyByVariant` from `cart.items`; pass it to the picker; keep `closeVariantPicker` as the only closer
- [X] T009 [P] [US1] Add locale keys (`pos.picker_close`, `pos.in_cart_count`) to `resources/js/locales/en.json` and `id.json`
- [ ] T010 [US1] Run T005/T006 plus `PosScan` and existing POS tests green; real-browser check (quickstart step 1) at tablet and laptop width, console clean **[Automated part done: picker/PosView/PosScan/locale tests green. Real-browser check NOT done — browser MCP unavailable this session.]**

**Checkpoint**: US1 shippable alone.

---

## Phase 4: User Story 2 — Draft actions at the top of the cart (P1)

**Goal**: "Save as draft" and "Draft Transactions" live in the cart panel header, not the search row.
**Independent test**: both buttons in the cart header, absent from the search row, behaviour unchanged.

- [X] T011 [P] [US2] Create `qa-tests/unit/PosCartHeader.test.js`: the cart panel renders both buttons; Save is disabled when the cart is empty and Drafts is not; clicks emit `save-draft` / `open-drafts`; the search row no longer contains them
- [X] T012 [US2] In `resources/js/components/pos/PosCartPanel.vue` add props `savingDraft`, emits `save-draft`/`open-drafts`, and a second header row `[Save as draft | Draft transactions]` below title/Clear (design tokens only, no hex; wraps safely at the 372 px width)
- [X] T013 [US2] In `resources/js/views/PosView.vue` remove the two `BaseButton`s from the search row, wire `@save-draft="saveDraft"` / `@open-drafts="showDrafts = true"` and pass `:saving-draft="savingDraft"`
- [ ] T014 [US2] Run `PosCartHeader` + existing draft tests; browser check (quickstart step 2): save a draft, resume it, no overlap with the Clear action at tablet width **[Automated part done. Real-browser check NOT done — browser MCP unavailable.]**

---

## Phase 5: User Story 5 — Sales statistics, All events and pagination agree (P1)

**Goal**: KPI cards equal the filtered rows (all pages); "All events" is selectable; the table is paginated and restorable from the address.
**Independent test**: apply cashier + date + event filters; cards, summary line and total row count match; choose All events; page through; reload keeps page.

### Tests (write first)

- [X] T015 [P] [US5] Extend `tests/Feature/SalesTransactionsTest.php`: each row carries `items_gross` and `items_net` (2-decimal strings) equal to Σ(line_total + item discount) / Σ(line_total), and for an unfiltered list their sums (non-voided) equal `GET /reports/sales` `totals.pos_gross_sales` / `pos_net_sales`
- [X] T016 [P] [US5] Create `qa-tests/unit/SalesKpiAndPagination.test.js` for `useSalesFilters`: KPI totals follow every filter (cashier, seller, search, dates, min/max, voided hidden), voided never counted, zero rows → zeros; `paged` slices `sorted`; changing any filter/sort/`includeVoided` resets `page` to 1; `page`/`size` round-trip through `toQuery`/`applyQuery`; an out-of-range or garbage page is clamped; "select all" still addresses all filtered rows
- [X] T017 [P] [US5] Create `qa-tests/unit/SalesAllEvents.test.js`: the Sales event options contain an empty "All events" option that is clickable and makes the view reload with no `event_id`

### Implementation

- [X] T018 [US5] In `app/Services/SalesTransactionsService.php::orderRow()` add `items_gross` and `items_net` (per research R4); update the OpenAPI `SalesTransaction` schema in `docs/openapi-pos-mvp.yaml`
- [X] T019 [US5] In `resources/js/composables/useSalesFilters.js` add `kpis` computed (transaction count, units, gross = Σ items_gross, net = Σ items_net, voided excluded, fall back to `total_amount` when the new fields are absent), `page`, `pageSize` (default 25), `paged`, `pageCount`, page reset watchers, and `page`/`size` in `toQuery`/`applyQuery`
- [X] T020 [US5] In `resources/js/views/SalesView.vue`: feed the four KPI cards from `kpis` (stop reading `sales.totals`); prepend `{ value: '', label: t('reports.all_events') }` to the event options; add `page`/`size` to `OUR_QUERY_KEYS`; render `paged` rows in `DataTable` and the existing `resources/js/components/ui/TablePagination.vue` below it (page-size choices 25/50/100); relabel "Select all" to mean all filtered rows
- [X] T021 [P] [US5] Add locale keys for page size/summary wording to `en.json` and `id.json`
- [ ] T022 [US5] Run T015–T017 and the existing `SalesPageDataTest`/`useSalesFilters` tests green; real-browser check (quickstart step 5) incl. All events and reload-restores-page **[T015–T017 + SalesView/SalesViewAdvanced tests green, 1012 FE tests pass. Real-browser check NOT done — browser MCP unavailable.]**
- [X] T023 [US5] Add the dated note to `CLAUDE.md` (Sales section): pagination is now client-side over the loaded list, KPI cards derive from filtered rows, "All events" root cause (placeholder `<div>`), without rewriting the old sentence

**Checkpoint**: all three P1 stories done — MVP.

---

## Phase 6: User Story 3 — Seller pills next to a smaller search box (P2)

**Goal**: seller dropdown → scrollable pill row (All first), beside a half-width search box.
**Independent test**: pills scroll sideways, All is default, toggling filters the grid, multi-select preserved.

- [X] T024 [P] [US3] Create `qa-tests/unit/PosFilterPills.test.js`: `PillFilterRow` renders All + options; All selected when the array is empty; tapping an option toggles it in `modelValue`; tapping All clears; `aria-pressed` reflects state; stale ids not in `options` are dropped; row is a horizontally scrollable container
- [X] T025 [US3] Create `resources/js/components/pos/PillFilterRow.vue` (props `modelValue` array, `options` `[{value,label}]`, `allLabel`; single non-wrapping scroll row, snap, `role="group"`, token classes, truncating long labels with a title)
- [X] T026 [US3] In `resources/js/views/PosView.vue` restructure the search row: search input at roughly half its previous width (min ~160 px) + `PillFilterRow` for sellers (`flex-1 min-w-0`) bound to `selectedArtistIds`; remove the seller `BaseMultiSelect`; clear stale selections when `artists` reload
- [X] T027 [P] [US3] Locale keys for the All pill reuse `master_data.all_artists`; add any new key to both locale files
- [ ] T028 [US3] Tests green; browser check at tablet width (quickstart step 3): no overlap, scroll works by touch/trackpad, scan Enter still adds an exact SKU

---

## Phase 7: User Story 4 — Category pills below the search box (P2)

**Goal**: category dropdown → pill row on its own row below search. Depends on T025 (`PillFilterRow`).
**Independent test**: category pills combine with seller filter.

- [X] T029 [P] [US4] Extend `qa-tests/unit/PosFilterPills.test.js` with PosView-level tests: category row sits below the search row, All categories default, seller + category combine into the same `listProducts` params (`artist_id[]`, `category_id[]`)
- [X] T030 [US4] In `resources/js/views/PosView.vue` replace the category `BaseMultiSelect` with a second `PillFilterRow` on its own row beneath the search row, bound to `selectedCategoryIds`; remove the now-unused `BaseMultiSelect` import if nothing else uses it
- [ ] T031 [US4] Tests green; browser check (quickstart step 3, category part)

---

## Phase 8: User Story 6 — Number and total on one line (P2)

- [X] T032 [P] [US6] Create `qa-tests/unit/SalesNowrapCells.test.js`: the number and total cell content carries `whitespace-nowrap`
- [X] T033 [US6] In `resources/js/views/SalesView.vue` wrap the `cell-order_number` button and `cell-total_amount` content in `whitespace-nowrap` (same approach as feature 053; do NOT change `formatIDR`)
- [ ] T034 [US6] Browser check at laptop and narrow widths (quickstart step 6)

---

## Phase 9: User Story 7 — Change a sale's payment method (P2)

**Goal**: authorised users correct a payment's method/channel without touching amounts.
**Independent test**: cash → QRIS on a sale in an open shift; shown everywhere; logged; refusals behave.

### Tests (write first)

- [X] T035 [P] [US7] Create `tests/Feature/PaymentMethodChangeTest.php` covering every rule in research R8: cash→non-cash happy path (channel required, `verification=pending`, activity log row, amount/total/`paid_amount`/status unchanged); non-cash→cash (needs actor's open shift else 409; verified; session attributed to actor's shift); non-cash→other non-cash resets a verified payment to pending; same method/channel → 422; wrong channel type or missing channel → 422; voided order → 409; cash in a closed shift → 409 `closed_shift`; cash with `change_amount > 0` → 409 `change_given`; recorder allowed, other cashier 403, owner/admin allowed; payload `can_change_method` correct per user; DEMO/LIVE isolation; shift `expected_cash` of an open shift reflects the move

### Implementation

- [X] T036 [US7] Create `app/Http/Requests/ChangePaymentMethodRequest.php` (method in cash/qr_ewallet/bank_transfer; `channel_id` required for non-cash, must be null for cash)
- [X] T037 [US7] Add `PaymentService::changeMethod()` in `app/Services/PaymentService.php` implementing R8 in one transaction with row lock (reuse `lock()`, `assertOpen()`, `receivingSessionId()`, `syncCache()`, `reload()`), writing `payment_method_changed` via `ActivityLogger` with before/after; throw `ValidationException` with `->status(409)` for business conflicts
- [X] T038 [US7] Add `OrderController::changePaymentMethod()` (guard `Payment::mayManageConfirmation()` → 403, map `ValidationException` status) and route `PATCH /orders/{order}/payments/{payment}/method` in `routes/api.php`
- [X] T039 [US7] Add `can_change_method` to each payment entry in `app/Http/Resources/OrderResource.php` (server-computed from `mayManageConfirmation` + order not voided + the closed-shift/change rules so the action is hidden when it can never succeed; eager-load what it needs on every endpoint that returns an order — the `relationLoaded` trap)
- [X] T040 [P] [US7] Add `changePaymentMethod` to `resources/js/api/orders.js`
- [X] T041 [US7] Create `resources/js/components/sales/ChangePaymentMethodModal.vue` (method choice, channel picker via the existing `ChannelPicker`, clear message about "will need verification again", errors via the central handler) and wire a "Change method" action into `resources/js/components/sales/TransactionItemsModal.vue` / `PaymentHistoryList` shown only when `can_change_method`; refresh the list on `changed`
- [X] T042 [P] [US7] Locale keys (both files) incl. the closed-shift and change-given messages; server messages in `lang/en/orders_payments.php` and `lang/id/orders_payments.php`
- [X] T043 [US7] Update `docs/openapi-pos-mvp.yaml` (route, request, 409 codes, `can_change_method`); add a dated exception note to the "Payment ledger" section of `CLAUDE.md` (method/channel correctable, amount/date still not)
- [ ] T044 [US7] Tests green incl. `PaymentLedgerTest`/`PaymentVerificationTest`; browser check (quickstart step 7), including the cashier-without-permission view

---

## Phase 10: User Story 8 — Transaction reference (note + image) with table link (P2)

**Goal**: attach, view, edit and remove a reference; list rows show a link when one exists.
**Independent test**: add note+image → row shows link → modal shows both; guessed image URL refused for unauthorised users.

### Tests (write first)

- [X] T045 [P] [US8] Create `tests/Feature/OrderReferenceTest.php`: create with note only / image only / both; reject pdf, >5 MB, fake-extension file, note >1000 chars, empty result (use DELETE); replace image deletes the old file after commit; DELETE removes both and the file; permissions (owner/admin and the sale's cashier can edit; another cashier 403 on write, 200 on read; user without `sales` menu 403/404 on image); image served from the private disk with `Cache-Control: private, no-store`; activity log rows written in the same transaction (rollback leaves none); money/stock/reports unchanged; voided sale may be annotated; DEMO/LIVE isolation
- [X] T046 [P] [US8] Extend `tests/Feature/SalesTransactionsTest.php`: `has_reference` true/false per row, no extra query per row (query count after warm-up)
- [ ] T047 [P] [US8] Extend `tests/Feature/DemoClone/` parity test (or the existing clone test) so a cloned order's `reference_image_path` is a COPY (different path, file exists) and `CloneRegistryDriftTest` passes

### Implementation

- [X] T048 [US8] Create migration `database/migrations/2026_11_03_000001_add_reference_to_orders_table.php` (columns per data-model.md; nullable; FK `reference_updated_by` `nullOnDelete`; reversible)
- [X] T049 [US8] Add the five fields to `Order` (`$fillable`, `referenceEditor()` relation, `hasReference()` helper) in `app/Models/Order.php`
- [X] T050 [US8] Create `app/Http/Requests/UpsertOrderReferenceRequest.php` (note ≤ 1000, image `mimes:jpeg,png,webp` max 5120 KB, `remove_image` boolean) and `app/Services/OrderReferenceService.php` (save/delete in one transaction + `ActivityLogger`; random UUID filename on `local` disk under `order-references/`; second MIME check from file contents; invariant mime↔path; delete replaced/removed file after commit)
- [X] T051 [US8] Add `OrderController` actions `showReference`, `referenceImage`, `saveReference`, `deleteReference` and routes in `routes/api.php` (static/extra segments registered with the other `/orders/{order}/…` routes; `GET /orders/{order}/reference/image` returns the file with `Cache-Control: private, no-store`); gate per research R9
- [X] T052 [US8] In `app/Services/SalesTransactionsService.php::orderRow()` add `has_reference`; in `app/Http/Resources/OrderResource.php` add the `reference` block (eager-load `referenceEditor` wherever orders are returned)
- [X] T053 [US8] In `app/Services/DemoClone/CloneRegistry.php` add `'files' => ['reference_image_path' => 'local']` to the `orders` entry (merge with existing keys, keep FK order)
- [X] T054 [P] [US8] Add `getReference`, `saveReference` (multipart), `deleteReference` and an authenticated-image helper (blob URL via the axios client, since the image needs the bearer token) to `resources/js/api/orders.js`
- [X] T055 [US8] Create `resources/js/components/sales/OrderReferenceModal.vue` (view note + image with `ImageLightbox`, edit mode for `can_edit`, file type/size checked client-side as a courtesy only) and add the reference link/icon column cell in `resources/js/views/SalesView.vue` (shown only when `has_reference`); add an "Add reference" action in `TransactionItemsModal.vue` for users who can edit
- [X] T056 [P] [US8] Locale keys (both files) and server messages in `lang/en|id/orders_payments.php`
- [X] T057 [US8] Update `docs/openapi-pos-mvp.yaml` (4 routes, `reference` block, `has_reference`); `qa-tests/unit/SalesReferenceAndMethod.test.js` (modal shows note/image, link only when `has_reference`, edit controls hidden when `can_edit` false)
- [ ] T058 [US8] Run `php artisan migrate` on the dev DB, tests green, browser check (quickstart step 8), including an unauthorised image request

---

## Phase 11: User Story 10 — Sales by product report with best-seller mark (P2)

**Goal**: per-product units/sales, best sellers marked, searchable, drill-down to transactions.
**Independent test**: known sales across products → figures match Sales page, top 3 marked, search narrows, drill-down lists the sales.

### Tests (write first)

- [X] T059 [P] [US10] Create `tests/Feature/SalesByProductReportTest.php`: per-product `units`/`sales`/`transaction_count` match underlying data; voided orders and pre-orders excluded; same-named products of different sellers stay separate; soft-deleted product/variant still listed; variants breakdown sums to the product total; best seller = top 3 by units with ties at the cutoff all marked, units ≥ 1; `q` filters rows and totals but NOT rank/marks (rank is global in scope); `%`/`_` in `q` match literally; drill-down returns only that product's lines per order and paginates; event/date filters; DEMO/LIVE isolation (explicit `data_mode`); 403 for a user without `reports`; no cost/margin keys anywhere in the payload; query count constant as products grow
- [X] T060 [P] [US10] Create `qa-tests/unit/ReportsNewTabs.test.js` (product part): tab renders rows, best-seller badge from `is_best_seller`, rule text shown, search debounced, empty state, row click opens the transactions modal

### Implementation

- [X] T061 [US10] Create `app/Services/SalesByProductReport.php` (one aggregate query + one variant-breakdown query, explicit `data_mode`, `LikeEscape` from T003, rank/marks computed in SQL or PHP over the full scope) and add `ReportController::salesByProduct()` and `salesByProductTransactions()` with the `canAccessMenu('reports')` gate used by sibling methods
- [X] T062 [US10] Register `GET /reports/sales-by-product` and `/reports/sales-by-product/{product}/transactions` in `routes/api.php` BEFORE `/reports/{report}/export`
- [X] T063 [P] [US10] Fill `salesByProduct` / `salesByProductTransactions` in `resources/js/api/reports.js`
- [X] T064 [US10] Create `resources/js/components/report/SalesByProductTab.vue` and `resources/js/components/report/ReportTransactionsModal.vue` (shared with US11); add the tab to `resources/js/views/ReportsView.vue` (NOT in `EVENT_SCOPED_TABS`; event optional like Pre-order); sale links open `TransactionItemsModal`
- [X] T065 [P] [US10] Locale keys (both files) incl. the best-seller rule sentence; OpenAPI entries in `docs/openapi-pos-mvp.yaml`
- [ ] T066 [US10] Tests green; browser check as `owner` (quickstart step 10) and confirm a cashier cannot reach the tab/endpoint

---

## Phase 12: User Story 11 — Sales by payment method report (P2)

**Goal**: totals per method/channel with a drill-down transaction list.
**Independent test**: payments across cash and two channels → counts/amounts equal the Sales page; split payments counted per method; list matches the count.

### Tests (write first)

- [X] T067 [P] [US11] Create `tests/Feature/SalesByPaymentMethodReportTest.php`: per-method/channel `amount` and `transaction_count`; cash net of change (equals Sales page `cash_amount`); rejected payments and voided orders excluded; split-payment sale counted in each method with its own portion while `totals.transaction_count` counts distinct sales; verified vs unverified amounts; grand total equals Σ(`cash_amount + noncash_amount`) of `SalesTransactionsService` for the same scope; a payment corrected via US7 appears only under its new method; drill-down list matches the row count and amounts; event/date filters; DEMO/LIVE isolation; 403 without `reports`
- [X] T068 [P] [US11] Extend `qa-tests/unit/ReportsNewTabs.test.js` (payment part): rows, totals, verified/unverified columns, drill-down modal, empty state

### Implementation

- [X] T069 [US11] Create `app/Services/SalesByPaymentMethodReport.php` (aggregate by `(method, channel_id)`, per-order cash net of change as `GREATEST(SUM(cash) − change_amount, 0)`, explicit `data_mode`, dated by `orders.created_at`) and `ReportController::salesByPaymentMethod()` / `salesByPaymentMethodTransactions()`; register both routes before `/reports/{report}/export`
- [X] T070 [P] [US11] Fill `salesByPaymentMethod` / `salesByPaymentMethodTransactions` in `resources/js/api/reports.js`
- [X] T071 [US11] Create `resources/js/components/report/SalesByPaymentMethodTab.vue` (reuse `ReportTransactionsModal.vue` from T064) and add the tab in `ReportsView.vue`
- [X] T072 [P] [US11] Locale keys (both files); OpenAPI entries
- [ ] T073 [US11] Tests green; browser check (quickstart step 11), including the corrected-method sale

---

## Phase 13: User Story 12 — Cashier session list with details (P2)

**Goal**: paginated session history with an opening/closing detail and the session's transactions.
**Independent test**: owner sees all, cashier only own; closed shift figures equal what `close` stored; open shift shows expected so far.

### Tests (write first)

- [X] T074 [P] [US12] Create `tests/Feature/CashierSessionListTest.php`: pagination envelope; filters (`user_id`, `event_id`, `status`, dates); non-owner/admin forced to own sessions even when passing another `user_id`; detail 403 for another cashier's session, 200 for owner/admin; closed shift returns the STORED `expected_cash/closing_cash/cash_difference`; open shift expected cash equals the formula used by `close()` (assert both come from the same method by closing the session and comparing); transactions include sales made in the shift and cash payments received in it with the right `kind`; voided sales marked; `/sessions/current` still resolves (route order); DEMO/LIVE isolation; query count constant per page
- [X] T075 [P] [US12] Create `qa-tests/unit/SessionsHistory.test.js`: list renders, filter changes reload, pagination, row opens detail, transaction click opens the sale detail, open vs closed presentation

### Implementation

- [X] T076 [US12] In `app/Http/Controllers/Api/CashierSessionController.php` extract the expected-cash computation now inline in `close()` into one private/shared method (e.g. `CashierSessionQuery::expectedCash(CashierSession)` in `app/Services/CashierSessionQuery.php`) and make `close()` use it with unchanged results (existing session tests must stay green)
- [X] T077 [US12] Implement `CashierSessionQuery` list/detail/transactions and add `index`, `show`, `transactions` to `CashierSessionController` (object-level rule identical to `summary()`); routes `GET /sessions`, `/sessions/{session}`, `/sessions/{session}/transactions` registered after `/sessions/current`
- [X] T078 [P] [US12] Fill `listSessions`, `getSession`, `sessionTransactions` in `resources/js/api/sessions.js`
- [X] T079 [US12] Create `resources/js/views/SessionsHistoryView.vue` (table + filters via `usePaginatedList` + `TablePagination`; detail in `BaseDrawer`), add route `sessions-history` (`menuKey: 'session'`) in `resources/js/router/index.js` and a sidebar entry under the existing session item in `resources/js/components/layout/AppSidebar.vue`
- [X] T080 [P] [US12] Locale keys (both files); OpenAPI entries
- [ ] T081 [US12] Tests green incl. existing `CashierSession`/shift tests; browser check as `owner` and `kasir01` (quickstart step 12)

---

## Phase 14: User Story 9 — Download all receipts (P3)

**Goal**: one action downloads a zip with a PDF receipt per filtered sale.
**Independent test**: filter to ~10 sales → zip has exactly 10 receipts; > 200 refused with a clear message.

### Tests (write first)

- [X] T082 [P] [US9] Create `tests/Feature/BulkReceiptsTest.php`: returns the same payload as `GET /orders/{id}/receipt` for each id; > 200 ids → 422; unknown or other-mode ids land in `missing`; voided orders returned with their status; no N+1 (query count constant for 5 vs 50 ids after warm-up); unauthenticated → 401; no additional customer fields beyond the single receipt
- [X] T083 [P] [US9] Create `qa-tests/unit/BulkReceipts.test.js`: button disabled with no rows, limit message above 200, progress counter, per-receipt failure reported not dropped, uses the filtered rows of all pages; `ReceiptDocument` renders identically to the modal body (snapshot of key lines)

### Implementation

- [X] T084 [US9] Extract the payload assembly from `OrderController::receipt()` into one shared builder (private method or `app/Services/OrderReceiptBuilder.php`) used by `receipt()` and the new `bulkReceipts()` (eager-load once); add `POST /orders/bulk-receipts` registered BEFORE `/orders/{order}`; existing receipt tests must stay green
- [X] T085 [US9] Extract the receipt template from `resources/js/components/receipt/ReceiptModal.vue` into `resources/js/components/receipt/ReceiptDocument.vue` (prop `receipt`; keep the hard-coded Indonesian labels per the existing FR-009 note) and make the modal use it
- [X] T086 [US9] Add `bulkReceipts` to `resources/js/api/orders.js` and a `useBulkReceipts` flow in `SalesView.vue` modelled on `PreordersView::mountBulkDocument` (dynamic-import `jszip`/`jspdf`, images swapped by `src` via `utils/pdfCapture.js`, sequential render with progress, one zip `struk-<event|tanggal>.zip` of `struk-<order_number>.pdf`, failures listed at the end)
- [X] T087 [US9] Add the "Download all receipts" button next to Export in `SalesView.vue` (counts the filtered rows, disabled when none, explains the 200 cap)
- [X] T088 [P] [US9] Locale keys (both files); OpenAPI entry
- [ ] T089 [US9] Tests green; browser check with a real 10-sale download and open two PDFs (quickstart step 9); confirm `jszip`/`jspdf` remain separate lazy chunks (inspect `npm run build` output, Constitution V)

---

## Phase 15: Polish & cross-cutting

- [ ] T090 [P] Run the full backend suite with the safe overrides and the full Vitest suite; attribute any failure to this feature or to the T001/T002 baseline
- [X] T091 [P] Verify `docs/openapi-pos-mvp.yaml` is valid and contains every route in contracts/api-changes.md (grep each path)
- [ ] T092 [P] Verify `CloneRegistryDriftTest` and the live→demo parity test with the new `orders` columns; confirm no new `HasDataMode` table was introduced
- [X] T093 Grep other callers of `BaseSelect` that pass an "all …" `placeholder` on an optional filter without an empty option (same dead-row bug as research R5) and list them in the PR description; do not change them in this feature unless they are on these screens **[Done (report only). Same dead-"All"-row bug, NOT fixed here: `EventsView.vue` status filter (no empty option); `SalesView.vue` payment-status, customer-type and settlement-status filters (only "Reset filters" clears them). `UsersView`, `StockView`, `VariantHistoryModal` already pass an empty option.]**
- [ ] T094 Final real-browser walkthrough of every quickstart step at tablet and laptop widths as `owner` and `kasir01`, console clean; record results in `specs/054-pos-layout-sales-reports/quickstart.md`
- [X] T095 Update `CLAUDE.md` with the lasting rules learned (payment method correctable under conditions, reference storage and permissions, report definitions, bulk receipt cap) in the existing section style; confirm no secrets or customer PII were added to fixtures, logs or docs

---

## Dependencies & execution order

- **Phase 1 → 2** first; Phase 2 is small and blocks only US10 (T003) and the API skeleton.
- **P1 stories (US1, US2, US5)** are mutually independent and can run in parallel after Phase 2; ship them first as the MVP.
- **US3 → US4**: US4 reuses `PillFilterRow` from T025. Both edit `PosView.vue`, so sequence them (and US1/US2, which also touch `PosView.vue`) or merge carefully — tasks T008, T013, T026, T030 edit the same file.
- **US7** is independent; **US11** does not require it, but its "corrected method" test (T067) needs US7's endpoint — run T067's correction case after US7 or skip that case until then.
- **US8** and **US7** both touch `OrderResource.php`, `OrderController.php`, `orders.js`, `TransactionItemsModal.vue`: sequence them.
- **US10 → US11** share `ReportTransactionsModal.vue` (T064) and the `ReportsView.vue` tab list.
- **US9** needs `ReceiptDocument.vue` (T085) and is last (P3).
- Within a story: tests first (must fail) → service/model → controller/route → frontend → locale/OpenAPI → browser check.

### Parallel opportunities

- Phase 1: T001 ‖ T002. Phase 2: T003 ‖ T004.
- After Phase 2: US1, US2 and US5 test-writing tasks (T005, T006, T011, T015–T017) are all [P].
- Backend report services (T061, T069) and the session query (T076–T077) touch disjoint files and can proceed in parallel with frontend pill work (T025–T030).
- Locale/OpenAPI tasks marked [P] can batch at the end of each story (keep locale edits in separate commits per story to avoid JSON merge conflicts).

## Implementation strategy

1. **MVP**: US1 + US2 + US5 (T001–T023). Verify in the browser, then stop and review.
2. **Layout increment**: US3, US4, US6.
3. **Payments increment**: US7 then US8 (one migration).
4. **Insight increment**: US10, US11, US12.
5. **Last**: US9 receipts, then Phase 15.

Each phase leaves the app working and every story independently demonstrable. Commit per story (Indonesian commit messages per project convention); open PRs only when asked.
