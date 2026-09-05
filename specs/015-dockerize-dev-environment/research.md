# Phase 0 Research: Dockerize Local Development Environment

## R1 — Three services (mysql, app, node), not one monolithic container

**Decision**: `docker-compose.yml` defines three services: `mysql` (official `mysql:8.0` image), `app` (a small custom image running `php artisan serve` — no Nginx/PHP-FPM split), and `node` (official `node:22` image running `npm run dev -- --host 0.0.0.0`). All three on one Compose network, source code bind-mounted into `app` and `node` for live-reload iteration (this is dev tooling, not a distributable build).

**Rationale**: This mirrors the native workflow exactly — today a contributor runs `php artisan serve`, `npm run dev`, and a standalone MySQL container (`laradock-mysql-1`) as three independent processes; Compose just gives the same three processes reproducible images and one start command (`docker compose up`), satisfying FR-001 without inventing a different runtime shape (no Nginx/PHP-FPM reverse proxy that doesn't exist in the native setup either — `php artisan serve` already IS the shipped runtime per `vite.config.js`'s own comment: "the shipped runtime is `php artisan serve` + the built assets from the same origin").

**Alternatives considered**: *A single container running everything (MySQL + PHP + Node via a process supervisor)* — rejected; harder to reason about, doesn't map to the three independently-restartable native processes a contributor already understands, and mixing a stateful database with disposable app containers in one image makes "wipe app, keep data" (FR-002) awkward.

## R2 — PHP 8.3, matching composer.json's declared floor, not whatever happens to be on the host

**Decision**: `docker/php/Dockerfile` uses `php:8.3-cli` (not 8.4, even though this dev machine happens to have 8.4.5 installed natively).

**Rationale**: `composer.json` declares `"php": "^8.3"` — pinning the Docker image to the actual declared minimum, rather than whatever version a given contributor's native PHP happens to be, is exactly the reproducibility Dockerizing is supposed to buy; running 8.4 in Docker only because the current dev machine has 8.4 natively would silently reintroduce the "works on my machine" problem this feature exists to remove.

**Alternatives considered**: *Match the host's native PHP 8.4* — rejected for the reason above; nothing in `composer.json`/`composer.lock` requires 8.4 specifically.

## R3 — Required PHP extensions come from `maatwebsite/excel`'s own declared requirements, not guesswork

**Decision**: The `app` image installs `bcmath`, `gd`, `intl`, `mbstring`, `pdo_mysql`, `zip` (via `docker-php-ext-install`/`docker-php-ext-configure` as each requires), plus the ones Laravel itself needs (`pdo`, `mbstring` already listed, `xml`, `curl` — standard for any Laravel 13 app).

**Rationale**: Confirmed via `composer show maatwebsite/excel` (the Excel export/import package this app already depends on for master-data import/export and every report export) exactly which extensions it declares — using that as the source of truth instead of trial-and-error avoids under-provisioning the image and then discovering a missing extension only when a specific screen (e.g. Excel export) is exercised.

**Alternatives considered**: *Start from a generic "laravel" community Docker image with a large extension set pre-installed* — rejected; this project's actual extension needs are small and explicit, and pinning them directly documents *why* each one is there (traceable to a real dependency) rather than inheriting an opaque, oversized image.

## R4 — MySQL seeds two databases (`boothpos`, `boothpos_test`) via one init script, matching the existing native convention exactly

**Decision**: A SQL script mounted at MySQL's `/docker-entrypoint-initdb.d/` creates both the `boothpos` (dev) and `boothpos_test` (test) databases on first container start (when the data volume is empty). `.env`'s `DB_DATABASE` and `.env.testing`'s `DB_DATABASE` continue to name these exact same two databases — nothing renamed.

**Rationale**: `CLAUDE.md`'s own non-negotiable constraint already establishes this exact two-database split ("`.env.testing` must exist and point at a separate database (`boothpos_test`)") to protect real dev data from `RefreshDatabase`'s wipe-and-reseed behavior during test runs. Docker must satisfy this existing rule, not invent a new one (FR-006) — one MySQL container, two databases, is the direct Docker translation of what already exists natively (one `laradock-mysql-1` container, two databases).

**Alternatives considered**: *A second MySQL container just for tests* — rejected; doubles the running containers for zero benefit, since MySQL already isolates databases from each other perfectly well within one instance, which is exactly why the native setup never needed two MySQL processes either.

## R5 — `vendor/` and `node_modules/` get anonymous volumes so the bind-mount doesn't shadow container-built dependencies

