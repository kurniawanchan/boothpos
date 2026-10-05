# Research: Subtotal per Seller in the Pre-order Report

## D1 — Where the sums are computed

- Today: `preordersByArtist()` returns `{rows}` (one row per seller × status × payment completeness); the screen's Grand Total is computed client-side by `sumRows()`; the Excel "Per Seller" sheet is built from the SAME rows (`exportPreorderReport()` calls `preorders()` with `breakdown=artist`).
- A client-only subtotal would leave the export needing a second (PHP) implementation of the same sums → two formulas that could drift (Constitution I).
- **Decision**: ONE PHP implementation, `PreorderSellerSubtotals::fromRows()`, used by both the JSON response (`subtotals`) and the export. The screen does no arithmetic for subtotals; it only inserts the server's rows.
- Alternatives: client-side only + separate PHP for the export (rejected: duplication); the server inserting subtotal rows into `rows` (rejected: the client filters rows by seller and the Detail drill-down keys on data rows — mixing synthetic rows into `rows` would break both).

## D2 — Exactness and the clamped outstanding

- Row figures are 2-decimal strings (`number_format(..., 2, '.', '')`). The sums are done in integer cents with `ReportSplit::cents()` (round-half-up, float-trap safe) and formatted back with `ReportSplit::money()` — the helper 033 introduced for the same "no drift" requirement.
- `total_outstanding` is `GREATEST(order_value − collected, 0)` per row (the screenshot's Paid row has collected Rp 1.099.000 > order value Rp 845.000, outstanding 0). The subtotal outstanding is therefore the SUM of the row outstanding values — never `subtotal_value − subtotal_collected` — so a reader can reproduce it from the visible rows (FR-002/FR-009).
- `preorder_count` is a plain integer sum (rows of one seller are disjoint: a pre-order has exactly one status and one completeness). Counting a multi-seller pre-order once per seller is existing behaviour and unchanged.
- Grand total: the client's `sumRows` uses floating point; make it accumulate in cents (`Math.round(parseMoney(x) * 100)`) so Grand Total = Σ subtotals exactly (SC-002). Results for existing tables are numerically identical (all inputs are 2-dp values).

## D3 — Contiguity of a seller's rows

- The query orders by `artists.name`, then status, then completeness. Two different artists with the same name would interleave. **Decision**: add `orderBy('preorder_items.artist_id')` right after the name so each seller's rows are contiguous; `fromRows()` groups by `artist_id` in first-seen order and the interleaving walks groups, so a subtotal always follows its seller's last row (spec edge case).

## D4 — Rendering inside `DataTable`

- `DataTable` renders `rows` with `#cell-<key>` slots and an optional `rowClass(row)`; the Grand Total uses the `#footer` slot. **Decision**: pass a display list = data rows with a synthetic subtotal row inserted after each seller group (`{ id: 'subtotal__<artist_id>', _subtotal: true, artist_name, preorder_count, total_* }`), no DataTable change:
  - `#cell-artist_name`: subtotal → `t('reports.subtotal') + ' — ' + name` (bold); data row → name (default behaviour reproduced).
  - `#cell-status` / `#cell-payment_completeness`: empty for subtotal.
  - `#cell-actions`: no Detail button for subtotal.
  - `rowClass`: subtotal → `bg-surface-subtle font-semibold border-t border-line-2` (lighter than Grand Total's `border-t-2 … font-bold`).
- Seller filter: the existing `artistFilter` filters data rows client-side; subtotals are filtered the same way (by `artist_id`), then interleaved.
- The Grand Total footer and the Detail drill-down keep reading the data rows only (subtotal rows are never passed to `openPreorderDetail`).
- Single-row sellers still get a subtotal (spec FR-004).

## D5 — Export

- `exportPreorderReport()` already has `$breakdownRows`; after computing the subtotals it builds the sheet rows by inserting, after each seller's last row, `['artist_id' => null, 'artist_name' => 'Subtotal — <name>', 'status' => '', 'payment_completeness' => '', 'preorder_count' => …, 'total_order_value' => …, 'total_collected' => …, 'total_outstanding' => …]`. "Subtotal" is the same word in Indonesian and English, so the file needs no locale. The Summary sheet, file name, sheet names and column order are untouched. No Grand Total row is added (none exists today).
- Alternative: styling/bolding subtotal rows in the sheet (rejected for now: `SheetArrayExport` is a plain array export; the label makes the rows identifiable).
