**Classification**: INTERNAL

# Quickstart: verifying each phase

Runs against this checkout's Docker stack (project `warkop_wjm`): app `http://localhost:8010`, Vite `http://localhost:5180`, MySQL host port `3308`, databases `warkop_wjm` (dev) and `warkop_wjm_test` (tests).

## Always (every phase)

```bash
# Backend — ONLY with these overrides (the container's env_file otherwise points tests at the dev database)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test --filter=<PhaseTests>
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test      # full suite before merge

# Frontend
npm test
npm run build            # inspect output: heavy modules must be separate chunks (constitution V)
```

Seed and activate a fresh dev database when needed: `php artisan db:seed`, `php artisan license:dev-activate`. Dev accounts are listed in `docs/RUNBOOK.md`.

**Before Phase 2a starts**: the four test files that fail today (`InvoiceAvailabilityAndLogoTest`, `ModeScopingFollowUpTest`, `OrderTest`, `PosPartialPaymentTest`) must pass or be explained.

**Migrations**: rehearse every migration on a copy of populated data, run `migrate:rollback` then `migrate` again, and confirm re-runnability after a forced mid-way failure (MySQL DDL is not transactional).

## Phase 1 — Frame, kit, restyle

1. Sign in as `owner`, `admin`, `kasir01`, `inventory`. For each, open every rail item: page is inside the new frame, breadcrumb matches, rail item highlighted, items the role cannot use are absent; typing a forbidden URL redirects (and the API still answers `403`).
2. Collapse the rail, reload: state persists; hover/focus shows names; tab through the rail with the keyboard.
3. Open Penjualan, Inventori, Pembelian (Purchase orders), Supplier (Vendors), Pelanggan, Produk, Laporan, Karyawan & User, Pengaturan: header, four-figure strip, count tabs, filter bar, table; numbers equal row counts/sums with no filter.
4. Cashier flow script (same script before and after): open shift → add 3 items → send to kitchen → pay cash → close shift. Record steps and elapsed time; no increase allowed (SC-010).
5. Switch language id↔en: layouts hold, no truncated primary actions.
6. Change the store theme colour in Settings: accent updates across rail, buttons, chips.
7. Browser console: no errors or warnings on any page; run the contrast check over the token pairs.

## Phase 2a — Organisation foundation

1. Create two outlets with different timezones and cut-offs; assign `kasir01` to one only. As `kasir01`, request data from the other outlet by id → `404`, never a leak; the outlet selector shows only the permitted outlet.
2. Sale at 02:30 with cut-off 03:00 lands on the previous business date; at 03:30 on the new date.
3. Publish a tax rule with a future date; a sale today keeps the old snapshot; a sale after the date uses the new rule.
4. Create a reason code, use it in a void; the action stores the code and note.
5. Self-approval: create a request as user A and approve as A → `409 SELF_APPROVAL`; approve as B → ok.
6. `OutletIsolationMatrixTest` passes (enumerates every outlet-scoped table and every report query).
7. Migration rehearsal on populated data: every user's effective access identical before/after; stock totals preserved; counts of orders per day unchanged.

## Phase 2b — Shared server

1. Bring up the shared-deployment stack on a second host/network; reach it by HTTPS from a device outside the LAN.
2. Login throttling and audit show the real client IP (trusted proxy working); token expires per policy; CORS and security headers as specified.
3. Scheduler process running (`schedule:list` shows entries); named volumes keep uploads across container recreate.
4. Load test at the target outlet count with kitchen/floor polling: measure p95 latency and error rate against the agreed budget (recorded in the sub-feature plan).

## Phase 3 — Menu & stock

1. Create an item with two variants, a required modifier group and a recipe: cost and margin appear and change when an ingredient price changes (new recipe version kept).
2. Mark the item "86" in outlet A only; POS in A cannot order it, B can. Set a daily quota of 2: third sale blocked; resets next business date.
3. Stock count with blind entry: counter cannot see system quantities; counter cannot approve; approver posts the adjustment; balances and valuation match.
4. Transfer A→B: in transit shows on neither on-hand; B receives with a difference → movement with reason.
5. Wastage above threshold stays pending; stock unchanged until approved.
6. Sell a recipe item: ingredient `consumption` movements appear at the right location in the same transaction.

## Phase 4 — Purchasing

1. PR created by user A cannot be approved by A; B approves; consolidate into a PO; price ≠ contract demands a reason.
2. Receive 60% of lines: PO `partly_received`; receive the rest: `fully_received`; stock updated per line.
3. Invoice with a price difference → exception; approve with reason; return damaged goods → negative movement.
4. Supplier with halal certificate expiring in < 60 days is flagged and counted in the page figure.

## Phase 5 — Sales, cash, orders monitor, day close

1. Closed sale cannot be edited (`409 IMMUTABLE`); reprint carries "SALINAN" and counts toward the limit.
2. Return with stock decision; refund executes only after approval by another user; failed refund shows retry.
3. Staff meal over the per-employee limit needs approval; excluded from net sales.
4. Shift with variance beyond tolerance cannot finalise without approval + reason.
5. Day close with an open order and a pending QRIS: blocked, both blockers listed; clear them, close; reopen with a reason; late write to the closed date → `409 BUSINESS_DAY_CLOSED`.
6. Orders monitor shows open orders and table board; no create/edit actions exist.

## Phase 6 — Dashboard, search, bell

1. Dashboard figures equal the sums of the underlying lists for the same filter; day-over-day uses the same time of day yesterday.
2. Search "meja 4", an order number, a member name and an item name: results respect the user's outlets and permissions.
3. Bell count equals the "Perlu tindakan" count; clicking opens the page that resolves it.

## Phase 7 — Customers, reports, people

1. Member crosses a tier threshold: tier changes, earn rate follows; manual point adjustment needs a reason; ledger shows who/when/why.
2. Member list masks phone/email for a user without `prv.pii.reveal`; export needs a reason and is logged; anonymise keeps transaction totals.
3. Schedule REP-001 weekly to two recipients; run the scheduler; file appears in export history with expiry.
4. Custom role: edit permissions in the matrix; read-only role cannot receive write permissions; five wrong approver PINs lock the account.

## Phase 8 — POS operations

1. Register a device with a one-time code; reuse after use or after 15 minutes fails.
2. Publish a menu release; device list shows applied versions and "tertinggal" for offline devices; roll back with a reason.
3. Send duplicate and conflicting device events: idempotent by (`device_id`,`seq`); each conflict lands in Sync Exceptions with the right code; nothing merges silently.
4. Lower an offline approval limit above the online limit: system lowers it and says so.

## Evidence to record per phase

Test run summary (backend + frontend), build chunk sizes, the browser walk-through results with console status, and for migrations the rehearsal result on populated data. A phase is not "done" without them (constitution II).
