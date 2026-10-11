**Classification**: INTERNAL

# Research: Dashboard Revamp

Labels: **FACT** = read in this repository or the design file on 2026-10-11; **ASSUMPTION** = to be confirmed. Spec: [spec.md](spec.md). Plan: [plan.md](plan.md).

## D1 — Amendment: rewrite for this requirement, keep it a draft until the owner ratifies

- **Decision**: maintain one amendment text, [constitution-amendment.md](constitution-amendment.md), version **2.0.0** (MAJOR, owner decision O1). It replaces the 041 wording (outlets + shared server only) with: one organisation, one or more outlets, single machine **or** one shared server, exactly one server/database per organisation, **registered devices that never hold authority**, plus two small additions — device identity and bounded client time in Principle IV, cache keys and computed-at time in Principle V.
- **Rationale**: the 041 text had no concept of a device and no rule for client-reported data; the 042 requirement (heartbeats, unsynced counts, offline cash, late events) needs both. Without the Principle IV addition, "business date and outlet are set by the system" (FR-028) would contradict "events that arrive late carry a client time".
- **Not applied**: the file in `.specify/memory/constitution.md` changes only when the owner ratifies (T001). The agent does not ratify its own governance change.
- **Alternatives rejected**: editing the constitution now (skips the owner); leaving the 041 text (does not cover devices); a MINOR bump (the owner treats the single-machine rule as a defining rule, so MAJOR).

## D2 — Two stages, so only the outlet and device work waits

- **Decision**: Stage A delivers Stories 1–4 and the "Per event" section with *installation-level* settings (`store_timezone`, `business_day_cutoff`, `cash_difference_tolerance`); Stage B (after T001) adds outlets and devices and **moves** those settings onto the default outlet by migration.
- **Rationale**: the business date, comparison, attention items and orders table are valid on a single-machine install and are the visible core of the request. Putting them behind a governance decision would delay the user for no technical reason. The settings move is one small migration, not throwaway work.
- **Alternatives rejected**: everything after T001 (blocks the whole request); building Stage A on `created_at` converted on the fly (then rebuilt when the stored date arrives).

## D3 — Business day: stored `business_date` + `completed_at`, store timezone, cut-off

