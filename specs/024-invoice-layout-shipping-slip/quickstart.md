# Quickstart: Manual Verification (Constitution II)

Run against the dev stack, logged in as `owner`/`password123`.

## US1 — Two-column header

1. Open a pre-order invoice for an order linked to an event with a name,
   location, and available-on day all set. Confirm the header renders as
   two columns: left = event name, then location, then available-on
   (each centered); right = pre-order number, status, and a "To:" block
   with whichever of the customer's name/email/phone/social handle/
   address are on file.
2. Open an invoice for a pre-order with no linked event. Confirm the left
   column's event-related content is simply absent (no empty gap).
3. Confirm the pre-order's creation date is visible somewhere near the
   order identity information.

## US2 — Document title

1. With the invoice open, confirm the modal's title bar reads as a
   pre-order invoice (not just the bare order number).

## US3 — Two-column payment options, bigger QR

1. Open an invoice for a store with both a QR-based channel and a
   bank-transfer channel configured. Confirm they render in two visually
   separate columns.
2. Confirm the QR image is clearly the dominant visual element in its
   column (larger than feature 023's size), still clickable to open the
   full-size popup.
3. Temporarily deactivate all bank-transfer channels (or use a store with
   only QR configured) — confirm only the QR column appears, no empty
   second column.

## US4 — Shipping slip

1. Open the invoice for a Mail Order (courier) pre-order. Confirm a
   shipping-slip section appears with: event name, pre-order number,
   "From" (store identity), "To" (customer details), and the item
   type(s) in the order.
2. Open the invoice for a Self Pickup pre-order. Confirm no shipping-slip
   section appears.
3. Confirm the shipping slip renders correctly even for a Mail Order
   pre-order that has no `Shipment` record created yet (no "Create
   shipment data" action taken).

## US5 — Footer message regression check

1. Confirm a store's configured footer/closing message still appears on
   the invoice, unchanged, after all the above layout changes.

## Payment invoice parity

1. Repeat the header/payment-columns/shipping-slip checks above on the
   payment invoice (record a payment on a pre-order, open its payment
   invoice) — confirm the same treatment applies there too.

## Regression check

Run `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test`
and the full frontend suite (`npm test -- --run`) before considering the
feature done.
