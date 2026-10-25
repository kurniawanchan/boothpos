# Research: Set User PIN from the Web Users Screen

**Classification**: INTERNAL

Findings come from reading the current code this session; library versions were not checked (unverified).

## R1 — Where PIN logic lives
- **Today**: `PinAuthService` is documented as "the only place that checks, changes and deletes PINs": `change()` (own PIN; first PIN needs the password), `reset()` (admin clears the PIN, revokes PIN tokens, logs `pin_reset`), `assertStrong()` (6 digits; same-digit and straight runs refused), `revokePinTokens()`. Controller `PinAuthController::reset` guards with `abort_unless(isOwnerOrAdmin)`.
- **Decision**: add `adminSet(User $actor, User $target, string $pin, string $source): bool` (returns whether a PIN already existed) and `suggest(): string` to `PinAuthService`. No second PIN writer.
- **Why**: Constitution I; the weak-PIN rule and lock handling must stay in one place.
- **Alternative rejected**: calling `reset()` then `change()` from the controller (two steps, the user would need their own password; not atomic).

## R2 — Endpoints
- **Decision**: `PUT /users/{user}/pin` with `{pin, source: typed|generated}` → 200 with the updated `UserResource`; `POST /pin-suggestions` → `{pin}`.
- **Why a suggestion endpoint** (not client-side generation): the weak-PIN rule would otherwise be duplicated in JS; server-side `random_int` is a CSPRNG; one implementation (`assertStrong`) decides validity. The suggestion is **not stored**, so "saved only when the admin confirms" (FR-006) holds.
- **Why POST**: responses to POST are not cached by browsers/proxies; a PIN must not sit in a cache.
- **Why not "generate and save in one call"**: the spec says the admin sees the PIN before saving.
- **Existing** `POST /users/{user}/pin-reset` (clear only) stays untouched; the UI does not use it (reset = set a new PIN in one step).

## R3 — Authorization
- **Decision**: `SetUserPinRequest::authorize()` returns `isOwnerOrAdmin()` (menu `settings`); `suggest()` checks the same. Matches the spec ("owner or admin; Users menu alone is not enough") and the existing `pin-reset`.
- **UI**: the action is hidden unless `auth.canAccessMenu('settings')` (cosmetic only; the server is the enforcer — Constitution IV).
- **Self**: an admin may set their own PIN (spec edge case); no self-lockout guard applies (unlike delete/deactivate in `UserPolicy::isSelfLockout`).

## R4 — Audit log
- **Decision**: `ActivityLogger` entry **inside the same transaction**: action `pin_set` (the user had no PIN) or `pin_replaced` (the user had one). The existing clear-only API logs `pin_reset` and the own-PIN change logs `pin_changed`, so all four stay distinguishable. The description names actor and target; `new_values` carries `{source}` only. Never the PIN or hash.
- **Limit**: `source` (typed/generated) is reported by the client; the server cannot prove a PIN was generated. It is advisory in the log. Stated in the log description ("dilaporkan klien") so nobody reads it as proof.
- **Why**: FR-009; a rolled-back change must not leave a log (Constitution IV).

## R5 — Effects of setting a PIN
- Clears `pin_failed_attempts`, `pin_locked_until`; sets `pin_changed_at`; deletes the target's `pos-pin` tokens (`revokePinTokens`) so a tablet session ends (FR-007).
- Row lock: `User::lockForUpdate()` on the target inside the transaction, so two admins saving at once end with one coherent PIN (last wins).
- Password and web (password) tokens are untouched; role/outlets untouched (FR-012).

## R6 — Status in the list
- **Decision**: `UserResource` adds `has_pin` (`pin_hash !== null`) and `pin_locked` (`pin_locked_until` in the future). `UserResource` is only used by `UserController` (verified), so no other response changes. `GET /users` gains `has_pin=1|0` (`whereNotNull`/`whereNull('pin_hash')`).
- **Exposure**: whoever can open the Users list already sees names/usernames; "has a PIN" and "locked" are low sensitivity. The hash is never serialized (`User` hides it).

## R7 — Owner/admin accounts
- The Android roster excludes owner/admin (073), but `OverrideGate::approvers()` selects active users **with a PIN** who hold the permission, in the outlet or with all-outlets roles — owner/admin included. So their PIN is functional (approvals), and the action is offered for every user (spec assumption confirmed by code).
- Inactive users: allowed (spec edge case); they stay out of the Android roster and approver lists while inactive (`is_active` is checked in both).

## R8 — Frontend
- `SetPinModal.vue` (props: `open`, `user`; emits `close`, `saved`): masked numeric input (`inputmode="numeric"`, max 6, show/hide), "Generate" button. A generated PIN is shown in the dialog before saving; after a successful save it **stays visible until the dialog is closed** (so it cannot be lost between confirm and the admin noting it), and is wiped from component state on close. A typed PIN is cleared on close/success.
- `UsersView`: new column `PIN` with `StatusPill` (success "Has PIN" / neutral "No PIN" / danger "Locked"), `FilterBar` select "PIN: all / has PIN / no PIN", row action `Set PIN` or `Reset PIN` (label by `has_pin`).
- Errors: 422 shows the field message (too weak / not 6 digits); 403 handled by the existing central handler (the control is hidden anyway).
- All copy Indonesian (`users.pin_*` in `id.json`, English mirror in `en.json`).

## R9 — Rate limiting
- `PUT /users/{user}/pin` and `POST /pin-suggestions`: `throttle:30,1`. They require an owner/admin token, so this only dampens accidents.

## R10 — What stays out
- A web screen for users to change their own PIN (the API stays; spec says out of scope), forced change at first sign-in (Android has no change-PIN screen), PIN expiry, bulk PIN setting, removing a PIN without replacing it (the old `pin-reset` API remains).

## R11 — Real-browser verification gap
- Constitution II requires exercising a changed screen in a real browser with the console checked. The browser MCP servers (`chrome-devtools`, `playwright`) failed to connect in this session. **Decision**: tasks include the browser check as a final, explicit task; if no tool is available when implementing, it stays open and is reported as not done — never inferred from unit tests.
