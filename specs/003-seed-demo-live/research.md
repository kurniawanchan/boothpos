# Phase 0 Research: Seed Data Dummy & Mode DEMO/LIVE

## Decision 1: Global scope implementation — named `Scope` class, not a closure

**Decision**: Implement `App\Models\Concerns\DataModeScope implements Illuminate\Database\Eloquent\Scope`, applied via `App\Models\Concerns\HasDataMode::bootHasDataMode()` calling `static::addGlobalScope(new DataModeScope)`. The trait also registers a `creating` model event that sets `$model->data_mode ??= ModeGate::current()` (only fills it in when not already explicitly set).

**Rationale**: Laravel's Eloquent only allows removing a *named* scope class via `Model::withoutGlobalScope(DataModeScope::class)` (or `withoutGlobalScopes()` for all). A closure-based scope registered inline cannot be selectively bypassed later. Both the seeder and any future cross-mode admin/reporting tool need exactly that bypass, so the scope must be a class from day one rather than refactored later.

**Alternatives considered**:
- *Manual `WHERE data_mode = ?` in every query/repository* — rejected: violates Constitution I (one sanctioned path per concern); every controller/service that lists any of the ~19 affected models would need to remember it, and one omission silently leaks cross-mode data (exactly the kind of bug this feature exists to prevent).
- *Separate database connections/schemas per mode* — rejected: this is a single-file-installation product (CLAUDE.md: "one machine... no cloud tier"), and PRD/Constitution both assume one MySQL database; running two schemas would double migration/backup complexity (`php artisan app:backup`/`app:restore`) for no benefit, since a simple column-and-scope achieves the same non-destructive isolation (FR-009) far more cheaply.

## Decision 2: Mode-forcing for the seeder and for other server-driven writes — `ModeGate::runAs()`

**Decision**: `ModeGate` gets a static, stack-based override:

```php
ModeGate::runAs('demo', function () {
    // any Eloquent creates in here get data_mode = 'demo'
    // regardless of the persisted `system_mode` setting
});
```

`ModeGate::current()` checks the override stack first, falling back to `Setting::get('system_mode', 'live')`. `SakanaFridgeDemoSeeder` wraps its entire `run()` body in `ModeGate::runAs('demo', ...)`.

**Rationale**: The seeder must always produce `data_mode = 'demo'` rows, independent of whatever mode happens to be active in `settings` at seed time (re-seeding while LIVE is active must not mislabel dummy rows as LIVE, and must not require the operator to remember to flip mode first). Because `OrderService`, `PreorderService`, and `StockService` (which the seeder reuses to produce *valid* transactions per FR-002) already call `Model::create()` internally without any mode parameter, routing the override through `ModeGate::current()` means **zero changes** to those services — they stay ignorant of "demo vs live" entirely, which is the correct boundary (business services shouldn't know about a presentation/sandboxing concern).

**Alternatives considered**:
- *Add a `$dataMode` parameter to `OrderService`/`PreorderService`/`StockService` methods* — rejected: leaks a cross-cutting concern into service signatures used by real request-time code paths too, and every call site (controllers) would need a meaningless `data_mode: ModeGate::current()` passthrough. Constitution I: no speculative parameters beyond what's needed.
- *Seeder inserts `data_mode` explicitly on every `create()` call, bypassing services entirely* — rejected as the *primary* mechanism (it's used as a secondary belt-and-suspenders default in the trait, "auto-stamp only when not already set", but the seeder itself uses real services): raw inserts would skip stock-movement/activity-log side effects those services provide, producing dummy data that does **not** "lolos semua aturan bisnis" (FR-002) and would fork a second, unaudited write path for orders — exactly what Constitution I forbids.

## Decision 3: Cross-mode reference safety is a natural side-effect, with one documented gap

**Decision**: Because every model-picker query (e.g., "choose an artist for this product", "choose a variant for this order line") goes through the same globally-scoped Eloquent queries, a user/service operating in mode X can only ever *see and select* rows already in mode X — cross-mode FK contamination (e.g., a LIVE order line referencing a DEMO-only product variant) cannot happen through any normal UI/API flow. No additional validation code is required for the common path.

**Known gap (documented, not built in this feature)**: MySQL foreign keys reference `id` only, not `(id, data_mode)`, so nothing at the database layer stops a stale ID captured before a mode switch from being submitted (e.g., a POS cart held open across a mode switch, then submitted). This is called out in spec.md's Edge Cases. Mitigation deferred to implementation-time judgment: the cheapest guard is having `OrderService`/`PreorderService` re-validate that every referenced variant's `data_mode` still equals `ModeGate::current()` before writing, returning the existing `409` conflict convention (CLAUDE.md API conventions) if not — a few extra `WHERE` clauses on already-loaded models, not a new subsystem. Full composite-FK enforcement at the database level is rejected as disproportionate (MySQL would need triggers, which this codebase has avoided everywhere else in favor of application-layer checks — see `docs/schema-pos-mvp.sql`'s own note on the `payment_proofs` non-empty-file rule being app-enforced, not DB-enforced).

## Decision 4: Seed content — original names, no real IP

**Decision**: All seeded artist/product/material names are original, generic stand-ins evoking "anime & game merch" categories (acrylic stands, keychains, enamel pins, stickers, canvas totebags) rather than any real copyrighted franchise or character name. Materials mirror real, genuinely generic craft/production inputs used for this kind of merch: akrilik bening, PVC lembaran, pin blank émail, kain kanvas, stiker vinyl, kertas photocard glossy, gantungan kunci metal, case handphone polos, kain kanvas totebag.

**Rationale**: This matches existing project precedent — CLAUDE.md already notes the shipped UI mockup's embedded demo data is "fabricated," and using invented names avoids any trademark/IP question entirely while still being immediately recognizable as "anime & game merchandise" to anyone testing the product. This is a content decision, not a scope one, so it doesn't need a spec-level clarification.

## Decision 5: `docs/openapi-pos-mvp.yaml` surface change

**Decision**: Extend the existing `GET /settings/features` response schema (already returns `multi_artist_enabled`, `artist_count`, `artist_limit_reached`) with one more field: `system_mode: string, enum: [demo, live]`. No new path is added to the OpenAPI document; `PUT /settings`'s existing generic `{key, value, type?, group?}[]` body schema already covers writing `system_mode` — only its description/example gains a `system_mode` row, per Constitution/CLAUDE.md's "OpenAPI must move in the same commit as any route/response change" rule (the response *shape* of `/settings/features` is changing, even though no new route is added).

**Rationale**: Reuses `SettingsController::features()`, the one endpoint this codebase already uses to surface a boolean/derived flag for frontend cosmetic gating (`multi_artist_enabled` pattern) — `system_mode` is exactly the same shape of "cosmetic-but-also-authoritative-enough-to-display" flag, so it belongs next to its sibling, not in a bespoke new endpoint.
