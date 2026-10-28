# Implementation Plan: Outlet Identity and Web Terminal Management

**Classification**: INTERNAL

**Branch**: `079-outlet-code-address-store-info` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/079-outlet-code-address-store-info/spec.md`

## Summary

Five stories in one feature, delivered server-first:

1. **Address** on the outlet record (new column, resource, form, export/import sheet).
2. **Receipts show the outlet of the sale**: one new support class `StoreIdentity` is the only place that decides the name, address, phone, email and notes block (receipts and screens); `OrderController::receipt` and `GET /settings/features` stop reading `store_*` settings directly.
3. **Outlet code = 3–5 letters/digits, editable**: rules move into one place; code columns and the SKU/product-prefix columns that embed the code are widened; `PUT /outlets/{id}` accepts `code`; old product codes are never rewritten.
4. **Screens follow the selected outlet**: web features payload is outlet-aware and the SPA refetches when the picker changes; the tablet reads the identity of its own outlet (new token-less read keyed by the outlet path).
5. **Terminals page on the web** over the existing `devices` API: filtered, paginated list, create under a chosen outlet, rename, move (guarded), deactivate/reactivate, and delete only when unused.

## Technical Context

**Language/Version**: PHP 8.x / Laravel 13 (API); Vue 3 + Pinia + Tailwind v4 (SPA); Kotlin + Jetpack Compose (`android-pos/`)

**Primary Dependencies**: existing only (Sanctum, Maatwebsite-style import/export already in `MasterDataSheets`, Vitest, PHPUnit, Android unit tests). No new package.

**Storage**: MySQL 8. New migrations only; existing migration filenames are untouched.

**Testing**: `tests/Feature/` (MySQL, run in container with the `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array` overrides from CLAUDE.md), `qa-tests/` (Vitest), `android-pos` unit tests, plus a real browser check of the Outlets, Terminals and Receipt screens (Constitution II).

**Target Platform**: Laravel API + Vue SPA on one machine or one shared server; Android tablet app.

**Project Type**: web application (API + SPA) with a separate Android client.

**Performance Goals**: receipts and `GET /settings/features` stay one extra indexed lookup (outlet by id); terminals list paginated, no N+1 (eager-load outlet, one aggregate for "last seen").

**Constraints**: API conventions (422/409/403, money-as-string unchanged, pagination envelope); `docs/openapi-pos-mvp.yaml` moves in the same commit as each route/response change; UI copy and comments in Indonesian; no raw hex; outlet is server-derived (Constitution IV).

**Scale/Scope**: a handful of outlets per organisation, tens of terminals.

## Constitution Check

*GATE: passes. Re-checked after Phase 1 design: still passes, one recorded exception (below).*

| Principle | Check | Result |
|---|---|---|
| I. Single write path / no duplication | Store identity decided only in `StoreIdentity`; outlet code rule only in one rule class; terminal writes only in `DeviceService`; move rule only in `OutletMoveGuard` (extended). Business logic in services, not controllers. | Pass |
| II. Testing | Feature tests per story (see tasks later), Vitest for the Terminals page and outlet form, Android unit tests for the identity mapping, browser verification of three screens with console checked. | Pass (to be evidenced) |
| III. UX consistency | Existing tokens, existing error handler, existing `usePaginatedList`; Terminals link shown only to owner/admin; Indonesian copy; outlet filter follows the 070/071 `outlet_id[]` + `skipOutletHeader` pattern. | Pass |
| IV. Security | Outlet never read blindly from a body: terminal create keeps the `X-Outlet-Id` header contract; only terminal **move** takes `outlet_id` in the body, validated against `OutletContext::permittedIds()` (precedent: 071 dining tables/stations); receipt outlet comes from the order; the token-less branding read exposes only name/logo (same exception as 077, recorded). Audit log inside the mutation transaction. Customer phone/email stay off receipts. | Pass with recorded exception R6 |
| V. Performance | No N+1 on terminals list; identity lookup is by primary key; features payload is keyed by the request's outlet, so it must not be cached across outlets. | Pass |
| Docs discipline | OpenAPI in the same commit; PRD dated note for F19.4 (code now editable) and for web terminal management; RUNBOOK note for the moved/reactivated terminal behaviour. | Planned |

No unjustified violations, so the Complexity Tracking table is empty.

## Project Structure

### Documentation (this feature)

```text
specs/079-outlet-code-address-store-info/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-changes.md
├── checklists/requirements.md
└── tasks.md            # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Support/
│   ├── StoreIdentity.php            # NEW  the only place that picks name/address/phone/email/notes (receipt vs screen rules)
│   └── OutletCodeRule.php           # NEW  one validation rule (3–5 alnum, unique among live outlets, ignoring case)
├── Services/
│   ├── OutletMoveGuard.php          # EXT  deviceBlocker(): open shift blocks a terminal move
│   ├── DeviceService.php            # EXT  create in chosen outlet, move, reactivate, deleteIfUnused
│   └── OutletService.php?           # only if code-change logic needs a service; otherwise stays in OutletController (see research D2)
├── Http/
│   ├── Controllers/Api/{OutletController,DeviceController,OrderController,SettingsController,PosSetupController}.php
│   ├── Requests/{StoreOutletRequest,UpdateOutletRequest,PairDeviceRequest,RenameDeviceRequest→UpdateDeviceRequest}.php
│   └── Resources/{OutletResource}.php
├── Models/Outlet.php                # address fillable
database/migrations/                 # NEW  address column; widen outlets.code; widen code_prefix/sku/sku_snapshot
database/seeders/                    # keep outlet seeds valid under the new rule
lang/{id,en}/{master_data,devices,outlets}.php
docs/{openapi-pos-mvp.yaml,PRD-POS-Event-Multivendor.md,RUNBOOK.md}
resources/js/
├── views/{OutletsView.vue,TerminalsView.vue}   # TerminalsView NEW
├── api/devices.js (NEW or extended), stores/settings.js, composables/useBranding.js
├── components/layout/{AppShell,TopBar,RailNav}.vue, components/receipt/ReceiptModal.vue
├── router/index.js, locales (id/en)
qa-tests/                            # Vitest for the above
android-pos/
├── core-network/.../dto/{ReceiptDtos,BrandingDtos}.kt, PosApi.kt
└── app/.../data/{ReceiptMapper,BrandingMapper}.kt, print/Documents.kt, state/PosStore.kt
tests/Feature/                       # Outlet code/address, receipt identity, features payload, devices
```

**Structure Decision**: existing web-application + Android layout; no new top-level directory. New code is limited to two support classes, one view, and one API module; everything else extends the owning service.

## Complexity Tracking

None.
