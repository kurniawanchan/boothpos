<?php

return [
    'not_courier_fulfillment' => 'This preorder does not use courier fulfillment.',
    'shipment_already_exists' => 'This preorder already has shipment data.',
    'invalid_status_transition' => "Preorder with status ':from' cannot move to ':to'.",
    'not_fully_paid' => 'This preorder is not fully paid and cannot be handed over. Outstanding balance: :outstanding',
    'customer_not_found' => 'Customer not found.',

    // 021-preorder-form-updates
    'discount_exceeds_total' => 'Discount cannot exceed the subtotal plus shipping cost.',
    'pickup_day_not_applicable' => "Pickup day does not apply to 'courier' (Mail Order) fulfillment.",
    'courier_not_applicable' => "Courier does not apply to 'pickup' (Self Pickup) fulfillment.",
    'pickup_day_requires_event' => 'Pickup day requires a linked event.',
    'pickup_day_out_of_range' => "Pickup day must fall within the event's date range.",

    // 022-preorder-invoice-crud-overhaul (US1)
    'edit_not_allowed_status' => 'A "Handed over" or "Cancelled" preorder can no longer be changed — this transaction is closed.',
    'edit_total_below_paid_amount' => 'This change would make the order total less than what the customer has already paid. Adjust the items or use the Cancel action instead.',
    'delete_not_allowed_status' => 'A preorder can only be deleted while its status is "Ordered" or "Cancelled". Use the Cancel action first for any other status.',
    'delete_not_allowed_has_payment' => 'This preorder already has a recorded payment and cannot be deleted. Use the Cancel action instead.',

    // 022-preorder-invoice-crud-overhaul (US5)
    'mail_subject_invoice' => 'Pre-order invoice',
    'mail_subject_payment_invoice' => 'Pre-order payment invoice',

    // 021-preorder-form-updates (US4) — import-row version of the same rules above.
    'import_discount_invalid' => 'Row :row: discount is invalid or exceeds the subtotal.',
    'import_pickup_day_not_applicable' => "Row :row: pickup day does not apply to 'courier' (Mail Order) fulfillment.",
    'import_courier_not_applicable' => "Row :row: courier does not apply to 'pickup' (Self Pickup) fulfillment.",
    'import_courier_unknown' => "Row :row: courier ':courier' is not recognized.",
    'import_pickup_day_requires_event' => 'Row :row: pickup day requires a valid linked event.',
    'import_pickup_day_out_of_range' => "Row :row: pickup day must fall within the event's date range.",

    // 007-preorder-import-export-notify (US3)
    'import_customer_name_required' => 'Customer name is required.',
    'import_event_not_found' => 'Event ID :id not found.',
    'import_sku_required' => 'Row :row: SKU is required.',
    'import_sku_not_found' => "Row :row: SKU ':sku' not found.",
    'import_qty_invalid' => 'Row :row: qty must be at least 1.',
    'import_no_items' => 'Row :row: this order has no valid items.',
    'import_nothing_saved' => 'Import failed — no rows were saved.',
    'not_authorized' => 'Only owner/admin can access this feature.',

    // 022-preorder-invoice-crud-overhaul (US7) — one-row-per-order layout
    'import_event_name_not_found' => "Row :row: event ':name' was not found.",
    'import_event_name_ambiguous' => "Row :row: event name ':name' matches more than one event — use a unique name.",
    'import_fulfillment_invalid' => "Row :row: the receive method column must be 'pickup' or 'mail order'.",
    'import_products_quantities_mismatch' => 'Row :row: products, quantities, and unit prices must have the same count.',
    'import_pickup_day_invalid_format' => "Row :row: pickup day must be written as 'Day 1', 'Day 2', etc.",
    'import_dispatch_status_invalid' => "Row :row: dispatch_status must be blank, 'pending', 'invoice_sent', or 'shipping'.",
    'import_dispatch_shipping_mail_order_only' => "Row :row: dispatch_status 'shipping' only applies to mail order preorders.",
    'import_dispatch_date_invalid' => "Row :row: :column is not a valid date/time — use e.g. 2026-09-27T10:00:00+07:00.",
    'import_dispatch_date_not_applicable' => "Row :row: :column does not apply when dispatch_status is ':status'.",
    'dispatch_status_shipping_mail_order_only' => "'Shipping in progress' only applies to Mail Order preorders.",
    'dispatch_status_cancelled' => 'A cancelled preorder no longer has an active invoice or shipment to track.',

    // 027-preorder-duplicate-split
    'duplicate_item_unavailable' => 'Item ":item" can no longer be sold (deleted or inactive), so this pre-order was not duplicated.',
    'duplicate_not_found' => 'Pre-order not found.',

    // 027-preorder-duplicate-split (split)
    'split_not_allowed_status' => 'A "Handed over" or "Cancelled" pre-order can no longer be split — this transaction is closed.',
    'split_not_allowed_has_payment' => 'This pre-order already has a recorded payment, so it cannot be split. Handle the payment first (for example duplicate it instead, or cancel it).',
    'split_item_not_in_order' => 'One of the selected items does not belong to this pre-order.',
    'split_qty_exceeds_line' => 'You cannot move more than the :qty unit(s) of ":item" in this pre-order.',
    'split_must_move_and_keep' => 'At least one unit must move to the new pre-order and at least one unit must stay on this one.',
    'split_discount_exceeds_remaining' => 'After the split, the discount on this pre-order would be larger than what is left to pay. Lower the discount first, then split again.',
    'split_single_seller' => 'All items in this pre-order belong to the same seller, so there is nothing to split by seller.',

    // Hapus pembayaran pre-order
    'payment_delete_not_allowed_status' => 'Payments of a "Handed over" or "Cancelled" pre-order can no longer be deleted — this transaction is closed.',
];
