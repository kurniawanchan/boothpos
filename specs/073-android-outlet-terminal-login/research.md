# Research: Android Outlet → Terminal → PIN Login

**Classification**: INTERNAL

Findings are from reading the current code (this session); versions of Laravel/Kotlin libraries were not checked (unverified).

## R1 — How is a terminal created today?
- **Decision**: reuse `Device` (table `devices`) and `DeviceService` as the only create path. Add `DeviceService::findOrRegister(Outlet $outlet, string $name): array{device, created}`.
- **Why**: `DeviceService::pair` derives the outlet from `OutletContext::singleId()` and logs with a `User`; both need an authenticated user. Name rules (trim, case-insensitive uniqueness among active devices of the outlet+mode, row lock on the outlet) already live in `assertNameFree` — Constitution I forbids a second implementation.
- **Behaviour change vs. `pair`**: a duplicate name **returns the existing active terminal** (FR-006/FR-017) instead of 422; blank still 422. `pair` (web owner/admin) keeps its 422 on duplicates.
- **Alternatives**: a second "pairing" path in the controller (rejected: duplicates the rule); making `pair` itself idempotent (rejected: would silently change the owner/admin flow and `DevicePairingTest`).

## R2 — No token before sign-in: what replaces the admin token?
- **Today**: the tablet stores an owner/admin Sanctum token (`TokenVault.ADMIN_TOKEN`) and uses it as bearer for `/staff/roster`, `/auth/pin-sessions`, `/devices/server-address`, heartbeat prep. `RequestContext` sends `sessionToken ?: adminToken`.
- **Decision**: the new pre-sign-in calls send **no** bearer. Routes: `GET /pos-setup/outlets`, `GET /pos-setup/outlets/{outlet}/terminals`, `POST /pos-setup/outlets/{outlet}/terminals`, plus `GET /staff/roster` and `POST /auth/pin-sessions` moved outside `auth:sanctum`. All `throttle`d.
- **Why**: Sanctum needs a user; the product owner removed the admin login (spec Q1).
- **Consequence for existing tablets**: on upgrade, delete the stored admin token from the vault (a long-lived owner/admin credential on a shared tablet is a standing risk and is no longer needed). The server-side token is not revoked by this change.
- **Verify in implementation**: `ApiClient`/`RequestContext` tolerate a null bearer.

## R3 — Outlet scope with no user
- `OutletContext` with no user applies **no** narrowing: all active outlets of the current data mode are "permitted" (`computePermitted`). `ResolveOutletContext` passes through when `$request->user() === null`. `Outlet::query()` is data-mode scoped.
- **Decision**: the controller validates `{outlet}` as an active outlet (`Outlet::where('is_active', true)->findOrFail`, same as `PinAuthController::roster`) and passes the model to the service. No `X-Outlet-Id` is read on these routes.
- **Constitution IV**: see plan Complexity Tracking.

## R4 — PIN lockout (conflict with Q2 answer)
- **Found**: `PinAuthService::MAX_ATTEMPTS = 5`, `LOCK_MINUTES = 15`; per-user counter on `users.pin_failed_attempts/pin_locked_until`, one transaction with `lockForUpdate`; the same `verifyAndCount` guards approver PINs (`verifyApproverPin`) and PIN change. The Android message hard-codes "dikunci 15 menit".
- **Spec says** 5 wrong → 5 minutes (Q2 option B was proposed without this fact).
- **Decision (confirmed by the owner 2026-10-26, "keep 5")**: set `LOCK_MINUTES = 5` as spec'd, and make the Android message use the server's `locked_until` instead of a hard-coded number. Note this also shortens the lock for approver PINs and PIN change.
- **Alternative**: keep 15 minutes (stronger, no change to approver PINs) and amend the spec. Recommended if the owner has no reason for 5.
- Pre-login exposure makes the per-user lock the main defence; there is **no per-IP/per-device lock** (a guesser can rotate across users, 5 tries each). Accepted by the owner under Q1; flagged as residual risk.

## R5 — Roster: exclude owner/admin
- **Today**: `PinAuthService::roster` returns active users assigned to the outlet **or** whose role has `all_outlets` (owner/admin). `login()` uses the same roster for eligibility.
- **Decision**: exclude users where `isOwnerOrAdmin()` (= can access the `settings` menu, `User::isOwnerOrAdmin`). Implemented once in `roster`, so both the listing and `login()` refuse owner/admin (generic 401, no counter).
- **Why not `all_outlets`**: a custom role may see all outlets without being an admin; the spec says owner/admin, and the codebase defines that as the `settings` menu.
- **Edge**: a custom role holding `settings` is treated as admin — consistent with the rest of the app.
- **Existing tablets**: owner/admin staff who used PIN on Android lose access there (spec Q3, owner-confirmed). Called out in release notes.

## R6 — Binding sign-in to the chosen terminal (FR-009, FR-015)
- **Decision**: `POST /auth/pin-sessions` accepts `device_id`; the server requires an **active** terminal of that outlet in the current mode, else 422 `device_id` (same code heartbeat uses). The activity log records `pin_login` with outlet, terminal name and user inside the login transaction.
- **Why**: so a PIN login cannot name a revoked terminal or one from another outlet, and the activity log records which terminal was used. **Limit (corrected after the 2026-10-26 automated security review):** `device_id` is a guessable integer, not a secret — this check prevents mistakes and revoked terminals, NOT an attacker, who can use any active terminal id. Real device authentication (a per-device secret from an admin-approved pairing) was offered in spec Q1 (options B/C) and declined by the owner.
- **Compatibility**: tablets paired before this feature send no `device_id` at login in older builds; the new app always sends it. Server tolerance for a missing `device_id` during rollout: **decision — require it** (a version skew means the tablet must update; heartbeat already carries `app_version`). To confirm.

## R7 — Remembering and changing the choice (US3)
- Saved today: `serverUrl`, `outletId`, `outletName`, `deviceId`, `deviceName` in `TokenVault`; `savedConfig()` returns null → setup screen. **Decision**: unchanged storage; the setup screen now has steps SERVER(discovery only) → OUTLET → TERMINAL. "Change outlet / terminal" on the PIN screen clears outlet+device but **keeps the server URL** (`resetTerminal()` today also wipes the URL; add a narrower reset).
- Invalid saved terminal at launch (FR-013): `roster`/heartbeat answer 422 `device_id` or the outlet 404 → return to the outlet screen with a message.

## R8 — Revoked while signed in (FR-018)
- **Found**: heartbeat already returns 422 `device_id` for a revoked terminal, but `PosStore.heartbeatOnce` treats every `ApiError` as a failure and ignores it; there is no local order queue (online-first), so "unsynced orders" is effectively empty.
- **Decision**: map 422 `device_id` on heartbeat to a `terminalRevoked` flag in `PosStore`: disable "new order/tab", let the open tab finish, then sign out and show the outlet screen with a message.
- **Server enforcement (verified)**: `TabService::open` (line ~72) already rejects a revoked/unknown `X-Device-Id` with 422 `device_id`; `CashierSessionController` does the same for shift opening. So "no new order" is already server-enforced; the client only needs to detect the 422 (heartbeat or open-tab) and move to the revoked state. No server change for FR-018 beyond a regression test.

## R9 — What stays out
- Web terminal screens, rename/revoke (owner/admin), `POST /devices/pairings` (web), server-address admin settings, the old `android/` WebView shell.
- Optional hardening not in spec: cap on terminals per outlet, per-IP failure counter. Listed for the owner; not planned.
