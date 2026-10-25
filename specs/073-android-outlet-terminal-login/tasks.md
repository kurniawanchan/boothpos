---
description: "Task list for feature 073 — Android outlet → terminal → PIN login"
---

# Tasks: Android Outlet → Terminal → PIN Login

**Classification**: INTERNAL

**Input**: Design documents from `/specs/073-android-outlet-terminal-login/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R9), data-model.md, contracts/pos-setup-api.md, quickstart.md

**Tests**: INCLUDED. The constitution (Principle II) requires `tests/Feature/` coverage for backend changes and verification in the real running app before "done".

**Test-run rule (CLAUDE.md, destructive otherwise)**: inside the container always use
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test … -e CACHE_STORE=array -e SESSION_DRIVER=array` (ALL `-e` flags before the service name `app`, not after `php artisan test`). Never run the suite against `warkop_wjm`.

**Organization**: grouped by user story. Backend and Android pieces of one story are separate tasks so each is reviewable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 / US2 / US3 from spec.md

## Path Conventions

- Server: `app/`, `routes/`, `lang/{id,en}/`, `tests/Feature/`, `docs/`
- Android: `android-pos/app/src/main/java/com/warkop/pos/…` (app), `android-pos/core-network/src/main/java/com/warkop/core/network/…` (API + DTOs), tests in `android-pos/app/src/test/java/com/warkop/pos/…`
- Do not touch `android/` (old WebView shell).

---

## Phase 1: Setup

**Purpose**: establish a known-good baseline before changing auth paths.

- [X] T001 Run the baseline backend tests for the areas this feature touches (`PinLoginTest`, `DevicePairingTest`, `DeviceHeartbeatTest`) with the container flags above and record pass/fail counts in `specs/073-android-outlet-terminal-login/evidence-baseline.md`
- [X] T002 [P] Run `./gradlew test` in `android-pos/` and record the baseline result in the same evidence file
- [X] T003 [P] Verify `ApiClient`/`RequestContext` in `android-pos/core-network/src/main/java/com/warkop/core/network/ApiClient.kt` send no `Authorization` header when the token provider returns null (research R2); if they do not tolerate null, note the needed fix in the evidence file and make it in T016

---

## Phase 2: Foundational (blocks all user stories)

**Purpose**: the single server write path and shared client plumbing that US1–US3 all use.

- [X] T004 Add `DeviceService::findOrRegister(Outlet $outlet, string $name): array{device: Device, created: bool}` in `app/Services/DeviceService.php`: one transaction, reuse `assertNameFree` logic split so a duplicate returns the existing active terminal instead of throwing (blank still 422), stamp `outlet_id` server-side, `paired_by` null, log `device_registered` via `ActivityLogger` (null actor) inside the same transaction only when created; keep `pair()` behaviour unchanged (research R1)
- [X] T005 [P] Add Indonesian and English messages used by the new flow in `lang/id/devices.php`, `lang/en/devices.php`, `lang/id/auth.php`, `lang/en/auth.php` (outlet not found, device mismatch, lock message that takes remaining minutes)
- [X] T006 [P] Add DTOs for the new calls (outlet list item, terminal list item, register-terminal request/response, `device_id` on the PIN-login request) in `android-pos/core-network/src/main/java/com/warkop/core/network/dto/AuthDtos.kt`
- [X] T007 Add the three setup endpoints to `android-pos/core-network/src/main/java/com/warkop/core/network/PosApi.kt` (`GET pos-setup/outlets`, `GET pos-setup/outlets/{id}/terminals`, `POST pos-setup/outlets/{id}/terminals`) and `device_id` to the existing PIN-login call
- [X] T008 Extend the `PosBackend` interface in `android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt`: replace `adminLogin` with `discoverOutlets()` / `terminals(outlet)` / `registerTerminal(outlet, name)` (names to match the contract), keep `roster`, `pinLogin`; add a fake implementation used by tests if one exists in `android-pos/app/src/test/`
  - **Done — note:** `PosBackend` now has `outlets()`, `terminals(outlet)`, `registerTerminal(outlet, name)` and `forgetTerminal()`; `FakePosBackend` updated (settable results, `logout` no longer throws).

