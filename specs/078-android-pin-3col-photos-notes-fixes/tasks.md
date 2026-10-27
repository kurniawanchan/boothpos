---
description: "Task list for 078 login lockout fix, three-column PIN screen, user photos everywhere, order note on documents"
---

# Tasks: Android POS — Three-Column PIN, User Photos Everywhere, Order Note on Documents, Login Lockout Fix

**Classification**: INTERNAL

**Input**: `specs/078-android-pin-3col-photos-notes-fixes/` (plan.md, spec.md, research.md, data-model.md, contracts/api-and-ui-contract.md, quickstart.md)

**Tests**: Included — Constitution II. PHP feature tests under `tests/Feature/`, Kotlin unit tests under `AT`. Visual states are verified on-device per `quickstart.md` (adb is not installed on this machine, so DEVICE steps stay manual and are never marked passed without evidence).

**Format**: `- [ ] T### [P?] [US?] Description with file path`. `[P]` = different files, no dependency on an unfinished task. Paths are relative to the repo root; `AP` = `android-pos/app/src/main/java/com/warkop/pos`, `AT` = `android-pos/app/src/test/java/com/warkop/pos`, `CN` = `android-pos/core-network/src/main/java/com/warkop/core/network`.

**Conventions**: code comments, docs and UI copy in Indonesian, explaining *why* (match surrounding style). **PHP tests inside the container MUST use** `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=...` (CLAUDE.md — otherwise the dev database is wiped). `docs/openapi-pos-mvp.yaml` moves in the same commit as the server tasks. Rate-limit tests must clear limiters (`RateLimiter::clear`) in `setUp`.

**Prerequisite**: this branch sits on top of 077's uncommitted work; commit or merge 077 before starting so 078 diffs stay reviewable.

## Phase 1: Setup

- [X] T001 Record the baseline in `specs/078-android-pin-3col-photos-notes-fixes/baseline.md`: Android `cd android-pos && ./gradlew :app:testDebugUnitTest` counts (expected 335 pass), and backend (safe flags) `--filter='PosSetupTest|PinLoginTest|PinLoginTerminalTest|TabLifecycleTest|OverrideGateTest|AuthTest|SalesTransactions'` counts; known pre-existing suite failure `ShiftReportParityTest`.
- [X] T002 Reproduce the lockout before changing anything and paste the evidence into `baseline.md`: from the host, `for i in $(seq 1 8); do curl -s -o /dev/null -w "%{http_code} " "localhost:8010/api/v1/staff/roster?outlet_id=9"; done` then `curl -s -XPOST localhost:8010/api/v1/auth/login -H 'Content-Type: application/json' -d '{"username":"owner","password":"wrong"}'` — record whether the login answers 429 "Too Many Attempts." (confirms the shared per-IP counter; if it answers 401, record that the cause is not reproducible from one IP and keep the feature-test reproduction in T005).

## Phase 2: Foundational (blocks US1 server work and US4 server work)

- [X] T003 Add the 429 renderer for `Illuminate\Http\Exceptions\ThrottleRequestsException` in `bootstrap/app.php` (API/JSON only): body `{message: __('auth.too_many_attempts', ['seconds' => N]), code: 'throttled', retry_after: N}`, status 429, keep the `Retry-After` header (take N from the exception headers). Add `too_many_attempts` to `lang/id/auth.php` ("Terlalu banyak percobaan. Coba lagi dalam :seconds detik.") and `lang/en/auth.php`.
- [X] T004 [P] Document the 429 shape and the limiter policy in `docs/openapi-pos-mvp.yaml` (a reusable `TooManyRequests` response with `message`, `code: throttled`, `retry_after`) and reference it from `/auth/login`, `/staff/roster`, `/auth/pin-sessions`, `/pos-setup/*`; parse-check the YAML.

**Checkpoint**: one place renders every throttle refusal in Indonesian with the wait time.

## Phase 3: User Story 1 — Signing in is never blocked by unrelated traffic (P1) 🎯 MVP

**Goal**: separate rate-limit buckets; web password login counted per account on failures only; tablet polling cannot affect any login.
**Independent test**: quickstart steps 1–3.

