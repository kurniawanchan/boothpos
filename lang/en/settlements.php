<?php

/**
 * 076-settlement-tab-session-branding — seller settlement error messages.
 * Keys match the `code` of the 409 response (SettlementRuleException).
 */
return [
    'settlement_exists' => 'An active settlement already exists for this seller and event.',
    'not_draft' => 'This settlement is finalized and cannot be changed this way. Reopen it or add an adjustment.',
    'not_finalized' => 'This settlement is not finalized yet.',
    'nothing_to_settle' => 'Nothing to settle: no sales, paid pre-orders, deductions or adjustments yet.',
    'reconciliation_mismatch' => 'The reconciliation does not balance (:figure); the settlement cannot be finalized.',
    'was_finalized' => 'A draft that was ever finalized cannot be deleted (its number was already issued).',
    'has_payments' => 'A settlement that already has payments cannot be reopened.',
    'net_below_paid' => 'This adjustment would make the total smaller than what has already been paid.',
    'direction_locked' => 'This adjustment would flip the payment direction although payments already exist.',
    'already_settled' => 'This settlement is already fully paid.',
    'nothing_to_pay' => 'This settlement is zero and needs no payment.',
    'deduction_after_finalization' => 'After finalization only adjustments can be added.',

    // Adjustment/deduction validation
    'amount_invalid' => 'The amount must be a number with at most two decimals.',
    'amount_positive' => 'The amount must be greater than zero with at most two decimals.',
    'amount_zero' => 'The amount cannot be zero.',
    'amount_deduction_positive' => 'Enter a deduction as a positive number; the system subtracts it.',
    'not_authorized' => 'You are not allowed to access the settlement module.',

    'amount_exceeds_balance' => 'The amount exceeds what is still owed; maximum :max.',
    'client_ref_taken' => 'This payment reference is already used on another settlement.',
    'acknowledgement_note_required' => 'A note is required for an acknowledgement recorded outside the system.',

    'created' => 'Created settlement :seller for event :event.',
    'refreshed' => 'Refreshed the sources of settlement :seller (:event).',
    'deleted' => 'Deleted the draft settlement :seller (:event).',
    'adjustment_added' => 'Added :kind ":label" to settlement :seller (:event).',
    'adjustment_updated' => 'Changed :kind ":label" on settlement :seller (:event).',
    'adjustment_removed' => 'Removed :kind ":label" from settlement :seller (:event).',
    'finalized' => 'Finalized settlement :number (:seller, :event).',
    'reopened' => 'Reopened settlement :number (:seller, :event).',
    'acknowledged' => 'Recorded the seller acknowledgement on settlement :number.',
    'payment_added' => 'Recorded a payment on settlement :number.',
    'payment_removed' => 'Removed a payment from settlement :number.',

    'kind_deduction' => 'deduction',
    'kind_adjustment' => 'adjustment',
];
