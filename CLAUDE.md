# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BoothPOS — a POS system for event-based multi-artist merchandise booths, sold as a **one-time license installed locally per store**. Laravel API + Vue SPA both run on one machine and are reached over `localhost`; there is no cloud tier, no separate frontend server, and no multi-tenancy. "Production" means a shopkeeper's laptop at an event venue.

Docs in `docs/` are the spec source of truth: `PRD-POS-Event-Multivendor.md`, `openapi-pos-mvp.yaml`, `schema-pos-mvp.sql`, `wbs-pos-mvp.md`, `uml-pos-mvp.md`. `docs/RUNBOOK.md` is the operational command reference; `README.md` carries the narrative history and the full list of bugs found during execution.

## Commands

```bash
# Backend
php artisan test                          # full suite (167 tests)
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

PRD §10.2/§10.3 explicitly **cut** these from MVP — do not build them even if scaffolding hints at them: vendor management, purchase management, materials/production, flash sale, QR/barcode scanning, granular custom roles, artist self-service portal, printed/PDF catalog, and Excel import *of sales transactions* (PRD F15.9).

**Excel import of master data was un-cut on 2026-09-01** at the product owner's explicit request and is now built (see "Master-data Excel export/import" below). PRD §10.2, §7.15 and README carry dated notes rather than rewritten history — don't "restore" the old scope cut when you read those.

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

## Conventions

- **Code comments, docs, commit messages, and UI copy are in Indonesian.** Comments explain *why*, often citing the PRD clause or the bug that motivated the code; several carry a `BUG YANG DITEMUKAN & DIPERBAIKI` header. Match this style.
- Seeded dev accounts (`php artisan db:seed`): `owner`, `admin`, `kasir01`, `kasir02`, `inventory` — all `password123`, local only.
- No git remote is configured; nothing is pushed.

<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan
<!-- SPECKIT END -->