- **FACT**: app timezone is hard-coded `UTC`; `orders` has `created_at` only; existing date filters are `whereDate(created_at)` / `DATE(created_at)` on UTC. A sale at 02:00 WIB lands on the previous UTC date today.
- **Decision**:
  - Add `orders.completed_at` (the instant it became a sale), `orders.business_date`, `payments.business_date`, `cashier_sessions.business_date`.
  - A new `BusinessDay` service answers "which business date is this instant?" and "what are the UTC bounds of this date?" from `store_timezone` (default `Asia/Jakarta`, ASSUMPTION: the user's clock in the session is WIB) and `business_day_cutoff` (default `00:00`). `config('app.timezone')` stays `UTC` — changing it would silently reinterpret every stored timestamp.
  - POS checkout: `completed_at = created time`, `business_date` from it. Tabs: stamped when the tab is **settled** (not opened); a tab opened 23:30 and settled 00:20 belongs to the later business date, matching "counted at completion". An open tab carries the date it was opened.
  - Voided orders keep their last stamped date.
  - Backfill: `completed_at` = `created_at` for retail orders, `updated_at` for completed dine-in/takeaway orders (ASSUMPTION — `updated_at` of a settled tab is the settlement unless it was edited later; the dry-run report lists any order whose two instants differ by more than 12 h); `business_date` from `completed_at ?? created_at` in the store timezone. See O2.
  - Reports and the Sales page switch their date filter from `DATE(created_at)` to `business_date` (one behaviour change, announced by a dated note and a pre-migration dry-run listing the rows that move day).
- **Rationale**: a stored date does not change when the timezone or cut-off is edited later (Constitution IV: immutable history); the hourly trend and "same time yesterday" need the completion instant that does not exist today.
- **Alternatives rejected**: `CONVERT_TZ()` in SQL (needs MySQL timezone tables, not guaranteed on a laptop install); computing the date in PHP per row on every request (slow at 30 days, unindexable).

## D4 — One sales-figures implementation shared with the Sales page

- **FACT**: the Sales page cards read `totals.pos_unit_count/pos_gross_sales/pos_net_sales` from `ReportController::sales()` (lines ~232–253): completed orders only, `SUM(order_items.line_total)` as net, `SUM(line_total + discount_amount)` as gross, `COUNT(DISTINCT orders.id)` as orders, explicit `order_items.data_mode`. Pre-orders are merged only into the *shared* totals, not the `pos_*` ones.
- **Decision**: extract that aggregation into `SalesFigures` (net, gross, orders, units, grouped by business date or hour, optional outlet filter) and make `ReportController::sales()`'s `pos_*` totals call it. The Dashboard calls the same class. **Characterisation tests of the current totals are written and passing before the extraction.**
- **Dashboard card definitions** (follow the Sales page by construction):
  - *Total penjualan* = `pos_net_sales` for the range.
  - *Total order* = **all-status** orders in the range (design label "Semua status"): `open + completed + voided` by their business date. This differs from the Sales page's *completed* count by design; the table's "Semua" tab count equals it (SC-005), and the spec's SC-001 is refined accordingly (see spec "Planning refinements").
  - *Rata-rata nilai order* = net sales ÷ **completed** orders (design: "Net ÷ order"); zero completed orders → "no comparison", never a division.
- **Known, inherited, flagged (O3)**: `SUM(line_total)` does not deduct order-level discounts, so a discounted tab shows a net that is higher than the takings. Fixing it belongs to its own change that alters the Sales page and Reports together.
- **Alternatives rejected**: a second query in the Dashboard (two formulas for one number; exactly what Constitution I forbids); Σ `orders.total_amount` (would disagree with the Sales page).

## D5 — Comparison basis and trend series

- **Decision**:
  - Period *today*: current = business-day start → now; comparison = **yesterday's cumulative figure up to the same elapsed time** since its own day start; series are **hourly** (hours since day start). The comparison series is drawn **dashed across the whole width**; no projection is invented.
  - Periods *7d / 30d*: current = last N business dates including today; comparison = the N dates immediately before; series are **daily**, grouped in SQL by `business_date`.
  - Change chip: `(current − comparison) / comparison`, one decimal; comparison 0 or absent → "no comparison" chip. For low stock the change is a **count difference** and a rise is bad (red).
  - Hourly bucketing is done in PHP from the day's completed orders (≤ a few thousand rows) using the store timezone, because SQL timezone conversion is not available (D3).
- **Rationale**: matches the design's chips ("vs kemarin s.d. 19.30"), stays honest (no forecast), and keeps 7/30-day queries to one grouped statement.

## D6 — "Perlu tindakan": scope, definitions, resolution

Attention items describe **now** (the current business date), not the selected period; the scope line says so. Each is computed on read by `AttentionService` with the same definitions used elsewhere.

| Item | Definition (FACT-based) | Link target | Resolves when |
|---|---|---|---|
| Payments awaiting verification | `payments.verification='pending'`, method ≠ cash, parent **order** not voided; POS/tab payments only (pre-order payments belong to the Pre-orders screen) | Sales page with the existing "needs verification" filter | verified through the existing verify action |
| Void after kitchen send | `kitchen_ticket_items.status='cancelled'` with `cancelled_at` in today's bounds; amount = `qty × unit_price` (new snapshot; older rows count as items with "amount unknown"); share = amount ÷ today's gross sales | Activity log filtered to `item_cancelled` today (no void/exception report exists; the design's REP-031 is out of scope) | the business day passes (no review state exists yet) |
| Shift cash difference | closed sessions with `closed_at` in today's bounds and `abs(cash_difference) > tolerance` | Shift list | the business day passes (approval flow is a later phase); the meta states the tolerance |
| Stock below reorder point | `low_stock_alert IS NOT NULL AND current_stock <= low_stock_alert AND is_active` (the existing `/stock/low` definition; org-wide, see D8) | Stock screen with the low-stock filter | stock raised |
| Offline devices (B) | registered, active devices with no heartbeat inside the threshold | Settings → Devices | device reports |

- **Scope of "Perangkat offline" meta**: device, minutes offline, unsynced events, exposure vs outlet limit — all advisory figures (amendment B).
- **Permissions**: each item carries `link` only when the caller can open the destination (menu key), otherwise `link: null`; items themselves show to anyone with the Dashboard menu. Money values follow the existing Dashboard visibility (no cost/margin introduced).
- **"memblokir EOD"** is omitted until an end-of-day close exists (spec assumption).
- **Alternatives rejected**: storing attention rows with read/unread (nothing to reconcile, no state to drift); following the selected period (a 30-day "pending verification" list would mislead).

## D7 — Order table: tabs mapped onto the statuses this product has

- **FACT**: order status `open|completed|voided`; payment state is derived (`PaymentSummary`), never stored for orders; `orders.paid_amount` keeps the *tendered* amount, so it cannot be compared with the total directly.
- **Decision**: each order belongs to exactly one tab, evaluated in this order: voided → **Dibatalkan**; `open` → **Terbuka**; completed with paid < total → **Dibayar sebagian**; completed with paid ≥ total → **Lunas**. **"Pre-bill dicetak" and "Ditutup" are hidden** (no recorded pre-bill, no day-close) — shown only when their data exists (a later feature). Payment-status filter options: Belum dibayar, Menunggu verifikasi, Sebagian, Berhasil; "Gagal" and "Refund" appear only when such states exist.
- **Payment state in SQL**: tab counts need a set-based expression. A SQL fragment is added **inside `PaymentSummary`** next to `for()` and a parity test asserts both give the same state for every combination (partial, rejected excluded, change deducted). No second formula elsewhere.
- **Types**: Retail (booth), Dine-in, Takeaway (the stored `service_type` values); the design lists only the latter two.

## D8 — Low stock: organisation-wide, history from the ledger

- **FACT**: `stock_movements` has `qty_change` and `stock_after`; there is no per-outlet stock (that is 041 phase 3).
- **Decision**: the KPI and attention item count variants at or below reorder point across the **whole organisation**, and the card says so when an outlet is selected. The value "at the same time yesterday" and the trend are rebuilt by replaying the ledger (`stock_after` of the last movement before the window, then movements inside it) for variants that have a reorder threshold, in one query + PHP replay. No snapshot table, no scheduler.
- **Risk**: replay cost grows with movements per window; measured in A5 against the 1,000-orders-per-day fixture; fallback is a daily snapshot table written when the Dashboard is first opened for a date.

## D9 — Devices: server registry and heartbeat; the web POS is online-only

- **FACT**: no service worker, IndexedDB, `navigator.onLine` handling or retry queue exists in the SPA; a failed `POST /orders` leaves the payment dialog open and the cashier retries manually with the same `local_ref`. There is no per-terminal identity; the only machine identity is the licence fingerprint (per installation).
- **Decision**: build the **registry and contract** (register → one-time activation code → device token with a device-only ability → `POST /devices/heartbeat` with `pending_events` and `offline_cash`). The existing SPA registers as a device from Settings and sends a heartbeat every 60 s while a POS screen is open, always reporting `pending_events = 0` and `offline_cash = 0`. An offline-capable client later uses the same endpoint unchanged.
- **Consequence stated plainly**: with only the web POS, the "Perangkat offline" item can show *which terminal stopped reporting and for how long*, but never unsynced events or offline cash. This is the largest limitation of choosing Q1 = B and is in the user-facing report.
- **Offline threshold**: a device is offline after `device_offline_after_minutes` without a heartbeat (default 5, ASSUMPTION); retired devices never count.
- **Credential model**: Sanctum token whose tokenable is the `Device`, ability `device:report`; a dedicated route group with `abilities:device:report`; `SetLocaleFromUser` must tolerate a non-user principal. A user token cannot call the heartbeat route and a device token cannot call anything else (tested both ways).
- **Alternatives rejected**: reusing the user's token with a device header (anyone with a login could impersonate a device); a long-lived shared secret per outlet (cannot revoke one device).

## D10 — Outlets and scope (Stage B)

- **Decision** (adopts 041 Decisions 3–4, narrowed): `outlets` (code, name, timezone, cut-off, offline cash limit, cash tolerance, active); `user_outlets` (no rows = all outlets); trait `BelongsToOutlet` + `OutletScope` on **Order, Payment, CashierSession, DiningTable, Station, KitchenTicket, Device**; master data (products, customers, vendors, materials, categories) and stock stay organisation-wide.
- **Selected outlet**: header `X-Outlet-Id` (or "all permitted"), intersected server-side with the user's permitted outlets; an outlet the user may not use is a 403, never a silent fallback.
- **Stamping**: `outlet_id` is copied from the cashier session (which gets it when the shift opens: the user's single permitted outlet, or the selected one, validated). Never read from a request body. With one outlet everything is the default outlet and the selector is hidden.
- **Raw queries**: a single `OutletFilter` helper; every raw `DB::table()` read of orders/payments/sessions in `ReportController`, `SalesFigures` and the Dashboard goes through it. An enumerating test lists the outlet-scoped tables and fails if a raw query in those files lacks the filter.
- **Alternatives rejected**: `outlet_id` on every table (duplicates the catalogue); database per outlet (blocks the all-outlets view).

## D11 — Existing reports and outlet-limited users

- **FACT**: `SettlementService::recalculateForEvent()` and several reports use raw queries that an Eloquent scope does not reach; the recap/profit/seller-cost reports are event-wide and cannot be honestly split by outlet in this feature.
- **Decision**: Stage B makes **Sales page, `GET /reports/sales` and its export, and the Dashboard** outlet-aware (via `SalesFigures` + `OutletFilter`). **Recap, Cost & Profit, Seller Cost and per-seller reports answer 403 to a user restricted to fewer than all outlets** until they are outlet-aware (O5). `SettlementService` keeps computing whole-event snapshots (it must never compute a partial one).
- **Rationale**: a partial snapshot written by an outlet-limited viewer would corrupt stored settlements; hiding the entry points is safer than a half-filtered report.

## D12 — Caching and refresh

- **Decision**: `DashboardService::summary()` and `AttentionService::items()` cache for ≤ 30 s with a key of `[data_mode, outlet ids, period, user's money-visibility]` and return `computed_at` (amendment C). Tests run on the `array` store. The orders table is **not cached**.
- **Real-time**: the SPA polls summary/attention/orders every 30 s while the tab is visible (`useAutoRefresh`, `document.visibilityState`), keeps filters, and shows "Real-time" only while the timer is running; it never overwrites a user's open row detail.
- **FACT** to respect: the file cache store is shared between the dev app and the test run (041 baseline note) — hence `CACHE_STORE=array` for tests.

## D13 — Exports

- **Decision**: both exports are **synchronous CSV downloads** (no queue or notification centre exists). The table export reuses `DashboardOrdersQuery` with the same filters and columns and excludes contact data; the Dashboard export writes the four figures with comparison and trend points and the attention items, with `computed_at` in the first lines. Excel is not used for either (CSV is what the design names for the table).
- **Alternative rejected**: queued export with a notification link (needs the notification phase).

## D14 — Existing Dashboard content moves, it is not rewritten

- **Decision** (Q2 = B): the current panels (sales per day, category/seller/event charts, customer statistics, shortcuts, pre-order and per-seller lists) move **unchanged** into `PerEventSection.vue` under a "Per event" heading, loading lazily after the new regions so they never delay them. The `chart.js` imports stay in that chunk. Their endpoints and permissions are untouched.
- **Rationale**: nothing a booth install relies on disappears; the diff is a move plus new code, which is reviewable and low-risk.

## D15 — Frontend building blocks

- **Decision**: add `TrendLine` (inline SVG, solid actual + dashed comparison, accessible text alternative), `ChangeChip` (arrow + sign + colour + hidden text; direction never by colour alone), `AttentionItem`/`AttentionPanel` to the 041 page kit; reuse `KpiStrip`, `TabBar`, `FilterBar`, `PageHeader`, `ErrorState`, `LoadingState`, `EmptyState`, `DataTable`, `TransactionItemsModal` (row detail). Period and filters live in the address via `useQueryState`. Each region has its own `loading | error | empty | data` state.
- **Bundle**: new code is in the route chunk of the Dashboard; main must stay within +10 kB of 374 kB (measured in A5).

## Risks

| Risk | Mitigation |
|---|---|
| Backfill moves sales between days | dry-run report, owner choice O2, dated note |
| Characterisation of Sales totals misses an edge (pre-orders merged only in shared totals) | tests pin `pos_*` separately from shared totals before extraction |
| Outlet scope missed on a raw query | one `OutletFilter`, enumerating test, 403 for non-splittable reports |
| Device features look empty with only the web POS | stated in the report; registry/contract ready for the native client |
| Replay of stock ledger slow | measured; snapshot-table fallback documented |
| Tab settlement stamping missed on one of three code paths (settle, split, merge/move) | test per path asserting `completed_at` and `business_date` |
