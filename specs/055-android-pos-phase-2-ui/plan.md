# Implementation Plan: Android POS Phase 2 — Two-Column Counter, Server Categories, Live Data, Refresh

**Branch**: `055-android-pos-phase-2-ui` (not created; work sits on `054-android-pin-layout-fixes` in the tree) | **Date**: 2026-10-18 | **Spec**: [spec.md](./spec.md)

**Input**: `specs/055-android-pos-phase-2-ui/spec.md`, corrected after code trace on 2026-10-18.

## Summary

Phase 2 changes the counter screen of the Android POS and adds one server read endpoint.

1. **Categories from the server for the active mode.** The root cause is that the tablet builds its category list from the products it received, so a category with no sellable product in the active mode disappears. Fix: a new read-only endpoint `GET /pos/categories` returns the active categories of the current mode (DEMO or LIVE, via the existing `HasDataMode` scope). The counter loads it alongside the menu.
2. **Product names** already come from the server (`GET /products?pos=1`); the DEMO seed names are server data. No product-source change is needed, only verification that LIVE data also reaches the counter.
3. **Two-column counter**: the product grid in the left column, the order list in the right column in landscape; the category strip becomes one horizontal scrolling row on every size, replacing the vertical rail (which is unscrollable, the reason only a few categories show).
4. **Totals area** gets one show/hide control; the choice is stored on the device.
5. **Kirim / Bayar** labels: drop the amount and count from the Bayar label, keep both labels whole by fitting text (reusing `ChipFit`).
6. **Tax line** label and rate come from the server (`tax_label`, `tax_rate` from `GET /settings/features`), not the hard-coded "Pajak 10%".
7. **Order list text** reduced by 2 sp.
8. **Pull to refresh** on every server-backed screen, using Material 3 pull-to-refresh, with the last data kept on failure.

Backend change: one additive endpoint, no migration, no change to existing payloads.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (backend); Kotlin + Jetpack Compose (Material 3 1.3.0, Compose BOM 2024.09.00) for `android-pos/`

**Primary Dependencies**: Eloquent `Category` (uses `HasDataMode`), `ProductPolicy` (for the POS gate), `ModeGate`; Android `PosStore`, `MenuMapper`, `RemoteBackend`, `PosApi` (Retrofit), `ChipFit` (from 054)

**Storage**: MySQL 8 (`categories`, `products`; no schema change). Android: small on-device preference for the totals show/hide state (plain shared preferences, not `TokenVault`, which holds secrets).

**Testing**: backend feature test under `tests/Feature/` against MySQL (container command with `-e` flags on `docker compose exec`); Android JVM unit tests (`testDebugUnitTest`) with `FakePosBackend` for category and totals logic; manual tablet check for layout, labels, and refresh.

**Target Platform**: Android tablet (pilot, landscape reference; portrait must still work).

**Project Type**: Mobile app (`android-pos/`) plus one additive API route on the Laravel backend.

**Performance Goals**: category list loads with the menu in one extra request; pull to refresh completes in the same time as the first load (product list is paged, unchanged).

**Constraints**: no change to `GET /products?pos=1` shape; the new endpoint adds no N+1 (one query, product counts via `withCount`); money and tax are read from the server, never computed from a constant on the tablet.

