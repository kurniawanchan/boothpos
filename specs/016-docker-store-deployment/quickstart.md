# Quickstart & Manual Verification: Docker-Based Store Deployment

These steps double as this feature's acceptance verification (Constitution II — no automated-test equivalent exists for deployment-level guarantees; see `plan.md`'s Constitution Check).

## 1. First-time setup (User Story 1)

1. On a clean machine with only Docker installed (no repo clone), obtain `boothpos-store-<version>.tar` (offline) or configure registry access.
2. `cp .env.store.example .env`, fill in `BACKUP_EXTERNAL_PATH` and any store-specific secrets.
3. Load or pull the image, then `docker compose -f docker-compose.store.yml up -d`.
4. **Verify**: `http://localhost:8000` loads the login page within a few minutes; `docker compose -f docker-compose.store.yml logs app` shows migrations applied, no errors.
5. **Verify**: log in with a seeded dev account, record one test sale.

## 2. Data survives restart (User Story 2)

1. With a sale recorded, run `docker compose -f docker-compose.store.yml down` (no `-v`), then `up -d` again.
2. **Verify**: the sale recorded in step 1 is still present in the Sales list.

## 3. Upgrade preserves data (User Story 2)

1. From a running deployment with data, follow the upgrade steps in `contracts/deployment-cli.md` to move to a newer image tag.
2. **Verify**: all previously recorded data (orders, stock, settings) is unchanged after the upgrade; any new migrations show as applied.

## 4. Backup/restore round-trip (User Story 3)

1. Run `docker compose -f docker-compose.store.yml exec app php artisan app:backup`.
2. **Verify**: a backup archive appears at the bind-mounted `BACKUP_EXTERNAL_PATH` on the host, in the same shape `RUNBOOK.md` §7 already documents.
3. Restore it into a fresh deployment (`down -v`, `up -d`, then `app:restore <path> --force`).
4. **Verify**: the restored deployment's data matches the original.

## 5. Offline setup with no internet at all

1. Repeat step 1 (First-time setup) on a machine with networking disabled after the `.tar` file has already been transferred via USB.
2. **Verify**: setup completes and day-to-day operation (recording a sale) works with zero outbound network calls, consistent with FR-006.
