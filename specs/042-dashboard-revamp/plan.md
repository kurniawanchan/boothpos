**Classification**: INTERNAL

# Implementation Plan: Dashboard Revamp

**Branch**: `042-dashboard-revamp` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/042-dashboard-revamp/spec.md`; plan argument: "change the constitution amendment to match the new requirement."

## Summary

Replace the Dashboard with the design's version — period switch, four KPI cards with change versus the comparison basis and trend lines, a "Perlu tindakan" panel, and an "Order terbaru" table — and keep the current event charts below it under "Per event" (Q2 = B). Because Q1 = B, the missing foundations are built here too: a stored **business date**, **outlets** with a server-side outlet scope, and a **device registry** with heartbeats, an offline cash limit and a cash-difference tolerance.

The plan has two stages so that nothing waits on the owner unnecessarily:

| Stage | Contents | Needs the constitution amendment? |
|---|---|---|
| **P0 Gate** | Ratify the rewritten amendment ([constitution-amendment.md](constitution-amendment.md)) | it *is* the gate (owner action, T001) |
| **A — Dashboard on today's single install** | Business date + completed time stamped and backfilled, store timezone/cut-off/tolerance as installation settings, one shared sales-figures implementation, three dashboard endpoints + exports, new Dashboard UI, "Per event" section | **No** — compatible with the single-machine constitution |
| **B — Outlets and devices** | Outlets, user assignment, outlet scope and filter, outlet selector, outlet-aware sales/reports reads, device registry, activation, heartbeat, Settings screens, device notice and item, per-outlet splits | **Yes** — must not start before T001 |

The existing web POS has no offline queue (FACT, see research D9). Its device record therefore reports online/offline by heartbeat only; unsynced events and offline cash stay at zero until an offline-capable client uses the same endpoint.

## Technical Context

**Language/Version**: PHP ^8.3, Laravel ^13.17 (FACT, `composer.json`); Vue ^3.5.13, Vite, Tailwind v4 CSS-first
**Primary Dependencies**: Laravel Sanctum ^4.0 (tokens, `expiration` null), Pinia ^2.3, vue-i18n, vue-router ^4.5; `chart.js ^4.5.1` + `vue-chartjs ^5.3.4` are kept **only** for the retained per-event charts. The new trend lines are small inline SVG, no chart library.
**Storage**: MySQL 8 only (SQLite hard-fails). Database `warkop_wjm`, tests on `warkop_wjm_test`.
**Testing**: PHPUnit on real MySQL (`tests/Feature/`), run in the container with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array`; Vitest + Testing Library in `qa-tests/`; mandatory real-browser check of the Dashboard (Constitution II).
**Target Platform**: shopkeeper laptop (Docker or native) today; one shared server after the amendment.
**Project Type**: web application (Laravel API + Vue SPA, one repository).
**Performance Goals**: all four Dashboard regions drawn ≤ 3 s with 1,000 orders in the day on the baseline laptop; period switch ≤ 2 s (spec SC-004). Dev server measured ~0.5 s per API call (041 evidence), so the Dashboard makes **3 requests, not one per card** (summary, attention, orders).
**Constraints**: main bundle ≤ 374 kB + 10 kB (SC-007); every raw `DB::table()` query carries explicit `data_mode` (and, in Stage B, outlet) filters; money returned as strings; `StockService::applyMovement()` remains the only stock writer (this feature writes no stock).
**Scale/Scope**: ~1,000 orders/day/outlet, up to a few outlets and devices (ASSUMPTION; hosting size and outlet/device counts are still an open owner decision from 041, P0-3).

FACTS this plan relies on (all verified in the repository on 2026-10-11, details in [research.md](research.md)):

- `config/app.php` timezone is hard-coded `UTC`; every `created_at` is UTC and existing reports use `whereDate(created_at)` / `DATE(created_at)`, so a Jakarta day is off by 7 hours today. There is no timezone setting.
- `orders` has no `completed_at`, `voided_at` or `business_date`; status is `open|completed|voided`; `orders.event_id` is NOT NULL, so restaurant orders do appear in the event-joined reports.
- The Sales page cards read `totals.pos_*` from `GET /reports/sales`, which is `SUM(order_items.line_total)` over completed orders (order-level discounts are not deducted).
- A line removed after the kitchen ticket was sent leaves a `kitchen_ticket_items` row with `status='cancelled'`, `cancelled_at`, `cancel_reason` but **no price**.
- `stock_movements` stores `qty_change` and `stock_after`, so past stock levels can be read without a new history table.
- `payments` has no status column; verification is `pending|verified|rejected`; methods are `cash|bank_transfer|qr_ewallet`.
- There is no outlet, device or tolerance concept anywhere; `OutletSelector.vue` is a prop-driven stub that hides itself at one outlet.

