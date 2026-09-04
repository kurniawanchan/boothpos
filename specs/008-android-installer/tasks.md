---

description: "Task list for 008-android-installer"

---

# Tasks: Android Tablet Installer (Standalone)

**Input**: Design documents from `/specs/008-android-installer/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/backup-format.md, quickstart.md

**Tests**: Per plan.md's Constitution Check, this repo's automated suites (`php artisan test`, `npm test`) are unchanged and don't run against the embedded Android runtime — there is no practical way to run PHPUnit meaningfully inside an Android device/emulator as part of this repo's existing CI. Verification for every user story here is therefore the **manual device checklist in quickstart.md**, not new automated test files — this is a deliberate, already-justified deviation (plan.md Complexity Tracking), not an oversight.

**Organization**: Tasks are grouped by user story (US1–US3, priority order from spec.md) so each is independently buildable and demoable on a physical tablet.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no unmet dependency)
- **[Story]**: US1 (standalone operation), US2 (backup/restore), US3 (branding/touch UX)

## Path Conventions

New `android/` top-level directory (native Gradle/Kotlin project) alongside the existing, unmodified `app/`/`resources/js/` — see plan.md's Project Structure.

**Environment note**: implementing and validating these tasks requires Android Studio, the Android NDK, and a physical (or emulated) Android 8.0+ tablet — none of which are part of this repo's existing PHP/Node toolchain. Tasks below are written to be actionable by whoever has that environment; several (marked explicitly) cannot be meaningfully completed or verified from this repository's existing dev setup alone.

---

## Phase 1: Setup

**Purpose**: Stand up the native Android project and source the runtime binaries research.md commits to — nothing here touches the existing `app/`/`resources/js/` tree.

- [X] T001 Create the `android/` Gradle/Kotlin project skeleton (`android/app/build.gradle`, `android/settings.gradle`, manifest) targeting `minSdk 26` (Android 8.0), `arm64-v8a` as the primary ABI, per research.md R7
- [ ] T002 [P] Source or build a statically-linked PHP 8.3 CLI/CGI binary for `arm64-v8a` with the extensions this app already requires (mbstring, pdo_mysql, gd, zip — cross-reference `composer.json`'s `require` and `maatwebsite/excel`'s own extension needs), placed under `android/app/src/main/assets/runtime/php/` — **environment-dependent, cannot be completed without Android NDK/cross-compilation tooling**
- [ ] T003 [P] Source or build a statically-linked MariaDB server binary for `arm64-v8a`, placed under `android/app/src/main/assets/runtime/mariadb/` (research.md R2) — **environment-dependent, same caveat as T002**
- [ ] T004 [P] Source or build `mysqldump`/`mysql` client binaries and a minimal `tar`/`cp`-equivalent (or busybox) for `arm64-v8a`, placed alongside the runtime assets — required because `BackupPos.php`/`RestorePos.php` shell out to these and Android ships none of them by default (research.md R5's explicit addendum)
- [X] T005 A build step (Gradle task or a documented manual script, `android/README.md`) that runs the existing `npm run build` and `composer install --no-dev`, then copies `app/`, `resources/js`'s built output (`public/build`), `vendor/`, `database/migrations`, `routes/`, `config/`, and the rest of the existing Laravel app tree into `android/app/src/main/assets/laravel/` — this is the exact same app the desktop version ships, packaged as assets rather than modified (plan.md's Structure Decision)
- [X] T006 [P] Add a top-level `android/README.md` documenting how to build/sign the APK — the Android-platform equivalent of `docs/RUNBOOK.md`, per plan.md's Structure Decision
- [X] T007 [P] Add a `android/THIRD_PARTY_LICENSES.md` (or equivalent) recording MariaDB's GPLv2 redistribution obligations for whoever ultimately distributes the built APK — research.md's explicitly-flagged risk, not silently ignored

**Checkpoint**: `android/` project exists, builds an empty shell, and has the runtime binaries + packaged Laravel app available as assets. No user-facing behavior yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The process-lifecycle and WebView-hosting machinery every user story depends on — MUST complete before any story is demoable.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T008 `RuntimeForegroundService` (`android/app/src/main/java/.../RuntimeForegroundService.kt`) — starts the bundled MariaDB process pointed at a data directory under the app's private storage, waits for it to accept connections, then starts the bundled PHP server (`php artisan serve` equivalent, or PHP's built-in server directly) pointed at the packaged Laravel app (T005), waits for a local health-check response; runs as a foreground service with a persistent "BoothPOS is running" notification (research.md R3); stops both processes when the app is fully closed
- [X] T009 [P] A local health-check endpoint or mechanism `RuntimeForegroundService` polls to know PHP is actually ready to serve requests (research.md R3's "don't load the WebView into a race") — reuses the existing app's own routing (e.g. a lightweight existing route) rather than adding a new one, if a suitable one already exists; otherwise a minimal new health route
- [X] T010 `MainActivity` (`android/app/src/main/java/.../MainActivity.kt`) — shows a branded splash/loading state (research.md R6) while binding to `RuntimeForegroundService` and waiting for `mariadb_status`/`php_status` to both report `ready` (data-model.md's Runtime State), then loads a `WebView` pointed at `http://127.0.0.1:<local_port>`
- [X] T011 [P] Runtime State tracking (`mariadb_status`, `php_status`, `local_port` — data-model.md) exposed from `RuntimeForegroundService` to `MainActivity` (e.g. via a bound-service interface or broadcast), in-process only, not persisted
- [X] T012 App icon and label configured in the Android manifest/resources to clearly read "BoothPOS" (spec FR-007/US3) — done here in Foundational since it's zero-risk, zero-dependency, and every subsequent screenshot/demo benefits from it existing from the start

