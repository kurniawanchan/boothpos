---

description: "Task list for Dockerize Local Development Environment"

---

# Tasks: Dockerize Local Development Environment

**Input**: Design documents from `/specs/015-dockerize-dev-environment/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md (no `contracts/` — no API changes, see plan.md)

**Tests**: This feature's "tests" are the quickstart.md scenarios themselves (running the existing `php artisan test`/`npm test` suites through Docker and comparing pass counts to native) — there is no new application code to unit-test, so no new `tests/Feature/`/`qa-tests/` files are created by this feature.

**Organization**: Tasks are grouped by user story (US1 P1, US2 P2, US3 P2, matching spec.md's priorities). US2 and US3 both depend on US1's stack existing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

## Path Conventions

Existing single Laravel + Vue repo, branched from `main`: new top-level `docker/` directory, `docker-compose.yml`, `.env.docker.example`, `.dockerignore` at repo root, one edit to `vite.config.js`, doc updates to `README.md`/`docs/RUNBOOK.md`.

---

## Phase 1: Setup

- [X] T001 Confirm Docker is available on this dev machine: `docker --version` and `docker compose version` both succeed — no code changes

---

## Phase 2: Foundational

**Note**: US1 IS the foundational build here — there is no separate blocking infrastructure phase distinct from it. US2 and US3 both depend on US1's `docker-compose.yml`/Dockerfiles/`vite.config.js` change existing before their own tasks can be meaningfully run. Proceed directly to Phase 3.

---

## Phase 3: User Story 1 - Get the full stack running with one command (Priority: P1) 🎯 MVP

**Goal**: `docker compose up` brings up MySQL 8 + the Laravel API + the Vite dev server together, reachable in a browser, with data persisting across restarts.

**Independent Test**: Fresh clone, only Docker installed, follow quickstart.md US1 steps 1–8 — stack comes up, login works after seeding, data survives a normal restart, `down -v` gives a clean reset.

- [X] T002 [P] [US1] Create `docker/mysql/init.sql`: `CREATE DATABASE IF NOT EXISTS boothpos; CREATE DATABASE IF NOT EXISTS boothpos_test;` — mounted at MySQL's `/docker-entrypoint-initdb.d/`, runs only on first container start with an empty data volume (research.md R4)
- [X] T003 [P] [US1] Create `docker/php/Dockerfile`: base `php:8.3-cli` (research.md R2), install `bcmath`, `gd`, `intl`, `mbstring`, `pdo_mysql`, `zip` extensions (research.md R3 — traced to `maatwebsite/excel`'s own declared requirements via `composer show maatwebsite/excel`), copy the `composer` binary from the official `composer:2` image, set working dir to `/var/www/html`, copy `docker/php/entrypoint.sh` in and make it executable, `ENTRYPOINT` it
- [X] T004 [US1] Create `docker/php/entrypoint.sh`: if `vendor/` is empty run `composer install`; if `.env`'s `APP_KEY` is empty run `php artisan key:generate --ansi`; always run `php artisan migrate` (idempotent — research.md R7, deliberately NOT `db:seed`); finally `exec php artisan serve --host=0.0.0.0 --port=8000`
- [X] T005 [P] [US1] Create `docker/node/Dockerfile`: base `node:22`, working dir `/var/www/html`, an entrypoint/CMD that installs `node_modules/` if empty then runs `npm run dev -- --host 0.0.0.0`
- [X] T006 [US1] In `vite.config.js`, change the dev-server proxy's `target` from the hardcoded `'http://127.0.0.1:8000'` to `process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'` (research.md R6 — the ONLY application source change this feature makes; the fallback keeps every native, non-Docker invocation of `npm run dev` byte-for-byte unchanged)
- [X] T007 [US1] Create `docker-compose.yml` at repo root: `mysql` service (official `mysql:8.0` image, named volume for `/var/lib/mysql`, `docker/mysql/init.sql` mounted read-only, port `3306` exposed, root password + app user from env); `app` service (built from `docker/php/Dockerfile`, repo root bind-mounted to `/var/www/html`, an anonymous volume over `/var/www/html/vendor` so it isn't shadowed by the bind mount — research.md R5, port `8000` exposed, `env_file: .env`, `depends_on: mysql`); `node` service (built from `docker/node/Dockerfile`, repo root bind-mounted, an anonymous volume over `/var/www/html/node_modules` — research.md R5, port `5173` exposed, `environment: VITE_API_PROXY_TARGET=http://app:8000`, `depends_on: app`)
- [X] T008 [P] [US1] Create `.env.docker.example`: a complete Docker-ready `.env` (NOT a copy of the stale `.env.example` — research.md R8) with `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_DATABASE=boothpos`, plus every other var this app's real `.env` conventions require (check the actual `.env`-reading code / existing `.env.testing` for the full real list — `APP_NAME`, `APP_ENV=local`, `APP_KEY=` blank, `APP_URL=http://localhost:8000`, etc.), with a comment explaining it's `cp`'d to `.env` for the Docker path only
- [X] T009 [P] [US1] Create `.dockerignore` at repo root: `vendor/`, `node_modules/`, `public/build/`, `storage/*.log`, `storage/framework/cache/*`, `.git/`, `.env` (never bake secrets into a build context) etc. — mirror the existing `.gitignore`'s intent for build-context exclusions
- [X] T010 [US1] Manual verification per quickstart.md US1 (steps 1–8): fresh stack up with only Docker installed, browser login works after manual seeding, `docker compose down` + `up` preserves data, `docker compose down -v` + `up` gives a clean reset

**Checkpoint**: User Story 1 fully functional — this is the feature's MVP. A contributor can get the full stack running with one command.

---

## Phase 4: User Story 2 - Run the automated test suites inside Docker (Priority: P2)

**Goal**: `docker compose exec app php artisan test` and `docker compose exec node npm test` report the same pass counts as the native commands.

**Independent Test**: With the US1 stack running, run both Dockerized test commands and diff their reported totals against the native `php artisan test`/`npm test` output (quickstart.md US2).

- [X] T011 [US2] Manual verification per quickstart.md US2: run `docker compose exec app php artisan test` — confirm it runs against real MySQL (not SQLite) using `.env.testing`'s `boothpos_test` database and reports the same total (424 tests as of this writing) as the native command; confirm the `boothpos` (dev) database's data is untouched afterward
- [X] T012 [US2] Manual verification per quickstart.md US2: run `docker compose exec node npm test` — confirm it reports the same total (205 tests, 2 skipped as of this writing) as the native command

**Checkpoint**: User Story 2 fully functional — Dockerized test runs are trustworthy, not a second-class path.

---

## Phase 5: User Story 3 - Existing native workflow keeps working, untouched (Priority: P2)

**Goal**: Prove the native (non-Docker) workflow is byte-for-byte unaffected by this feature.

**Independent Test**: Run the pre-existing native setup on a machine that also has Docker installed and confirm no behavior changed; confirm a deliberate port clash between native and Docker fails obviously rather than silently (quickstart.md US3).

- [X] T013 [US3] Manual verification per quickstart.md US3: with the Docker stack stopped, run the native workflow (`laradock-mysql-1`, `.env` pointing at `127.0.0.1`, `php artisan serve`, `npm run dev`) and confirm `npm run dev`'s proxy still reaches `127.0.0.1:8000` with no `VITE_API_PROXY_TARGET` set anywhere in the native contributor's environment
- [X] T014 [US3] Manual verification per quickstart.md US3: attempt to start native `php artisan serve` (port 8000) and `docker compose up`'s `app` service (also port 8000) at the same time — confirm a clear "port already in use" failure, not silent double-serving

**Checkpoint**: All 3 user stories independently functional — feature complete.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T015 [P] Update `docs/RUNBOOK.md` and `README.md`: document the Docker path (prerequisites, `cp .env.docker.example .env`, `docker compose up`, seeding command, test commands, reset command) alongside — not replacing — the existing native instructions
- [X] T016 Full regression: `php artisan test` and `npm test` both fully green NATIVELY (confirming this feature didn't regress the existing native path at the code level, on top of T013/T014's behavioral checks)
- [X] T017 If any real bug was found and fixed during execution (per this repo's established convention), document it in `README.md`'s dated bug-log style

---

## Dependencies & Execution Order

- **Phase 3 (US1)** has no dependency on anything else in this feature and must complete first — it produces the `docker-compose.yml`/Dockerfiles/`vite.config.js` change every other phase verifies against.
- **Phase 4 (US2)** and **Phase 5 (US3)** both depend on Phase 3 being complete (a running stack to test against / a changed `vite.config.js` to verify the fallback of), but have no dependency on each other and can be worked in parallel once Phase 3 is done.
- Within Phase 3, T002/T003/T005/T008/T009 touch entirely different new files and can be done in parallel; T004 depends on T003 (the Dockerfile that copies it in); T006 is an isolated one-line edit to an existing file, parallel-safe with everything in Phase 3 except T007; T007 (`docker-compose.yml`) is the integration point and should come after T002–T006 exist so it can reference them correctly.
- **Phase 6 (Polish)** runs last.

## Parallel Execution Examples

```text
# Early in Phase 3, these have zero file overlap and no dependency on each other:
T002 [P] (docker/mysql/init.sql)  ‖  T003 [P] (docker/php/Dockerfile)  ‖  T005 [P] (docker/node/Dockerfile)  ‖  T008 [P] (.env.docker.example)  ‖  T009 [P] (.dockerignore)  ‖  T006 (vite.config.js)

# After Phase 3 completes, US2 and US3 verification run independently:
T011, T012 (US2)  ‖  T013, T014 (US3)
```

## Implementation Strategy

**MVP first**: Phase 3 (US1) alone delivers the entire point of "dockerize the app" for local dev — a contributor can get the full stack running with one command. This is the suggested MVP scope.

**Incremental delivery**: Phase 4 (US2, Docker-based testing) and Phase 5 (US3, native-path regression proof) are both verification-only passes with no new files beyond what US1 already created — cheap to do immediately after US1, and both required before considering the feature genuinely done (a Docker setup nobody has proven can run tests, or proven doesn't break the native path, is not trustworthy).
