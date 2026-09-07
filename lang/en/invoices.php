<?php

/**
 * 019-billing-system (research.md R2') — Invoice moved from the
 * 'companies' menu gate to its own menu; invoice-related messages moved
 * to this namespace too (previously in lang/{locale}/companies.php).
 */
return [
    'invoice_invalid_transition' => 'This invoice is already :status and cannot be changed further.',
    // FR-011 — a paid invoice is a final financial record and cannot be
    // deleted through any path (single delete or import).
    'delete_paid_blocked' => 'A paid invoice cannot be deleted.',
    'update_paid_blocked' => 'A paid invoice cannot be edited.',

    // 019-billing-system (T074) — per-row import/export messages, mirroring
    // the preorders.import_* pattern.
    'not_authorized' => 'You are not authorized to perform this action.',
    'import_company_required' => 'Row :row: company_name is required.',
    'import_company_not_found' => 'Row :row: company ":name" not found.',
    'import_license_required' => 'Row :row: license_name is required.',
    'import_license_not_found' => 'Row :row: license ":name" not found.',
    'import_subtotal_invalid' => 'Row :row: subtotal must be a number greater than 0.',
    'import_due_date_required' => 'Row :row: due_date is required.',
    'import_invoice_not_found' => 'Row :row: invoice_number ":invoice_number" not found.',
    'import_update_paid_blocked' => 'Row :row: invoice ":invoice_number" is already paid and cannot be changed.',
    'import_nothing_saved' => 'Nothing was saved because problematic rows were found.',
];
