**Classification**: INTERNAL

# Evidence — Stage 5 (stock per outlet) — DONE except browser check

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## What changed

- **Migration `2026_12_04_000001_create_outlet_stock`** (re-runnable, `down()` provided): `variant_outlet_stock`, `material_outlet_stock` (unique pair, CHECK ≥ 0), `stock_movements.outlet_id` / `material_stock_movements.outlet_id` (backfilled, NOT NULL, FK, index), enums extended (`transfer_out|in|cancel`, material `adjustment`). Variant balances go to the product's **home outlet**, material balances to the **first outlet**; the migration verifies "Σ outlet = old total" and no NULL outlet and **fails loudly** otherwise.
- **`StockService` / `MaterialStockService`**: `outletId` is a REQUIRED argument. One transaction: lock variant row, lock the (item, outlet) balance, refuse a negative outlet balance (409/422 message names the outlet), write the ledger row (`stock_before/after` = outlet balance), update balance **and** the cached total.
- **Call sites**: orders/void (order outlet), tab lines/returns (tab's outlet), pre-order arrival/handover/edit delta (pre-order outlet), purchase-order receipt (PO's outlet, legacy PO → first outlet), `StockController` adjustments (`outlet_id`, required with several outlets, 403 outside scope), Excel (`stock` sheet gained `outlet_code`; initial stock → product home outlet), demo seeder. A source-scan test fails if any call omits the outlet.
- **`OutletStockSync`**: direct writes to `current_stock` outside the services (factories, seeders, data fixes — they never had ledger rows) are booked at the default outlet so I2 holds; new variants always get a balance row.
- **APIs**: movements carry/filter by outlet and are scoped; `GET /stock/balances`; `GET /stock/low` = one row per (variant, outlet) ≤ threshold, same definition as the Dashboard (`LowStockHistory::currentQuery`, history per pair; KPI scope is now `outlet`); `POST /materials/{id}/adjustments`; `GET /materials` carries `outlet_stock`; POS variants/lookup carry `outlet_stock` (`pos=1`, `stock_outlet`).
- **Frontend**: Stock screen (outlet column/filter, adjustment asks the outlet and shows the balance at that outlet, low list names the outlet), variant history column, materials table stock column + adjustment dialog, POS/tab cards use the shift outlet's balance. All hidden at one outlet.
- **`outlets:reconcile`** gained I1 (balance = last ledger `stock_after` per pair) and I2 (total = Σ outlet balances) for variants and materials.

## Verification

| Check | Result |
|---|---|
| New backend tests | `StockPerOutletTest` 9, `StockLedgerInvariantTest` 6, `MaterialStockPerOutletTest` 3, `LowStockPerOutletTest` 3, `StockImportOutletTest` 3 |
| New frontend tests | `StockViewOutlets` 3, `MaterialStockAdjustModal` 2, `posProductCards` on-hand |
| Backend full suite | **1830 passed, 1 skipped** (after fixing two legacy tests that assumed a global stock number: Dashboard scope value, tab line needs stock at the shift outlet) |
| Frontend full suite + build | **189 files, 1758 passed, 2 skipped, exit 0; build passes** |
| Dev DB | backup `storage/app/backups/2026-10-12_090011` first; migrated; 27 balance rows, Σ = 1084 = old total, 0 NULL outlets; `outlets:reconcile` OK incl. I1/I2. Smoke as `owner`: balances/low/movements 200, adjustment +2/−2 (201), missing outlet with 3 outlets → 422. The smoke left two `smoke` adjustment rows in the ledger (stock reverted); the reconcile baseline was re-taken afterwards. |

## Deviations / open

- **Concurrency**: no real two-connection test (the suite runs inside a transaction). Covered by (a) a test that the movement issues `FOR UPDATE` on the variant row and then the balance row (fixed lock order) and (b) a sequential "last unit" test. A genuinely parallel run is not evidenced.
- **Legacy tests**: ~90 existing tests created shifts and products on different default outlets; they were kept green by giving direct stock writes the default outlet (same as shifts/tables default) and by topping up stock in the multi-outlet fixtures — production paths are unchanged.
- **Product detail modal** still shows the cached total per variant (per-outlet balances appear on the Stock screen, history and POS).
- Alerts exist only for (variant, outlet) pairs that have a balance row (home/default outlet and any outlet that ever moved stock).
- **T083 browser check** not done (Playwright browser profile still locked by an unrelated Chrome; not killed). Server behaviour is covered by tests and the dev API smoke.
- Pre-existing, not 045: `app:backup` dumps include the generated column `orders.open_table_key`, which breaks restore into MySQL 8.
- **T076 (automated backfill test)** left open: like T034, the migration cannot be re-run against legacy-shaped rows inside the NOT-NULL test schema. Backfill is evidenced on the dev DB (Σ outlet balances = old total, 0 NULL outlets, reconcile OK) and checked by the migration itself.
