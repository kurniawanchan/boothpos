# Contract: Operator-Facing Deployment Commands

This is the CLI "contract" a store operator (or whoever provisions the machine) relies on. No HTTP API changes in this feature — `docs/openapi-pos-mvp.yaml` is not touched.

## Package a release (maintainer-side, run once per release, not on the store machine)

```bash
docker/store/package-release.sh <version>
```

- Builds `boothpos-store:<version>` from `docker/store/Dockerfile`.
- Produces `dist/boothpos-store-<version>.tar` (via `docker save`) for offline distribution.
- Optionally pushes the same tag to a configured registry, if the maintainer has one set up (registry path is opt-in, per R3 — the script must not fail if no registry is configured).

## First-time store setup (operator-side)

**Registry path**:
```bash
cp .env.store.example .env
docker compose -f docker-compose.store.yml pull
docker compose -f docker-compose.store.yml up -d
```

**Offline path**:
```bash
cp .env.store.example .env
docker load -i boothpos-store-<version>.tar
docker compose -f docker-compose.store.yml up -d
```

Both converge here: migrations auto-run (R4), app is reachable at `http://localhost:8000`.

## Upgrade to a new version (operator-side, fully manual — FR-010)

**Registry path**:
```bash
docker compose -f docker-compose.store.yml pull app
docker compose -f docker-compose.store.yml up -d app
```

**Offline path**:
```bash
docker load -i boothpos-store-<new-version>.tar
# edit docker-compose.store.yml's app.image tag to <new-version>
docker compose -f docker-compose.store.yml up -d app
```

The `mysql` service and its volume are never touched by either upgrade path (R2).

## Backup / restore (operator-side — unchanged commands, WBS 9.2)

```bash
docker compose -f docker-compose.store.yml exec app php artisan app:backup
docker compose -f docker-compose.store.yml exec app php artisan app:restore <path> [--force]
```

Requires `.env`'s `BACKUP_EXTERNAL_PATH` to be bind-mounted to a real external drive's host path (R5) — configured once at setup time in `docker-compose.store.yml`.
