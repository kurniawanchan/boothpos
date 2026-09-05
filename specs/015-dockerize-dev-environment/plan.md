# Implementation Plan: Dockerize Local Development Environment

**Branch**: `015-dockerize-dev-environment` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/015-dockerize-dev-environment/spec.md`

## Summary

A Docker Compose setup (`mysql` + `app` + `node` services) that reproduces the existing native
dev workflow (`laradock-mysql-1` + `php artisan serve` + `npm run dev`) as one reproducible,
one-command stack, purely for local development — no change to how the product is built or
deployed to a shopkeeper's machine. The one real source change is making `vite.config.js`'s
dev-server API proxy target configurable via an env var that defaults to today's exact
hardcoded value, so the native path is provably unaffected. Everything else is new, additive
tooling files (Dockerfiles, compose file, entrypoint script, DB init script, env example).
See research.md R1–R8 for the full reasoning, including why seeding stays a manual step and
why `vendor/`/`node_modules/` need anonymous volumes under the source bind-mount.

## Technical Context

**Language/Version**: PHP 8.3 (matching `composer.json`'s declared `^8.3` floor, not the
host's native 8.4 — research.md R2), Node 22, MySQL 8.0.

**Primary Dependencies**: Docker Compose (v2, the `docker compose` CLI plugin). No new
application-level dependency — `maatwebsite/excel`'s existing declared PHP extensions
(`bcmath`, `gd`, `intl`, `mbstring`, `pdo_mysql`, `zip`) drive the `app` image's extension list
(research.md R3).

**Storage**: MySQL 8 in a named Docker volume (persists across `docker compose down`/`up`;
only `docker compose down -v` wipes it, per FR-002).

**Testing**: `docker compose exec app php artisan test` / `docker compose exec node npm test`
must report identical pass counts to the native commands (quickstart.md US2).

**Target Platform**: Contributor's local dev machine (macOS/Linux/Windows+WSL2 with Docker
Desktop or Docker Engine) — this is dev tooling only, not a store-deployment target (spec.md
Assumptions, FR-007).

**Project Type**: Web application (existing Laravel API + Vue SPA) — this feature adds an
*alternative way to run* that same existing codebase locally, not a new project type.

**Performance Goals**: N/A — dev convenience, not a performance-sensitive path. Startup time
target is captured in SC-001 (under 10 minutes fresh-clone to logged-in) rather than a runtime
performance goal.

**Constraints**: Must not change the native (non-Docker) workflow's behavior at all (FR-004);
must satisfy every existing non-negotiable environment constraint from CLAUDE.md (MySQL-only,
separate test database, etc. — FR-006) from inside containers rather than relaxing any of them.

**Scale/Scope**: New `docker-compose.yml`, `docker/php/{Dockerfile,entrypoint.sh}`,
`docker/node/Dockerfile`, `docker/mysql/init.sql`, `.env.docker.example`, `.dockerignore`; one
small edit to `vite.config.js`; documentation updates to `README.md`/`docs/RUNBOOK.md`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability**: The one source change (`vite.config.js`'s proxy
  target) is a single, narrowly-scoped env-var fallback — not a new abstraction, not
  speculative configurability beyond what Docker networking actually requires (research.md
  R6). Required PHP extensions are traced directly to `maatwebsite/excel`'s own declared
  requirements, not guessed or over-provisioned (research.md R3). PASS.
- **II. Testing Standards**: quickstart.md US2 requires the Dockerized test commands to
  report identical pass counts to the existing native `php artisan test`/`npm test` — this
  feature doesn't get to claim done without that check actually being run. PASS.
- **III. User Experience Consistency**: N/A for this feature directly (no UI change), but the
  underlying principle (predictable, non-surprising behavior) extends to FR-004's "native path
  must not change" requirement, verified explicitly in quickstart.md US3. PASS.
- **IV/V (Security, Performance)**: No new authorization surface (dev-only tooling, not a
  deployed service); no production security exposure since this never runs on a shopkeeper's
  machine (FR-007). No performance-sensitive path. PASS.

No violations — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/015-dockerize-dev-environment/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output (no business entities — tooling files only)
└── quickstart.md         # Phase 1 output
```

No `contracts/` directory — this feature has no external interface (API/route) changes to
document; skipped per the planning template's own guidance for purely internal tooling.

### Source Code (repository root)

```text
docker-compose.yml                  # mysql, app, node services
docker/
├── php/
│   ├── Dockerfile                  # php:8.3-cli + bcmath/gd/intl/mbstring/pdo_mysql/zip
│   └── entrypoint.sh               # composer install (if needed), key:generate (if empty),
│                                    # migrate (always, idempotent), then php artisan serve
├── node/
│   └── Dockerfile                  # node:22 + npm install (if needed), then npm run dev
└── mysql/
    └── init.sql                    # CREATE DATABASE boothpos, boothpos_test (first-boot only)
.env.docker.example                 # Docker-specific overrides (DB_HOST=mysql, etc.) —
                                     # NOT a copy of the stale .env.example (research.md R8)
.dockerignore                       # vendor/, node_modules/, public/build, storage/*.log, etc.
vite.config.js                      # ONE line changed: proxy target reads
                                     # process.env.VITE_API_PROXY_TARGET, same fallback default
README.md / docs/RUNBOOK.md         # Docker path documented alongside the existing native one
```

**Structure Decision**: All new files live under a new top-level `docker/` directory (keeping
Docker-specific files out of `app/`/`resources/js/`) plus one `docker-compose.yml` at the repo
root (the conventional location `docker compose up` looks for by default) and one narrowly-
scoped edit to the existing `vite.config.js`.

## Complexity Tracking

*No violations — section intentionally left empty.*
