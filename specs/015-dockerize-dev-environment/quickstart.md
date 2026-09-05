# Quickstart: Dockerize Local Development Environment

## US1 — One-command full stack

1. Fresh clone, no PHP/Node/MySQL installed natively, only Docker.
2. `cp .env.docker.example .env` (per research.md R8 — NOT `.env.example`, which is stale
   stock Laravel boilerplate defaulting to SQLite).
3. `docker compose up` (first run pulls/builds images, installs `vendor/`/`node_modules/`
   inside their containers, creates `boothpos`/`boothpos_test` databases, generates `APP_KEY`,
   runs migrations, then starts `php artisan serve` and `npm run dev` together).
4. Open `http://localhost:5173` (Vite dev server) in a browser. Confirm the login screen
   loads and API calls succeed (proxied to the `app` container).
5. Log in with a seeded dev account (`owner`/`password123`) — confirm this fails gracefully
   with "no such user" until step 6 below, since seeding is a deliberate manual step
   (research.md R7).
6. `docker compose exec app php artisan db:seed --class=SakanaFridgeDemoSeeder`. Reload,
   log in again — confirm success and that the demo data (Sakana Fridge event, artists,
   products) is visible.
7. `docker compose down` then `docker compose up` again. Confirm the demo data from step 6
   is still present (FR-002 — normal stop/start never loses data).
8. `docker compose down -v` (removes volumes) then `docker compose up`. Confirm the database
   is back to empty (fresh migrations, no seeded data) — this is the deliberate "reset" path.

## US2 — Run test suites in Docker

1. With the stack running, `docker compose exec app php artisan test`. Confirm it reports the
   same total test count as running `php artisan test` natively (currently 424 tests), and
   that it ran against real MySQL (not SQLite — confirm no "SQLite" mentioned in any error,
   and that the `CHECK` constraint migrations succeeded).
2. Confirm the `boothpos` (dev) database's data from US1 step 6 is untouched after the test
   run — `tests/Feature/*` uses `RefreshDatabase` against `.env.testing`'s `boothpos_test`
   database only, never `boothpos`.
3. `docker compose exec node npm test`. Confirm it reports the same total test count as
   running `npm test` natively (currently 205 tests, 2 skipped).

## US3 — Native workflow unaffected

1. On a machine with Docker installed but NOT running (`docker compose` stack stopped), follow
   the pre-existing native steps: `laradock-mysql-1` running, `.env` pointing at `127.0.0.1`,
   `php artisan serve`, `npm run dev`. Confirm everything works exactly as it did before this
   feature existed — in particular, confirm `npm run dev`'s proxy still reaches
   `127.0.0.1:8000` with no `VITE_API_PROXY_TARGET` set (research.md R6).
2. Attempt to start both the native `php artisan serve` (port 8000) and
   `docker compose up`'s `app` service (also port 8000) at the same time. Confirm a clear
   "port already in use" failure from whichever starts second — not silent double-serving or
   data confusion (spec.md Edge Cases).

## Regression check

- `php artisan test` and `npm test`, run BOTH natively and via Docker, report identical pass
  counts.
- `docs/RUNBOOK.md`/`README.md` updated with the Docker path documented alongside (not
  replacing) the existing native instructions.
