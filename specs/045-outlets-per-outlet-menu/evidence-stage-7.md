**Classification**: INTERNAL

# Evidence — Stage 7 (reports by selling outlet) — DONE except browser check

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## What changed

- **One definition of "reporting outlet"** (`OutletFilter::reportOutletSql()` / `reportPreorderOutletSql()` + join/filter helpers): the outlet where the sale was made (`orders.outlet_id`); for legacy rows (`outlet_backfilled`) the item's former home outlet. So history does not move and post-change sales follow the selling outlet.
- **Used by**: `SalesFigures::posBase` (sales report groupings, Dashboard), the recap (`outlet-settlements`) and its transaction drill-down, cost & profit (totals and per-outlet rows, so Σ rows = total), the pre-order report by outlet, the recap export detail, and `SettlementService::posSalesForEvent` (the stored `outlet_settlements` snapshot, recalculated on read and on event close — screen and snapshot cannot differ). The Dashboard "Results per outlet" panel reads the recap, so it follows.
- **Rows carry `historical`** (any legacy order contributing); the recap export has a `historical` column (file = screen).
- **Scope**: the recap lists only entitled outlets (deactivated outlets with sales still show); stock-by-outlet now reads per-outlet balances (`variant_outlet_stock`) for permitted outlets only (403 for a foreign outlet).
- **UI**: "Historical/Riwayat" tag with a hint on recap and cost & profit rows; the existing outlet selector already narrows the reports through `X-Outlet-Id`.

## Verification

| Check | Result |
|---|---|
| New backend tests | `OutletReportsTest` 7: post-change sales grouped by selling outlet (home outlet gets 0), Σ rows = total and the header filter narrows, legacy rows keep the former seller + `historical` with no double counting, stored snapshot = screen and event close, profit + drill-down, scoped user sees only their rows, stock-by-outlet from balances |
| Characterisation suites | The 5 older seller-report suites (`ReportTest`, `SellerRecapPosOnlyTest`, `ReportCostPosPreorderSplitTest`, `PreorderSellerSubtotalsReportTest`, `OutletRenameCharacterizationTest`) now use `TreatsOrdersAsLegacy`: it marks existing orders/pre-orders `outlet_backfilled` before report/event requests (how the migration marks real history), so they keep asserting the former attribution unchanged. Their expectations were NOT loosened except: recap rows/export gain `historical`; stock report totals now follow per-outlet balances |
| New frontend tests | `ReportHistoricalTag` 3 |
| Backend full suite | **1860 passed, 1 skipped, exit 0** |
| Frontend full suite + build | **191 files; one run failed only the `noSellerWording` guard on my new hint text — reworded; re-run of the guard, locale and new tests passes; build passes** (full frontend suite not re-run after the rewording) |
| Dev DB (all 5 orders legacy) | recap per outlet = raw `GROUP BY order_items.home_outlet_id` (190 000 / 85 000 / 130 000), every row `historical: true`; `outlets:reconcile` compare OK (no figure moved) |

## Open

- **T099 browser check / T100** not done (browser profile still locked by an unrelated Chrome).
- Order-level counters (e.g. number of orders in dashboard figures) follow `orders.outlet_id` (legacy → first outlet), while item-level money follows the reporting outlet; sums are consistent per metric.
- Pre-order rows are not tagged `historical` (only POS-derived rows are).
- OpenAPI still names the report routes with the old "artist" words (pre-existing drift); a note above `/reports/sales` states the real routes and the new semantics.
