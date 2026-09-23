# Quickstart: Verifying Pre-order Form & Workflow Updates

## Backend tests

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderFormUpdatesTest
```

(Note the explicit `-e` overrides — running the bare `docker compose exec
app php artisan test` in this Docker dev setup silently wipes the real dev
database; see `phpunit.xml`/`docs/RUNBOOK.md`, discovered during feature 020.)

Expect coverage for: discount rejected when it would make the total
negative; pickup day accepted only within the linked event's date range;
pickup day rejected with no linked event; pickup day cleared when an
event's dates change to exclude a previously-valid day; courier defaults
to JNE when omitted; courier rejected if not in the known list; a
pickup_day-on-courier-fulfillment (and vice versa) import row is a row
error, not silently accepted; existing pre-020/021 preorders still load
correctly with all three new fields `null`/zero.

## Manual walkthrough (real browser, per Constitution II)

1. Log in as any role at `http://localhost:8000/preorders`. Click "New
   preorder."
2. Type part of a customer's name into the customer field. Confirm a
   dropdown of matches appears **inline, without a second pop-up window**.
   Confirm "walk-in" and "add new customer" are still reachable from it.
3. Add an item, confirm its quantity can be typed directly (not only
   via the +/- buttons).
4. Enter a discount amount. Confirm the displayed estimated total
   decreases accordingly. Try a discount larger than the subtotal —
   confirm it's rejected with a clear message, not silently clamped.
5. Choose an event with a **2-day** date range, then select "Self
   Pickup." Confirm two day choices appear, each showing a real calendar
   date matching the event. Save.
6. Repeat with a **1-day** event — confirm only one day is offered.
   Repeat with **no event selected** — confirm no pickup-day field
   appears at all, and the preorder saves without one.
7. Choose "Mail Order" (previously "Courier" — confirm the label reads
   "Mail Order" here and everywhere else this fulfillment option is
   shown: the preorder list, filters, and reports). Confirm a courier
   dropdown appears, pre-selected to "JNE," and can be changed.
8. Open the saved preorder's invoice. Confirm: the discount line appears
   with the correct amount; if Self Pickup was chosen, the real pickup
   date appears; if Mail Order was chosen, nothing courier-specific is
   required on the invoice itself (the *shipment* record is still a
   separate step).
9. For a Mail Order preorder, go create its shipment (existing flow).
   Confirm the courier field is now a dropdown, pre-filled with the
   value chosen at order time, and can still be changed independently.
10. Export the pre-order list. Confirm the file has `discount`,
    `pickup_day`, `courier_name` columns populated correctly.
11. Import a file with a row that sets `pickup_day` while
    `fulfillment=courier` (or `courier_name` while `fulfillment=pickup`).
    Confirm the whole import is rejected with that row identified by
    number, and nothing from the file is saved.
12. Re-export immediately after a successful import and confirm the same
    discount/pickup_day/courier_name values round-trip unchanged.
