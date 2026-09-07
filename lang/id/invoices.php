<?php

/**
 * 019-billing-system (research.md R2') — Invoice pindah dari gerbang menu
 * 'companies' ke menunya sendiri; pesan-pesan terkait invoice pindah ke
 * namespace ini juga (sebelumnya di lang/{locale}/companies.php).
 */
return [
    'invoice_invalid_transition' => 'Invoice ini sudah berstatus :status, tidak dapat diubah lagi.',
    // FR-011 — invoice yang sudah lunas adalah catatan keuangan final,
    // tidak bisa dihapus lewat jalur manapun (single-delete maupun import).
    'delete_paid_blocked' => 'Invoice yang sudah lunas tidak dapat dihapus.',
    'update_paid_blocked' => 'Invoice yang sudah lunas tidak dapat diubah.',

    // 019-billing-system (T074) — pesan per-baris impor/ekspor invoice,
    // meniru pola pesan preorders.import_*.
    'not_authorized' => 'Anda tidak berhak melakukan aksi ini.',
    'import_company_required' => 'Baris :row: company_name wajib diisi.',
    'import_company_not_found' => 'Baris :row: company ":name" tidak ditemukan.',
    'import_license_required' => 'Baris :row: license_name wajib diisi.',
    'import_license_not_found' => 'Baris :row: license ":name" tidak ditemukan.',
    'import_subtotal_invalid' => 'Baris :row: subtotal harus berupa angka lebih dari 0.',
    'import_due_date_required' => 'Baris :row: due_date wajib diisi.',
    'import_invoice_not_found' => 'Baris :row: invoice_number ":invoice_number" tidak ditemukan.',
    'import_update_paid_blocked' => 'Baris :row: invoice ":invoice_number" sudah lunas dan tidak dapat diubah.',
    'import_nothing_saved' => 'Tidak ada data yang disimpan karena ditemukan baris bermasalah.',
];
