#!/bin/sh
# Cron wrapper for `php artisan app:backup` on the Oracle Cloud VM.
#
# routes/console.php does NOT schedule app:backup automatically
# (documented as a known gap in specs/016-docker-store-deployment/plan.md
# and docs/RUNBOOK.md §7) — this script is the manual substitute until
# that's added upstream.
#
# Install once:
#   crontab -e
#   # daily at 02:00 server time:
#   0 2 * * * /home/ubuntu/boothpos/deploy/oracle-cloud/backup-cron.sh >> /home/ubuntu/boothpos-backup.log 2>&1
#
# Adjust APP_DIR below if the repo lives somewhere other than
# ~/boothpos on the VM.

set -eu

APP_DIR="$HOME/boothpos"
COMPOSE_FILE="docker-compose.store.yml"
RETENTION_DAYS=30

cd "$APP_DIR"

echo "[$(date -Iseconds)] Starting app:backup"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan app:backup

# storage/app/backups/<timestamp>/ accumulates forever otherwise on a
# small VM disk — prune anything older than RETENTION_DAYS. This only
# touches the LOCAL copy; BACKUP_EXTERNAL_PATH's copy (the actual
# off-machine backup) is left untouched, matching the same "local copy
# is disposable, external copy is the real backup" assumption
# app:backup itself is built on.
find storage/app/backups -mindepth 1 -maxdepth 1 -type d -mtime "+${RETENTION_DAYS}" -exec rm -rf {} \;

echo "[$(date -Iseconds)] app:backup done"
