# Research: Docker-Based Store Deployment

## R1 — Separate production Dockerfile/Compose from feature 015's dev setup

**Decision**: New `docker/store/Dockerfile` + `docker-compose.store.yml`, entirely separate from feature 015's `docker/php/Dockerfile` + root `docker-compose.yml`.

**Rationale**: Feature 015's image is explicitly dev-only — it bind-mounts the whole repo (`.:/var/www/html`) and runs `php artisan serve` against source that's expected to change live, plus a separate `node` service for Vite hot-reload. A store deployment needs the opposite: `vendor/`/built frontend assets baked into the image at build time (so the store machine never runs `composer install`/`npm run build`), no bind mount (there is no source checkout on the store machine to mount), and no Node service at all at runtime (RUNBOOK §9 already establishes that a real install only ever runs `php artisan serve` against `public/build/`, Node is a build-time-only tool). Forking the Dockerfile is not duplication for its own sake — it's two genuinely different deployment shapes sharing a base image (`php:8.4-cli`, matching feature 015's own resolved PHP-version finding) and PHP extension list.

**Alternatives considered**: Adding a `target: production` stage to the existing dev Dockerfile — rejected because the dev image's entrypoint/volume assumptions (`vendor`/`node_modules` as anonymous volumes to avoid shadowing bind-mounted source) have no meaning without a bind mount, making a shared file more confusing to read than two small, single-purpose ones.

## R2 — MySQL persistence via a named volume, decoupled from the app image

**Decision**: `docker-compose.store.yml`'s `mysql` service keeps its data in a named volume (`boothpos_mysql_data`), exactly as feature 015's dev compose already does (`mysql_data`) — this part is not new, just carried forward.

**Rationale**: A named volume survives `docker compose down` (only `down -v` removes it — an explicit, separate destructive action never part of the documented upgrade flow) and is completely independent of which `app` image tag is currently deployed. Upgrading the store to a new BoothPOS version means changing the `app` service's image tag and restarting that one service — the `mysql` service and its volume are untouched by that action, which is exactly what FR-002/FR-005 require.

**Alternatives considered**: A bind-mounted host directory for MySQL's datadir — rejected: MySQL's own datadir has strict file-ownership/permission expectations that are fragile across host OS boundaries (a real concern for a Windows+WSL2 or macOS store laptop), whereas a named Docker volume is managed entirely by the Docker MySQL image itself and sidesteps that class of problem — the same reasoning feature 015 already applied.

## R3 — Dual distribution: registry pull AND offline archive

**Decision**: `docker/store/package-release.sh` builds and tags the image once, then supports two consumption paths from that same artifact: (a) push the tag to a container registry for `docker compose pull`, or (b) `docker save <tag> -o boothpos-<version>.tar` for offline transfer (USB/download), consumed via `docker load -i` on the store machine. Both converge on the same `docker-compose.store.yml` referencing the same image tag — the compose file and runtime behavior do not differ between the two paths.

**Rationale**: Per the product owner's explicit direction (spec clarification), both paths are required — a store venue's internet reliability is not guaranteed, so an offline path is not optional, but a registry path is still valuable for stores that do have reliable internet (faster, no manual file handling). `docker save`/`docker load` round-trips an image byte-for-byte with no registry involved, so this is a well-established, low-risk mechanism, not a novel one.

**Alternatives considered**: Offline-only (no registry) — rejected, it would force every store onto manual file transfer even when reliable internet is available, adding friction the spec doesn't require. Registry-only — rejected outright by the spec clarification (Option C was explicitly chosen over Option A).

## R4 — Auto-migration reuses feature 015's entrypoint pattern

**Decision**: `docker/store/entrypoint.sh` runs `php artisan migrate --force` before starting `php artisan serve`, mirroring feature 015's dev entrypoint (already idempotent — safe to run on every container start, including restarts where nothing is pending).

