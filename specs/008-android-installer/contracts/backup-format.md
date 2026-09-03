# Contract: Backup Archive Format

This feature does not expose a new HTTP API (the existing `docs/openapi-pos-mvp.yaml` is unchanged — the Android shell talks to the same Laravel API the desktop version already does, over `127.0.0.1` instead of `localhost`/LAN). The one real interface this feature defines is the **Backup Archive** a user can move between devices/storage — this is the contract other tooling (a restore on a different tablet, a desktop restore of a tablet's backup, or vice versa) must honor.

## Shape (unchanged from the existing desktop `app:backup`, per research.md R5)

A directory (packaged for transport as the user's file-picker step dictates — e.g. zipped by the Android shell before handing it to the Storage Access Framework, since a bare directory isn't a shareable "file"):

```text
<timestamp>/
├── database.sql              # mysqldump output — full schema + data
└── payment-proofs.tar.gz     # archived storage/app/private/payment-proofs (omitted if that directory doesn't exist yet)
```

## Producer

`php artisan app:backup`, invoked identically on desktop and on the embedded Android runtime (research.md R5) — the command itself is platform-agnostic; only what happens to the resulting directory differs (desktop: optionally copied to `BACKUP_EXTERNAL_PATH`; Android: zipped and handed to the OS's save-file picker, per spec FR-007).

## Consumer

`php artisan app:restore {path} --force`, same on both platforms. Must reject (spec FR-010):
- A file that isn't a valid archive of this shape (e.g., missing `database.sql`).
- A `database.sql` that doesn't `mysqldump`-restore cleanly (surfaced as the command's existing failure path, unchanged).

## Cross-platform compatibility

Because this is the *exact same format* on desktop and Android (research.md R5's explicit reuse decision, not a platform-specific reinvention), a backup taken on a desktop install can in principle restore onto a tablet install and vice versa — this is a natural consequence of reuse, not a new requirement this feature needs to separately build or test beyond confirming the existing command behaves the same way regardless of which MySQL-compatible engine (real MySQL vs. embedded MariaDB) is on the receiving end.
