# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BoothPOS — a POS system for event-based multi-artist merchandise booths, sold as a **one-time license installed locally per store**. Laravel API + Vue SPA both run on one machine and are reached over `localhost`; there is no cloud tier, no separate frontend server, and no multi-tenancy. "Production" means a shopkeeper's laptop at an event venue.

Docs in `docs/` are the spec source of truth: `PRD-POS-Event-Multivendor.md`, `openapi-pos-mvp.yaml`, `schema-pos-mvp.sql`, `wbs-pos-mvp.md`, `uml-pos-mvp.md`. `docs/RUNBOOK.md` is the operational command reference; `README.md` carries the narrative history and the full list of bugs found during execution.

## Commands

```bash
# Backend
php artisan test                          # full suite (214 tests)
php artisan test --filter=PreorderTest    # one file
php artisan test --filter=test_arrived_status_increases_stock   # one test
php artisan migrate && php artisan db:seed
php artisan serve                         # :8000

# Frontend
npm test                                  # Vitest, 44 tests, no backend needed (APIs are vi.mock'd)
npm run test:watch
npm run build                             # → public/build, required before Laravel can serve the SPA
npm run dev                               # Vite :5173, proxies /api → :8000

# Backup / restore (WBS 9.2)
php artisan app:backup
php artisan app:restore <path/database.sql> [--force]
```

## Non-negotiable environment constraints

- **MySQL 8 is required; SQLite hard-fails.** `create_orders_and_payments_tables` and `create_preorders_tables` use raw `DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')`, which SQLite cannot execute. `phpunit.xml` deliberately does *not* pin `DB_CONNECTION` so that `.env.testing` decides — don't "helpfully" add a sqlite default to it.
- **`.env.testing` must exist and point at a separate database** (`boothpos_test`). It is gitignored. The suite runs `RefreshDatabase`, so pointing it at the app DB destroys real data.
- On this dev machine MySQL lives only in the `laradock-mysql-1` Docker container (`127.0.0.1:3306`). **Do not `brew install mysql-client`** — that was done once and deliberately reverted. If a CLI tool like `mysqldump` is needed, proxy through `docker exec`, and keep that shim out of committed code.
- **Migration filename date prefixes are load-bearing** for FK order. Never rename or reorder them. `payments.preorder_id` is intentionally created *without* a constraint in `orders_and_payments`, then constrained later in `preorders_tables` via `Schema::table()`, because `preorders` doesn't exist yet at that point.

## Architecture

**Business logic lives in `app/Services/`, not controllers.** `OrderService`, `PreorderService`, `StockService`, `SettlementService`, `PaymentRecorder`, `ActivityLogger`, `ProductCodeGenerator`. Controllers validate, delegate, and shape responses. Prices, totals, and stock deltas are always computed server-side — client-supplied amounts are never trusted.

**Authorization is split across three mechanisms.** Before concluding an endpoint is unguarded, check all three:
1. `FormRequest::authorize()` (17 request classes) — e.g. `StockAdjustmentRequest` gates stock adjustments via `canManageMasterData()`
2. Inline `$request->user()->isOwnerOrAdmin()` in 6 controllers (`ReportController`, `OrderController`, `CashierSessionController`, `PaymentProofController`, `PaymentChannelController`, `ActivityLogController`)
3. Policies in `app/Policies/` (Artist, Category, Customer, Event, Product, Setting)

Roles are `owner`, `admin`, `cashier`, `inventory`, with two helpers on `User`: `isOwnerOrAdmin()` and `canManageMasterData()` (owner/admin/inventory). Some object-level checks are ownership-based rather than role-based (a cashier may close/summarize only their own session).

**Pro vs Master licensing** is one setting, `multi_artist_enabled`. All the logic is in `app/Support/LicenseGate.php` and enforced in `ArtistPolicy::create` — not in controllers, and not in the frontend, where the check is cosmetic only. `Setting::get()` caches with model-event invalidation; note the deliberate `filter_var(..., FILTER_VALIDATE_BOOLEAN)` rather than a `(bool)` cast, because `(bool)"false"` is `true` in PHP.

