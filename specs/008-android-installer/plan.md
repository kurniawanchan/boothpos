# Implementation Plan: Android Tablet Installer (Standalone)

**Branch**: `008-android-installer` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/008-android-installer/spec.md`

## Summary

Package the entire existing BoothPOS application (PHP/Laravel backend, MySQL-schema database, Vue SPA) to run **entirely on-device** on an Android tablet, with no separate server and no network dependency for core operation. The approach reuses the existing application unmodified — no rewrite of business logic, no port to a native language, no change to the desktop/CI stack — by bundling a self-contained PHP runtime and a MySQL-protocol-compatible embedded database engine inside a thin native Android shell that launches them as local background processes and displays the existing Vue SPA in a WebView pointed at `127.0.0.1`. Backup/restore reuses the existing `app:backup`/`app:restore` command pattern, invoked against the on-device database, producing a file the user saves via Android's standard file-picker (Storage Access Framework) rather than a raw filesystem path.

## Technical Context

**Language/Version**: Unchanged for the application itself — PHP 8.3 / Laravel 13 / Vue 3, same codebase as the desktop version. New: a thin native Android shell (Kotlin, targeting Android 8.0/API 26+ per research.md R7) whose only job is process lifecycle management and hosting the WebView — it contains no business logic.

**Primary Dependencies**: Existing `composer.json`/`package.json` unchanged. New, Android-side only: a statically-built PHP-CLI/CGI binary for `arm64-v8a` (and `armeabi-v7a` for older tablets) with the same extensions this app already requires (mbstring, pdo_mysql, gd, zip for `maatwebsite/excel`, etc.), and a statically-built MariaDB server binary for the same ABIs (MySQL-wire-compatible substitute — see research.md R2 for why MariaDB, not real MySQL or SQLite).

**Storage**: MariaDB (embedded, on-device) replaces MySQL for this deployment target only — schema, migrations, and all `App\Models` are unchanged, since MariaDB accepts the same DDL (including the `CHECK` constraint syntax two migrations rely on, per CLAUDE.md's SQLite-hard-fails note). Desktop installs and the test suite (`.env.testing`) continue against real MySQL 8, untouched.

**Testing**: Existing `php artisan test` / `npm test` suites are unchanged and continue to run against real MySQL (desktop dev machine / CI) — they are not run against the embedded Android runtime. New: a manual device-verification checklist (quickstart.md) for the Android shell itself, since there is no practical way to run PHPUnit *inside* an Android app as part of this repo's existing CI.

**Target Platform**: Android tablets, API 26+ (Android 8.0+, research.md R7), `arm64-v8a` primary target (`armeabi-v7a` as a secondary build if device coverage requires it — no `x86`/`x86_64` target, since real tablets in the field are ARM).

**Project Type**: Existing web application (Laravel API + Vue SPA), **plus** a new native Android wrapper project (`android/`) — this is additive packaging, not a restructuring of the existing `app/`/`resources/js/` tree.

**Performance Goals**: App must reach a usable login screen within a few seconds of a cold launch (embedded MariaDB + PHP built-in server both need to start first) — see SC-002 in spec.md's spirit (adapted: standalone startup replaces "reach login screen" timing, since there's no network round-trip to wait on instead).

**Constraints**: Zero network dependency for all core operation (spec FR-004); on-device storage only, no external/shared storage access outside the explicit, user-directed backup/restore file flow (Android scoped storage — research.md R7); MariaDB's GPLv2 licensing must be honored when redistributing its binary inside the APK (research.md R2 flags this explicitly, not silently assumed fine).

**Scale/Scope**: Single-tablet, single-store data volumes — same order of magnitude as the desktop version's existing "single store, live event" scale, not a new multi-tenant or high-concurrency target.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality**: The existing application is reused as-is — no duplicated business logic, no second implementation of POS/stock/reports rules in a native layer. The Android shell is intentionally "dumb" (process lifecycle + WebView only), keeping exactly one implementation of every business rule, consistent with Constitution I's single-sanctioned-path spirit, just now running on a second platform rather than a second codebase. **PASS**.
- **II. Testing**: Backend/frontend test suites are unchanged and keep running against real MySQL (this feature does not touch `phpunit.xml`/`.env.testing`, and explicitly must not — see research.md R2). Because there is no practical automated way to run this repo's test suite *inside* an Android device/emulator as part of this plan's scope, the Android shell itself is verified via a manual device checklist (quickstart.md) instead — this is a deliberate, documented gap, not a silent skip. **PASS, with documented manual-verification scope for the Android-shell-specific piece** (flagged in Complexity Tracking).
- **III. UX Consistency**: The exact same Vue SPA, same design tokens, same Indonesian-first UI copy is reused — this feature does not introduce a second UI. Touch/tablet sizing (spec FR-013) is a CSS-level concern already largely covered by this app's existing responsive design (POS screen is already tablet-oriented per prior features), verified rather than assumed in quickstart.md. **PASS**.
- **IV. Security**: No new client-supplied-money-trust surface — server-side (now on-device) computation is unchanged. New surface: the backup file now leaves device control at the user's discretion (spec FR-007) — same trust model as the existing desktop `app:backup` already has (a file the shopkeeper is responsible for), not a new exposure. Restore requires explicit confirmation before overwrite (spec FR-009), matching the existing `RestorePos`'s `--force`-gated-confirmation precedent. **PASS**.
- **V. Performance**: A cold start now pays the cost of starting an embedded database server, which the desktop version never had to do (MySQL is normally already running) — this is a genuinely new performance concern specific to this platform, addressed in research.md R6 (startup sequencing) rather than left unaddressed. **PASS, with a new platform-specific concern tracked, not ignored**.

**No violations requiring the Complexity Tracking table's standard "reject a simpler alternative" format** — but one substantive, non-standard deviation is recorded below because it's unusual enough to call out explicitly rather than bury in prose.

## Project Structure

### Documentation (this feature)

```text
specs/008-android-installer/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── contracts/
│   └── backup-format.md # Phase 1 output — the one real "interface" this feature defines
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks — not this command)
```

### Source Code (repository root)

```text
android/                              # NEW — native wrapper project (Android Studio/Gradle)
├── app/
│   ├── src/main/java/.../
│   │   ├── MainActivity.kt           # Hosts the WebView, points at http://127.0.0.1:<port>
│   │   ├── RuntimeForegroundService.kt  # Starts/stops the bundled PHP + MariaDB processes
│   │   ├── FirstRunSetup.kt          # Drives `php artisan migrate` + initial owner account on first boot
│   │   └── BackupRestoreBridge.kt    # Wraps app:backup/app:restore, hands the file to Android's Storage Access Framework
│   ├── src/main/assets/runtime/      # Bundled PHP + MariaDB static binaries (per-ABI), the existing app/ + resources/js build output, vendor/
│   └── build.gradle
└── README.md                        # How to build/sign the APK — mirrors docs/RUNBOOK.md's role for this platform

