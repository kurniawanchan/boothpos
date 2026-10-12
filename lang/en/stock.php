<?php

// 036-bom-variant-stock-ux — pesan layar/riwayat stok.
return [
    'not_authorized' => 'You do not have access to stock movements.',
    'outlet_required' => 'Choose the outlet whose stock this changes.',
    'transfer_same_outlet' => 'The source and destination outlets must be different.',
    'transfer_outlet_inactive' => 'Transfers to or from an inactive outlet are not allowed.',
    'transfer_over_receipt' => 'You cannot receive more than was sent.',
    'transfer_reason_required' => 'A reason is required when less than was sent is received.',
    'transfer_already_received' => 'This transfer has already been received.',
    'transfer_not_in_transit' => 'This transfer is no longer in transit.',
    'transfer_lines_required' => 'Add at least one line.',
    'transfer_line_invalid' => 'Each line needs a kind (variant or material), an item and a quantity above zero.',
    'transfer_line_duplicate' => 'The same item appears twice in this transfer.',
    'transfer_item_not_found' => 'Item not found.',
    'transfer_untracked' => 'Item :sku does not track stock, so it cannot be transferred.',
    'transfer_whole_qty' => 'Items are transferred in whole units.',
    'transfer_client_ref_conflict' => 'This client reference was already used for a different transfer.',
];
