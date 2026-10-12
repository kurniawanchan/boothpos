**Classification**: INTERNAL

# Quickstart — verifying 046

Run after each stage; stages 1–2 can be verified before any module is deleted.

## A. Trade with no events (US1, FR-003/004/013)
1. Fresh install or a copy with `events` emptied: sign in as `kasir01`, open a shift (outlet + opening cash only).
2. Ring up and pay a sale, open the receipt: no event name/location/dates. Open a tab on a table, pay, close.
3. Close the shift; the summary shows cash and sales, no event.

## B. Reports by date (US2, FR-005/010)
1. Reports → every tab has a date range (default today), no event selector; Recap and Cost & Profit show sales-only figures; Profit has no event cost.
2. Dashboard has no "per event" block; Sales page has no event filter/column; exports have no event column or file name.
3. With the outlet selector on one outlet, figures equal that outlet's rows.

## C. Menus gone (US3/US4, FR-001/002/007/008)
1. As every seeded role: no Events or Pre-orders item, no badge, no shortcut; the role editor lists neither.
2. Open `/events`, `/preorders`, `/preorders?preorder_id=1`, `/events?event_id=1`: you land on the default screen.
3. API: `GET /api/v1/events` and `GET /api/v1/preorders` → 404.

## D. Customer and product views (FR-009/012)
Customer history shows sales only; product form/detail/lists have no pre-order flag or ETA; the master-data template has no such columns and an old file that still has them imports.

## E. Upgrade proof (US5, FR-014/015, SC-003/004)
1. `app:backup`; `outlets:reconcile --before`; record per-business-date totals of orders, order payments and shift cash plus row counts of `events`, `preorders`, `payments`, `stock_movements`.
2. Migrate (nullable event_id, role keys), `outlets:reconcile --after/--compare` → green; totals and counts identical.
3. A role whose saved menu list still had `events`/`preorders` signs in normally and cannot reach them.

## F. Tests
`php artisan test` (container with `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test`), `npx vitest run`, `npm run build`; browser walk of A–D when the browser profile is available.
