<?php

return [
    'no_open_session' => 'No open session.',
    'already_has_open_session' => 'You still have an open cashier session. Close it first.',
    'opening_cash_entries_mismatch' => 'The per-outlet opening cash entries must sum to the total opening cash.',
    'not_authorized_close_session' => 'You are not authorized to close this session.',
    'session_already_closed' => 'This session is already closed.',
    'not_authorized_view_summary' => 'You are not authorized to view this session\'s summary.',
    'cannot_close_with_open_tabs' => 'Close the shift only after every open tab is paid, voided or handed over.',
    'denomination_duplicate' => 'Denomination :value was entered twice. Combine it into one row.',
    'opening_denominations_mismatch' => 'The total of the denomination breakdown must equal the opening cash.',
    'cash_movement_not_owner' => 'Cash can only be recorded on your own shift.',
    'invalid_cash_reason' => 'Unknown cash reason for this direction.',
    'reason_note_required' => 'Write a note for the "Other" reason.',
    'cash_out_needs_approval' => 'Cash out above the limit needs approval. Approval is not available in this app yet.',
    'pending_qris_payments' => 'There are :count QRIS payments not yet checked. Check them before closing the shift.',
    'variance_needs_approval' => 'The cash difference is above tolerance and needs approval. Approval is not available in this app yet.',
    'count_or_closing_required' => 'Enter the closing cash amount or the count by denomination.',
    // 065 — label alasan kas masuk/keluar untuk daftar kas di web (kode tetap sumber kebenaran).
    'cash_reason' => [
        'float_topup' => 'Top up float',
        'change_exchange' => 'Change exchange',
        'petty_cash' => 'Petty cash / purchases',
        'supplier' => 'Supplier payment',
        'bank_deposit' => 'Bank / safe deposit',
        'refund' => 'Refund',
        'other' => 'Other',
    ],
    'count_one_method_only' => 'Use one counting method only: by denomination or a typed total, not both.',
    'counted_total_whole' => 'The amount must be whole rupiah (no cents).',
];
