<?php

// 034-seller-po-bom — error messages for the purchase-order based BOM.
return [
    'not_authorized' => 'You are not allowed to manage purchase-order BOMs.',
    'line_not_eligible' => 'This purchase order line cannot be used: it does not belong to this variant\'s seller, or its purchase order is not ordered, received or paid.',
    'duplicate_line' => 'This purchase order line is already in this variant\'s BOM. Change the quantity on the existing row instead.',
    'qty_must_be_positive' => 'Quantity must be greater than zero.',
    'complete_empty' => 'The BOM is empty and cannot be marked complete.',
    'complete_has_legacy' => 'The BOM still has old rows without a purchase order source: :rows. Replace them with purchase order lines or remove them first.',
    'complete_invalid_row' => 'A BOM row has an invalid source or quantity.',
    'cost_price_locked' => 'This variant\'s cost price follows its completed BOM. Reopen the BOM to change it manually.',
    'bom_complete_blocks_legacy_write' => 'This variant\'s BOM is complete. Reopen the BOM before adding or changing legacy rows.',
    'copy_different_product' => 'A BOM can only be copied between variants of the same product.',
    'copy_requires_confirmation' => 'The target variant already has BOM rows. Confirm to replace them.',
    'copy_no_next' => 'There is no next variant for this product.',
    'copy_same_variant' => 'The source and target variants must differ.',
    'purchase_order_seller_in_use' => 'This purchase order\'s seller cannot be changed because its lines are already used by a BOM.',
    'source_replace_not_allowed' => 'This row\'s source cannot be replaced.',
    'copy_source_empty' => 'The source BOM is empty, there is nothing to copy.',
    'copy_no_other_variants' => 'This product has no other variants.',
];
