**Classification**: INTERNAL

# Research: Back Office UI Redesign (full design scope)

**Feature**: 041-back-office-ui-redesign | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

Method: three read-only code surveys (frontend shell and components, backend coverage against the design's modules, deployment/licensing/auth/ops) plus a read of the design bundle (13 captures and 12 page definitions). Labels: **FACT** = read from code or the design; **ASSUMPTION** = inferred, to be verified in the task that depends on it; **UNVERIFIED** = names a package or version not yet checked (not stated as real).

## 0. Where the system stands against the design

| Design module | Today | Gap |
|---|---|---|
| Frame (rail, breadcrumb, search, bell, outlet selector) | text sidebar with groups, topbar with title, language toggle, mode badge (FACT) | icon rail, breadcrumb, global search, bell, outlet selector, user menu all absent |
| Shared page kit (KPI strip, count tabs, filter bar, bulk bar, detail panel, reason dialog) | `DataTable`, `BaseDrawer`, `StatusPill`, `ConfirmDialog`, `usePaginatedList`; 4-card KPI is copy-pasted in `DashboardView`; reason dialog takes free text (FACT) | needs a reusable kit; no row selection, no tabs-with-counts, no bulk bar |
| Dashboard | no dashboard endpoint; view reuses `/reports/*` (FACT) | new summary endpoint with day-over-day and attention items |
| Pesanan / tables | tables, floor board, open tabs, kitchen tickets (028) (FACT) | design adds order monitor KPIs, "over 45 minutes", status tabs |
| Produk | variants, modifiers, BOM with cost, stations (FACT) | combos, outlet prices, 86/quota, channels, ingredient UOM/batch, semi-finished, packaging, retail goods, recipe versions |
| Inventori | single global stock per variant/material; material movements only `purchase` (FACT) | locations, batches/expiry, counts, transfers, wastage, approvals |
| Pembelian / Supplier | PO draft→ordered→received→paid, vendors, vendor material prices (FACT) | purchase requests, goods-receipt documents, supplier invoices + 3-way match, returns, scorecards, halal certificates |
| Penjualan | orders completed/voided/open, void with free-text reason, payments with verification (FACT) | immutability guard, returns/refunds with approval, complimentary/staff meal, reprint limits, shift variance approval, X/Z |
| Pelanggan | basic customer CRM + import/export (FACT) | members, tiers, points ledger, feedback, consent, DSR |
| Laporan | 9 report endpoints + exports; no history/schedule (FACT) | 22-report catalogue, schedules, export history |
| Karyawan & User | roles = flat `menu_keys` (24); no PIN, no MFA; activity log with old/new values (FACT) | permission matrix, outlet scope, PIN, MFA, SoD rules |
| Konfigurasi POS | none (FACT) | EOD, sync exceptions, releases, limits, presets, receipt, app policy |
| Pengaturan | key/value `settings`, payment channels, theme colour (FACT) | outlets, tax/rounding, payment methods, reason codes, devices, printers, schedules, import history |

**FACT — no outlet concept exists**: no `outlet_id`, `business_date`, tax, reason-code, approval, device or release tables anywhere. Stock is one global `current_stock`. `orders.event_id` and `session_id` are NOT NULL; the restaurant variant (028) models a venue as one long-lived Event plus one artist "Restoran".

## Decision 1 — Phase the work; ship the visual system first because it needs no constitutional change

- **Decision**: Deliver in ordered phases (see plan.md). Phase 1 (frame, tokens, page kit, restyle of all screens including cashier screens) changes presentation only and is allowed by the current constitution. Phases 2+ (outlets and everything that depends on them) require the constitution amendment in Decision 2.
- **Rationale**: Q1 = C makes this a multi-quarter programme. The frame and kit are on the critical path for every module, produce value alone, and carry no data-model risk.
- **Alternatives rejected**: one big-bang branch (unreviewable, blocks all other work); building modules first on the old frame (would be rebuilt twice).

## Decision 2 — Amend the constitution before any multi-outlet work (gate)

- **FACT**: Constitution v1.0.0, "Stack & Environment Constraints": one local machine per store, no cloud tier, no multi-tenancy; "architectural decisions MUST account for this". The design needs several physically separate outlets to share data.
- **Decision**: Propose amendment **1.1.0**: "one organisation; one or more outlets; the system runs either on a single local machine (unchanged) or on one shared server reachable by all outlets. Multi-tenancy across unrelated customers remains out." Single-outlet installs keep working with no behaviour change (one default outlet, selector hidden).
- **Rationale**: keeps every existing install valid and makes the new capability additive. Version level is a governance call (the section is materially expanded → MINOR; if the owner considers the "no multi-tenancy" rule a principle → MAJOR); recorded as open for the owner.
- **Also update, in dated notes (not rewritten history)**: PRD §1/§9.2/§10.2, `CLAUDE.md` (single-store statements), RUNBOOK deployment sections.
- **Alternatives rejected**: ignoring the conflict (silently violates the document that "supersedes ad hoc practice"); one installation per outlet with sync between them (a distributed-systems project, see Decision 3).

## Decision 3 — One shared server for outlets, not per-outlet installs with replication

- **Decision**: A multi-outlet organisation runs ONE server (own server or hosted VM). Outlet devices reach it over HTTPS. Offline behaviour is a client concern handled through the sync contract (Decision 12); the server stays single-writer.
- **Rationale**: replication/merge between independent MySQL instances would need conflict resolution for stock, numbering and cash that the design already models only as "device events", not as database merges. A hosted guide already exists (`docs/deploy-oracle-cloud.md`, Caddy + Let's Encrypt, **FACT**), so the infrastructure pattern is proven for one store.
- **Alternatives rejected**: per-outlet local servers with two-way DB sync (high risk of corrupt stock/cash, no tooling); peer-to-peer.
- **Consequences recorded**: licensing is per machine today (**FACT**: one `license_activations` row bound to a machine fingerprint). One shared server = one licence covers all outlets. Whether outlet or device counts are licensed is a business decision, **left open** (see plan "Open decisions"); default: no limits enforced.

## Decision 4 — Outlet scoping: a second global scope, derived server-side

- **FACT**: the codebase already isolates DEMO/LIVE with `HasDataMode` + `DataModeScope` (35 models), with the known trap that raw `DB::table()` queries bypass Eloquent scopes and must filter by hand (`ReportController`).
- **Decision**: add trait `BelongsToOutlet` + `OutletScope` mirroring `HasDataMode`. The active outlet set comes from the authenticated user's permitted outlets intersected with the request's selected outlet (header `X-Outlet-Id`, or "all permitted"). The server stamps `outlet_id` at creation from session/context; a client-supplied `outlet_id` is never trusted (Constitution IV).
- **Which tables**: operational/transactional ones: orders, cashier_sessions, dining_tables, stations, payments (via order), stock locations/balances/movements, purchase documents, wastage/counts/transfers, shifts, devices, printers, shift schedules. NOT outlet-scoped: products/categories/modifiers/materials/vendors/customers (organisation-wide master data), with per-outlet overrides in pivot tables (price, availability).
- **Guard against the known trap**: a test that enumerates every outlet-scoped table and asserts raw report queries filter on it; every new report goes through one `OutletFilter` helper rather than hand-written `where`s.
- **Alternatives rejected**: a column on every table including masters (forces duplication of the catalogue); database-per-outlet (blocks organisation-wide reporting).

## Decision 5 — Business date: stamped column, reports switch to it

- **FACT**: no `business_date` exists; reports filter `whereDate(created_at)`; order numbers reset per calendar day.
- **Decision**: add `business_date` (date) to orders, payments, cashier_sessions and movement tables; stamped server-side from the outlet's timezone and cut-off at creation; backfilled from `created_at` in the app timezone. Reports and dashboard use `business_date`.
- **Decision**: `day_closes` per (outlet, business_date). Writes to a closed business date are refused with `409 BUSINESS_DAY_CLOSED` in the same service layer that already guards orders (a late device event is routed to Sync Exceptions instead, Decision 12).
- **Rationale**: a stored date is immutable after the cut-off or timezone changes; recomputing from timestamps would silently move historical sales (Constitution IV: immutable snapshots).

## Decision 6 — Permissions: add action-level permissions next to menu keys

- **FACT**: `roles.menu_keys` JSON, 24 flat keys, `User::isOwnerOrAdmin()` is literally `canAccessMenu('settings')`; `users` and `roles` are reserved. The design's matrix has ~25 action permissions (e.g. `sal.return.approve`, `pur.po.approve`, `prv.pii.reveal`).
- **Decision**: add `roles.permissions` JSON (action keys named as in the design). Menu visibility becomes derived (a module is visible if the role holds any of its permissions or its legacy menu key). Enforcement goes through one `PermissionGate` used by FormRequests/Policies/controllers (the three existing mechanisms stay; they call the gate). A migration maps each existing role's `menu_keys` to equivalent permissions, so no current user loses or gains access.
- **Decision**: `user_outlets` pivot (empty = all outlets) for outlet scope.
- **Separation of duties** table (`sod_rules`): e.g. admin cannot transact, auditor is read-only, requester ≠ approver. Enforced in `ApprovalService`, not in controllers.
- **Alternatives rejected**: replacing `menu_keys` outright (breaks the existing role UI, tests and reserved-key rule); per-module ad-hoc `if role ===` checks (the pattern the constitution warns against).

## Decision 7 — One approval path

- **Decision**: a single `ApprovalService` and an `approvals` table (subject, requested_by, decided_by, decision, reason_code, amount, outlet). Purchase requests, stock counts, wastage over threshold, refunds, cash variances, price releases, discounts/voids above limit all call it. It enforces requester ≠ approver and the role's `approval_limits`.
- **Rationale**: Constitution I (one implementation per cross-cutting concern, like `PaymentRecorder` and `ActivityLogger`). **FACT**: only one SoD rule exists today (`Payment::mayVerify`).
- **Alternatives rejected**: per-module approval columns (seven copies of the same rule).

## Decision 8 — Reason codes as managed data; free text stays as the note

- **FACT**: reasons are free text today (`void_reason`, `cancel_reason`, stock `reason`).
- **Decision**: `reason_codes` (category, code, label, requires_approval, active). New flows store `reason_code_id` + optional note. Existing flows keep accepting text until each module's phase migrates it; the shared dialog supports both.
- **Alternatives rejected**: enum columns (not editable by the owner, contradicts "managed list" in the design).

## Decision 9 — Stock by location, with `StockService` still the only write path

- **FACT**: `StockService::applyMovement()` is the sole write path for variant stock (Constitution I); materials have `MaterialStockService` and only a `purchase` movement type.
- **Decision**: add `stock_locations` and `stock_balances (stockable, location, qty, avg_cost)`; extend `applyMovement` with a location and new movement types (`transfer_out`, `transfer_in`, `wastage`, `count_adjustment`, `consumption`). Keep `current_stock` as a denormalised total maintained in the same transaction, so existing code, imports and reports keep working. Unify variant and material movements behind the same service rather than forking a second one.
- **Costing**: moving average per location (the design states this), computed on receipt/transfer-in.
- **Batches/expiry**: `stock_batches` only for items flagged `track_batch`; consumption uses first-expired-first-out.
- **Consumption by sales**: when an item with a recipe sells, ingredient consumption is posted per location by the order service (design: "EOD otomatis"). ASSUMPTION: posted at order completion, not at end of day, so stock is current; the design's "EOD otomatis" label is reconciled in Phase 5.

## Decision 10 — Purchase-to-pay extends the existing PO, it does not replace it

- **FACT**: `PurchaseOrder` with `ALLOWED_TRANSITIONS`; receiving = status change crediting material stock; no GRN document, no partial receipt.
- **Decision**: add `purchase_requests`, `goods_receipts` (+ lines, QC result, photo), `supplier_invoices` (+ match result), `supplier_returns`. Extend the PO status set (awaiting approval, sent, partly received, fully received, closed) with a migration that maps existing statuses (draft→draft, ordered→sent, received→fully received, paid→closed, cancelled→cancelled) and keeps the existing printable invoice and payment linking. 3-way match compares PO line, receipt line and invoice line within a configurable tolerance.
- **Alternatives rejected**: new parallel "procurement" module beside the old PO (two sources of truth).

## Decision 11 — Configuration releases are snapshots; rollback re-applies a snapshot

- **Decision**: `config_releases` hold an immutable JSON snapshot of the effective configuration (menu prices/availability/modifiers, tax rule version, business rules, reason codes, printers) plus a diff against the previous release. Price/menu edits are staged as drafts and applied atomically on publish. Rollback publishes an earlier snapshot as a new release (history is never rewritten). Clients fetch `GET /config/current` and report their applied version (`devices.applied_release_id`).
- **Risk**: highest-complexity piece of Phase 8; it is isolated behind one service so it can be descoped to "price/menu only" without touching other modules.
- **Alternatives rejected**: live edits with no versioning (the design explicitly shows a release lifecycle); event-sourced configuration (disproportionate).

## Decision 12 — Devices and sync: build the server contract, not the Android client

- **Decision**: `devices` + one-time activation codes (stored hashed, 15-minute expiry, single use), device tokens via the existing token mechanism with a device ability, `POST /sync/events` (idempotent by device id + sequence), conflict classification into `sync_exceptions` (number conflict, table conflict, total mismatch, revoked user, closed business day, unknown item). The existing web POS registers as an online-only device.
- **ASSUMPTION** (carried from the spec): the native Android POS is a separate product. This plan defines the contract it will use and stops there.
- **Alternatives rejected**: building offline-first behaviour into the web SPA now (not in the design's back-office scope, and a large separate effort).

## Decision 13 — Search, notifications and dashboard are computed on read

- **Decision**: `GET /search` federates over orders (number, table), customers/members, products and tables with LIKE on indexed columns and the caller's permissions/outlets applied; no external search engine. `GET /attention` computes attention items on read (cached ~30 s per user and outlet) and feeds the bell, rail dots and the Dashboard list; nothing is stored, so there is no read/unread state. `GET /dashboard/summary` returns today vs yesterday-at-same-time, trend series and period totals from `business_date`.
- **Rationale**: matches the spec entity "Attention Item: derived, read-only"; avoids a notification store and its retention rules. **ASSUMPTION**: per-user "dismiss" is not required; revisit if users ask.
- **Alternatives rejected**: Elasticsearch/Meilisearch (new infrastructure for a few thousand rows per outlet); stored notifications (unneeded state).

## Decision 14 — Reports: registry-driven; scheduling needs a scheduler process

- **FACT**: 9 report endpoints and on-demand exports exist; `routes/console.php` has no schedule; no queue worker (queue driver `sync`).
- **Decision**: a report registry (id, group, parameters, formats) with one runner class per report, built from shared query helpers (`OutletFilter`, business date), so all pages and reports read one sales source (SC-006). Exports use the existing spreadsheet library for CSV/XLSX. Export history on a private disk with expiry. Scheduled reports run from the Laravel scheduler; this requires adding a scheduler process to the container entrypoints (a recorded change to `docker/*/entrypoint.sh`).
- **UNVERIFIED**: server-side PDF generation needs a library; none is confirmed in `composer.json`. The existing client-side image/PDF approach (`html2canvas` + `jsPDF`) is a fallback for single documents but cannot serve scheduled delivery. The choice and its version are made in the Phase 7 task, after checking the package index.
- **Alternatives rejected**: cron-only scripts (outside the app's audit and permission model).

## Decision 15 — Shared-server hardening is its own phase and precedes real multi-outlet use

- **FACT**: both images serve PHP with `artisan serve` (4 experimental workers); no nginx/php-fpm; no trusted-proxy configuration; Sanctum tokens never expire; CORS `*` without credentials; no security headers; default DB root user; uploads are not on a named volume in the store compose (**FACT** from survey, to be confirmed).
- **Decision**: Phase 2b delivers a production web tier for the shared deployment (a dedicated image with a real PHP application server and reverse proxy; the specific stack is chosen in the task and verified), trusted proxies, security headers, token expiry/rotation, a non-root DB user, named volumes for uploads, and a scheduler. The single-store images stay as they are.
- **Rationale**: with several outlets the app stops being "LAN only"; the security review in `docs/security/2026-09-05-full-app-security-review.md` assumed a LAN attacker model.
- **Load note**: floor and kitchen screens poll every ~3 s; the 4-worker setting was measured only to about 5 tabs (**FACT**). Phase 2b includes a load test sized to the target outlet count before go-live.

## Decision 16 — Personal data (UU PDP, ISO 27701)

- **Decision**: permission `prv.pii.reveal` gates unmasked phone/email/birth date in lists and exports; masked by default. Consent stored per member and channel. Data-subject deletion anonymises the member (keeps transaction totals) with a reason and audit entry. Member export requires a reason (logged). The audit log gains actor IP and outlet.
- **FACT**: customers already hold phone/email; `ActivityLogger` stores old/new values but not IP; the audit log is not tamper-evident. **Decision**: adding IP/outlet now; tamper-evidence (hash chain) is **out of scope** and noted as a risk.

## Decision 17 — Visual system: extend tokens, keep the runtime accent mechanism

- **FACT**: tokens are Tailwind v4 `@theme` variables in `resources/css/app.css` (brand green, mint tints, line/muted scales); `utils/theme.js` overrides `--color-brand*` at runtime from the store's theme colour; Plus Jakarta Sans is already the font; Phosphor icons.
- **FACT**: the Back Office design loads Plus Jakarta Sans. The `_ds/modernist-…` folder in the design bundle is a different design system (red accent, Archivo font, square corners) and **MUST NOT** be used as the source of tokens.
- **Decision**: define the Back Office palette (dark navy rail, indigo accent, semantic marker colours) as new `@theme` tokens **sampled from the reference captures in the first Phase 1 task** (no hex values are invented here), keep `--color-brand*` as the accent that the theme-colour setting overrides, keep Phosphor icons (no new icon dependency). Rail 64 px collapsed width and 240 px expanded width are read from the design page.
- **Constitution III**: no raw hex in components; copy in Indonesian with English through the existing i18n; hidden (not disabled) controls for unauthorised actions.

## Decision 18 — Frontend structure: a page kit, then migrate screens

- **Decision**: new `components/layout/` frame parts (`RailNav`, `TopBar`, `Breadcrumb`, `OutletSelector`, `GlobalSearch`, `NotificationBell`, `UserMenu`), new `components/page/` kit (`PageHeader`, `KpiStrip`+`KpiCard`, `TabBar`, `FilterBar`, `DataTable` selection/bulk support, `DetailPanel` built on `BaseDrawer`, `BulkBar`, `ReasonDialog` with codes, `SettingsLayout`, `StatusChip`). Existing views migrate to the kit one by one; the largest (`PreordersView` 1,983 lines, `ProductsView` 908, `ReportsView` 738, `SettingsView` 562) are split while migrating.
- **Detail panel**: the design opens a right-hand panel; the existing `BaseDrawer` is an overlay with focus trap and Escape handling. **Decision**: reuse it (accessible, tested) rather than a persistent side-by-side pane; revisit if the design review requires non-modal behaviour.
- **Rationale**: Constitution I and III (one implementation, tokens only). **FACT**: 31 views, 21,588 lines.
- **Alternatives rejected**: a third-party admin template (conflicts with "no external design-system dependency", Constitution III).

## Decision 19 — Testing and verification

- **FACT**: 138 backend feature-test files (~1,378 tests) on real MySQL; 95 frontend test files (Vitest + Testing Library); no browser E2E tooling.
- **Decision**: no new test framework. Per phase: PHPUnit feature tests (including an outlet-isolation matrix and the data-mode matrix), Vitest tests for each kit component, and a real-browser check against the real API with the console clean (Constitution II) recorded in `quickstart.md`. A before/after cashier-flow script measures steps and time (SC-010). Migrations are rehearsed on a copy of populated data (as feature 028 did).
- **Known baseline**: the full suite currently has failures in `InvoiceAvailabilityAndLogoTest`, `ModeScopingFollowUpTest`, `OrderTest` and `PosPartialPaymentTest` (observed 2026-10-11, causes not yet established). They are a precondition to clear before Phase 2 migrations touch orders and payments.

## Decision 20 — Data migration and the default outlet

- **Decision**: a single migration set creates the default outlet from current store settings, backfills `outlet_id` and `business_date`, creates default stock location(s) and moves `current_stock` into balances, maps `menu_keys`→`permissions`, and assigns existing users to all outlets. Every migration is reversible and rehearsed on populated data; DDL is not transactional in MySQL, so each is written to be safely re-runnable (lesson from the 028 migration incident).
- **DEMO/LIVE**: both modes continue; outlet scope applies inside each mode.

## Items deliberately not decided here (see plan "Open decisions")

1. Licensing entitlement for outlets/devices.
2. Constitution amendment level (MINOR vs MAJOR).
3. Hosting choice for the shared server (own hardware vs hosted VM) and target outlet count for the load test.
4. Server-side PDF library.
5. Exact content/columns of the 22 reports beyond the visible sample.