**Scale/Scope**: one outlet's counter; under 50 categories and a few hundred products per mode.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I. Code quality | One category source: `GET /pos/categories` through one repository function on Android; the counter reads the store, not the endpoint. The fit logic is `ChipFit` (054), reused, not copied. Business logic stays in a service/class, not the controller. | PASS |
| II. Testing | Backend test for mode scoping and gate; Android unit tests for: categories list not derived from products, totals visibility persistence, label fit decisions. Browser is not needed (no admin page change); tablet check is recorded as a manual item. | PASS (tablet check pending) |
| III. UX | Design tokens and Material 3 components only; Indonesian copy for new labels ("Tampilkan total", "Sembunyikan total"); hidden rather than disabled where a role cannot act. | PASS |
| IV. Security | The new endpoint is authenticated and uses the same gate as `GET /products?pos=1` (`ProductPolicy::viewAny`); it reads the mode from the server; no client value decides the mode. Tax values are read from the server. | PASS |
| V. Performance | One query with `withCount` (no per-category query); list stays lean (no product payload); categories are small and fetched with the menu. A cached figure is not introduced. | PASS |
| Stack shape | Works in shape (a) and (b): the endpoint is the same API on the local or the shared server. | PASS |
| Documentation | `docs/openapi-pos-mvp.yaml` gets the new operation in the same commit (constitution, Documentation section). | PASS (task) |

**Gate result**: PASS. No violations; Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/055-android-pos-phase-2-ui/
├── spec.md
├── plan.md              # this file
├── research.md          # decisions R1–R8
├── data-model.md        # POS category view, totals preference, tax values
├── quickstart.md        # verification steps
├── contracts/
│   └── pos-categories.md  # GET /pos/categories
├── checklists/requirements.md
└── tasks.md             # NOT created here (/speckit-tasks)
```

### Source Code (repository root)

```text
app/Http/Controllers/Api/PosCategoryController.php   # NEW: index() → active categories of the mode
app/Http/Resources/PosCategoryResource.php           # NEW? only if a Resource is used; else inline array (decided in tasks)
routes/api.php                                       # EDIT: GET /pos/categories in the auth group, next to /products
tests/Feature/PosCategoriesTest.php                  # NEW: mode scoping, inactive hidden, gate, no derived-from-products
docs/openapi-pos-mvp.yaml                            # EDIT: new operation (same commit)

android-pos/core-network/src/main/java/com/warkop/core/network/PosApi.kt       # EDIT: pos/categories call
android-pos/core-network/src/main/java/com/warkop/core/network/dto/…           # EDIT: PosCategoryDto
android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt               # EDIT: loadCategories()
android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt            # EDIT: implement loadCategories()
android-pos/app/src/main/java/com/warkop/pos/data/MenuMapper.kt               # EDIT: categories come from the server list, not from products
android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt                # EDIT: loadMenu loads categories + menu; totals pref; tax values
android-pos/app/src/main/java/com/warkop/pos/ui/screens/EditorScreen.kt       # EDIT: two columns, horizontal strip, totals toggle, labels, tax label, PullToRefreshBox
android-pos/app/src/main/java/com/warkop/pos/ui/screens/OrdersScreen.kt       # EDIT: pull to refresh; order list font (if here)
android-pos/app/src/main/java/com/warkop/pos/ui/screens/TablesScreen.kt       # EDIT: pull to refresh
android-pos/app/src/main/java/com/warkop/pos/ui/screens/ShiftScreens.kt       # EDIT: pull to refresh (Shift)
android-pos/app/src/main/java/com/warkop/pos/ui/screens/PrinterScreen.kt      # EDIT: pull to refresh (Perangkat), if it loads server data
android-pos/app/src/main/java/com/warkop/pos/ui/Common.kt                     # EDIT or NEW: shared refresh wrapper (one implementation)
android-pos/app/src/main/java/com/warkop/pos/ui/ChipFit.kt                    # REUSE (054) for Kirim/Bayar labels
android-pos/app/src/main/java/com/warkop/pos/state/TotalsPreference.kt        # NEW: show/hide state on device
android-pos/app/src/test/java/com/warkop/pos/state/PosStoreCategoriesTest.kt  # NEW
android-pos/app/src/test/java/com/warkop/pos/state/TotalsPreferenceTest.kt   # NEW (or in PosStoreCategoriesTest)
```

**Structure Decision**: extend the existing backend route group and the existing Android counter (`EditorScreen`), with one new endpoint and one new small preference class. No new module, no new screen, no migration.

## Complexity Tracking

> No constitution violations. Nothing to justify.
