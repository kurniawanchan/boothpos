**Classification**: INTERNAL

# Quickstart — verifying 045 (per stage)

Dev stack: `docker compose up -d`; app http://localhost:8010; seeded accounts `owner`, `admin`, `kasir01`, `kasir02`, `inventory` (local only). **Back up the dev DB before applying a stage's migrations** (`docker compose exec app php artisan app:backup`). Backend tests ONLY with the overrides:
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test --filter=<Name>`.

## Stage 0 — governance
Owner reviews the amendment wording; `.specify/memory/constitution.md` shows 2.0.0 with the Sync Impact Report; CLAUDE.md and PRD carry dated notes.

## Stage 1 — rename
1. `php artisan outlets:reconcile --before` (snapshot) → migrate → `--after`: zero differences.
2. `/outlets` lists the three sellers as outlets (HOS/NEK/YUK) with their product counts; `/artists` redirects; `GET /api/v1/artists` → 404; `GET /api/v1/outlets` works; delete with products → 409; Pro licence second outlet → refused.
3. Switch language: no "Seller/Penjual/Artist" on live screens; export an Excel workbook, re-import it, and import an **old-format** workbook (sheet `artists`) — both succeed.
4. Roles that had the Sellers menu still reach Outlets.

## Stage 2 — foundation
Seed 3 outlets; assign `kasir01` → WJ1, `kasir02` → WJ2, `inventory` → WJ1+WJ2; owner/admin all.
- Selector: owner sees "Semua outlet · 3"; kasir01 sees none; inventory sees two.
- kasir01 opens a shift (outlet WJ1); orders/tabs/tables/floor show WJ1 only; `GET /floor` with `X-Outlet-Id: 2` as kasir01 → 403; a direct `GET /orders/{id}` of a WJ2 order → 404.
- Owner on the Sales Tables board with "all outlets": one group per outlet, figures equal the tiles.
- Legacy orders (pre-migration) appear under the first outlet and are flagged.

## Stage 3/4 — menu per outlet
- Products list shows chips per outlet; filter by outlet; "86 today" follows the selection.
- Add an item carried at WJ1+WJ2 only, price 27 000 at WJ2 (base 25 000); reopen: prefilled.
- 86 the item at WJ1 only: kasir01's POS greys it; kasir02 still orders it at 27 000; order at WJ1 → `409 item_unavailable_at_outlet`.
- Bulk price change scoped to WJ2: preview per outlet; history names WJ2.

## Stage 5 — stock
- After migration each item's per-outlet balances sum to the old total (reconcile); each outlet's balance equals its ledger.
- Sell at WJ1: only WJ1's balance moves; the last unit at WJ1 is refused there but sellable at WJ2.
- Adjustment/stock-take per outlet; a PO received adds to the PO's outlet; pre-order arrival adds to its outlet; low-stock lists name the outlet.

## Stage 6 — transfers
1. `inventory`: New transfer WJ1 → WJ2, 10 of an item + 2 of a material → source −10/−2, "in transit" visible, neither outlet can sell them.
2. As a WJ2 user: Receive 8 of 10 with a reason → WJ2 +8, transfer "received · discrepancy", discrepancy list shows 2 short.
3. New transfer then Cancel → goods back at the source.
4. Two browsers dispatch the last units together → exactly one succeeds.
5. Invariant script/test: Σ balances + in-transit + shortfalls constant.

## Stage 7 — reports
Make sales at WJ1 and WJ2 after the change: Outlet recap shows each outlet; the all-outlets total equals the sum; legacy rows are tagged historical and their figures equal the pre-change report exported before migration.

## Stage 8 — hardening
Isolation matrix (outlets × roles × endpoints) green; reconciliation green on a populated dump; performance budgets (SC-010); full browser pass with 3 outlets × 4 roles in both languages; console clean; evidence per stage in `specs/045-outlets-per-outlet-menu/evidence-stage-N.md`.
