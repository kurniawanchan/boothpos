#!/bin/sh
# Gladi bersih pemutakhiran 046 pada SALINAN database (tidak pernah menyentuh warkop_wjm / warkop_wjm_test).
# Pemakaian: sh specs/046-hide-events-preorders/rehearse.sh
#
# Catatan: dump `app:backup` memuat kolom turunan orders.open_table_key sehingga TIDAK bisa dimuat ke MySQL 8
# (kekurangan 045 yang sudah tercatat); gladi ini memakai mysqldump --complete-insert (melewati kolom turunan).
# Salinan diambil dari database dev, dimundurkan ke skema PRA-046 (rollback dua migrasi), diberi kunci menu lama pada
# satu peran, lalu dimigrasikan maju: total harus identik dan rekonsiliasi bersih.
set -e
COPY=warkop_wjm_rehearsal
DC="docker compose"
DIR="$(dirname "$0")"
ROOT='mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'

$DC exec -T mysql sh -c "$ROOT -e 'DROP DATABASE IF EXISTS $COPY; CREATE DATABASE $COPY CHARACTER SET utf8mb4;'" 2>/dev/null
$DC exec -T mysql sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --complete-insert --no-tablespaces warkop_wjm 2>/dev/null | $ROOT $COPY 2>/dev/null"
$DC exec -T -e DB_DATABASE=$COPY app php artisan migrate:rollback --step=2 --force > /dev/null
# Peran yang masih menyimpan kunci menu lama (sebelum migrasi data mencabutnya).
$DC exec -T mysql sh -c "$ROOT $COPY -e \"UPDATE roles SET menu_keys = JSON_ARRAY_APPEND(JSON_ARRAY_APPEND(menu_keys, '\\\$', 'events'), '\\\$', 'preorders') WHERE name = 'Owner'\"" 2>/dev/null

"$DIR/tools/totals.sh" $COPY > /tmp/rehearsal-before.txt
$DC exec -T -e DB_DATABASE=$COPY app php artisan outlets:reconcile --before > /dev/null
$DC exec -T -e DB_DATABASE=$COPY app php artisan migrate --force
"$DIR/tools/totals.sh" $COPY > /tmp/rehearsal-after.txt
$DC exec -T -e DB_DATABASE=$COPY app php artisan outlets:reconcile --after > /dev/null

echo "--- skema: event_id nullable? ---"
$DC exec -T mysql sh -c "$ROOT $COPY -N -e \"SELECT TABLE_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$COPY' AND COLUMN_NAME='event_id' AND TABLE_NAME IN ('cashier_sessions','orders','outlet_settlements')\"" 2>/dev/null
echo "--- kunci menu peran Owner (tanpa events/preorders) ---"
$DC exec -T mysql sh -c "$ROOT $COPY -N -e \"SELECT menu_keys FROM roles WHERE name='Owner'\"" 2>/dev/null
echo "--- diff total (kosong = identik) ---"
diff /tmp/rehearsal-before.txt /tmp/rehearsal-after.txt && echo "TOTAL IDENTIK"
$DC exec -T -e DB_DATABASE=$COPY app php artisan outlets:reconcile --compare
$DC exec -T mysql sh -c "$ROOT -e 'DROP DATABASE $COPY;'" 2>/dev/null
