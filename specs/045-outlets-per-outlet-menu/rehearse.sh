#!/usr/bin/env bash
# 045 — rehearsal of the outlet migration chain on a COPY of the dev database.
# Never touches the dev (warkop_wjm) or test (warkop_wjm_test) databases: the target is the scratch database
# warkop_wjm_rehearsal, recreated on every run. Credentials stay inside the mysql container (no secret in this file).
#
# Usage:  specs/045-outlets-per-outlet-menu/rehearse.sh            # copy + migrate + reconcile
#         KEEP=1 specs/045-outlets-per-outlet-menu/rehearse.sh      # also leave the scratch database in place
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
SRC=warkop_wjm
DST=warkop_wjm_rehearsal

# Safety: the destination must be the scratch database, whatever the environment says.
case "$DST" in *rehearsal*) ;; *) echo "refusing: destination must contain 'rehearsal'" >&2; exit 2 ;; esac

echo "== 1/5 recreate $DST from a consistent dump of $SRC"
docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS $DST; CREATE DATABASE $DST CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;'" 2>/dev/null
docker compose exec -T mysql sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers $SRC 2>/dev/null | mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $DST 2>/dev/null"

run() { docker compose exec -T -e APP_ENV=local -e DB_DATABASE="$DST" -e CACHE_STORE=array app php artisan "$@"; }

echo "== 2/5 snapshot BEFORE"
run outlets:reconcile --before

echo "== 3/5 migrate (timed)"
START=$(date +%s)
run migrate --force
echo "migration time: $(( $(date +%s) - START )) s"

echo "== 4/5 snapshot AFTER"
run outlets:reconcile --after

echo "== 5/5 compare (exit code != 0 on ANY difference)"
run outlets:reconcile --compare

if [ "${KEEP:-0}" != "1" ]; then
  docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS $DST;'" 2>/dev/null
  echo "scratch database dropped"
fi
echo "REHEARSAL OK"
