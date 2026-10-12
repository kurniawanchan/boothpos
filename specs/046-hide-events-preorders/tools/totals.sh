#!/bin/sh
# Cuplikan total untuk bukti SC-003: jalankan sebelum/sesudah tiap fase dan bandingkan.
# Pemakaian: tools/totals.sh [nama_database]   (bawaan: warkop_wjm)
DB="${1:-warkop_wjm}"
docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N $DB" <<'SQL' 2>/dev/null
SELECT 'events', COUNT(*) FROM events;
SELECT 'preorders', COUNT(*) FROM preorders;
SELECT 'preorder_items', COUNT(*) FROM preorder_items;
SELECT 'preorder_notifications', COUNT(*) FROM preorder_notifications;
SELECT 'shipments', COUNT(*) FROM shipments;
SELECT 'payments', COUNT(*), COALESCE(SUM(amount),0) FROM payments;
SELECT 'payments_preorder', COUNT(*), COALESCE(SUM(amount),0) FROM payments WHERE preorder_id IS NOT NULL;
SELECT 'outlet_settlements', COUNT(*) FROM outlet_settlements;
SELECT 'stock_movements', COUNT(*), COALESCE(SUM(qty_change),0) FROM stock_movements;
SELECT 'orders_by_date', business_date, status, COUNT(*), COALESCE(SUM(total_amount),0) FROM orders GROUP BY business_date, status ORDER BY business_date, status;
SELECT 'payments_by_date', business_date, method, COALESCE(SUM(amount),0) FROM payments WHERE order_id IS NOT NULL GROUP BY business_date, method ORDER BY business_date, method;
SELECT 'sessions', COUNT(*), COALESCE(SUM(opening_cash),0), COALESCE(SUM(closing_cash),0) FROM cashier_sessions;
SQL
