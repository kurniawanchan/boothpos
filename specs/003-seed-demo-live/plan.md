# Implementation Plan: Seed Data Dummy & Mode DEMO/LIVE

**Branch**: `003-seed-demo-live` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-seed-demo-live/spec.md`

## Summary

Two coupled deliverables: (1) a one-time, idempotent seeder that populates a
full realistic dataset for a store named "Demo Sakana Fridge" (event, 3 artists,
9 products × 3 variants with stock, 3 categories, sales history, 3
customers, 6 vendors, anime/game-themed materials with vendor prices,
pre-orders); and (2) a store-wide **DEMO/LIVE mode** toggle that tags every
business/transactional record with the mode active when it was created, and
filters all reads by the currently active mode, so DEMO (seed + sandbox
practice data) and LIVE (real operational data) never mix in lists, POS
screens, or financial reports.

Technical approach: add a `data_mode ENUM('demo','live')` column to every
business/transactional table (not to users/roles/settings/activity_logs/
payment_channels, which stay mode-agnostic per FR-012), enforced through one
reusable Eloquent trait (`HasDataMode`) that (a) auto-stamps new rows with
the currently active mode and (b) applies a global scope filtering reads to
that mode. The active mode itself is stored as one more row in the existing
`settings` table (`system_mode`, values `demo`/`live`) and read through a
new `App\Support\ModeGate` class, mirroring the existing `LicenseGate`
pattern exactly. Switching mode reuses the existing `PUT /settings`
bulk-update endpoint (already owner/admin-gated via `canAccessMenu('settings')`
and already writes to `ActivityLogger` in the same transaction) — no new
write endpoint is needed, only new read surfacing (`GET /settings/features`)
and one new validation rule.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13), Vue 3 (Composition API), TypeScript-free JS

**Primary Dependencies**: Laravel (Eloquent, `FormRequest`, Policies), Pinia, vue-i18n (from 002-language-toggle), Vite

**Storage**: MySQL 8 (required — see CLAUDE.md; `ALTER TABLE ... CHECK` and ENUM columns used elsewhere in this schema)

**Testing**: `php artisan test` (Feature tests against real MySQL, `tests/Feature/`), Vitest (`qa-tests/`)

**Target Platform**: Single local machine per store (localhost Laravel API + Vue SPA), no cloud tier — see CLAUDE.md "What this is"

**Project Type**: Web application (Laravel API + Vue SPA, single repo, no separate frontend server in production)

**Performance Goals**: No new goal beyond existing Constitution Principle V (no N+1; the `data_mode` global scope must not add a query per row — it is a single `WHERE` clause per query, not a subquery or join)

**Constraints**: Mode switch must be non-destructive (FR-009) and must never let LIVE financial reports be affected by DEMO activity (FR-010, SC-005) — this rules out any design that recomputes/caches aggregates without a mode filter baked in.

**Scale/Scope**: ~20 existing tables touched by the `data_mode` column addition; 1 new seeder class; 1 new support class (`ModeGate`); 1 new Eloquent trait (`HasDataMode`); frontend: 1 status badge component + 1 settings toggle control + Pinia store field; no new controllers/routes required (reuses `SettingsController`).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability** — PASS. The `data_mode` scoping logic lives in exactly one place (`HasDataMode` trait), not copy-pasted per model, matching the "one sanctioned path per concern" rule (`StockService::applyMovement()`, `ActivityLogger` precedent). Mode read/write reuses `Setting`/`LicenseGate`'s existing pattern rather than inventing a parallel config mechanism.
- **II. Testing Standards** — PASS (must be honored in implementation, not just planned). Every new Feature test runs against real MySQL. The seeder's output MUST be verified by actually running it (`php artisan db:seed --class=...`) and inspecting real rows, not just reading the seeder code. Frontend mode badge/toggle MUST be exercised in a real browser per this principle before being declared done.
- **III. User Experience Consistency** — PASS, with an explicit design obligation: the mode badge and settings toggle are new UI copy and MUST get both `id` and `en` entries in `resources/js/locales/{id,en}.json` (002-language-toggle precedent — see `specs/002-language-toggle/plan.md`), and MUST use existing `@theme` design tokens, not new hex literals. A cashier/inventory user who cannot change mode MUST NOT see a disabled control (Principle III: hidden, not disabled) — the toggle itself is hidden for those roles; the read-only status badge remains visible to everyone (FR-005 applies to all roles, FR-013 restricts only the *change* action).
- **IV. Security** — PASS. Mode-switch authorization is enforced server-side via the existing `SettingPolicy::update` (`canAccessMenu('settings')`), not a frontend-only check — consistent with the Pro/Master `LicenseGate` precedent this plan mirrors. No client-supplied `data_mode` value is ever trusted on write paths for business records: it is always server-stamped from `ModeGate::current()` at creation time, never accepted from request input.
- **V. Performance & Optimization** — PASS. The global scope adds one indexed `WHERE data_mode = ?` per query (each affected table gets `KEY idx_<table>_data_mode (data_mode)` or a composite index where an existing index already leads on a more selective column); it does not introduce N+1 patterns or per-row lookups.

No violations requiring Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/003-seed-demo-live/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/            # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Support/
│   └── ModeGate.php                       # NEW — mirrors LicenseGate.php
├── Models/
│   └── Concerns/
│       └── HasDataMode.php                # NEW — trait: auto-stamp + global scope
│   (existing models gain `use HasDataMode;`: Event, Artist, Category, Product,
│    ProductVariant, Customer, Vendor, Material, VendorMaterialPrice,
│    ProductVariantBomLine, CashierSession, Order, OrderItem, Preorder,
│    PreorderItem, Shipment, Payment, PaymentProof, StockMovement,
│    ArtistSettlement)
├── Http/Controllers/Api/SettingsController.php   # MODIFIED — features() exposes system_mode
├── Http/Requests/UpdateSettingsRequest.php       # MODIFIED — validate system_mode in {demo,live}
database/
├── migrations/
│   └── 2026_10_11_000001_add_data_mode_to_business_tables.php   # NEW
├── seeders/
│   ├── DatabaseSeeder.php                 # MODIFIED — optionally invoke demo seeder
│   └── SakanaFridgeDemoSeeder.php         # NEW — the full dummy dataset (FR-001)
docs/
├── schema-pos-mvp.sql                     # MODIFIED — data_mode columns + settings note
├── openapi-pos-mvp.yaml                   # MODIFIED — GET /settings/features response
└── PRD-POS-Event-Multivendor.md           # MODIFIED — dated note (post-MVP addition)

resources/js/
├── stores/
│   └── settings.js (or equivalent)        # MODIFIED — holds current system_mode
├── components/layout/
│   └── AppShell.vue                       # MODIFIED — mode badge in header
│   └── SystemModeBadge.vue                # NEW
├── views/settings/
│   └── (existing Settings view)           # MODIFIED — owner/admin-only mode toggle control
└── locales/
    ├── id.json                            # MODIFIED — new keys
    └── en.json                            # MODIFIED — new keys

tests/Feature/
├── DataModeScopingTest.php                # NEW
├── SakanaFridgeDemoSeederTest.php         # NEW
└── SettingsSystemModeTest.php             # NEW

qa-tests/
└── (mode badge + toggle visibility/switch tests)   # NEW
```

**Structure Decision**: Existing single-repo Laravel API + Vue SPA structure is reused as-is (per CLAUDE.md, this is a one-machine installation, not a distributed web app) — no new top-level directories. The feature is additive: one migration, one seeder, one trait, one support class, and small, targeted edits to already-established controllers/policies/settings infrastructure.

## Phase 0 Unknowns Requiring Research

- How to structure the `data_mode` global scope so it can be **temporarily bypassed** for cross-mode integrity checks and for the seeder itself (which must write DEMO rows while, in principle, either mode could be active in `settings` at seed time).
- Whether `ProductVariantFactory`/`ArtistFactory`/etc. (existing factories) need a `data_mode` default, and how the new `SakanaFridgeDemoSeeder` should create transactional rows (orders, preorders) validly — via `OrderService`/`PreorderService`/`StockService` rather than raw inserts, per Constitution I and FR-002.
- Exact list of anime/game-themed material examples and vendor split (online vs offline) content, to keep seed data realistic without inventing brand-infringing specifics.
- How `docs/openapi-pos-mvp.yaml`'s `GET /settings/features` schema should represent `system_mode` alongside the existing `multi_artist_enabled` fields.

See `research.md` for resolutions.
