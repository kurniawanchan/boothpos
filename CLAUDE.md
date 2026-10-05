# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BoothPOS — a POS system for event-based multi-artist merchandise booths, sold as a **one-time license installed locally per store**. Laravel API + Vue SPA both run on one machine and are reached over `localhost`; there is no cloud tier, no separate frontend server, and no multi-tenancy. "Production" means a shopkeeper's laptop at an event venue — as of `016-docker-store-deployment` (dated note, 2026-09-05), that laptop MAY run BoothPOS via Docker instead of a native install; both are real, supported production paths, not just feature 015's dev-only tooling. See that feature's plan for the details.

Docs in `docs/` are the spec source of truth: `PRD-POS-Event-Multivendor.md`, `openapi-pos-mvp.yaml`, `schema-pos-mvp.sql`, `wbs-pos-mvp.md`, `uml-pos-mvp.md`. `docs/RUNBOOK.md` is the operational command reference; `README.md` carries the narrative history and the full list of bugs found during execution.

## Commands

```bash
# Backend
php artisan test                          # full suite (native/host only — see warning below)
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
- **Running `php artisan test` INSIDE the `app` Docker container is destructive unless you pass explicit `-e` overrides — this has actually wiped the dev database twice (020-customer-data-import-export, and again 2026-09-27).** `docker-compose.yml`'s `app` service uses `env_file: .env`, which injects `DB_DATABASE=boothpos` (the real dev DB) as a real OS-level environment variable inside the container. phpdotenv never overwrites an environment variable that's already set, so `.env.testing` — and even `-e APP_ENV=testing` alone — silently do **nothing**; `RefreshDatabase` then drops and rebuilds `boothpos` itself. The ONLY safe way to run tests inside the container:
  ```bash
  docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test
  ```
  `tests/TestCase.php::guardAgainstWrongDatabase()` is a hard technical backstop that refuses to run any test at all unless the resolved database name contains `test` — but treat that as a last-resort safety net, not permission to skip the `-e` flags above. If data does go missing, recover with `php artisan db:seed` + `php artisan db:seed --class=SakanaFridgeDemoSeeder` (docs/RUNBOOK.md §Mode C has the full recovery note).
- On this dev machine MySQL runs in the `boothpos-mysql-1` Docker container, exposed to the host at `127.0.0.1:3307` (container-internal port stays `3306` — that's what `mysql`/`DB_HOST=mysql` inside the `app` container resolves to). **Do not `brew install mysql-client`** — that was done once and deliberately reverted. If a CLI tool like `mysqldump` is needed, proxy through `docker exec`, and keep that shim out of committed code.
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
- **Bentuk kartu POS dan item keranjang (`buildProductCards`, `buildSearchCards`, `toCartItem`) hidup di `utils/posProductCards.js`.** Jangan membuat kartu dengan `map` inline di view: kartu hasil pencarian pernah dibuat begitu, memilih sebagian field saja, dan `image_url` yang sudah dikirim `GET /variants/lookup` terbuang sehingga kartu dan baris keranjang tanpa foto (feature 030).
- **Semua unduhan dokumen (invoice, payment invoice, surat jalan, struk, PO, invoice billing) lewat `utils/pdfCapture.js`.** Gambar ditukar ke data URL di klon html2canvas berdasarkan `src`-nya sendiri, **JANGAN PERNAH berdasarkan indeks**: argumen pertama `onclone` adalah klon SELURUH halaman, jadi gambar lain di halaman (avatar, thumbnail) menggeser indeks dan QR pembayaran tampil di slot logo (feature 029). Tes regresinya memakai gambar umpan (`qa-tests/unit/pdfCapture.test.js`). Dokumen invoice dan payment invoice pre-order didefinisikan SEKALI di `components/preorder/PreorderInvoiceDocument.vue` / `PreorderPaymentDocument.vue` — dipakai modalnya DAN unduh massal (`PreordersView.vue::mountBulkDocument`); jangan buat tata letak kedua (`utils/invoiceDocument.js` kini hanya berisi surat jalan).

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
- **`bom_cost` is a separate, read-only figure — it does not write to
  `cost_price`, EXCEPT for a variant whose BOM was explicitly marked
  COMPLETE (feature 034, see "BOM from purchase orders" below).** `cost_price`
  already feeds the profit report and artist settlements throughout this
  codebase; silently overwriting it from BOM data would be a correctness risk
  to code that's already tested elsewhere, so only the explicit "complete"
  action (via `VariantBomService`, never `BomCostCalculator`) syncs it. See
  `App\Services\BomCostCalculator`'s docblock for the full rationale.
- **Since 034 a BOM row normally references a PURCHASE ORDER LINE** (price =
  the recorded purchase price, vendor/PO traceable). The rows described here
  — material + quantity priced from the vendor price list — are now the
  **legacy** rows (kept, flagged, still costed this way).
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

## BOM from purchase orders (feature 034, 2026-10-05)

A variant's BOM is the traceable list of PURCHASE-ORDER LINES that produce it (seller → variant → BOM row → PO line → vendor → purchase cost). Rules (all in `VariantBomService` / `PurchaseOrderItem::eligibleForSeller()` / `BomCostCalculator`):

- **`VariantBomService` is the ONLY BOM writer** (add PO lines, qty, remove, replace-source, copy, complete, reopen, and the legacy create). Each call locks the variant row and, in ONE transaction, writes the rows, syncs `cost_price` if the BOM is complete, and writes the `activity_logs` row. Never write `product_variant_bom_lines` or a complete variant's `cost_price` anywhere else (the Excel `bom` sheet is the one bulk exception and only touches LEGACY rows of non-complete variants).
- **Seller rule:** a PO has ONE seller (`purchase_orders.artist_id`, required on create, NULL = legacy and never offered). A BOM row may only use a line of a PO whose seller = the variant's product's seller AND whose status is ordered/received/paid (drafts are excluded because their lines are deleted and recreated on every edit; cancelled POs aren't real purchases). The selector AND add/replace-source both use the single scope `PurchaseOrderItem::eligibleForSeller()` — the UI filter is not the boundary. A PO whose lines feed a BOM cannot change seller (409).
- **Rows store a SNAPSHOT** (item name, line type, PO number, vendor, `unit_cost`); a recorded cost never changes by itself — not on a PO price edit, a cancelled PO, or a vendor rename. Only the explicit `replace-source` action re-snapshots. "Newer price" and "source cancelled" are READ-TIME cues (one batched query, `sourceCues()`), never stored.
- **Row identity:** unique `(variant, purchase_order_item_id)` — the same PO line twice is refused (409) but two lines of the SAME material at different prices may coexist. `qty_needed` is per ONE finished unit (> 0, ≤ 4 decimals); the PO's purchased qty is shown for reference only (no consumption tracking yet).
- **Legacy rows** (`purchase_order_item_id` NULL) are derived, not flagged: kept, costed from the vendor price list live, counted as material cost, block "complete", replaceable one by one. The Excel `bom` sheet and `POST /variants/{v}/bom` still create/update them (one per material; a PO row of the same material does not conflict) and are refused for a complete variant.
- **Complete = cost price follows the BOM** (`product_variants.bom_complete`): needs ≥1 row, no legacy row, every row valid (qty > 0, source still the seller's); a cancelled source does NOT block. While complete `cost_price` = BOM cost, re-synced on every change (logged `cost_price_synced`) and locked: `PUT /variants/{id}` with a different value → 409 `cost_price_locked_by_bom` (the SPA sends the whole variant, so an unchanged value is accepted); the products Excel sheet likewise. Removing the last row auto-reopens (value kept); a copy onto a complete variant reopens it. Recorded `order_items`/`preorder_items` keep their own `cost_price` snapshot, so past reports never change.
- **Copy** (`/bom/copy`, `/bom/copy-out`, or `copy_bom_from_variant_id` on variant create): same product only, all-or-nothing, target with rows needs `confirm_replace`, never completes the target, independent afterwards (no shared template).
- **Authorization:** reading a BOM needs the `products` menu; EVERY other BOM action (incl. the selector, which exposes PO prices/vendors) needs `products` AND `purchase_orders` — one helper, `VariantBomController::authorizeBom()`. Typed business errors use `BomRuleException` (409 + machine-readable `code`).
- **`ProductVariantResource`** always carries `bom_complete`; `has_bom`/`bom_cost` appear only when `bomLines` was eager-loaded (`ProductController::variantRelations()`, products-menu users on show/update) — the `relationLoaded()` trap again.
- "Linked Product" is gone from the PO form but `purchase_order_items.product_id` stays (API still accepts it; the form carries an existing value through when a draft is edited).

## BOM quantities, batch save, variant history (feature 036, 2026-10-05)

- **BOM quantity is a WHOLE number ≥ 1 everywhere it is written** — one rule, `App\Rules\WholeBomQuantity` (accepts `2`, `"2"`, `2.0`, `"11.0000"`; refuses `1.5`, `0`, `-1`). It is NOT Laravel's `integer` (that rejects `"11.0000"`/`2.0`, exactly what the DB and Excel hand back). Used by `PUT /bom/{id}`, `POST …/bom/items`, the legacy `POST /variants/{v}/bom`, the batch save and the Excel `bom` sheet (per-row error, all-or-nothing import unchanged). **Stored legacy fractions are never rewritten** (still read and costed as stored; editing that row needs a whole number) — `BomCostTest` keeps a model-level `2.5` fixture on purpose.
- **Save is one all-or-nothing batch:** `PUT /variants/{variant}/bom` → `VariantBomService::updateQuantities()` (one lock, ids proven to belong to the variant BEFORE any write → 409 `bom_line_not_found`, unchanged values skipped, one `bom_qty_changed` audit row per changed line, `cost_price` re-synced ONCE when complete). Last writer wins (no optimistic version). The dialog keeps quantities as a DRAFT (no blur-save), shows an unsaved indicator and routes close / add / remove / replace / copy / complete / reopen through one `guard()` that asks before discarding. `summary.current_stock` is read-only — nothing in the BOM dialog changes stock or deducts materials. Cost labels say "per 1 product"; the maths is unchanged. A `watch(..., {immediate:true})` that resets state must come AFTER the refs it touches are declared (TDZ crash seen in 135 unrelated tests).
- **`GET /stock/movements` is the one ledger source** for the Stock screen and the per-variant history (`VariantHistoryModal`, opened from the variant row in `ProductDetailModal`). It is now gated to the `stock` OR `products` menu (it was open to every logged-in role) and returns `user_name` (the Stock screen's BY column was always blank — the OpenAPI promised it, the controller never sent it), `variant_name`, `product_id` (null when the product is deleted), `product_name`, and a resolved `reference {type: order|preorder, id, number}`.
- **`reference_id` means different things per writer**, so `StockMovementReferences` resolves by (movement type, reference_type) and proves the order/item really involves the movement's variant, else `null` (never a wrong number): `sale`+`order_item` = order id; `return`+`order_item` = order-item id; `purchase`/`preorder_handover`+`preorder_item` = pre-order-item id; `purchase`+`preorder` = pre-order id (written by the pre-order edit delta since 036; older edit rows were stored as `preorder_item` holding a pre-order id — ambiguous, resolved as an item with the variant guard). Known limitation: editing a pre-order after arrival rebuilds its items, so the earlier arrival movement points at a deleted item and shows no reference. `purchase_order_item` references exist only on MATERIAL movements.
- **`BaseSelect`** no longer closes when its own list scrolls (the window-level capture scroll listener used to catch it) and has an opt-in `searchable` mode (default off; the 8 other callers are unchanged). `BomCopyMenu` uses it.
- **i18n guard:** `qa-tests/unit/localeKeys.test.js` fails when any literal `t('a.b')` is missing from `en.json`/`id.json` (`master_data.col_type` was the only one — it surfaced as the raw header `MASTER_DATA.COL_TYPE`). Dynamic keys are not checked.
- Stock list: the SKU is a button (opens `ProductDetailModal` with the variant highlighted) only with the `products` menu, plain text otherwise; Products list: 56 px thumbnail and a one-line (truncated, titled) code.

## Variant drawer and BOM copy tool (feature 037, 2026-10-05)

- **Variant card (`ProductsView.vue` Edit-product drawer, `max-w-[1040px]`):** white bordered `rounded-card shadow-sm` cards on a `surface-subtle` tray. Header chips are colour-coded by role — SKU `sky`, markup `mint` (danger when negative), margin `violet` (danger when negative), BOM cost `warn` (only for a saved variant with a BOM) — via the new `--color-sky-*` / `--color-violet-*` `@theme` pairs (contrast ≥ 4.5:1; every chip keeps its text label). Header actions, right side, exact order: **Open BOM** (`BaseButton` inside `BaseTooltip`, saved variants only) · **Apply markup** · delete. Fields: name · stock · cost · sell. Picture 66 px (+50 %) with a same-size placeholder. `data-testid="variant-card"` / `"variant-header"` are used by the tests.
- **`BaseTooltip`** (`components/ui`): hover/focus tooltip (`role="tooltip"`, `aria-describedby`, Escape closes) — the native `title` is not keyboard-accessible; reuse it instead of `title` for explanatory text. **038:** the bubble is Teleported to `body` with `position: fixed` (below the trigger, flips above when < 90 px below, clamped to the viewport, closes on scroll/resize, never shown for blank `text`) because absolutely-positioned bubbles are clipped by scroll containers such as `DataTable`'s `overflow-auto`. The Products list uses it on every SKU (variant name); its first column is the picture (96 px) above the one-line code.
- **Duplicate variant** has NO endpoint: `duplicateVariantRow()` splices an UNSAVED card below the source (name `"<name> (copy)"`, prices, low-stock alert, status, and the source's STOCK — product-owner decision — with `original_stock = 0`), so saving reuses the new-variant flow: `POST /products/{id}/variants` with `copy_bom_from_variant_id` (the server copies the BOM in the same transaction, never marks it complete, and re-checks products + purchase_orders) and the shared stock-adjustment reason for the copied stock. Picture, SKU and history are never copied. BOM is promised only when the source is a saved variant WITH a BOM and the user has `purchase_orders`. `VariantDuplicateFlowTest` pins these server guarantees.
- **`BaseSelect` fits the screen:** the fixed panel flips upward when there is < 220 px below and more room above, and its height is capped to the available space (140–320 px). The reported "copy picker can't scroll to the bottom" was the panel running off the viewport (it always opened downward at a fixed height). Options may carry `thumb` (URL or `null`) to show thumbnails/placeholders — opt-in.
- **Copy to chosen variants:** `POST /variants/{v}/bom/copy-out` with `mode: "selected"` + `variant_ids[]` (`CopyBomRequest`, `VariantBomService::copyTargets()` loads exactly those ids — a missing one fails the whole request; `copy()` still enforces same product / not the source / confirm-replace / never complete). UI: `VariantPickList` (inline checkbox list with pictures, search, select-all of the VISIBLE rows, own scroll container) inside `BomCopyMenu`, behind the same unsaved-changes `guard`. **Add BOM item** shares one row with **Save changes** in `VariantBomModal` (`data-testid="bom-actions-row"`).

## App name ("Powered by") and the backup/restore screen (added post-MVP, 2026-09-30)

- **`app_name`** is one more `settings` row (single value for the whole install, NOT per DEMO/LIVE like `store_name`), read only through `App\Support\AppName::current()` — trimmed, blank means the default `BoothPOS`, max 50 characters (validated in `UpdateSettingsRequest`). It is exposed to every role via `GET /settings/features`, and rides along inside the invoice payloads (`BuildsInvoiceDocument`, so bulk downloads match the screen) and `GET /orders/{id}/receipt`. Frontend: `stores/settings.js` (`appName`), the sidebar brand, and a "Powered by {name}" line on the pre-order invoice, payment invoice, sales receipt and the bulk-download HTML (`utils/invoiceDocument.js`). The frontend default lives in `utils/appName.js` (`resolveAppName`) — keep it equal to `AppName::DEFAULT`. The bulk builder HTML-escapes the name (it is user-typed). Not applied to the billing `InvoiceDetailModal` (the vendor's licence invoice) or the license-key e-mail.
- **Backup/restore** lives in `App\Services\BackupService`, shared by `app:backup`, `app:restore` and `BackupController` (`/backups`, owner/admin only, `isOwnerOrAdmin()`). The `mysqldump`/`mysql` calls sit behind `App\Services\Backup\DatabaseDumper` (real implementation `MysqlCliDumper`, bound in `AppServiceProvider`). **The dev `app` image now bundles the MySQL client** (`default-mysql-client` + a `ssl=0` client config, same as the store image — Debian's MariaDB client otherwise rejects MySQL 8's self-signed certificate), so the screen works in dev; before that it failed with `sh: 1: mysqldump: not found`. Every test still swaps in `Tests\Support\FakeDatabaseDumper` — never call the real restore from a test, it overwrites the database. A real round trip was verified once against `boothpos_test` with `BACKUP_PATH` pointed at a temp dir.
- **Dev container identity (licence gate):** the licence activation binds to a fingerprint of `/etc/machine-id`, and a recreated container has none, so the app answered 423 "belum diaktivasi" after an image rebuild. `docker/php/entrypoint.sh` now writes the well-known dev value (the one `license:dev-activate` prints) when the file is missing, so activation survives `docker compose up --build`/`--force-recreate`. Tests also point `config('backup.path')` at a temp dir and use `Storage::fake('local')`, so they never touch real backups or payment proofs.
- **Restore safety rules (all enforced server-side):** the request must carry `confirm: "RESTORE"` (422 otherwise); a safety backup of the current state is taken FIRST and the restore is aborted if it fails; an uploaded file must look like this app's dump (has `settings` and `users` tables) — checked against a real `mysqldump`. Backup ids are validated against `BackupService::ID_PATTERN` (route `where` + service) before touching the filesystem. `app:restore` deliberately skips the safety backup so disaster recovery is never blocked by a dump that fails on a damaged database. Payment-proof files are backed up but never restored by the UI. A restore replaces the users/tokens tables too, so the frontend reloads (`utils/reloadApp.js`) and may land on the login page. `MysqlCliDumper::restore()` runs `mysql --binary-mode` so an uploaded file cannot run client commands (`\!` shell escapes); `MysqlCliDumperTest` asserts the flag — don't drop it.
- **A restore brings back `license_activations` too, on purpose** (product-owner decision, 2026-10-01): all data is restored as-is, and a dump from another machine (or one made before activation) leaves the app locked (423) until it is re-activated with a licence key. The restore dialog warns about this (`backup.restore_warning_license`); do NOT "fix" it by preserving the current activation rows across a restore.
- **Upload size:** `BackupController` allows 50 MB; `docker/php/uploads.ini` (`upload_max_filesize = 64M`) is installed in BOTH the dev and the store image so PHP doesn't reject the file first (the store image used to have no ini at all → PHP's 2M default). A PHP-rejected upload returns 422 with `backups.upload_failed`.
- **Deleting a backup** (`DELETE /backups/{id}`, `BackupService::delete()`) removes only the LOCAL folder (database.sql + proofs archive) — never the database, the real payment proofs, or the copy in `BACKUP_EXTERNAL_PATH` — and writes a warning log line with who/which. It reuses `sqlPath()` for id validation, so it is covered by the same path-traversal guard as download/restore. No typed confirmation (unlike restore): a plain confirm dialog is enough for a copy.
- **Shared-code fix worth knowing:** `useFocusTrap` no longer steals focus from an element the user already focused inside a dialog, and prefers a `[data-autofocus]` element for initial focus (the restore dialog uses it on the confirmation field).

## Sales page: POS transactions only — filters, export, void (added post-MVP, 2026-09-30)

- **The Sales page shows transactions created from the POS only. Pre-orders are deliberately NOT on it** (they live on the Pre-orders screen). An earlier iteration listed pre-orders as rows and was reverted at the product owner's request — don't reintroduce it. The 4 summary cards follow suit: they read `totals.pos_unit_count` / `pos_gross_sales` / `pos_net_sales`, while the shared `unit_count` / `gross_sales` / `net_sales` keep including recognised pre-order revenue (feature 010) because Dashboard and Reports rely on that. Older responses without `pos_*` fall back to the shared keys.
- **One source for the list AND its export:** `App\Services\SalesTransactionsService::build()` produces the rows for `GET /reports/sales` (`transactions[]`, `sessions[]`) and for `POST /reports/sales/transactions/export`, so the file can never differ from the screen. The client only sends row KEYS (`order:12`; `preorder:*` is rejected with 422) and the server rebuilds everything, ignoring keys outside the filters. Contact details (phone/email) are deliberately NOT exported.
- **Voided orders** are excluded by default and NEVER counted in `totals` or the on-screen summary; `include_voided=1` (a server param, toggled by "Tampilkan transaksi batal") lists them for audit. Voiding itself lives in `TransactionItemsModal` (button gated on the `settings` menu — the same rule `OrderController::void()` enforces), and the customer history screen reloads via the modal's `changed` event.
- **Cashier-safe by construction:** the page is open to every role, so cost/margin (`cost_total`, `margin_amount`, `margin_percent`) is added by the service ONLY when `canAccessMenu('reports')` (same gate as `profit()`), never hidden in the frontend. The Margin column exists only when rows carry it. `GET /orders/{id}` has no cost either (`SalesPageDataTest`, `SalesTransactionsTest`).
- **Money fields on rows:** `discount_amount` = item discounts + order-level discount (no double count: `orders.subtotal` is already net of item discounts); `cash_amount` = cash received MINUS change (rejected payments excluded); `payment_state` = worst verification among payments (rejected > pending > verified, `none` if unpaid). A shift's "expected cash" is opening cash + that shift's cash sales for an open shift, and the server-stored `expected_cash`/`closing_cash` for a closed one.
- **Filtering, sorting, summary, shift panel and URL sync are client-side** in `composables/useSalesFilters.js` over the loaded array (a booth event's list is small; no pagination). AND across filters, OR within one; empty values sort last in either direction; dates compare the user's LOCAL day (`toLocalDateKey`). State lives in the address bar (`?cashier=2&sort=total_amount…`); unknown/invalid values (including the retired `type` param) are ignored, not fatal. The "Showing X of Y…" line is exactly the filtered rows.
- **Not built, on purpose: return/refund of part of a sale.** `OrderService::void()` reverses a whole order (stock `return` movements). A partial return moves money, stock and artist settlements and needs business rules first (refund channel, effect on settlements/reports, per-item limits).
- **`TransactionItemsModal`** is reused by the customer history modal, so its receipt button opens `ReceiptModal` from inside itself. `OrderResource` additions are `whenLoaded`/null-safe.

## Pre-order duplicate and split (added post-MVP, 2026-10-01)

`POST /preorders/duplicate` (one or many ids) and `POST /preorders/{id}/split` (manual `items` or `by_seller`). Non-obvious rules, all in `PreorderService`:

- **Duplicate wraps `create()`** (`duplicate()`), it does not `replicate()`: the copy is re-priced at the variant's CURRENT price (product-owner decision), starts `ordered` with no payments/shipment/dispatch marker, and inherits create()'s numbering, discount cap, pickup-day/courier rules and DEMO/LIVE stamping. Stale input degrades (deleted event → no event, out-of-range pickup day dropped); a deleted/inactive variant or product FAILS that order. The endpoint always answers `200` with a per-order report (`bulk-email` pattern) — a failure is `status: failed`, not an HTTP error.
- **Split is refused (409) while the pre-order has ANY payment row** (product-owner decision: no payment-allocation logic), and for `handed_over`/`cancelled`. It also 409s if the original's discount would exceed its remaining subtotal + shipping. Discount, shipping, notes, shipment and the dispatch marker stay on the original. `ValidationException::status(409)` is how the service marks conflicts vs. plain 422s; the controller just returns `$e->status`.
- **Split never touches stock** — no `StockService` call. A whole line moves by re-parenting the same `preorder_items` row (stock movements reference its id); a partial line is shrunk and its snapshot cloned. The new order inherits `status`.
- Origin lives in `preorders.source_preorder_id` (nullOnDelete) / `source_type` / `source_preorder_number` (snapshot). `present()` returns `source` always and `split_children` only when `splitChildren` is eager-loaded (`show()` and the split response) — the usual relationLoaded trap.
- List rows carry `has_payments` (one `withExists('payments')` subquery) so the UI can disable Split without N+1. Activity-log actions: `duplicated`, `split` (written inside the transaction).
- Row actions: Split is hidden for closed statuses but DISABLED with a tooltip when paid; Duplicate exists for every status — which is why a handed-over row now has a "More" menu.

## Payment ledger — partial and split payments (added post-MVP, 2026-10-04)

Pre-orders AND POS sales share one payment ledger. Rules (all in `PaymentService` / `PaymentSummary`):

- **Single write path.** `PaymentRecorder::record()` is still the only creator of `payments` rows; `PaymentService::addPayment()/deletePayment()` wrap it for BOTH targets (row lock, closed-state guard, cache recompute, pre-order lifecycle, audit row — all in one transaction). `PreorderService::recordPayment()/deletePayment()` are thin delegates. Never `Payment::create()` elsewhere.
- **The summary is derived, never stored for orders.** `PaymentSummary::for($target)` = grand total / total paid / remaining / status (`unpaid|partially_paid|fully_paid`) / count. Rejected entries are excluded; non-cash `pending` entries count (nothing ever verifies them); for an ORDER total paid = Σ entries − `change_amount` (payments store the tendered cash). `preorders.paid_amount` / `orders.paid_amount` are caches rewritten from it (the order cache keeps the tendered semantic).
- **Overpayment is refused** (422 `errors.amount` naming the max; a fully paid or closed target is 409). This deliberately REVERSED the old "no overpay guard" pre-order behaviour — see the rewritten `PreorderTest` case.
- **Idempotency**: optional client UUID `client_ref` (SPA always sends it; reused on retry, regenerated per open). Same ref + same target → `200` replay, no new row; ref on another target → 422; `payments.client_ref` is UNIQUE across modes (lookup uses `withoutGlobalScopes()`).
- **Shift cash follows `payments.session_id`** (the shift the money was RECEIVED in), not `orders.session_id`. Checkout payments get the sale's shift; a later cash payment needs the recording user's OPEN shift (409 otherwise); non-cash records it if present; pre-order payments stay NULL (outside shift cash). `close()`, `summary()` and `SalesTransactionsService` (`sessions[].cash_received`, which also lists shifts that only received late payments) use it. Deleting a CASH payment of a CLOSED shift is refused (409) — it would break the stored reconciliation.
- **Partial POS sale** (`POST /orders` with paid < total) requires `customer_id` (409 otherwise — `OrderController::store()` maps every service `ValidationException` to 409). It stays `completed`, so every report still counts it at completion; only the Sales page shows `paid_amount` / `balance_amount` / `payment_status`. POS checkout payments are NOT written to `activity_logs` (the order is the audit trail); later payments and every delete are.
- `PaymentSummaryCard` + `PaymentHistoryList` + `AddPaymentModal` are shared by Pre-orders and the Sales detail modal; `RecordPaymentModal` (client-side accumulation) no longer exists. History entries' money (amount/method/channel/date) has no edit path; Delete is owner/admin only. What CAN change later is the *confirmation* (see below).

### Payment confirmation: optional proof, addable later (feature 031, 2026-10-04)

- **The proof (photo/file) is OPTIONAL** for non-cash sale and pre-order payments (reference and notes always were). `PaymentRecorder` no longer demands a `proof_token`, but a *supplied* token must be valid and unlinked, a non-cash payment must carry a `channel_id` (explicit 422/409 instead of the DB's `chk_payments_channel` 500), and **purchase-order payments keep the old rule** (non-cash still needs a proof). `PaymentPanel` mirrors it (Confirm no longer waits for a proof, only for an in-flight upload).
- **Confirmation path**: `PATCH /orders|preorders/{id}/payments/{payment}/confirmation` → `PaymentService::updateConfirmation()` (row-locked, activity log `payment_confirmation_updated` inside the transaction). Body keys `proof_token` / `reference` / `notes`, all optional, at least one; `null` clears reference/notes but the result may never be entirely empty. Allowed for **non-cash** payments only, for **owner/admin or the user who recorded that payment** (`Payment::mayManageConfirmation()` / `confirmationEditableBy()` — the single definition; legacy `recorded_by` NULL ⇒ owner/admin only), while the target is not a voided sale / cancelled pre-order (a handed-over pre-order is still allowed — no money moves). It can **never** change amount, method, channel, date, status, totals or shift cash.
- **Replacing a proof supersedes, never deletes**: the old `payment_proofs` row gets `superseded_at` (file kept for audit); `Payment::currentProof()` is the one definition of "the proof" used by both payload presenters. `deletePayment()` still removes every proof row and file of the payment.
- **Viewing**: `GET /payment-proofs/{id}/file` stays owner/admin or uploader (BOLA protection) **plus the payment's recorder for the current proof** (`Payment::proofViewableBy()`); superseded proofs only owner/admin/uploader. Payment payloads (`OrderResource`, `PreorderController::present()`) carry server-computed `notes`, `proof_id`, `has_proof`, `can_view_proof`, `can_edit_confirmation` — the SPA never derives permission from the role. The proof-dependent fields appear only when `payments.proofs` is eager-loaded (omitted, not "no proof", otherwise — the usual `relationLoaded` trap), so every endpoint that returns payments must load it.
- UI: `PaymentHistoryList` shows "No proof yet", notes, and the add/edit action; `PaymentConfirmationModal` (reuses `ProofCapture`) is shared by the Sales detail (`TransactionItemsModal`) and the Pre-order detail.

### Payment verification: mark verified (feature 032, 2026-10-04)

- **Every non-cash payment is stored `verification = 'pending'`** (cash is `verified` when recorded) and shows "Not verified" until the shop checks it against the bank/e-wallet statement. The action is `POST /orders|preorders/{id}/payments/{payment}/verify` → `PaymentService::markVerified()` (row-locked, activity log `payment_verified` in the same transaction); bulk `POST /orders/verify-payments` (`PaymentService::verifyOrderPayments()`, the Sales list selection) is a LOOP over that single path that skips (with per-reason counts: `already_verified`, `own_payment`, `voided`, `rejected`) instead of failing; cash is ignored.
- **One-way and FINAL (product-owner decision):** only `pending → verified`; there is no un-verify route, service method or UI, and the guard makes a repeat/stale request a harmless 409 (no second log row). `rejected` is an existing state this feature never enters or leaves (rejecting is out of scope).
- **Who may verify** — `Payment::mayVerify()` (the single definition; `isVerifiable()` is "non-cash and pending"): owner/admin always (even payments they recorded), anyone else EXCEPT the payment's recorder (separation of duties); a payment with `recorded_by` NULL can be verified by anyone. Payloads carry server-computed `can_verify` plus `verified_by_name` (only when `payments.verifier` is eager-loaded — the relationLoaded trap again) and `verified_at`; the SPA never derives permission from the role.
- **Money is untouched** (amount, status, totals, expected shift cash, reports). One intended visible effect: `CashierSessionController::summary()` has always counted only VERIFIED payments in its per-method breakdown, so a verified QRIS payment now appears there; expected cash is cash-only and unaffected.
- UI: `PaymentHistoryList` shows "Not verified"/"Verified" + "Verified by X · date" and the "Mark verified" action (always behind a "cannot be undone" `ConfirmDialog`), in the Sales detail and the Pre-order payment history; the Sales list has "Mark verified (N)" next to Export, meant to be used with the existing "Needs verification" (`pstate=pending`) filter + Select all.

## Seller Recap is POS-only (feature 040, 2026-10-06)

**This REVERSES the Seller Recap half of feature 033 below** (product-owner decision: "change to only show POS transaction", whole recap, not just the detail list). Rules:

- `SettlementService::recalculateForEvent()` aggregates **completed, non-voided POS `order_items` only** (`posSalesForEvent()`, explicit `data_mode` filter). The pre-order aggregation, `salesBreakdownForEvent()` and the remainder rules no longer exist for the recap. So the stored `artist_settlements` (`total_sales`, `total_units`, `payable_amount = total_sales − deduction`), Payable/Paid/Outstanding/Status, "Record payment" and the totals written on **event close** are all POS-only. The existing "reset every settlement row first" step zeroes a seller that used to have pre-order-derived totals; `paid_amount` is never touched.
- `GET /reports/artist-settlements` no longer returns `pos_units`/`preorder_units`/`pos_sales`/`preorder_sales`, and `outstanding = max(0, payable − paid)` (a seller paid against the old pre-order-inclusive payable keeps the recorded `paid_amount`; only the remainder shows 0). The recap table has Unit / Sales only; the "Transaction detail" list (`artistSettlementTransactions()`) and the recap export lose their pre-order parts (no `source` field, no type badge; export "Rekap" has 10 columns).
- **Not changed, on purpose:** the Pre-order report, Cost & Profit and Seller Cost keep the 033 POS + pre-order split. So **Seller Cost's `total_sales` no longer equals the recap's** for an event with pre-orders (its `sales_pos` still does). The Dashboard "Results per seller" panel reads the recap's `total_sales` and therefore became POS-only too.
- Consequence to remember: **pre-order revenue no longer produces a payable amount on the recap**; settling sellers for pre-order sales has to happen outside that screen (the Pre-order report still shows what was collected per seller).

## Reports: POS vs pre-order split (feature 033, 2026-10-04)

> **040 (2026-10-06):** the Seller Recap part of this section is superseded — see "Seller Recap is POS-only" above. Cost & Profit and Seller Cost below are unchanged.

The Seller Recap, Cost & Profit and Seller Cost (owner/admin only) show the **POS** part and the **pre-order** part next to the total. Rules (all in `SettlementService` / `ReportController` / `App\Support\ReportSplit`):

- **One source.** *(Superseded for the recap by 040: `salesBreakdownForEvent()` was deleted and the settlement is POS-only. Cost & Profit / Seller Cost keep their own aggregations in `ReportController`.)* It used to be `SettlementService::salesBreakdownForEvent()` running the two aggregations (completed `order_items`; non-cancelled `preorder_items` × paid fraction), with `recalculateForEvent()` built on it. Never add a second formula for "POS vs pre-order".
- **The pre-order part is the REMAINDER of the shown total** (`total − POS`: integer units, cents for money via `ReportSplit::remainder()`), never an independently rounded figure. `artist_settlements.total_units` is a rounded integer, so independent rounding can drift by 1; the remainder makes POS + pre-order equal the total exactly. Don't "fix" pre-order units to decimals.
- **Seller Cost (`GET /reports/artist-profit`) used to be POS-only** and now includes the paid part of pre-orders (product-owner decision) — at the time so its `total_sales` equalled the Recap's (no longer true since 040: only `sales_pos` equals the recap); `sales_pos`/`modal_pos`/`gross_profit_pos` equal the old figures, and pre-order-only sellers now appear. It does one extra `GROUP BY` query over `preorderRecognizedRevenueBase()` with the explicit `data_mode` filter.
- Cost & Profit adds flat `*_pos` / `*_preorder` keys (flat so the generic export gets real columns); `event_cost` / `net_profit` are whole-event figures and are NOT split.
- Exports: ~~the Recap summary sheet appends `pos_units, preorder_units, pos_sales, preorder_sales`~~ (removed in 040; the "Detail Transaksi" sheet was always POS-only and now agrees with the summary). The seller drill-down rounds per row, so its by-kind sums can differ from the column by ≤ 1 cent per row for sub-cent pre-order fractions (display rounding only; pinned by a test).

## Purchase-order row actions and stale-schema handling (feature 035, 2026-10-05)

- **Every PO row offers Detail / Edit / Delete** through the shared `PreorderRowActions.vue` (generic `{key,label,danger?,disabled?,title?}[]`) — never a second row-menu component. Actions that are not available stay VISIBLE but disabled with a reason: Delete is enabled for `draft` only (`purchase_orders.delete_draft_only`). Edit opens at any status; non-draft orders keep lines locked and save only vendor/seller/notes (already the server rule, now audited as `purchase_order_updated` inside the transaction).
- **`PurchaseOrderService::delete()` guards (409):** not draft, has payments, or any line is referenced by a BOM line (`ProductVariantBomLine::withoutGlobalScopes()` — a lock check must see BOTH data modes).
- **Stale database is explained, not exposed.** `bootstrap/app.php` converts a `QueryException` with SQLSTATE `42S22`/`42S02` on an API request into **503 `{code:'schema_outdated'}`** with a generic message (`lang/*/system.php`, never SQL/table/column names); every other database error stays a 500, and reporting is untouched (full detail stays in the log). `App\Support\SchemaStatus::pendingMigrations()` (files minus ran, `[]` if the `migrations` table is missing, never throws) feeds `GET /settings/features` → `schema_update_required`, true **only** for users with the `settings` menu (owner/admin), never listing names. **The app never runs migrations from a web request** — the admin does (`docker/php/entrypoint.sh` migrates only when the container STARTS; see RUNBOOK §"Setelah menarik perubahan kode"). Root cause of the two screenshots that started this feature was exactly that, not a code bug.
- **A failed load is a failure state, not an empty state.** `PurchaseOrderDetailModal`, `AddBomItemModal` and `VariantBomModal` keep a `loadError` and render the message + **Retry** (`common.retry`); the selector must never answer a failed request with "no eligible lines". `ApiError.isSchemaOutdated` + one cooldown-limited toast in `api/client.js`; `settings.schemaUpdateRequired` drives `SchemaUpdateBanner.vue` in `AppShell.vue`.

## Pre-order report: per-seller subtotals (feature 039, 2026-10-05)

Reports → Pre-order → By Seller shows a **Subtotal row after each seller's last row**; the Grand Total footer is unchanged. Rules (all in `App\Support\PreorderSellerSubtotals` / `ReportController`):

- **One implementation, server-side.** `PreorderSellerSubtotals::fromRows()` sums the SAME rows the response returns, per `artist_id`, in integer cents (`ReportSplit::cents()/money()`); the API returns them as `subtotals[]` and the Excel "Per Seller" sheet inserts them as `Subtotal — <name>` rows. The SPA only PLACES them — never re-derive a subtotal client-side from a different source.
- **`total_outstanding` is the SUM of the rows' (already clamped) outstanding**, not `order value − collected`: a Paid row can have collected > order value, so the subtraction would disagree with hand addition of the visible column.
- **Rows of one seller must be contiguous.** `preordersByArtist()` orders by `artists.name` THEN `preorder_items.artist_id` — two sellers with the same name used to interleave their rows (found in 039), which would have put a subtotal in the middle of another seller's block.
- The seller filter on the screen hides rows and their seller's subtotal together; the filtered subtotal/Grand Total still equal the visible rows. The default (summary) and drill-down shapes of `GET /reports/preorders` are untouched; the Summary sheet of the export is untouched.

## Conventions

- **Code comments, docs, commit messages, and UI copy are in Indonesian.** Comments explain *why*, often citing the PRD clause or the bug that motivated the code; several carry a `BUG YANG DITEMUKAN & DIPERBAIKI` header. Match this style.
- Seeded dev accounts (`php artisan db:seed`): `owner`, `admin`, `kasir01`, `kasir02`, `inventory` — all `password123`, local only.
- No git remote is configured; nothing is pushed.

<!-- SPECKIT START -->
Active feature plan: `specs/040-recap-pos-transactions-only/plan.md`
(branch `040-recap-pos-transactions-only`, from `develop` after PR #33) — the Seller Recap becomes POS-ONLY
(product-owner decision: whole recap, not just the detail list). `SettlementService` aggregates completed POS
order items only (the 033 pre-order aggregation/breakdown is deleted, `salesBreakdownForEvent` → `posSalesForEvent`),
so the stored settlement (`total_sales/total_units/payable_amount`), Payable/Paid/Outstanding, "Record payment" and
event close are POS-only. `GET /reports/artist-settlements` drops `pos_units/preorder_units/pos_sales/preorder_sales`
and clamps `outstanding` at 0 (a seller already paid against the old pre-order-inclusive payable keeps `paid_amount`
untouched); the seller "Transaction detail" and the export lose their pre-order parts; the Dashboard "Results per
seller" panel follows (it reads the same `total_sales`). Seller Cost / Cost & Profit / Pre-order report are
UNCHANGED and still include pre-orders, so Seller Cost's total no longer equals the recap's Sales. Consequence:
pre-order revenue no longer yields a payable amount on the recap. No migration. See research.md.

Previous feature: `specs/039-preorder-seller-subtotal/plan.md`
(branch `039-preorder-seller-subtotal`, from `develop` after PR #32) — Reports → Pre-order → By Seller
gets a "Subtotal — <seller>" row after each seller's rows (also in the Excel "Per Seller" sheet — product-owner
decision). ONE implementation: `App\Support\PreorderSellerSubtotals::fromRows()` sums the four figures per seller
in cents (reusing `ReportSplit`), `GET /reports/preorders?breakdown=artist` adds `subtotals` (additive; `rows`
unchanged and now contiguous per seller) and the export interleaves the same subtotals; the SPA only places them.
Outstanding is the SUM of the rows' clamped outstanding values, not value − collected (a paid row can have
collected > order value). Grand Total unchanged = Σ subtotals (client `sumRows` made cent-exact). No migration.
See research.md.

Previous feature: `specs/038-product-list-image-sku-tooltip/plan.md`
(branch `038-product-list-image-sku-tooltip`, from `develop` after PR #31) — Products list: the
picture-only column is merged into the Code column (96 px picture above the one-line code, so the
table gets narrower, not wider) and each SKU shows its variant name in a `BaseTooltip` on hover /
keyboard focus (click still opens the variant detail). `BaseTooltip` now teleports its bubble to
`body` with fixed positioning (below the trigger, flips above, clamped to the viewport, closes on
scroll/resize, never shown for blank text) because `DataTable`'s `overflow-auto` wrapper would
clip an absolutely-positioned bubble. Frontend only. See research.md.

Previous feature: `specs/037-variant-drawer-bom-ui/plan.md`
(branch `037-variant-drawer-bom-ui`, branched from `036-bom-variant-stock-ux`) — UI pass on the
Edit-product variant cards and the BOM dialog: bounded cards, four distinct chip colours (new
`sky`/`violet` token pairs; SKU blue, markup green, margin violet, BOM cost amber), header actions
`Open BOM (button + BaseTooltip) · Apply markup · delete`, fields name → stock → cost → sell, drawer
820 → 1040 px, variant picture 44 → 66 px. **Duplicate variant** reuses the existing new-variant
save flow (unsaved card seeded from the source incl. its stock — product-owner decision — with
`copy_bom_from` so the server copies the BOM; no new endpoint). BOM copy: `BaseSelect` now flips
above / caps its height to the space on screen (the picker used to run off the bottom of the
viewport) and supports option thumbnails; new `mode=selected` + `variant_ids[]` on
`POST /variants/{v}/bom/copy-out` (still `VariantBomService::copy()`), UI `VariantPickList`;
**Add BOM item** moves onto the Save changes row. No migration. See research.md.

Previous feature: `specs/036-bom-variant-stock-ux/plan.md`
(branch `036-bom-variant-stock-ux`, branched from `develop` after PR #30) — UX/correctness
pass on the BOM dialog, variant history and the product/stock lists. BOM quantities become
WHOLE numbers through one shared rule (`App\Rules\WholeBomQuantity`, every HTTP + Excel
entry point; stored legacy fractions are never rewritten); quantity edits become a draft
saved by an explicit Save through ONE all-or-nothing batch endpoint
(`PUT /variants/{variant}/bom` → `VariantBomService::updateQuantities()`), with an
unsaved-changes guard, the variant's current stock (read-only, `summary.current_stock`) and
"per 1 product" labels (cost maths untouched). Root causes found: the copy picker could not
scroll because `BaseSelect` closed on ANY scroll event, including its own list (fixed +
opt-in `searchable` mode); `master_data.col_type` was never defined (the only missing literal
i18n key — a static test now guards all keys). Variant history reuses
`GET /stock/movements` (adds `user_name`, product/variant names, a safely-resolved
`reference`; now gated to the `stock`/`products` menu — it was open to every role).
SKU on the Stock list opens `ProductDetailModal`; Products list gets a larger thumbnail and a
one-line code. No migration. See research.md.

Previous feature: `specs/035-po-row-actions/plan.md`
(branch `035-po-row-actions`, branched from `develop` after PR #29) — Purchase Orders
list gets Detail / Edit / Delete on every row (reusing `PreorderRowActions`; unavailable
actions stay visible with a reason). Edit is no longer draft-only (after draft: vendor,
seller, notes; lines locked); Delete stays draft-only server-side (message now says
"Cancel it instead", plus payment/BOM guards); edits write `purchase_order_updated`.
The two reported errors were NOT code bugs: the dev DB had 4 pending migrations (the
container started before PR #29; the entrypoint only migrates on start). To stop a stale
schema from looking like a bug: a global renderer maps database "unknown column / table
not found" (SQLSTATE 42S22/42S02) on API requests to **503 `schema_outdated`** with a
friendly message (SQL stays in the log); `SchemaStatus` feeds
`GET /settings/features.schema_update_required` (owner/admin only) for an app-shell
banner; the PO detail dialog and the BOM selector/modal show the error + Retry instead of a
blank dialog / a misleading "no eligible lines". See research.md.

Previous feature: `specs/034-seller-po-bom/plan.md`
(branch `034-seller-po-bom`, branched from `develop` after PR #28) — a variant's
BOM becomes the traceable list of PURCHASE-ORDER LINES that produce it. A PO gets a
seller (`purchase_orders.artist_id`, one per PO, required on create, NULL = legacy and
never offered as a source); BOM rows (`product_variant_bom_lines`, EXTENDED, not
replaced) gain `purchase_order_item_id` plus a SNAPSHOT of item/type/PO number/vendor/
unit cost, so a row that has no PO line is a flagged "legacy" row (kept, costed from the
vendor price list as before, blocks completion). `VariantBomService` is the only BOM
writer (add several PO lines, qty, remove, replace-source, copy from/next/all, complete,
reopen) and does cost-price sync + audit in the same transaction. When a variant's BOM is
COMPLETE its `cost_price` = BOM cost, auto-synced and locked against hand edits (409,
also through the Excel import); past sales keep their recorded cost. Eligible sources: the
variant's seller's POs in ordered/received/paid status only (never draft/cancelled).
Nothing reprices by itself — "newer price" and "source cancelled" are read-time cues.
"Linked Product" leaves the PO form (stored values stay). See research.md.

Previous feature: `specs/033-seller-recap-pos-preorder-split/plan.md`
(branch `033-seller-recap-pos-preorder-split`, branched from `develop` after PR #27) —
the Seller Recap's Unit/Sales, Cost & Profit's revenue/cost/gross profit blend POS
sales with the paid portion of pre-orders but only show the sum. No new storage:
`SettlementService::salesBreakdownForEvent()` exposes the two aggregations it already
runs (and `recalculateForEvent()` is built on it, so totals = POS + pre-order by
construction); the reports add `pos_*`/`preorder_*` parts, the pre-order part always
derived as the REMAINDER of the shown total (integer units, cents) so POS + pre-order
equals the total with zero drift despite the stored unit total being a rounded
integer. **Seller Cost (`artist-profit`) used to be POS-only** — by the requester's
decision it now includes pre-orders (POS parts = the old figures, plus a pre-order
part and a combined total), matching the Seller Recap. UI: four columns on the Recap,
"POS · Pre-order" sub-lines on Cost & Profit and Seller Cost; exports append the new
columns. See research.md.

Previous feature: `specs/032-mark-payment-verified/plan.md`
(branch `032-mark-payment-verified`, branched from `develop` after PR #26) — the
"Not verified" badge on non-cash sales never cleared because nothing could change
`payments.verification`. Adds the missing, **one-way** action (no schema change: the
columns `verified_by`/`verified_at` already exist): `POST /orders|preorders/{id}/
payments/{payment}/verify` → `PaymentService::markVerified()` (row-locked,
`pending → verified` only, non-cash only, not for voided/cancelled targets, audit
`payment_verified` in the same transaction) and a bulk `POST /orders/verify-payments`
for the Sales list selection (loop over the single path; skips own/already-verified/
voided/rejected with a per-reason summary). Allowed for owner/admin or any user EXCEPT
the payment's recorder (`Payment::mayVerify`); there is deliberately NO undo (product-
owner decision). Verification never touches amounts, status, totals, shift cash or
reports. Payloads gain `verified_by_name`, `verified_at`, `can_verify`. See research.md.

Previous feature: `specs/031-optional-payment-proof/plan.md`
(branch `031-optional-payment-proof`, branched from `develop` after PR #25) — the
payment proof (photo/file) becomes OPTIONAL for non-cash sales and pre-order
payments (reference and notes already were): `PaymentRecorder` no longer demands
a `proof_token` (an invalid one is still refused; purchase-order payments keep the
rule) and `PaymentPanel` no longer gates the Confirm button on it. A confirmation
(proof, reference, notes) can then be added or changed LATER from the Sales detail
(and the Pre-order payment history, same `PaymentHistoryList`) through
`PATCH /orders|preorders/{id}/payments/{payment}/confirmation`
(`PaymentService::updateConfirmation()`, row-locked, audited in the same transaction),
allowed only for owner/admin or the cashier who recorded that payment, for non-cash
payments of a not-voided sale / not-cancelled pre-order. Replacing a proof marks the
old `payment_proofs` row `superseded_at` (file kept for audit). It never touches
amount/method/status/totals/shift cash. The proof viewing rule (owner/admin or
uploader) is kept and extended to the payment's recorder; payloads carry server-
computed `can_view_proof` / `can_edit_confirmation`. See research.md.

Previous feature: `specs/030-fix-pos-search-product-image/plan.md`
(branch `030-fix-pos-search-product-image`, branched from `develop` after PR #24) —
a frontend fix: POS search result cards showed the placeholder icon and the cart
line had no photo for items added from a search. `GET /variants/lookup` already
returns `image_url` (variant's own, else the product's, via
`ProductVariant::image_url`), but `PosView.vue`'s inline `searchCards` map copied
only a subset of fields and dropped it (the cart inherits the gap because the
card is pushed into `posCart`). The fix builds the search card in
`utils/posProductCards.js` (`buildSearchCards`, beside `buildProductCards` /
`toCartItem`, where every card/cart shape lives) and ignores responses of
superseded searches. Search cards also show the category name (follow-up
request, FR-009), the same label browse cards have. OpenAPI `VariantLookup`
gains the already-returned `image_url`/`category_name`. See research.md.

Previous feature: `specs/029-fix-bulk-invoice-logo/plan.md`
(branch `029-fix-bulk-invoice-logo`, branched from `develop` after PR #23) — a
frontend-only defect fix: bulk-downloaded invoice PDFs showed the payment QR
image in the store-logo slot. Root cause is in the shared
`utils/pdfCapture.js::captureElementCanvas()`: it swapped the pre-fetched
`data:` images into html2canvas's clone BY INDEX over
`clonedDoc.querySelectorAll('img')`, but `onclone`'s first argument is a clone
of the WHOLE page, so any other `<img>` on the page (avatar, product
thumbnails) shifted every index. The fix keys the swap by each image's own
`src` (identity, not position), so an image can only ever get its own bytes;
all eight document-download call sites inherit it. Never reintroduce
index-based matching between `el.querySelectorAll('img')` and the clone. See
research.md.

Previous feature: `specs/028-partial-split-payment/plan.md`
(branch `028-partial-split-payment`, branched from `develop` after PR #22) —
payments become an independently saved ledger for BOTH pre-orders and POS
sales. One `PaymentService` records/deletes payments (amount ≤ remaining,
row-locked, idempotent via a client `client_ref` UUID, audited inside the
transaction) on top of the single row-writer `PaymentRecorder`, and returns a
derived `payment_summary` (grand total / total paid / remaining / status
unpaid|partially_paid|fully_paid / count) that is NEVER stored for orders
(pre-order and order `paid_amount` stay as caches recomputed from the
entries). Deliberate reversals: pre-order overpayment is now refused (422; the
old "no overpay guard" test is rewritten) and cash shift attribution moves
from `orders.session_id` to a new `payments.session_id` (the shift the money
was RECEIVED in) so a late cash payment cannot corrupt a closed shift's
reconciliation; `close()`, `summary()` and the Sales shift panel are rebased
on it. A POS sale may be completed partly paid only with a customer attached
(it stays `completed`, so every report still counts it at completion), and is
settled later from the Sales page. `RecordPaymentModal` (client-side
accumulation + sequential submit) is replaced by a single-click
`AddPaymentModal` plus shared `PaymentSummaryCard`/`PaymentHistoryList`. See
research.md Decisions 1–12.

Previous feature: `specs/027-preorder-duplicate-split/plan.md`
(merged, PR #22) — two new pre-order actions. **Duplicate** (one row or the
checkbox selection, `POST /preorders/duplicate`) is built ON TOP of
`PreorderService::create()`, not `replicate()`, so every copy is re-priced at
today's variant price (product-owner answer), starts `ordered` with no
payments/shipment/dispatch marker, and reuses create()'s numbering, discount
cap, pickup-day rules and DEMO/LIVE stamping; stale inputs degrade (missing
event → no event, out-of-range pickup day dropped) while a deleted/inactive
item fails THAT order only — bulk is always `200` with a per-order report, like
`bulk-email`. **Split** (`POST /preorders/{id}/split`, manual units or
`by_seller`) is refused with 409 for `handed_over`/`cancelled` AND for any
order that has a recorded payment (product-owner answer: no payment
allocation logic), moves whole lines by re-parenting the `preorder_items` row
(keeps the id stock movements reference) and partial lines by shrinking +
cloning the snapshot, keeps discount/shipping/shipment/notes on the original
(409 if the discount would then exceed the original's total), and NEVER calls
`StockService` — total qty per variant is unchanged. Origin is three nullable
columns on `preorders` (`source_preorder_id` nullOnDelete, `source_type`,
`source_preorder_number` snapshot); `present()` hides `split_children` unless
`show()`/the split response eager-load it. Neither action sends email. See
research.md Decisions 1–8.

Previous feature: `specs/025-preorder-dispatch-status-list-refinements/plan.md`
(branch `025-preorder-dispatch-status-list-refinements` — not yet created; the
work sits uncommitted on `develop` on top of feature 024's tip) — a manual,
three-value marker on pre-orders (`preorders.dispatch_status`: `pending` /
`invoice_sent` / `shipping`), deliberately a **separate column from `status`**
(which is the stock/payment state machine — the marker never touches stock,
payments, notifications, or `status`) and from `shipments.status` (which only
exists once a courier record does, and never for Self Pickup). Changed from
the detail drawer via `PATCH /preorders/{id}/dispatch-status` (both directions;
409 for a cancelled pre-order), filterable in the list (`dispatch_status[]`,
honoured by `/preorders/summary` and `/preorders/export` too). **`shipping` is
Mail Order (`fulfillment=courier`) only** — refused with 409 otherwise, and
`PreorderService::update()` downgrades it to `invoice_sent` if an edit moves
the order to pickup. `invoice_sent_at` / `shipping_at` are derived by the
server from the *target state* (re-marking keeps the date; leaving `shipping`
clears `shipping_at`; `pending` clears both; a client-supplied date is never
read) and shown inside the existing status cell of the list. Also delivered:
a "Print" dropdown in the detail (invoice / payment invoice), left-aligned
customer names, an "Actions" column whose row actions overflow into a
teleported "More" menu past three (`PreorderRowActions.vue`), a fix for the
list row's dead "Payment invoice" link (a signature mismatch in
`openPaymentReceipt`), sortable created/updated columns, and Excel
export/import of the marker and its dates — `created_at`/`updated_at` are
**export-only** (ignored on import so an imported order is stamped at import
time), dates are ISO 8601 with offset and **must be normalised to the app
timezone on import** (Eloquent writes a Carbon in its own timezone without
converting), and the export now accepts array-shaped `status`/`fulfillment`
filters (it used to throw). Two traps worth remembering: a database
`DEFAULT` isn't visible on a model returned by `create()` (mirrored in
`Preorder::$attributes`), and a JSON locale key repeated within one section
silently overrides the earlier one (hit with `created_at_label`). Real-browser
verification (quickstart.md) is still open. See research.md Decisions 1–8.

Previous feature: `specs/024-invoice-layout-shipping-slip/plan.md`
(branch `024-invoice-layout-shipping-slip`, branched from
`023-event-availability-invoice-redesign`'s tip) — five refinements to the
pre-order invoice (and, where it shares the shell, the payment invoice):
(1) the header becomes two columns — event name/location/available-on
(centered) on the left, pre-order number/status/a new "To:" recipient
block (customer name/email/phone/social handle/address) on the right;
(2) the document's own title now reads as a pre-order invoice (a new
dedicated locale key, not a reuse of the existing in-body "Invoice" badge
key) and the pre-order's creation date is shown; (3) payment channels
split into two columns by their existing `type` field (`qr_ewallet` vs
`bank_transfer` — already exactly the grouping requested, no new field),
with the QR growing again (96px → 128px, on top of feature 023's own
enlargement); (4) a new shipping-slip section appears ONLY for Mail Order
(`fulfillment === 'courier'`) pre-orders, showing event name/order
number/From (store identity)/To (customer)/item type — deliberately
sourced from data the invoice payload already has rather than the
separately-created `Shipment` record, so it works even before a shipment
has been logged (research.md Decision 5); (5) the existing footer message
is unaffected. The only backend change in this entire feature is adding
`created_at` to `PreorderController::present()` (confirmed missing by
reading the method directly) — every other field was already returned by
the existing invoice payload built up across features 007/014/022/023.
See research.md Decisions 1–6 for the full reasoning.

Previous feature: `specs/023-event-availability-invoice-redesign/plan.md`
(branch `023-event-availability-invoice-redesign`, branched from
`022-preorder-invoice-crud-overhaul`'s tip) — six changes: (1) Event gains
a nullable `available_on` field (`day_1`/`day_2`, resolved live to the
event's own start/end date via `Event::availableOnDate()` — never
snapshotted, so editing an event's dates never leaves it stale), offered
only for multi-day events and auto-cleared in the same transaction that
already clears stale pre-order pickup days (feature 021) when an event
collapses to a single day; (2) that resolved date, together with the
event's location, is rendered as one visually standout block — not small
footer text — on the pre-order invoice, the payment invoice (which
already shares that shell per feature 022), and the POS sale receipt,
**replacing** the previous tiny "Location:/Dates:" footer line rather
than duplicating it (research.md Decision 3, to avoid showing two
different, confusable date concepts — the full event range vs. the one
day the booth is open — on the same document); (3) the pre-order invoice
is restructured into a real header → itemized `<table>` → footer
document and widened (`max-w-[480px]` → `max-w-[720px]`) — scoped
deliberately to that document only, since the sales receipt has no
shipping-cost concept and never showed an on-document QR to begin with
(research.md Decision 4); (4) the payment QR on that invoice grows from a
56px to a 96px thumbnail, still click-to-enlarge via the existing
`ImageLightbox.vue` (feature 022); (5) a "Shipping cost" line is added to
the invoice's totals — the data (`Preorder.shipping_cost`) was already
being returned by the existing invoice payload since feature 022, just
never rendered, so this is a frontend-only change; (6) the reported
store-logo bug — the root cause found was that `SettingsView.vue` is the
one place in this codebase that hand-constructs a public-disk image URL
instead of reusing `ImageUploadService::url()`, the convention every
other image (products, categories, payment-channel QR codes, the invoice
documents themselves) already goes through — fixed by having
`GET /settings`/`POST /settings/store-logo` return the resolved URL
directly and deleting the frontend's guess. See research.md Decisions
1–8 for the full reasoning, and data-model.md for the exact
`available_on` resolution rule and response-shape additions.

Previous feature: `specs/022-preorder-invoice-crud-overhaul/plan.md`
(branch `022-preorder-invoice-crud-overhaul`, branched from
`021-preorder-form-updates`'s tip) — seven changes to the Pre-order screen
and its documents: (1) edit/delete a pre-order (edit allowed for any
status except "Handed over"/"Cancelled," with stock corrected via a
per-variant delta through `StockService::applyMovement()` once past
"Goods arrived" rather than a blanket reverse-and-reapply; delete allowed
only while still "Ordered" and with no recorded payment — every later
status must use the existing "Cancel" action instead); (2) the shipment
form auto-fills recipient name/phone/address from the linked customer and
drops the separate `city`/`postal_code` columns in favor of one address
field (mirrors `Customer.address` from feature 020); (3) "Receipt" →
"Invoice" everywhere, with the document gaining the store's logo, name,
contact person/phone/email, and full address, plus payment channels
(payment terms) and footer text — **all reused from the exact assembly
`OrderController::receipt()`/`ReceiptModal.vue` already built for the POS
sale receipt**, not redesigned from scratch, and the payment channels are
shown **unmasked** on this document specifically (a deliberate, narrow
exception to `PaymentChannelController::index()`'s internal-staff masking
rule, since a customer needs the real number to actually pay); (4)
"Payment receipt" → "Payment invoice," restyled to the same document
shell as (3) with payment-event-specific fields layered in; (5) bulk
download (client-side only — one `html2canvas`+`jsPDF` render per
selected order, bundled into a `.zip` via a new `jszip` dependency) and
bulk email (a rich-HTML email body via a new `PreorderInvoiceMail`, **no
PDF attachment** — deliberately avoiding a second, server-side document
template, since this codebase has never rendered a receipt/invoice
document server-side) for both invoice and payment-invoice documents; (6)
the customer picker (`CustomerSearchDropdown.vue`, from feature 021) shows
a scrollable default list of customers on open, not only after typing a
search term — reusing the same paginated `GET /customers` call already
made for search, just triggered earlier; (7) the pre-order import/export
workbook is redesigned from row-per-item to **one row per order**
(`event_name`, `fulfillment` as "pickup"/"mail order", `pickup_day` as
"Day N" text resolved against the matched event's real date range,
comma-separated `products`/`quantities`/`unit_prices` positionally
matched, `shipping_cost`, `courier_name`, `expected_date`, `discount`,
`notes`), **replacing** the existing format entirely (no second, parallel
template) — export produces the identical column layout so an exported
file round-trips back through import. The QR-enlarge-and-click-to-popup
change (also requested) lands in the one shared `ChannelPicker.vue`
component already used by both POS checkout and pre-order settlement,
not duplicated per screen. See research.md for the full reasoning behind
each decision (Decisions 1–8), and data-model.md for the exact stock-delta
algorithm and the new import/export column shape.

Previous feature: `specs/021-preorder-form-updates/plan.md`
(branch `021-preorder-form-updates`, branched from `020-customer-data-import-export`'s
tip) — six additive changes to the Pre-order create form: the customer
picker collapses from a button-opens-a-second-modal flow into one inline
searchable dropdown (`CustomerSearchDropdown.vue`, reusing
`BaseMultiSelect.vue`'s Teleport/positioning mechanics with a remote
debounced search instead of a local option list); a new order-level
`discount` (fixed Rupiah amount, matching every other money field in this
product — never a percentage); item quantity gains direct numeric entry
alongside the existing +/- stepper (frontend-only, `qty`'s backend
validation already accepts any value ≥ 1 however collected); "Courier" is
relabeled "Mail Order" everywhere it's shown (label-only — the stored
`fulfillment` enum value stays `courier`, no data migration); a
`pickup_day` for "Self Pickup" stored as a real `date` **derived from the
linked event's actual start/end date range** (not a fixed "Day 1"/"Day 2"
— a 1-day event offers one choice, a 3-day event offers three, no linked
event means no pickup-day field at all), shown on the invoice as that real
date and cleared automatically if the event's dates change to exclude it;
and a courier-name dropdown (default "JNE", shared list in the new
`App\Support\Couriers`) captured on `Preorder` itself as a *default/
preference* value — the existing, separately-created `Shipment` record
(with its own required recipient/address fields, still filled in later,
unchanged in shape) is untouched, just pre-filled from this default and
upgraded from free text to the same shared dropdown. Import/export
(`PreorderExportImportService`) gains matching `discount`/`pickup_day`/
`courier_name` columns, with a fulfillment-mismatched value (e.g. a
courier on a pickup row) reported as a row-level error rather than
silently accepted or dropped. See research.md for the full reasoning
behind each decision, especially why the courier default deliberately
does NOT touch `shipments`' schema.

Previous feature: `specs/020-customer-data-import-export/plan.md`
(branch `020-customer-data-import-export`, branched from `main`) — bulk
Excel export/import for `Customer`, as a **standalone** flow (its own
template file, its own endpoints/buttons on the Customers screen) —
deliberately NOT folded into the existing combined master-data workbook
(`MasterDataSheets::ORDER`), mirroring feature 007's
`PreorderExportImportService` shape (one sheet, full-validate-then-one-
transaction, `dry_run` preview) rather than `MasterDataImportService`'s
multi-sheet dependency-ordering machinery, since Customer has no FK
dependency on any other sheet. The one genuinely new rule (no precedent
elsewhere in this codebase): an imported row whose `email` matches an
existing customer's `email` (case-insensitive exact match, scoped to the
currently active DEMO/LIVE mode) UPDATES that customer instead of creating
a duplicate — blank cells on such a row leave the existing value
unchanged, matching the master-data importer's own "blank means
unchanged" convention. A row with no email always creates a new customer,
even if name/phone happen to match an existing one. Matching is
implemented as a single batched `whereIn('email', ...)` pre-fetch, not a
per-row query (Constitution V — avoid N+1 on a list sized up to ~1,000
rows). Gated to `canManageMasterData()` (owner/admin/inventory) — the same
tier as the rest of bulk master-data export/import, deliberately stricter
than the per-customer CRUD endpoints every role already uses, since
`Customer` itself is documented as holding personal data (phone/email/
social_handle) that must stay internal-only. See research.md for the full
reasoning behind each of these decisions.

Previous feature: `specs/019-billing-system/plan.md`
(branch `019-billing-system`, branched from `main`) — scope expanded
2026-09-06 (dated note): the smaller pass below (Invoice as a modal under
Companies, `Package` reused as-is) shipped first and is this update's
starting point, not discarded. This update **renames/expands `Package` →
`License`** (adds `price`, `payment_type` one_time/subscription — a
descriptive label only, no automated recurring billing — plus its own
top-level menu, seeded Pro/Master rows visible identically in DEMO/LIVE)
and **expands `Invoice`** into a full standalone document: its own
top-level menu (separate from both Companies and Licenses), a generated
`invoice_number` (unique across both data modes, mirroring
`PreorderService::generateNumber()`'s `withoutGlobalScope` pattern),
`license_id` + `subtotal`/`discount`/`grand_total` (a fixed snapshot,
never recomputed if the License's price later changes), full CRUD with
paid-invoices undeletable through any path (409), summary statistics,
client-side PDF/image download (reusing `ReceiptModal.vue`'s exact
`html2canvas`+`jsPDF` pattern — no server-side PDF generation), and a
separate single-sheet Excel export/import workbook mirroring feature
007's `PreorderExportImportService` (not folded into the master-data
workbook — invoices are transactional, not master data). Adds a new
Settings → Payment submenu (`invoice_payment_settings`, one
administrative row, not `HasDataMode`-scoped) as a sibling route under
`AppSidebar.vue`'s existing `settings-group`, gated on the existing
`settings` menu key. Naming note: the new `LicenseCatalogController`
(deliberately not named `LicenseController`) avoids colliding with
018's unrelated, already-shipped `LicenseController` (installation
activation gate — a completely different concept). See research.md
R0-R9' for the full reasoning.

Original small-scope pass (superseded above, kept for history): added
`Invoice` as a new entity tied to `Company` (017), a manually-entered
billing record (amount, due date, status: unpaid/paid/cancelled), no
payment-gateway integration. Reused 017's `companies` menu key. Status
transitions (`markPaid()`/`cancel()`) guarded in `InvoiceService`,
mirroring `PurchaseOrderService`'s transition-guard pattern. See
research.md R1-R4 for that original reasoning.

Previous feature: `specs/018-license-activation/plan.md`
(branch `018-license-activation`, branched from `main`) — a global gate
blocking every application function until THIS installation has redeemed
a valid, vendor-signed license key, matching the existing "one-time
license installed locally per store" business model. Fully separate from
feature 017's Company Onboarding CRM tracker (explicit product-owner
scope decision) — this gates the installation itself, before the login
screen is even reachable, for ANY URL including direct API hits.
Vendor-signed Ed25519 key (PHP's built-in `sodium` extension, no new
dependency), verifiable entirely offline against a public key baked into
the app — the app itself can never forge a valid key, since only the
vendor's own local, never-committed private key can sign one. On first
successful verification, binds to a machine fingerprint (`/etc/machine-id`
on Linux, `IOPlatformUUID` via `ioreg` on macOS — hashed, one-way,
mirroring how this codebase already hashes passwords) stored in a new
`license_activations` table (NOT `HasDataMode`-scoped — installation-level
security data, same category as `payment_channels`) — copying an
activated installation's data to a different machine fails closed
(fingerprint mismatch), verified manually since this has no automated-test
equivalent. Enforced by a global backend middleware first (`423 Locked`
on every route except the two license endpoints, including `/auth/login`
itself) — the frontend router guard is a UX mirror only, never the real
security boundary (Constitution IV). License generation is vendor-side
CLI tooling (`php artisan license:generate`), never a customer-facing
screen — mirrors feature 016's maintainer-vs-shipped-product separation;
safe to ship the command's code since it's cryptographically inert
without the vendor's own private key, which no customer's `.env` ever
contains. Feature 016's `docker-compose.store.yml` gets one small
addition (bind-mounting the HOST's `/etc/machine-id`) so a store's
license survives routine container-recreate upgrades rather than
false-locking on every one. Honest, explicitly-documented limitation:
this fully-offline design cannot detect the *same* key being activated
on two independently-fresh machines — only "copying an already-activated
install's data elsewhere" is technically prevented; the rest is a
vendor-side business-process concern, not a technical control. See
research.md R1-R8 for the full reasoning.

Previous feature: `specs/017-company-onboarding/plan.md`
(branch `017-company-onboarding`, branched from `main`) — Company/Package/
Business Type as new administratively-managed entities inside THIS
existing single BoothPOS installation: an internal sales/onboarding CRM
tracker, explicitly NOT a multi-tenant runtime pivot (confirmed with the
product owner before speccing — see spec.md's "Scope clarified" note).
An owner/admin onboards a company (business type + package + details +
contact + initial owner username/password); the system creates an
inactive owner `User` row and emails a single-use, hashed, 24h-expiring
6-digit activation code (research.md R2) to the contact — submitting the
correct code flips the company active and the owner user usable.
Package's `license_tier` (pro/master) is recorded on the Company as
DESCRIPTIVE DATA ONLY — it is deliberately never auto-applied to this
install's own `LicenseGate`/`multi_artist_enabled` Setting, since that's
a single global value and blindly overwriting it per-company-onboarded
would silently change what an ALREADY-USING company is licensed for
(research.md R4 — a real correctness risk, not just out-of-scope).
New `companies` menu key (owner/admin only, via a default-roles
migration mirroring `purchase_orders`'s own). Every activation-email send
attempt is logged in `company_activation_notifications`, an exact
structural mirror of the existing `preorder_notifications`/
`PreorderNotifier` pattern (including the `mail.default === 'log'` ->
`skipped_not_configured` convention) rather than a new audit-log shape.
Activation endpoint is rate-limited (`throttle:10,1`) since a 6-digit
code is a real brute-force target without it (research.md R5). See
research.md R1-R7 for the full reasoning.

Previous feature: `specs/016-docker-store-deployment/plan.md`
(branch `016-docker-store-deployment`, branched from `main`) — a SECOND,
production-shaped Docker path (`docker/store/`, `docker-compose.store.yml`),
entirely separate from feature 015's dev-only setup below. Dated note
(2026-09-05): this REVERSES feature 015's own documented "not a new
store-deployment channel" decision — a real store may now be deployed via
Docker as an alternative to the native install, not just via dev tooling.
Single self-contained image (Composer/npm build baked in at image-build
time, no bind mounts, no Node service at runtime) + `mysql` service with a
named volume decoupled from the app image's lifecycle, so an app-image
upgrade never touches persisted data. Distributed BOTH via container
registry (`docker pull`) AND an offline `.tar` archive (`docker save`/
`docker load`, for venues with unreliable internet) — both paths converge
on the same compose file/image tag. Migrations auto-run on start, reusing
015's idempotent entrypoint pattern. `app:backup`/`app:restore` (WBS 9.2)
stay unmodified — the new image bundles `mysqldump`/`mysql` client tools,
and `BACKUP_EXTERNAL_PATH` bind-mounts a real host path so backups remain
portable to a physical external drive, per existing RUNBOOK §9 guidance.
Upgrades and seeding stay fully manual, no auto-update/version-check
(product-owner decision, spec.md clarifications) — consistent with this
product's existing no-cloud-tier posture. See research.md R1-R7.

Previous feature: `specs/015-dockerize-dev-environment/plan.md`
(branch `015-dockerize-dev-environment`, branched from `main`) — a Docker
Compose setup (`mysql`+`app`+`node` services) reproducing the existing
native dev workflow (`laradock-mysql-1` + `php artisan serve` +
`npm run dev`) as one reproducible, one-command stack — **local
development tooling only**, explicitly NOT a new store-deployment channel
at the time it was built (clarified before speccing; "production" meant a
native, Docker-free install on the shopkeeper's machine). **This
"not-a-deployment-channel" framing was superseded by
`016-docker-store-deployment` (2026-09-05)** — a real store may now be
deployed via Docker too, through a wholly separate `docker/store/` tree,
never this feature's dev-only `docker/php|node` images. Everything else
below about this feature's own dev-tooling design remains accurate. PHP
image pinned to 8.3 (composer.json's declared
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