**Checkpoint**: service method and client interface exist; stories can proceed.

---

## Phase 3: User Story 1 — Pick outlet and terminal (Priority: P1) 🎯 MVP

**Goal**: first screen is the outlet list with no owner/admin login; choosing an outlet reveals a Terminal field (pick or type) and the terminal is saved per outlet.

**Independent Test**: fresh install → outlet list → pick outlet → pick an existing terminal or type a new one → the terminal appears under that outlet on the server; no username/password prompt.

### Tests for User Story 1

- [X] T009 [P] [US1] Feature test `tests/Feature/PosSetupTest.php`: outlets list returns only active outlets of the current data mode, name+id only, no token needed
- [X] T010 [P] [US1] In `tests/Feature/PosSetupTest.php`: terminals list returns only active terminals of that outlet (revoked and other outlets excluded), no device metadata; unknown/inactive outlet → 404
- [X] T011 [P] [US1] In `tests/Feature/PosSetupTest.php`: register new name → 201 `created:true` under that outlet; same name with other case/spaces → 200 same id, `created:false`, no second row; blank → 422; same name allowed in a different outlet; name of a revoked terminal creates a new record; concurrent duplicate registration yields one row (SC-004); activity-log row `device_registered` written with null actor
- [X] T012 [P] [US1] In `tests/Feature/PosSetupTest.php`: DEMO/LIVE isolation (a terminal registered in one data mode is not listed in the other) and throttle returns 429 past the limit
- [X] T013 [P] [US1] Kotlin unit test `android-pos/app/src/test/java/com/warkop/pos/setup/TerminalSetupStepsTest.kt`: step order is SERVER → OUTLET → TERMINAL; Terminal field absent until an outlet is chosen; typed name is trimmed and blank is not submittable; existing terminal selectable; single-outlet install pre-selects the outlet but still shows Terminal
  - **Done — note:** Written as `app/src/test/java/com/warkop/pos/setup/SetupRulesTest.kt` (6 tests). The screen is ONE screen (outlet list, then the Terminal field appears), not a stepper, so the test covers visibility/name/submit rules in `SetupRules`, not step order.

### Implementation for User Story 1

- [X] T014 [US1] Add `app/Http/Requests/RegisterTerminalRequest.php` (name required, string, trimmed, max = devices.name column length) and `app/Http/Controllers/Api/PosSetupController.php` (`outlets`, `terminals`, `registerTerminal`): thin, validates outlet via `Outlet::where('is_active', true)->findOrFail`, delegates to `DeviceService::findOrRegister`, returns 201/200 per `contracts/pos-setup-api.md`; outlet comes from the path only (Constitution IV exception, plan Complexity Tracking)
- [X] T015 [US1] Register the three routes in `routes/api.php` outside `auth:sanctum` with `throttle` (reads 30/min, register 10/min, tuned in T012); add a comment explaining the Constitution IV exception and citing spec Q1
- [X] T016 [US1] Implement the new `PosBackend` calls in `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`: no bearer token, `outletId` set only after the user picks, save `OUTLET_ID/OUTLET_NAME/DEVICE_ID/DEVICE_NAME` in `TokenVault` after register/select; remove `adminLogin` and the admin-token path in `api()` (apply the T003 fix here if needed)
- [X] T017 [US1] Rework `android-pos/app/src/main/java/com/warkop/pos/setup/TerminalSetupScreen.kt`: SERVER step becomes discovery only (keep `DiscoveryProblem` states), remove username/password fields; OUTLET step lists outlets (empty and unreachable states with retry); TERMINAL step shows a list of the outlet's terminals plus a "Nama terminal baru" text field (typing a new name overrides the selection); Indonesian copy; call `onDone(TerminalConfig)`
  - **Done — note:** Single-screen design (discovery states → outlet radios → Terminal field after an outlet is chosen); optional `notice` and `onCancel` parameters added. Compose UI itself is compile-checked only — not run on a device.