- [X] T005 [US1] Write `tests/Feature/TabletPollingThrottleTest.php` (clear limiters in `setUp`): 40 `GET /api/v1/staff/roster` and 40 `GET /api/v1/pos-setup/branding` from one IP never produce 429 (limit 300/min) and, afterwards, `POST /api/v1/auth/login` with the right password still returns 200; a 301st read in one minute returns 429 with `code: throttled`; `GET /pos-setup/branding` and `/staff/roster` do not share a counter with `/auth/login`.
- [X] T006 [US1] Write `tests/Feature/LoginThrottleTest.php`: 5 wrong passwords for `owner` → 401 each, the 6th → 429 `throttled` with Indonesian text containing the seconds and a `Retry-After` header, even when the 6th password is correct; a different username from the same IP still logs in (200); an unknown username counts and answers 401 with the same message as a wrong password (no enumeration) and also locks after 5; a successful login clears the counter (4 wrong, 1 right, 4 wrong → not locked); after `travel(61)->seconds()` the correct password succeeds; the `login-flood` cap (61st request/min from one IP across many usernames) → 429.
- [X] T007 [US1] Define the named limiters in `app/Providers/AppServiceProvider.php::boot` with `RateLimiter::for`: `login-flood` (60/min by ip), `tablet-read` (300/min by ip), `tablet-write` (10/min by ip), `pin-login` (20/min by `ip|outlet_id|user_id` taken from the request body — fall back to ip when absent). Comment why each exists and that named limiters hash `name.key`, which is what separates the buckets (research R1).
- [X] T008 [US1] In `routes/api.php` replace `throttle:30,1` with `throttle:tablet-read` on `/server-address`, `/pos-setup/branding`, `/pos-setup/outlets`, `/pos-setup/outlets/{outlet}/terminals`, `/staff/roster`; `throttle:10,1` on the terminal POST with `throttle:tablet-write`; `/auth/pin-sessions` with `throttle:pin-login`; `/auth/login` with `throttle:login-flood`. Update the 073 comments next to those routes.
- [X] T009 [US1] Implement the per-account failure counter in `app/Http/Controllers/Api/AuthController.php::login`: key `login|mb_strtolower(username)|ip`; before verifying, `RateLimiter::tooManyAttempts($key, 5)` → throw/return the 429 of T003 with `availableIn($key)` seconds; on wrong password or unknown user `RateLimiter::hit($key, 60)` and keep the existing 401 text; on success `RateLimiter::clear($key)`. Inactive-account 401 also counts as a failure. Passes T005 and T006.
- [X] T010 [P] [US1] Make the web login screen show the server's `message` for 429 without replacing it with a generic string: check `resources/js/stores/auth.js` (or the login view) error handling for status 429 and add a test in `qa-tests/` only if a change is needed; record "no change needed" in `evidence.md` otherwise.
- [X] T011 [US1] Android: slow the PIN-screen poll in `AP/state/PosStore.kt::startPinProbe` — roster every tick (10 s) as today, `pullBranding()` only on every 3rd tick (≈ 30 s); extend `AT/state/PosStoreModeTest.kt` with a test that over 6 ticks `branding()` is called exactly twice (counter on `FakePosBackend`) and `roster()` six times.
- [X] T012 [P] [US1] Android: in `core-network/src/main/java/com/warkop/core/network/ApiErrorMapper.kt` prefer the server message for 429 with `code == "throttled"` (already Indonesian) and keep `pin_locked` handling untouched; add a case to the existing `core-network` error-mapper test (or `AT/state/PinLockMessageTest.kt`) asserting a `throttled` body maps to `ApiError.TooMany` with the server text, and that the PIN screen shows it (`PosStore.loginPin` failure path uses `e.message`).

**Checkpoint**: US1 deliverable alone fixes the owner's lockout; ship first.

## Phase 4: User Story 2 — Three-column PIN screen, each user once (P2)

**Goal**: three columns ≥ 960 dp, two columns 720–959 dp, stacked below; one row per person; nothing clipped.
**Independent test**: quickstart step 4.

