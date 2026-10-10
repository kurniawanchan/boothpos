<?php

// Restaurant feature messages (028). Keys are added per user story.
return [
    'forbidden' => 'You are not allowed to do this.',

    // Tables (US1)
    'table_name_taken' => 'A table with this name already exists in that area.',
    'table_has_open_tab' => 'This table has an open tab. Settle or void the tab before deactivating the table.',
    'table_has_history' => 'This table has been used by orders and cannot be deleted. Deactivate it instead.',

    // Tabs (US2)
    'local_ref_not_a_tab' => 'This reference belongs to a sale, not to a tab.',
    'table_inactive' => 'This table is inactive and cannot receive a new tab.',
    'table_occupied' => 'This table already has an open tab.',
    'tab_not_open' => 'This tab is no longer open.',
    'tab_not_empty' => 'This tab has items. Remove them, or void the tab.',
    'empty_tab_reason' => 'Empty tab closed',
    'tab_empty' => 'The tab has no items to pay for.',
    'line_already_sent' => 'This item was already sent to preparation and can no longer be edited here.',
    'total_below_paid' => 'The tab total cannot drop below the amount already paid.',

    // Modifiers (US3)
    'modifier_rule_violated' => 'Group ":group": choose :range option(s).',
    'modifier_option_invalid' => 'One of the chosen options is not available for this item.',
    'modifier_price_negative' => 'The chosen options would make the item price negative.',
    'modifier_min_above_max' => 'The minimum selection cannot be higher than the maximum.',
    'modifier_option_not_in_group' => 'An option id does not belong to this group.',
    'modifier_group_has_no_options' => 'Add at least one active option before attaching this group.',
    'modifier_group_in_use' => 'This group has been used on orders and cannot be deleted. Deactivate it instead.',
    'attachment_not_found' => 'One or more of the selected items does not exist.',

    // Kitchen (US4)
    'station_name_taken' => 'A station with this name already exists.',
    'station_in_use' => 'This station is assigned to a category or product, or has received tickets. Deactivate it instead.',
    'station_not_found' => 'The selected station does not exist.',
    'nothing_to_send' => 'There is nothing new to send to the kitchen.',
    'ticket_transition_invalid' => 'An item that is ":from" cannot be moved to ":to".',

    // Move / merge / split (US5)
    'merge_same_tab' => 'A tab cannot be merged into itself.',
    'merge_different_shift' => 'Only tabs of the same cashier shift can be merged.',
    'merge_source_has_payments' => 'The tab to merge already has payments and cannot be merged.',
    'merged_reason' => 'Merged into :number',
    'split_has_payments' => 'A tab that already has payments cannot be split.',
    'split_partial_sent_line' => 'Only whole units of ":name" can be split while it is being prepared.',
    'split_qty_exceeds' => 'The quantity to split is more than what ":name" has on the tab.',
    'split_paid_in_full_reason' => 'Paid in full through split bills',

    // Cancel / void (US6)
    'cancel_reason_required' => 'A reason is required to cancel an item that was already sent.',
    'cancel_after_prep_forbidden' => 'Only an owner or admin can cancel an item once preparation has started.',
    'void_has_payments' => 'A tab with recorded payments cannot be voided.',
];
