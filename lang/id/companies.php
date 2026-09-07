<?php

return [
    'business_type_delete_has_companies' => 'Jenis bisnis ini masih dirujuk oleh company dan tidak dapat dihapus.',
    // package_delete_has_companies dipindah ke lang/id/licenses.php sebagai
    // license_delete_has_companies (019-billing-system, research.md R2').
    'activation_already_active' => 'Company ini sudah aktif.',
    'activation_requires_paid_invoice' => 'Company ini belum bisa diaktifkan — belum ada invoice yang berstatus lunas.',
    'deactivation_not_active' => 'Company ini belum aktif, tidak bisa dinonaktifkan.',
    // invoice_invalid_transition dipindah ke lang/id/invoices.php
    // (019-billing-system, research.md R2' — Invoice kini menu mandiri).
    'company_delete_has_invoices' => 'Perusahaan ini masih memiliki invoice tercatat, tidak dapat dihapus.',
];