**Decision**: The bind mount of the repo root into both `app` and `node` containers is paired with an anonymous volume specifically over `/var/www/html/vendor` (in `app`) and `/var/www/html/node_modules` (in `node`), so each container's own `composer install`/`npm install` output survives and isn't hidden by whatever (or nothing) exists at those paths on the host.

**Rationale**: This is the standard, well-known Docker Compose gotcha for any bind-mounted PHP/Node project — without this, a host machine with no `vendor/`/`node_modules/` at all (exactly the scenario FR-001 targets: a contributor with nothing but Docker installed) would have the container's freshly-installed dependencies immediately masked by the empty host directory the bind mount imposes on top, breaking the app on first boot.

**Alternatives considered**: *Bake `vendor`/`node_modules` into the image and skip the bind mount for the whole repo* — rejected; that would mean rebuilding the image on every source change instead of live-reloading, defeating the entire point of a dev environment (this is explicitly not a distributable production build, per spec.md's Assumptions).

## R6 — Frontend-to-backend proxy target becomes configurable via an env var, defaulting to the exact existing native value

**Decision**: `vite.config.js`'s dev-server proxy target (`http://127.0.0.1:8000`) becomes `process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'`. The Docker Compose `node` service sets `VITE_API_PROXY_TARGET=http://app:8000` (the Compose network's service-name DNS); every native, non-Docker invocation of `npm run dev` gets no such env var and falls back to the exact existing hardcoded value, unchanged.

**Rationale**: Inside Docker, the `node` container's own `127.0.0.1` is itself, not the `app` container — a hardcoded `127.0.0.1:8000` literally cannot reach the backend from inside a separate container, so *something* has to become configurable. Gating it behind an env var that defaults to today's exact literal is the smallest change that satisfies both FR-001 (Docker must work) and FR-004/FR-006 (native path must be provably unchanged, not just "probably fine") — a native contributor's environment never sets `VITE_API_PROXY_TARGET`, so `npm run dev` behaves identically to before this feature.

**Alternatives considered**: *Always proxy through a fixed Docker-network hostname, unconditionally* — rejected outright; would break `npm run dev` for every contributor NOT using Docker, violating FR-004 directly.

## R7 — Migrations run automatically on `app` container start; seeding stays a deliberate, separate, manual step

**Decision**: The `app` container's entrypoint script runs `composer install` (if `vendor/` is empty), `php artisan key:generate` (if `.env`'s `APP_KEY` is empty), and `php artisan migrate` (always — idempotent, a no-op if already up to date) before starting `php artisan serve`. It does NOT run `php artisan db:seed` or the demo seeder automatically.

**Rationale**: Migrations are safe to run unconditionally (Laravel already no-ops a migration that's already applied), so auto-running them removes a manual step for FR-001's "one command" goal with no downside. Seeding is different — `SakanaFridgeDemoSeeder` is documented elsewhere in this codebase as deliberately NOT run by the base `DatabaseSeeder`/an automatic path, specifically so it stays a one-time, intentional action; auto-running it on every container start would either duplicate demo data or require it to be made idempotent in a way that's out of this feature's scope. Keeping it manual also directly satisfies FR-002's "explicit, separately-documented action" framing for anything that touches data volume state.

**Alternatives considered**: *Auto-seed on first boot only (detect empty database)* — rejected as unnecessary scope growth for a dev-convenience feature; a documented one-line `docker compose exec app php artisan db:seed --class=SakanaFridgeDemoSeeder` is not meaningfully more friction than what the native setup already asks of a contributor today, and avoids teaching the entrypoint script to distinguish "empty because fresh" from "empty because deliberately reset."

## R8 — `.env.example` is NOT the starting point for the Docker path as-is

**Decision**: A new `.env.docker.example` (or a clearly-marked section appended to the existing `.env.example`) provides the Docker-specific overrides (`DB_HOST=mysql`, `VITE_API_PROXY_TARGET` note) — it is explicitly NOT a plain `cp .env.example .env`, since the current `.env.example` is stock unmodified Laravel boilerplate (`DB_CONNECTION=sqlite`) that directly contradicts this project's own MySQL-only rule.

**Rationale**: Confirmed via reading `.env.example` that it was never updated to reflect this project's real conventions (`DB_CONNECTION=sqlite`, commented-out MySQL settings) — copying it verbatim for the Docker path would silently violate CLAUDE.md's "SQLite hard-fails" constraint on the very first migration. This is a pre-existing gap in onboarding docs, not something introduced by this feature, but Docker's own quickstart must route around it rather than repeat it.

**Alternatives considered**: *Fix `.env.example` itself to default to MySQL* — out of scope for this feature (it's a pre-existing, unrelated onboarding gap affecting the native path too); flagged here rather than silently expanded into.