app/                                  # UNCHANGED — same Laravel app the desktop version runs
resources/js/                         # UNCHANGED — same Vue SPA, built via the existing `npm run build`
database/migrations/                  # UNCHANGED — same schema, now also applied inside the embedded MariaDB on first run
app/Console/Commands/
├── BackupPos.php                     # Reused as-is by BackupRestoreBridge.kt (invoked as a CLI call into the bundled PHP)
└── RestorePos.php                    # Reused as-is, same way
```

**Structure Decision**: Additive — a new `android/` top-level directory holds the native wrapper, entirely separate from the existing `app/`/`resources/js/` tree, which is packaged into the APK's assets rather than modified. This mirrors how `docs/RUNBOOK.md` already documents the desktop deployment process — `android/README.md` becomes the equivalent for this platform, not a rewrite of the existing one.

## Complexity Tracking

| Deviation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|---------------------------------------|
| MariaDB (not MySQL) as the embedded database engine for this one deployment target | MySQL itself publishes no official Android/ARM builds; MariaDB is wire- and DDL-compatible (including the `CHECK` constraint syntax this schema already depends on) and has a real community track record of ARM/embedded builds | SQLite was rejected outright — CLAUDE.md states two existing migrations hard-fail on SQLite by design, and porting them (plus revalidating every MySQL-specific assumption elsewhere) would be a much larger, ongoing-dual-support undertaking than swapping the *engine*, not the *schema* |
| No automated test suite runs against the embedded Android runtime itself | Running PHPUnit meaningfully inside an Android device/emulator, against the embedded MariaDB, is disproportionate new CI infrastructure for what this feature needs | A manual device-verification checklist (quickstart.md) is judged sufficient given this platform's test-authoring cost vs. this being a packaging concern, not new business logic (which *is* still covered by the existing suite, since the Laravel app itself is unchanged) |
