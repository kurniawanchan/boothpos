# BoothPOS — Android Tablet Installer

Native Gradle/Kotlin wrapper project for running the **entire, unmodified**
BoothPOS application standalone on an Android tablet — no separate server,
no network dependency for core operation. See
`specs/008-android-installer/` (spec.md, plan.md, research.md) for the
full design and reasoning; this file is the build/operate reference,
the Android-platform equivalent of `docs/RUNBOOK.md`.

**Read research.md before changing anything here** — several choices
(MariaDB over MySQL/SQLite, foreground-service process lifecycle, the
reuse of `app:backup`/`app:restore` unmodified) are deliberate, documented
decisions with rejected alternatives on record, not arbitrary.

## What this is, and isn't

- **Is**: a thin native shell (`MainActivity` + `RuntimeForegroundService`)
  that launches a bundled PHP server and a bundled MariaDB instance as
  local background processes, then shows the existing Vue SPA in a
  `WebView` pointed at `127.0.0.1`. Zero BoothPOS business logic lives in
  this Kotlin code.
- **Isn't**: a second implementation of BoothPOS, a client to a
  separately-hosted server, or a Play Store product (spec.md's
  Assumptions — sideload distribution only).

## Prerequisites to actually build this

This scaffold was authored without an Android SDK/NDK, Gradle, Composer,
or Node toolchain available in the authoring environment — the Kotlin/
Gradle/manifest/resource *source files* are complete and reviewed, but
**building, running, and device-testing this project requires**:

- Android Studio (or a standalone Gradle + Android SDK/NDK install),
  `compileSdk 34`, `minSdk 26`, targeting `arm64-v8a` (see
  `research.md` R7).
- The existing repo's normal toolchain (`composer`, `npm`) on the machine
  running the Gradle build, since `copyLaravelApp` (in `app/build.gradle.kts`)
  shells out to `npm run build` and `composer install --no-dev` against
  the repo root.
- The runtime binaries below, which this repo does **not** vendor.

## Runtime binaries this project depends on but does not include

Per `research.md` R1/R2/R5, place these under
`app/src/main/assets/runtime/` before building (see `.gitignore` — this
directory is intentionally excluded from version control; these are
large, platform-specific native artifacts, not source):

| Binary | Purpose | Notes |
|---|---|---|
| `php` | Runs the Laravel app (`php -S 127.0.0.1:<port> -t public`) | Static build, `arm64-v8a`, with `mbstring`/`pdo_mysql`/`gd`/`zip` extensions (matches `composer.json`'s requirements + `maatwebsite/excel`'s needs) |
| `mariadbd`, `mariadb-install-db` | The embedded database server | Static build, `arm64-v8a` — **MariaDB, not MySQL** (research.md R2: MySQL publishes no official Android/ARM builds; MariaDB is wire/DDL-compatible, including the `CHECK` constraint syntax two of this app's migrations depend on) |
| `mysqldump`, `mysql` | Used unmodified by `BackupPos.php`/`RestorePos.php` | Same ABI, typically ships alongside a MariaDB client package |
| `tar` | Used unmodified by `BackupPos.php` to archive `payment-proofs/` | Android does not ship this by default — bundle a static build or a busybox equivalent |

**Sourcing strategy is an open implementation decision**, not resolved by
this scaffold — candidates include a Termux-package-derived prebuilt set
(review licensing before doing this), or building each from source
against the Android NDK's toolchain. Whichever is chosen, update
`THIRD_PARTY_LICENSES.md` accordingly (see below) before distributing a
built APK.

## Building

```bash
cd android
./gradlew assembleRelease
```

`copyLaravelApp` (a `preBuild`-wired Gradle task) runs `npm run build` and
`composer install --no-dev --optimize-autoloader` against the repo root,
then copies `app/`, `vendor/`, `database/migrations/`, `routes/`,
`config/`, `resources/views/`, `public/`, and `lang/` into
`app/src/main/assets/laravel/` — the exact same application the desktop
version ships, packaged as assets rather than modified.

`storage/` is **not** copied wholesale — `FirstRunSetup` provisions a
fresh, empty `storage/` skeleton on-device on first launch, since a
device's runtime storage (logs, cache, payment-proof uploads) must never
ship pre-populated from the build machine.

## Signing / distribution

Per `spec.md`'s Assumptions: **signed but informal** — enough to avoid
Android's "unknown sources" scare warnings on a shop's own device, no
Google Play Store listing. Standard Android release-signing (a keystore
the shop/installer controls) applies; no special process beyond what any
sideloaded Android app already requires.

## First run, on-device

1. `RuntimeForegroundService` detects no initialized MariaDB data
   directory exists yet (`FirstRunSetup.isFirstRun()`).
2. Extracts the bundled runtime binaries from `assets/runtime/` to
   app-private storage with the executable bit set (`assets/` itself is
   read-only, non-executable).
3. Generates a fresh, random, **per-installation** Laravel `APP_KEY` and
   `.env` — never a value baked into the APK (see `FirstRunSetup.kt`'s
   docblock for why: one installation's encrypted data must never be
   decryptable by another installation's key).
4. Runs `mariadb-install-db`, starts `mariadbd`, waits for it to accept
   TCP connections, runs `php artisan migrate --force`, then starts the
   PHP server and waits for it to serve a 200 from `GET /` (the existing
   SPA catch-all route, reused as the health check — research.md R3, no
   new endpoint added for this).
5. `MainActivity`'s `WebView` loads only once both processes report
   ready — never raced (research.md R6).
6. The existing, unmodified owner-account-creation screen (whatever the
   desktop version already shows for a freshly-migrated, unseeded
   database) is what the user sees next — no separate native setup UI.

## Backup / restore (US2)

Native menu items (`Cadangkan data` / `Pulihkan dari cadangan`) invoke
`BackupRestoreBridge`, which shells into the already-running on-device
PHP to run `php artisan app:backup` / `app:restore --force` — the
**exact same commands** the desktop version already has, unmodified. See
`specs/008-android-installer/contracts/backup-format.md` for the archive
shape a restore must accept, on either platform.

## Known gaps in this scaffold (explicitly, not silently)

- **App icon is a placeholder** (`res/mipmap-anydpi-v26/ic_launcher.xml`,
  `res/drawable/ic_launcher_foreground.xml`) — real BoothPOS brand
  artwork needs to be supplied before release; this environment could not
  generate raster/vector brand assets.
- **Runtime binaries are not vendored** (see table above) — sourcing/
  building them is real, unresolved lead-time work, flagged as this
  feature's single biggest risk in `tasks.md`'s Notes.
- **No automated test suite runs against this project** — verification
  is the manual device checklist in `specs/008-android-installer/quickstart.md`;
  see `plan.md`'s Constitution Check for why that's a deliberate,
  documented choice rather than an oversight.
- **Gradle wrapper JAR/scripts are not included** — a standard
  `gradle wrapper` run (with a working Gradle install) generates
  `gradlew`/`gradlew.bat`/`gradle/wrapper/gradle-wrapper.jar`; omitted
  here since the binary wrapper JAR isn't meaningfully authorable as
  source text.
