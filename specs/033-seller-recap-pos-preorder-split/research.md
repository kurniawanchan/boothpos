# Research: POS vs Pre-order Split in the Reports

## Evidence (current behaviour)

- **Seller Recap**: `ReportController::artistSettlements()` calls `SettlementService::recalculateForEvent($event)` then reads `artist_settlements` (`total_sales`, `total_units`, deduction, payable, paid, status). The service builds the totals from TWO separate aggregations — completed `order_items` (POS) and `preorder_items` of non-cancelled pre-orders weighted by `collected / preorders.subtotal` (the "recognised" fraction, payments excluding `rejected`) — and then stores only the sum. `total_units` is written as `(int) round($totalUnits)` (integer column) while `total_sales` keeps 2 decimals.
- **Cost & Profit**: `profit()` sums `order_items` revenue/cost (completed orders) with `preorder_items` revenue/cost × the same fraction (`PREORDER_FRACTION_EXPR` via `preorderRecognizedRevenueBase()`), then `gross_profit = revenue − cost`, `net_profit = gross − event_cost`. Response is a flat object, used by the page's cards and by the generic export (headings = response keys).
- **Seller Cost**: `artistProfit()` aggregates ONLY `order_items` (completed orders) per artist: `total_sales`, `modal` (cost), `gross_profit`. Pre-order items are not read at all, and only artists that have POS items appear.
- **Exports**: `artist-settlements` export has explicit summary headings (`id, artist_id, artist_name, total_sales, total_units, deduction, payable_amount, paid_amount, outstanding, status`, documented as "old shape unchanged") and a "Detail Transaksi" sheet from `order_items` only; `profit`/`artist-profit` use `GenericArrayExport` whose headings are the response keys.
- **Drill-down** (`artistSettlementTransactions`) already returns POS (`order-{id}`) and pre-order (`preorder-{id}`) rows for one seller (feature 012).
- **UI**: `ReportsView.vue` renders the Recap with `DataTable` (+ footer Grand Total via `sumRows`), Cost & Profit as five stat cards, Seller Cost as a `DataTable` with a footer.

## Decision 1 — Expose the parts the service already computes; no new storage

**Decision**: add `SettlementService::salesBreakdownForEvent(Event): Collection` (keyed by `artist_id`, values `pos_sales`, `pos_units` from `order_items`; `preorder_sales`, `preorder_units` from the fractioned `preorder_items`). `recalculateForEvent()` consumes it instead of its inline queries, so the stored totals are `pos + preorder` by construction. The reports read the breakdown (live, same moment as the recalculation they already trigger).

**Rationale**: One aggregation path (Constitution I). The report already recalculates on every call, so a live breakdown is as fresh as the totals; no migration and no backfill (the spec requires historical events to work).

## Decision 2 — Exact reconciliation by remainder (units integer, money in cents)

**Problem**: the stored unit total is a rounded integer of `POS units (integer) + pre-order units (fractional)`; presenting independently rounded parts could differ from the total by 1 (e.g. pre-order 0.5 + 0.5 → 1 + 1 ≠ round(1.0)=1).

**Decision**: show **POS** as computed (integer units; money to 2 dp) and **pre-order = shown total − POS**: `preorder_units = total_units − pos_units` (`pos_units` is an integer, so this equals `round(preorder fractional units)` and never drifts), `preorder_sales` in integer cents `round(total×100) − round(pos×100)`. Both parts always add up to the total on every row; the Grand Total is the sum of the rows (frontend `sumRows`), so it reconciles too. A tiny helper (`App\Support\ReportSplit::remainderCents()` / unit remainder) is shared by the three reports. Tests cover .5 units and sub-cent fractions.

**Rationale**: FR-002/SC-001 demand zero drift; remainder derivation guarantees it without changing any existing figure (the total is untouched).

## Decision 3 — Cost & Profit: flat extra keys, cent-exact remainders

`profit()` keeps every existing key and adds `revenue_pos`, `revenue_preorder`, `cost_of_goods_pos`, `cost_of_goods_preorder`, `gross_profit_pos`, `gross_profit_preorder` (flat, because the generic export takes headings from keys; nested objects would export as unreadable arrays). Pre-order parts are remainders of the existing totals in cents; `gross_profit_pos = revenue_pos − cost_pos`, `gross_profit_preorder = gross_profit − gross_profit_pos` (cents). `event_cost`/`net_profit` unchanged and not split.

## Decision 4 — Seller Cost: add the pre-order aggregation (requester's decision), keep totals' keys

