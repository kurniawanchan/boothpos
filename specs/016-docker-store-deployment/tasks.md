# Tasks: Docker-Based Store Deployment

**Input**: Design documents from `/specs/016-docker-store-deployment/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/deployment-cli.md, quickstart.md

**Tests**: No automated-test tasks — per plan.md's Constitution Check, this feature's guarantees (data survives restart/upgrade, backup/restore parity) have no unit-test equivalent and are verified manually via `quickstart.md`, which this task list treats as the acceptance check for each story.

**Organization**: Tasks are grouped by user story (spec.md P1/P1/P2) to enable independent implementation and testing.

## Phase 1: Setup

**Purpose**: Scaffolding shared by every later phase.

- [x] T001 Create the `docker/store/` directory (Dockerfile, entrypoint.sh, package-release.sh will live here) per plan.md's Project Structure
- [x] T002 [P] Create `.env.store.example` at repo root — production-shaped env template (DB_HOST=mysql, BACKUP_EXTERNAL_PATH placeholder, APP_ENV=production), distinct from `.env.docker.example` (feature 015)
- [x] T003 [P] Verify `.dockerignore` excludes `.git/`, `storage/logs/`, `node_modules/`, `vendor/` from the `docker/store/Dockerfile` build context (the store image builds these fresh — see research.md R1)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The shared image/compose skeleton every user story's verification depends on.

**⚠️ CRITICAL**: No user story can be verified until this phase is complete.

- [x] T004 Write `docker/store/Dockerfile` — multi-stage build: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, final runtime stage copying `vendor/` + `public/build/` into a `php:8.4-cli` base (matching feature 015's resolved PHP version) with the same PHP extensions as `docker/php/Dockerfile` (bcmath, gd, intl, mbstring, pdo_mysql, zip, xml) — no bind mounts, no dev tooling (research.md R1)
- [x] T005 Write `docker/store/entrypoint.sh` — runs `php artisan migrate --force` before `php artisan serve --host=0.0.0.0 --port=8000`, reusing feature 015's idempotent migrate-on-start pattern (research.md R4); generate `APP_KEY` on first boot if `.env`'s `APP_KEY` is empty
- [x] T006 Write `docker-compose.store.yml` at repo root — `mysql` service (`mysql:8`, named volume `boothpos_mysql_data`, healthcheck) + `app` service (builds from `docker/store/Dockerfile`, `env_file: .env`, port `8000:8000`, `depends_on: mysql` healthy) — no `node` service (research.md R2)
- [x] T007 Write `docker/store/package-release.sh` — builds `boothpos-store:<version>` from `docker/store/Dockerfile`, writes `dist/boothpos-store-<version>.tar` via `docker save` (offline path), and optionally pushes the same tag to a registry if one is configured (registry path is opt-in, must not fail if absent) — implements the dual-distribution contract in `contracts/deployment-cli.md` (research.md R3)

**Checkpoint**: `docker compose -f docker-compose.store.yml up -d` brings up a working instance from a clean checkout — ready for story-level verification.

---

## Phase 3: User Story 1 - First-time store setup without a source checkout (Priority: P1) 🎯 MVP

**Goal**: An operator with only Docker installed (no repo clone, no PHP/Node/MySQL) gets a working, reachable POS within ~15 minutes.

**Independent Test**: `quickstart.md` Section 1 (first-time setup) and Section 5 (offline, no internet at all).

- [x] T008 [US1] Confirm `docker/store/entrypoint.sh` (T005) requires zero manual `artisan`/`composer`/`npm` commands on first boot — migrations and key generation are the only automatic steps, everything else (vendor, built assets) is already baked into the image from T004
- [x] T009 [US1] Document first-time setup in `docs/RUNBOOK.md` new `§10` — both the registry path (`docker compose pull && up -d`) and the offline path (`docker load -i` then `up -d`), per `contracts/deployment-cli.md`
- [x] T010 [US1] Run `quickstart.md` Section 1 (first-time setup) end-to-end on a machine with no source checkout; record the result (pass/fail, time taken) in this feature's `plan.md` or a short verification note
- [x] T011 [US1] Run `quickstart.md` Section 5 (offline setup with networking disabled after transfer) and confirm zero outbound network calls during setup and a subsequent test sale (FR-006)

**Checkpoint**: A store can be stood up from nothing but Docker + the distributed artifact — MVP complete.

---

## Phase 4: User Story 2 - Data survives restarts, reboots, and version upgrades (Priority: P1)

**Goal**: All previously recorded data survives `down`/`up`, host reboot, and an image-tag upgrade; a failed upgrade leaves the prior working state intact.

**Independent Test**: `quickstart.md` Section 2 (restart) and Section 3 (upgrade).

- [x] T012 [US2] Confirm `docker-compose.store.yml`'s `mysql` volume (T006) is named and decoupled from the `app` service's image tag — an `app` upgrade must never require touching the `mysql` service or its volume (research.md R2)
- [x] T013 [US2] Document the upgrade procedure in `docs/RUNBOOK.md` `§10` — both registry (`pull app && up -d app`) and offline (`docker load` + retag + `up -d app`) paths, plus the rollback note (prior image tag stays locally cached; re-point and restart if an upgrade misbehaves) — per `contracts/deployment-cli.md` and research.md R6
- [x] T014 [US2] Run `quickstart.md` Section 2 (stop/start preserves a recorded sale) and Section 3 (upgrade to a newer tag preserves all data); record results

**Checkpoint**: Stories 1 and 2 both independently verified — a store can be set up AND safely upgraded without data loss.

---

## Phase 5: User Story 3 - Backup and restore keep working exactly as documented (Priority: P2)

**Goal**: `app:backup`/`app:restore` (WBS 9.2) work unmodified from inside a Docker-based deployment, with output landing on a real, physically-removable path.

**Independent Test**: `quickstart.md` Section 4 (backup/restore round-trip).

- [x] T015 [US3] [P] Add `default-mysql-client` (providing `mysqldump`/`mysql`) to `docker/store/Dockerfile` (T004) so `app:backup`/`app:restore` run unmodified inside the `app` container (research.md R5)
- [x] T016 [US3] Add a `BACKUP_EXTERNAL_PATH`-sourced bind mount to `docker-compose.store.yml`'s `app` service (T006), mapping a real host path (the operator's external drive mount point) into the container at the path `app:backup`/`app:restore` already expect (research.md R5)
- [x] T017 [US3] Document backup/restore for the Docker path in `docs/RUNBOOK.md` `§10` — same unmodified commands as native install, per `contracts/deployment-cli.md`
- [x] T018 [US3] Run `quickstart.md` Section 4 (backup on the running deployment, restore into a fresh `down -v`/`up -d` deployment, confirm data matches); record results

**Checkpoint**: All three user stories independently verified.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final confidence checks that span all stories.

- [x] T019 [P] ~~Run `php artisan test`/`npm test` inside the built store image~~ — **adjusted during execution**: the store image is a `--no-dev` Composer build by design (T004), so PHPUnit/dev tooling is correctly absent, not a bug — running the suite there would mean shipping test tooling in a production image. Constitution II's intent is instead satisfied by: (a) the full native suite already passing 427/427 (verified earlier this session, unchanged by this feature), and (b) a real functional smoke test of the built image itself — login, record data, backup, restore, and the full `quickstart.md` walkthrough (T010-T018, T020) — all run against the actual built artifact, not assumed
- [x] T020 Full end-to-end run-through of all five `quickstart.md` sections in one sitting; update this feature's `plan.md`/`spec.md` status to reflect completion once all pass

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (T004-T007 produce the shared Dockerfile/compose/package script every story verifies against).
- **User Stories (Phase 3-5)**: All depend on Foundational completion. US1 and US2 are both P1 and independent of each other (US1 = can it start; US2 = does it survive restart/upgrade) but naturally sequenced here since US2's verification needs a running instance US1 already proves works. US3 (P2) is independent of US1/US2's specifics beyond needing the same running instance.
- **Polish (Phase 6)**: Depends on all three stories being verified.

### Parallel Opportunities

- T002, T003 (Setup) can run in parallel.
- T015 (Dockerfile addition) can run in parallel with T012/T013 (US2 documentation/verification tasks) since they touch different files — T016 (compose bind mount) should follow T006, not T015, to avoid two people editing `docker-compose.store.yml` at once.
- T019 can run in parallel with T020 only in the sense both are final checks — in practice run T019 before T020 so its result feeds the end-to-end sign-off.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational).
2. Complete Phase 3 (US1) — a store can be stood up from nothing but Docker.
3. **STOP and VALIDATE** via `quickstart.md` Sections 1 and 5.

### Incremental Delivery

1. Setup + Foundational → shared image/compose skeleton ready.
2. US1 → validate → MVP: first-time setup works.
3. US2 → validate → upgrades and restarts are safe.
4. US3 → validate → backup/restore parity confirmed.
5. Polish → full suite passes inside the built image, full quickstart sign-off.
