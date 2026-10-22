---

description: "Task list for 065-android-shift-payment-updates"
---

# Tasks: Android Shift, Payment and Setup Updates

**Input**: `/specs/065-android-shift-payment-updates/` — plan.md, spec.md, research.md (D1–D12), data-model.md, contracts/shift-and-address-api.md, quickstart.md

**Tests**: INCLUDED (constitution II): backend feature tests on real MySQL, Vitest, Android JVM unit tests, and a real-browser check. Backend tests run in the container with the `-e` flags BEFORE the service name, as literal arguments (never through an unquoted shell variable):
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Test>`. Never run migrations or tests against `warkop_wjm` (dev) without the owner asking; for a rehearsal use a DB copy and print `select database()` first.

**Organization**: by user story — US1 web shift visibility (P1), US2 cash in/out in web (P1), US3 close shift (P1), US4 open-shift screen (P2), US5 QRIS sheet (P2), US6 server address (P2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependency on an unfinished task)
- Code comments, Android UI copy, commit messages in Indonesian; web copy through i18n (`resources/js/locales/id.json` + `en.json`). Comments explain *why*.

## Phase 1: Setup

- [X] T001 Record the baseline: run `CashierSessionTest`, `ShiftCloseBlindCountTest`, `SessionOpeningDenominationsTest`, `SessionOpeningCashPerOutletTest`, `CashMovement*`, `ServerAddressTest`, `DashboardAttention*Test`, `PaymentLedger*`/`Refund*` tests (grep `tests/Feature` for the exact names) with the safe flags, and `./gradlew testDebugUnitTest` in `android-pos` (note the known pre-existing `core-network` test compile error in `S4DtosTest.kt:51`), so later failures are attributable.

---

## Phase 2: Foundational (blocks the stories)

**⚠️ CRITICAL**: shift source separation touches every place that picks "the user's open shift".

- [X] T002 Create migration `database/migrations/2026_12_27_000001_cashier_sessions_source_index.php`: add index `idx_cashier_sessions_user_status_device (user_id, status, device_id)` on `cashier_sessions`; `down()` drops it. Header comment cites research D4 (source is derived from `device_id`, not stored).
- [X] T003 Create `app/Support/ShiftSource.php`: `isApp(Request)` = request carries an `X-Device-Id` that resolves to an active (not revoked) `Device` of an outlet the user can see (reuse the same lookup rule as `DeviceController::activeDevice`, do not copy it — extract a shared static/helper if needed); `deviceId(Request)`; `openFor(User $user, ?int $deviceId)` = the user's open `CashierSession` whose `device_id` is null (web) or equals the given device (app). Source is NEVER read from the body (Constitution IV).
- [X] T004 Apply `ShiftSource` in `app/Http/Controllers/Api/CashierSessionController.php`: `current()` returns the open shift of the request's source (404 when that source has none); `store()` turns the "already has open session" 409 into per-(user, source), checked under a lock on the user's open sessions so two simultaneous opens cannot both pass. Keep stamping `device_id` from the header as today.
- [X] T005 [P] Apply `ShiftSource` in `app/Services/PaymentService.php` (≈ line 427, the recording user's open shift for a later cash payment) and `app/Services/RefundService.php` (≈ line 54): use the open shift of the request's source (pass the device id / source into the service from the controller; services must not read the global request). Orders/tabs keep settling in their own `session_id`.
- [X] T006 [P] Write `tests/Feature/ShiftSourceSeparationTest.php`: one user opens an app shift (header) and a web shift (no header) → both 201; second app open → 409, second web open → 409; `GET /sessions/current` returns the matching one per source and 404 when only the other source is open; a later cash payment and a refund land in the shift of the request's source; closing the app shift leaves the web shift open; a revoked/unknown `X-Device-Id` is treated as a 422 (existing rule), never as "web".

**Checkpoint**: `ShiftSourceSeparationTest` green; existing `CashierSessionTest` still green (update only where it assumed one open shift per user across sources).

---

## Phase 3: User Story 1 — Owner sees every shift in the web (Priority: P1) 🎯 MVP

**Goal**: Cashier Session page lists open and closed shifts of all users (both sources) for owner/admin, with a detail drawer; the dashboard shows open shifts per user.

**Independent Test**: open a shift on the tablet as a cashier; as owner the shift appears in the web open list and on the dashboard with the cashier's name and terminal; close it → it appears under closed with its figures; as a cashier the all-shifts tabs are absent and the API returns only own shifts.

### Tests for US1

- [X] T007 [P] [US1] `tests/Feature/ShiftListTest.php` for `GET /sessions`: owner sees all users' shifts of permitted outlets in the active DEMO/LIVE mode; cashier sees only own; a foreign `user_id` filter is ignored for a cashier; `status=open|closed|all`, `source=app|web`, date filter on closed `business_date`, pagination meta; `X-Outlet-Id` narrows; row fields per data-model.md (money strings, `source`, `terminal_name`, `sales_total`, `cash_net`, `cash_in/out`, `expected_cash`, counted/difference for closed); a query-count assertion proves the number of queries does not grow with the number of shifts.
- [X] T008 [P] [US1] `tests/Feature/DashboardShiftsTest.php` for `GET /dashboard/shifts`: one row per open shift across users, newest first, capped at 50 with `total_open`, owner/admin/dashboard-menu only (403 otherwise), respects outlet + mode.
- [X] T009 [P] [US1] Vitest `qa-tests/component/ShiftTable.test.js` and `qa-tests/component/SessionViewAllShifts.test.js`: the Open/Closed/Cash tabs render only for owner/admin (hidden — not disabled — otherwise); rows show cashier, source label (terminal name or "Web"), opened time, opening cash, sales; clicking a row opens the detail drawer; empty and error states; and `qa-tests/component/OpenShiftsPanel.test.js` for the dashboard panel (one row per user, link to `/session?id=`).

### Implementation for US1 — backend

- [X] T010 [US1] Add batch helpers to `app/Services/ShiftReportService.php` (e.g. `figuresForMany(Collection $sessions)`): grouped queries for completed orders count/total, cash received, change given, cash in/out and voids keyed by `session_id`, reusing the existing single-shift formulas (`expectedCash`, `cashReceived`, `changeGiven`) so there is ONE cash formula; closed shifts read stored columns/`z_report`.
- [X] T011 [US1] Create `app/Services/ShiftListService.php`: builds the list rows (eager-load `user:id,name`, `device:id,name`, `outlet:id,name`), applies visibility (owner/admin all, else own `user_id`), filters and pagination, and uses T010 for figures. Register any raw `DB::table` it uses in `app/Support/OutletScopeRegistry.php` (filtered with `OutletFilter`) so `OutletScopeRegistryTest` stays green.
- [X] T012 [US1] `CashierSessionController::index()` + `Route::get('/sessions', …)` in `routes/api.php` placed BEFORE `/sessions/{session}` routes; response `{data, meta}` per contracts. Non-owner/admin never gets another user's row.
- [X] T013 [US1] `DashboardController::shifts()` + `Route::get('/dashboard/shifts', …)` (same gate as the other dashboard routes) returning `{data: ≤50 open rows, total_open}` via `ShiftListService`.
- [X] T014 [P] [US1] Make `summary()`/`xReport()` safe for the drawer when the caller is owner/admin viewing another user's shift (already allowed) and add `source`, `terminal_name`, `cashier{id,name}`, `outlet` and the cash movements count to the payload only if missing — additive; do not change existing keys.

### Implementation for US1 — web

- [X] T015 [P] [US1] `resources/js/api/sessions.js`: `listSessions(params)`; `resources/js/api/dashboard.js`: `dashboardShifts()`.
- [X] T016 [US1] Create `resources/js/components/session/ShiftTable.vue` (uses `DataTable`, `usePaginatedList`; columns cashier, source/terminal, outlet, opened/closed, opening cash, sales, difference for closed), `resources/js/components/session/ShiftDetail.vue` (drawer: sales information, payment-method split, cash in/out entries — filled by US2's list, counted/expected/difference when closed; reuse `GET /sessions/{id}/summary`).
- [X] T017 [US1] Integrate into `resources/js/views/SessionView.vue` (keep the existing "my shift" card and open/close forms exactly as they are for the web shift): for owner/admin add tabs *Open · Closed* (Cash in/out tab added in US2) under it; other roles unchanged. Add i18n keys to `locales/id.json` and `locales/en.json` (checked by `qa-tests/unit/localeKeys.test.js`).
- [X] T018 [US1] Create `resources/js/components/dashboard/OpenShiftsPanel.vue` and mount it in `resources/js/views/DashboardView.vue` (owner/admin only; one row per open shift; link `/session?id=<id>`; loading/empty/error states; refresh with the page's existing period refresh, no extra polling).

**Checkpoint**: `ShiftListTest`, `DashboardShiftsTest`, `ShiftSourceSeparationTest`, Vitest US1 tests green; browser check of `/session` and `/dashboard` (T047).

---

## Phase 4: User Story 2 — Cash in / cash out visible in the web (Priority: P1)

**Goal**: every saved "Kas masuk/Kas keluar" is visible in the shift detail and in a filterable list with totals; tablet and web figures agree.

**Independent Test**: record a cash out on the tablet; the web shift detail and the cash list show it (reason, amount, user, time) and totals equal the tablet card.

### Tests for US2

- [X] T019 [P] [US2] `tests/Feature/CashMovementsListTest.php` for `GET /cash-movements`: visibility (owner/admin all, cashier own shifts only), filters `direction`, `user_id`, `session_id`, date (shift business date), pagination, `totals {in,out}` cover the whole filtered set (not just the page), outlet + mode scope, `reason_label`, `terminal_name`, `source`, no N+1 (query-count assertion). Also assert the per-shift `GET /sessions/{id}/summary` `cash_in`/`cash_out` equal the sum of that shift's list rows (FR-007).
- [X] T020 [P] [US2] Vitest `qa-tests/component/CashMovementsTable.test.js`: renders entries, direction badge, filters narrow the list, totals row, empty state; and a `ShiftDetail` case showing the shift's entries.

### Implementation for US2

- [X] T021 [US2] `CashMovementController::list()` (new action, name distinct from the per-shift `index`) + `Route::get('/cash-movements', …)`; reasons resolved to labels through the existing reason-code mechanism; visibility rule shared with `ShiftListService` (do not duplicate it — extract a small `visibleSessionsQuery($user)` there and call it from both).
- [X] T022 [P] [US2] `resources/js/api/sessions.js`: `listCashMovements(params)`; `resources/js/components/session/CashMovementsTable.vue` (filters: date, user, direction; totals).
- [X] T023 [US2] Add the *Cash in/out* tab to `SessionView.vue` (owner/admin) and the entries section to `ShiftDetail.vue` (uses `GET /sessions/{id}/cash-movements`); i18n keys in both locales.

**Checkpoint**: `CashMovementsListTest` and Vitest US2 tests green.

---

## Phase 5: User Story 3 — Close shift: sales info, typed amount, no EDC (Priority: P1)

**Goal**: the close screen shows full sales information (including expected cash), accepts a typed total, has no EDC, and the saved close is visible in the web.

**Independent Test**: close a shift by typing an amount; no EDC field/text anywhere in the close flow; the web shows the closed shift with counted, expected and difference.

### Tests for US3

- [X] T024 [P] [US3] `tests/Feature/CloseShiftTypedTotalTest.php`: `counted_total` closes the shift (`closing_cash = counted_total`, `counted_by_denomination` null, `variance` and `expected_cash` stored); pending QRIS → 409 `pending_payments` and open tabs → 409 also for the typed path; variance above tolerance needs the approval token and the token is bound to the exact `counted_total` (a token issued for a different amount is refused); legacy `closing_cash` path unchanged; sending both `counted_total` and `counted_by_denomination` → 422; negative/non-numeric → 422; whole rupiah only; retry with the same `Idempotency-Key` closes once.
- [X] T025 [P] [US3] Update `tests/Feature/ShiftCloseBlindCountTest.php`: `edc_batch_total` sent by an old APK is ignored (not stored, absent from `z_report`); `summary` and `x-report` now include `expected_cash` (replace the old "hidden before counting" assertions — record in a comment that Q2 = B supersedes the 048 blind count).
- [X] T026 [P] [US3] Android tests: `FakePosBackend.kt` (new `closeShift(sessionId, countedTotal | denominations, key, overrideToken)` signature), new `app/src/test/java/com/warkop/pos/state/CloseShiftTypedTotalTest.kt` (typed total wins and clears steppers, steppers clear typed total, only one total submitted, no EDC value sent), and update existing close-shift store tests that passed `edcBatch`.

### Implementation for US3 — backend

- [X] T027 [US3] `CashierSessionController::close()`: add `counted_total` (nullable, numeric, min 0, whole rupiah) to validation; `$blind = counted_by_denomination is array OR counted_total !== null`; reject both together; closing cash = typed or denomination total; remove `edc_batch_total` from validation (ignored), from the `update([...])` payload (leave column NULL) and from `z_report`; bind the variance override hash to `['session_id', 'counted_by_denomination'|'counted_total']` in `OverrideGate::requireFor` call (and the matching subject in `OverrideSubject` if it enumerates fields).
- [X] T028 [US3] `summary()` and `xReport()`: include `expected_cash` (drop the `Arr::except` and the "SENGAJA tidak dikirim" comment, replace it with a note that Q2 = B ended the blind count), plus `void_count`/`void_total` and the payment-method split already returned; keep money as strings.

### Implementation for US3 — Android

- [X] T029 [P] [US3] `android-pos/core-network/.../dto/ShiftDtos.kt`: `CloseSessionRequest` → `counted_by_denomination` nullable, add `counted_total` (nullable), remove `edc_batch_total`; make sure explicit nulls are not serialized (existing `ApiClient.json` config).
- [X] T030 [US3] `data/PosBackend.kt` + `data/RemoteBackend.kt` + `state/PosStore.kt`: `closeShift`/`submitCount` take either a denomination map or a typed total (sealed `ShiftCount`), no `edcBatch` parameter; `PosStore.loadReport`/summary loading exposes `expected_cash` and sales figures to the close screen.
- [X] T031 [US3] Rework `ui/screens/ShiftScreens.kt::CloseShiftScreen`: add a sales-information card (receipts, sales total, payment split, cash in/out, voids, expected cash) above the count; add a typed-total field (digits only, thousand separators shown, whole rupiah) next to the denomination steppers with the one-source-wins rule; delete the EDC label, field and helper text; keep the online / no-pending gates and the confirm sheet ("Kirim hitungan"). Remove EDC wording from `CloseResultScreen` and any Z-report print line (`print/Documents.kt`) if present.

**Checkpoint**: `CloseShiftTypedTotalTest`, `ShiftCloseBlindCountTest`, Android close tests green; web closed-shift detail (T016) shows the result.

---

## Phase 6: User Story 4 — Open shift screen (Priority: P2)

**Goal**: readable denomination rows, typed opening total, no skip link.

**Independent Test**: on the tablet in landscape every label is fully visible; typing 100000 opens a shift with that opening cash; there is no "Lewati, ambil order saja".

- [X] T032 [P] [US4] Android tests `app/src/test/java/com/warkop/pos/state/OpenShiftTypedAmountTest.kt` (typed amount → `opening_cash` only, steppers → denominations only, switching clears the other, Rp 0 still asks for confirmation) and a layout-rule unit test for the new `OpenShiftLayout` helper (below): at widths < 440 dp per cell the layout is one row per denomination, never 2-up; label min width ≥ 120 dp.
- [X] T033 [US4] `ui/screens/OpenShiftScreen.kt`: fix clipping — label gets a minimum width, subtotal column narrower, extract the "2-up or 1-up" decision into a small pure function (testable); add a "Total modal (ketik)" field with the one-source-wins rule from research D9; remove the `TextButton` "Lewati, ambil order saja" and any code path that navigates to orders without an open shift.
- [X] T034 [US4] `state/PosStore.kt::openShift`, `data/PosBackend.kt::openShift`, `data/RemoteBackend.kt::openShift`: accept typed total (`opening_cash`) or denominations; keep the server reconciliation rule (the app sends exactly one).

**Checkpoint**: Android US4 tests green; visual check on tablet/emulator (T048).

---

## Phase 7: User Story 5 — QRIS sheet (Priority: P2)

**Goal**: QR from the payment channel, title beside the QR icon, no warning, new instruction text, honest failure state.

**Independent Test**: with the web QRIS channel's QR set, the sheet shows it; text/title/warning match FR-016..019; an unreachable image shows "Muat ulang".

- [X] T035 [P] [US5] Android tests: `app/src/test/java/com/warkop/pos/ui/QrisSheetTextTest.kt` over a new `QrisTexts` object (exact instruction string "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order", no warning string anywhere), and `QrisQrStateTest.kt` for the three QR states (no URL → "QR belum diatur"; URL + load failure → "tidak dapat dimuat" with retry; success → image) as a pure state function.
- [X] T036 [US5] `ui/Sheets.kt`: in `QrisRecordDialog` move the title (`QrisMapper.sheetTitle(...)`) into the header row beside the QR icon; delete the warning row (≈ line 515) and ALSO the same warning in `QrisVerifyDialog` (≈ line 598) for consistency; replace the instruction text (≈ line 530) via `QrisTexts`.
- [X] T037 [US5] `ui/Sheets.kt::QrCodeBox/QrCard` + `data/QrImageLoader.kt`: distinguish *not set* from *could not load* (retry button re-fetches and evicts the cached failure); verify against the real dev channel (id 3) whether the earlier "QR belum diatur" came from a missing upload or an unreachable `/storage/...` URL and record the finding in `specs/065-android-shift-payment-updates/evidence.md`; if `RemoteBackend.qrisChannel()` picks the wrong channel when several `qr_ewallet` rows exist, pick the first active one that HAS an image.

**Checkpoint**: Android US5 tests green; on-device check (T048).

---

## Phase 8: User Story 6 — Server address from the web setting (Priority: P2)

**Goal**: no address input; the tablet learns the admin's choice once from the server via build-time defaults and keeps it until data is cleared.

**Independent Test**: choose Local in the web; set up a fresh tablet: no address field, setup completes against the local address; clear data and set up again: address fetched again; both defaults unreachable → clear message + retry.

### Tests for US6

- [X] T038 [P] [US6] `tests/Feature/ServerAddressPublicTest.php` for `GET /server-address`: no token needed; returns only `{selected, url}` (the non-selected address is never in the payload); `url: null` when the chosen option has none; throttled (31st request/min → 429); licence gate still applies (423 when not activated, like other routes); old `GET /devices/server-address` still works.
- [X] T039 [P] [US6] Android tests: `app/src/test/java/com/warkop/pos/data/ServerDiscoveryTest.kt` (tries local then online default with a timeout; first answering default wins; returns the server's `url`; `url: null` → "belum diatur admin" result; both unreachable → "tidak terjangkau" result; never invents an address) and update `PosStoreConnectionTest.kt` / `FakePosBackend.kt` to drop the 054 adoption tests (`adoptSelectedServer`, `serverEndpoint`, `adoptServerUrl`) and assert the stored URL is used for every request and is NOT re-read unless data is cleared.

### Implementation for US6

- [X] T040 [US6] Server: `ServerAddressController` (or a small new `PublicServerAddressController`) `show`-for-tablets action + `Route::get('/server-address', …)->middleware('throttle:30,1')` OUTSIDE `auth:sanctum` but inside the licence gate; returns `ServerAddress::current()['selected']` and `ServerAddress::selectedUrl()` only.
- [X] T041 [P] [US6] `android-pos/app/build.gradle.kts`: `buildConfigField` `DEFAULT_LOCAL_URL` and `DEFAULT_ONLINE_URL` read from Gradle/`local.properties` keys `pos.defaultLocalUrl` / `pos.defaultOnlineUrl` (emulator fallback `http://10.0.2.2:8010` for local, empty for online); document in `android-pos/quickstart.md`.
- [X] T042 [US6] Create `data/ServerDiscovery.kt` (pure logic + an injectable HTTP probe so it is unit-testable): sequential probe with 3 s timeout, returns `Found(url) | NotConfigured | Unreachable`; add `PosApi`/DTO for `GET server-address` in `core-network` (no auth header, no device header).
- [X] T043 [US6] `setup/TerminalSetupScreen.kt`: remove the "Alamat server" `OutlinedTextField` and its helper text; on screen start run discovery, show progress, then the existing admin login; on `NotConfigured` show "Alamat server belum diatur. Minta admin mengisinya di menu Server address." and on `Unreachable` show "Server tidak terjangkau." with a "Coba lagi" button — no input fallback. `data/RemoteBackend.kt::adminLogin(serverUrl, …)` keeps taking the discovered URL and persists it to `TokenVault.SERVER_URL` exactly once.
- [X] T044 [US6] Remove 054 runtime adoption: `state/PosStore.kt` (`adoptSelectedServer` and its call at ≈ line 278), `data/RemoteBackend.kt` (`adoptServerUrl`, `serverEndpoint`), `data/PosBackend.kt` (`serverEndpoint`, `ServerEndpoint`), and the `/devices/server-address` use in `PosApi.kt`/DTO (keep the server route for old APKs). Add a comment pointing to research D8 (accepted consequence: switching local/online needs a data clear).

