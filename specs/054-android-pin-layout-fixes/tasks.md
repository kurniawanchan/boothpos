# Tasks: Android POS Phase 1 Layout, Connection and Server Address Fixes

**Input**: `specs/054-android-pin-layout-fixes/` (plan.md, spec.md, research.md, data-model.md, contracts/server-address.md, quickstart.md)

**Tests**: Included. Constitution II requires backend tests under `tests/Feature/` (against MySQL) and frontend tests under `qa-tests/`, and the spec's acceptance scenarios are testable only that way. The Android unit tests are the only automated check for the connection state and the address adoption.

**Organization**: by user story (spec.md US1–US4), in priority order. All four stories are P1 in the spec. Recommended order: US2 first (it is the reported bug), then US1, US3, US4.

**Corrections found while tasking** (plan.md wording vs. code):
- There is no `SampleBackend`. `RemoteBackend` is the only `PosBackend` implementation (`android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt`).
- `SettingPolicy` exposes `viewAny` and `update` (not `view`). The admin GET uses `viewAny`; the PUT uses `update`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[Story]**: US1 (chip), US2 (connection), US3 (server address), US4 (PIN scroll)

## Path Conventions

- Backend: `app/`, `routes/api.php`, `tests/Feature/`, `docs/openapi-pos-mvp.yaml`
- Admin SPA: `resources/js/`, `qa-tests/`
- Android: `android-pos/app/src/main/java/com/warkop/pos/`, `android-pos/app/src/test/java/com/warkop/pos/`, `android-pos/core-network/src/main/java/com/warkop/core/network/`

---

## Phase 1: Setup (baseline)

**Purpose**: prove the toolchains run before changing anything (research.md open items).

