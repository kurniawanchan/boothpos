# Phase 0 Research: Android Tablet Installer (Standalone)

## R1: How can an existing server-side PHP/Laravel application run entirely on-device on Android, with no rewrite?

**Decision**: Bundle a statically-built PHP runtime and launch it (via `php artisan serve` or a lightweight built-in-server invocation, matching what `docs/RUNBOOK.md` already documents for desktop dev) as a background process from a thin native Android shell, on app start. The shell's `MainActivity` hosts a `WebView` pointed at `http://127.0.0.1:<port>` — from the Vue SPA's point of view, and from the Laravel API's point of view, nothing is different from how it's reached today; only *what's on the other end of localhost* has moved from "a Mac/PC" to "this same device."

**Rationale**: This is the only approach that reuses 100% of the existing business logic without a second implementation. Every prior feature in this codebase has been built under Constitution I's "single sanctioned write path per concern" — a native reimplementation would create a second, inevitably-diverging copy of every business rule (stock movements, payment recording, status transitions, DEMO/LIVE scoping, authorization). That risk alone rules out a rewrite regardless of how appealing "a real native app" sounds.

**Alternatives considered**:
- **Full native rewrite (Kotlin/Compose or Flutter), local Room/SQLite database**: rejected. This is not "packaging the existing app for a new platform," it is building a second, independent product that happens to serve the same purpose — every business rule in `app/Services/` (stock, payments, settlements, DEMO/LIVE scoping, authorization) would need re-implementing and re-verifying from scratch, with no way to guarantee behavioral parity, and every future feature would need to be built twice from that point forward. Disproportionate to what was actually asked for ("run BoothPOS on a tablet," not "build a second BoothPOS for Android").
- **Require the user to install a general-purpose terminal/Linux app (e.g., Termux) themselves and run the existing `composer install`/`php artisan serve` steps manually**: rejected as not actually an "installer" — spec FR-001/FR-002/FR-014 require a single installable package with a guided first-run, not a manual technical setup a non-technical shopkeeper would have to perform via a command line.

## R2: What database engine backs the on-device instance?

**Decision**: MariaDB, statically built for `arm64-v8a` (and `armeabi-v7a` if device coverage requires it), bundled and launched the same way as the PHP runtime — a local background process, not a separate app the user installs.

**Rationale**: MariaDB is wire-protocol- and SQL-DDL-compatible with MySQL, including the `CHECK` constraint syntax `create_orders_and_payments_tables`/`create_preorders_tables` depend on (per CLAUDE.md: "SQLite hard-fails... two migrations use raw `DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')`"). Every existing migration, model, and query in this codebase runs against it unmodified. MySQL itself (Oracle) does not publish official Android/ARM server builds; MariaDB has a real community track record of ARM and embedded builds, making it the only realistic same-DDL substitute.

**Explicit risk flagged, not silently assumed away**: MariaDB is GPLv2-licensed. Bundling and redistributing its binary inside a distributed APK has real license-compliance obligations (source-availability/attribution requirements) that must be honored in however this app is ultimately distributed — this is a legal/process detail for whoever ships the APK, not a code change, but it must not be silently ignored. Flagged here so it surfaces before distribution, not after.

