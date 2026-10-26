---
description: "Task list for 077 Android mode data, store branding, counter polish and cashier notes"
---

# Tasks: Android POS — Mode Data, Store Branding, Counter Polish & Cashier Notes

**Classification**: INTERNAL

**Input**: `specs/077-android-mode-branding-counter-notes/` (plan.md, spec.md, research.md, data-model.md, contracts/api-and-ui-contract.md, quickstart.md)

**Tests**: Included — Constitution II requires proof. PHP feature tests under `tests/Feature/`, Kotlin unit tests under `AT`. Visual states are verified on-device per `quickstart.md` (adb is not installed on this machine, so those steps are manual).

**Format**: `- [ ] T### [P?] [US?] Description with file path`. `[P]` = different files, no dependency on an unfinished task. Paths are relative to the repo root; `AP` = `android-pos/app/src/main/java/com/warkop/pos`, `AT` = `android-pos/app/src/test/java/com/warkop/pos`, `CN` = `android-pos/core-network/src/main/java/com/warkop/core/network`.

**Conventions**: code comments, docs and UI copy in Indonesian, explaining *why* (match surrounding style). **PHP tests inside the container MUST use** `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=...` (CLAUDE.md — otherwise the dev database is wiped). `docs/openapi-pos-mvp.yaml` moves in the same commit as the server tasks.

## Phase 1: Setup

- [X] T001 Record the Android baseline: run `cd android-pos && ./gradlew :app:testDebugUnitTest` and write pass/fail counts to `specs/077-android-mode-branding-counter-notes/baseline.md`.
- [X] T002 Record the server baseline for the touched areas with the safe flags above, `--filter='PosSetupTest|TabLifecycleTest|TabDataModeTest|TabDetailReadTest'`, appending counts to `baseline.md` (known pre-existing suite failure: `ShiftReportParityTest`).

## Phase 2: Foundational (blocks US1, US2, US7)

- [X] T003 Extract the store name/logo/mode selection from `SettingsController::features` into one shared method in a new `app/Support/StoreBranding.php` (per-mode name key `store_name_demo`/`store_name`, logo via `ImageUploadService::url`, mode via `ModeGate::current()`); make `features()` call it so there is one place that decides these values (Constitution I). Existing features tests must stay green.
- [X] T004 Add `GET /pos-setup/branding` to `app/Http/Controllers/Api/PosSetupController.php` and `routes/api.php` (token-less, `throttle:30,1`, next to the other `pos-setup` routes) returning exactly `system_mode`, `store_name`, `store_logo_url` per contracts/api-and-ui-contract.md; add the recorded-exception comment in the 073 style.
- [X] T005 [P] Write `tests/Feature/PosSetupBrandingTest.php`: no token needed; returns only the three keys; demo mode returns `store_name_demo`; null logo → `null`; DEMO/LIVE value follows `Setting` `system_mode`; throttled route group matches 073's.
- [X] T006 [P] Document the endpoint in `docs/openapi-pos-mvp.yaml` (new path + schema, security none) and parse-check the YAML.
- [X] T007 [P] Add `BrandingDto` and `branding()` to `CN/PosApi.kt`/`CN/dto/` and add `system_mode`, `store_name`, `store_logo_url`, `outlet_count` (optional) to the features DTO in `CN/dto/ShiftDtos.kt`.
- [X] T008 Add `SystemMode` (LIVE/DEMO, tolerant parse: unknown → LIVE) and `Branding(mode, storeName, logoUrl)` to `AP/model/PosModels.kt`; add `branding()` to `PosBackend`/`RemoteBackend` in `AP/data/` (logo through `ServerUrl.resolve`) and to `AT/state/FakePosBackend.kt`.

**Checkpoint**: branding is readable pre- and post-login by one code path.

## Phase 3: User Story 1 — Android follows the system mode (P1) 🎯 MVP