- [X] T001 Run the backend baseline inside the container with the mandatory overrides from CLAUDE.md: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test --filter=DeviceHeartbeatTest -e CACHE_STORE=array -e SESSION_DRIVER=array`. Record the output line count and result in the plan's evidence notes.
- [X] T002 Run the Android baseline in `android-pos/`: `./gradlew :app:testDebugUnitTest` (must be executed, not assumed; feature 050 notes say offline compile only). Record the result. If the toolchain is not installed on this machine, stop and report it; do not mark later Android tasks verified.

---

## Phase 2: Foundational

**Purpose**: no shared blocking infrastructure. Each story touches its own files; the only overlap is `PosStore.kt` (US2 and US3) and `PinScreen.kt` (US1 and US4), handled by ordering below. No foundational tasks.

**Checkpoint**: setup green → user stories can start.

---

## Phase 3: User Story 2 - Connection state is honest (Priority: P1) 🎯 MVP

**Goal**: the chip reflects the real network state on the sign-in screen; it no longer defaults to "Online" (root cause: `PosStore.kt:112`, roster never updates `conn`).

**Independent Test**: quickstart steps 7–9 (Wi-Fi on → "Online", no banner; Wi-Fi off → "Offline" with banner within ~10 s; "Coba lagi" after reconnect → "Online").

- [X] T003 [US2] In `android-pos/app/src/main/java/com/warkop/pos/state/PosStore.kt`, add `UNKNOWN("Memeriksa koneksi…")` to `enum class Conn` (line 61), and change the initial value of `var conn` (line 112) from `Conn.ONLINE` to `Conn.UNKNOWN`.
- [X] T004 [US2] In `PosStore.kt` `loadRoster()` (lines 179–186): on success set `conn = Conn.ONLINE`; on `ApiError.Network` set `conn = Conn.OFFLINE`; on any other `ApiError` leave `conn` unchanged (FR-006). Keep the silent-refresh behaviour that keeps the staff list (FR-005 path).
- [X] T005 [US2] In `PosStore.kt`, add `startPinProbe()` and `stopPinProbe()`: while `dest == Dest.PIN`, call `loadRoster(silent = true)` every 10 s on `viewModelScope`; cancel on stop. Single writer for `conn` stays `PosStore` (constitution I). Do not add a second timer elsewhere.
- [X] T006 [US2] In `android-pos/app/src/main/java/com/warkop/pos/ui/screens/PinScreen.kt`, call `store.startPinProbe()` / `store.stopPinProbe()` from a `DisposableEffect` on the sign-in screen.
- [X] T007 [US2] In `android-pos/app/src/main/java/com/warkop/pos/ui/Shell.kt` (line 106, `warn = store.conn != Conn.ONLINE`): make `UNKNOWN` show no warning tint, so the signed-out screen doesn't flash a warning before the first check.
- [X] T008 [US2] Verify `android-pos/app/src/main/java/com/warkop/pos/ui/ErrorState.kt` (line 19) shows the "Tidak dapat menjangkau server" text only for `ApiError.Network`. Change nothing if already true; otherwise restrict it and record why in a comment.
- [X] T009 [P] [US2] Create `android-pos/app/src/test/java/com/warkop/pos/state/PosStoreConnectionTest.kt`. First check how `PosStore` receives its backend (the `debugInstall` hook near `PosStore.kt:195`, or a constructor parameter); reuse it. If neither allows a fake `PosBackend`, add a constructor parameter (do not add a second write path). Cases: initial `UNKNOWN`; roster success → `ONLINE`; roster `ApiError.Network` → `OFFLINE`; roster 401 → unchanged; heartbeat behaviour unchanged.
- [ ] T010 [US2] Manual verification on the pilot tablet: quickstart steps 7–9. Record the result in the plan's evidence notes. This is the confirmation of the root cause the spec flags as unverified.

**Checkpoint**: US2 done. The "always Online" bug is fixed in code and verified on device.

---

## Phase 4: User Story 1 - Full outlet name on one line (Priority: P1)

**Goal**: the terminal chip shows the full name on one line: font steps down to a 12sp floor, then the chip moves to a full-width row (research.md R6).

**Independent Test**: quickstart steps 11–12 (T02 name one line; 40-character name fully visible, no ellipsis).

- [X] T011 [P] [US1] Create `android-pos/app/src/main/java/com/warkop/pos/ui/ChipFit.kt` with a pure function `chooseFontSize(textWidthAt: (Float) -> Int, available: Int, sizes: List<Float>): Float?`. Returns the first size (largest first) whose measured width fits; returns `null` when none fits (meaning "move the chip"). Sizes are passed in, not hard-coded, so the 14sp→12sp steps live with the caller.
- [X] T012 [P] [US1] Create `android-pos/app/src/test/java/com/warkop/pos/ui/ChipFitTest.kt`: fits at the first size; shrinks to the second; returns `null` when the smallest overflows; exact-fit boundary counts as fitting; empty size list returns `null`.
- [X] T013 [US1] In `PinScreen.kt` `BrandPanel` (terminal chip, around line 30), measure the chip text with `onTextLayout`, pick a size with `ChipFit.chooseFontSize`, set `maxLines = 1` and `softWrap = false`, and never apply `TextOverflow.Ellipsis`. When the result is `null`, render the chip as a full-width row above the brand title (position decided on device; record the choice in a code comment with the reason).
- [ ] T014 [US1] Manual verification: quickstart steps 11–12 (portrait and landscape; a deliberately long 40-character outlet name). Record in evidence notes.

**Checkpoint**: US1 done and verified on device.

---

## Phase 5: User Story 3 - Admin sets the server address (Priority: P1)

**Goal**: owner/admin choose local or online server address through the admin page and API; tablets adopt the selected address after a successful contact (option A).

**Independent Test**: quickstart steps 3–6 (admin page save and reload; cashier 403; invalid URL inline error; console clean) and step 10 (tablet switches address within ~10 s).

### Backend (can run in parallel with the Android work below)

- [X] T015 [P] [US3] Create `app/Support/ServerAddress.php`: the only reader/writer of `server_address_local`, `server_address_online`, `server_address_selected` (group `server`, default selected `local`). Methods: `current(): array{local,online,selected}`, `selectedUrl(): ?string` (returns the selected address or null), `save(array $values): void`. Write through `Setting` the same way `SettingsController` does (check `app/Models/Setting.php` for the write helper; do not invent a new one).
- [X] T016 [P] [US3] Create `app/Http/Requests/UpdateServerAddressRequest.php`: `authorize()` uses `$this->user()?->can('update', Setting::class)`, same as `UpdateSettingsRequest`. Rules: `local` and `online` nullable, must be `http` or `https` URL with a host; `selected` required, `in:local,online`; after validation, `selected = online` requires `online` to be set (422 on `selected`). Indonesian messages inline, matching the style of `UpdateSettingsRequest`.
- [X] T017 [US3] Create `app/Http/Controllers/Api/ServerAddressController.php` with: `show()` (authorize `viewAny` on `Setting`; returns `{local, online, selected}`); `update()` (in one `DB::transaction`, call `ServerAddress::save` and `ActivityLogger` action `server_address_updated` with before/after values; returns the show shape); `device()` (requires header `X-Device-Id`; reuse the device lookup and revocation check from `DeviceController::heartbeat` rather than copying it; 401 when missing/unknown, 403 when revoked; returns exactly `{selected, url}` where `url` is `ServerAddress::selectedUrl()`). Depends on T015, T016.
- [X] T018 [US3] In `routes/api.php`: add `GET` and `PUT` `/settings/server-address` inside the existing `settings`-menu-gated group; add `GET /devices/server-address` next to `/devices/heartbeat` (line 85), outside the owner/admin group, same as heartbeat. Depends on T017.
- [X] T019 [US3] Create `tests/Feature/ServerAddressTest.php` covering: owner GET/PUT 200; cashier GET and PUT 403; 422 for a URL without scheme; 422 for `selected=online` with no online address (on `selected`); 422 writes no row; the PUT writes exactly one `activity_logs` row with action `server_address_updated`; defaults when rows are absent (`selected = local`, both addresses null); device endpoint returns exactly the keys `selected` and `url`; unknown device 401; revoked device 403; `url` is null when local is null and selected is local. Depends on T017, T018. Run with the container command from quickstart step 1.
- [X] T020 [US3] Add the three operations to `docs/openapi-pos-mvp.yaml` in the same commit as the routes (constitution, Documentation section); the shapes come from `contracts/server-address.md`. Depends on T018.

### Admin page (SPA)

- [X] T021 [P] [US3] In `resources/js/api/settings.js`, add `getServerAddress()` and `saveServerAddress(payload)` calling `/api/v1/settings/server-address` (relative path, constitution III/Stack).
- [X] T022 [US3] In `resources/js/views/SettingsView.vue`, add an "Alamat server" section: fields for local and online address, a choice of "Lokal" or "Online", a save button, and inline 422 messages per field. Indonesian copy only; design tokens only (no hex). Show the section only when the existing settings menu gate allows it (hidden, not disabled, constitution III). Depends on T021.
- [X] T023 [P] [US3] Create `qa-tests/unit/serverAddress.test.js`: the API helpers send the right method and body; the section does not render for a role without the settings menu; a 422 response maps to the field messages. Mirror the style of existing `qa-tests/unit/*.test.js` (for example `pdfCapture.test.js`).
- [X] T024 [US3] Browser check (constitution II): as `owner` (`password123`, local only), open Settings → Alamat server, save, reload, confirm values persist; as `kasir01`, confirm the section is hidden; enter `not a url`, confirm the inline message; browser console shows no errors. Depends on T022. Record in evidence notes.

### Android (depends on US2 for the probe and on the backend route being live)

- [X] T025 [P] [US3] Add `serverAddress(deviceId: String): ServerAddressDto` to `android-pos/core-network/src/main/java/com/warkop/core/network/PosApi.kt` as `@GET("devices/server-address")` with an `@Header("X-Device-Id")` parameter, and a `ServerAddressDto(val selected: String, val url: String?)` in the same package as the other DTOs.
- [X] T026 [US3] Add `suspend fun serverEndpoint(): ServerEndpoint?` to the `PosBackend` interface (`android-pos/app/src/main/java/com/warkop/pos/data/PosBackend.kt`) with `data class ServerEndpoint(val selected: String, val url: String?)`. Depends on T025.
- [X] T027 [US3] Implement `serverEndpoint()` in `android-pos/app/src/main/java/com/warkop/pos/data/RemoteBackend.kt` (the only implementation): call T025 with the stored device id; return null on network error or when no device is paired. Depends on T026.
- [X] T028 [US3] In `RemoteBackend.kt`, add `adoptServerUrl(url: String)`: if different from the stored `TokenVault.SERVER_URL`, write it, set `serverUrl`, and clear `apiFor` so the client is rebuilt. Do not touch session tokens, the outlet or the device id (open shift unaffected, FR-013). Depends on T027.
- [X] T029 [US3] In `PosStore.kt`, extend the probe from T005: after a successful roster call, call `serverEndpoint()`; when `url` is non-null, call `adoptServerUrl`. Depends on T005, T028. Option A (research.md R3): the tablet adopts only after a successful contact.
- [X] T030 [P] [US3] Extend `PosStoreConnectionTest.kt` (created in T009) with address cases: adoption when the returned URL differs; no change when `url` is null; no adoption when the roster call failed; the device id and session are unchanged after adoption. Depends on T009, T029.
- [ ] T031 [US3] Manual verification: quickstart step 10 (admin switches the selected address; tablet uses the new URL within ~10 s; open shift unaffected). Record in evidence notes.

**Checkpoint**: US3 done. Backend tests pass, admin page verified in a browser, tablet adopts the selection.

---

## Phase 6: User Story 4 - PIN area scrolls (Priority: P1)

**Goal**: the sign-in content (title, banner, staff prompt, PIN dots, keypad) scrolls as one area when it does not fit; entered digits survive scrolling (FR-017).

**Independent Test**: quickstart steps 13–14 (short landscape split-screen; normal screen unchanged).

- [X] T032 [US4] In `PinScreen.kt`, wrap the sign-in content in one `Column` with `Modifier.verticalScroll(rememberScrollState())`. Keep the BrandPanel outside the scroll area if it is a separate panel; scrolling must reach the keypad on every orientation (FR-008). Depends on T013 (same file, US1 first).
- [X] T033 [US4] In `PinScreen.kt`, check where the typed PIN buffer is held. If it is plain `remember`, change it to `rememberSaveable` so digits survive recomposition and rotation (FR-017). Depends on T032.
- [ ] T034 [US4] Manual verification: quickstart steps 13–14 (short landscape split-screen with banner, scroll, keypad reachable, six digits submit; normal-height screen identical to current release, side-by-side screenshot). Record in evidence notes.

**Checkpoint**: US4 done.

---

## Final Phase: Polish & Cross-Cutting

- [X] T035 Run the full Android unit suite `./gradlew :app:testDebugUnitTest` (includes ChipFitTest, PosStoreConnectionTest). Record the result.
- [X] T036 Run the backend tests touching settings and devices: `--filter='ServerAddressTest|DeviceHeartbeatTest|DevicePairingTest|SettingsTest'` with the container overrides. Record the result.
- [X] T037 Run the frontend suite `npm test` (Vitest) and confirm `qa-tests/unit/serverAddress.test.js` passes.
- [ ] T038 Gate before merge: the product owner confirms research.md R4 (local and online addresses reach the same backend and database). This is a decision, not code; do not merge until confirmed.
- [ ] T039 Walk through quickstart.md end to end once and attach the evidence list (test outputs, screenshot references, console check) to the plan. Remove any debug logging added during the work.

---

## Dependencies & Execution Order

- **Setup (T001–T002)** → everything.
- **US2 (T003–T010)** first: fixes the reported bug; adds the probe that US3 needs.
- **US1 (T011–T014)** after US2 in `PinScreen.kt`/`Shell.kt` order; T011–T012 can run in parallel with US2 (different files).
- **US3**: backend chain T015/T016 [P] → T017 → T018 → T019/T020; admin chain T021 [P] → T022 → T024; Android chain T025 [P] → T026 → T027 → T028 → T029 → T030 → T031. The Android chain needs T005 (US2) and the live backend route (T018).
- **US4 (T032–T034)** after US1 (same file, `PinScreen.kt`).
- **Polish** last; T038 gates the merge.

### Parallel opportunities

- Setup T001 and T002 (different toolchains).
- US1: T011 [P] with T009 [P] (ChipFit vs PosStore test).
- US3 backend: T015 [P] with T016 [P]; admin T021 [P] and T023 [P] alongside backend work; Android T025 [P] alongside the backend chain.

### Story independence

- US2: testable alone with a fake backend (T009) and a device with Wi-Fi off (T010).
- US1: testable alone with ChipFitTest (T012) and a long-name device check (T014).
- US3: backend testable alone (T019); admin page testable alone (T024); tablet adoption needs the backend route.
- US4: testable alone on a short-screen device check (T034).

---

## Implementation Strategy

- **MVP**: US2 (T001–T010). It fixes the reported "always Online" bug, the most visible defect, with a small change set.
- **Increment 2**: US1 (chip) and US4 (PIN scroll), both on `PinScreen.kt`, done together to avoid two passes over one file.
- **Increment 3**: US3 (server address). The only change that adds an API and an admin page; blocked for merge on T038.
- **Each increment** ends with its own checkpoint and evidence, not a single final test run (constitution II).

---

## Task count

- Total: **39 tasks** (T001–T039).
- Setup: 2 · Foundational: 0 · US2: 8 · US1: 4 · US3: 17 · US4: 3 · Polish: 5.
- Parallel-marked ([P]): 9 tasks (T009, T011, T012, T015, T016, T021, T023, T025, T030 — T030 is [P] only with respect to other test files, it still waits on T029).
- Format check: every task has a checkbox, a sequential ID, a `[US#]` label in story phases only, and a file path or a named manual/browser check.