- [X] T013 [P] [US2] Update `AT/ui/PinLayoutRuleTest.kt` for the three-way rule (`THREE_COLUMN` ≥ 960, `TWO_COLUMN` 720–959, `STACKED` < 720, boundaries 719/720/959/960) and add a pure `staffRows(everyone, selectedId)` rule test in `AT/ui/StaffRowsRuleTest.kt`: every user id appears exactly once; the selected one is `expanded`; two users with the same name both appear; a user without a PIN is `dimmed` and not selectable; an empty list returns an empty list.
- [X] T014 [US2] Implement the rules in `AP/ui/PinLayoutRule.kt` (`PinLayout.THREE_COLUMN` added, `pinLayoutFor`) and a new `AP/ui/StaffRows.kt` (`data class StaffRow(user, expanded, selectable)` + `staffRows(...)`). Passes T013.
- [X] T015 [US2] Rebuild `AP/ui/screens/PinScreen.kt` for THREE_COLUMN: col 1 (weight 0.28) `BrandPanel` as before 077 (StoreMark + DEMO badge, outlet · terminal, clock back to 72 sp, date, `OutletStatusChips`, settings icon) with no staff content inside; col 2 (0.32) a fixed heading "Siapa yang bertugas?" above a `LazyColumn` (key = user id) of `StaffRow`s — expanded row: 72 dp `UserAvatar`, name 20 sp, role, highlighted container; compact rows 48 dp; dimmed no-PIN rows with "Belum ada PIN"; col 3 (0.40) PIN title, 6 dots, error line, keypad (≤ 340 dp), "Ganti outlet / terminal". Remove the 077 `SelectedUser` block and `FlowRow` picker (the source of the duplicate name and the clipped heading).
- [X] T016 [US2] Keep TWO_COLUMN (store identity + staff list in col 1, PIN in col 2) and STACKED (StoreMark, outlet line, staff list, PIN) in the same file using the same `StaffRow` list composable, so no layout can show a name twice; wrong-PIN/locked errors render only in the PIN section while the selected row stays visible.
- [X] T017 [P] [US2] Add `AT/ui/PinScreenCopyTest.kt` (pure strings/rules only): "Belum ada PIN" reason text for rows without a PIN, and `selectedAfterRosterChange(selected, staff)` (keeps the selection when still present, else the first usable user, else null) extracted from the screen's `LaunchedEffect` so it is testable.

## Phase 5: User Story 3 — User photos replace initials everywhere (P2)

**Goal**: every user avatar shows the photo when it exists, including the approver picker and a header that follows photo changes.
**Independent test**: quickstart step 5.

- [X] T018 [P] [US3] Write `tests/Feature/ApproverPhotoTest.php` (safe flags): `GET /api/v1/overrides/approvers?action=…` items carry `photo_url` (resolved URL with a photo, null without) and existing keys are unchanged; only eligible approvers appear as before.
- [X] T019 [US3] Add `photo_url` to the approver items in `app/Http/Controllers/Api/OverrideController.php::approvers` (via `ImageUploadService::url($u->photo_path)`); document in `docs/openapi-pos-mvp.yaml` (`/overrides/approvers`).
- [X] T020 [P] [US3] Android DTO/model: `photo_url` in `ApproverDto` (`CN/dto/OverrideDtos.kt`), `Approver.photoUrl` (`AP/model/PosModels.kt`), mapping through `ServerUrl.resolve` where approvers are mapped in `AP/data/RemoteBackend.kt`; update `FakePosBackend` if it builds approvers.
- [X] T021 [US3] Replace the initials circle in the approver picker (`AP/ui/Sheets.kt` near line 433, `ap.name.take(2).uppercase()`) with `UserAvatar` (build a small `StaffUser`-compatible overload or pass `photoUrl` + `initials`) so the picker shows photo or initials like every other avatar; keep 38 dp size.
- [X] T022 [P] [US3] Write `AT/state/PosStoreUserPhotoTest.kt`: after `refreshCounter()`/`refreshCurrent()` the store re-reads the current user (`FakePosBackend.meResult`) and `store.user.photoUrl` follows a changed URL; a failed read keeps the last known user; a user with no photo stays `null`.
- [X] T023 [US3] Add `me(): StaffUser` to `PosBackend`/`RemoteBackend` (`GET /auth/me` → `AuthUserDto` → `StaffUser` with `photoUrl` via `ServerUrl.resolve`) and `FakePosBackend`; in `AP/state/PosStore.kt` call it from `refreshCounter`, `refreshCurrent` and after the PIN roster refresh to update `user` (photo and name only; never the session). Passes T022.
- [X] T024 [US3] Audit remaining user-avatar renders: `grep -rn "initials\|take(2)" AP/` must list only `StoreMark` (store initials, intentional), `ProductPhoto` (items) and `UserAvatar`; record the grep output in `evidence.md`. Fix any other user avatar found.