**Goal**: tablet shows and writes only the data of the web's current mode; per-mode terminal setup; DEMO badge; safe mid-session switch.
**Independent test**: quickstart steps 2–3.

- [X] T009 [P] [US1] Write `AT/data/TerminalConfigPerModeTest.kt`: save under LIVE then DEMO keeps both; read returns the current mode's entry; `forgetTerminal` removes only the current mode's entry; legacy single config migrates into the first observed mode.
- [X] T010 [US1] Make the vault per-mode in `AP/data/TokenVault.kt` and `AP/data/RemoteBackend.kt` (`savedConfig(mode)`, `saveConfig`, `forgetTerminal(mode)`), including the migration of the pre-077 single config (data-model.md). Passes T009.
- [X] T011 [P] [US1] Write `AT/state/PosStoreModeTest.kt` (with `FakePosBackend`): bootstrap reads the mode first and picks that mode's config or goes to setup with a neutral notice; flipping back restores the first config; `ModeChanged` blocks `send`/pay/fire and leaves open tabs untouched; sign-out from `ModeChanged` lands on the new mode's setup/PIN.
- [X] T012 [US1] Add `mode: SystemMode` and `ModeState` to `AP/state/PosStore.kt`: set from `branding()` in `bootstrap()` (before choosing the saved config; on network failure fall back to the last known mode and keep today's behaviour), refreshed from every `features()` read; remove the unconditional "Terminal ini tidak berlaku lagi" path for a mode-caused NotFound in `validateSavedTerminal`.
- [X] T013 [US1] Classify 403 `outlet_forbidden`/404 device-outlet errors: if the mode read now differs from the session's mode → `ModeChanged`, not `markTerminalRevoked`. Edit `AP/state/TerminalRevokedRule.kt` and its call sites in `PosStore.kt`; extend `AT/state/TerminalRevokedRuleTest.kt` for both cases (changed mode vs unchanged mode keeps today's behaviour).
- [X] T014 [US1] Block order writes while `ModeChanged` (FR-004): guard in `PosStore.send`, `goPay`, `payCash`, `payEdc`, `fire`, `openTable` with the message from the contract; add the blocking dialog (only action: Keluar) in `AP/ui/PosApp.kt`, driven by `PosStore.modeChanged` rather than a `Sheet` (sheets are dismissible; this one must not be).
- [X] T015 [US1] Clear mode-bound caches on a mode change (menu, `soldOut`, floor, orders, image cache) in `PosStore.kt` so no item of the old mode stays visible (FR-002); extend `AT/state/PosStoreRefreshTest.kt` accordingly.
- [X] T016 [P] [US1] Add the DEMO badge component `ModeBadge(mode)` in `AP/ui/StoreMark.kt` (file created here, filled in by US2) and show it on the setup, PIN and post-login screens when `mode == DEMO`; nothing in LIVE.

**Checkpoint**: US1 deliverable and testable alone (badge and mode logic need no branding images).

## Phase 4: User Story 2 — Store logo and name on every screen (P1)

**Goal**: logo on the PIN screen; logo + name (+ outlet when multi-outlet) in the header of every post-login screen.
**Independent test**: quickstart steps 1 and 4.

- [X] T017 [P] [US2] Write `AT/ui/StoreMarkRuleTest.kt` for the pure rules: initials fallback from the store name (empty → "?"), outlet shown only when `outlet_count > 1`, name ellipsis rule input.
- [X] T018 [US2] Create `StoreMark(logoUrl, storeName, outletName?)` in `AP/ui/StoreMark.kt`: fixed-size rounded logo via `MenuImageLoader` (+ `ServerUrl.resolve`), initials tile on null/failed load, 1-line ellipsized name; stable layout (no shift). Passes T017.
- [X] T019 [US2] Hold `branding` in `PosStore` (loaded in `bootstrap()`/PIN screen entry, refreshed by `features()` reads and pull-to-refresh) in `AP/state/PosStore.kt`; expose `outletCount` from `features()`.
- [X] T020 [US2] Place `StoreMark` in the top bar of `PosShell` in `AP/ui/Shell.kt` so every post-login screen (Counter, Orders, Tables, Shift, Printer) shows it; keep screen `title` unchanged.
- [X] T021 [US2] Replace the "W" placeholder in `BrandPanel` (`AP/ui/screens/PinScreen.kt`) and the header of `AP/setup/TerminalSetupScreen.kt` with `StoreMark`.
- [X] T022 [P] [US2] Extend `AT/state/PosStoreRefreshTest.kt` (or new `PosStoreBrandingTest.kt`): changed name/logo on the server appears after the next refresh; failed branding keeps the last known value.

