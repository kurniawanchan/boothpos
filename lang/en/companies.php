<?php

return [
    'business_type_delete_has_companies' => 'This business type is still referenced by a company and cannot be deleted.',
    // package_delete_has_companies moved to lang/en/licenses.php as
    // license_delete_has_companies (019-billing-system, research.md R2').
    'activation_already_active' => 'This company is already active.',
    'activation_requires_paid_invoice' => 'This company cannot be activated yet — no paid invoice exists for it.',
    'deactivation_not_active' => 'This company is not active, so it cannot be deactivated.',
    // invoice_invalid_transition moved to lang/en/invoices.php
    // (019-billing-system, research.md R2' — Invoice is now its own menu).
    'company_delete_has_invoices' => 'This company still has invoices recorded and cannot be deleted.',
];