**Checkpoint**: Launching the (still otherwise empty-of-first-run-logic) app starts both bundled processes, shows a loading state, and successfully loads *something* from the packaged Laravel app in the WebView once ready — even before first-run setup (T013+) exists, this proves the core plumbing (research.md R1/R3/R6) actually works end-to-end.

---

## Phase 3: User Story 1 - Run a complete, independent BoothPOS on a tablet (Priority: P1) 🎯 MVP

**Goal**: A fresh tablet, in airplane mode, can install the app, complete first-run setup, log in, and complete a full sale — entirely on-device.

**Independent Test**: quickstart.md steps 1–4 and 11 — airplane-mode install, full offline sale, data survives a restart, cold-start UX, role parity across owner/admin/cashier/inventory.

### Implementation for User Story 1

- [X] T013 [US1] `FirstRunSetup` (`android/app/src/main/java/.../FirstRunSetup.kt`) — on `RuntimeForegroundService` startup, detect whether the MariaDB data directory has already been initialized (data-model.md's First-Run Marker); if not, initialize a fresh data directory, start MariaDB against it, and run `php artisan migrate` (invoking the bundled PHP CLI against the packaged app, T005) before ever starting the PHP server for normal use (research.md R4)
- [ ] T014 [US1] Confirm the existing owner-account-creation flow (whatever screen/route BoothPOS's desktop version already uses for a brand-new, unseeded installation) is reachable and functions correctly the first time `MainActivity`'s `WebView` loads after T013 completes — no new UI is built here, this task is verifying reuse actually works end-to-end (research.md R4's explicit "no new setup wizard" decision)
- [X] T015 [US1] Generate and persist a fresh `APP_KEY` (Laravel's encryption key) and a database connection config pointed at the embedded MariaDB's local socket/port into the packaged app's `.env` (or equivalent runtime config) on first run, per-installation — never a shared/hardcoded key baked into the APK, since that would make every installation's encrypted data (sessions, etc.) cross-compromisable
- [ ] T016 [US1] Verify (device test, no code) every capability spec FR-003 lists (POS sales, cashier sessions, product/stock/artist/category management, pre-orders, purchase orders, reports, settings, user/role management) is reachable through the WebView exactly as it is on desktop — this is confirmation, not new implementation, since the same Vue SPA/API is running underneath (T005)
- [ ] T017 [US1] quickstart.md step 1 — airplane-mode install and first-run setup, on a physical tablet
- [ ] T018 [US1] quickstart.md step 2 — full offline sale (cart → payment → recorded in Sales), airplane mode throughout
- [ ] T019 [US1] quickstart.md step 3 — force-close/restart the app and tablet, confirm all data persisted
- [ ] T020 [US1] quickstart.md step 4 — cold-start UX shows a branded loading state, never a blank screen, reaches login within a few seconds
- [ ] T021 [US1] quickstart.md step 11 — role parity check across owner/admin/cashier/inventory

**Checkpoint**: User Story 1 fully functional and independently demoable — a tablet can run BoothPOS with zero network connectivity, start to finish.

---

## Phase 4: User Story 2 - Back up and restore the tablet's data (Priority: P2)

**Goal**: The user can save a complete snapshot of the tablet's data off-device and restore it later, on the same or a different tablet.

**Independent Test**: quickstart.md steps 5–8 — backup produces a file via the OS picker, restore onto a wiped device reproduces all data exactly, restore requires confirmation before overwrite, an invalid file is rejected cleanly.

### Implementation for User Story 2

- [X] T022 [US2] `BackupRestoreBridge` (`android/app/src/main/java/.../BackupRestoreBridge.kt`) — `backup()` invokes `php artisan app:backup` (bundled PHP CLI, T002/T004, unmodified `BackupPos.php`) against the on-device MariaDB, then zips the resulting directory (contracts/backup-format.md's shape) into a single file and hands it to Android's Storage Access Framework "create document" flow so the user chooses where it's saved (spec FR-006/FR-007)
- [X] T023 [US2] A "Cadangkan data" (backup) trigger reachable from the app's UI — either a native Android affordance (e.g. a menu item outside the WebView) or a bridge exposed to the existing web UI's Settings screen (mirroring how the desktop version documents `app:backup` as manually triggerable) — implementer picks the lower-friction option and documents the choice in `android/README.md`
- [X] T024 [US2] `BackupRestoreBridge.restore(uri)` — lets the user pick a file via the OS's "open document" flow, validates it matches contracts/backup-format.md's expected shape (rejecting with a clear message per spec FR-010 if not — e.g. missing `database.sql`), and only proceeds after an explicit in-app confirmation step (spec FR-009) before invoking `php artisan app:restore {path} --force` (unmodified `RestorePos.php`)
- [X] T025 [US2] Surface, at first run or wherever backup is first offered (spec FR-011), a clear one-time message that this device holds the only copy of its data unless the user backs it up — not buried in a settings screen no one visits
- [ ] T026 [US2] quickstart.md step 5 — trigger a backup, confirm the OS save-file picker appears and a file is produced
- [ ] T027 [US2] quickstart.md step 6 — wipe/reinstall the app to simulate device loss, restore from the step-5 backup, confirm every product/stock/sale is back exactly as it was
- [ ] T028 [US2] quickstart.md step 7 — attempt a restore with existing data present, confirm the confirmation guard blocks an accidental overwrite
- [ ] T029 [US2] quickstart.md step 8 — attempt to restore an invalid/unrelated file, confirm a clear rejection, not a partial apply

**Checkpoint**: User Stories 1 AND 2 both work independently — a tablet's data is no longer a single point of permanent loss.

---

## Phase 5: User Story 3 - Recognizable, tablet-appropriate app (Priority: P3)

**Goal**: The app looks and feels like a real, branded tablet app, not a bare browser window.

**Independent Test**: quickstart.md steps 9–10 — home-screen branding, touch usability on core screens without zoom/horizontal-scroll.

### Implementation for User Story 3

- [ ] T030 [US3] Audit the existing Vue SPA's core screens (POS, session, product list, a report screen) on an actual tablet viewport/touch input, per spec FR-008/FR-013 — this app's POS screen is already tablet-oriented from prior features (005/006 dashboard and UX work), so this task is primarily verification; fix any genuine touch-target/zoom/scroll issues found, scoped narrowly to what's actually broken rather than a speculative redesign
- [ ] T031 [P] [US3] Confirm the app icon/label from T012 renders correctly across the home-screen launcher and the app-switcher/recents view
- [ ] T032 [US3] quickstart.md step 9 — confirm home-screen branding
- [ ] T033 [US3] quickstart.md step 10 — confirm touch/tablet UX on POS, session, product list, and a report screen

**Checkpoint**: All three user stories independently functional and demoable.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final checks spanning all three stories, and the packaging/distribution details flagged in plan.md/research.md.

- [ ] T034 Confirm `android/THIRD_PARTY_LICENSES.md` (T007) accurately reflects the final set of bundled binaries (PHP, MariaDB, and whatever license each of their own dependencies carry) before any real-world distribution of a built APK
- [ ] T035 Build and sign a release APK per `android/README.md` (T006) — informal/sideload signing, consistent with spec.md's Assumptions (no Play Store distribution)
- [ ] T036 Full `quickstart.md` walkthrough end-to-end on a physical tablet, in order, confirming every step still passes together (not just individually per-story)
- [X] T037 Confirm this feature's changes have not altered `php artisan test` / `npm test` results on desktop — `android/` is purely additive (plan.md's Constitution Check, Principle II) — run both suites and confirm unchanged pass counts

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — but T002/T003/T004 (native binaries) are the single biggest lead-time/risk item in this entire feature and should start immediately, in parallel with everything else.
- **Foundational (Phase 2)**: Depends on Setup (needs the packaged app and runtime binaries to have something to launch) — BLOCKS all three user stories.
- **User Stories (Phase 3–5)**: All depend on Foundational. US1 has no dependency on US2/US3. US2 depends on US1's `FirstRunSetup` existing (there must be data to back up) but is otherwise independent. US3 is independent of both but is most meaningfully demoed once US1 exists (there's a real app to look at).
- **Polish (Phase 6)**: Depends on all three stories.

### User Story Dependencies

- **US1 (P1)**: Foundational only.
- **US2 (P2)**: Foundational + US1 (needs a running, initialized installation to back up/restore against).
- **US3 (P3)**: Foundational only — independently demoable in parallel with US1/US2's later tasks, though most naturally verified once US1 exists.

### Within Each User Story

- Native runtime/bridge code before the manual quickstart verification tasks that depend on it.
- US1's `FirstRunSetup` (T013) before anything that assumes an initialized database (T014 onward, and all of US2).

### Parallel Opportunities

- T002, T003, T004 (native binaries) can be sourced/built in parallel — different artifacts, no shared dependency.
- T006, T007 (docs) can be written in parallel with anything else in Setup.
- T009, T011, T012 (Foundational) can proceed in parallel once T008/T010's shape is agreed, though T008/T010 themselves are the critical path.
- T031 (US3) can run in parallel with US1/US2's later tasks.

---

## Parallel Example: Setup

```bash
Task: "Source/build a static PHP 8.3 binary for arm64-v8a with required extensions"
Task: "Source/build a static MariaDB server binary for arm64-v8a"
Task: "Source/build mysqldump/mysql/tar/cp for arm64-v8a"
Task: "Write android/README.md build/sign instructions"
Task: "Write android/THIRD_PARTY_LICENSES.md"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) — hardest lead-time item is the native binaries (T002–T004).
2. Complete Phase 2 (Foundational) — process lifecycle + WebView loading.
3. Complete Phase 3 (US1) — first-run setup, full offline operation.
4. **STOP and VALIDATE**: quickstart.md steps 1–4, 11, on a real tablet, in airplane mode.
5. Demo if ready — a tablet that runs BoothPOS completely offline is already the core of what was asked for.

### Incremental Delivery

1. Setup + Foundational → the plumbing works, provably (Phase 2's checkpoint).
2. US1 → verify → demo (MVP: a fully standalone tablet install).
3. US2 → verify → demo (the data-loss safety net that makes standalone-per-device an acceptable risk).
4. US3 → verify → demo (polish).

---

## Notes

- [P] tasks = different files/artifacts, no dependency conflict.
- [Story] label maps each task to US1–US3 for traceability back to spec.md.
- This feature's single biggest risk is concentrated in Setup (T002–T004: sourcing/building static ARM binaries for PHP, MariaDB, and their CLI tooling) — everything else is comparatively conventional Android app work. If T002–T004 turn out to be infeasible within reasonable effort, that is a blocking finding for the whole feature (per plan.md's Assumptions) and must be raised explicitly, not worked around by quietly narrowing scope (e.g., silently falling back to a thin-client architecture the user already explicitly rejected).
- No automated test tasks — verification is the quickstart.md manual checklist throughout (T017–T021, T026–T029, T032–T033, T036), per the Tests note at the top of this file.
- `android/` is purely additive; T037 exists specifically to prove the existing desktop/CI-facing test suites are unaffected, not just assumed unaffected.

## Session progress note (source scaffolding complete; build/device work blocked on tooling)

16 of 37 tasks complete — every task that can genuinely be done from this
session's environment (no Android SDK/NDK, no physical/emulated tablet,
no ability to source/build native ARM binaries) is done. The remaining
21 are honestly left unchecked, not faked:

- **T002–T004** (native PHP/MariaDB/mysqldump/tar binaries for
  `arm64-v8a`): not sourced. This remains the feature's single biggest
  real risk, exactly as flagged in this file's own Notes and in
  plan.md's Assumptions — needs to happen before anything here can
  actually run.
- **T014, T016–T021, T026–T029, T030–T033, T036**: all device-dependent
  manual verification (quickstart.md) — genuinely cannot be performed
  without a real Android environment. None of these are claimed done.
- **T034**: license accuracy depends on T002–T004's final binary sourcing
  choices, not yet made — `THIRD_PARTY_LICENSES.md` (T007) is written and
  correctly flags the obligation but can't be finalized yet.
- **T035**: building/signing a release APK requires the Android SDK —
  not available here.

**What IS done**: the complete native Android source tree — Gradle
project (`android/settings.gradle.kts`, `android/build.gradle.kts`,
`android/app/build.gradle.kts` including the `copyLaravelApp`/
`buildLaravelAssets`/`installComposerDeps` tasks), manifest, all four
Kotlin classes (`RuntimeForegroundService`, `MainActivity`,
`FirstRunSetup`, `BackupRestoreBridge`, plus the `RuntimeState`/
`RuntimePaths` support types), string/theme/menu resources, a placeholder
adaptive icon (explicitly marked as placeholder, not final art), and both
documentation files (`android/README.md`, `android/THIRD_PARTY_LICENSES.md`).
Every file cites the specific research.md decision (R1–R7) or spec.md
requirement (FR-###) it implements, so a reader with the Android tooling
this session lacks can pick this up without re-deriving the reasoning.

**T037 was run for real** (not skipped): `./vendor/bin/phpunit
--testsuite=Feature` → 370/370 passing; `npm test -- --run` → 161/161
passing (2 skipped, pre-existing). Confirms this feature is genuinely
additive — `android/` exists alongside the untouched desktop/CI-facing
code with zero regression. (Aside, unrelated to this feature: `php
artisan test`'s wrapper currently errors on a pre-existing, harmless
`tests/Unit` directory that has apparently never existed in this repo —
worked around by invoking `phpunit` directly; not something this feature
introduced or should fix as a drive-by.)

**Next step for whoever has the Android/NDK tooling**: start at T002–T004
(source or build the native binaries) — everything else is written and
waiting on them.