**Checkpoint**: P1 stories complete — this is the suggested first release.

## Phase 5: User Story 3 — PIN screen two columns (P2)

**Goal**: user information and PIN entry in separate columns (landscape); compact stays one scrolling column.
**Independent test**: quickstart step 5.

- [X] T023 [US3] Restructure the landscape branch of `PinScreen` in `AP/ui/screens/PinScreen.kt`: left column = `StoreMark`, outlet · terminal, clock, staff picker and the selected user (avatar, name, role); right column = "PIN <name>", dots, error line, keypad, "Ganti outlet / terminal". Remove the old 38/62 brand-vs-entry split; keep `OutletStatusChips` and `chooseFontSize` behaviour (054).
- [X] T024 [US3] Keep the compact layout single-column in the same order and make sure error text renders in the PIN column while the selected user stays visible (`PinScreen.kt`).
- [X] T025 [P] [US3] Add a pure layout rule + test (`AT/ui/PinLayoutRuleTest.kt`): which layout (two-column vs stacked) for each `Size` and for narrow landscape widths; use it from `PinScreen`.

## Phase 6: User Story 4 — Cashier note on the order (P2)

**Goal**: order-level note on the Counter, saved to `orders.notes`, visible after restart and on web Sales detail.
**Independent test**: quickstart steps 8–9.

- [X] T026 [P] [US4] Write `tests/Feature/TabNoteTest.php` (safe flags): set/clear note, trims blank → null, 1001 chars → 422, other outlet's tab → 404, wrong role → 403, paid/closed/voided tab → 409, `outlet_id`/`data_mode` in the body ignored, activity log written in the same transaction (rolled-back write leaves no log), `GET /tabs/{id}` returns `notes` (the `GET /tabs` summary stays unchanged), DEMO note invisible in LIVE.
- [X] T027 [US4] Add `app/Http/Requests/UpdateTabNoteRequest.php` (`notes` nullable string max 1000; authorize like `UpdateTabLineRequest`), `TabService::setNote()` in `app/Services/TabService.php` (one transaction + `ActivityLogger` entry that records the change, not the text), `TabController::note()` and `PUT /tabs/{order}/note` in `routes/api.php`.
- [X] T028 [US4] Add `notes` to `app/Http/Resources/TabResource.php`; verify the `relationLoaded()` pitfall from CLAUDE.md does not apply (the column is on `orders`, no relation needed) and that the service's `fresh()` call returns it.
- [X] T029 [P] [US4] Document `PUT /tabs/{order}/note` and the `notes` field in `docs/openapi-pos-mvp.yaml`; parse-check; dated note in `docs/RUNBOOK.md` only if it lists tab endpoints.
- [X] T030 [P] [US4] Add `notes` to the tab DTO and `setNote(tabId, notes)` + request DTO to `CN/PosApi.kt`/`CN/dto/TabDtos.kt`; add `Order.note` in `AP/model/PosModels.kt`, map it in `AP/data/OrderMapper.kt`, add `setNote` to `PosBackend`/`RemoteBackend`/`FakePosBackend`.
- [X] T031 [P] [US4] Write `AT/state/PosStoreOrderNoteTest.kt`: save goes through `send(...)` with intent key `note:$tab:$text`; retry after a network failure does not duplicate; over-1000 is refused before sending with the contract message; closed order disables edits; note survives `refreshCurrent`.
- [X] T032 [US4] Add `PosStore.saveOrderNote(text)` in `AP/state/PosStore.kt` using the existing `send(intent, action, retry)` pipeline (idempotent by state), plus the 1000-char guard and pending-text retention on failure.
- [X] T033 [US4] Add the "Catatan order" row to `OrderPane` in `AP/ui/screens/EditorScreen.kt` above the totals: collapsed = saved text or "Tambah catatan", expanded = field with `n/1000` + Simpan, disabled when closed; show the note read-only in the order detail in `AP/ui/screens/OrdersScreen.kt`.

