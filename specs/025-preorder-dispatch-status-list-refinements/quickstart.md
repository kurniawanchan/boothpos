# Quickstart: Manual Verification (Constitution II)

Run against the dev stack (`localhost:8000`), logged in as `owner` / `password123`. Rebuild first if needed: `npm run build`; migrate: `docker compose exec app php artisan migrate`.

**Status: not yet run in a real browser** — the automated suites pass (backend 210 preorder/shipment/report tests, frontend 291), but nothing below has been ticked by hand.

## US1 / US2 — Manual marker and Mail Order rule

1. Open the Pre-orders list, then **Detail** on a Self Pickup order. Confirm the "Invoice & shipping progress" card offers only **Not sent yet** and **Invoice sent**.
2. Click **Invoice sent**. Confirm a toast, the button becomes highlighted, and the list row's pill changes without a reload.
3. Open a **Mail Order** order. Confirm all three options; click **Shipping in progress**, then **Invoice sent**, then **Not sent yet** and confirm each step.
4. Confirm the order's stepper status, outstanding amount and stock were not affected by any of the clicks.
5. Open a cancelled order; confirm the three buttons are disabled.
6. Edit the Mail Order order (marked *Shipping in progress*) to **Self pickup** (clear the courier); reopen its detail and confirm it now reads **Invoice sent**.
7. In the list, open the **All invoice/shipping** filter, choose **Invoice sent**; confirm only matching rows and a matching summary panel remain; combine with a status filter.

## US6 — Dates

1. Mark an order **Invoice sent**; confirm the list cell shows "Invoice sent: <date time>" under the pill and the detail card shows the same.
2. Mark it **Invoice sent** again after a few minutes; confirm the date did not change.
3. Mark a Mail Order order **Shipping in progress**; confirm both lines when an invoice date exists, only the shipping line otherwise. Go back to **Invoice sent** and confirm the shipping line disappears; **Not sent yet** clears both.

## US3 — Print menu

1. In a detail with no payment, click **Print**; confirm **Preorder invoice** works and **Payment invoice** is disabled with a tooltip.
2. Record a payment; confirm **Payment invoice** now opens the payment invoice.

## US4 — Row layout and actions

1. Confirm customer names are left-aligned, including names that wrap onto two lines.
2. Confirm the last column header reads **Actions**.
3. On an **Ordered** row, confirm **Detail** plus a **More ▾** menu (Invoice, Payment invoice, Edit, Delete). On a **Handed over** row, confirm three inline links and no menu.
4. Open **More** near the bottom of the page and confirm it is not clipped; press Escape / click elsewhere / scroll and confirm it closes.
5. On a row with a payment choose **Payment invoice** (opens that order's document); on a row without one, confirm the item is disabled.

## US5 — Created / Updated

1. Confirm **Created** and **Updated** columns; sort each both ways.
2. Open a detail; confirm "Created" and "Last updated" under the stepper.

## US7 — Export / import

1. Filter the list (e.g. Invoice sent), click **Export .xlsx**; open the file and confirm only those rows, and columns `dispatch_status`, `invoice_sent_at`, `shipping_at`, `created_at`, `updated_at` (in that order at the end).
2. Delete/keep a copy of the rows, then **Import** the same file unmodified (dry run first); confirm it is accepted and the marker and dates match.
3. Download the template; confirm it has the first three new columns and not `created_at` / `updated_at`.
4. Edit a row to `dispatch_status = shipping` on a `pickup` order; confirm the import is rejected with a row-level message and nothing is created.
