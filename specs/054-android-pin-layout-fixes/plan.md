# Implementation Plan: Android POS Phase 1 Layout, Connection and Server Address Fixes

**Branch**: `054-android-pin-layout-fixes` | **Date**: 2026-10-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/054-android-pin-layout-fixes/spec.md`

## Summary

Three changes on the Android sign-in screen and one backend addition:

1. **Full outlet name on one line** (US1, FR-001/002): the terminal chip measures its text and shrinks the font to fit; if even the smallest readable size does not fit, the chip moves to a full-width row above the keypad. Nothing is cut or wrapped.
2. **Honest connection state** (US2, FR-003–007): the chip starts as "unknown" instead of "Online", and the sign-in staff-list request and a short periodic check on the PIN screen drive it. The banner and the chip read the same state.
3. **Admin-configured server address** (US3, FR-008–014): the backend stores a local address, an online address and the selected choice; owners and admins change them through a dedicated API used by the admin settings page. Tablets learn the selected address from a device-scoped endpoint, and adopt it only after a successful contact (option A, decided 2026-10-17).
4. **PIN area scrolls** (US4, FR-015–018): the sign-in content scrolls as one area when it does not fit; entered digits live in store state, so scrolling cannot clear them.

Technical approach: the backend change is additive (three `settings` rows, one service, one request class, two admin routes, one device route, an audit row). The Android change is confined to the sign-in screen, `PosStore` connection state, and `RemoteBackend`'s stored server URL. No database migration.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (backend); Kotlin with Jetpack Compose, Material 3 (Android POS, `android-pos/`); Vue 3 SPA (admin settings page)

**Primary Dependencies**: Compose BOM `2024.09.00` (`android-pos/gradle/libs.versions.toml`), kotlinx-serialization, Retrofit (`core-network`); Laravel FormRequest, Eloquent `Setting` model, `ActivityLogger`

**Storage**: MySQL 8, existing `settings` table (key/value, `type`, `group`; no `HasDataMode`, same as `app_name`). Android: `TokenVault` (encrypted preferences) already holds `SERVER_URL`.

**Testing**: `php artisan test` against MySQL test DB (inside Docker with the mandatory `-e` overrides from CLAUDE.md); `qa-tests/` Vitest for the admin page; Android JVM unit tests (`testDebugUnitTest`) for `PosStore` connection transitions and the chip-fit function; manual device check for layout and Wi-Fi-off behaviour.

**Target Platform**: Android tablet (pilot: landscape, one reference device); browser for the admin page; Laravel server on localhost (dev/store) or a shared live server (shape b).

**Project Type**: Mobile app (Android POS) + web backend API + SPA admin page, in one repo.

**Performance Goals**: connection check on the sign-in screen at most every 10 s while idle; the chip resolves to a state within one request timeout of Wi-Fi changing.

**Constraints**: works in both supported shapes (constitution Stack section): default selection `local` and empty addresses mean the tablet keeps the address it was paired with, so shape (a) is unchanged. Sign-in must not require a session. Layout must not change when content fits (FR-018).

**Scale/Scope**: one organisation, one server, one database; a handful of tablets per outlet. One admin settings section, one device endpoint, one sign-in screen.

## Constitution Check

*GATE: checked before Phase 0 and again after Phase 1 design.*

| Principle | Check | Result |
|---|---|---|
| I. Code quality | Server-address reads and writes go through ONE service (`App\Support\ServerAddress`); controllers only validate and delegate. The Android connection state has one writer (`PosStore`), and `Conn` is the single enum the chip and banner read. | PASS |
| II. Testing | Backend feature tests under `tests/Feature/`, against MySQL. Android unit tests for transitions and fit logic. The admin page change is exercised in a real browser against the real API, with the console checked (quickstart step). | PASS (verification pending, see quickstart) |
| III. UX consistency | Admin page uses existing tokens and components; Indonesian copy for every new string; errors use 422 with field messages; hidden for non-admin roles (no disabled control). | PASS |
| IV. Security | Admin-only read and write, enforced server-side (`SettingPolicy`, plus the route group), not by hiding the page. The device endpoint returns only the **selected** URL, never the stored local/online pair or any credential. Changes write an `activity_logs` row in the same transaction. Outlet is not involved. | PASS |
| V. Performance | Probe is one lean request every 10 s while on the PIN screen; no N+1; the device endpoint reads three settings rows. | PASS |
| Stack shape | Default `local` with empty addresses keeps shape (a) unchanged; shape (b) is what the admin selection serves. | PASS |
| Documentation | `docs/openapi-pos-mvp.yaml` must move in the same commit as the routes (constitution, Documentation section). | PASS (task to include) |

**Gate result**: PASS, no violations. Complexity Tracking is not needed.

**Assumption carried into design (not yet confirmed by the product owner)**: the two addresses are two ways to reach the **same** backend and database (one server per organisation, constitution). If they were ever two different servers, data would split; that would be a constitution question, not a plan detail. See research.md R4.

## Project Structure

### Documentation (this feature)

```text
specs/054-android-pin-layout-fixes/
├── plan.md              # This file
├── research.md          # Phase 0: decisions and alternatives
├── data-model.md        # Phase 1: settings rows, connection state, terminal config
├── quickstart.md        # Phase 1: how to verify each story
├── contracts/
│   └── server-address.md  # Phase 1: admin and device endpoints
├── checklists/
│   └── requirements.md
└── tasks.md             # NOT created here (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Support/ServerAddress.php                 # NEW: the single reader/writer of the three settings rows
├── Http/Requests/UpdateServerAddressRequest.php   # NEW: URL and selection validation (422)
├── Http/Controllers/Api/ServerAddressController.php # NEW: admin GET/PUT; device GET (selected URL only)
└── (routes/api.php)                          # 2 admin routes under settings menu, 1 device route next to /devices/heartbeat

