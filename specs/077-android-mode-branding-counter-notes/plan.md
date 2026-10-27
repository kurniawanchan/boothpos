# Implementation Plan: Android POS — Mode Data, Store Branding, Counter Polish & Cashier Notes

**Classification**: INTERNAL

**Branch**: `077-android-mode-branding-counter-notes` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

## Summary

Seven tablet changes (`android-pos/`) plus three small, additive server changes. The tablet is mostly a client, but three of the asks cannot be met from the client alone:

1. **Mode** — the server already scopes outlets, terminals, menu and orders by `system_mode` (`HasDataMode`); staff accounts are shared. A tablet set up under LIVE holds an outlet + terminal that do not exist under DEMO, and nothing on the tablet knows the mode. Plan: the tablet reads the mode (pre-login from a new public branding endpoint, post-login from the existing `GET /settings/features`), **keeps one saved terminal config per mode**, shows a DEMO badge, and handles a mid-session switch by asking the cashier to sign out instead of failing as "terminal revoked".
2. **Branding** — pre-login routes expose no store name or logo, so one new token-less read endpoint is added. Post-login the existing `features` payload already has `store_name` and `store_logo_url`.
3. **Order note** — `orders.notes` and `OrderResource.notes` exist, but the tab flow (the only flow the tablet uses) has **no way to write or read it**. One new endpoint plus `notes` on `TabResource`.
4. **Avatar photo** — the staff roster (token-less) omits `photo_url`; one field is added. Post-login `UserDto.photoUrl` already exists.
5. **PIN two columns, draggable divider, price range** — Android UI only.

## Technical Context

**Language/Version**: Kotlin + Jetpack Compose/M3 (`android-pos/app`, DTOs in `core-network`); Laravel 13 / PHP (server).
**Primary Dependencies**: existing only. No new library (images go through the existing in-house loaders, see research R4).
**Storage**: per-mode terminal config in the existing token vault; divider fraction in the existing per-device preference store (pattern: `TotalsPreference`); order note in the existing `orders.notes` column (**no migration**).
**Testing**: PHPUnit feature tests (MySQL, container run with the mandatory `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test … -e CACHE_STORE=array -e SESSION_DRIVER=array` flags); JUnit unit tests in `android-pos/app/src/test` (pattern: `PosStore*Test`, `FakePosBackend`); on-device check per `quickstart.md` (adb not available on this machine — T-checks stay manual).
**Target Platform**: Android tablet (landscape primary), phone (`Size.COMPACT`), medium (stacked).
**Project Type**: mobile client + Laravel API.
**Performance Goals**: branding/avatar images decoded at display size, cached, never block login or ordering; divider drag at display frame rate with no server call.
**Constraints**: all copy Indonesian; money untouched; note limit 1000 chars (matches payment notes); `outlet_id` never read from a request body.
**Scale/Scope**: 1 tablet app, 5 screens touched (Pin, Setup, Shell, Editor, Orders), 3 server endpoints/fields.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I Code quality / single path | PASS | Mode known in one place (`ModeGate` server, one `ModeState` in `PosStore`). Note writes only through a `TabService` method. Image URLs always via `ServerUrl.resolve`. No second price-label function: `priceLabel` is edited in place. |
| II Testing | PASS (plan) | PHP tests: branding, roster photo, note write/read/guards/idempotency, mode isolation. Kotlin tests: per-mode config, mode-change handling, price label, divider clamp, note intent. Visual states are on-device (adb absent → manual). |
| III UX consistency | PASS | Indonesian copy, web store-identity values reused, existing sheets/pills. |
| IV Security | **PASS with one recorded exception** | New `GET /pos-setup/branding` is token-less. Same class as 073's recorded exception: returns only store name, logo URL and mode (all already visible to every logged-in role). Roster `photo_url` is a public-disk URL, same class as `store_logo_url`; **residual risk**: pre-login photo URLs are reachable by anyone who can reach the server and holds the roster URL (ASSUMPTION: acceptable on a LAN-only install; owner can remove photos). Note endpoint: `auth:sanctum`, outlet-scoped through the existing `OrderScope`, role = whoever may edit the tab; audit-logged in the same transaction. |
| V Performance | PASS | No polling added: mode is read from `features` calls the app already makes. Image caches bounded. |
| Docs discipline | PASS (plan) | `docs/openapi-pos-mvp.yaml` updated in the same commit as the three server changes; `RUNBOOK`/`CLAUDE.md` dated note. |

No unjustified violation. Complexity Tracking: the token-less branding endpoint (above).

Re-checked after design: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/077-android-mode-branding-counter-notes/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/api-and-ui-contract.md
└── tasks.md   # /speckit-tasks
```

### Source Code (files touched)

```text
app/Http/Controllers/Api/PosSetupController.php     # + branding()
app/Http/Controllers/Api/PinAuthController.php      # roster + photo_url
app/Http/Controllers/Api/TabController.php          # + note()
app/Http/Requests/UpdateTabNoteRequest.php          # NEW (max 1000, nullable)
app/Http/Resources/TabResource.php                  # + notes
app/Services/TabService.php                         # + setNote() (transaction + ActivityLogger)
routes/api.php                                      # GET /pos-setup/branding, PUT /tabs/{order}/note
docs/openapi-pos-mvp.yaml                           # same commit
tests/Feature/{PosSetupBrandingTest,PinRosterPhotoTest,TabNoteTest}.php   # NEW

android-pos/
├── core-network/.../PosApi.kt, dto/{AuthDtos,TabDtos,ShiftDtos(Features)}.kt   # branding, roster photo, note, features store_*
├── app/src/main/java/com/warkop/pos/
│   ├── data/{PosBackend,RemoteBackend,TokenVault}.kt   # per-mode TerminalConfig, branding(), setNote()
│   ├── model/PosModels.kt                              # StaffUser.photoUrl, Order.note, Branding, SystemMode
│   ├── state/PosStore.kt                               # ModeState, bootstrap by mode, mode-change handling, note intent
│   ├── state/SplitPreference.kt                        # NEW (pattern of TotalsPreference)
│   ├── ui/{Shell.kt,StoreMark.kt(NEW),UserAvatar.kt(NEW),CounterLabels.kt}
│   └── ui/screens/{PinScreen,EditorScreen,TerminalSetupScreen}.kt
└── app/src/test/java/com/warkop/pos/{state,data,ui}/…   # new tests per research
```

**Structure Decision**: Android + three narrow server touch-points. Web SPA untouched (the note already renders from `orders.notes` in Sales detail; verified in quickstart step 8).
