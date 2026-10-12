**Classification**: INTERNAL

# Evidence — Stage 6 (transfers between outlets) — DONE except browser check and true parallel test

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## What changed

- **Migration `2026_12_05_000001_create_stock_transfers`** (+ `000002_add_transfer_permissions` for roles that already store an explicit permission list): `stock_transfers` (number `TRF-YYYYMMDD-####` unique across modes, from ≠ to CHECK, status in_transit|received|cancelled, discrepancy flag, unique `client_ref`) and `stock_transfer_lines` (variant|material, `qty_sent`, `qty_received`, reason; CHECK on the quantities). Applied to the dev DB after a backup (`2026-10-12_113804`).
- **`StockTransferService`** is the only writer: dispatch (source drops at once, `transfer_out`, goods in transit), receive (destination gains the received quantity, `transfer_in`; short → reason required + discrepancy; over-receipt 422; second receive 409), cancel (only in transit; goods return to the source, `transfer_cancel`). Everything goes through `StockService`/`MaterialStockService` in one transaction with the audit row (`stock_transfer_dispatched|received|cancelled`). Lines are processed in one global order (kind, item id) and each movement locks the item row then the balance row, so crossing transfers lock alike.
- **API** per `contracts/api-stock-transfers.md`: list/detail/create+dispatch (idempotent `client_ref`)/receive/cancel, `discrepancies`, `in-transit`. Permissions `stock.transfer.dispatch|receive|cancel` (owner/admin all; inventory dispatch+receive) **and** outlet entitlement: dispatch needs the source, receive the destination, cancel the source; entitlement includes deactivated outlets so goods already in transit can still be received/cancelled; list/detail show only transfers touching an entitled outlet (others → 404). Each transfer carries a server-computed `can {receive, cancel}`. Movements' `reference` resolves the transfer number.
- **UI** (`/stock/transfers`, linked from the Stock screen with several outlets): list with status/outlet filters and a Discrepancies tab, new-transfer drawer (items and materials, availability at the source outlet), detail (lines, steps, ledger trail, "not sold at the destination" warning), receive dialog (per-line quantity, reason when short), cancel confirm. Actions only with the permission and the server's `can`; at one outlet the page explains transfers need several outlets.

## Verification

| Check | Result |
|---|---|
| New backend tests | `StockTransferTest` 18 (dispatch, validation, idempotency, warning, full/short/over receive, deactivated destination, cancel, materials, permissions, assignment, scope, filters, rollback incl. audit, audit rows), `StockTransferInvariantTest` 3 (I3 over 40 random steps for items AND materials, lock order, last units) |
| New frontend tests | `TransfersView.test.js` (single-outlet notice, list + permission, detail actions follow server `can`, receive dialog reason/limits, new drawer searches at the source outlet) |
| Backend full suite | **1853 passed, 1 skipped** (one existing assertion updated: inventory default permissions now include the two transfer ones) |
| Frontend full suite + build | **190 files, 1765 passed, 2 skipped, exit 0; build passes** |
| Dev DB | migrated; API smoke as `owner`: dispatch 1 unit between two outlets → `in_transit`, cancel → ledger `transfer_out`, `transfer_cancel`; `outlets:reconcile` OK |

## Open / deviations

- **T088 true parallelism**: not evidenced (the suite runs inside a transaction). Covered by the lock-order test, a sequential "last units" test and the I3 sequence; a two-process run against the dev stack was not done.
- **T094 browser check** not done (browser profile still locked by an unrelated Chrome).
- Transfers have no nav entry of their own — they are reached from the Stock screen (several outlets only).
- Reconcile does not yet have a dedicated I3 check (I3 is asserted by the randomised test); `outlets:reconcile` I1/I2 already include `transfer_*` movements.