`artistProfit()` currently omits pre-orders. It gains one `GROUP BY artist` aggregation over `preorderRecognizedRevenueBase()` (revenue = `line_total × fraction`, cost = `cost_price × qty × fraction`) and merges it with the POS rows by `artist_id` (so pre-order-only sellers appear; ordered by name). Response rows keep `total_sales`, `modal`, `gross_profit` as the **totals** (now POS + pre-order) and add `sales_pos`, `sales_preorder`, `modal_pos`, `modal_preorder`, `gross_profit_pos`, `gross_profit_preorder` (cent-exact remainders). For events with no pre-orders every old value is identical (POS parts = old figures, pre-order parts 0). Added test: a seller's Seller Cost `total_sales` equals their Seller Recap `total_sales`.

**Rationale**: Consistency across the three reports was the requester's goal; keeping the old keys as totals means clients reading them stay valid.

## Decision 5 — Presentation

- **Seller Recap**: explicit columns as requested — `POS unit`, `Pre-order unit`, `Unit` (total), `POS sales`, `Pre-order sales`, `Sales` (total) — plus a Grand Total row for all of them. Compact header labels and `whitespace-nowrap` numeric cells; the table already scrolls horizontally on narrow screens.
- **Cost & Profit**: the Revenue / Cost of goods / Gross profit cards each get a muted sub-line `POS Rp … · Pre-order Rp …`; event cost and net profit cards unchanged.
- **Seller Cost**: the Sales / Cost / Gross profit cells show the total with the same sub-line (three metrics × three columns would make the table unreadably wide); Grand Total row likewise.
- Labels in both locales (`POS`, `Pre-order`, `POS unit`, …).

## Decision 6 — Exports

- `artist-settlements` summary sheet: keep every existing heading in place (documented "old shape unchanged") and **append** `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` (same names as the API fields, so the sheet needs no aliasing).
- `profit` and `artist-profit` (generic): new keys flow into the export automatically; they are appended after the existing keys.
- The "Detail Transaksi" sheet of the settlement export still lists only POS items (existing behaviour, untouched by this feature; noted as a known gap, not part of the requested split).

## Implementation finding — drill-down rounding (T018)

No code change was needed for the drill-down. Its `amount_for_artist` is rounded **per transaction row**, while the Recap column rounds the seller's full fractional sum once. For clean amounts the by-kind sums equal the columns exactly (tested). For pre-orders that recognise sub-cent fractions (e.g. three pre-orders of 3333.333…) the detail rows can differ from the column by at most 1 cent per row — display rounding of the same data, pinned by `test_drilldown_rounds_each_row_so_sub_cent_preorders_may_differ_from_the_column_by_at_most_one_cent_per_row`. The export headings reuse the API field names (`pos_units`, …) so no aliasing is needed.

## Implementation finding — zero units in the Excel export

`SheetArrayExport` writes through `fromArray()` with a NON-strict null comparison, so an integer `0` is written as an EMPTY cell (already true for the existing `total_units`). The two new unit columns are therefore cast to strings in `exportArtistSettlements()` so zero is written as a real `0`; the API payload is unchanged and `total_units` is deliberately left as it was (old shape preserved). Pinned by `test_recap_export_writes_zero_units_as_zero_not_as_a_blank_cell`.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| New columns on `artist_settlements` (`pos_sales`, … stored) | Migration + backfill for old events, and a second place the same numbers live; the breakdown is cheap to compute live because the report already recalculates. |
| Round each part independently | Parts can differ from the displayed total by 1 unit / 1 cent (see Decision 2) — violates FR-002. |
| Keep Seller Cost POS-only and just label it | Rejected by the requester (they chose to add pre-order columns and a combined total). |
| Nested `{pos, preorder}` objects in `profit()` | The generic export would flatten them into unreadable cells; flat keys are export-friendly and match the existing flat response. |
| Separate "POS" and "Pre-order" tabs/rows | Duplicates tables and breaks the per-seller totals; the request was for columns. |
| Also add pre-order lines to the settlement export's detail sheet | Not requested; would change an existing sheet's row set. Left as a noted gap. |

## Risks / notes

- Seller Cost totals change (intended) for events that have pre-orders; POS parts equal the old figures, which is stated in the spec, the OpenAPI and `CLAUDE.md`.
- Pre-order units are fractional in meaning but integer in presentation (they are the remainder of an already-rounded total); documented so nobody "fixes" the display to decimals.
- Hand-rolled `DB::table` queries bypass the Eloquent DEMO/LIVE scope — every new query filters `data_mode = ModeGate::current()` explicitly (existing project rule).