## Phase 7: User Story 5 — Draggable divider (P3)

**Goal**: resize product vs order pane in the side-by-side Counter layout; remembered per device.
**Independent test**: quickstart step 7.

- [X] T034 [P] [US5] Write `AT/state/SplitPreferenceTest.kt` and `AT/ui/SplitClampTest.kt`: default 0.6; clamp to [0.35, 0.75] and ≥ 320 dp per pane for a given total width; re-clamp when a saved value does not fit a smaller screen; reset value.
- [X] T035 [US5] Create `AP/state/SplitPreference.kt` (pattern of `TotalsPreference.kt`, per device) and a pure `clampSplit(fraction, totalWidthPx, density)` used by the test.
- [X] T036 [US5] Add `SplitDivider` (visible grip, ≥ 24 dp touch target, `detectHorizontalDragGestures`, double-tap resets) and use it in the `else ->` branch of `EditorScreen` replacing the fixed `weight(0.6f)/weight(0.4f)` in `AP/ui/screens/EditorScreen.kt`; persist on drag end; `MEDIUM`/`COMPACT` layouts unchanged.

## Phase 8: User Story 6 — Price range in full (P3)

**Goal**: no truncated price; "Rp 7.000 – Rp 15.000" wraps only at the dash.
**Independent test**: quickstart step 10.

- [X] T037 [P] [US6] Extend `AT/ui/CounterLabelsTest.kt`: range label text `Rp 7.000 – Rp 15.000` with a non-breaking space inside each price; equal prices → single price; large numbers keep both prices intact.
- [X] T038 [US6] Edit `priceLabel` in `AP/ui/CounterLabels.kt` in place (no second function) and change the price `Text` in `ProductCard` (`AP/ui/screens/EditorScreen.kt`) to soft-wrap with `maxLines = 2` and a reserved two-line min height for entry cards so grid rows stay even; the variant picker rows in `AP/ui/Sheets.kt` are checked for the same label.

## Phase 9: User Story 7 — Avatar from user photo (P3)

**Goal**: photo wherever an initial avatar shows; initials as fallback.
**Independent test**: quickstart step 6.

- [X] T039 [P] [US7] Write `tests/Feature/PinRosterPhotoTest.php` (safe flags): roster item has `photo_url` (null without photo, URL with photo); existing keys unchanged; no username/hash leaked; outlet scoping unchanged.
- [X] T040 [US7] Add `photo_url` to the item map in `PinAuthController::roster` (`app/Http/Controllers/Api/PinAuthController.php`) via `ImageUploadService::url($u->photo_path)`; document in `docs/openapi-pos-mvp.yaml`.
- [X] T041 [P] [US7] Add `photo_url` to the roster DTO in `CN/dto/AuthDtos.kt`, `StaffUser.photoUrl` in `AP/model/PosModels.kt` and map it (through `ServerUrl.resolve`) in the roster mapper in `AP/data/RemoteBackend.kt`; update `FakePosBackend`.
- [X] T042 [P] [US7] Write `AT/ui/UserAvatarRuleTest.kt` (photo present → photo; null/blank/load-failed → initials; initials rule unchanged).
- [X] T043 [US7] Create `UserAvatar(photoUrl, initials, size)` in `AP/ui/UserAvatar.kt` (via `MenuImageLoader`, fallback to initials) and use it in `StaffChip` and the selected-user block of `PinScreen.kt` and the avatar in `AP/ui/Shell.kt` (post-login user photo from the auth user DTO).

