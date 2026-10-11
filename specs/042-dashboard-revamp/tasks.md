**Classification**: INTERNAL

---
description: "Task list for 042-dashboard-revamp"
---

# Tasks: Dashboard Revamp

**Input**: `specs/042-dashboard-revamp/` — plan.md, spec.md, research.md (D1–D15), data-model.md, contracts/, quickstart.md, constitution-amendment.md

**Tests**: INCLUDED. The constitution (Principle II) makes tests and a real-browser check mandatory; each story lists its tests first, and they must fail before the implementation exists.

**Decisions already made by the owner (2026-10-11)**: amendment level MAJOR 2.0.0 (O1); backfill in `Asia/Jakarta` (O2); "net sales" = keep the Sales page definition (O3, one-word answer read as "match the Sales page", flagged in research); O5 default kept (403 for outlet-limited users on non-splittable reports).

## Format: `- [ ] T### [P?] [US#?] Description with file path`

- **[P]** different files, no dependency on an incomplete task. **[US#]** user story label (story phases only).
- Paths are relative to the repository root. Backend tests run in the container with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array`; never without the overrides.

## Stages

- **Stage A** = Phases 1–6: no constitution gate. Ships Stories 1–4 and the "Per event" section.
- **Stage B** = Phases 7–9: **blocked until T001 is done**. Ships Stories 5–6.

---

## Phase 1: Setup and baseline

- [ ] T001 **GATE (needs the product owner; blocks Phases 7–9 only)** Ratify `specs/042-dashboard-revamp/constitution-amendment.md` as version 2.0.0: apply it to `.specify/memory/constitution.md` with the Sync Impact Report and `Last Amended`; add dated notes (not rewritten history) to `CLAUDE.md` (project description and environment-constraints list), `docs/PRD-POS-Event-Multivendor.md` (§1, §9.2, §10.2) and `docs/RUNBOOK.md` (shared-server note); tick the checklist at the end of the amendment file
- [X] T002 Record the baseline in `specs/042-dashboard-revamp/baseline.md`: full backend run per file (expect 1402 passed from 041), `npm test` (expect 1204 passed + 2 skipped), `npm run build` chunk sizes (main 374 kB), and the measured latency of `/reports/sales` on the dev stack
- [X] T003 [P] Write characterisation tests that pin the CURRENT `pos_unit_count`, `pos_gross_sales`, `pos_net_sales` and order count of `ReportController::sales()` — completed only, voided and open excluded, item discounts, pre-orders present (shared totals include them, `pos_*` do not), two dates, DEMO vs LIVE — in `tests/Feature/SalesTotalsCharacterizationTest.php`; they must pass on the untouched code

---

## Phase 2: Foundational — business day and one figures implementation (Stage A, blocks all stories)

**Goal**: orders, payments and shifts carry a stored business date; the Sales page and the Dashboard read figures from one class.

- [X] T004 [P] Migration `database/migrations/2026_11_03_000001_add_business_day_columns.php`: `orders.completed_at` (timestamp null), `orders.business_date` (date null) with index `(business_date, status)`, `payments.business_date`, `cashier_sessions.business_date`, index `payments(verification, method)`; guarded by `Schema::hasColumn`, reversible, re-runnable after a partial failure
- [X] T005 [P] Migration `database/migrations/2026_11_03_000002_add_unit_price_to_kitchen_ticket_items.php`: `unit_price decimal(14,2) null` (no backfill, per data-model)
- [X] T006 [P] Add settings keys `store_timezone` (default `Asia/Jakarta`), `business_day_cutoff` (`00:00`), `cash_difference_tolerance` (`5000.00`), `device_offline_after_minutes` (`5`): validation in `app/Http/Requests/UpdateSettingsRequest.php` (IANA list, `HH:MM`, money ≥ 0, int 1–1440), defaults served when the row is missing, and `store_timezone`/`business_day_cutoff` exposed through `GET /settings/features` in `app/Http/Controllers/Api/SettingController.php`; test `tests/Feature/BusinessDaySettingsTest.php`
- [X] T007 Implement `app/Support/BusinessDay.php` — `dateFor(instant)`, `bounds(date)` (UTC start/end honouring cut-off), `today()`, `startOfToday()`; reads the settings keys (outlet fields come in Phase 7); `config('app.timezone')` stays `UTC`; tests in `tests/Feature/BusinessDayTest.php` (cut-off 04:00, 23:59 and 00:00 boundaries, a named zone, DST-free and DST zone, bounds are half-open)
- [X] T008 Implement the dry-run command `app/Console/Commands/BusinessDateDryRun.php` (`php artisan app:business-date-dry-run`): lists orders whose business date differs from their UTC date and orders whose `created_at` and `updated_at` differ by more than 12 h (research D3), with counts per date; read-only; test `tests/Feature/BusinessDateDryRunTest.php`
- [X] T009 Backfill migration `database/migrations/2026_11_03_000003_backfill_business_dates.php` (chunked, idempotent, re-runnable): `orders.completed_at` = `created_at` for retail, `updated_at` for completed dine-in/takeaway; `orders.business_date` from `completed_at ?? created_at` in the store timezone; `payments.business_date` from `paid_at`; `cashier_sessions.business_date` from `opened_at`; test on populated fixtures including a forced mid-run failure and a re-run in `tests/Feature/BusinessDateBackfillTest.php`
- [X] T010 Stamp `completed_at` / `business_date` on every path that creates or completes an order, and `business_date` on payments and shifts: `app/Services/OrderService.php` (checkout), `app/Services/TabService.php` (open stamps the open date; split/merge/move keep consistency), `app/Services/TabSettlementService.php` (settle and split re-stamp at completion), `app/Services/PaymentRecorder.php` (the single payment writer), the shift-open code in `app/Http/Controllers/Api/CashierSessionController.php`; none of the three fields may be mass-assignable from a request; tests `tests/Feature/BusinessDayStampingTest.php` (one test per path, including a tab opened 23:30 and settled 00:20, and a request body carrying `business_date`/`completed_at`/`outlet_id` that is ignored)
- [X] T011 [P] Stamp `kitchen_ticket_items.unit_price` when a ticket is sent in `app/Services/KitchenService.php` (add to `$fillable` in `app/Models/KitchenTicketItem.php`); tests in `tests/Feature/KitchenItemPriceSnapshotTest.php` (price survives a later variant price change; cancelled item keeps it)
- [X] T012 [P] Add the set-based paid-amount and payment-state SQL fragment next to `for()` in `app/Support/PaymentSummary.php` (rejected excluded, change deducted, derived `unpaid|pending|partial|paid`); parity test against `PaymentSummary::for()` over every combination in `tests/Feature/PaymentSummarySqlParityTest.php`
- [X] T013 Extract the `pos_*` aggregation of `ReportController::sales()` into `app/Services/SalesFigures.php` (net, gross, units, completed-order count, all-status order count; grouped by business date or by hour in the store timezone; explicit `data_mode`); make `ReportController::sales()` call it; **T003 must still pass unchanged**; add `tests/Feature/SalesFiguresTest.php`
- [X] T014 Switch the date basis from `DATE(created_at)` to `business_date` in `ReportController::sales()`, `app/Services/SalesTransactionsService.php`, `profit()`, `artistProfit()` and the seller-transaction drill-down; update only those assertions in the existing tests that the dry-run report (T008) shows should move, and say which in the commit; add `tests/Feature/ReportsBusinessDateTest.php`
- [X] T015 [P] Settings UI for the four keys (timezone select, cut-off time, tolerance, offline threshold) in `resources/js/views/settings/BusinessDaySection.vue`, mounted in `resources/js/views/SettingsView.vue`; `resources/js/stores/settings.js` exposes timezone and cut-off; locale keys `id.json`/`en.json`; test `qa-tests/component/BusinessDaySection.test.js`
- [X] T016 [P] Dated notes for the date-basis change in `docs/PRD-POS-Event-Multivendor.md` and `CLAUDE.md` (report dates now follow the business date in the store timezone); `docs/openapi-pos-mvp.yaml` documents the four settings keys and the `business_date` basis

**Checkpoint**: dry-run reviewed by the owner, backfill applied on the dev DB, Sales page numbers explained row by row, T003 green.

---

## Phase 3: User Story 1 — Today against yesterday at a glance (P1) 🎯 MVP

**Goal**: header, period switch, four KPI cards with change chips and trend lines, "Per event" section retained.

**Independent Test**: seed two days of orders, open the Dashboard, compare each card with the Sales page for the same dates, switch period.

### Tests first

- [X] T017 [P] [US1] `tests/Feature/DashboardSummaryTest.php`: values equal `SalesFigures`; same-time-yesterday comparison with a different elapsed time; `7d`/`30d` previous-period basis; empty day (zeros, `change_pct` null); zero denominator; voided excluded from sales and average; order count counts every status; DEMO/LIVE isolation; `403` without the `dashboard` menu; `422` bad period; cache key varies by mode and period
- [X] T018 [P] [US1] `tests/Feature/LowStockHistoryTest.php`: current low-stock count equals `/stock/low`; value "yesterday at this time" and a 7-day series rebuilt from `stock_movements.stock_after`; variants without threshold ignored; inactive ignored
- [X] T019 [P] [US1] Component/unit tests: `qa-tests/component/TrendLine.test.js` (accessible label, dashed comparison, empty series), `qa-tests/component/ChangeChip.test.js` (up/down, good/bad colour inverted for low stock, null → "no comparison", text not colour-only), `qa-tests/unit/useDashboardPeriod.test.js` (reads/writes `?period=`, ignores invalid), `qa-tests/unit/useAutoRefresh.test.js` (pauses when hidden, cleans up)

### Implementation

- [X] T020 [US1] `app/Services/LowStockHistory.php` (ledger replay, one query + PHP) and `app/Services/DashboardService.php` (summary: four KPIs, comparison, series, 30 s cache keyed by mode + period + money visibility, `computed_at`)
- [X] T021 [US1] `app/Http/Controllers/Api/DashboardController.php` (`summary` only), `app/Http/Requests/DashboardQueryRequest.php`, route `GET /dashboard/summary` in `routes/api.php` behind `canAccessMenu('dashboard')`; OpenAPI path in `docs/openapi-pos-mvp.yaml`
- [X] T022 [P] [US1] `resources/js/api/dashboard.js`, `resources/js/composables/useDashboardPeriod.js`, `resources/js/composables/useAutoRefresh.js`
- [X] T023 [P] [US1] `resources/js/components/page/TrendLine.vue` and `ChangeChip.vue` (tokens only, no hex; inline SVG, no chart library)
- [X] T024 [US1] `resources/js/components/dashboard/DashboardKpis.vue` on `KpiStrip`: four cells with unit note, value, chip, trend, `—`-free zero values, own loading/error/empty; "organisation-wide" note on the low-stock cell when an outlet is selected (Phase 8)
- [X] T025 [US1] Move the current panels unchanged into `resources/js/components/dashboard/PerEventSection.vue` (lazy-loaded, keeps its own `chart.js` imports, gates, loading and error states; heading "Per event"), then rewrite `resources/js/views/DashboardView.vue` as header (business date, "Diperbarui HH.MM", period control, Export placeholder) + KPI region + `PerEventSection`; update `qa-tests/component/DashboardPageKit.test.js` and add `qa-tests/component/DashboardKpis.test.js`, `qa-tests/component/PerEventSection.test.js` (everything the old Dashboard showed is still reachable)
- [X] T026 [P] [US1] Locale keys for the new Dashboard strings in `resources/js/locales/id.json` and `en.json`; `qa-tests/unit/localeKeys.test.js` must pass
- [X] T027 [US1] Browser check of Story 1 in both languages (empty install and seeded day, each period, one failing region simulated, console clean, keyboard); record in `specs/042-dashboard-revamp/evidence-stage-a.md`

**Checkpoint**: MVP — the new header and KPI cards live, old content intact below.

---

## Phase 4: User Story 2 — Know what needs a decision (P1)

**Independent Test**: create one of each condition, check count/amount/link, remove it, check the all-clear state.

### Tests first

- [X] T028 [P] [US2] `tests/Feature/DashboardAttentionTest.php`: payments awaiting verification (non-cash pending only, voided order excluded, cash excluded, POS/tab only); void after fire (count, `qty × unit_price`, `amount_unknown_count` for old rows, share of gross); cash difference (only closed today, only beyond tolerance, tolerance in meta); low stock; every kind always present with `severity: ok` when clear; top `count` = non-ok items; `link: null` without the destination menu; DEMO/LIVE; `403`
- [X] T029 [P] [US2] `qa-tests/component/AttentionPanel.test.js` and `AttentionItem.test.js`: all-clear text, count chip, link vs plain text (no disabled button), severity styling not colour-only, scope line, per-region error with Retry
- [X] T030 [P] [US2] Destination tests (SC-003): for each kind, the item's count equals the row count of the page its link opens — `tests/Feature/DashboardAttentionDestinationsTest.php` (API side) and `qa-tests/component/AttentionDestinations.test.js` (filters read from the URL)

### Implementation

- [X] T031 [US2] `app/Services/AttentionService.php` (definitions of research D6, reusing existing definitions: the `/stock/low` predicate extracted to one place, the existing verification states, session `cash_difference`; 30 s cache like the summary) and `GET /dashboard/attention` in `DashboardController` + route + OpenAPI
- [X] T032 [US2] Make each destination list exactly what was counted: confirm or add the `pstate=pending` filter on the Sales page (`resources/js/composables/useSalesFilters.js`), a low-stock filter `?low=1` in `resources/js/views/StockView.vue`, `?action=item_cancelled&date=` in `resources/js/views/ActivityLogView.vue` (+ `app/Http/Controllers/Api/ActivityLogController.php` if the server lacks them), and a shift deep link `?id=` on the session screen; tests for each added filter
- [X] T033 [P] [US2] `resources/js/components/page/AttentionItem.vue` and `AttentionPanel.vue` (icons from the existing icon set, tokens only)
- [X] T034 [US2] Mount the panel in `DashboardView.vue` under the KPI region with its own state and the 30 s refresh; i18n keys for titles/meta/all-clear lines (id + en)
- [ ] T035 [US2] (PARTIAL: panel, all-clear state, role links and API conditions verified; a browser walk-through with LIVE conditions is still open) Browser check of Story 2 (create each condition, click through, clear it) appended to `evidence-stage-a.md`

---

## Phase 5: User Story 3 — Follow the latest orders (P2)

**Independent Test**: orders in every status; tab counts equal rows; combine search and filter; open a row; compare the CSV with the screen.

### Tests first

- [X] T036 [P] [US3] `tests/Feature/DashboardOrdersTest.php`: each order in exactly one tab (voided → open → partial → paid); `counts` computed with all filters except `tab`; filters (type, method, pstat, dates) and search (order number, table, item); pagination and sort; `hidden_tabs` is `["bill","closed"]`; no contact fields; `paid_amount` = tendered − change; DEMO/LIVE; `403`; `422`; row count equals the "Semua" count (SC-005); **no N+1** (query-count assertion)
- [X] T037 [P] [US3] `tests/Feature/DashboardOrdersExportTest.php`: CSV rows equal the filtered screen rows over the full range, no contact data, correct headers and encoding
- [X] T038 [P] [US3] `qa-tests/component/DashboardOrdersTable.test.js`: tabs with counts, hidden tabs not rendered, search/filter menus, removable chips, "Reset filter", URL round-trip, row click opens the detail, empty and error states, table refresh keeps filters and an open detail

### Implementation

- [X] T039 [US3] `app/Services/DashboardOrdersQuery.php` (tab predicate, counts, filters, search, rows with the set-based payment state from T012, items summary built without N+1) and routes `GET /dashboard/orders`, `GET /dashboard/orders/export` (streamed CSV) in `DashboardController`; OpenAPI paths
- [X] T040 [US3] `resources/js/components/dashboard/DashboardOrdersTable.vue` on `TabBar`, `FilterBar`, `DataTable`; row opens `TransactionItemsModal`; filters/tab/page in the URL via `useQueryState`; "Real-time" marker bound to the refresh timer; "Ekspor CSV"
- [X] T041 [US3] Mount in `DashboardView.vue`; refresh through `useAutoRefresh` without resetting filters; i18n keys (id + en)
- [X] T042 [US3] Browser check of Story 3 (all tabs, every filter, reload restores the view, open a row, export and open the file, create an order while the page is open) appended to `evidence-stage-a.md`

---

## Phase 6: User Story 4 — Export the dashboard (P3) and Stage A verification

### Tests first

- [X] T043 [P] [US4] `tests/Feature/DashboardExportTest.php`: file for each period contains `computed_at`, range, comparison basis, the four KPIs with comparison and trend points, and the attention items; numbers equal `/dashboard/summary` and `/dashboard/attention`; `403`

### Implementation

- [X] T044 [US4] `DashboardController::export` + route `GET /dashboard/export` + OpenAPI; wire the "Ekspor" button in the header (download, error toast on failure) with `qa-tests/component/DashboardExport.test.js`

### Stage A verification

- [X] T045 Roles pass (`owner`, `admin`, `kasir01`, `inventory`): each sees only the regions and links its menu keys allow; a role without the `dashboard` menu gets `403` on all five routes; record in `evidence-stage-a.md`
- [ ] T046 (PARTIAL: measured under extreme machine load, summary today 2.2 s / attention 1.7 s after optimisation; unloaded-machine figures still open) Performance pass with 1,000 orders in the day (quickstart step 7): three requests + first paint ≤ 3 s, period switch ≤ 2 s; if the ledger replay dominates, implement the documented snapshot fallback as a new task before closing
- [ ] T047 (PARTIAL: backend 1496 passed; frontend 1341 passed after re-running 21 load-induced timeouts; bundle +13.3 kB is OVER the +10 kB budget — owner call) Run the full backend suite per file and `npm test`; `npm run build` and confirm main chunk ≤ 374 kB + 10 kB; confirm 7 other pages are unaffected by the date-basis change (console clean); write the Stage A summary and any figure that moved day into `evidence-stage-a.md`
- [X] T048 Update `CLAUDE.md` (a short "Dashboard" paragraph: `BusinessDay`, `SalesFigures` single source, attention definitions, `computed_at`, what the web POS cannot report) and tick completed tasks here

**Checkpoint**: Stage A shippable on its own. Stages below start only when T001 is done.

---

## Phase 7: Outlet foundation (Stage B — **blocked by T001**; blocks Stories 5 and 6)

**Goal**: outlets, scope, stamping and selector, with no visible change on a single-outlet install.

- [ ] T049 Migration `database/migrations/2026_11_04_000001_create_outlets_and_user_outlets.php` (re-runnable): `outlets`, `user_outlets`; create the default outlet from the Stage A settings and **move** `store_timezone`, `business_day_cutoff`, `cash_difference_tolerance` onto it; add `offline_cash_limit` default 3000000.00
- [ ] T050 Migration `database/migrations/2026_11_04_000002_add_outlet_id_to_operational_tables.php`: add nullable `outlet_id` to `orders`, `payments`, `cashier_sessions`, `dining_tables`, `stations`, `kitchen_tickets`; backfill to the default outlet; then NOT NULL, FK, indexes including `orders(outlet_id, business_date, status)`; each step re-runnable; rehearsal on a populated dump
- [ ] T051 [P] Tests first for scope and stamping: `tests/Feature/OutletScopeTest.php` (Eloquent reads limited to permitted outlets, forbidden outlet → 403, `X-Outlet-Id` honoured), `tests/Feature/OutletStampingTest.php` (shift takes the selected/single outlet; order, payment, ticket copy it; a body `outlet_id` is ignored; ambiguous outlet → 422), `tests/Feature/OutletScopeInventoryTest.php` (enumerates the scoped tables and fails if a raw query on them in `ReportController`, `SalesFigures`, `SalesTransactionsService`, `DashboardService`, `AttentionService`, `DashboardOrdersQuery` lacks `OutletFilter`)
- [ ] T052 `app/Models/Outlet.php`, `app/Models/Concerns/BelongsToOutlet.php`, `OutletScope.php`, `app/Support/OutletContext.php` (permitted set ∩ `X-Outlet-Id`), middleware reading the header, `app/Support/OutletFilter.php`; apply the trait to Order, Payment, CashierSession, DiningTable, Station, KitchenTicket
- [ ] T053 Stamp `outlet_id`: shift open in `CashierSessionController`, `OrderService`, `TabService`, `TabSettlementService` (split/merge/move), `PaymentRecorder`, `KitchenService`, dining table/station creation; update `BusinessDay` to read the outlet's timezone and cut-off (settings stay as the fallback); `tests/Feature/BusinessDayOutletTest.php`
- [ ] T054 Apply `OutletFilter` to every raw order/payment/session query in `ReportController` (sales, profit, artist-profit), `SalesFigures`, `SalesTransactionsService`; make recap / Cost & Profit / Seller Cost / per-seller endpoints return `403` for a user restricted to fewer than all outlets (O5 default); `SettlementService` stays whole-event; tests `tests/Feature/OutletReportsGuardTest.php`
- [ ] T055 [P] `GET /me/scope` (`app/Http/Controllers/Api/MeScopeController.php`), outlet CRUD (`OutletController`, `StoreOutletRequest`, activate/deactivate with last-outlet `409`, code immutable after use, activity log in the same transaction) and `outlet_ids` on user create/update (`UserController`, user request); tests `tests/Feature/OutletCrudTest.php`, `UserOutletAssignmentTest.php`; routes + OpenAPI
- [ ] T056 [P] Frontend: `resources/js/api/outlets.js`, `resources/js/stores/outlet.js` (permitted outlets, selection, sends `X-Outlet-Id` through `resources/js/api/client.js`), wire `OutletSelector.vue` into `TopBar.vue` (hidden at one outlet, "Semua outlet" when allowed); tests `qa-tests/component/OutletSelector.test.js` (extend), `qa-tests/unit/outletStore.test.js`
- [ ] T057 [P] Settings → Outlets screen `resources/js/views/settings/OutletsSection.vue` (list, create, edit timezone/cut-off/limit/tolerance, activate/deactivate) routed under settings in `resources/js/router/index.js`; user form multi-select of outlets in `resources/js/views/UsersView.vue`; tests; i18n

**Checkpoint**: on a single-outlet install nothing new is visible and every figure is unchanged (SC-010); apply on a populated dump first.

---

## Phase 8: User Story 5 — Outlets and one business day for every figure (P1, foundation)

**Independent Test**: two outlets with sales (including one after midnight and one before cut-off); each outlet's figures, combined figures, and a limited user cannot see or request the other.

### Tests first

- [ ] T058 [P] [US5] `tests/Feature/OutletIsolationMatrixTest.php`: outlets × roles × endpoints (Dashboard summary/attention/orders/exports, Sales page, orders, shifts, tables, kitchen, reports) — zero rows or counts from another outlet; a forged `X-Outlet-Id`, a body `outlet_id` and another outlet's order id are refused (SC-009)
- [ ] T059 [P] [US5] `tests/Feature/DashboardOutletTest.php`: `outlet_id` filter and "all permitted" sums; attention items name their outlet when combined; low stock labelled `scope: organisation`; business date after midnight but before cut-off follows the previous date on the Dashboard and the Sales page alike

### Implementation

- [ ] T060 [US5] Add the outlet parameter and `OutletFilter` to `DashboardService`, `AttentionService`, `DashboardOrdersQuery` and the exports; per-outlet meta ("WJM 1 (3), WJM 3 (1)") in attention items when several outlets are combined; `403` for a forbidden outlet
- [ ] T061 [US5] Dashboard scope text and refetch on outlet change (`DashboardView.vue`, scope line "Semua outlet · per HH.MM …"), row outlet column only when more than one outlet; i18n; tests `qa-tests/component/DashboardOutletScope.test.js`
- [ ] T062 [US5] Browser check with two outlets × four roles, selector hidden at one outlet, "Semua outlet" and each outlet; appended to `specs/042-dashboard-revamp/evidence-stage-b.md`

---

## Phase 9: User Story 6 — See which devices are behind (P2)

**Independent Test**: register two devices, let one stop reporting with unsynced figures; check the notice, the action item and its tooltip; both clear when it reports.

### Tests first

- [ ] T063 [P] [US6] `tests/Feature/DeviceActivationTest.php` (code shown once, hashed at rest, 15-minute expiry, single use, same message for unknown/used/expired, throttle, a new code invalidates the previous), `tests/Feature/DeviceHeartbeatTest.php` (`last_seen_at` uses server time, retired/unknown device → 401, offline derived from the threshold), `tests/Feature/DeviceTokenSeparationTest.php` (a user token cannot heartbeat; a device token cannot call any other route), `tests/Feature/DeviceAdvisoryFiguresTest.php` (changing `pending_events`/`offline_cash` changes no sales, payment, stock or cash total — amendment B)
- [ ] T064 [P] [US6] `tests/Feature/DashboardDevicesTest.php`: `devices.behind_count` and items in the summary, `device_offline` attention item with exposure % against the outlet limit (critical above 100%), all-clear "Semua online", retired devices ignored, no devices registered = healthy; `qa-tests/component/DeviceNotice.test.js`, `qa-tests/component/DevicesSection.test.js`, `qa-tests/unit/useDeviceHeartbeat.test.js`

### Implementation

- [ ] T065 [US6] Migration `database/migrations/2026_11_04_000003_create_devices_and_activation_codes.php` (data-model); `app/Models/Device.php` (Sanctum `HasApiTokens`, outlet-scoped, not data-mode scoped), `app/Models/DeviceActivationCode.php`
- [ ] T066 [US6] `app/Services/DeviceService.php` (register, issue code, activate → device token with ability `device:report`, heartbeat, retire with token revocation, offline derivation from `device_offline_after_minutes`), `DeviceController`, `StoreDeviceRequest`, routes (`/devices`, `/devices/activate` public + throttle, `/devices/heartbeat` with `abilities:device:report`); make `app/Http/Middleware/SetLocaleFromUser.php` tolerate a non-user principal; activity-log rows in the same transaction; OpenAPI
- [ ] T067 [US6] Feed the Dashboard: `devices` block in `DashboardService`, `device_offline` kind in `AttentionService` (meta device, outlet, minutes, events, cash, limit, exposure %); link to Settings → Devices only for users with the `settings` menu
- [ ] T068 [P] [US6] `resources/js/views/settings/DevicesSection.vue` (registry with online state, register → code shown once, new code, retire with reason) and `resources/js/api/devices.js`, routed under settings
- [ ] T069 [US6] `resources/js/composables/useDeviceHeartbeat.js`: "Daftarkan perangkat ini" in Devices stores the device token in local storage, heartbeats every 60 s while the POS screens are open with `pending_events: 0`, `offline_cash: "0.00"` (the web POS has no offline queue); clears on retire/401
- [ ] T070 [US6] `resources/js/components/dashboard/DeviceNotice.vue` in the header ("N perangkat belum sinkron", hover/focus details, figures may rise after sync) and the `device_offline` item in `AttentionPanel`; i18n
- [ ] T071 [US6] Browser check of Story 6 (register, activate, heartbeat, stop it, see the notice within 2 minutes, resume, see it clear within 1 minute; set offline cash above the limit; retire); append to `evidence-stage-b.md`

---

## Phase 10: Polish and cross-cutting

- [ ] T072 [P] Documentation: `CLAUDE.md` section for outlets/scope/devices/advisory figures and the web POS limitation; dated notes in the PRD and RUNBOOK for the shared-server shape; confirm every new route is in `docs/openapi-pos-mvp.yaml`
- [ ] T073 [P] Accessibility pass on the Dashboard (keyboard order, focus rings, period tablist, table tabs, tooltips via `BaseTooltip`, change direction not by colour alone) in both languages; fix findings
- [ ] T074 Final verification (quickstart Stage B): full backend suite per file, `npm test`, build and bundle budget (SC-007), console sweep of the Dashboard and 7 other pages, token-separation and isolation matrices green; write `specs/042-dashboard-revamp/evidence-stage-b.md`
- [ ] T075 Mark every completed task `[X]`, list anything not done with the reason, and prepare the commit split (backend / frontend / specs) for the owner

---

## Dependencies and execution order

- **Phase 1** first; **T001 is independent** (owner) and gates only Phases 7–9.
- **Phase 2 blocks everything in Stage A.** Inside it: T004–T006 in parallel → T007 → T008 → T009 (after owner reviews the dry-run) → T010, T011 → T012 → T013 (needs T003, T007) → T014 → T015/T016.
- **Stage A stories**: US1 (Phase 3) → US2 (Phase 4) → US3 (Phase 5) → US4 (Phase 6). US2 and US3 depend only on Phase 2 and the page shell from T025; US2 and US3 can be built in parallel after T025.
- **Stage B** (after T001 and Stage A): Phase 7 → Phase 8 (US5) and Phase 9 (US6). US6 needs Phase 7 (outlets, scope) but not US5; US5 and US6 can proceed in parallel after Phase 7. Phase 10 last.
- Within a story: tests first (they must fail) → services → controller/route → frontend → browser check.
- Tasks touching the same file are sequential: `DashboardController.php` (T021, T031, T039, T044), `DashboardView.vue` (T025, T034, T041, T061, T070), `ReportController.php` (T013, T014, T054), `AttentionService.php` (T031, T060, T067).

## Parallel examples

```text
Phase 2:  T004, T005, T006            (three independent migrations/settings)
US1:      T017, T018, T019            (backend and frontend tests, different files)
          T022, T023                  (API client/composables and kit components)
US2:      T028, T029, T030            (tests) → then T031 ‖ T033
US3:      T036, T037, T038            (tests)
Stage B:  T051, T055, T056, T057      (after T049–T050)
          T058, T059 ‖ T063, T064     (US5 and US6 tests once Phase 7 is done)
```

## Implementation strategy

1. **MVP = Phases 1–3**: baseline, business-day foundation, then the KPI header. Stop and show it.
2. Add Story 2 (attention), Story 3 (orders table), Story 4 (export); Stage A is shippable.
3. When the owner has ratified T001: outlet foundation, then Story 5 and Story 6 in either order.
4. Never apply the backfill migration (T009) before the owner has read the dry-run report (T008); never start Phase 7 before T001.
5. Each checkpoint ends with evidence recorded in the matching `evidence-*.md`; nothing is called done on the shape of the diff alone.

## Summary

| Phase | Tasks | Count |
|---|---|---|
| 1 Setup | T001–T003 | 3 |
| 2 Foundational (A) | T004–T016 | 13 |
| 3 US1 | T017–T027 | 11 |
| 4 US2 | T028–T035 | 8 |
| 5 US3 | T036–T042 | 7 |
| 6 US4 + Stage A verification | T043–T048 | 6 |
| 7 Outlet foundation (B) | T049–T057 | 9 |
| 8 US5 | T058–T062 | 5 |
| 9 US6 | T063–T071 | 9 |
| 10 Polish | T072–T075 | 4 |
| **Total** | | **75** |
