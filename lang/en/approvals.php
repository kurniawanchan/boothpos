<?php

// 059 — seller transaction approval. Server messages; on-screen text lives in locales/*.json.
return [
    'order_voided' => 'This sale has been voided; approvals cannot be recorded.',
    'approval_not_allowed' => 'You are not allowed to approve this seller\'s items.',
    'already_approved' => 'This seller\'s items are already approved.',
    'approval_stale' => 'This seller\'s items changed since the page was opened. Reload and review again before approving.',
    'seller_not_required' => 'This seller has no items in this sale.',
    'image_required' => 'A signature or approval image is required for this method.',
    'image_not_allowed_for_stamp' => 'The stamp method does not use an image.',
    'image_forbidden' => 'You are not allowed to view this approval image.',
    'image_missing' => 'The approval image file was not found.',
    'unsupported_file_type' => 'Only PNG or JPG images are accepted.',
    'approved_log' => 'Seller :seller approved the items of sale :number (:method:behalf).',
    'invalidated_log' => 'Seller :seller\'s approval on sale :number was invalidated because the items changed.',
    'behalf_suffix' => ', on behalf of the seller',
    'methods' => ['draw' => 'drawn signature', 'upload' => 'uploaded image', 'stamp' => 'stamp'],
    'bulk_limit_exceeded' => 'You can approve at most :max sales in one bulk action. Reduce the selection and try again.',
    'bulk_approved_log' => 'Bulk approval (:method) by :user: :approved of :selected sales approved, :skipped skipped.',
    'bulk_via' => 'via bulk',
    'skip_reasons' => [
        'not_found' => 'Sale not found or not available to you.',
        'voided' => 'The sale is voided.',
        'already_approved' => 'Already approved.',
        'no_approvable_seller' => 'You have no seller to approve on this sale.',
        'changed' => 'The sale changed since the preview. Reload and review again.',
        'failed' => 'Could not be approved. Try again.',
    ],
];