## Phase 10: Polish & cross-cutting

- [X] T044 Run the full Android unit suite and the full backend suite (safe flags); compare with `baseline.md`; no new failures beyond the known `ShiftReportParityTest`. **Result (2026-10-27)**: Android 335 pass / 0 fail; backend 2123 pass, 1 skipped, 1 fail (`ShiftReportParityTest`, pre-existing, unchanged).
- [X] T045 Run `npm test` only if any web file changed (none expected); confirm web Sales detail already renders `notes` for a tablet-written note (quickstart step 8) and record the result in `specs/077-android-mode-branding-counter-notes/evidence.md`.
- [ ] T046 Manual on-device pass of `quickstart.md` steps 1–10 (DEVICE steps need a tablet/emulator with adb — currently unavailable; record each step as done/blocked in `evidence.md`, do not mark unverified steps as passed).
- [X] T047 Update `CLAUDE.md` with a dated note (2026-10-26): per-mode terminal config, branding endpoint exception, `PUT /tabs/{order}/note` as the only order-note writer, and that `StoreBranding` is the only place choosing store name/logo; update `docs/RUNBOOK.md` if mode-switch behaviour for tablets belongs there.
- [X] T048 Security recheck of the two token-less exposures (branding, roster `photo_url`) against `specs/073-android-outlet-terminal-login/security-findings.md`; note any residual risk in `evidence.md`.

## Dependencies & execution order

- **Phase 1 → Phase 2 → stories.** Phase 2 blocks US1, US2 and US7's pre-login pieces.
- **US1** and **US2** are the P1 pair; US2 reuses `StoreMark.kt` created in T016 (US1) — do T016 first or create the file in T018 and let T016 import it; either way both files are touched by one person at a time.
- **US3** needs US2 (`StoreMark`) and benefits from US7's `UserAvatar` (T043); until then it uses initials.
- **US4** (server T026–T029 → Android T030–T033) is independent of US1–US3 and can run in parallel with them.
- **US5, US6** are independent of everything else (both touch `EditorScreen.kt`, so sequence T036 and T038 or merge carefully; US4's T033 also touches it).
- **US7** server tasks (T039–T040) are independent; Android tasks need Phase 2's DTO work only for `ServerUrl`.
- Within a story: tests → model/DTO → service/endpoint → UI.

## Parallel opportunities

- Phase 2: T005, T006, T007 together after T004.
- US1: T009 and T011 (tests) in parallel; T016 alongside T012–T015.
- US4 server (T026, T029) alongside US4 Android DTOs (T030) and tests (T031).
- US5 (T034), US6 (T037), US7 (T039, T041, T042) test/DTO tasks all parallel — different files.
- A second developer can take US4 + US7-server while the first does US1 + US2.

## Implementation strategy

1. **MVP = US1 + US2** (both P1): correct data per mode and visible store identity. Ship after T001–T022 and quickstart steps 1–4.
2. **Increment 2**: US4 (note, includes the server change) then US3 (PIN layout).
3. **Increment 3**: US5, US6, US7 — small, independent polish.
4. Commit per phase; server tasks and their OpenAPI entries always in the same commit. Do not push without the developer's explicit instruction.

## Summary

- **Total tasks**: 48 (T001–T048)
- **Per story**: US1 8 (T009–T016) · US2 6 (T017–T022) · US3 3 (T023–T025) · US4 8 (T026–T033) · US5 3 (T034–T036) · US6 2 (T037–T038) · US7 5 (T039–T043) · Setup 2 · Foundational 6 · Polish 5
- **Format**: every task has a checkbox, ID, `[P]` where parallel, `[USn]` in story phases and a file path.
