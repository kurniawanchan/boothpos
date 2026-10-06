# Research: Seller Recap Shows POS Transactions Only

## R1 — Remove the pre-order half at the source, not in the screen

**Decision**: change `SettlementService` so the stored settlement (`total_sales`, `total_units`, `payable_amount`) is built from POS only, and delete the pre-order aggregation inside `salesBreakdownForEvent()` (rename it to `posSalesForEvent()`).

**Rationale**: the recap row's Payable/Paid/Outstanding/Status and "Record payment" are read from `artist_settlements`, and `recalculateForEvent()` rewrites that row from the aggregation on every read and on event close (`EventController`). Hiding two columns in the SPA would leave Sales POS-only but Payable still including pre-orders — exactly the contradiction the requester ruled out when choosing "whole recap is POS-only". Only two callers use the breakdown (`recalculateForEvent`, `artistSettlements`), so the change is local.

**Alternatives considered**: (a) hide columns in the UI only — rejected (contradictory Payable); (b) keep the pre-order aggregation behind a flag — rejected (dead code, constitution I); (c) keep the 033 fields in the API but unused — rejected (a contract that advertises numbers the screen no longer has).

## R2 — Remove `pos_units`, `preorder_units`, `pos_sales`, `preorder_sales` from the response

**Decision**: `GET /reports/artist-settlements` rows keep `total_sales`/`total_units` (now POS-only) and drop the four 033 fields; the export drops the same four columns.

**Rationale**: with the recap POS-only they are redundant (`pos_* == total_*`, `preorder_* == 0`). Consumers found by grep: `ReportsView.vue` (recap table + footer), `DashboardView.vue` (reads only `total_sales`), the export, and tests. No external consumer.

## R3 — Outstanding is clamped at 0

**Decision**: `outstanding = max(0, payable − paid)` in the response (and therefore in the export, which reuses it).

**Rationale**: after the change, a seller who was already paid against a pre-order-inclusive Payable can have `paid > payable`. Today the response would show a negative Outstanding (`number_format($payable - $paid)`); the spec (FR-005, Story 3 scenario 4) requires 0. Recorded `paid_amount` is never altered. `deriveStatus()` already yields `paid` when `paid >= payable`, so the status needs no change. The "Record payment" link already requires `outstanding > 0`.

## R4 — "Transaction detail" loses its pre-order half

**Decision**: `artistSettlementTransactions()` returns only the order transactions (existing code path, unchanged); the pre-order query, the merge and the `source` field are removed; `ArtistTransactionsModal` drops the Sale/Pre-order badge and its type column.

**Rationale**: FR-007 — the list must add up to the recap row's Sales. Keeping a `source` field that is always `order` would be a vestigial contract. (The modal tests that pin the badge are rewritten.)

## R5 — Dashboard "Results per seller" follows the recap

**Decision**: no Dashboard code change; its panel reads `total_sales` from the same endpoint, so it becomes POS-only. The spec's FR-011 was corrected to say so.

**Rationale**: the panel is the same concept as the recap (it is literally the recap's `total_sales` bar chart). Keeping it blended would need a second, pre-order-inclusive field kept only for it. The Dashboard's other panels use `GET /reports/sales` and are untouched. **ASSUMPTION** flagged to the requester.

## R6 — Seller Cost / Cost & Profit stay as they are

**Decision**: not touched. Their `total_sales` still includes the paid part of pre-orders (feature 033), so for an event with pre-orders Seller Cost's total no longer equals the recap's Sales.

**Rationale**: the request names the Seller Recap; widening it would change reports the requester did not mention. CLAUDE.md's 033 section stated the two were equal, so it gets a dated correction. Recorded as an open question for the requester in the spec Assumptions.

## R7 — Existing settlement rows and payments

**Decision**: no migration and no data rewrite. Stored `total_sales/units/payable` are overwritten by the next read (existing behaviour); `paid_amount`, `deduction` and `paid_at` are never touched. A seller whose only activity was pre-orders is reset to zero by the existing "reset every settlement row first" step.

**Rationale**: `recalculateForEvent()` already zeroes every settlement row of the event before re-aggregating (the stale-row fix), so a seller that no longer has any counted sale ends at 0 without new code.

## R8 — Consequence to flag: pre-order revenue no longer creates a payable

**Decision**: documented, not mitigated here.

**Rationale**: before this change, paying a seller for pre-order revenue was possible through "Record payment" (the paid fraction of the pre-order was inside Payable). After it, that settlement must happen outside the recap. The Pre-order report still lists the collected amounts per seller. This is the product owner's explicit choice ("whole recap is POS-only"), recorded in spec Assumptions and the CLAUDE.md note.