tests/Feature/ServerAddressTest.php           # NEW: validation, role refusal, audit row, device response shape

docs/openapi-pos-mvp.yaml                     # EDIT: three new operations (same commit)

resources/js/
├── api/settings.js                           # EDIT: getServerAddress / saveServerAddress
└── views/SettingsView.vue (+ a section)      # EDIT: "Alamat server" section, owner/admin only
qa-tests/                                     # NEW: Vitest for the section's form validation

android-pos/
├── app/src/main/java/com/warkop/pos/state/PosStore.kt
│     # EDIT: Conn.UNKNOWN initial state; roster result drives conn; probe on PIN screen; adopt selected URL
├── app/src/main/java/com/warkop/pos/data/RemoteBackend.kt
│     # EDIT: fetch selected server URL (device endpoint); persist via TokenVault.SERVER_URL; rebuild API client
├── app/src/main/java/com/warkop/pos/data/PosBackend.kt
│     # EDIT: interface gains serverEndpoint(); SampleBackend returns null (no change in behaviour)
├── app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt
│     # EDIT: BrandPanel chip fits the name (measured font step, then full-width row); sign-in content
│     #       wrapped in a vertical scroll; chip label shows "Memeriksa koneksi…" while unknown
├── app/src/main/java/com/warkop/pos/ui/ChipFit.kt
│     # NEW: pure function chooseFontSize(...) → size or null (move), unit-testable
└── app/src/test/java/com/warkop/pos/state/PosStoreConnectionTest.kt
      # NEW: fake PosBackend drives ONLINE/OFFLINE/UNKNOWN and address adoption

.specify/memory/ and CLAUDE.md             # EDIT: SPECKIT pointer to this plan (agent context)
```

**Structure Decision**: extend the existing web backend (`app/`, `routes/api.php`), the existing SPA settings view, and the existing Android sign-in files. No new module, no new table, no new Android screen. The only new Kotlin logic outside `PinScreen`/`PosStore` is the pure fit function, kept separate so it can be unit-tested without a device.

## Complexity Tracking

> No constitution violations. Nothing to justify.