- [X] T018 [US1] Update `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` and `MainActivity.kt` wiring so a device with no saved outlet/terminal starts at the new setup screen and a completed setup goes to the PIN screen

**Checkpoint**: US1 works alone — a fresh tablet reaches the PIN screen with an outlet and a persisted terminal.

---

## Phase 4: User Story 2 — Choose user and sign in with PIN (Priority: P1)

**Goal**: the roster shows only that outlet's cashier/inventory users, PIN signs in on the chosen outlet and terminal, 5 wrong PINs lock the user for 5 minutes.

**Independent Test**: with an outlet+terminal chosen, only cashier/inventory appear; correct PIN signs in; 5 wrong PINs lock with a visible wait.

### Tests for User Story 2

- [X] T019 [P] [US2] Update `tests/Feature/PinLoginTest.php`: roster works without a token; roster excludes owner/admin (users that `isOwnerOrAdmin()`), including an all-outlets role without `settings`, which stays listed; owner/admin PIN login → generic 401 with no counter increment; has_pin/locked flags unchanged
- [X] T020 [P] [US2] In `tests/Feature/PinLoginTest.php`: `device_id` required; terminal of another outlet, revoked, or unknown → 422 `device_id`; success writes a `pin_login` activity-log entry naming user, outlet and terminal inside the same transaction
- [X] T021 [P] [US2] In `tests/Feature/PinLoginTest.php`: 5 wrong PINs → 429 `pin_locked` with `locked_until` ≈ 5 minutes ahead, applies across devices, correct PIN resets the count; approver-PIN path (`verifyApproverPin`) uses the same 5-minute lock; adjust any existing assertion that expects 15 minutes
- [X] T022 [P] [US2] Kotlin unit test `android-pos/app/src/test/java/com/warkop/pos/state/PinLockMessageTest.kt`: lock message uses the server's `locked_until` (remaining minutes), not a hard-coded number
  - **Done — note:** `PinLockMessageTest` (4 tests); the message is built by `PinLockMessage` from the server's `locked_until`.

### Implementation for User Story 2

- [X] T023 [US2] `app/Services/PinAuthService.php`: roster excludes `isOwnerOrAdmin()` users (one place, so `login()` eligibility follows), `LOCK_MINUTES` 15 → 5 with a comment citing spec Q2 and that approver PINs share it, `login()` accepts the terminal and logs `pin_login` in the transaction
- [X] T024 [US2] `app/Http/Controllers/Api/PinAuthController.php`: validate `device_id`, check it is an active terminal of `outlet_id` (422 `device_id` via the same message heartbeat uses), pass the terminal to the service
- [X] T025 [US2] `routes/api.php`: move `GET /staff/roster` and `POST /auth/pin-sessions` out of the `auth:sanctum` group (keep `throttle:30,1`); update the stale comment that says a terminal token is required
- [X] T026 [US2] `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`: roster and PIN login without bearer, send `device_id`, drop the admin-token retry in `roster()`/`dropSession` flow that falls back to `adminToken`
- [X] T027 [US2] `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt` + `ui/screens/PinScreen.kt`: lock message from `locked_until`; disable has-no-PIN users with a visible reason (FR-011); sign-out returns to the roster of the same outlet+terminal (not outlet selection)

**Checkpoint**: US1+US2 deliver the full flow on a fresh tablet.

---

## Phase 5: User Story 3 — Remember outlet/terminal, change, recover (Priority: P2)

**Goal**: relaunch and sign-out go straight to the roster; "change outlet / terminal" resets; invalid or revoked terminals are handled (FR-012, FR-013, FR-018).

**Independent Test**: complete setup, relaunch → roster; change → outlet screen with server URL kept; revoke the terminal on the web mid-session → finish the open tab, no new tab, then outlet screen with a message.

### Tests for User Story 3

