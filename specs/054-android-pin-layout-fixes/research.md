# Research: Android POS Layout, Connection and Server Address (054)

Status legend: **Decision** = chosen; **Verified** = read in code this session; **ASSUMPTION** = not yet confirmed (labelled per the organisation's output rules).

## R1. Where the server address lives

- **Decision**: three rows in the existing `settings` table, group `server`: `server_address_local`, `server_address_online` (strings, nullable) and `server_address_selected` (`local` | `online`, default `local`). Read and written only through `App\Support\ServerAddress`.
- **Rationale**: `settings` is the key-value table every administrative flag already uses (`app_name`, `multi_outlet_enabled`, `system_mode`). `settings` is not `HasDataMode`, so the address is the same in DEMO and LIVE, which is what we want for a server address.
- **Verified**: `settings` columns are `key` (unique, 100), `value`, `type` enum (`string`, …), `group` (`general` default). Migration `2026_10_04_000001_create_settings_table.php`.
- **Alternatives rejected**: a new table (no benefit for three values, adds a migration); a `.env` value (cannot be changed by the admin page, which is the requirement).

## R2. Admin API shape

- **Decision**: dedicated `GET /settings/server-address` and `PUT /settings/server-address`, not the generic bulk `PUT /settings`.
- **Rationale**: the generic endpoint validates by key only through closures (see `UpdateSettingsRequest`); a dedicated FormRequest keeps the URL rules and the "selected online needs an online address" rule in one place, and gives the admin page precise 422 field messages. Access uses the same `SettingPolicy` `update` gate as `PUT /settings`, so the rule is not duplicated.
- **ASSUMPTION**: `SettingPolicy` exposes `update` (verified via `UpdateSettingsRequest::authorize()`); a `view` ability is needed for the GET. Confirm when implementing; if absent, GET uses the same `update` gate (admin-only reading, FR-011).
- **Alternatives rejected**: extending `PUT /settings` with URL rules (mixes concerns, weakens the "one place" rule).

## R3. How a tablet learns the selected address

- **Decision** (option A, product-owner choice 2026-10-17): `GET /devices/server-address`, device-scoped by the same device identity the heartbeat uses (header `X-Device-Id`, a non-revoked device). It returns **only** `{ "selected": "local|online", "url": "<selected address or null>" }`. The tablet keeps its current URL when `url` is null, and adopts the returned `url` only after this call succeeds.
- **Rationale**: the tablet cannot read the admin endpoint (no admin token). The heartbeat cannot be used before sign-in because it requires a session (`PosStore.heartbeatOnce()` returns when `session == null`), so a dedicated device call is needed. Returning only the selected URL keeps FR-011 (admin-only reading of the pair) intact.
- **Verified**: `DeviceController::heartbeat` is registered at `routes/api.php:85`, outside the owner/admin group, and the header `X-Device-Id` is already used by `DeviceController` and tab/session controllers.
- **Alternatives rejected**: embedding the URL in the staff roster (roster is unauthenticated and would then disclose the choice to anyone on the network); pushing from the server (no push channel exists).

## R4. Both addresses reach the same backend (ASSUMPTION, to confirm)

- **Decision**: the design assumes local and online are two network routes to **one** backend and **one** database, as the constitution requires (one server and one database per organisation).
- **Consequence**: the admin's choice changes reachability, not data. Switching is safe for open shifts (FR-013).
- **Risk if wrong**: if the local address were a separate server with its own database, shifts and orders would split. That would contradict the constitution and must be decided as an amendment, not a plan detail. **Confirm with the product owner before tasks are generated.**

## R5. Connection state on the sign-in screen

- **Decision**: `Conn` gains `UNKNOWN` (label "Memeriksa koneksi…") as the initial value. The sign-in staff-list request sets `ONLINE` on success and `OFFLINE` on `ApiError.Network`. While on the PIN screen, a silent roster refresh every 10 s keeps the state current (reuses `loadRoster(silent = true)`, which keeps the displayed staff list).
- **Verified (root cause)**: `PosStore.conn` is initialised to `Conn.ONLINE` (`PosStore.kt:112`). It is only changed by heartbeat (`heartbeatOnce`, session only, line ~136–146) and by signed-in calls. `loadRoster()` (lines 179–185) never touches `conn`. So a signed-out tablet with Wi-Fi off never leaves "Online".
- **Consumers to check**: `Shell.kt:106` (`warn = conn != ONLINE`) would show the warning tint during `UNKNOWN`. Decision: treat `UNKNOWN` as no warning on the sign-in screen only; the shell keeps its current behaviour after sign-in because `UNKNOWN` is replaced by the first heartbeat.
- **Alternatives rejected**: a separate "reachable" boolean (two sources of truth for one fact); a background service (the app has no such service on the sign-in screen, and constitution I forbids a second write path).

## R6. Full outlet name without ellipsis

- **Decision**: measure, don't guess. The chip text is laid out with `onTextLayout`; if it overflows the chip width, the font size steps down from 14sp to a floor of 12sp. If the floor still overflows, the chip moves to its own full-width row above the keypad panel. Pure function `ChipFit.chooseFontSize(textWidthAt: (Float)->Int, available: Int, sizes): Float?` returns the size or `null` (meaning move).
- **Rationale**: the Compose BOM in use is `2024.09.00`. I did not confirm that stock text auto-sizing is available at that BOM, so the plan does not depend on it (ASSUMPTION, avoided rather than verified).
- **Alternatives rejected**: ellipsis (rejected by the product owner); always shrinking (a 40-character name would become unreadable); always moving the chip (changes the layout for normal names, violating FR-018).

## R7. PIN area scrolling

- **Decision**: wrap the sign-in content (title, banner, staff prompt, PIN dots, keypad) in one vertical scroll container. Entered digits stay in `PosStore`/PIN state, so scrolling cannot clear them (FR-017). When content fits, no scrollbar is shown (FR-018).
- **Rationale**: one scroll area keeps the keypad and dots together; a per-section scroll would separate the dots from the keypad.
- **Verified scope**: `PinScreen.kt` builds `BrandPanel` and the sign-in content; the exact container is confirmed when editing.

## R8. Admin page and i18n

- **Decision**: a new section in the existing settings view, shown only to roles that pass the menu gate. Copy in Indonesian (constitution III). The section shows "Alamat server saat ini" from the selected value.
- **Alternatives rejected**: a new menu item (more UI than the requirement needs).

## Open items carried to tasks

- R4 confirmation (product owner).
- Device verification of the "Online" root cause with Wi-Fi off, before the fix is accepted.
- Gradle build on this machine: earlier feature notes record that the Android toolchain was compiled offline in feature 050; this must be re-run, not assumed.
