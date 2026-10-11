**Classification**: INTERNAL

# Research & Decisions — 044 Sales Tables View

All spec assumptions were resolvable from the repository; no open clarification remains.

## D1 — Data source: reuse `GET /floor` and the floor store
**Decision**: the board reads the same snapshot as the Floor screen (`areas[]` with tables and their open order, `takeaway_tabs[]`, `version`), through the existing `useFloorStore` + `usePolling`.
**Rationale**: it is already permissioned (`floor` menu), already cheap when nothing changed (version token), already constant-query, and already ordered the way the spec requires (FR-007). A second endpoint would duplicate all of that (Constitution I).
**Alternatives**: a dedicated `/sales/tables` endpoint (duplicates grouping/ordering/permission; two sources that can drift — rejected); computing from `GET /tabs` (no table grouping, N lookups — rejected).

## D2 — The four figures are computed once, client-side, from the snapshot
**Decision**: a pure module `utils/floorBoard.js` takes the snapshot and "now" and returns the four figures and every tile's state; the board and tiles use only its output.
**Rationale**: SC-004 (figures never drift from tiles) is guaranteed by construction. "Over 45 minutes" depends on the clock, and the server snapshot deliberately excludes clock-dependent fields so its version token stays stable (see `FloorQuery` docblock) — so this figure cannot come from the server anyway. Money is summed in integer cents from the server's money strings (no float drift).
**Alternatives**: server-side summary object (adds a second implementation for the clock-free figures and still needs client code for the clock-based one — rejected).

## D3 — Partially paid: add `paid_amount` to each open order in the floor snapshot
**Decision**: select the paid amount in the existing open-orders query using the existing SQL helper `PaymentSummary::orderPaidSql()`; the client flags a tile *partial* when `0 < paid < total`.
**Rationale**: the derived-payment rule already has a single implementation (verified by a parity test); `orders.paid_amount` is only a cache that keeps tendered-cash semantics. It is one extra column, not a query per order. The Floor screen ignores the new field. The snapshot hash changes when a payment is recorded, so the board updates.
**Alternatives**: read the cache column (wrong semantics for change — rejected); fetch payments per tab (N+1 — rejected).

## D4 — Inactive tables that still hold an open order must stay on the board
**Decision**: `FloorQuery` lists active tables **plus** any inactive table that has an open order, with `is_active: false`; the tile shows a small "Nonaktif" mark.
**Rationale**: spec edge case — open money must never vanish from the board. Today such an order disappears from `GET /floor` (and therefore from the Floor screen too, which is itself a latent gap). An inactive table without an open order stays hidden, as the existing test requires.
**Alternatives**: a query flag only for the board (two behaviours for one read model — rejected); ignore the edge (violates FR-006 equality when an order is "open" but invisible — rejected).

## D5 — Detail = existing `GET /tabs/{id}` (read-only use) + `opened_by`
**Decision**: the panel loads the tab through the existing endpoint (lines with modifiers, notes, kitchen `prep_status`, discounts, totals, payments, balance) and shows it read-only. `TabResource` gains `opened_by` (the cashier's name) — one eager-loaded relation in `TabService::loaded()`.
**Rationale**: everything FR-015 lists is already in that payload except who opened it; the payload is already cost-free (FR-020) and gated by `floor` (FR-019). No new endpoint, no new permission.
**Alternatives**: a new back-office order-detail endpoint (duplicate shaping — rejected); reusing the finished-order popup (`TransactionItemsModal`, built for completed sales, no kitchen status — rejected for open orders, but reused for the "now paid" link).

## D6 — Keeping the detail fresh and handling the end of the order
**Decision**: while the panel is open it polls the same endpoint (≈5 s, paused when hidden, via `usePolling`). Responses map to states: open → live; `status: completed` → "no longer open — paid" with a button that opens the existing completed-sale popup; `404` (voided or merged away) → "no longer open — cancelled or merged"; network failure → keep last data with an "outdated" mark.
**Rationale**: FR-017. The tab endpoint already answers 404 for voided orders and returns the finished status for completed ones, so no backend change is needed.

## D7 — Toggle, URL state and who sees it
**Decision**: reuse the page-kit `ViewToggle` and `useQueryState` with `?view=tables` (default list; unknown value → list). Rendered only when the user can access the `floor` menu (hidden, not disabled); if the URL asks for `tables` without that access it falls back to the list.
**Rationale**: FR-003/FR-005; matches how the Products list persists its view.

## D8 — Preserve the order list exactly (SC-006)
**Decision**: the list's script state (filters, sorting, selection, loaded data) stays in `SalesView`'s setup, so unmounting its template while on Tables loses nothing; the list-only header actions (event filter, export, bulk verify) are hidden in Tables view; the Tables view mounts only when chosen (its polling starts then and stops on leave).
**Rationale**: FR-004 and zero regression risk; no refactor of a 500-line view.
**Alternatives**: split into two routes (`/sales`, `/sales/tables`) — acceptable but loses the "same page, one toggle" feel and duplicates the header; rejected for now.

## D9 — Tile design and states
**Decision**: a new `FloorBoardTile` (not the cashier's `TableCard`, which is built around opening/handling tabs): name + state badge on top; order number, elapsed time and amount for occupied; "Tersedia/Available" for empty. States and tones: empty (white), occupied (light blue), partial (yellow), over-45 (yellow outline), inactive-with-order (muted label). Image 27's *pre-bill* state is not rendered (no data — FR-011). Only occupied/takeaway tiles are focusable buttons.
**Rationale**: spec FR-008/FR-009/FR-014; avoids changing the Floor screen's tile.

## D10 — Where the list-view toggle sits and the page title
**Decision**: the toggle sits at the right of the Sales `PageHeader` actions; the page title and subtitle are unchanged ("Sales"); in Tables view the header's other actions are hidden.
**Rationale**: matches image 26/27 placement without renaming an existing page.

## D11 — Elapsed time
**Decision**: `useNow` (15 s tick) minus the order's `opened_at`; format "N mnt/min" under 60, "Hh Mm" above; the 45-minute rule is a constant in `floorBoard.js`.
**Rationale**: real elapsed time, not the business date (spec edge case). Threshold is not configurable in this feature (spec assumption).

## D12 — Out of scope, confirmed
No guest counts, pre-bill state, outlet grouping/selector, configurable threshold, push updates, or any action that changes an order.
