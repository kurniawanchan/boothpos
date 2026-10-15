<?php

return [
    'not_authorized_void' => 'Only owner/admin can void a transaction.',
    'unsupported_file_type' => 'Unsupported file type. Only JPEG or PNG.',
    'not_authorized_proof_access' => 'You are not authorized to access this file.',
    'proof_not_found' => 'File not found.',
    'not_authorized' => 'Not authorized.',
    'session_not_open' => 'The cashier session is not open.',
    'variant_inactive' => 'Variant :sku is inactive.',
    'payment_insufficient' => 'The payment total does not cover the transaction total.',
    'proof_required_for_non_cash' => 'A payment proof is required for non-cash methods.',
    'proof_token_invalid' => 'Invalid or already-used payment proof token.',
    'change_exceeds_cash_received' => 'Change cannot exceed the cash amount received.',
    'already_voided' => 'This transaction was already voided.',
    'customer_not_found' => 'Customer not found.',
    'discount_exceeds_line_value' => 'The discount for variant :sku exceeds that line\'s value.',
    'discount_exceeds_subtotal' => 'The order discount exceeds the subtotal.',
    'channel_in_use' => 'This payment channel is in use and cannot be deleted.',

    // 028-partial-split-payment
    'payment_exceeds_balance' => 'The amount is higher than the remaining balance. You can pay at most :max.',
    'payment_already_fully_paid' => 'This transaction is already fully paid.',
    'payment_target_closed' => 'This transaction is closed (handed over, cancelled or voided), so payments can no longer be added or removed.',
    'payment_session_required' => 'Cash payments need an open cashier session. Open a session first, then record the payment.',
    'payment_client_ref_conflict' => 'This payment reference key was already used for a different transaction.',
    'customer_required_for_partial_payment' => 'A customer is required to complete a sale that is not paid in full, so it is clear who owes the remaining balance.',
    'payment_delete_closed_shift' => 'This cash payment belongs to a cashier shift that is already closed and reconciled, so it cannot be deleted.',

    // 031-optional-payment-proof
    'channel_required_for_non_cash' => 'A payment channel is required for non-cash methods.',
    'payment_confirmation_not_allowed' => 'Only an owner/admin or the user who recorded this payment can change its confirmation.',
    'payment_confirmation_empty' => 'Provide at least a proof, a reference or notes; a confirmation cannot be left empty.',
    'payment_confirmation_cash' => 'Cash payments have no confirmation to add.',
    'payment_confirmation_target_closed' => 'This transaction is voided or cancelled; its confirmation can no longer be changed.',

    // 032-mark-payment-verified
    'payment_verify_not_allowed' => 'You recorded this payment, so someone else must verify it.',
    'payment_verify_cash' => 'Cash payments are verified when they are recorded.',
    'payment_already_verified' => 'This payment is already verified.',
    'payment_rejected_cannot_verify' => 'A rejected payment cannot be verified.',
    'payment_verify_target_closed' => 'This transaction is voided or cancelled; its payments can no longer be verified.',
];
