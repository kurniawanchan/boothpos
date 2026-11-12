<?php

// 057 — izin aksi per menu.
return [
    'action_not_allowed' => 'Your role is not allowed to do this (:action on :menu).',
    'owner_locked' => 'The Owner role always has full access and cannot be restricted.',
    'unknown_menu' => 'Unknown menu: :menu.',
    'unknown_action' => 'Unknown action ":action" for menu :menu.',
    'menu_not_in_role' => 'Menu :menu is not part of this role.',
    'actions' => [
        'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete',
        'draft' => 'Drafts', 'status' => 'Change status', 'bom' => 'Bill of materials', 'catalog' => 'Catalog',
        'import' => 'Import', 'export' => 'Export', 'publish' => 'Publish to storefront', 'adjust' => 'Adjust stock', 'payment' => 'Payments',
        'dispatch' => 'Dispatch status', 'verify' => 'Verify payments', 'split' => 'Split', 'duplicate' => 'Duplicate',
        'notify' => 'Send notifications', 'activate' => 'Activate / deactivate', 'mark_paid' => 'Mark paid', 'cancel' => 'Cancel',
        'void' => 'Void sales', 'payment_method' => 'Change payment method', 'reference' => 'Sale reference', 'receipts' => 'Download receipts',
        'refresh' => 'Refresh', 'finalize' => 'Finalize', 'reopen' => 'Reopen', 'acknowledge' => 'Acknowledgement', 'payout' => 'Payouts', 'approve' => 'Approve seller items',
        // Customer commerce — transisi pesanan pelanggan (menu customer_orders); `cancel` memakai label di atas.
        'confirm' => 'Confirm order', 'pack' => 'Pack', 'ready' => 'Ready for pickup', 'pickup' => 'Hand over (picked up)', 'ship' => 'Ship & tracking', 'deliver' => 'Mark delivered',
        'backup' => 'Backups', 'restore' => 'Restore', 'branding' => 'Store branding',
    ],
];
