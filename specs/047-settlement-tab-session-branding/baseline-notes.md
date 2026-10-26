**Classification: INTERNAL**

# Baseline notes (T001)

Recorded 2026-10-26 on branch `076-settlement-tab-session-branding` before any implementation edit.

## Frontend (Vitest) — clean baseline
`npm test`: **82 files, 787 tests passed, 2 skipped, 0 failed** (47 s).
Known pre-existing console noise in the suite (not failures): jsdom "Not implemented: navigation" and a few "socket hang up" XHR lines from older tests that call the real axios client.

## Backend (PHPUnit) — baseline is NOT a clean reference
The first full run was started with the safe command (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`) but I kept editing files while it ran, and a second test process ran in the same container (shared file cache and `storage/framework/testing` fake disks). It was stopped after ~50 minutes with 1 unattributed failure, so it is **not** used as a baseline. The authoritative backend result is the full run at the end of the feature (see the PR description / T091); any failure there is compared against `git stash`-free reasoning (is the failing test touched by the diff?) rather than against this file.

## Environment traps hit while working (all documented in CLAUDE.md / RUNBOOK)
- Two concurrent `artisan test` runs in one container collide (file cache + fake disks) → use `-e CACHE_STORE=array` and a separate database for the second run.
- `php artisan serve` re-reads `.env` and ignores container `DB_DATABASE`; a second server on another database must use `php -S`.
