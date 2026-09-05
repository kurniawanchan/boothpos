# Implementation Plan: Docker-Based Store Deployment

**Branch**: `016-docker-store-deployment` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/016-docker-store-deployment/spec.md`

## Summary

Add a second, production-shaped Docker deployment path (`docker/store/`) alongside the existing dev-only Docker setup (feature 015, `docker-compose.yml`, unchanged). A single self-contained image (Composer/npm build baked in at image-build time, no bind mounts, no Node service at runtime) plus a `mysql` service with a **named, upgrade-independent volume** lets an operator run a real store install with only Docker installed — no source checkout, no local build step. The image is distributable both via a container registry (`docker pull`) and as an offline `.tar` archive (`docker save`/`docker load`), so setup and upgrade work with or without venue internet. Migrations auto-run on container start (reusing feature 015's entrypoint pattern); `app:backup`/`app:restore` keep working unmodified, with the app image now bundling `mysqldump`/`mysql` client tools and `BACKUP_EXTERNAL_PATH` bind-mounted to a real host path so backups can still land on a physical external drive.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13), Vue 3 — unchanged; no new application language.

**Primary Dependencies**: Docker Engine 24+ / Docker Compose v2 (new host-level requirement for this deployment path); reuses the app's existing Composer/npm dependency set unchanged.

**Storage**: MySQL 8, held in a named Docker volume (`boothpos_mysql_data`) decoupled from the app image's own lifecycle — an app image upgrade never touches this volume. `BACKUP_EXTERNAL_PATH` is bind-mounted from a real host path (ideally a physically removable drive), not a Docker-managed volume, so `app:backup`'s output is genuinely portable off the machine.

**Testing**: Existing `php artisan test` / `npm test` run unchanged (inside the built image, to prove the production build itself is sound — Constitution II). This feature additionally needs *operational* verification with no unit-test equivalent: restart-preserves-data, backup→restore round-trip, and upgrade→data-preserved round-trip — captured as manual verification steps in `quickstart.md`, run and recorded before this feature is considered done.

**Target Platform**: A single store laptop/PC running Docker Engine (Linux, macOS, or Windows+WSL2) — no cloud tier, no multi-server, matching the Constitution's Stack & Environment Constraints.

**Project Type**: Deployment/infrastructure feature — new Docker artifacts and operator-facing scripts/docs, no new application (PHP/Vue) code paths.

**Performance Goals**: N/A beyond the app's existing performance characteristics (Constitution V); the only new goal is operational — SC-001 (first run < 15 min), SC-004 (upgrade < 10 min).

**Constraints**: No source checkout or local build tooling required on the store machine (FR-001); no internet dependency for day-to-day operation (FR-006); dual distribution path, registry AND offline archive (FR-009); update is fully manual, no auto-check (FR-010).

**Scale/Scope**: Single store, single machine — unchanged from the existing architecture; this feature does not introduce multi-store or hosted capability.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Stack & Environment Constraints** ("single local machine per store, no cloud tier, no multi-tenancy") — ✅ PASS. This feature stays single-machine; Docker is a packaging/runtime choice, not a hosting-model change. MySQL 8 remains required (Constitution's explicit MySQL-8 rule) — the store Compose file pins `mysql:8`, matching the existing dev Compose file.
- **Principle II (Testing Standards)** — ✅ PASS, with a documented exception: the production image itself is verified by running the existing `php artisan test`/`npm test` suites inside it (proving the build is sound), but the *deployment-level* guarantees this feature exists to provide (data survives restart/upgrade, backup/restore parity) have no automated-test equivalent and are verified manually per `quickstart.md` — consistent with how feature 015 already verified its Docker-specific claims (`docker compose up` + real browser check), not a new precedent.
- **Principle IV (Security)** — ✅ PASS, with an explicit carry-forward obligation: RUNBOOK §9 already states seeded dev credentials (`password123`) MUST be changed when provisioning a real store; this feature's quickstart/docs reiterate that requirement for the Docker path specifically, rather than silently relying on the reader having seen the native-install doc.
- **Documentation & Change Discipline** — this feature reverses a previously documented decision ("production = native non-Docker install only") — per the Constitution's explicit rule, this MUST be recorded as an explicit, dated note in `CLAUDE.md` (not a silent rewrite). Planned as part of this feature's docs update (see Project Structure).

No violations requiring the Complexity Tracking table.

## Project Structure

### Documentation (this feature)

```text
specs/016-docker-store-deployment/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output (deployment-level concepts, no new DB entities)
├── quickstart.md         # Phase 1 output — setup/backup/upgrade walkthroughs + manual verification steps
├── contracts/
│   └── deployment-cli.md # Phase 1 output — operator-facing commands (start/backup/restore/upgrade/package)
└── tasks.md              # Phase 2 output (/speckit-tasks — not created by this command)
```

### Source Code (repository root)

```text
docker/
├── php/                       # UNCHANGED — feature 015's dev-only image (bind-mount, hot-reload)
├── node/                      # UNCHANGED — feature 015's dev-only Vite service
├── mysql/                     # UNCHANGED — feature 015's dev init script
└── store/                     # NEW — this feature
    ├── Dockerfile             # Multi-stage: composer install --no-dev, npm run build, single runtime image
    ├── entrypoint.sh          # Migrate-on-start, reusing feature 015's idempotent pattern
    └── package-release.sh     # Builds + tags the image; produces the offline .tar artifact (docker save)