**Rationale**: FR-003 requires migrations to auto-apply on startup and upgrade, exactly matching the native install's existing auto-migrate behavior (`CLAUDE.md`: "migrations run automatically on container start (idempotent)") — no new mechanism needs to be invented, just carried into the new image.

**Alternatives considered**: A separate one-shot "migrate" container/job run before `app` starts — rejected as unnecessary indirection; `--force` (required outside `APP_ENV=local`) plus the idempotent-migration guarantee Laravel already provides is sufficient and matches the simpler pattern already proven in feature 015.

## R5 — Backup/restore: bundle client tools, bind-mount the external path

**Decision**: `docker/store/Dockerfile` installs `default-mysql-client` (providing `mysqldump`/`mysql`) alongside the existing PHP extensions. `docker-compose.store.yml`'s `app` service bind-mounts a host path (supplied via `.env.store`'s `BACKUP_EXTERNAL_PATH`) into the container at the same path `app:backup`/`app:restore` already expect.

**Rationale**: `app:backup`/`app:restore` (WBS 9.2, unchanged by this feature) shell out to `mysqldump`/`mysql`/`tar` and expect `BACKUP_EXTERNAL_PATH` to be a real, physically-removable location (RUNBOOK §9: "WAJIB menunjuk ke flashdisk/HDD fisik yang benar-benar tercolok"). Inside a container, a Docker-managed volume cannot be that — a bind mount to a host path (which the operator points at their actual external drive's OS-level mount point) is the only way to preserve that existing, documented guarantee. Bundling the client tools directly in the `app` image (rather than shelling out to the `mysql` container, as this repo's own dev-machine README explicitly says NOT to do via `brew install mysql-client` workarounds) keeps the backup commands identical to how they already run on a native install — same binary, same invocation, same output format.

**Alternatives considered**: Running `mysqldump` via `docker exec` into the `mysql` service from a host-side script — rejected: this would mean writing a NEW backup mechanism instead of reusing `app:backup` unmodified, directly contradicting this feature's own requirement (FR-004) to keep the existing command working as-is.

## R6 — Fully manual, single-command upgrade

**Decision**: A documented two-line upgrade procedure per distribution path — registry: `docker compose pull app && docker compose up -d app`; offline: `docker load -i boothpos-<new-version>.tar` then update the image tag in `docker-compose.store.yml` and `docker compose up -d app`. No in-app version check, no notification.

**Rationale**: FR-010 and the product owner's clarification are explicit: manual only, consistent with this product's existing no-cloud-tier, no-auto-update posture. Docker's own layer cache means a previously-loaded/pulled image tag stays available locally even after upgrading past it, so reverting to it (re-pointing the compose file's image tag and restarting) is a real, if manual, rollback path if an upgrade misbehaves — this matches, rather than exceeds, the native install's own rollback story (which also has no automated rollback of an in-place upgrade).

**Alternatives considered**: A wrapper `update.sh` script that also pings a version endpoint — rejected outright by the spec's Q2 answer (Option A, no version-check mechanism).

## R7 — Seeding stays a deliberate manual step; default credentials must be changed

**Decision**: `docker-compose.store.yml`'s `app` service runs migrations only on start — never `db:seed` or `SakanaFridgeDemoSeeder` automatically. `quickstart.md` and the new RUNBOOK §10 explicitly restate RUNBOOK §9's existing requirement that seeded dev credentials (`password123`) MUST be changed during real-store provisioning.

**Rationale**: This matches both the native install and feature 015's dev-Docker path exactly (`CLAUDE.md`: "seeding... stays a deliberate manual step, not auto-run"); a real store's first login should not silently ship with a well-known password. This feature does not add new automated credential-rotation tooling — that responsibility already sits with whoever provisions the store, unchanged.

**Alternatives considered**: Auto-generating a random admin password on first boot — appealing, but out of scope for this feature (it would need a way to *surface* that generated password to the operator, a genuinely new capability not requested in the spec); flagged as a good candidate for a future, separately-specified enhancement rather than folded in here.
