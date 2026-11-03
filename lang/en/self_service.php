<?php

// Self-service QR ordering (090). Customer-facing pages are always Indonesian; this file only keeps key parity.
return [
    'qr_invalid' => 'This QR code is not valid. Please ask the staff for help.',
    'table_unavailable' => 'This table is not taking self-service orders right now. Please ask the staff for help.',

    'qris_unavailable' => 'QRIS payment is not available at this outlet. Please ask the staff for help.',
    'item_unavailable' => 'Some items cannot be ordered right now. Please update your cart.',
    'proof_required' => 'A photo of the payment proof is required.',
    'proof_invalid' => 'The payment proof is not valid. Please upload the photo again.',
    'proof_used' => 'This payment proof has already been used. Please upload a new photo.',
    'cart_empty' => 'Your cart is empty.',
    'local_ref_taken' => 'This submission cannot be processed. Please try again.',
    'proof_file_invalid' => 'The file must be a JPEG or PNG photo, 5 MB at most.',

    // QR meja (US2)
    'qr_base_url_invalid' => 'The self-service base address is not set or cannot be reached from customer phones (not localhost). Set it in Settings → Self-Service.',
    'qr_batch_one_of' => 'Send exactly one of: a list of selected tables (ids) or a filter.',
    'qr_batch_too_large' => 'Too many tables ({max} at most). Narrow the filter or select fewer tables.',
    'invalid_transition' => 'This order is already "{status}" and cannot be processed this way.',
    'verify_not_allowed' => 'You are not allowed to verify or reject QRIS payments.',
    'reference_min' => 'The payment reference must be at least 4 characters.',
    'reject_reason_required' => 'A rejection reason is required.',
    'no_open_shift' => 'Open a shift at this outlet before sending orders to the kitchen.',
    'total_changed' => 'The price or tax changed since the customer ordered, so the total no longer matches what was paid. Reject this order and ask the customer to order again.',
    'status_awaiting_verification' => 'awaiting verification',
    'status_verified' => 'verified',
    'status_dispatched' => 'sent to the kitchen',
    'status_rejected' => 'rejected',
    'receipt_not_found' => 'Receipt not found. Check the link or ask the staff for help.',
    'customer_name_required' => 'A name is required when you fill in your details.',
    'customer_phone_invalid' => 'The phone number is not valid. Example: 081234567890.',
];
