# Data Model: Docker-Based Store Deployment

This feature introduces **no new application database entities** — the existing schema, models, and DEMO/LIVE scoping are entirely unchanged. What follows are the deployment-level concepts this feature introduces, which live outside the database.

## Deployment Artifact

The versioned, ready-to-run unit an operator obtains to stand up or upgrade a store instance.

- **Identity**: an image tag (e.g. `boothpos-store:1.3.0`), produced once by `docker/store/package-release.sh` from a given commit/release.
- **Form**: either a container-registry reference (for `docker compose pull`) or a portable `.tar` file (for `docker load`) — both represent the exact same image bytes (R3).
- **Lifecycle**: replaced wholesale on upgrade; carries no state of its own — all durable state lives in the Persistent Data Volume below.

## Persistent Data Volume

The durable storage location holding a store's actual business data.

- **Identity**: a named Docker volume (`boothpos_mysql_data`), scoped to one store deployment (one `docker-compose.store.yml` project).
- **Contents**: the full MySQL datadir — every table this app already defines (Order, OrderItem, Product, StockMovement, etc.), unchanged in shape by this feature.
- **Lifecycle**: created once on first `docker compose up`; survives container restarts, host reboots, and every app-image upgrade; destroyed only by the explicit, separate `docker compose down -v` (never part of the documented upgrade or restart flow).

## Backup Archive

Unchanged by this feature — the existing format `app:backup`/`app:restore` already produce/consume (WBS 9.2): a MySQL dump (`database.sql`) plus a payment-proofs archive (`payment-proofs.tar.gz`), copied to `BACKUP_EXTERNAL_PATH`. This feature's only obligation is to keep producing/consuming that same format from inside the new deployment shape (R5) — it does not redefine it.
