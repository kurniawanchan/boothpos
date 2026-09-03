# Phase 1 Data Model: Seed Data Dummy & Mode DEMO/LIVE

## New/changed column: `data_mode`

Added as `ENUM('demo','live') NOT NULL DEFAULT 'live'` to every table below, each
with its own `KEY idx_<table>_data_mode (data_mode)` (or folded into an
existing composite index where one already leads with a more selective
column, e.g. `idx_orders_event (event_id, status)` stays as-is and gets a
sibling `idx_orders_data_mode`). `DEFAULT 'live'` means any row inserted by
code that has not yet adopted `HasDataMode` (or any tooling doing a raw
insert) safely defaults to LIVE — never silently DEMO — which also satisfies
the spec's Assumption that pre-existing installations upgrading into this
feature keep their real history as LIVE with zero migration data-fix step.

| Table | Why in scope (FR-012) |
|---|---|
| `events` | business data |
| `artists` | business data |
| `categories` | business data |
| `products` | business data |
| `product_variants` | business data |
| `customers` | business data |
| `vendors` | business data |
| `materials` | business data |
| `vendor_material_prices` | business data |
| `product_variant_bom_lines` | business data |
| `cashier_sessions` | business/transactional |
| `orders` | transactional |
| `order_items` | transactional |
| `preorders` | transactional |
| `preorder_items` | transactional |
| `shipments` | transactional |
| `payments` | transactional |
| `payment_proofs` | transactional (files still live on disk regardless of mode; only the DB row is filtered) |
| `stock_movements` | transactional (append-only ledger — see note below) |
| `artist_settlements` | derived/report data, must follow its event's mode |

**Explicitly out of scope** (Assumptions in spec.md): `users`, `roles`,
`settings`, `activity_logs`, `payment_channels`. These stay globally visible
in both modes — an owner's account, role permissions, store profile, and the
history of *who changed what* are administrative facts, not
demoable/real business records.

**`stock_movements` note**: this table is append-only (schema comment:
"tabel ini bersifat append-only. Tidak ada UPDATE atau DELETE"). Adding
`data_mode` does not change that invariant — a movement's mode is set once
at insert time by `HasDataMode` and never updated, exactly like every other
immutable column already on this table (`stock_before`, `stock_after`).

**`artist_settlements` note**: settlement rows are keyed `(event_id,
artist_id)` and recomputed from `order_items` (schema comment: "total_sales
adalah hasil hitung ulang... bukan angka yang diketik manual"). Its
`data_mode` is stamped from the same value as the `event_id` it belongs to
at creation time — since the global scope already prevents a LIVE event
from being aggregated against DEMO order_items (both sides are scoped), this
is consistent by construction, not by extra logic.

## New entity: `system_mode` setting row

Not a new table — one more row in the existing `settings` table, following
the exact shape of `multi_artist_enabled`:

| key | value | type | group |
|---|---|---|---|
| `system_mode` | `demo` \| `live` | `string` | `system` |

Default when absent: `live` (via `Setting::get('system_mode', 'live')`,
same fallback pattern `LicenseGate::multiArtistEnabled()` uses for its own
key). A fresh installation that has never touched this setting is LIVE by
default — DEMO is something an owner/admin opts into, never the silent
default, so a brand-new real store never accidentally starts in sandbox
mode.

## New support class: `App\Support\ModeGate`

Mirrors `App\Support\LicenseGate` exactly:

- `ModeGate::current(): string` — returns `'demo'` or `'live'`. Checks an
  in-process override stack first (see `runAs`), else
  `Setting::get('system_mode', 'live')`.
- `ModeGate::isDemo(): bool` / `ModeGate::isLive(): bool`
- `ModeGate::runAs(string $mode, callable $callback): mixed` — pushes
  `$mode` onto the override stack, runs `$callback`, pops it in a `finally`
  block (safe even if `$callback` throws). Used by `SakanaFridgeDemoSeeder`.

## New trait: `App\Models\Concerns\HasDataMode`

Applied to every model in the "in scope" table above.

- `protected static function bootHasDataMode(): void` — registers
  `static::addGlobalScope(new DataModeScope)` and a `creating` listener that
  does `$model->data_mode = $model->data_mode ?? ModeGate::current();`
- Companion class `App\Models\Concerns\DataModeScope implements Scope` —
  applies `$builder->where('data_mode', ModeGate::current())`.

## Seed entities (all created via `ModeGate::runAs('demo', ...)`, `data_mode = 'demo'`)

- **Store profile**: `settings` row `store_name = 'Demo Sakana Fridge'` (existing
  `settings.store_name` key — reused, not mode-scoped, since store profile
  is administrative per FR-012's exclusion list; this is the one real
  store name the installation carries regardless of mode).
- **Event**: 1 row, `status = 'active'`.
- **Artists (3)**: each with a unique 3-letter `code`.
- **Categories (3)**: merchandise categories, unique 2-letter `code` each.
- **Products (9)**: 3 per artist, each `category_id` drawn from the 3
  seeded categories, each with a deterministic `code_prefix` per the
  existing `ProductCodeGenerator` rules (artist code + category code +
  product segment).
- **Product Variants (27)**: 3 per product, each with `sku`, `cost_price`,
  `sell_price`, and non-zero `current_stock`, created through
  `StockService::applyMovement()` (`type = 'initial'`) so the
  ledger/quick-read invariant (schema Decision 3) holds for seed data too.
- **Customers (3)**.
- **Vendors (6)**: 3 flagged as online-store vendors, 3 as offline-store
  vendors — `notes` field carries the online/offline distinction (schema
  has no dedicated `vendor_type` column; adding one is out of scope for
  this feature, see Assumptions below).
- **Materials**: anime/game-merch-themed (acrylic sheet, PVC sheet, enamel
  pin blank, canvas fabric, vinyl sticker, glossy photocard paper, metal
  keychain ring, phone case blank), each with 1–2 `vendor_material_prices`
  rows (at least one material has 2 vendors to exercise the
  `is_preferred`/cheapest-price selection logic already built for
  `BomCostCalculator`).
- **BOM lines**: at least one seeded variant gets `product_variant_bom_lines`
  rows, so `GET /variants/{variant}/cost-breakdown` has real seed data to
  show.
- **Sales (orders)**: created through `OrderService` against a seeded, closed
  `cashier_sessions` row, so totals/stock deltas are real, not hand-typed.
- **Pre-orders**: created through `PreorderService`, spanning at least two
  different `status` values (e.g., one `dp_paid`, one `handed_over`) so the
  `PreorderStatusStepper` UI has something to demonstrate.

## Frontend state

- Extend the existing settings Pinia store with `systemMode: 'demo' | 'live'`,
  populated from `GET /settings/features`.
- New `SystemModeBadge.vue`, mounted in `AppShell.vue`'s header, next to the
  language toggle (both are always-visible, global, cross-cutting status/
  controls — same visual tier).
- Settings view gains one new control (visible only when
  `canAccessMenu('settings')`, i.e., already gated the same way the rest of
  that screen is) to flip `system_mode` via the existing bulk `PUT /settings`
  call.

## Assumptions carried from spec.md, restated as data-model constraints

- No new `vendor_type` (online/offline) column is introduced by this
  feature — spec.md FR-001 only requires the *seed data* to represent 3
  online + 3 offline vendors, not a permanent schema distinction; encoding
  it in `vendors.notes` (already a free-text field) satisfies the
  requirement without a schema change unrelated to DEMO/LIVE. If a future
  feature needs to *query* by vendor type, that is a separate, explicit
  schema change — not silently added here.