docker-compose.store.yml       # NEW — mysql (named volume) + app (single service, no node) for real deployments
.env.store.example             # NEW — production-shaped env template (distinct from .env.docker.example)

docs/RUNBOOK.md                 # MODIFIED — new §10 documenting the store-deployment path
CLAUDE.md                       # MODIFIED — dated note reversing "production = native only"
```

**Structure Decision**: A parallel `docker/store/` tree and a separate `docker-compose.store.yml`, kept entirely apart from feature 015's `docker/php|node|mysql` and root `docker-compose.yml` — the dev path (bind-mounts, hot-reload, throwaway data) and the store path (baked-in build, no bind-mounts, durable volume) have genuinely different requirements and must not share a Dockerfile or compose file, per research.md R1.

## Complexity Tracking

*No Constitution Check violations — table intentionally omitted.*

## Implementation Notes (post-execution, 2026-09-05)

All 20 tasks in `tasks.md` completed; all three user stories verified with a real `docker build` + `docker compose -f docker-compose.store.yml up` run under an isolated project name (`boothpos-store-test`), not just by reading the files. Three real bugs were found and fixed only because of that real execution (Constitution II):

1. **`env_file: .env` is a literal filename, not variable-substituted by Compose** — a verification run against an alternate env file silently loaded the wrong one. Fixed by parameterizing it (`env_file: - ${ENV_FILE:-.env}`), defaulting to the same `.env` real usage expects.
2. **No `.env` file exists inside the container at all without a bind mount** — `env_file:` only injects environment variables into the process; it never materializes `/var/www/html/.env`. This silently broke `APP_KEY` generation (nowhere to persist a generated key across restarts) with no error until the app actually rendered a page (`MissingAppKeyException`). Fixed by bind-mounting the single host `.env` file (not source code) into the container at that path, so `php artisan key:generate` persists back to the host — durable across restarts and upgrades.
3. **Debian's `default-mysql-client` (MariaDB client) rejects MySQL 8's self-signed cert by default** ("TLS/SSL error: self-signed certificate in certificate chain"), breaking both the entrypoint's MySQL-readiness wait loop and `mysqldump` inside `app:backup` — found only by actually running `app:backup`, not by reading `BackupPos.php` (which correctly must stay unmodified per FR-004). Fixed at the image level with a global MySQL client config (`/etc/mysql/conf.d/disable-ssl.cnf`, `ssl=0`), since this is a local Compose-network connection with no external exposure.

A fourth, non-bug finding: `php artisan test` cannot run inside the built store image, because it's correctly a `--no-dev` Composer build (T004) — PHPUnit/dev tooling is absent by design, not by accident. `tasks.md`'s T019 was adjusted to reflect this rather than forcing dev dependencies into a production image; Constitution II's verification intent is satisfied instead by the native suite (427/427, unchanged) plus a real functional smoke test of the built artifact itself (login, backup, restore, full `quickstart.md` walkthrough).

All test-only artifacts (`docker-compose.store.override.test.yml`, `.env.store.test`, `tmp-backup-test/`, `dist/`, the `boothpos-store-test` Compose project and its containers/volumes, and the `boothpos-store:test`/`boothpos-store:0.0.1-test` images) were torn down after verification — none are part of this feature's committed deliverable.