- [X] T028 [P] [US3] Regression test in `tests/Feature/DeviceHeartbeatTest.php`: revoked terminal → heartbeat 422 `device_id`; and in `tests/Feature/PosSetupTest.php` (or the tab tests) open-tab with a revoked `X-Device-Id` → 422 `device_id` (server enforcement already exists, research R8). **Already covered by existing tests**, no new test written: `DeviceHeartbeatTest::test_a_revoked_device_is_refused` and `TabOpenPosFieldsTest::test_an_unknown_or_revoked_device_is_rejected`
- [X] T029 [P] [US3] Kotlin unit test `android-pos/app/src/test/java/com/warkop/pos/state/TerminalRevokedRuleTest.kt`: 422 `device_id` from heartbeat or open-tab sets the revoked state; revoked state blocks new order/tab, allows finishing the open tab, then requests sign-out to the outlet screen
  - **Done — note:** `TerminalRevokedRuleTest` (7 tests) covers the rule and the navigation decision as pure functions, plus `PosStoreSetupTest` (4 tests: first-launch → setup, login refused with 422 `device_id` → back to setup, ordinary wrong PIN does not). **Not covered by an automated test:** the heartbeat/open-tab → `markTerminalRevoked()` path inside `PosStore` (needs a signed-in store with many stubs); that path is only compile-checked and awaits T037.
- [X] T030 [P] [US3] Kotlin unit test `android-pos/app/src/test/java/com/warkop/pos/data/TerminalMigrationTest.kt`: upgrade path clears the stored admin token and keeps a valid saved outlet/terminal
  - **Done — note:** `LegacyVaultCleanupTest` (2 tests) proves only the admin token key is cleared and the outlet/terminal/URL/session keys are not. The real `TokenVault` (Android Keystore) is not exercised by a JVM test.

### Implementation for User Story 3

- [X] T031 [US3] `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`: add a narrow reset (clears outlet+device+session, keeps server URL) next to `resetTerminal()`; clear `TokenVault.ADMIN_TOKEN` on first launch of this version (research R2)
  - **Done — note:** The old unused `resetTerminal()` was removed and replaced by `forgetTerminal()` (keeps the server URL); admin-token cleanup runs in `RemoteBackend.init`.
- [X] T032 [US3] `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt`: map 422 `device_id` (heartbeat and open-tab) to a `terminalRevoked` state per research R8; on a roster/heartbeat answer that the saved terminal or outlet is invalid at launch, return to the outlet screen with a message (FR-013)
- [X] T033 [US3] `android-pos/app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt`: "Ganti outlet / terminal" action and a banner for the revoked/invalid message; hide the new-order control while revoked
  - **Done — note:** Added a "Ganti outlet / terminal" button to the PIN entry column (phones had no panel with the gear icon) and renamed the gear's description. The revoked/invalid message is shown on the SETUP screen (`setupNotice`), not as a banner on the PIN screen, because the tablet returns to the outlet screen (FR-013/FR-018). Also: staff without a PIN are shown dimmed with "Belum ada PIN" and cannot be selected (FR-011); previously they were hidden.

**Checkpoint**: all three stories work independently.

---

## Phase 6: Polish & Cross-Cutting

- [X] T034 [P] Update `docs/openapi-pos-mvp.yaml` in the same change as the routes: three new paths, `device_id` on `/auth/pin-sessions`, roster/pin-sessions marked without auth, 429 lock description (constitution: contract moves with routes)
  - **Done — note:** `docs/openapi-pos-mvp.yaml` parses as YAML; three new paths, `SetupTerminal` schema, roster/pin-sessions updated (no token, `device_id`, 5-minute lock). Not validated with an OpenAPI linter.
- [X] T035 [P] Update `docs/RUNBOOK.md` (setup steps near §569 that say "Masuk dengan akun owner atau admin") and add a dated note to `docs/PRD-POS-Event-Multivendor.md`; add a dated 073 note under "Terminals (064)" in `CLAUDE.md`
  - **Done — note:** RUNBOOK §13.1 and §13.4, PRD dated note (2026-10-26) and the CLAUDE.md Terminals bullet updated.