## Constitution Check

*GATE: must pass before Phase 0 research; re-checked after design.*

Evaluated against Constitution **v1.0.0** as currently in force.

| Principle / section | Stage A | Stage B | Notes |
|---|---|---|---|
| I. Single write path, no duplicated logic | PASS | PASS | One `SalesFigures` implementation serves the Dashboard and the Sales/report totals (research D4); one `BusinessDay` service; one `OutletFilter`. No new stock or payment write path. |
| II. Verify, don't assume | PASS | PASS | Characterisation tests of the existing Sales totals are written **before** they are changed; real-browser check of every Dashboard region and role; parity tests (Dashboard = Sales page). |
| III. UX consistency | PASS | PASS | Tokens only, shared kit, `ErrorState` per region; links the role cannot open are omitted, not disabled; outlet selector and device screens hidden when not applicable. Copy in Indonesian with English locale kept (existing convention). |
| IV. Security | PASS | PASS | Money recomputed server-side; the Dashboard is read-only. Stage B adds server-enforced outlet scope, device credentials with a device-only ability, and "advisory device figures" (amendment B). |
| V. Performance | PASS | PASS | Three requests; SQL aggregation (no N+1); trend buckets for 7/30 days grouped in SQL; summary cached ≤ 30 s keyed by mode + outlet set + period (amendment C). SVG sparklines add no bundle weight. |
| **Stack & Environment: "single local machine per store … no multi-tenancy"** | PASS | **VIOLATION — amendment required** | Stage B needs several outlets sharing one database and devices as separate clients. See Complexity Tracking. |
| Documentation & Change Discipline | PASS | PASS | OpenAPI updated in the same commit as each route change; dated notes in PRD/CLAUDE.md for the new capability and for the date-basis change (research D3). |

**Gate result**: Stage A passes unconditionally. Stage B is **conditionally cleared**: the product owner chose Q1 = B, but **no Stage B task may start until T001 (ratification) is done.** The amendment text was rewritten for this requirement and replaces the 041 draft.

**Re-check after design (Phase 1)**: unchanged. The design adds no second server, no per-install replication and no cross-customer sharing; every Stage B element is covered by amendment sections A–C.

## Project Structure

### Documentation (this feature)

```text
specs/042-dashboard-revamp/
├── spec.md
├── plan.md                      # this file
├── research.md                  # Decisions D1–D15
├── data-model.md                # migrations, entities, state
├── constitution-amendment.md    # rewritten amendment 2.0.0 (T001 gate)
├── quickstart.md                # how to verify each stage
├── contracts/
│   ├── api-dashboard.md         # summary, attention, orders, exports
│   ├── api-outlets-devices.md   # outlets, scope, devices, activation, heartbeat, settings keys
│   └── ui-contracts.md          # new kit components, Dashboard layout and states
├── checklists/requirements.md
└── tasks.md                     # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Support/
│   ├── BusinessDay.php                  # NEW: date for an instant, UTC bounds of a date, today(); settings first (A), outlet later (B)
│   ├── OutletFilter.php                 # NEW (B): the one helper raw queries use
│   └── PaymentSummary.php               # EXTEND: set-based paid/remaining SQL fragment next to for(), parity-tested
├── Services/
│   ├── SalesFigures.php                 # NEW: net sales / orders / average by day or hour — single implementation
│   ├── DashboardService.php             # NEW: summary (KPIs, series, comparison), cache
│   ├── AttentionService.php             # NEW: attention items, computed on read
│   ├── DashboardOrdersQuery.php         # NEW: tab counts, filters, rows, export rows
│   ├── DeviceService.php                # NEW (B): register, activate, heartbeat, retire, offline derivation
│   ├── OrderService.php, TabService.php, TabSettlementService.php   # EXTEND: stamp completed_at/business_date (A); outlet_id (B); kitchen item unit_price at send (A)
│   └── KitchenService.php               # EXTEND: stamp kitchen_ticket_items.unit_price when a ticket is sent
├── Models/
│   ├── Outlet.php, Device.php           # NEW (B)
│   └── Concerns/BelongsToOutlet.php, OutletScope.php   # NEW (B), mirror HasDataMode / DataModeScope
├── Http/Controllers/Api/
│   ├── DashboardController.php          # NEW: summary, attention, orders, orders/export, export
│   ├── OutletController.php, DeviceController.php, MeScopeController.php   # NEW (B)
│   └── ReportController.php, SalesTransactionsService.php               # EXTEND: business_date basis, shared SalesFigures, OutletFilter (B)
├── Http/Requests/                       # DashboardQueryRequest, StoreOutletRequest, StoreDeviceRequest, UpdateSettingsRequest (+4 keys)
database/migrations/                     # dated after 2026_11_02_000003 (see data-model.md)
routes/api.php
docs/openapi-pos-mvp.yaml                # same commit as each route change
tests/Feature/                           # Dashboard*, BusinessDay*, SalesFiguresParity*, Outlet*, Device*, OutletIsolation*
resources/js/
├── api/dashboard.js, api/outlets.js, api/devices.js            # NEW
├── stores/outlet.js                                             # NEW (B): permitted outlets, selected outlet, X-Outlet-Id
├── components/page/TrendLine.vue, ChangeChip.vue, AttentionItem.vue, AttentionPanel.vue   # NEW kit pieces
├── components/dashboard/DashboardOrdersTable.vue, PerEventSection.vue
├── composables/useDashboardPeriod.js, useAutoRefresh.js         # NEW
├── views/DashboardView.vue                                      # REWRITTEN; old panels move into PerEventSection
├── views/settings/OutletsSection.vue, DevicesSection.vue        # NEW (B), routed under settings
├── components/layout/OutletSelector.vue, TopBar.vue             # WIRED (B) to the outlet store
└── locales/id.json, en.json
qa-tests/                                                         # component, unit, and one parity test per region
```

