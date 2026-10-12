**Classification**: INTERNAL

# Contract: Sales page UI

## Toggle (page header, right)
- Two segments: `sales.view_list` ("Daftar order" / "Order list") and `sales.view_tables` ("Meja" / "Tables"). Active segment filled with the brand colour. Reuses the page-kit `ViewToggle`.
- URL: `?view=tables` (absent = list). Unknown value = list. Rendered only with floor access; absent otherwise.
- In Tables view the header's other actions (event filter, bulk verify, export) are not rendered.

## Tables view layout
1. **Figure strip** (page-kit `KpiStrip`, four cells): Open orders · Occupied tables (`20 / 38`, `53% occupied`) · Tables over 45 min · Open order value. Sub-lines only state real values.
2. **"Status meja / Table status" panel**: title, subtitle "Live · per HH.MM · click an occupied table for order details" (time of last successful refresh), legend (Empty, Occupied, Partially paid, Over 45 min, Inactive when present).
3. **Area groups**: heading `Area name` + "N of M tables occupied"; ungrouped tables last; then a **Takeaway** group when any takeaway order is open. Tiles wrap in a responsive grid.
4. **States**: loading skeleton; empty ("no tables configured", with a setup link only for users with the setup menu); error with Retry (never an empty floor); stale notice with the last good data when a refresh fails; paused while the page is hidden.

## Tile
- Empty: name · "Kosong/Empty" badge · "Tersedia/Available". Not focusable.
- Occupied: name · badge · order number · elapsed ("19 mnt") · amount (bold, right). A real `<button>` with an accessible name "Table 4, order WJM-…, 19 min, Rp 33.000"; Enter/Space opens the detail.
- Partial: badge "Sebagian/Partial", yellow fill. Over 45: yellow outline. Inactive with order: "Nonaktif/Inactive" mark.
- Elapsed text refreshes on a clock tick without a data refetch.

## Detail panel (page-kit `DetailPanel`, right side)
- Kicker "Table 4" (or "Takeaway"), title = order number, chips: status, "Over 45 min"/"Partial" when applicable.
- Facts: opened by · opened at · elapsed · table/area · kitchen summary.
- Body: lines table (qty, name, modifiers, note, amount, kitchen status pill), totals (subtotal, discount, total, paid, balance), payments list.
- Actions: none that change the order. Optional single link "Open in cashier screen" (shown only with floor access, which this view already requires).
- Live refresh ≈5 s. Ended states: paid → message + "View transaction" (opens the existing completed-sale popup); cancelled/merged → message; offline → "outdated" mark.
- Keyboard: focus trap, Escape closes, focus returns to the tile.

## Copy
Every string in `id.json` and `en.json` (keys under `sales.*`), added through `merge_locale.py`; parity and key-usage tests must pass.
