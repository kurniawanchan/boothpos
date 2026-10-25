# Implementation Plan: Android Outlet → Terminal → PIN Login

**Classification**: INTERNAL

**Branch**: `073-android-outlet-terminal-login` | **Date**: 2026-10-25 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/073-android-outlet-terminal-login/spec.md`

## Summary

Remove the owner/admin password step from Android terminal setup. The tablet finds the server (unchanged, 065), shows the outlets, then a Terminal field (pick existing, or type a new name → server find-or-registers it under that outlet), then the outlet's cashier/inventory roster and PIN. The outlet/terminal choice is remembered on the device.

Approach: three new **unauthenticated, throttled, read-minimal** server endpoints (outlets, terminals, register-terminal) plus opening the two existing PIN endpoints (`/staff/roster`, `/auth/pin-sessions`) to callers without a token. `DeviceService` and `PinAuthService` stay the only writers/checkers. No migration. Android: replace the admin-login step of `TerminalSetupScreen` with outlet + terminal steps, drop the stored admin token, handle revoked-terminal detection.

## Technical Context

**Language/Version**: PHP / Laravel (server); Kotlin + Jetpack Compose (`android-pos/`). Exact versions: unverified (not read for this plan).

**Primary Dependencies**: Laravel Sanctum (existing token model), Retrofit/OkHttp-style `PosApi` + `RemoteBackend` in `android-pos`. No new dependencies.

**Storage**: MySQL 8 — existing `devices` table (terminal = `Device`, outlet-owned, `HasDataMode`); `users.pin_*` columns for lockout. **No migration.**

**Testing**: `tests/Feature/` against real MySQL (container run MUST use the `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test … -e CACHE_STORE=array -e SESSION_DRIVER=array` flags from CLAUDE.md); Kotlin unit tests in `android-pos`; on-device check of the flow.

**Target Platform**: Laravel API on the store machine/shared server; Android tablets/phones.

**Project Type**: mobile-app + API (existing monorepo layout).

**Performance Goals**: SC-001 (<60 s first-time setup), SC-002 (<10 s returning sign-in). Outlet/terminal/roster lookups are small, single-query lists.

**Constraints**: online-first app (no local order queue, `queue_depth` is reported as 0); setup requires server reachability; UI copy and comments in Indonesian.

**Scale/Scope**: one organisation, a handful of outlets, tens of terminals and staff. 4 new routes, 2 routes de-authenticated, 1 Android screen reworked, 1 constant changed.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Notes |
|---|---|---|
| I. Single write path / logic in services | PASS | Terminal creation stays in `DeviceService` (new `findOrRegister`, shares `assertNameFree`); PIN checking stays in `PinAuthService`. Controllers validate and delegate. |
| II. Testing | PASS (plan) | Feature tests for every new/changed route; container test flags mandatory. On-device verification of the Android flow is required before "done" and is **not yet done**. |
| III. UX consistency | PASS | Indonesian copy; 422/409/403/401/429 convention kept. Web UI untouched. (Android uses its own theme; token rule applies to the web SPA.) |
| IV. Security — outlet never from request | **VIOLATION, justified** | See Complexity Tracking. |
| IV. Security — audit log in same transaction | PASS | Terminal registration and PIN sign-in each write an `ActivityLogger` entry (FR-015). |
| Docs discipline | PASS (plan) | `docs/openapi-pos-mvp.yaml` updated in the same commit as routes; dated notes in RUNBOOK §569 and PRD. |
| V. Performance | PASS | Roster eager-loads role; no N+1. |

**Post-design re-check**: the Principle IV exception is confined to three endpoints that take the outlet as a *path* parameter, validate it is an active outlet, and only (a) list names or (b) find-or-create a terminal name. No business data is readable or writable through them.

## Project Structure

### Documentation (this feature)

```text
specs/073-android-outlet-terminal-login/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── pos-setup-api.md
└── tasks.md             # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
routes/api.php                                   # move roster + pin-sessions out of auth:sanctum; add 3 setup routes
app/Http/Controllers/Api/
├── PosSetupController.php                       # NEW: outlets, terminals, register-terminal (thin)
└── PinAuthController.php                        # roster/login: accept device_id; no token needed
app/Http/Requests/RegisterTerminalRequest.php    # NEW
app/Services/
├── DeviceService.php                            # + findOrRegister(Outlet, name); reuses assertNameFree
└── PinAuthService.php                           # roster excludes owner/admin; LOCK_MINUTES 15→5; login logs sign-in + device
app/Http/Controllers/Api/DeviceController.php    # revoked-terminal detection reused by heartbeat (already 422 device_id)
lang/id/devices.php, lang/id/auth.php            # new messages
docs/openapi-pos-mvp.yaml, docs/RUNBOOK.md       # contract + setup steps
tests/Feature/PosSetupTest.php                   # NEW (outlets/terminals/register/throttle/mode scoping)
tests/Feature/PinLoginTest.php, DevicePairingTest.php   # adjusted

android-pos/app/src/main/java/com/warkop/pos/
├── setup/TerminalSetupScreen.kt                 # admin-login step → outlet + terminal steps
├── data/PosBackend.kt, RemoteBackend.kt, PosApi # listOutlets/terminals/registerTerminal without token; drop adminLogin
├── state/PosStore.kt                            # saved outlet+terminal → straight to roster; change-terminal; revoked handling
└── ui/screens/PinScreen.kt                      # "change outlet / terminal" action; lockout message from server minutes
android-pos/…/test                               # Kotlin unit tests
```

**Structure Decision**: extend the existing Laravel + `android-pos` layout. One new thin controller for the setup routes; all rules live in the two existing services. `android/` (old WebView shell) is not touched.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Outlet taken from a request (path param) on unauthenticated routes, against Constitution IV ("never taken from a request body") | Before sign-in there is no user, assignment or open shift to derive an outlet from; choosing the outlet *is* the feature (spec FR-001–FR-003). | Deriving it from a token would require the owner/admin login the feature removes. The exception is path-only, validated as an active outlet in the current data mode, and exposes names only. **Needs the owner's explicit acceptance in the constitution sense** — recorded here, not amended there. |
| Unauthenticated write (terminal registration) | Spec Q1 = option A, owner-confirmed. | Setup code / admin PIN options (B, C) were offered and declined. Mitigation: `throttle`, name-only data, activity log entry. |
| PIN lock shortened 15 → 5 minutes | Spec Q2 = 5 min. | **Conflict found**: existing behaviour is 15 min and the same constant guards approver PINs (048/S3). Plan applies 5 min to both; see research R4. **Confirmed by the owner 2026-10-26: keep 5 minutes.** |
