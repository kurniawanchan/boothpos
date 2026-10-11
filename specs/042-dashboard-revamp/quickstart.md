**Classification**: INTERNAL

# Quickstart: verifying the Dashboard revamp

Commands run against `warkop_wjm` (dev) and `warkop_wjm_test` (tests). Never run the suite in the container without the `-e` overrides (CLAUDE.md).

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test --filter=Dashboard
npm test
npm run build            # then compare the main chunk with 374 kB (+10 kB budget)
```

## Stage A

1. **Baseline before any change**: run the full backend and frontend suites (expected 1402 / 1204 + 2 skipped from 041); record chunk sizes. Write the characterisation tests for `ReportController::sales()` `pos_*` totals (with and without pre-orders, voided, item discounts, two days) — they must pass **before** `SalesFigures` is extracted.
2. **Migrations**: run the dry-run command to list orders whose business date would differ from the UTC date; apply migrations; confirm the totals of a fixed date range on the Sales page equal the previous values except for the listed rows. Re-run the migration after forcing a failure halfway (re-runnable).
3. **Seed a day**: demo seeder + a script that creates completed orders at 08:00, 12:00, 19:00 WIB yesterday and today, one voided, one open tab, one partial POS sale, one pending QRIS payment, one item voided after kitchen send, one shift closed with a Rp 23.500 difference, one variant below reorder point.
4. **API checks** (owner token): `GET /dashboard/summary?period=today|7d|30d`, `/dashboard/attention`, `/dashboard/orders?tab=…`, both exports. Compare: net sales = Sales page cards for the same date; order count = table "Semua" count; each attention count = the destination list's row count.
5. **Roles**: `owner`, `admin`, `kasir01`, `inventory` — each sees only the regions and links its menu keys allow; a role without the `dashboard` menu gets `403` on all five routes.
6. **Browser** (Constitution II): open `/dashboard` in both languages, with data and on an empty install; switch periods; use every filter, reload and check the URL restores the view; click each attention item and each row; export both files and open them; leave the page open 60 s and watch it refresh without losing filters; check the console is clean; confirm the "Per event" section still shows what the old Dashboard showed.
7. **Performance**: with 1,000 orders in the day, time the three requests and the first paint (≤ 3 s), and a period switch (≤ 2 s). If the stock-ledger replay is the slow part, switch to the documented snapshot fallback.

## Stage B (after T001)

1. Apply the outlet migrations on populated data; confirm every figure and every list is unchanged for a single-outlet install and **no new control is visible**.
2. Create a second outlet and users limited to each; create orders on both; run the **isolation matrix** (outlets × roles × endpoints: Dashboard, Sales page, orders, shifts, tables, kitchen, reports): zero rows or counts from the other outlet; a forged `X-Outlet-Id`, `outlet_id` in a body, or a request for another outlet's order id is refused.
3. Register a device, redeem its code once (second use fails, expired fails), send heartbeats, stop them: the notice and item appear within 2 minutes of crossing the threshold and clear within 1 minute of the next heartbeat; set `offline_cash` above the outlet limit and see it turn critical; change the advisory figures and verify no sales, payment, stock or cash total changes.
4. Token separation: a user token cannot heartbeat; a device token cannot read orders.
5. Repeat the browser pass with two outlets, selector visible, "Semua outlet" and each outlet.

## Evidence

Record results in `evidence.md` (commands, counts, screenshots under `evidence/`) the way 041 did; do not claim a stage done without it.
