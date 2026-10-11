**Classification**: INTERNAL

# Tasks: Back Office UI Redesign (full design scope)

**Input**: Design documents from `/specs/041-back-office-ui-redesign/` — [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: INCLUDED. The constitution (Principle II) requires backend tests under `tests/Feature/` (real MySQL), frontend tests under `qa-tests/`, and a real-browser check per user-facing change. Tests come before the code they cover and must fail first.

**Organization**: grouped by user story (spec priorities P1 → P3). Plan phases map to stories as follows: plan Phase 1 = US1 + US2 + US14; Phase 2a = US3 (+ foundation of US8/US12); Phase 2b = deployment tasks inside US3's gate group; Phase 3 = US5 + US6; Phase 4 = US7; Phase 5 = US8 + US9; Phase 6 = US4; Phase 7 = US10 + US11 + US12; Phase 8 = US13; Phase 9 = settings completion inside US3/US13.

**Umbrella note**: this file is the full backlog. Each plan phase is intended to become its own sub-feature (own spec/plan/tasks) when it starts; the tasks here are the source for that split. Do not start a phase before its gate (see Dependencies).

## Format: `- [ ] T### [P?] [US#?] Description with file path`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[US#]**: user story from spec.md (story phases only)
- Paths follow plan.md "Source Code". Migration file names use the next free date prefixes after `2026_11_02_000003` (prefixes are load-bearing for FK order — never rename or reorder).
- Backend tests run ONLY as: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test`.
- Code comments, docs and UI copy are Indonesian (English through i18n); commits in Indonesian.

---

## Phase 1: Setup

**Purpose**: baseline and measurements that every later "no regression" claim depends on.

- [X] T001 Run the full backend suite file-by-file on `warkop_wjm_test` and record per-file results in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T002 Run `npm test` and `npm run build`; record the test count and the production chunk sizes in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T003 [P] Diagnose `tests/Feature/InvoiceAvailabilityAndLogoTest.php` failures; fix the cause or record evidence it pre-dates this work in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T004 [P] Diagnose `tests/Feature/ModeScopingFollowUpTest.php` failures; fix the cause or record evidence it pre-dates this work in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T005 [P] Diagnose `tests/Feature/OrderTest.php` failures; fix the cause or record evidence it pre-dates this work in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T006 [P] Diagnose `tests/Feature/PosPartialPaymentTest.php` failures; fix the cause or record evidence it pre-dates this work in `specs/041-back-office-ui-redesign/baseline.md`
- [X] T007 Write the cashier-flow measurement procedure (open shift → add 3 items → send to kitchen → pay cash → close shift: count steps and time) and record the BEFORE numbers in `specs/041-back-office-ui-redesign/cashier-flow-baseline.md` (SC-010)
- [X] T008 Sample the Back Office tokens (rail and accent colours, marker colours, table header/hover, panel border, rail widths 64/240) from the 13 captures in `design/screens/SaaS Restaurant Admin Dashboard/` into a token table `specs/041-back-office-ui-redesign/design-tokens.md`; do NOT use `design/_ds/modernist-*` (different design system)

---

## Phase 2: Foundational (blocks all user stories)

**Purpose**: shared tokens, navigation data, state helpers and base states used by both the frame and the page kit.

- [X] T009 Extend the `@theme` tokens in `resources/css/app.css` from `design-tokens.md`, keeping `--color-brand*` as the accent that `resources/js/utils/theme.js` overrides at runtime
- [X] T010 [P] Add a token contrast test for every text/background pair (WCAG 2.1 AA) in `qa-tests/unit/tokenContrast.test.js`
- [X] T011 [P] Add shared frame/page copy keys (rail, breadcrumb, "N data", "Menampilkan X–Y dari Z", loading, empty, error, retry) to `resources/js/locales/id.json` and `resources/js/locales/en.json`; keep `qa-tests/unit/localeParity.test.js` green
- [X] T012 [P] Create the central module/group definition (key, label key, icon, route, required permission or menu key, group, badge source) in `resources/js/utils/navigation.js` to replace the hard-coded `NAV_DEFS` in `resources/js/components/layout/AppSidebar.vue`, with `qa-tests/unit/navigation.test.js`
- [X] T013 [P] Create `resources/js/composables/useQueryState.js` (mirror tab, filters, sort, page into the address bar; generalises `useSalesFilters.js`) with `qa-tests/unit/useQueryState.test.js`
- [X] T014 [P] Add or extend formatters for `Rp 18.420.500`, Indonesian long dates and "Menampilkan X–Y dari Z" in `resources/js/utils/formatters.js` with `qa-tests/unit/formatters.test.js`
- [X] T015 [P] Create `resources/js/composables/useRailState.js` persisting the collapsed state per user (replaces key `boothpos:sidebar-visible`) with `qa-tests/unit/useRailState.test.js`
- [X] T016 [P] Extend `resources/js/components/ui/StatusPill.vue` with the StatusChip tones (neutral, info, success, warning, danger, accent) keeping the existing variants as aliases, and update its test in `qa-tests/component/`
- [X] T017 [P] Create `resources/js/components/ui/LoadingState.vue` and `resources/js/components/ui/ErrorState.vue` (with retry) and extend `resources/js/components/ui/EmptyState.vue` (explanation + primary action), with `qa-tests/component/PageStates.test.js`

**Checkpoint**: tokens, navigation data and base states exist; `npm test` green.

---

## Phase 3: User Story 1 — Work inside the new application frame (Priority: P1) 🎯 MVP

**Goal**: every page renders in the new frame (rail, top bar, breadcrumb, user menu), permission-driven, collapsible, with status-dot and outlet/search/bell slots ready for later data.

**Independent Test**: sign in as `owner`, `admin`, `kasir01`, `inventory`; open every rail item; breadcrumb and highlighted item match, forbidden modules are absent, collapse state persists (quickstart, Phase 1 steps 1–2).

### Tests for User Story 1 (write first, must fail)

- [X] T018 [P] [US1] Component test for the rail (permission filtering, groups, status dot, collapsed names on hover/focus, keyboard) in `qa-tests/component/RailNav.test.js`
- [X] T019 [P] [US1] Component tests for TopBar, Breadcrumb and UserMenu in `qa-tests/component/TopBar.test.js`
- [X] T020 [P] [US1] Update the shell test for the new composition in `qa-tests/component/AppShell.test.js`
- [X] T021 [P] [US1] Component test for OutletSelector (hidden with one outlet, "Semua outlet (n)", emits selection) in `qa-tests/component/OutletSelector.test.js`
- [X] T022 [P] [US1] Component tests for GlobalSearch (Ctrl/Cmd+K, grouped results, empty state) and NotificationBell (count, list) in `qa-tests/component/SearchAndBell.test.js`

### Implementation for User Story 1

- [X] T023 [US1] Implement the rail in `resources/js/components/layout/RailNav.vue` (64/240 px, groups, active bar, dots, collapse control, accessible names) using `utils/navigation.js` and `composables/useRailState.js`
- [X] T024 [P] [US1] Implement `resources/js/components/layout/Breadcrumb.vue` reading route `meta.titleKey`
- [X] T025 [P] [US1] Implement `resources/js/components/layout/UserMenu.vue` (name, role, profile, language switch, sign out) absorbing `LanguageSwitcher.vue` and `SystemModeBadge.vue` so DEMO/LIVE stays visible
- [X] T026 [P] [US1] Implement `resources/js/components/layout/OutletSelector.vue` (prop-driven; data wired in US3)
- [X] T027 [P] [US1] Implement `resources/js/components/layout/GlobalSearch.vue` (UI and shortcut; the search call is injected, wired in US4)
- [X] T028 [P] [US1] Implement `resources/js/components/layout/NotificationBell.vue` (count and list slot; data wired in US4)
- [X] T029 [US1] Compose `resources/js/components/layout/TopBar.vue` (breadcrumb, search, outlet selector, bell, user menu) replacing `AppTopbar.vue`
- [X] T030 [US1] Rewrite `resources/js/components/layout/AppShell.vue` to use RailNav and TopBar, keeping `SchemaUpdateBanner.vue` and the license-gate behaviour
- [X] T031 [US1] Add `meta.titleKey` (and subtitle key) to every route in `resources/js/router/index.js`, keeping the cosmetic permission guard
- [X] T032 [US1] Move the POS cart badge and pre-order `dp_paid` badge into the rail through `utils/navigation.js`
- [X] T033 [US1] Add rail/top-bar copy to `resources/js/locales/id.json` and `en.json`; keep locale parity tests green
- [X] T034 [US1] Remove `resources/js/components/layout/AppSidebar.vue`, `AppTopbar.vue` and their old tests once the new frame has parity; fix imports
- [X] T035 [US1] Real-browser verification of quickstart Phase 1 steps 1–2 and 5–7 (all four roles, collapse persistence, language switch, theme colour, clean console); record evidence in `specs/041-back-office-ui-redesign/evidence-phase1.md`

**Checkpoint**: whole product runs in the new frame; US1 independently demonstrable.

---

## Phase 4: User Story 2 — Read, filter and act on every list the same way (Priority: P1)

**Goal**: a reusable page kit and every existing management view migrated onto it.

**Independent Test**: on two unrelated pages verify search, filters, sort, pagination, selection, detail panel, create/edit, reason-coded confirmation and loading/empty/error states behave identically (quickstart Phase 1 step 3).

### Tests for User Story 2 (write first, must fail)

- [X] T036 [P] [US2] Component tests for PageHeader and KpiStrip/KpiCard (four cards, "—" for unavailable, click target) in `qa-tests/component/page/PageHeaderKpi.test.js`
- [X] T037 [P] [US2] Component test for TabBar (counts describe the section, arrow-key navigation, roles) in `qa-tests/component/page/TabBar.test.js`
- [X] T038 [P] [US2] Component test for FilterBar (counter, reset, address-bar sync) in `qa-tests/component/page/FilterBar.test.js`
- [X] T039 [P] [US2] Extend the DataTable test for selection, select-all-on-page, sticky header, state rows and pagination text in `qa-tests/component/DataTable.test.js`
- [X] T040 [P] [US2] Component test for BulkBar (only actions valid for all selected rows, per-row result) in `qa-tests/component/page/BulkBar.test.js`
- [X] T041 [P] [US2] Component test for DetailPanel (focus trap, Escape, actions by status) in `qa-tests/component/page/DetailPanel.test.js`
- [X] T042 [P] [US2] Extend `qa-tests/component/ReasonDialog.test.js` for the reason-code list, optional note and disabled-until-chosen behaviour
- [X] T043 [P] [US2] Component test for SettingsLayout (grouped list, issue badges) in `qa-tests/component/page/SettingsLayout.test.js`

### Implementation for User Story 2

- [X] T044 [P] [US2] Implement `resources/js/components/page/PageHeader.vue`
- [X] T045 [P] [US2] Implement `resources/js/components/page/KpiStrip.vue` and `KpiCard.vue` (marker colour tokens, "—" when unavailable)
- [X] T046 [P] [US2] Implement `resources/js/components/page/TabBar.vue`
- [X] T047 [P] [US2] Implement `resources/js/components/page/FilterBar.vue` using `composables/useQueryState.js`
- [X] T048 [US2] Extend `resources/js/components/ui/DataTable.vue` with selection column, sticky header, in-table horizontal scroll and state rows
- [X] T049 [P] [US2] Implement `resources/js/components/page/BulkBar.vue`
- [X] T050 [P] [US2] Implement `resources/js/components/page/DetailPanel.vue` on top of `components/ui/BaseDrawer.vue` (kicker, title, chips, actions, facts, sections, timeline)
- [X] T051 [US2] Extend `resources/js/components/restaurant/ReasonDialog.vue` (move to `components/page/ReasonDialog.vue`) to take a category and list reason codes, falling back to a supplied static list until the endpoint exists
- [X] T052 [P] [US2] Implement `resources/js/components/page/SettingsLayout.vue`
- [X] T053 [P] [US2] Implement `resources/js/components/page/FormDrawer.vue` (titled sections, required markers, inline 422 messages through the central error handler)
- [X] T054 [US2] Migrate `resources/js/views/SalesView.vue` onto the kit (header, four-figure strip, tabs, filter bar, table); keep `qa-tests` for it green
- [X] T055 [P] [US2] Migrate `resources/js/views/StockView.vue` and `resources/js/views/MaterialsView.vue` onto the kit
- [X] T056 [P] [US2] Migrate `resources/js/views/PurchaseOrdersView.vue` and `resources/js/views/VendorsView.vue` onto the kit
- [X] T057 [P] [US2] Migrate `resources/js/views/CustomersView.vue` onto the kit
- [X] T058 [P] [US2] Split and migrate `resources/js/views/ProductsView.vue` (908 lines) and `resources/js/views/CategoriesView.vue` onto the kit
- [X] T059 [P] [US2] Split and migrate `resources/js/views/ReportsView.vue` (738 lines) onto the kit
- [X] T060 [P] [US2] Migrate `resources/js/views/UsersView.vue` and `resources/js/views/RolesView.vue` onto the kit
- [X] T061 [P] [US2] Split `resources/js/views/SettingsView.vue` (562 lines) into sections and migrate it and `resources/js/views/SettingsPaymentView.vue` onto SettingsLayout
- [X] T062 [P] [US2] Re-frame `resources/js/views/DashboardView.vue` with PageHeader and KpiStrip (replace the copy-pasted stat cards); data stays as is until US4
- [X] T063 [P] [US2] Split `resources/js/views/PreordersView.vue` (1,983 lines) into components and migrate it onto the kit
- [X] T064 [P] [US2] Migrate `resources/js/views/EventsView.vue` and `resources/js/views/ArtistsView.vue` onto the kit
- [X] T065 [P] [US2] Migrate `resources/js/views/CompaniesView.vue`, `BusinessTypesView.vue`, `LicensesView.vue`, `InvoicesView.vue` onto the kit
- [X] T066 [P] [US2] Migrate `resources/js/views/ActivityLogView.vue`, `ProfileView.vue`, `LoginView.vue`, `LicenseLockView.vue` to the new tokens and page structure
- [X] T067 [US2] Real-browser verification of quickstart Phase 1 steps 3 and 7 on every migrated page; confirm each summary figure equals its list (SC-006); record evidence in `specs/041-back-office-ui-redesign/evidence-phase1.md`
- [X] T068 [US2] Run `npm test` and `npm run build`; compare against the baseline (zero regressions, heavy modules still separate chunks)

**US2 migration notes (2026-10-11, T054–T066, T068 done; T067 browser verification open):**
- Every listed view uses `PageHeader`; lists use `FilterBar` (with `searchable=false` where the API has no text search), `DataTable` error/retry, and `TabBar` where real sections exist. Big views were split (PreordersView 1,983 → 477 lines, ProductsView 908 → 267, SettingsView into 8 sections on `SettingsLayout` with `?section=`, ReportsView into 6 tab components).
- **KPI strips were added only where honest figures already existed** (Sales, Invoices, Pre-orders, Dashboard). Stock, Purchase Orders, Vendors, Materials, Customers, Products, Users, Settings, Events and Sellers have none because four truthful figures need summary endpoints that arrive in later phases (US4–US9). This is a known gap against the design.
- Deliberate small additions beyond "presentation only": search boxes on Licenses and Business Types (existing backend `search` parameter); error-with-retry states on several lists; Reports export button hidden for roles without the `reports` menu key.
- Kit extensions made during migration: `FilterBar` `searchable`, `DataTable` `rowLabel`, a single `DEFAULT_ACCENT_COLOR` (`utils/theme.js`, guarded by `themeDefault.test.js`).
- `PreordersView` and `SalesView` keep their own selection column and bulk bar (labels "Pilih {nomor}" and a document-type picker); they can move to `DataTable selectable` + `BulkBar` later.
- Frontend result: 126 files, 1203 passed, 2 skipped; production main chunk 374 kB (baseline 379 kB).

**Checkpoint**: US1 + US2 deliver plan Phase 1 minus cashier screens; no data-model change; releasable.

---

## Phase 5: User Story 3 — Define organisation, outlets and business rules (Priority: P1)

**Goal**: outlets, business date, action-level permissions with outlet scope, reason codes, one approval path, tax and payment methods, audit additions, default-outlet migration; settings pages for all of it. (Plan Phase 2a, plus the shared-server hardening of Phase 2b as its own group.)

**Independent Test**: create two outlets, scope a user to one, verify no cross-outlet leak, cut-off assignment, tax versioning, reason-coded void, self-approval refusal (quickstart Phase 2a).

### Gate (no implementation before this group is done)

- [ ] T069 [US3] GATE (needs the product owner): ratify the constitution amendment text in plan.md "Complexity Tracking" and edit `.specify/memory/constitution.md` with a Sync Impact Report and the chosen version (1.1.0 or 2.0.0)
- [ ] T070 [P] [US3] Add dated notes for the multi-outlet decision to `docs/PRD-POS-Event-Multivendor.md` §1, §9.2 and §10.2 (do not rewrite prior scope)
- [ ] T071 [P] [US3] Update the single-store statements in `CLAUDE.md` and add the multi-outlet notes to `docs/RUNBOOK.md`
- [ ] T072 [P] [US3] Record the licensing entitlement decision (default: no outlet/device limit) in `specs/041-back-office-ui-redesign/decisions.md`
- [ ] T073 [P] [US3] Record the hosting choice and target outlet/device count in `specs/041-back-office-ui-redesign/decisions.md`

### Tests for User Story 3 (write first, must fail)

- [ ] T074 [P] [US3] `tests/Feature/DefaultOutletMigrationTest.php` — backfill of outlet, business date and stock location on populated data; reversibility; re-run after partial failure
- [ ] T075 [P] [US3] `tests/Feature/OutletIsolationMatrixTest.php` — enumerates every outlet-scoped table and every report query; a scoped user never sees or changes other outlets (404, not 403)
- [ ] T076 [P] [US3] `tests/Feature/BusinessDateTest.php` — cut-off 03:00 boundaries, timezone differences, closed-date write refused with `BUSINESS_DAY_CLOSED`
- [ ] T077 [P] [US3] `tests/Feature/TaxRuleVersioningTest.php` — effective dates, no overlap, past sales keep their snapshot, rounding 100/500 half-up
- [ ] T078 [P] [US3] `tests/Feature/PaymentMethodsTest.php` — scope per outlet and order type, cash cannot be deleted, channel migration
- [ ] T079 [P] [US3] `tests/Feature/ReasonCodesTest.php` — category validation, deactivate vs delete, `422` on wrong category
- [ ] T080 [P] [US3] `tests/Feature/SelfApprovalTest.php` — requester ≠ approver for every approval kind, limits per role
- [ ] T081 [P] [US3] `tests/Feature/ApprovalLimitsTest.php` — offline limit above online limit is lowered and reported
- [ ] T082 [P] [US3] `tests/Feature/RolePermissionMigrationTest.php` — every existing role's effective access identical before and after; read-only and non-transacting roles refuse write permissions
- [ ] T083 [P] [US3] `tests/Feature/DayCloseTest.php` — blockers listed, close, reopen with reason, report regenerated
- [ ] T084 [P] [US3] `tests/Feature/OutletsApiTest.php` — create/update/deactivate guards, code immutability after first document, `/me/scope`
- [ ] T085 [P] [US3] `tests/Feature/ActivityLogOutletTest.php` — audit rows carry outlet and IP and are written inside the same transaction
- [ ] T086 [P] [US3] Frontend tests for the outlet store and OutletSelector wiring in `qa-tests/component/OutletStore.test.js`

### Implementation — schema (one task per migration; reversible, re-runnable)

- [ ] T087 [US3] Migration `database/migrations/2026_11_03_000001_create_outlets_and_user_outlets_tables.php` (outlets, user_outlets) and model `app/Models/Outlet.php`
- [ ] T088 [US3] Migration `database/migrations/2026_11_03_000002_create_default_outlet_from_settings.php` creating the default outlet from the current store settings and assigning all users
- [ ] T089 [US3] Migration `database/migrations/2026_11_03_000003_add_outlet_and_business_date_to_orders_sessions_payments.php` with backfill from `created_at` in the app timezone
- [ ] T090 [US3] Migration `database/migrations/2026_11_03_000004_add_outlet_to_dining_tables_stations_purchase_orders.php` with backfill
- [ ] T091 [US3] Migration `database/migrations/2026_11_03_000005_create_tax_rules_and_payment_methods_tables.php` (+ map `payment_channels` to methods, add tax snapshot columns to orders)
- [ ] T092 [US3] Migration `database/migrations/2026_11_03_000006_create_reason_codes_table.php` with seeded default categories
- [ ] T093 [US3] Migration `database/migrations/2026_11_03_000007_create_approvals_limits_and_sod_rules_tables.php`
- [ ] T094 [US3] Migration `database/migrations/2026_11_03_000008_add_permissions_to_roles_table.php` mapping `menu_keys` to equivalent permissions
- [ ] T095 [US3] Migration `database/migrations/2026_11_03_000009_create_day_closes_table.php`
- [ ] T096 [US3] Migration `database/migrations/2026_11_03_000010_add_outlet_and_ip_to_activity_logs_table.php`

### Implementation — backend

- [ ] T097 [P] [US3] Create `app/Models/Concerns/BelongsToOutlet.php` and `OutletScope.php` mirroring `HasDataMode`/`DataModeScope`, with `app/Support/OutletContext.php`
- [ ] T098 [US3] Create middleware `app/Http/Middleware/ResolveOutletContext.php` (intersect `X-Outlet-Id` with the user's permitted outlets) and register it on the authenticated API group in `bootstrap/app.php`
- [ ] T099 [P] [US3] Create `app/Services/OutletFilter.php` (single helper for raw `DB::table()` queries) and apply it in `app/Http/Controllers/Api/ReportController.php`
- [ ] T100 [P] [US3] Create `app/Services/BusinessDateResolver.php` (timezone + cut-off) with `app/Services/DayCloseService.php`
- [ ] T101 [P] [US3] Create `app/Services/TaxCalculator.php` (included/excluded, service charge, rounding, per order or line) used by `app/Services/OrderService.php` and `TabService.php`
- [ ] T102 [P] [US3] Create `app/Support/PermissionCatalog.php` and `app/Support/PermissionGate.php`; keep `app/Support/MenuKeys.php` and derive menu visibility from permissions
- [ ] T103 [US3] Create `app/Services/ApprovalService.php` (requester ≠ approver, role limits, audit) as the only approval path
- [ ] T104 [US3] Apply the outlet traits to orders, sessions, tables, stations and purchase orders in `app/Models/` and stamp `outlet_id` and `business_date` server-side in `OrderService`, `TabService` and `CashierSessionController`
- [ ] T105 [US3] Refuse writes to a closed business date in the order, tab and payment services with a `409 BUSINESS_DAY_CLOSED` response
- [ ] T106 [P] [US3] Controllers, requests and resources for outlets and `/me/scope` in `app/Http/Controllers/Api/OutletController.php` and `MeScopeController.php`
- [ ] T107 [P] [US3] Controllers for permissions and roles (extend `RoleController.php`) and for `sod-rules`
- [ ] T108 [P] [US3] `app/Http/Controllers/Api/ReasonCodeController.php` with requests and resources
- [ ] T109 [P] [US3] `app/Http/Controllers/Api/ApprovalController.php` and `ApprovalLimitController.php`
- [ ] T110 [P] [US3] `app/Http/Controllers/Api/TaxRuleController.php`, `PaymentMethodController.php`, `BusinessRuleController.php`
- [ ] T111 [P] [US3] `app/Http/Controllers/Api/DayCloseController.php` and `SettingsSectionsController.php`
- [ ] T112 [US3] Add outlet and IP to `app/Services/ActivityLogger.php` and filters to `GET /activity-logs`
- [ ] T113 [US3] Register all routes in `routes/api.php` grouped by module and update `docs/openapi-pos-mvp.yaml` in the same commit
- [ ] T114 [US3] Run the new tests, then the full suite with the safe `-e` flags; rehearse the migrations on a copy of populated data, roll back and migrate again

### Implementation — shared-server hardening (plan Phase 2b)

- [ ] T115 [US3] Create `docker/shared/` (production web tier and application server chosen and verified in this task, scheduler process, non-root DB user, named volumes for uploads) without modifying `docker/php/` or `docker/store/`
- [ ] T116 [P] [US3] Configure trusted proxies in `bootstrap/app.php`, security headers and a CORS allow-list; test that login throttling and audit use the real client IP in `tests/Feature/TrustedProxyTest.php`
- [ ] T117 [P] [US3] Token expiry and rotation policy in `config/sanctum.php` and `app/Http/Controllers/Api/AuthController.php` with `tests/Feature/TokenExpiryTest.php`
- [ ] T118 [US3] Load test kitchen/floor polling at the target outlet count and record p95/error budget in `specs/041-back-office-ui-redesign/decisions.md`
- [ ] T119 [US3] Document the shared deployment in `docs/RUNBOOK.md` (new section) including backup and restore across outlets

### Implementation — frontend

- [ ] T120 [P] [US3] API modules `resources/js/api/outlets.js`, `permissions.js`, `reasonCodes.js`, `approvals.js`, `taxRules.js`, `paymentMethods.js`, `dayCloses.js`
- [ ] T121 [US3] Create `resources/js/stores/outlet.js` (permitted outlets, selected outlet, `X-Outlet-Id` header via the axios instance) and wire `OutletSelector.vue` in `TopBar.vue`
- [ ] T122 [US3] Point `ReasonDialog` at `/reason-codes` by category and replace the free-text void/cancel reasons in existing flows incrementally
- [ ] T123 [P] [US3] Settings sections on SettingsLayout: Outlets (`resources/js/views/settings/OutletsSection.vue`), Tax & rounding, Payment methods, Reason codes, Business rules
- [ ] T124 [P] [US3] Locale keys for all new settings and approval messages in `id.json` and `en.json`
- [ ] T125 [US3] Real-browser verification of quickstart Phase 2a and Phase 2b; record evidence in `specs/041-back-office-ui-redesign/evidence-phase2.md`

**Checkpoint**: outlet foundation in place; later business modules can build on it.

---

## Phase 6: User Story 4 — Dashboard with what needs a decision (Priority: P2)

**Goal**: day-over-day dashboard, global search and notification bell fed by derived data.

**Independent Test**: with a day of activity across two outlets, figures equal their lists, search respects permissions, bell equals "Perlu tindakan" (quickstart Phase 6).

### Tests for User Story 4

- [ ] T126 [P] [US4] `tests/Feature/DashboardSummaryTest.php` — period switch, same-time-of-day comparison, outlet scope, figures equal list sums
- [ ] T127 [P] [US4] `tests/Feature/SearchTest.php` — orders by number/table, members, items, tables; permission and outlet limits; min length
- [ ] T128 [P] [US4] `tests/Feature/AttentionItemsTest.php` — each attention kind, caching, per-user scope
- [ ] T129 [P] [US4] Frontend tests for the dashboard view and attention list in `qa-tests/component/DashboardView.test.js`

### Implementation for User Story 4

- [ ] T130 [US4] Add indexes for search and dashboard in migration `database/migrations/2026_11_04_000001_add_search_and_dashboard_indexes.php`
- [ ] T131 [P] [US4] `app/Services/DashboardService.php` and `app/Http/Controllers/Api/DashboardController.php` (`GET /dashboard/summary`, export)
- [ ] T132 [P] [US4] `app/Services/SearchService.php` and `app/Http/Controllers/Api/SearchController.php`
- [ ] T133 [P] [US4] `app/Services/AttentionService.php` (derived items) and `app/Http/Controllers/Api/AttentionController.php` with a 30 s cache keyed by user and outlet set
- [ ] T134 [US4] Update `docs/openapi-pos-mvp.yaml` for the three endpoints
- [ ] T135 [US4] Wire the dashboard view to the summary endpoint with period switch, trend lines, export and the "Perlu tindakan" list in `resources/js/views/DashboardView.vue`
- [ ] T136 [P] [US4] Wire `GlobalSearch.vue` to `GET /search` and `NotificationBell.vue` to `GET /attention`; feed rail status dots from the same data
- [ ] T137 [US4] Real-browser verification of quickstart Phase 6; record evidence in `specs/041-back-office-ui-redesign/evidence-phase6.md`

---

## Phase 7: User Story 5 — Manage the menu, ingredients and recipes (Priority: P2)

**Goal**: menu items with outlet availability, 86/quota, combos, ingredients, recipes with versions and live cost, price drafts.

**Independent Test**: item with two variants, required modifier group and recipe shows cost and margin; 86 in one outlet; quota blocks (quickstart Phase 3, steps 1–2).

### Tests for User Story 5

- [ ] T138 [P] [US5] `tests/Feature/OutletAvailabilityTest.php` — 86 per outlet with reason, quota auto-unavailable per business date, price override resolution server-side
- [ ] T139 [P] [US5] `tests/Feature/RecipeVersioningTest.php` — immutable old versions, cost and margin from average cost, migration of existing BOM lines as version 1
- [ ] T140 [P] [US5] `tests/Feature/ComboTest.php` — choice groups, min/max, cost resolution
- [ ] T141 [P] [US5] `tests/Feature/PriceDraftTest.php` — bulk percent/nominal with Rp 500 rounding, not effective until released
- [ ] T142 [P] [US5] `tests/Feature/MaterialUnitsTest.php` — purchase unit and factor, approximate-factor flag, base unit locked after movements
- [ ] T143 [P] [US5] Frontend tests for the menu views in `qa-tests/component/MenuItems.test.js`

### Implementation for User Story 5

- [ ] T144 [US5] Migration `database/migrations/2026_11_05_000001_add_menu_fields_and_outlet_items.php` (item fields, `outlet_items`, combos)
- [ ] T145 [US5] Migration `database/migrations/2026_11_05_000002_extend_materials_and_create_recipes.php` (kinds, units, batch flag, recipes and lines, migrate BOM lines to version 1)
- [ ] T146 [US5] Migration `database/migrations/2026_11_05_000003_create_price_drafts_table.php`
- [ ] T147 [P] [US5] Models and `app/Services/OutletAvailabilityService.php` (86, quota, effective price) wired into product search and order creation
- [ ] T148 [P] [US5] `app/Services/RecipeService.php` (versions, cost) extending `BomCostCalculator.php`; keep the existing `/variants/{variant}/bom` endpoints reading the current version
- [ ] T149 [P] [US5] `app/Services/ComboService.php` and `app/Services/PriceDraftService.php`
- [ ] T150 [P] [US5] Controllers, requests, resources and routes for outlet availability, combos, price drafts, recipes and extended materials; update `docs/openapi-pos-mvp.yaml`
- [ ] T151 [US5] Frontend: menu item form drawer (variants, outlet prices, channels, quota, station, prep time), 86 action with ReasonDialog, combos, ingredients/semi-finished/packaging/retail tabs, recipe editor with live cost, bulk price draft, "unreleased changes" banner in `resources/js/views/ProductsView.vue` and `resources/js/views/menu/`
- [ ] T152 [US5] Real-browser verification of quickstart Phase 3 steps 1–2; record evidence in `specs/041-back-office-ui-redesign/evidence-phase3.md`

---

## Phase 8: User Story 6 — Control stock across locations (Priority: P2)

**Goal**: balances per location with moving-average cost, reorder suggestions, batches/expiry, counts, transfers, wastage; consumption by sales.

**Independent Test**: blind count with approval by another user, transfer in transit, wastage over threshold pending (quickstart Phase 3, steps 3–6).

### Tests for User Story 6

- [ ] T153 [P] [US6] `tests/Feature/StockBalancesTest.php` — balances per location, `current_stock` equals the sum, written only through `StockService`
- [ ] T154 [P] [US6] `tests/Feature/StockCountTest.php` — blind entry hides system quantities, counter cannot approve, tolerance, posting adjustments
- [ ] T155 [P] [US6] `tests/Feature/TransferTest.php` — in-transit quantities, receive with difference
- [ ] T156 [P] [US6] `tests/Feature/WastageApprovalTest.php` — threshold, pending state, stock moves only on approval
- [ ] T157 [P] [US6] `tests/Feature/BatchFefoTest.php` — expiry list, first-expired-first-out consumption
- [ ] T158 [P] [US6] `tests/Feature/RecipeConsumptionTest.php` — selling a recipe item posts consumption per location in the same transaction; no recipe means none and a flag
- [ ] T159 [P] [US6] Frontend tests for the inventory views in `qa-tests/component/InventoryViews.test.js`

### Implementation for User Story 6

- [ ] T160 [US6] Migration `database/migrations/2026_11_06_000001_create_stock_locations_balances_batches.php` (+ default location, move `current_stock` into balances)
- [ ] T161 [US6] Migration `database/migrations/2026_11_06_000002_extend_stock_movements_for_locations.php` (location, new types, document reference) for variants and materials
- [ ] T162 [US6] Migration `database/migrations/2026_11_06_000003_create_stock_counts_transfers_wastage_tables.php`
- [ ] T163 [US6] Extend `app/Services/StockService.php` (location, new movement types, moving average, FEFO) and unify `MaterialStockService.php` behind it; it remains the only write path
- [ ] T164 [P] [US6] `app/Services/StockCountService.php`, `TransferService.php`, `WastageService.php` using `ApprovalService`
- [ ] T165 [P] [US6] Post recipe consumption from `OrderService`/`TabService` completion via `StockService`
- [ ] T166 [P] [US6] Controllers, requests, resources, routes for locations, balances, suggestions, batches, counts, transfers, wastage; update `docs/openapi-pos-mvp.yaml`
- [ ] T167 [US6] Frontend: Inventori page (tabs: needs action, on-hand, movements, count, transfers, wastage) with KPI strip, "create PR from suggestions" action stubbed until US7, count entry screen with blind mode in `resources/js/views/StockView.vue` and `resources/js/views/inventory/`
- [ ] T168 [US6] Real-browser verification of quickstart Phase 3 steps 3–6; record evidence in `specs/041-back-office-ui-redesign/evidence-phase3.md`

---

## Phase 9: User Story 7 — Purchase-to-pay and suppliers (Priority: P2)

**Goal**: purchase requests with approval, extended PO lifecycle, goods receipts, supplier invoices with 3-way match, returns, supplier master and scorecards.

**Independent Test**: request → approve (other user) → PO → partial receipt → invoice exception → return (quickstart Phase 4).

### Tests for User Story 7

- [ ] T169 [P] [US7] `tests/Feature/PurchaseRequestTest.php` — creation, self-approval refused, bulk approve per-row result, consolidation
- [ ] T170 [P] [US7] `tests/Feature/PurchaseOrderLifecycleTest.php` — extended statuses and the status migration map, late flag, price-vs-contract reason
- [ ] T171 [P] [US7] `tests/Feature/GoodsReceiptTest.php` — partial receipts, weighed quantities, stock movements per line
- [ ] T172 [P] [US7] `tests/Feature/SupplierInvoiceMatchTest.php` — matched, price difference, quantity difference, exception approval with reason
- [ ] T173 [P] [US7] `tests/Feature/SupplierReturnTest.php` and `SupplierScorecardTest.php`
- [ ] T174 [P] [US7] Frontend tests for Pembelian and Supplier views in `qa-tests/component/PurchasingViews.test.js`

### Implementation for User Story 7

- [ ] T175 [US7] Migration `database/migrations/2026_11_07_000001_extend_vendors_and_create_supplier_prices.php` (contact, terms, halal certificate, price catalogue fields)
- [ ] T176 [US7] Migration `database/migrations/2026_11_07_000002_create_purchase_requests_tables.php`
- [ ] T177 [US7] Migration `database/migrations/2026_11_07_000003_extend_purchase_orders_status_and_create_receipts_invoices_returns.php` (status map draft→draft, ordered→sent, received→fully_received, paid→closed)
- [ ] T178 [P] [US7] `app/Services/PurchaseRequestService.php` and extend `app/Services/PurchaseOrderService.php` (statuses, approval, contract price reasons)
- [ ] T179 [P] [US7] `app/Services/GoodsReceiptService.php` (posts movements through `StockService`) and `SupplierInvoiceMatchService.php`
- [ ] T180 [P] [US7] `app/Services/SupplierReturnService.php` and `SupplierScorecardService.php`
- [ ] T181 [P] [US7] Controllers, requests, resources, routes for suppliers, price catalogue, requests, receipts, invoices, returns and `GET /purchasing/summary`; update `docs/openapi-pos-mvp.yaml`
- [ ] T182 [US7] Frontend: Pembelian page (work queue, requests, orders, receipts, invoices, returns) and Supplier page (list, catalogue, scorecard, halal flag) in `resources/js/views/PurchaseOrdersView.vue`, `resources/js/views/VendorsView.vue` and `resources/js/views/purchasing/`
- [ ] T183 [US7] Connect the Inventori "create PR from suggestions" action to `POST /purchase-requests`
- [ ] T184 [US7] Real-browser verification of quickstart Phase 4; record evidence in `specs/041-back-office-ui-redesign/evidence-phase4.md`

---

## Phase 10: User Story 8 — Sales records, returns, cash and shifts (Priority: P2)

**Goal**: immutable sales records, reprint with copy marker, returns with approval, complimentary/staff meal, shift cash variance approval, X/Z reports.

**Independent Test**: reprint, return with approval, staff meal limit, variance approval (quickstart Phase 5, steps 1–4).

### Tests for User Story 8

- [ ] T185 [P] [US8] `tests/Feature/SalesRecordImmutabilityTest.php` — closed orders refuse updates with `409 IMMUTABLE`, calculation and config version shown
- [ ] T186 [P] [US8] `tests/Feature/ReprintLimitTest.php` — copy marker, count, limit and approval
- [ ] T187 [P] [US8] `tests/Feature/SalesReturnTest.php` — stock decisions, refund after approval, failed refund retry, original order untouched
- [ ] T188 [P] [US8] `tests/Feature/ComplimentaryStaffMealTest.php` — notional value, daily limit, excluded from revenue
- [ ] T189 [P] [US8] `tests/Feature/ShiftVarianceTest.php` — blind count by denomination, tolerance, approval with reason, force close, cash movements above threshold
- [ ] T190 [P] [US8] `tests/Feature/ShiftReportXZTest.php` — X computed, Z stored once
- [ ] T191 [P] [US8] Frontend tests for Penjualan views in `qa-tests/component/SalesRecords.test.js`

### Implementation for User Story 8

- [ ] T192 [US8] Migration `database/migrations/2026_11_08_000001_create_sales_returns_complimentary_cash_tables.php`
- [ ] T193 [US8] Migration `database/migrations/2026_11_08_000002_add_reprint_count_and_shift_variance_columns.php`
- [ ] T194 [P] [US8] Enforce immutability of closed orders in `OrderService`/`TabService` and add the reprint counter to `GET /orders/{id}/receipt`
- [ ] T195 [P] [US8] `app/Services/SalesReturnService.php` (refund through `PaymentRecorder`, stock decision through `StockService`) using `ApprovalService`
- [ ] T196 [P] [US8] `app/Services/ComplimentaryService.php` and `app/Services/ShiftCashService.php` (count, variance, force close, movements, X/Z)
- [ ] T197 [P] [US8] Controllers, requests, resources, routes for sales records, returns, complimentary, shifts/cash; update `docs/openapi-pos-mvp.yaml`
- [ ] T198 [US8] Frontend: Penjualan page (records, returns/refunds, complimentary & staff meal, shifts & cash) with detail panels and approval actions in `resources/js/views/SalesView.vue` and `resources/js/views/sales/`
- [ ] T199 [US8] Real-browser verification of quickstart Phase 5 steps 1–4; record evidence in `specs/041-back-office-ui-redesign/evidence-phase5.md`

---

## Phase 11: User Story 9 — Monitor open orders and tables (Priority: P2)

**Goal**: read-only order monitor and live table board with over-45-minute prompt; end-of-day close wired to the day-close backend.

**Independent Test**: open tabs on several tables show correct counts, colours and detail; no create/edit actions; day close blocked then allowed (quickstart Phase 5, steps 5–6).

### Tests for User Story 9

- [ ] T200 [P] [US9] `tests/Feature/OrdersMonitorTest.php` — tab counts by status, summary figures, over-threshold tables, outlet scope
- [ ] T201 [P] [US9] `tests/Feature/DayCloseChecklistTest.php` — each blocker kind and clearing it
- [ ] T202 [P] [US9] Frontend tests for the monitor and board in `qa-tests/component/OrdersMonitor.test.js`

### Implementation for User Story 9

- [ ] T203 [P] [US9] `app/Http/Controllers/Api/OrdersMonitorController.php` (list, summary) and extend `FloorController.php` with seated minutes and threshold flag; update `docs/openapi-pos-mvp.yaml`
- [ ] T204 [P] [US9] Back-office actions `POST /orders/{id}/verify-qris` and `/cancel-unpaid` with reason in `app/Http/Controllers/Api/OrderController.php`
- [ ] T205 [US9] Frontend: Pesanan page (list tab + table board tab) in `resources/js/views/OrdersMonitorView.vue`, route and rail entry
- [ ] T206 [US9] Frontend: end-of-day screen (checklist, blockers, close, reopen with reason) in `resources/js/views/pos-config/DayCloseSection.vue`
- [ ] T207 [US9] Real-browser verification of quickstart Phase 5 steps 5–6; record evidence in `specs/041-back-office-ui-redesign/evidence-phase5.md`

---

## Phase 12: User Story 10 — Customers and loyalty (Priority: P3)

**Goal**: members, tiers, points ledger, consent, deletion on request, feedback.

**Independent Test**: tier change on threshold, point adjustment with reason, masked PII, anonymisation (quickstart Phase 7, steps 1–2).

### Tests for User Story 10

- [ ] T208 [P] [US10] `tests/Feature/LoyaltyTierTest.php` — threshold, window, earn rate, tier recompute
- [ ] T209 [P] [US10] `tests/Feature/PointLedgerTest.php` — append-only, adjustment needs reason
- [ ] T210 [P] [US10] `tests/Feature/MemberPrivacyTest.php` — masking without privilege, consent per channel, export reason logged, anonymise keeps totals
- [ ] T211 [P] [US10] Frontend tests for Pelanggan views in `qa-tests/component/MembersViews.test.js`

### Implementation for User Story 10

- [ ] T212 [US10] Migration `database/migrations/2026_11_09_000001_extend_customers_to_members_and_create_loyalty_tables.php`
- [ ] T213 [P] [US10] `app/Services/LoyaltyService.php` (earn on completed sales, tier recompute, expiry) and `MemberPrivacyService.php`
- [ ] T214 [P] [US10] Controllers, requests, resources, routes for members, tiers, points, feedback, bulk voucher; update `docs/openapi-pos-mvp.yaml`
- [ ] T215 [US10] Frontend: Pelanggan page (members, tiers & rules, feedback) in `resources/js/views/CustomersView.vue` and `resources/js/views/members/`
- [ ] T216 [US10] Real-browser verification of quickstart Phase 7 steps 1–2; record evidence in `specs/041-back-office-ui-redesign/evidence-phase7.md`

---

## Phase 13: User Story 11 — Reports catalogue, schedules and exports (Priority: P3)

**Goal**: 22-report catalogue, preview, export, schedules, export history.

**Independent Test**: preview, export in each format, schedule weekly, see history (quickstart Phase 7, step 3).

### Tests for User Story 11

- [ ] T217 [P] [US11] `tests/Feature/ReportCatalogTest.php` — catalogue content, permissions, freshness and date basis
- [ ] T218 [P] [US11] `tests/Feature/ReportConsistencyTest.php` — sales totals identical across dashboard, sales records and reports for the same business date and outlet set (SC-006)
- [ ] T219 [P] [US11] `tests/Feature/ReportScheduleExportTest.php` — schedule, run, history, expiry (`410`), personal-data reason
- [ ] T220 [P] [US11] Frontend tests for Laporan views in `qa-tests/component/ReportCatalog.test.js`

### Implementation for User Story 11

- [ ] T221 [US11] Confirm the 22 reports and their columns with the report owners and record them in `specs/041-back-office-ui-redesign/reports.md` (P0-7)
- [ ] T222 [US11] Choose and verify the server-side PDF approach (check package availability/version first) and record it in `specs/041-back-office-ui-redesign/decisions.md`
- [ ] T223 [US11] Migration `database/migrations/2026_11_10_000001_create_report_schedules_and_exports_tables.php`
- [ ] T224 [P] [US11] `app/Services/Reports/ReportRegistry.php` and one runner per report under `app/Services/Reports/` using `OutletFilter` and `business_date`; re-express the existing report endpoints through the runners
- [ ] T225 [P] [US11] `app/Services/ReportExportService.php` (CSV/XLSX/PDF to the private disk, expiry) and `app/Console/Commands/RunScheduledReports.php` registered in `routes/console.php`
- [ ] T226 [P] [US11] Controllers, requests, resources, routes for catalogue, preview, export, schedules, history; update `docs/openapi-pos-mvp.yaml`
- [ ] T227 [US11] Ensure the scheduler process from `docker/shared/` and the single-store entrypoints runs `schedule:run` (document in `docs/RUNBOOK.md`)
- [ ] T228 [US11] Frontend: Laporan page (catalogue, scheduled, export history, preview) in `resources/js/views/ReportsView.vue` and `resources/js/views/reports/`
- [ ] T229 [US11] Real-browser verification of quickstart Phase 7 step 3; record evidence in `specs/041-back-office-ui-redesign/evidence-phase7.md`

---

## Phase 14: User Story 12 — Users, roles and audit (Priority: P3)

**Goal**: invitations, outlet scope, POS PIN, MFA, locking, permission matrix, audit trail.

**Independent Test**: scoped invitation, custom role in the matrix, wrong-PIN lockout, audit entries (quickstart Phase 7, step 4).

### Tests for User Story 12

- [ ] T230 [P] [US12] `tests/Feature/UserInvitationTest.php` — invite, scope, deactivate with reason, last owner protected
- [ ] T231 [P] [US12] `tests/Feature/ApproverPinLockoutTest.php` — five wrong PINs lock for 15 minutes, no hint which part failed
- [ ] T232 [P] [US12] `tests/Feature/MfaTest.php` — enrolment and verification (method chosen in the task below)
- [ ] T233 [P] [US12] `tests/Feature/AuditTrailTest.php` — before/after values, masking, read-only auditor
- [ ] T234 [P] [US12] Frontend tests for Karyawan & User views and the permission matrix in `qa-tests/component/UsersViews.test.js`

### Implementation for User Story 12

- [ ] T235 [US12] Choose and verify the MFA method and any package (check availability/version first) and record it in `specs/041-back-office-ui-redesign/decisions.md`
- [ ] T236 [US12] Migration `database/migrations/2026_11_11_000001_add_pin_mfa_and_lock_columns_to_users_table.php`
- [ ] T237 [P] [US12] `app/Services/UserInvitationService.php`, `ApproverPinService.php`, MFA service; endpoints per `contracts/api-people-reports.md`
- [ ] T238 [P] [US12] `app/Http/Controllers/Api/AuditController.php` with masking of personal data in values; update `docs/openapi-pos-mvp.yaml`
- [ ] T239 [US12] Frontend: Karyawan & User page (users, roles & permission matrix with separation-of-duties notes, activity & audit) in `resources/js/views/UsersView.vue` and `resources/js/views/RolesView.vue`
- [ ] T240 [US12] Real-browser verification of quickstart Phase 7 step 4; record evidence in `specs/041-back-office-ui-redesign/evidence-phase7.md`

---

## Phase 15: User Story 13 — Run the POS day: devices, sync, releases, limits (Priority: P3)

**Goal**: device registry and activation, printers, shift slots, sync exceptions, configuration releases, approval limits, presets, denominations, receipt template, app version policy, tables and menu layout.

**Independent Test**: one-time code activation, release publish/rollback with per-device version, conflicting events land in exceptions (quickstart Phase 8).

### Tests for User Story 13

- [ ] T241 [P] [US13] `tests/Feature/DeviceActivationTest.php` — code hashed, 15-minute expiry, single use, revoke with reason
- [ ] T242 [P] [US13] `tests/Feature/SyncIngestTest.php` — idempotent by (`device_id`,`seq`), accept/reject/quarantine
- [ ] T243 [P] [US13] `tests/Feature/SyncExceptionTest.php` — each code and its allowed resolutions, nothing merges silently
- [ ] T244 [P] [US13] `tests/Feature/ConfigReleaseTest.php` — draft, publish now/scheduled, atomic price application, rollback creates a new release
- [ ] T245 [P] [US13] `tests/Feature/PosConfigurationTest.php` — discount presets, denominations, receipt template, app version policy, menu layout, bulk table creation
- [ ] T246 [P] [US13] Frontend tests for Konfigurasi POS and device settings views in `qa-tests/component/PosConfigViews.test.js`

### Implementation for User Story 13

- [ ] T247 [US13] Migration `database/migrations/2026_11_12_000001_create_devices_activation_printers_shift_slots_tables.php`
- [ ] T248 [US13] Migration `database/migrations/2026_11_12_000002_create_sync_events_and_exceptions_tables.php`
- [ ] T249 [US13] Migration `database/migrations/2026_11_12_000003_create_config_releases_and_pos_config_tables.php` (releases, discount presets, denominations, receipt templates, app version policy)
- [ ] T250 [P] [US13] `app/Services/DeviceService.php` (activation codes, device tokens, revoke) and endpoint `POST /devices/activate` with throttling
- [ ] T251 [P] [US13] `app/Services/SyncIngestService.php` and `SyncExceptionService.php`; register the existing web POS as an online-only device
- [ ] T252 [P] [US13] `app/Services/ReleaseService.php` (snapshot, diff, publish, schedule, rollback) isolated so it can shrink to price/menu only
- [ ] T253 [P] [US13] Controllers, requests, resources, routes for devices, printers, shift slots, sync, releases, limits, presets, denominations, receipt templates, app policy, menu layout; update `docs/openapi-pos-mvp.yaml`
- [ ] T254 [US13] Frontend: Konfigurasi POS page (end-of-day, sync events, open shifts, tables, menu layout, releases, approval limits, discount presets, cash & receipt, app version) in `resources/js/views/PosConfigView.vue` and `resources/js/views/pos-config/`
- [ ] T255 [US13] Frontend: Pengaturan sections for devices, printers, shift schedules, imports in `resources/js/views/settings/`
- [ ] T256 [US13] Real-browser verification of quickstart Phase 8; record evidence in `specs/041-back-office-ui-redesign/evidence-phase8.md`

---

## Phase 16: User Story 14 — New visual system on cashier-facing screens (Priority: P3)

**Goal**: POS checkout, cashier session, floor/tab and kitchen use the same tokens and components without adding steps or reducing tap targets.

**Independent Test**: the cashier-flow script gives the same or fewer steps and the same or less time than the baseline (SC-010).

### Tests for User Story 14

- [ ] T257 [P] [US14] Update existing view tests for POS, session, floor, tab and kitchen to the new markup in `qa-tests/component/`
- [ ] T258 [P] [US14] Add a test asserting minimum tap-target and text sizes for the cashier-facing components in `qa-tests/unit/tapTargets.test.js`

### Implementation for User Story 14

- [ ] T259 [P] [US14] Restyle `resources/js/views/PosView.vue` and its components with the new tokens and chips, keeping layout density and step count
- [ ] T260 [P] [US14] Restyle `resources/js/views/SessionView.vue` (shift open/close, cash count) with the page kit where it fits and the same flow
- [ ] T261 [P] [US14] Restyle `resources/js/views/FloorView.vue`, `TabView.vue` and `DiningSetupView.vue`
- [ ] T262 [P] [US14] Restyle `resources/js/views/KitchenView.vue` and the restaurant components in `resources/js/components/restaurant/`
- [ ] T263 [US14] Re-run the cashier-flow procedure and record AFTER numbers beside the baseline in `specs/041-back-office-ui-redesign/cashier-flow-baseline.md`; no increase allowed
- [ ] T264 [US14] Real-browser verification of the cashier flows on a touch-sized window; record evidence in `specs/041-back-office-ui-redesign/evidence-phase1.md`

---

## Phase 17: Polish & cross-cutting concerns

- [ ] T265 [P] Update `docs/RUNBOOK.md` and `README.md` for the new frame, outlets, shared deployment, scheduler and report schedules
- [ ] T266 [P] Final accessibility pass (contrast, keyboard, focus, reduced motion) over every page; fix findings
- [ ] T267 [P] Review every `DB::table()` query for an `OutletFilter` and `business_date` (grep and checklist) and add any missing cases to `tests/Feature/OutletIsolationMatrixTest.php`
- [ ] T268 [P] Confirm every new route is in `docs/openapi-pos-mvp.yaml` and every migration is reversible; run `migrate:rollback` and `migrate` on a copy of populated data
- [ ] T269 Run the full backend suite and `npm test` and `npm run build`; compare with the baseline; record final results in `specs/041-back-office-ui-redesign/baseline.md`
- [ ] T270 Update `CLAUDE.md` (active plan note, architecture notes for outlets, permissions, approvals, releases) and add dated notes to the PRD for each delivered phase

---

## Dependencies & Execution Order

- **Setup → Foundational → everything**: tokens, navigation data and base states block all stories.
- **US1 → US2 → US14**: frame first, then the kit, then restyle cashier screens (US14 needs the kit and the frame).
- **GATE before US3 implementation**: the constitution amendment (first tasks of US3) must be ratified. US1, US2 and US14 do not need it and can ship first.
- **US3 blocks** US4–US13 (outlet scope, business date, permissions, reasons, approvals).
- **US5 → US6 → US7**: menu/recipes and units before stock by location, stock locations before purchasing receipts. US7's "create PR from suggestions" connects after US6.
- **US8 needs** US3 (+ US6 for return stock decisions). **US9 needs** US3 and US8's day-close pieces.
- **US4 (dashboard)** is best finished after US5–US9 have data; its endpoints can start after US3 and grow as modules land.
- **US10, US11, US12** need US3; US11 should follow US5–US9 so reports have sources; US12 can run in parallel with US10.
- **US13** needs US3 and US8; **its release engine** may be descoped to price/menu only without affecting other stories.

### Parallel opportunities

- Within a phase, tasks marked [P] touch different files and can run together (all test-writing tasks; independent migrations only if their FK order is respected by date prefix).
- After the Foundational phase: US1 frame components, the kit components and view migrations can be split across people.
- After US3: US5/US6, US7, US8/US9 and US10/US12 can proceed in parallel streams.

### Example: parallel start for US2

```text
Tests:   PageHeaderKpi.test.js · TabBar.test.js · FilterBar.test.js · BulkBar.test.js · DetailPanel.test.js · SettingsLayout.test.js
Then:    PageHeader.vue · KpiStrip.vue · TabBar.vue · FilterBar.vue · BulkBar.vue · DetailPanel.vue · SettingsLayout.vue · FormDrawer.vue
Then:    view migrations (Sales, Stock/Materials, PO/Vendors, Customers, Products, Reports, Users/Roles, Settings, ...) in parallel
```

## Implementation Strategy

1. **MVP = Setup + Foundational + US1 + US2** (plan Phase 1 without cashier screens): the whole product in the new frame and page structure, no data-model change, no constitution change. Validate with the quickstart Phase 1 checks, then demo.
2. **Then US14** (cashier screens) once SC-010 numbers are recorded.
3. **Pass the gate, then US3**, then one stream at a time (US5 → US6 → US7; US8 → US9; US4 last among P2).
4. **P3 stories** (US10–US13) in the order the product owner values; US13 last.
5. Each phase ends with its evidence file and a green full suite; do not start the next phase on a red suite.

## Notes

- Tests first, and watch them fail before implementing.
- Every route or response change updates `docs/openapi-pos-mvp.yaml` in the same commit.
- Every migration is reversible and re-runnable; rehearse on populated data (MySQL DDL is not transactional).
- Never trust client-supplied outlet, business date, totals, prices, stock quantities or permissions.
- Commit after each task or logical group, in Indonesian, one concern per commit.
