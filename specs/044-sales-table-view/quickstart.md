**Classification**: INTERNAL

# Quickstart — verifying 044

Dev stack: `docker compose up -d`; app at http://localhost:8010; accounts `owner` / `kasir01` (seeded, local only).

## Backend (real MySQL, explicit test DB — never run tests without the overrides)

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app \
  php artisan test --filter='FloorViewTest|TabDetail'
```

Expect: `paid_amount` present and correct (none / partial / paid-with-change), inactive table with an open order is listed, inactive without one is not, `opened_by` present, no cost fields, `403` without the floor menu, query count constant.

## Frontend

```bash
npx vitest run qa-tests/unit/floorBoard.test.js qa-tests/component/FloorBoard*.test.js \
  qa-tests/component/OpenOrderDetail.test.js qa-tests/component/SalesViewToggle.test.js qa-tests/component/SalesView*.test.js \
  qa-tests/unit/localeParity.test.js qa-tests/unit/localeKeys.test.js
npm run build   # main chunk must not grow; the board ships with the Sales route chunk
```

## Browser walk-through (record in `evidence.md`)

1. As `owner`: open `/sales`. Toggle shows *Order list* (active) and *Tables*; the list, filters, KPI cards are exactly as before.
2. Choose *Tables*: URL gains `?view=tables`; four figures + area groups + (if any) takeaway group. Reload keeps the view.
3. In another window as `kasir01` (open shift): open a tab on a free table, add items, send, pay part of it. Within ~5 s the tile turns occupied, then *Partial*; figures update; no manual reload.
4. Wait/shift the clock (or seed an order opened > 45 min ago): tile outlined, figure increments.
5. Click the occupied tile: detail lists lines, modifiers, notes, kitchen status, payments, totals, opened-by. No edit/pay/void/move control anywhere. Escape closes; focus returns to the tile.
6. Click an empty tile: nothing opens. Deactivate a table that holds an order: its tile stays, marked inactive.
7. Pay/void the order while the detail is open: the panel says so (paid → "View transaction" opens the completed-sale popup).
8. As a role without the floor menu: no toggle; `GET /api/v1/floor` and `GET /api/v1/tabs/{id}` answer 403.
9. Switch language: toggle reads *Daftar order / Meja* (id) and *Order list / Tables* (en). Console: 0 errors.
