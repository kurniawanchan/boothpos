**Classification**: INTERNAL

# Contract: UI (all stages)

Rule for every outlet-specific control: rendered only when `outlet.multi` (more than one visible outlet) **and**, where relevant, the user has the permission — hidden, never disabled. Single-outlet installs look and behave as today. All copy id + en; tokens only.

## Top bar (Stage 2)
Outlet selector: "Semua outlet · N" or one outlet; search inside the list when > 8; persists per user; change refetches scoped pages. Absent with one visible outlet. Also shows the user's role (existing).

## Outlets page (Stage 1) — `/outlets`
Title "Outlets", subtitle "Lokasi yang menjual menu"; actions Export .xlsx · Bulk import · **Add outlet**; search "Search outlet name…"; table CODE · NAME · CONTACT · PRODUCTS · STATUS · Edit / Delete; paging; delete conflict and licence messages in outlet wording. `/artists` redirects.

## Users (Stage 2)
Outlet multi-select on the user form and a column on the list; administrators show "All outlets".

## Products (Stage 3)
- List/grid/table: availability chip per visible outlet (code + state; collapse to "N available · M sold out" above 4); outlet filter follows the selector; figures for the selected outlet(s).
- Add/edit form (the shared sections): *Home outlet* (required) · *Active at outlets* checkboxes (all checked by default) · variants table with one price column per visible outlet (grey = follows base; columns scroll horizontally above 5 outlets).
- Item page: availability table with one row per outlet (state, who, until); Mark-86 dialog with outlet checkboxes (default selected outlet); variants drawer with outlet price columns; price history names the outlet.
- Bulk price drawer: scope = Base / All outlets / chosen outlets; preview grouped by outlet.

## Cashier screens (Stage 4)
Shift open dialog asks the outlet when the cashier has several; POS/tab cards show only items carried at the shift's outlet, greyed with "86" when sold out there; receipt and order detail show the outlet name.

## Floor and Sales board (Stage 2/7)
Selected outlet's tables only; "all outlets" shows one group per outlet headed "Outlet — N dari M meja terisi"; the four figures follow the selection.

## Stock (Stage 5)
Outlet selector/column; per-outlet on-hand with total in "all outlets" mode; adjustment and stock-take ask the outlet; low-stock list names the outlet.

## Transfers (Stage 6) — `/stock/transfers`
List (number, from → to, status pill, lines, who/when; filters outlet/status/date) · detail (lines with sent/received, steps, ledger rows, discrepancy banner) · **New transfer** drawer (from outlet, to outlet, line table with search and available quantity, warnings for items not carried at the destination) · Receive dialog (per line quantity, reason required when short) · Cancel (confirm) · discrepancies tab. Actions shown only with the matching permission and outlet assignment.

## Reports and dashboard (Stage 7)
Report headers say "by outlet"; rows from legacy history carry a "historical" tag; outlet filter follows the selector; Dashboard attention rows name the outlet when several are visible.