## Phase 6: User Story 4 — Order note on receipt, kitchen ticket and transaction (P2)

**Goal**: the order-level note reaches the receipt, the KOT and the transaction record/export.
**Independent test**: quickstart step 6.

- [X] T025 [P] [US4] Write `tests/Feature/ReceiptOrderNoteTest.php`: `GET /api/v1/orders/{id}/receipt` returns `notes` (the order's note, null when none); other fields unchanged; an order of the other data mode is 404.
- [X] T026 [P] [US4] Write `tests/Feature/TicketOrderNoteTest.php`: a ticket created by sending a tab with a note carries `order_note`; changing the note afterwards makes the next read of the same ticket show the new note (live read, no snapshot); no note → null; item `note` untouched.
- [X] T027 [P] [US4] Write `tests/Feature/SalesExportOrderNoteTest.php`: `SalesTransactionsService::build(detail: true)` rows carry `notes`; the xlsx export (`POST /reports/sales/transactions/export`) has an `order_note` column after `items` with the note text and empty for orders without one (read the generated sheet with `Excel::fake()` or by calling the controller mapping).
- [X] T028 [US4] Add `'notes' => $order->notes` to the receipt payload in `app/Http/Controllers/Api/OrderController.php::receipt`, `'order_note' => $order?->notes` to `app/Http/Resources/TicketResource.php` (the ticket's `order` relation is already loaded), `'notes' => $order->notes` to `app/Services/SalesTransactionsService.php::orderRow`, and `'order_note' => $r['notes']` after `'items'` in `ReportController::exportSalesTransactions`. Passes T025–T027.
- [X] T029 [P] [US4] Document the three fields in `docs/openapi-pos-mvp.yaml` (receipt `notes`, ticket `order_note`, sales transaction `notes`, export column); parse-check.
- [X] T030 [P] [US4] Write `AT/print/DocumentsOrderNoteTest.kt`: `Documents.struk` with `orderNote = "Es sedikit"` contains a bold `Catatan order:` line followed by the text after the item lines and before the first totals separator; blank/null → no such lines; a 120-character note wraps within 32 columns with no text lost (`EscPos.wrap`); emoji/unsupported characters are replaced by `EscPos.sanitize` without throwing; `Documents.kot` prints bold `CATATAN ORDER:` + text under the header and before the items, nothing when blank; BATAL tickets unchanged.
- [X] T031 [US4] Android DTO/model/mapper: `notes` in `ReceiptDto` (`CN/dto/ReceiptDtos.kt`), `order_note` in `TicketDto` (`CN/dto/TicketDtos.kt`), `ReceiptView.orderNote` (`AP/model/PosModels.kt`) and `PrintableKot.orderNote` (`AP/model/KitchenPrint.kt`) with defaults so old constructors stay valid; map in `AP/data/ReceiptMapper.kt` and `AP/data/TicketMapper.kt` (trim; blank → null).
- [X] T032 [US4] Print the note in `AP/print/Documents.kt`: in `struk()` after the item loop and before the first totals separator add `PrintLine("Catatan order:", bold = true)` and the text (wrapped by the existing printer path); in `kot()` right after the "Dibuat oleh" line and before the separator that precedes the items add the bold `CATATAN ORDER:` block; both only when `orderNote` is not blank. Passes T030.
- [X] T033 [US4] Make sure a note added/changed on the tablet reaches a later reprint: `AP/state/PosStore.kt` reprint and send-to-kitchen paths use the freshly fetched receipt/ticket (they already call the server); add a test in `AT/state/PosStoreOrderNoteTest.kt` that a `FakePosBackend` ticket returned after `saveOrderNote` carries the note into `Documents.kot` input (use `TicketMapper.kot`).

## Phase 7: Polish & cross-cutting

- [ ] T034 Run the full Android unit suite and the full backend suite (safe flags, long run — use a background task); compare with `baseline.md`; no new failures beyond `ShiftReportParityTest`. Run `:app:assembleDebug`. **Status 2026-10-27: Android 363 pass / core-network 74 pass / assembleDebug OK; the full backend run was KILLED (exit 137) before finishing — only the targeted backend groups are verified (throttle, PIN, setup, tabs, kitchen, sales/export, override, orders). Re-run the full backend suite before merging.**
- [ ] T035 Manual `quickstart.md` steps 1–7 (DEVICE parts need a tablet/emulator; record each step as done/blocked in `evidence.md`; the printer note layout on the 58 mm TM-58V is checked on paper). Do not mark unverified steps as passed.
- [X] T036 Security recheck in `evidence.md`: (a) login brute-force still bounded (5/account/min + 60/min/IP + PIN 5-wrong lock) and no enumeration difference (timing: both paths hash-check or equal-cost — note if not); (b) tablet-read 300/min is a flood cap, not an auth control; (c) approvers `photo_url` is authenticated; (d) receipt/KOT notes are read through outlet/mode-scoped models; (e) roster `photo_url` residual risk from 077 unchanged and still awaiting the owner's decision.
- [X] T037 Update `CLAUDE.md` with a dated note (2026-10-27): named limiters per bucket and per-account failure counting for `/auth/login`, why (numeric `throttle:N,1` shares one counter per IP), the single 429 renderer, the PIN screen rule/threshold, and that the order note is read live on KOT/receipt (no snapshot). Update `docs/RUNBOOK.md` only if it documents rate limits.

## Dependencies & execution order

- **Phase 1 → Phase 2 → stories.** T003 (429 renderer) blocks T006/T009 tests that assert the message.
- **US1 first (MVP)**: T005–T006 (tests) → T007 (limiters) → T008 (routes) → T009 (controller). T011/T012 (Android) are independent of the server tasks.
- **US2** is independent of US1/US3/US4 but uses `UserAvatar` (exists since 077); T015/T016 both edit `PinScreen.kt` — sequence them.
- **US3**: server T018–T019 independent; Android T020–T021 need T019 only for real data (fake works earlier); T023 edits `PosStore.kt`, as do T011 and T033 — sequence those three.
- **US4** server T025–T029 independent of Android T030–T033; T031–T033 edit `Documents.kt`/`PosStore.kt` after T030.
- **Polish** last.

## Parallel opportunities

- Tests first, in parallel (different files): T005, T006 (US1); T013, T017 (US2); T018, T022 (US3); T025, T026, T027, T030 (US4).
- After T003: US1 server (T007–T009) and Android T011/T012 in parallel.
- US2 (Android UI) can run fully in parallel with US4 server tasks and US1 server tasks (different developers/files).
- T004, T010, T012, T029 are documentation/mapper tasks that run alongside code tasks.

## Implementation strategy

1. **MVP = US1 (T001–T012)**: unblocks the owner's sign-in. Verify with quickstart steps 1–3 before anything else.
2. **Increment 2**: US4 (note on documents — server + printing) then US3 (photos).
3. **Increment 3**: US2 (three-column PIN screen) — largest UI change, no server dependency.
4. Commit per phase; server tasks and their OpenAPI entries in the same commit. Do not push without the developer's explicit instruction.

## Summary

- **Total tasks**: 37 (T001–T037)
- **Per story**: US1 8 (T005–T012) · US2 5 (T013–T017) · US3 7 (T018–T024) · US4 9 (T025–T033) · Setup 2 · Foundational 2 · Polish 4
- **Format**: every task has a checkbox, ID, `[P]` where parallel, `[USn]` in story phases and a file path.