**Stock movement invariants** (`stock_movements.type`: `purchase`, `sale`, `preorder_handover`, `adjustment`, `return`, `initial`). The preorder lifecycle is the easiest thing in this codebase to get backwards:
- Creating a preorder does **not** touch stock (goods don't physically exist yet)
- `arrived` → `purchase` movement, stock **increases**
- `handed_over` → `preorder_handover` movement, stock **decreases** (blocked with 409 unless fully paid)

Stock rows are append-only history; `current_stock` is maintained alongside them by `StockService::applyMovement()`, which is the only sanctioned write path — including for the bulk Excel import.

**`GET /reports/artist-settlements` lists every *active* artist, not only those with sales.** The settlement rows themselves still come only from `GROUP BY order_items.artist_id`, so zero-earning artists are left-joined in at report time with `id: null` and all money `"0.00"`. Use `artist_id` (always present) as the UI row key, not `id`. Inactive/soft-deleted artists appear only if they hold a settlement row for that event.

**`GET /products` omits `variants` unless `?with_variants=1`.** Opt-in on purpose: the product management screen doesn't render variants, the POS does.

**Payment proofs are uploaded before the payment exists.** `POST /payment-proofs` stores the file on the private `local` disk (`storage/app/private/payment-proofs`, never public) and returns a `proof_token`; the subsequent `POST /orders` or preorder payment call carries that token and links the record. Files are served only through an authorizing endpoint.

**Order idempotency** uses a client-generated `local_ref` UUID on `POST /orders`.

**Activity log (F13.4)** writes only through `ActivityLogger`, and always *inside* the same transaction as the mutation it records, so a rolled-back delete never leaves a log claiming it happened.

## API conventions

- **Status codes are meaningful and the frontend depends on them**: `422` shape/validation errors, `409` business-rule conflicts (insufficient stock, session already open, invalid status transition, delete guards), `403` role/ownership denial.
- **Money is returned as a string**, always `number_format((float) $x, 2, '.', '')`.
- **Pagination envelope** is hand-rolled in several controllers: `{"data": [...], "meta": {current_page, per_page, total, last_page}}`.
- **Two response-shaping styles coexist**: `JsonResource` classes in `app/Http/Resources/`, and hand-rolled private `present()`/array builders in `PreorderController` and `ReportController`. The `present()` style guards relations with `relationLoaded()` — if a caller forgets to eager-load, the field silently vanishes from the response rather than erroring. That exact pattern caused a real bug (customer/payments/shipment missing from every preorder response); when touching these, verify the service's `load()`/`fresh()` call includes what `present()` reads.
- `docs/openapi-pos-mvp.yaml` must move in the same commit as any route/response change (PRD §9.5).

## Frontend

Vue 3 SPA (`resources/js/`: `api/`, `stores/` (Pinia), `router/`, `composables/`, `components/`, `views/`), built by Vite into `public/build`, served by Laravel through a catch-all in `routes/web.php` that excludes `/api`. Axios always calls relative `/api/v1/...` — same origin, no base URL config.

- **Tailwind v4, CSS-first.** There is no `tailwind.config.js`; design tokens are `@theme` CSS variables in `resources/css/app.css`. Components use token classes — **no raw hex literals**.
- Plus Jakarta Sans + Phosphor Icons (duotone). This product deliberately does **not** use Mekari Pixel: it ships to external customers, so no internal design-system dependency belongs in it.
- **Frontend tests live in `qa-tests/`; backend tests in `tests/Feature/`.**
- One central error handler maps the 422/409/403/401 convention above; `usePaginatedList` handles the pagination envelope. Reuse them rather than re-implementing per screen.
- Login posts a **`username`**, not an email.

## Scope discipline

PRD §10.2/§10.3 explicitly **cut** these from MVP — do not build them even if scaffolding hints at them: purchase management (PO to vendors), full production/manufacturing scheduling, flash sale, QR/barcode scanning, granular custom roles, artist self-service portal, printed/PDF catalog, and Excel import *of sales transactions* (PRD F15.9).

**Excel import of master data was un-cut on 2026-09-01** at the product owner's explicit request and is now built (see "Master-data Excel export/import" below). PRD §10.2, §7.15 and README carry dated notes rather than rewritten history — don't "restore" the old scope cut when you read those.

**Vendor/material/BOM tracking was added post-MVP on 2026-09-01** at the product owner's explicit request — see "Vendor, material, and BOM tracking" below. This is a genuinely new capability, not a resurrection of the PRD §10.2 "vendor management" or "materials/production" cuts: it's deliberately narrower than either (no purchase orders, no production scheduling) and doesn't map to any existing F-number. PRD §10.2 carries a dated note rather than rewritten history.

Some mockup elements in `docs/UI-mockups/BoothPOS.dc.html` have no backend and are intentionally left unwired: the receipt PDF button and the Settings "Cadangkan sekarang" button (`app:backup` is CLI-only). The generic "Ekspor .xlsx" buttons on master-data tables now DO have a backend (`GET /exports/{entity}`) but are not wired up in the SPA yet. The mockup's embedded JS also contains fabricated demo data and discount rules — none of it is real logic.

## Master-data Excel export/import (PRD 7.15)

`GET /exports/{artists|categories|products|stock}`, `GET /imports/master-data/template`, `POST /imports/master-data`. All three gated on `canManageMasterData()` — deliberately stricter than the per-entity read endpoints, because bulk file extraction is not a cashier need.

Non-obvious rules, all enforced in `app/Services/MasterDataImportService.php` (read its docblock before changing anything):

- **One workbook, four sheets**, always processed in dependency order (`artists` → `categories` → `products` → `stock`) regardless of physical sheet order. Sheet names match case-insensitively; unrecognised sheets are ignored, not an error.
- **All-or-nothing**: full validation pass, then one transaction. This deliberately contradicts PRD F15.5's "keep the 97 valid rows" acceptance criterion — the reasoning is written out in the service docblock and in PRD §7.15. `dry_run=1` gives the F15.4 preview through the identical validation path.
- **Stock column is absolute, not a delta.** The delta is computed server-side and applied via `StockService::applyMovement()` (type `adjustment`) — never a direct `current_stock` write. A row matching current stock writes no movement at all.
- **Initial stock for *new* variants belongs on the `products` sheet (`initial_stock`)**, because SKUs are server-generated. The `stock` sheet may still reference a SKU the same file will create (SKUs are deterministic: `code_prefix` + 4-digit sequence) — that resolution is deliberately deferred to apply time, after the products sheet. A SKU that still doesn't resolve rolls the whole import back with a normal per-row error. This is what makes the shipped template importable as-is; `test_the_shipped_template_imports_as_is` guards it, so re-run it if you change the example rows in `MasterDataSheets::exampleRow()`.
- **Upsert keys**: `artists.code`, `categories.code`, `stock.sku`; products by `sku` when filled, else `code_prefix` + `variant_name`. One `products` row = one *variant*.
- **A blank cell means "leave unchanged"**, not "clear the value".
- **The Pro/Master license quota is re-checked inside the import** — `ArtistPolicy` is not on this path, so without that check a spreadsheet would be a free licence upgrade.
- Sheet names and column headers come from one place, `app/Support/MasterDataSheets.php`, shared by export, template, and import — so export files round-trip back through import. Don't fork that list.

## Vendor, material, and BOM tracking (added post-MVP, 2026-09-01)

Tracks which vendor(s) sell a raw material and at what price, and what a
product variant's BOM (Bill of Materials) actually costs in materials.
`GET|POST|PUT|DELETE /vendors`, `/materials`, plus
`POST/PUT/DELETE /materials/{material}/vendor-prices` (attach/update/detach
a vendor's price for a material) and `POST/PUT/DELETE /variants/{variant}/bom`
(attach/update/detach a BOM line) and `GET /variants/{variant}/cost-breakdown`.
All gated on `canManageMasterData()`, same tier as Products/Categories/Stock.

- **BOM is keyed to the product *variant*, not the parent product** —
  different variants of the same product (e.g. keychain sizes) can need
  different quantities/materials, and `ProductVariant` is already the
  first-class entity for per-SKU data (price, stock) in this codebase.
- **A material can have prices from multiple vendors** (`vendor_material_prices`,
  unique on `(vendor_id, material_id)`). One vendor per material may be
  flagged `is_preferred`; flagging one automatically unflags any other for
  the same material (enforced in `MaterialController`, not a DB constraint).
- **`bom_cost` is a separate, read-only figure — it never writes to
  `cost_price`.** `cost_price` already feeds the profit report and artist
  settlements throughout this codebase; silently overwriting it from BOM
  data would be a correctness risk to code that's already tested elsewhere.
  See `App\Services\BomCostCalculator`'s docblock for the full rationale.
- **Price selection when a material has >1 vendor**: the vendor flagged
  `is_preferred`, else the *cheapest* price (a defensive/optimistic default
  for a cost estimate, not a purchasing recommendation) — documented in
  `BomCostCalculator` and `Material::referencePrice()`, don't duplicate that
  logic elsewhere.
- **Delete guards** mirror Artist/Category: a vendor referenced by any
  `vendor_material_prices` row, or a material referenced by any
  `vendor_material_prices` row or BOM line, cannot be deleted (409).
- **Excel import/export**: `vendors`, `materials`, `vendor_prices`, `bom`
  are four more sheets in the *same* combined master-data workbook
  (`MasterDataSheets::ORDER`), processed after `stock` in dependency order.
  `vendor_prices`/`bom` reference vendors/materials/variants by `code`/`sku`,
  the same pattern as `artist_code`/`category_code` on the `products` sheet.
  `bom` rows may reference a SKU created by the `products` sheet in the same
  file (resolved at apply time, same deferred-resolution pattern as `stock`).

## Seed data and DEMO/LIVE mode (added post-MVP, 2026-09-03)

Every business/transactional model (Event, Artist, Category, Product,
ProductVariant, Customer, Vendor, Material, VendorMaterialPrice,
ProductVariantBomLine, CashierSession, Order, OrderItem, Preorder,
PreorderItem, Shipment, Payment, PaymentProof, StockMovement,
ArtistSettlement — 20 in total) uses the `App\Models\Concerns\HasDataMode`
trait: a `data_mode` column (`demo`/`live`) auto-stamped at creation from
`App\Support\ModeGate::current()`, filtered on every read via a global
`DataModeScope`. **When adding a new model that represents business or
transactional data, add this trait** — omitting it means the model
silently ignores the DEMO/LIVE boundary. `users`, `roles`, `settings`,
`activity_logs`, and `payment_channels` deliberately do NOT use it
(administrative data, visible identically in both modes).

- **Active mode** is one more `settings` row (`system_mode`, `demo`/`live`,
  default `live`), read via `ModeGate::current()` — same pattern as
  `multi_artist_enabled`/`LicenseGate`. Changed through the existing
  `PUT /settings` bulk endpoint (no dedicated route); surfaced to the
  frontend via `GET /settings/features`'s `system_mode` field, visible to
  every role, changeable only by owner/admin (`canAccessMenu('settings')`).
- **`ModeGate::runAs($mode, $callback)`** temporarily overrides the active
  mode for the duration of a callback — used by `SakanaFridgeDemoSeeder`
  (`php artisan db:seed --class=SakanaFridgeDemoSeeder`, not run by the
  base `DatabaseSeeder`) so seeded rows are always `data_mode = 'demo'`
  regardless of whatever `system_mode` is currently persisted. Business
  services (`OrderService`, `PreorderService`, `StockService`) are
  completely unaware of DEMO/LIVE — they just call `Model::create()`, and
  the trait does the stamping.
- **Hand-rolled `DB::table(...)` queries bypass the Eloquent global
  scope.** `ReportController` (`sales()`, `profit()`, `artistProfit()`,
  `exportArtistSettlements()`) and `SettlementService::recalculateForEvent()`
  all filter `order_items.data_mode` explicitly for this reason — if you
  add another raw query touching one of the 20 tables above, it needs the
  same explicit filter, or the report will (in the un-filtered case) sum
  across both modes.
- **A value with a database-wide UNIQUE constraint must count/check
  across BOTH modes, not just the active one** — `OrderService::
  generateOrderNumber()`, `PreorderService::generateNumber()`, and
  `ProductCodeGenerator::buildCodePrefix()` all use
  `withoutGlobalScope(DataModeScope::class)` for exactly this reason
  (`order_number`/`preorder_number`/`code_prefix` are unique across the
  whole table, not per mode — a naive per-mode count lets a DEMO and a
  LIVE row collide on the same generated value).
- **A foreign key that isn't re-validated through a scoped Eloquent
  lookup can smuggle a cross-mode reference.** `variant_id`/`session_id`
  are safe because their services call `findOrFail()` against a
  `HasDataMode` model (404s automatically if it belongs to the other
  mode). `customer_id` was NOT re-validated this way (it went straight
  into `Order`/`Preorder::create()`, and the FormRequest's `exists:` rule
  bypasses Eloquent scopes same as the uniqueness checks above) —
  `OrderService`/`PreorderService::create()` now re-fetch the customer via
  `Customer::findOrFail()` before writing, specifically to close this gap.

## Conventions

- **Code comments, docs, commit messages, and UI copy are in Indonesian.** Comments explain *why*, often citing the PRD clause or the bug that motivated the code; several carry a `BUG YANG DITEMUKAN & DIPERBAIKI` header. Match this style.
- Seeded dev accounts (`php artisan db:seed`): `owner`, `admin`, `kasir01`, `kasir02`, `inventory` — all `password123`, local only.
- No git remote is configured; nothing is pushed.

<!-- SPECKIT START -->
Active feature plan: `specs/015-dockerize-dev-environment/plan.md`
(branch `015-dockerize-dev-environment`, branched from `main`) — a Docker
Compose setup (`mysql`+`app`+`node` services) reproducing the existing
native dev workflow (`laradock-mysql-1` + `php artisan serve` +
`npm run dev`) as one reproducible, one-command stack — **local
development tooling only**, explicitly NOT a new store-deployment channel
(clarified before speccing; "production" still means a native,
Docker-free install on the shopkeeper's machine, per this file's own
opening description). PHP image pinned to 8.3 (composer.json's declared
floor, not whatever the host happens to have — research.md R2); required
PHP extensions traced to `maatwebsite/excel`'s own declared requirements,
not guessed (R3); one MySQL container seeds both `boothpos`/`boothpos_test`
databases via an init script, exactly mirroring this file's own existing
two-database convention (R4); `vendor/`/`node_modules/` get anonymous
volumes so the dev bind-mount doesn't shadow container-installed
dependencies (R5); `vite.config.js`'s dev-server proxy target becomes
configurable via an env var that defaults to today's exact hardcoded
value, so the native (non-Docker) workflow is provably unaffected (R6);
migrations run automatically on container start (idempotent), but
seeding (`SakanaFridgeDemoSeeder`) stays a deliberate manual step, not
auto-run (R7); the Docker path's env file is NOT a copy of the existing
`.env.example`, which is stale stock Laravel boilerplate defaulting to
SQLite (R8). See research.md R1–R8.

Previous feature: `specs/014-sales-receipt-event-footer/plan.md`
(branch `014-sales-receipt-event-footer`, branched from `main` — 012/013
already merged, shipped, PR #11 merged) — two small additive changes: restoring the "View
receipt" action on the Sales list (removed as a *trigger* during `009`'s
redesign in favor of the products-sold popup, but `ReceiptModal.vue` and
`GET /orders/{id}/receipt` were never touched, so this is a pure frontend
rewire, zero backend change, and purely additive alongside the existing
products-sold action); and event name/location/dates added to the footer
of both the POS receipt and the pre-order invoice/receipt, needing one
new `Preorder::event()` relation (mirroring the already-existing
`Order::event()`) since a preorder's event is optional and Order's isn't.
No shared footer component extracted for what is currently only two call
sites (research.md R4). See research.md R1–R4.

Previous feature: `specs/013-preorder-list-filters-receipt/plan.md`
(branch `013-preorder-list-filters-receipt`, branched from
`012-seller-preorder-report-detail-export`, PR #10 open) — five additive changes to the
Pre-orders screen, none requiring a schema change: a seller filter + a
visible seller column/detail (finally surfacing `preorder_items.artist_id`,
which already existed but was never exposed as a relation or in any
response — a preorder can span more than one seller, same as an
order/POS cart); making the transaction number clickable to open the same
detail view its existing "Detail" button already opens; restyling
`PreorderInvoiceModal.vue` to match the POS receipt's visual conventions
while showing the preorder's live granular status, reusing the
"Pre-order marking + StatusPill" pattern `PreorderPaymentReceiptModal.vue`
already established in `010` (this fully absorbs the dormant, unplanned
`011-preorder-invoice-receipt-style` spec — `011` needs no separate
implementation); renaming the two "Print"-wording locale keys actually
used on this screen to "Receipt" wording, in both languages; and a new
`GET /preorders/summary` aggregate endpoint (transaction count, per-status
totals, grand total, outstanding), computed from the same filtered query
`index()` already builds so it can never disagree with what's on screen —
deliberately reusing `Preorder.total_amount`/`paid_amount` directly rather
than the artist-proration recognized-revenue rule `010`/`012` use
elsewhere, since that rule solves a different, per-seller-attribution
problem this preorder-level summary doesn't have. See research.md R1–R7.

Previous feature: `specs/012-seller-preorder-report-detail-export/plan.md`
(branch `012-seller-preorder-report-detail-export`, branched from
`010-split-payment-preorder-reports` — not `main` — since it directly
extends that feature's report tabs) — four additive changes: merging
preorder-sourced transactions into `artistSettlementTransactions()`'s
per-seller drill-down (today it queries only `OrderItem`, so the Seller
Recap total already includes preorder revenue per `010` but its own
detail view can't explain that total — also closes a real, unrelated gap
found while touching this method: it had **no `data_mode` filter at
all**); adding a per-artist breakdown to `GET /reports/preorders` — a
genuinely bigger change than "add a GROUP BY column," since that query
currently has zero join to `preorder_items` and aggregates at the
`preorders` header level, so the breakdown requires the same
cash-collected/proration rule `010` established, applied one level
deeper; a new on-demand drilldown modal (mirroring `009`'s
`StockByArtistDetailModal.vue` pattern) that lists the individual
preorders behind any Pre-order report row and opens them via
`PreordersView.vue`'s already-existing `route.query.preorder_id`
deep-link rather than new navigation; and Excel export added to the three
report tabs that had none (Purchases, Stock by Seller, Pre-order) — the
first two via plain `GenericArrayExport`, Pre-order via the
`MultiSheetArrayExport`/`SheetArrayExport` two-sheet pattern already
established by `exportArtistSettlements()`, since its export must carry
both the summary and the new per-seller breakdown. See research.md R1–R6
for the full reasoning.

Previous feature: `specs/011-preorder-invoice-receipt-style/plan.md`
(branch `011-preorder-invoice-receipt-style`, branched from `main`) —
restyles the preorder invoice document (`PreorderInvoiceModal.vue`) to
match the POS sale receipt's visual layout (store header, dashed-line
itemization, prominent total, download-as-image/PDF), while keeping it
unmistakably marked as a preorder with its live current status — a
restyle of an already-related component (it already documents itself as
following `ReceiptModal.vue`'s pattern), not a rebuild, and explicitly
scoped to the invoice document only (not `010`'s separate per-payment-event
receipt).

Previous feature: `specs/010-split-payment-preorder-reports/plan.md`
(branch `010-split-payment-preorder-reports`) — six changes: making the
already-existing multi-entry split-payment capability actually visible at
POS checkout (today `PaymentPanel.vue` gives zero on-screen affordance
that splitting is possible before a user stumbles into it) and making it
actually *work* for Preorders (today `PaymentPanel`'s `mode="record"`
branch never accumulates entries — every submit sends exactly one payment,
and `POST /preorders/{id}/payments` only ever accepted one payment object
per call; fixed by having the frontend accumulate entries and submit them
as sequential calls to that same, unmodified endpoint — no new batch
endpoint, see research.md R2); row-hover highlight added to `DataTable.vue`
plus four other components that render their own raw `<table>` outside it;
a new `PreorderPaymentReceiptModal.vue` (POS-receipt-styled, clearly
marked "Pre-order" + status, one receipt per payment event) built off
`GET /preorders/{id}`'s already-loaded `payments` relation — deliberately
NOT a reuse of `ReceiptModal.vue` (order-specific fields don't map) or
`PreorderInvoiceModal.vue` (a different document, the order confirmation);
Preorder revenue merged into `sales()`/`profit()`/`artistSettlements()`
using only cash actually collected (summed live from `payments`, never
from the `preorders.paid_amount` cache, which can drift), prorated across
a preorder's items/artists by each item's value share — the one genuinely
non-obvious design decision in this plan, since a naive merge of full
`preorder_items.line_total` would overcount an unpaid or partially-paid
preorder's revenue; and a wholly new `GET /reports/preorders` endpoint
(no prior aggregate existed) grouping by status × payment-completeness.
See research.md R1–R7 for the full reasoning.

Previous feature: `specs/009-ui-ux-refinements/plan.md` (branch
`009-ui-ux-refinements`, PR #8 open, not yet merged to `main` as of this
plan) — login/navbar cleanup, Sales page popup redesign, Artist→Penjual/
Sellers label rename, guarded Event/Customer delete, customer transaction
history, Dashboard per-customer stats, stock-by-artist drilldown, and
removal of the Settings Data Backup section. See that plan for the
`Customer::orders()/preorders()` and `Event::preorders()` relations it
introduced, and the `group_by=customer` addition to `GET /reports/sales`
that `010`'s `sales()` preorder-merge work builds on top of.

Previous feature: `specs/008-android-installer/plan.md` (branch
`008-android-installer`) — a **fully standalone** Android tablet build of
BoothPOS: the entire existing PHP/Laravel + Vue app runs unmodified,
on-device, with zero network dependency for core operation — NOT a thin
client to a Mac/PC (that's a materially different, rejected
interpretation; see spec.md's Input line and Assumptions). Achieved by
bundling a statically-built PHP runtime + a statically-built **MariaDB**
(not MySQL, not SQLite — MySQL publishes no Android/ARM builds; MariaDB
is wire/DDL-compatible including the `CHECK`-constraint migrations
CLAUDE.md already documents as SQLite-hard-fails) inside a thin native
Android shell (`android/`, new top-level dir) that launches both as a
foreground `Service` and displays the existing Vue SPA in a `WebView`
pointed at `127.0.0.1` — zero rewrite of `app/`/`resources/js/`, zero new
business-logic implementation, avoiding a second, inevitably-diverging
copy of every domain rule. Backup/restore reuses `BackupPos`/`RestorePos`
unmodified (same `mysqldump`+`tar` archive shape on both platforms — see
`contracts/backup-format.md`), only swapping "where the file ends up" for
Android's Storage Access Framework instead of `BACKUP_EXTERNAL_PATH`.
Flagged, not hidden: MariaDB's GPLv2 redistribution obligations, and that
`mysqldump`/`tar`/`cp` (shelled out to by the existing backup commands)
aren't present on Android by default and must be bundled too. See
research.md R1–R7 for the full feasibility grounding, including two
rejected alternatives (a native Kotlin/Flutter rewrite; a SQLite port)
and why each was rejected.

Previous feature: `specs/007-preorder-import-export-notify/plan.md`
(branch `007-preorder-import-export-notify`, shipped, PR #6 merged
2026-09-03) — Pre-order search by
customer name, status-appropriate printable invoice/receipt (client-side,
mirroring `ReceiptModal.vue`/the PO invoice), export/import via a
**separate, single-sheet workbook** (not a fifth sheet in
`MasterDataSheets::ORDER` — pre-orders are transactional, not master,
data), and email notification on status change + on-demand resend,
backed by a new `preorder_notifications` audit table so a failed/skipped
send is always visible, never silent. Imported pre-orders always start at
`status = 'ordered'` with recorded (not re-priced) historical amounts —
a deliberate, documented exception to this codebase's usual "server
always recomputes money" rule, since import is backfilling orders that
already happened elsewhere, possibly at a different price than today's.
Email is sent synchronously (no queue worker exists on this
single-machine deployment) via Laravel's stock `Mail` facade configured
through `.env` `MAIL_*` vars — no new Settings-UI SMTP config in this
feature's scope. Export/import/resend are gated `isOwnerOrAdmin()`
inline, not a new menu key, since the existing `preorders` menu key is
already shared with cashier/inventory for base CRUD. See research.md for
the full grounding (R1–R7).

Previous feature: `specs/006-purchase-order-and-ops/plan.md` (branch
`006-purchase-order-and-ops`, shipped, PR #5 merged 2026-09-03) —
Purchase Orders, Store Customization,
Activity Log Screen, New Reports, POS Drafts, Per-Artist Opening Cash,
Split Payment: 10 independent slices. Deliberately reverses PRD §10.2's
"no purchase orders" cut (dated note, same pattern as the 2026-09-01
Vendor/Material addition) — new `purchase_orders`/`purchase_order_items`
tables + `PurchaseOrderService` (status: draft→ordered→received→paid,
+cancelled, mirroring `PreorderService`'s transition-guard pattern).
Materials have NO stock concept today (`StockService` is
`ProductVariant`-only) — adds a genuinely new, parallel
`materials.current_stock` + `material_stock_movements` +
`MaterialStockService`, not a fork of the existing variant stock path.
Split payment and payment notes are ~80% already built server-side
(`POST /orders` already accepts a `payments[]` array with cash-overpay
guards; `Payment.notes` is already a real column) — the gap is almost
entirely `PaymentPanel.vue`, which today hardcodes exactly one payment
entry. POS drafts are a loosely-validated JSON cart snapshot (not
normalized FK rows) so a since-deleted variant/customer degrades to a
flagged line, not a crash. Per-artist opening cash is additive —
`cashier_sessions.opening_cash` stays as the sum of a new
`session_opening_cash_entries` table, old sessions keep working unchanged.
Theme color is applied by setting the same `@theme` CSS custom properties
(`--color-brand` etc.) at runtime via `document.documentElement.style`,
not a second theming system. See research.md for the full grounding —
most of this feature's scope was discovered by reading the existing
code, not assumed from the request alone.

Previous feature: `specs/005-ux-enhancements-dashboard/plan.md` (branch
`005-ux-enhancements-dashboard`, shipped, PR #4 merged 2026-09-03) — UX
Enhancements: replaces the Products/POS artist/category chip filters
(added in 004) with a searchable multi-select dropdown
(`BaseMultiSelect.vue`, `GET /products`'s `artist_id[]`/`category_id[]`
now array-capable); dashboard shortcut tiles, a day-filterable sales
panel, and category/artist/event breakdown charts (`chart.js`) — reusing
the existing, already-tested `GET /reports/sales` (extended with an
`event` grouping) rather than a new `DashboardController`/
`DashboardService`, once research showed that endpoint already provided
everything needed; self-service Profile screen (`PUT /auth/password`,
`POST /auth/photo`, both self-scoped, deliberately not routed through
`UserController`'s admin-gated `{user}` routes); sidebar
"Purchase"→"Pembelian" fix, submenu-item color-consistency fix, and a
show/hide sidebar toggle (animated width transition, reveal button lives
in `AppTopbar.vue`'s flex row — not a fixed-position overlay, which used
to sit on top of the page title once the sidebar was hidden).

Previous feature: `specs/004-sidebar-menu-reorg/plan.md` (branch
`004-sidebar-menu-reorg`) — Sidebar Menu Reorg + Product Images &
Clickable Filters: frontend-only reorder of the sidebar (Sesi Kasir →
Sales → Purchase → Inventaris → Pre-orders) grouping Kategori/Produk/Stok
under a new "Inventaris" collapsible parent and Vendor/Bahan Baku under a
new "Purchase" parent (same group mechanism as the existing "Pengaturan"
group — no menu_key/route/authorization changes), plus product image
thumbnails (Products table + POS cards) and clickable artist/category
filter chips (replacing Products page's dropdowns, adding an artist chip
row to POS) with an explicit "All" option per axis. No backend changes —
`ProductResource.image_url` and `GET /products`'s `artist_id`/
`category_id` filters already existed before this feature.

Previous feature: `specs/003-seed-demo-live/plan.md` (branch
`003-seed-demo-live`) — Seed Data Dummy & Mode DEMO/LIVE: a one-time
idempotent seeder (`SakanaFridgeDemoSeeder`) populating a full realistic
dataset (event, 3 artists, 9 products × 3 variants + stock, 3 categories,
sales, 3 customers, 6 vendors, anime/game-merch materials, pre-orders) for
a store called "Demo Sakana Fridge", plus a store-wide DEMO/LIVE mode toggle
(`system_mode` setting + `App\Support\ModeGate`) that tags every
business/transactional row (`data_mode` column, `HasDataMode` trait +
global scope) with the mode active when it was created, so DEMO and LIVE
data never mix in any list, POS screen, or financial report. See that
plan's data-model.md for the exact list of ~19 affected tables and
research.md for the `ModeGate::runAs()` mode-forcing mechanism the seeder
relies on before touching any model that gains the `HasDataMode` trait.

Previous feature: `specs/002-language-toggle/plan.md` (branch
`002-language-toggle`) — Ganti Bahasa Antarmuka (Indonesia/English):
post-login language toggle stored per user account (`users.language`,
default English), full-app translation scope via `vue-i18n` on the
frontend and Laravel's `lang/`/`App::setLocale()` on the backend (neither
existed in this codebase before this feature). Login screen and
transaction receipts are explicitly excluded — always Indonesian. This
feature has a documented, justified conflict with Constitution Principle
III (Indonesian-only UI copy) — see that plan's Constitution Check and
Complexity Tracking before touching UI copy or error-message strings
while this feature is in flight.

Earlier feature: `specs/001-user-store-settings/plan.md` — Pengaturan
Pengguna dan Toko: user CRUD with photo/last-access/search/filter, a
fully configurable role/menu-permission system replacing the fixed
4-role model, expanded store profile, and bulk user export/import.
Shipped (PR #1); see that plan for the Role/menu_keys authorization
model design before touching authorization code.
<!-- SPECKIT END -->