**Alternatives considered**:
- **SQLite**: rejected outright. CLAUDE.md is explicit and load-bearing on this: "MySQL 8 is required; SQLite hard-fails." Porting the two `CHECK`-constraint migrations to SQLite-compatible DDL, then re-auditing every other MySQL-specific assumption in this codebase (there is no guarantee only two migrations rely on MySQL-specific behavior — that's just the two *documented* ones), is a much larger and open-ended undertaking than substituting the *engine* while keeping the *schema* — and would require formally amending a constitution principle that currently reads as non-negotiable, not something to do as a side effect of a packaging feature.
- **A cloud-hosted or LAN-hosted MySQL the tablet talks to over network**: rejected — this is the thin-client architecture the user explicitly rejected in favor of "fully standalone" (see spec.md's Input line). Would violate spec FR-004 (zero network dependency for core operation).

## R3: What manages the lifecycle of the bundled PHP/MariaDB processes on Android?

**Decision**: A foreground `Service` (`RuntimeForegroundService`, Android's sanctioned mechanism for a long-running background process the user is aware of, shown as a persistent notification while the app is in use) starts both processes when the app is opened and stops them when the app is fully closed — not merely backgrounded, to respect Android's battery/process-management expectations for typical apps, but reliably enough that a POS session mid-sale isn't killed by the OS the moment the screen turns off. `MainActivity`'s `WebView` does not load until the service reports both processes are accepting connections (avoiding a race where the WebView hits a not-yet-listening `127.0.0.1`).

**Rationale**: Android aggressively suspends/kills background processes that aren't a foreground `Service` with a visible notification — silently trying to keep a bare background process alive would be unreliable and against platform norms (and likely to get the app flagged by battery-optimization heuristics). A foreground service with a visible "BoothPOS is running" notification is the standard, expected pattern for exactly this kind of "local server the app depends on" use case.

**Alternatives considered**: Starting the PHP/MariaDB processes lazily on first API call from the WebView — rejected, adds a confusing first-request delay/failure window and couples backend startup timing to arbitrary WebView JS behavior instead of an explicit, observable app lifecycle event.

## R4: How does first-run setup work with no network and no one else configuring anything?

**Decision**: `FirstRunSetup` runs, once, the first time the app is ever opened (detected by the absence of an already-initialized MariaDB data directory in the app's private storage): starts MariaDB pointed at a fresh, empty data directory, runs `php artisan migrate` (the same migrations the desktop version already has, unmodified), then presents the existing owner-account-creation flow already built into this app rather than inventing a new one. This satisfies spec FR-002 without introducing a second "setup wizard" concept.

**Rationale**: Reuses exactly what already exists (`php artisan migrate`, the existing login/account screens) — consistent with R1's overall reuse-first approach. No new account-creation UI needs to be designed or tested; it's the same screens already covered by this app's existing frontend tests.

## R5: How does backup/restore work without a `mysqldump`-to-`BACKUP_EXTERNAL_PATH` external drive, per spec FR-006–FR-011?

**Decision**: `BackupRestoreBridge` invokes the existing `php artisan app:backup` command against the on-device MariaDB (identical `mysqldump`-based logic in `BackupPos.php`, unmodified), producing the same archive shape the desktop version already produces, but instead of copying it to `BACKUP_EXTERNAL_PATH` (a concept that doesn't map to Android's storage model), it hands the resulting file to Android's Storage Access Framework (the OS's own "save this file" picker) so the user chooses where it goes (spec FR-007) — a cloud drive, another device, wherever they have access to from the tablet. Restore is the mirror: the user picks a file via the same OS picker, and `BackupRestoreBridge` invokes `php artisan app:restore {path} --force` only after the app's own in-UI confirmation step (spec FR-009) — the existing `--force` flag exists specifically for exactly this kind of programmatic/automated invocation, per `RestorePos.php`'s own docblock.

**Rationale**: Reuses the exact backup/restore logic already written, tested, and documented (`README.md`'s "Cadangan & pemulihan (WBS 9.2)" section) rather than inventing a parallel mechanism — only the "where does the file end up" step changes, because Android's scoped-storage model doesn't allow arbitrary filesystem paths the way a desktop `BACKUP_EXTERNAL_PATH` env var does.

**Alternatives considered**: A custom backup format specific to this platform — rejected; spec.md's own Assumptions section already commits to mirroring "the intent of this project's existing desktop backup/restore capability," and reusing the literal same command/format is the most direct way to honor that.

**Additional risk flagged**: `BackupPos.php`/`RestorePos.php` shell out to `mysqldump`, `mysql`, `tar`, and `cp` via `proc_open()`/`exec()` — these are ordinary Linux userland tools present on any desktop/CI machine, but Android does not ship them. The bundled runtime (research.md R1/R2) must therefore include not just PHP and MariaDB but also `mysqldump`/`mysql` client binaries and a minimal `tar`/`cp`-equivalent userland (or these two commands must be adapted to call an equivalent library/API directly instead of shelling out) — this is a concrete implementation detail for planning/implementation to resolve, not assumed to "just work" because it works today on desktop/CI.

## R6: What's the cold-start sequence, and what does the user see while it happens?

**Decision**: On launch, `MainActivity` shows a branded splash/loading state (not a blank white WebView) while `RuntimeForegroundService` starts MariaDB, waits for it to accept connections, starts the PHP server, waits for *it* to respond to a local health check, and only then loads the WebView. This sequencing is a new concern this platform introduces that the desktop version never had (MySQL is normally already running when a desktop user opens their browser) — explicitly tracked in plan.md's Constitution Check under Principle V rather than left unaddressed.

**Rationale**: A blank/frozen screen during a multi-second startup would read as broken, especially to non-technical staff (spec's own Edge Cases language: "a blank or frozen screen" is called out as an explicit failure mode to avoid, in the *connection-loss* context originally, but the same UX principle applies to *startup* now that standalone architecture makes startup itself a multi-second event).

## R7: Minimum Android version and device-ABI targets?

**Decision**: Android 8.0 (API 26) as the minimum supported version, `arm64-v8a` as the primary/required build target, with `armeabi-v7a` as a secondary build only if real device coverage during planning/implementation shows a meaningful share of target tablets are still 32-bit-only (increasingly rare on tablets sold in the last several years).

**Rationale**: API 26 is old enough to cover the overwhelming majority of tablets a small business would realistically still be using, while being recent enough that scoped storage, foreground service, and WebView APIs this feature depends on are all mature and stable — avoiding having to special-case older, inconsistent WebView/behavior quirks from earlier API levels. `arm64-v8a`-only keeps the number of native binaries that must be sourced/built (PHP, MariaDB, per R1/R2) to one ABI for the initial build, deferring `armeabi-v7a` as a follow-up only if actually needed, rather than committing to double the native-build surface area up front on a guess.

**Alternatives considered**: Targeting the newest Android version only — rejected, would exclude tablets already in use by exactly the kind of small shop this product serves, undermining the product's own "install on hardware you already have" value proposition.
