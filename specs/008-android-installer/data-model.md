# Phase 1 Data Model: Android Tablet Installer (Standalone)

## Existing entities: unchanged

Every `App\Models` class, every migration, every table — completely unchanged. The embedded MariaDB instance on the tablet holds the exact same schema the desktop MySQL instance does; this feature adds no columns, no tables, and no new business entities to the application itself. This is the direct consequence of research.md R1/R2's decision to reuse the existing application rather than build a second one.

## New, Android-side-only concepts (not application/business data)

These exist entirely within the native Android shell (`android/`) and are never sent to, stored in, or read by the Laravel application's own database — they are packaging/runtime concerns, not BoothPOS business data.

### Runtime State (in-process, not persisted)

Tracked by `RuntimeForegroundService` while the app is open:

| Field | Meaning |
|---|---|
| `mariadb_status` | `not_started` \| `starting` \| `ready` \| `failed` |
| `php_status` | `not_started` \| `starting` \| `ready` \| `failed` |
| `local_port` | The port the bundled PHP server is listening on, passed to `MainActivity`'s `WebView` once `php_status = ready` |

Not persisted across restarts — recomputed fresh on every app launch, per research.md R6's startup sequencing.

### First-Run Marker (persisted, on-device only)

A single boolean-equivalent marker (e.g., presence of an initialized MariaDB data directory under the app's private storage) that `FirstRunSetup` (research.md R4) checks on every launch to decide whether to run migrations/show first-run setup, or skip straight to normal startup. This is the on-device equivalent of "has `php artisan migrate` ever been run" — not a new concept, just where that fact is recorded on this platform.

## Key Entities (from spec.md, both realized entirely within the existing, unmodified application database)

- **Local Data Store**: not a new schema — this is simply *the entire existing BoothPOS database*, running inside the embedded MariaDB instance instead of a desktop MySQL instance. One per installation, exactly as spec.md's Assumptions describe (no sync between installs).
- **Backup Archive**: not a new format — this is the exact file `php artisan app:backup` already produces today (a `mysqldump` SQL file plus archived `storage/app/payment-proofs`, per `BackupPos.php`'s existing docblock), per research.md R5. See `contracts/backup-format.md` for the shape a restore operation must be able to accept.
