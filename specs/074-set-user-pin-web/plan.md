# Implementation Plan: Set User PIN from the Web Users Screen

**Classification**: INTERNAL

**Branch**: `074-set-user-pin-web` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/074-set-user-pin-web/spec.md`

## Summary

Give owners and admins a "Set PIN" / "Reset PIN" action on the web Users screen, a PIN-status column ("Has PIN" / "No PIN" / "Locked") and a "No PIN" filter. The admin types a 6-digit PIN or presses "Generate" for a random one shown once. Setting a PIN clears the lock and ends the user's PIN sessions, and is audit-logged without the PIN.

Approach: two new thin endpoints (`PUT /users/{user}/pin`, `POST /pin-suggestions`) on top of `PinAuthService`, which stays the only place that writes or checks PINs. `UserResource` gains `has_pin` and `pin_locked`; `GET /users` gains a `has_pin` filter. Frontend: a `SetPinModal` component and a column, filter and row action in `UsersView`. No migration.

## Technical Context

**Language/Version**: PHP / Laravel (server); Vue 3 SPA with Pinia, vue-i18n, Tailwind v4 (`resources/js/`). Exact versions: unverified (not read for this plan).

**Primary Dependencies**: none new. Reuses `BaseModal`, `BaseInput`, `BaseButton`, `StatusPill`, `FilterBar`, `BaseSelect`, `DataTable`, `useToastStore`.

**Storage**: MySQL 8 — existing `users` columns `pin_hash`, `pin_failed_attempts`, `pin_locked_until`, `pin_changed_at`; existing `activity_logs`. **No migration.**

**Testing**: PHPUnit feature tests in `tests/Feature/` against real MySQL (container flags: all `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array` BEFORE the service name `app`); Vitest component tests in `qa-tests/component/`; real-browser check of the Users screen (required by Constitution II).

**Target Platform**: web SPA served by the Laravel app on the store machine / shared server.

**Project Type**: web application (API + SPA, same repo).

**Performance Goals**: SC-001 (<30 s to give a cashier a PIN). The two new response fields come from columns already loaded; no extra query.

**Constraints**: UI copy and comments in Indonesian; no raw hex colors; the PIN must never appear in a list, log, activity entry or response other than the one-time suggestion/confirmation.

**Scale/Scope**: tens of users per organisation. 2 routes, 2 resource fields, 1 filter, 1 component, 1 view change, 2 locale files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Notes |
|---|---|---|
| I. Single write path / logic in services | PASS | `PinAuthService` (the only PIN writer/checker) gains `adminSet()` and `suggest()`; the weak-PIN rule stays in its one `assertStrong`. Controller stays thin. |
| II. Testing | PASS (plan) / **GAP** | Feature + Vitest tests planned. The constitution also requires a real-browser check of the changed screen; the browser MCP tools (`chrome-devtools`, `playwright`) failed to connect in this environment, so that check has no tool yet — see research R11. |
| III. UX consistency | PASS | Design tokens only; Indonesian copy; 422/403/409 conventions; the action is hidden (not disabled) for users who cannot use it. |
| IV. Security | PASS | Authorization enforced server-side (`FormRequest::authorize()` → `isOwnerOrAdmin()`); UI hiding is cosmetic. Audit entry in the same transaction as the change. PIN never logged. No outlet-from-request issue (the target is a user id). |
| V. Performance | PASS | No N+1 (`has_pin`/`pin_locked` derive from the loaded user row). List stays lean. |
| Docs discipline | PASS (plan) | `docs/openapi-pos-mvp.yaml` in the same commit as the routes; RUNBOOK §13 note. |

**Post-design re-check**: no violation; nothing to justify in Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/074-set-user-pin-web/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── user-pin-api.md
└── tasks.md             # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
routes/api.php                                  # PUT /users/{user}/pin, POST /pin-suggestions (inside auth:sanctum)
app/Http/Controllers/Api/PinAuthController.php  # + setUserPin(), suggest() — thin
app/Http/Requests/SetUserPinRequest.php         # NEW: authorize() owner/admin; pin 6 digits; source typed|generated
app/Services/PinAuthService.php                 # + adminSet(), suggest(); reuses assertStrong, revokePinTokens
app/Http/Resources/UserResource.php             # + has_pin, pin_locked
app/Http/Controllers/Api/UserController.php     # index(): has_pin filter
lang/{id,en}/auth.php                           # new messages if any (pin_set_ok …)
docs/openapi-pos-mvp.yaml, docs/RUNBOOK.md      # contract + admin how-to
tests/Feature/UserPinAdminTest.php              # NEW

resources/js/api/users.js                       # setUserPin(), suggestPin()
resources/js/components/users/SetPinModal.vue   # NEW: type or generate, one-time display
resources/js/views/UsersView.vue                # PIN column, "No PIN" filter, row action (hidden unless owner/admin)
resources/js/locales/id.json, en.json           # users.pin_* keys
qa-tests/component/SetPinModal.test.js          # NEW
qa-tests/component/UsersView.test.js            # extended (column, filter, action visibility)
```

**Structure Decision**: extend the existing Laravel + SPA layout. The modal is a new component rather than more lines in the 366-line `UsersView.vue`. No new service class: PIN logic belongs in `PinAuthService`.

## Complexity Tracking

None — no constitution violation to justify.