- [X] T036 Run the full backend suite with the container flags and `./gradlew test`; record counts and any failures **verbatim** in `specs/073-android-outlet-terminal-login/evidence-final.md` (do not summarise failures away)
  - **Done — note:** full backend suite 2087 passed / 1 failed / 1 skipped; the failure is `ShiftReportParityTest` (shift summary `expected_cash`), unrelated to 073 and matching a failure recorded as pre-existing on 2026-10-24 but NOT re-proved on a clean tree. Android 337/337 per variant. Details in `evidence-final.md`.
- [ ] T037 On-device verification per `quickstart.md` steps 2–7 (fresh install, new + existing terminal, shared terminal, lockout, relaunch, change terminal, revoke mid-session, upgrade over an old install); record what was actually observed. If no device is available, state that explicitly in the evidence file and leave this task open
- [X] T038 Security re-check against the plan's residual risks: confirm the three setup routes and the two de-authenticated routes expose only the fields in `contracts/pos-setup-api.md` (no usernames/emails/phones/hashes), and that throttling is active; note the accepted risks (open registration, per-user-only lock) in the evidence file
  - **Done — note:** Checked read-only against the running dev server (:8010), no writes: outlets and terminals return only `id`/`name`; roster returns `id,name,role,has_pin,locked` only and only the `Kasir` role; unknown outlet → 404; PIN login without `device_id` → 422 on `device_id`. Throttling is proven by `PosSetupTest` only (not re-checked live). Accepted residual risks stand: open registration, per-user-only PIN lock. **Automated security review (2026-10-26, not a user message) re-flagged them:** (1) roster + PIN login without a device credential, and `device_id` is a guessable integer so it authenticates nothing; (2) open terminal registration that returns existing terminals; (3) lock shortened to 5 min and no per-outlet/global failed-PIN limit (an attacker can try 5 PINs on every listed user in turn). Items (1)–(2) are owner decisions from spec Q1 (device-credential options declined); the 5-minute lock was owner-confirmed on 2026-10-26 after being advised to keep 15. Nothing was changed; follow-up options if the owner wants them: admin-approved pairing with a per-device secret, a per-outlet failed-PIN limit, or a longer/escalating lock.

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** → stories. Phase 2 blocks everything.
- **US1** needs T004–T008. **US2** needs T004–T008 and, for the Android half, the US1 setup screen reaching the PIN screen (T017–T018); its backend half (T019–T025) is independent of US1 and can run in parallel with it.
- **US3** needs US1 + US2 (it changes their screens and relies on the PIN session).
- **Phase 6** last; T037 needs a built APK containing all stories.
- Same-file serialisation: `routes/api.php` (T015, T025), `RemoteBackend.kt` (T016, T026, T031), `PosStore.kt` (T018, T027, T032), `PinScreen.kt` (T027, T033), `PinLoginTest.php` (T019–T021), `PosSetupTest.php` (T009–T012, T028) — do not parallelise within a file.

## Parallel Opportunities

- Phase 1: T002 ∥ T003. Phase 2: T005 ∥ T006 (T004, T007, T008 are sequential per file).
- US1 tests T009–T013 can be written together before T014–T015.
- US1 backend (T014–T015) ∥ US2 backend (T019–T025) — different files except `routes/api.php`.
- US3 tests T028–T030 are independent of each other.
- T034 ∥ T035.

## Implementation Strategy

1. **MVP = Phases 1–4 (US1 + US2)**: both are P1 and the flow is only useful with both. Stop and run quickstart steps 2–4 on a device before continuing.
2. Add **US3** (remember/change/revoked) as the second increment.
3. Polish, contract docs and full-suite evidence last. Nothing is "done" until T036 and T037 have recorded evidence from this work session (Constitution II).

## Notes

- Tasks T004/T023 share `DeviceService`/`PinAuthService` conventions: do not add a second creation or PIN-check path (Constitution I).
- Comments and UI copy are Indonesian, explaining *why* and citing spec/research items.
- Open owner decisions already resolved: lock = 5 minutes (confirmed 2026-10-26), open registration (Q1), roster excludes owner/admin (Q3).