**Structure Decision**: web application inside the existing Laravel + Vue repository; new backend logic goes into `app/Services` and `app/Support` (Constitution I); new UI pieces go into the 041 page kit, not into the view.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Stack constraint "single machine, no multi-tenancy" (Stage B) | The design's "Semua outlet" Dashboard, per-outlet limits and registered devices need several outlets and several device clients on one data set | One install per outlet would need replication and conflict handling for sales, numbering and cash across databases; far riskier than one shared server (041 Decision 3). Ignoring the constraint would leave the constitution contradicting the product. |
| Second global scope (`OutletScope`) beside `DataModeScope` | Outlet isolation must hold for every Eloquent read, including ones written later | Hand-written `where outlet_id` per query is the defect class DEMO/LIVE already hit with raw queries; one scope plus one `OutletFilter` for raw queries and an enumerating test is cheaper |
| Stored `business_date` and `completed_at` on orders (Stage A) | Same-time-yesterday comparison and hourly trend need the completion instant; a stored date survives timezone/cut-off changes (Constitution IV, immutable history) | Recomputing from `created_at` each time silently moves history if the timezone or cut-off is edited, and cannot place an open tab settled after midnight |
| `unit_price` snapshot on `kitchen_ticket_items` | "Void setelah fire" must show an amount; the cancelled line is deleted and the ticket item has no price | Reading the current variant price would rewrite history when prices change; the audit log has quantity but no price |

## Open decisions (owner)

| # | Decision | Default used in this plan |
|---|---|---|
| O1 | Amendment level | **DECIDED 2026-10-11: MAJOR 2.0.0** |
| O2 | Backfill of `business_date` for existing rows | **DECIDED 2026-10-11: store timezone `Asia/Jakarta`** (sales between 00:00 and 07:00 WIB move to the local day), with a **dry-run report** of moved rows before the migration is applied |
| O3 | The Sales page's "net sales" is `SUM(line_total)` and ignores order-level discounts | **DECIDED 2026-10-11: "net sales" — read as: keep the Sales page definition; the Dashboard matches it** (INTERPRETATION of a one-word answer; correcting the definition stays a separate change) |
| O4 | Defaults: day cut-off `00:00`, offline threshold 5 min, tolerance Rp 5.000, offline limit Rp 3.000.000 (the last two are the design's values) | As stated; editable in Settings |
| O5 | Whether outlet-limited users may see recap/profit reports that cannot be split by outlet | Not answered; default kept: denied with 403 until those reports are outlet-aware (research D11) |

## Delivery order (input to /speckit-tasks)

1. **P0** — T001 ratify amendment (owner). Run in parallel with Stage A.
2. **A1** business-day foundation → **A2** shared figures + characterisation → **A3** endpoints → **A4** UI → **A5** verification and evidence. Stories 1–4 and FR-020 ship here.
3. **B1** outlets, scope, selector, user assignment → **B2** outlet-aware reads → **B3** devices, activation, heartbeat, Settings screens, SPA heartbeat → **B4** Dashboard integration (device item/notice, outlet splits) → **B5** verification with two outlets × four roles.
