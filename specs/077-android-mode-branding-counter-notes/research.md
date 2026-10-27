# Research — 077 Android mode, branding, counter notes

**Classification**: INTERNAL. Facts were read from the repo on 2026-10-26; anything else is marked ASSUMPTION or unverified.

## R1 — What "follow the web's system mode" actually requires
- **Fact**: `ModeGate::current()` reads `settings.system_mode` (default `live`). `HasDataMode` adds a global scope to ~20 models incl. `Outlet`, `Device`, `Order`, `Category`, menu. `User` is explicitly **not** mode-scoped (roster is the same in both modes).
- **Fact**: `GET /pos-setup/outlets` currently returns "Warkop WJM 2/3" = the LIVE outlets (matches the screenshot: LIVE, All outlets (2)). DEMO has its own outlet rows.
- **Fact**: Android stores one `TerminalConfig(serverUrl, outletId, outletName, deviceId, deviceName)`; `bootstrap()` loads it and `validateSavedTerminal` calls `terminals(outlet)`; a `NotFound` → `returnToSetup("Terminal ini tidak berlaku lagi")` **and `forgetTerminal()`**. Android has no mode concept (no `system_mode` in any `.kt`).
- **Consequence (inference)**: after the owner flips the mode, the saved outlet/terminal are not found → the tablet silently discards the config and sends the cashier through setup; flipping back repeats it. Mid-session, X-Outlet-Id/device headers no longer match → 403 `outlet_forbidden` / terminal-not-found, which `TerminalRevokedRule` can misread as "terminal revoked" (unverified without a device; covered by a unit test on the rule).
- **Decision**: (a) `SystemMode` (LIVE/DEMO) lives in `PosStore`, set from branding pre-login and from every `features()` read post-login; (b) vault keeps `TerminalConfig` **per mode** (key = mode), so a switch selects the other mode's saved terminal or, if none, goes to setup with a neutral notice; flipping back restores the first one; (c) a 403/404 that appears together with a changed mode is classified as **mode change**, not revoked terminal; (d) mid-session change → blocking dialog "Mode berubah ke DEMO/LIVE", only action = sign out (open tab stays server-side, untouched); order writes are refused while the dialog is pending (FR-004).
- **Rejected**: polling `features` on a timer (new traffic); tablet-side mode toggle (out of scope); deleting config on switch (loses LIVE setup).

## R2 — Pre-login branding and mode source
- **Fact**: token-less routes today: `server-address`, `pos-setup/*`, `staff/roster`, `auth/pin-sessions`. `GET /settings/features` is behind `auth:sanctum` and already returns `system_mode`, `store_name` (per-mode key `store_name_demo`), `store_logo_url`, `outlet_count`.
- **Decision**: add `GET /pos-setup/branding` → `{system_mode, store_name, store_logo_url}` (throttle 30/1, same group as 073). It reuses `ModeGate`, `Setting::get` with the same per-mode name key, and `ImageUploadService::url` — extract the three lines from `SettingsController::features` into one small shared method so there is **one** place that decides store name/logo (Constitution I).
- **Rejected**: stuffing branding into `staff/roster` (roster is per outlet and requires an outlet id the setup screen does not have yet); showing no logo until login (violates FR-006).

## R3 — Header on every screen
- **Fact**: `ui/Shell.kt::PosShell` wraps post-login screens (`title`, `subtitle`); avatar there is `u?.initials`. `PosApp.kt` routes `Dest`s.
- **Decision**: `StoreMark(logoUrl, storeName, outletName?)` composable placed once in `PosShell`'s top bar; DEMO badge sits next to it. PIN/Setup screens use the same composable with branding from the pre-login endpoint. Outlet name only when `outlet_count > 1` (ASSUMPTION from spec).

## R4 — Images (logo, avatar)
- **Fact**: 075 added `MenuImageLoader` (bounded LRU, downsampled, null on error) and `ServerUrl.resolve`; `QrImageLoader` handles logo for receipts.
- **Decision**: reuse `MenuImageLoader` for store logo and user photos; always `ServerUrl.resolve` (server `APP_URL` may be `localhost`). Failure/empty → store-name initials / user initials (`initialsOf`). Cache cleared by the existing pull-to-refresh and on mode change.
- **Rejected**: new image library (unverified version, same reasoning as 075 R2).