**Checkpoint**: `ServerAddressPublicTest`, `ServerAddressTest`, Android US6 tests green.

---

## Phase 9: Polish & Cross-Cutting

- [X] T045 [P] Update `docs/openapi-pos-mvp.yaml` in the same change: `GET /sessions`, `GET /cash-movements`, `GET /dashboard/shifts`, public `GET /server-address`, `current`/`store` per-source behaviour, `close` (`counted_total`, no `edc_batch_total`), `summary`/`x-report` `expected_cash`; validate YAML loads.
- [X] T046 [P] Add the dated note to `CLAUDE.md` ("Feature 065, 2026-10-22": web shift list + cash movements + dashboard panel; app/web shift separation rule and `ShiftSource`; `counted_total` in the controlled protocol; EDC removed from close; Q2 = B supersedes the 048 blind count; public `/server-address`, build-time defaults, 054 adoption removed with its consequence) and write `specs/065-android-shift-payment-updates/release-notes.md` (upgrade steps, the Gradle properties to set, compatibility D12, rollback = `migrate:rollback --step=1` drops only the index).
- [ ] T047 Real-browser verification (headless Chrome over CDP as in feature 064's evidence; rebuild the SPA with `docker compose exec -T node npm run build` first): `/session` tabs as owner and as a cashier, shift detail drawer, cash tab, `/dashboard` panel; console clean; record in `evidence.md`.
- [ ] T048 On-device Android verification (tablet or emulator) of quickstart steps 5–8 (close by typed amount, no EDC, open-shift layout/typed amount/no skip link, QRIS sheet, fresh setup without address input and after clearing data). If no device is available, say so in `evidence.md` instead of claiming it.
- [ ] T049 Upgrade rehearsal on a COPY of the dev DB (never the dev DB itself): dump into a scratch schema, confirm `select database()` is the scratch schema BEFORE running, `outlets:reconcile --before` → `migrate` → `--after` → `--compare`, test `migrate:rollback --step=1`, then drop the scratch schema. Also confirm an old-APK style close (with `edc_batch_total`) still works against the new server.
- [ ] T050 Run the full backend suite (safe flags), `npm test`, `npm run build`, and `./gradlew testDebugUnitTest`; fix regressions; run `qa-tests/unit/localeKeys.test.js`; sweep with `grep -rniE "edc_batch|edcBatch|Alamat server|Lewati, ambil|Jangan terima bukti" app resources/js android-pos/app/src android-pos/core-network/src` — the only allowed survivors are the legacy DB column, the EDC *payment method*, and history comments.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002–T006 block US1/US2/US3 because lists and close depend on the source rule) → stories → Polish.
- **US1 before US2**: T016/`ShiftDetail.vue` and T021's shared visibility helper come from US1 (T011). **US3** backend (T027/T028) is independent of US1/US2; US3 Android needs T029–T030 first. **US4, US5, US6** are independent of each other and of US1–US3 (US4's `openShift` signature and US3's `closeShift` signature both touch `PosBackend.kt`/`RemoteBackend.kt`/`PosStore.kt` — do T030 and T034 sequentially, not in parallel).
- Within a story: tests first (they should fail), then backend, then web/Android.
- `tasks.md` T044 (remove adoption) must follow T043 (new setup flow) so the app is never without a way to get an address.

## Parallel Examples

- After T002–T005: T006, T007, T008, T009 together; then T015, T014 together.
- US3: T024, T025, T026 together; T029 with T028.
- US4/US5/US6 Android test tasks (T032, T035, T039) together.

## Implementation Strategy

- **MVP = Phase 2 + US1** (web can finally see every shift, app/web separated) — demo: open a shift on the tablet, see it in the web and on the dashboard.
- Then US2 (cash in/out) and US3 (close) — the other two P1 stories; ship together with US1 as the first release.
- US4–US6 are the Android polish/setup stories; US6 changes tablet setup so ship it with the APK that has the build-time default addresses configured.
- Server and APK must be released together (contracts). Do NOT push or open a PR without the developer's explicit authorization; commit only on request.
