<?php

return [
    'required' => 'This action needs approval from an authorised approver.',
    'mismatch' => 'The approval does not match this action. Request a new approval.',
    'expired' => 'The approval has expired. Request a new approval.',
    'consumed' => 'This approval has already been used.',
    'not_permitted' => 'This approver is not authorised for this action.',
    'same_person' => 'The approver must not be the same person as the requester.',
    'has_payments' => 'A tab that has been paid cannot be cancelled.',
    'pending_qris' => 'A QRIS payment is not yet checked. Resolve it before taking another non-cash payment.',
    'duplicate_edc' => 'This EDC approval code was recorded recently. Make sure this is not a duplicate.',
    'approval_code_invalid' => 'The EDC approval code must be 6–12 uppercase letters or digits.',
    'non_cash_exceeds_remaining' => 'Non-cash payments exceed the remaining balance.',
    'kitchen_status_changed' => 'The kitchen status changed. Reload the tab and try again.',
    'stock_decision_required' => 'Choose a stock decision: return or record as wasted.',
    'stock_return_after_prep' => 'Stock cannot be returned after the item has started cooking.',
    'reference_min' => 'The QRIS reference must be at least 4 characters.',
    'fail_only_qris' => 'Only a QRIS payment can be marked as not found.',
    'fail_only_open_tab' => 'A payment can only be marked as not found while its tab is still open.',
    'subject_outlet_required' => 'The approval subject must include outlet_id.',
    'outlet_ids_empty' => 'outlet_ids must not be empty; name at least one outlet.',
];
