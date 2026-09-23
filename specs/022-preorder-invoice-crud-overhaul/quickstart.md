# Quickstart: Manual Verification (Constitution II)

Run against the dev stack (`docker compose up`, seeded LIVE + DEMO data).
Log in as `owner`/`password123`.

## US1 — Edit / Delete a pre-order

1. Open Pre-orders, find an order with status "Ordered". Click **Edit**.
   Change an item's quantity, add a new item, save. Confirm totals update
   and no stock movement was recorded (`inventory` → Stock → variant
   history unchanged).
2. Advance that order to "Goods arrived" (stock increases). Edit it again:
   increase one item's quantity by 2. Confirm the variant's
   `current_stock` decreases by exactly 2 more (a `purchase` movement for
   the delta only, not a full reversal) and a matching entry appears in
   stock history.
3. Try editing an order with status "Handed over" or "Cancelled" — confirm
   the Edit action is disabled/blocked with a clear message.
4. Try deleting an order with status other than "Ordered" — confirm a 409
   with a message pointing to "Cancel" instead. Delete an "Ordered" order
   with no payments — confirm it disappears from the list and its items
   are gone.

## US2 — Shipment auto-fill + address consolidation

1. Open a pre-order, click "Create shipment data". Confirm
   name/phone/address are pre-filled from the linked customer, editable
   before submit.
2. Confirm the form has no City/Postal Code fields — only one address
   field.

## US3 — Invoice rename + redesign

1. Open a pre-order's document — confirm the button/label says "Invoice"
   everywhere ("Print" wording as well, if still present).
2. Confirm the invoice shows: store logo/name/contact
   person/phone/email/address; itemized lines; discount line; payment
   channels ("payment terms") with full unmasked account numbers; footer
   text.
3. Click the QR — confirm it enlarges in a popup.
4. Download as PDF/image — confirm it renders correctly (no blank/cut-off
   sections).

## US4 — Payment invoice

1. Record a payment on a pre-order, open its "Payment invoice" (renamed
   from "Payment receipt"). Confirm the same visual shell as the invoice,
   plus the specific payment's amount/method/date and running
   paid/outstanding figures.

## US5 — Bulk download / email

1. In the Pre-orders list, select multiple orders via checkboxes. Click
   "Download invoices" — confirm a single zip downloads containing one
   PDF per order.
2. Click "Email invoices" — confirm a success/skip summary is shown per
   order, and (if `MAIL_MAILER` is configured to `log`) check
   `storage/logs/laravel.log` for the rendered email body.

## US6 — Customer picker scrollable list

1. Open the customer dropdown on the Create/Edit pre-order form without
   typing anything — confirm a scrollable list of customers appears
   immediately (not just after typing a search term).

## US7 — Import/export overhaul

1. Download the pre-order import template — confirm columns match:
   event_name, fulfillment, pickup_day, products, quantities,
   unit_prices, shipping_cost, courier_name, expected_date, discount,
   notes (plus customer_name).
2. Fill one valid row referencing a real event/products, import it with
   `dry_run=1` — confirm a preview with no rows written. Import for real —
   confirm the pre-order is created correctly (items, quantities, pickup
   day resolved to a real date, courier only when mail order).
3. Export existing pre-orders — confirm the same column layout, and that
   the exported file re-imports without modification.

## Regression check

Run `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderCrudInvoiceTest`
and the full suite before considering the feature done.
