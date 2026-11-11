# Data Model: Bulk Shipping Slips on A4 Cut Sheets

No database or API change. Transient, in-browser shapes only.

- **Slip input** — one element of the existing `POST /preorders/bulk-invoices` response (`preorder_number`, `store_identity{name,address,contact_phone,contact_person}`, `customer{name,phone,address}`). Unchanged.
- **Slip cell** — HTML for one slip: header (title + number) and From | To. Width is the column's, height is the row's.
- **Layout result** — `pages[] → rows[] → { slipIndexes[≤3], height }`. Invariants: slipIndexes across all pages = 0..N-1 in order, once each; a row never spans pages; `sum(row heights) + 1 ≤ 1123` per page.
- **Cut sheet** — the final PDF: `pages.length` A4 portrait pages.