## R5 — PIN screen: two columns
- **Fact**: landscape today = `BrandPanel` (38 %: "W" placeholder mark, outlet/terminal, clock, status chips) | entry (62 %: "Siapa yang bertugas?" chips row, dots, keypad stacked in one column). Compact = one scrolling column.
- **Interpretation (ASSUMPTION — confirm on review)**: "user information and PIN input in each column" = **left column: store logo, outlet/terminal, clock and the staff picker with the selected user's large avatar/name/role; right column: PIN title, dots, error, keypad**. The brand panel and staff chips merge into the left column; the keypad gets the whole right column. Compact keeps the stacked single column.
- **Alternative**: three columns (brand | staff | PIN) — rejected, too cramped at the narrowest landscape tablet width.
- Error text stays in the PIN column; selected user stays visible (spec scenario 3).

## R6 — Draggable divider
- **Fact**: `EditorScreen` `else ->` branch (landscape `Size.LARGE`) uses `weight(0.6f)`/`weight(0.4f)`. `Size.MEDIUM` stacks vertically (no left-right axis); `COMPACT` shows one pane at a time.
- **Decision**: drag applies to the side-by-side layout only. A `SplitDivider` (visible handle, ≥ 24 dp touch target) uses `detectHorizontalDragGestures`; fraction clamped so each pane is ≥ 320 dp and the fraction ∈ [0.35, 0.75]; stored per device in `SplitPreference` (fraction as float) and re-clamped on read for the current width. Double-tap resets to default 0.6 (ASSUMPTION, cheap discoverability).
- **Rejected**: persisting per user/outlet (spec says per device).

## R7 — Order-level note (the one real server gap)
- **Fact**: `orders.notes` text nullable exists and is `$fillable`; `OrderResource` returns it. `TabService`/`TabController`/`OpenTabRequest`/`TabResource` contain **no** `notes`. Per-line `note` (max 255) is separate.
- **Decision**: `PUT /tabs/{order}/note` body `{notes: string|null (max 1000)}`; allowed while the tab is open (same guard as adding a line); `TabService::setNote` in one transaction with `ActivityLogger` entry (`note_updated`; ASSUMPTION: the log records that the note changed, not its content, so free text never lands in logs); returns `TabResource` with new `notes`. Idempotent by nature (PUT sets state) so no `idempotent` middleware.
- Android: `Order.note`; "Catatan order" field in `OrderPane` (collapsed row → expands; saved on "Simpan"/focus loss through the existing `send(...)` pipeline with intent key `note:$tab:$text`, so retry is safe and a lost network keeps the pending text). Orders list/detail reads `notes`.
- **Receipts/kitchen tickets**: not changed (spec out of scope). `ReceiptMapper` ignores order notes today (unverified for server receipt template).
- **Rejected**: sending the note with `POST /tabs/{id}/send` (couples note to kitchen firing; counter orders may be paid without sending).

## R8 — Price range
- **Fact**: `priceLabel(min,max)` = `"${rp(min)}–${rp(max)}"`; the card `Text` sets `maxLines = 1, softWrap = false`, so a narrow card clips to "Rp 7.000-Rp".
- **Decision**: edit `priceLabel` in place to `"Rp 7.000 – Rp 15.000"` with a non-breaking space inside each price (`Rp 7.000`), and let the card text wrap (`maxLines = 2`, soft wrap on). It can only break at the dash, so a number is never cut; one price stays one line. Reserve two-line height for entry cards so the grid rows stay even.
- **Rejected**: dropping the second "Rp" (less clear for shopkeepers); shrinking font until it fits (unreadable on the narrowest card).

## R9 — Avatar photo
- **Fact**: `UserResource.photo_url` exists (via `ImageUploadService`); `AuthDtos` already has `photoUrl`; the roster payload does not. Initials come from `StaffUser.initials`.
- **Decision**: add `photo_url` to roster; `StaffUser.photoUrl`; `UserAvatar(photoUrl, initials)` used in PIN chips, selected-user column, `PosShell`. Fallback to initials on null or load failure.

## Open items (not blocking; verify on device)
1. Actual symptom of a mode flip on a real tablet (R1 inference) — quickstart step 2.
2. Whether the server receipt prints order notes — quickstart step 9.
3. R5 column interpretation — confirm with the owner on the first build.
