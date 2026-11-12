<?php

return [
    'artist_delete_has_active_products' => 'This seller still has active products and cannot be deleted.',
    'artist_delete_has_settlements' => 'This seller already has settlements and cannot be deleted.',
    'artist_delete_has_users' => 'This seller is linked to one or more users. Unlink or change their seller first.',
    'category_delete_has_active_subcategories' => 'This category still has active subcategories and cannot be deleted.',
    'category_delete_has_active_products' => 'This category still has active products and cannot be deleted.',
    'product_delete_has_active_variants' => 'This product still has active variants. Deactivate all variants first.',
    'artist_code_permanent' => 'The seller code is permanent and cannot be changed after creation.',
    'category_code_permanent' => 'The category code is permanent and cannot be changed after creation.',
    'artist_not_authorized' => 'You are not authorized to manage sellers.',
    'artist_license_quota_exceeded' => 'This installation uses a Pro license (one seller). Upgrade to Master to add more sellers.',
    'insufficient_stock' => 'Insufficient stock for variant :sku. Available: :available.',
    'customer_delete_has_transactions' => 'This customer still has transactions (orders/pre-orders) and cannot be deleted.',
    // Customer commerce: kontak dengan akun aplikasi / pesanan pelanggan tidak boleh dihapus (akun jadi yatim).
    'customer_delete_has_customer_account' => 'This customer has a customer app account or customer orders and cannot be deleted.',
];
